<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Jobs\ProcessLeadImport;
use App\Support\ItalianPhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Storage;

class LeadController extends Controller
{
    use HandlesApiErrors;

    private const IMPORT_EXTENSIONS = ['csv', 'txt', 'xls', 'xlsx'];

    /**
     * Display a listing of leads.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->isSuperAdmin() && $request->filled('company_id')) {
            $request->validate(['company_id' => 'integer|exists:companies,id']);
        }
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : ($user->company_id ?? null);

        // Company scoping for clients is applied by Lead's global scope
        // (granted categories + own company_id). Super admin can optionally
        // narrow to one company.
        if ($user->isSuperAdmin() && $request->filled('company_id')) {
            $query = Lead::where('company_id', $companyId);
        } else {
            $query = Lead::query();
        }

        // Apply search filter (includes JSON file body for legacy "one row = whole file" leads)
        if ($request->has('search') && $request->search) {
            $this->applyLeadSearch($query, (string) $request->search);
        }

        // Apply status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Apply source filter
        if ($request->has('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        // Apply category filter (clients may only choose categories they can access)
        if ($request->has('category') && $request->category !== 'all') {
            $category = (string) $request->category;
            if (! $user->isSuperAdmin() && ! $this->userCanAccessLeadCategory($user, $category)) {
                return response()->json(['message' => 'Access denied for this category.'], 403);
            }
            $query->where('category', $category);
        }

        $this->applyLeadFieldFilters($query, $request);
        $this->applyImportFiltersFromRequest($query, $request);

        // Use explicit orderBy with index for better performance
        // This prevents MySQL from sorting all rows before pagination
        $leads = $query->orderBy('created_at', 'desc')
                      ->paginate($request->get('per_page', 15));

        $this->trimEmbeddedFileRecordsForList($leads->getCollection());

        return response()->json($leads);
    }

    /**
     * Cap embedded CSV/Excel rows on the list endpoint so JSON payloads and the SPA table
     * stay responsive (full rows remain available on {@see show}).
     */
    private function trimEmbeddedFileRecordsForList(\Illuminate\Support\Collection $leads): void
    {
        $maxRows = 1500;
        foreach ($leads as $lead) {
            if (! $lead instanceof Lead) {
                continue;
            }
            $records = $lead->file_records;
            if (! is_array($records) || count($records) <= $maxRows) {
                continue;
            }
            $lead->setAttribute('file_records', array_slice($records, 0, $maxRows));
        }
    }

    /**
     * Search lead scalar columns and legacy embedded file_records JSON (substring match).
     */
    private function applyLeadSearch(\Illuminate\Database\Eloquent\Builder $query, string $search): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
        $like = '%'.$escaped.'%';

        $query->where(function ($q) use ($like) {
            $q->where('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('mobile', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('age', 'like', $like)
                ->orWhere('gender', 'like', $like)
                ->orWhere('country', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('date_of_birth', 'like', $like)
                ->orWhere('intention', 'like', $like)
                ->orWhere('file_name', 'like', $like)
                ->orWhere(function ($q2) use ($like) {
                    $q2->whereNotNull('file_records')
                        ->where('file_records', 'like', $like);
                });
        });
    }

    /** Apply the explicit Leads-page filters while retaining legacy raw imports. */
    private function applyLeadFieldFilters(Builder $query, Request $request): void
    {
        $filters = [
            'age' => [
                'column' => 'age',
                'aliases' => ['Age', 'Eta', 'Età', 'EtÃ ', 'Anni'],
            ],
            'gender' => [
                'column' => 'gender',
                'aliases' => ['Gender', 'Sesso', 'Sex'],
            ],
            'country' => [
                'column' => 'country',
                'aliases' => ['Country', 'Paese', 'Nazione', 'Stato'],
            ],
            'city' => [
                'column' => 'city',
                'aliases' => ['City', 'Città', 'Citta', 'Comune', 'Località', 'Localita', 'Municipality'],
            ],
            'intention' => [
                'column' => 'intention',
                'aliases' => ['Intention', 'Intent', 'Intenzione', 'Interesse', 'Interest', 'Lead Intention'],
            ],
        ];

        foreach ($filters as $requestKey => $definition) {
            $value = trim((string) $request->input($requestKey, ''));
            if ($value === '') {
                continue;
            }

            $this->applyLeadColumnOrRawFilter(
                $query,
                $definition['column'],
                $value,
                $definition['aliases']
            );
        }

        foreach (['date_of_birth_from', 'date_of_birth_to'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                $request->validate([$field => 'date_format:Y-m-d']);
            }
        }

        if ($request->filled('date_of_birth_from')) {
            $query->where(function (Builder $dateFilter) use ($request): void {
                $dateFilter->whereDate('date_of_birth', '>=', (string) $request->input('date_of_birth_from'))
                    // Legacy file-batch leads contain several contacts in JSON.
                    // They are retained here and filtered per-contact in the SPA.
                    ->orWhereNotNull('file_records');
            });
        }

        if ($request->filled('date_of_birth_to')) {
            $query->where(function (Builder $dateFilter) use ($request): void {
                $dateFilter->whereDate('date_of_birth', '<=', (string) $request->input('date_of_birth_to'))
                    ->orWhereNotNull('file_records');
            });
        }
    }

    /** Search a dedicated field first, then the original imported JSON fields. */
    private function applyLeadColumnOrRawFilter(
        Builder $query,
        string $column,
        string $value,
        array $aliases
    ): void {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($value));
        $like = '%'.$escaped.'%';
        $driver = $query->getModel()->getConnection()->getDriverName();

        $query->where(function (Builder $filter) use ($column, $like, $aliases, $driver): void {
            $filter->whereRaw('LOWER(CAST('.$column.' AS CHAR)) LIKE ?', [$like]);

            $filter->orWhere(function (Builder $legacy) use ($like): void {
                $legacy->whereNotNull('file_records')
                    ->where('file_records', 'like', $like);
            });

            $filter->orWhere(function (Builder $raw) use ($like, $aliases, $driver): void {
                $raw->whereNotNull('raw_attributes');
                if ($driver === 'mysql') {
                    $raw->where(function (Builder $aliasQuery) use ($like, $aliases): void {
                        foreach ($aliases as $alias) {
                            $path = '$."'.str_replace(['\\', '"'], ['\\\\', '\\"'], $alias).'"';
                            $aliasQuery->orWhereRaw(
                                'LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_attributes, ?))) LIKE ? COLLATE utf8mb4_unicode_ci',
                                [$path, $like]
                            );
                        }
                    });
                } else {
                    $raw->where('raw_attributes', 'like', $like);
                }
            });
        });
    }

    /**
     * Optional stacked filters on imported columns (raw_attributes JSON and legacy file_records).
     * Request: import_filters JSON array of { "field": "Città", "value": "Roma" } (field optional = search any column).
     */
    private function applyImportFiltersFromRequest(Builder $query, Request $request): void
    {
        $filters = $this->parseImportFiltersPayload($request);
        foreach ($filters as $filter) {
            $field = $filter['field'];
            $value = $filter['value'];
            $this->applySingleImportFilter($query, $field, $value);
        }
    }

    /**
     * @return array<int, array{field: string, value: string}>
     */
    private function parseImportFiltersPayload(Request $request): array
    {
        $raw = $request->input('import_filters');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $field = mb_substr(trim((string) ($item['field'] ?? '')), 0, 120);
            $value = mb_substr(trim((string) ($item['value'] ?? '')), 0, 255);
            if ($value === '') {
                continue;
            }
            $out[] = ['field' => $field, 'value' => $value];
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    private function applySingleImportFilter(Builder $query, string $field, string $value): void
    {
        $effectiveField = $field;
        if ($effectiveField !== '' && ! $this->isSafeImportFieldKey($effectiveField)) {
            $effectiveField = '';
        }

        $escapedForLike = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
        $valueLike = '%'.$escapedForLike.'%';
        $escapedLower = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($value));
        $valueLower = '%'.$escapedLower.'%';
        $fieldLike = $effectiveField !== '' ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $effectiveField).'%' : '';

        $driver = $query->getModel()->getConnection()->getDriverName();

        $query->where(function (Builder $outer) use ($effectiveField, $valueLike, $valueLower, $fieldLike, $driver) {
            $outer->where(function (Builder $q) use ($effectiveField, $valueLike, $valueLower, $driver) {
                $q->whereNotNull('raw_attributes');
                if ($effectiveField !== '') {
                    if ($driver === 'mysql') {
                        $ptr = '$."'.str_replace(['\\', '"'], ['\\\\', '\"'], $effectiveField).'"';
                        $q->whereRaw(
                            'LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw_attributes, ?))) LIKE ? COLLATE utf8mb4_unicode_ci',
                            [$ptr, $valueLower]
                        );
                    } else {
                        $q->where('raw_attributes', 'like', $valueLike);
                    }
                } elseif ($driver === 'mysql') {
                    $q->whereRaw('LOWER(CAST(raw_attributes AS CHAR)) COLLATE utf8mb4_unicode_ci LIKE ?', [$valueLower]);
                } else {
                    $q->where('raw_attributes', 'like', $valueLike);
                }
            });

            $outer->orWhere(function (Builder $q) use ($effectiveField, $valueLike, $fieldLike) {
                $q->whereNotNull('file_records');
                if ($effectiveField !== '') {
                    $q->whereNotNull('file_headers')
                        ->where('file_headers', 'like', $fieldLike)
                        ->where('file_records', 'like', $valueLike);
                } else {
                    $q->where('file_records', 'like', $valueLike);
                }
            });
        });
    }

    private function isSafeImportFieldKey(string $field): bool
    {
        return mb_strlen($field) <= 120 && preg_match('/^[\p{L}\p{N}\s\-_\.]+$/u', $field) === 1;
    }

    /**
     * Store a newly created lead (File Upload).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        // Determine company_id (can be null)
        if ($user->isSuperAdmin() && $request->has('company_id')) {
            $companyId = $request->company_id;
        } else {
            $companyId = $user->company_id ?? null;
        }

        // Validate company_id if provided (for super admin)
        if ($user->isSuperAdmin() && $request->has('company_id') && !empty($request->company_id)) {
            $request->validate([
                'company_id' => 'exists:companies,id',
            ]);
        }

        // Do not use Laravel's strict 'mimes' rule here. Spreadsheet files are
        // commonly reported with different MIME types by browsers, PHP
        // Fileinfo, and web servers (especially legacy .xls files). Validate
        // the extension explicitly, then validate the actual contents while
        // parsing below.
        $request->validate([
            'file' => 'required|file',
            'category' => 'required|string|max:255',
            'format' => 'nullable|string|in:csv,excel',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, self::IMPORT_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file must be a CSV, TXT, XLS, or XLSX spreadsheet.'],
            ]);
        }

        // The extension is authoritative so a stale/missing UI format value
        // cannot cause an Excel workbook to be sent through the CSV parser.
        $format = in_array($extension, ['csv', 'txt'], true) ? 'csv' : 'excel';
        $category = (string) $request->input('category');

        if (! $user->isSuperAdmin() && ! $this->userCanAccessLeadCategory($user, $category)) {
            throw ValidationException::withMessages([
                'category' => ['You can only upload leads into categories granted to your company.'],
            ]);
        }

        $storedPath = null;
        try {
            $storedPath = $file->store('lead-imports');
            if (! $storedPath) {
                throw new \RuntimeException('The uploaded spreadsheet could not be stored.');
            }

            $import = LeadImport::create([
                'user_id' => $user->id,
                'company_id' => $companyId,
                'file_name' => $fileName,
                'stored_path' => $storedPath,
                'file_format' => $format,
                'category' => $category,
                'status' => 'queued',
            ]);

            ProcessLeadImport::dispatch($import->id)->onConnection('database');

            Log::info('Lead import queued', [
                'import_id' => $import->id,
                'file_name' => $fileName,
                'file_format' => $format,
                'company_id' => $companyId,
            ]);

            return response()->json([
                'message' => 'Import queued',
                'import_id' => $import->id,
                'file_name' => $fileName,
                'status' => $import->status,
            ], 202);
        } catch (\Throwable $e) {
            if ($storedPath && Storage::exists($storedPath)) {
                Storage::delete($storedPath);
            }
            Log::error('Lead file upload failed', [
                'file_name' => $fileName,
                'extension' => $extension,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'Failed to queue the uploaded spreadsheet. Please try again.',
            ], 422);
        }
    }

    /** Return the authenticated user's import status. */
    public function importStatus(Request $request, LeadImport $leadImport)
    {
        $user = $request->user();
        if (! $user->isSuperAdmin() && $leadImport->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        return response()->json([
            'id' => $leadImport->id,
            'file_name' => $leadImport->file_name,
            'status' => $leadImport->status,
            'total_rows' => $leadImport->total_rows,
            'processed_rows' => $leadImport->processed_rows,
            'imported_count' => $leadImport->imported_count,
            'error_count' => $leadImport->error_count,
            'error_message' => $leadImport->error_message,
            'started_at' => $leadImport->started_at,
            'completed_at' => $leadImport->completed_at,
        ]);
    }

    /** Process a queued import in a worker, outside the browser request. */
    public function processQueuedImport(int $importId): void
    {
        $import = LeadImport::findOrFail($importId);
        if (in_array($import->status, ['completed', 'failed'], true)) {
            return;
        }

        // A worker may be restarted after a timeout while this import is still
        // marked as processing. Remove only rows created by this import so a
        // retry always starts from a clean, idempotent state.
        Lead::where('lead_import_id', $import->id)->delete();

        $import->update([
            'status' => 'processing',
            'started_at' => $import->started_at ?? now(),
            'total_rows' => null,
            'processed_rows' => 0,
            'imported_count' => 0,
            'error_count' => 0,
            'error_message' => null,
        ]);
        Log::info('Lead import processing started', [
            'import_id' => $import->id,
            'file_name' => $import->file_name,
        ]);

        try {
            $absolutePath = Storage::path($import->stored_path);
            if (! is_readable($absolutePath)) {
                throw new \RuntimeException('The stored spreadsheet could not be read.');
            }

            $uploaded = new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $absolutePath,
                $import->file_name,
                null,
                UPLOAD_ERR_OK,
                true
            );
            $data = $this->parseFile($uploaded, $import->file_format, strtolower(pathinfo($import->file_name, PATHINFO_EXTENSION)));
            $headers = $data['headers'] ?? [];
            $records = $data['records'] ?? [];
            $import->update(['total_rows' => count($records)]);

            $processed = 0;
            $imported = 0;
            foreach (array_chunk($records, 200) as $recordChunk) {
                $insertRows = [];
                foreach ($recordChunk as $row) {
                    $processed++;
                    $mapped = $this->mapImportRowToLead($headers, $row);
                    if ($mapped === null) {
                        continue;
                    }
                    $insertRows[] = [
                        'lead_import_id' => $import->id,
                        'company_id' => $import->company_id,
                        // Match DB varchar limits so one oversized cell cannot
                        // abort the whole chunk (and leave the import stuck).
                        'name' => $this->limitImportString($mapped['name'], 255),
                        'email' => $this->limitImportString($mapped['email'], 255),
                        'phone' => $this->limitImportString($mapped['phone'], 255),
                        'mobile' => $this->limitImportString($mapped['mobile'], 255),
                        'age' => $this->limitImportString($mapped['age'], 20),
                        'gender' => $this->limitImportString($mapped['gender'], 100),
                        'country' => $this->limitImportString($mapped['country'], 100),
                        'city' => $this->limitImportString($mapped['city'], 120),
                        'date_of_birth' => $mapped['date_of_birth'],
                        'intention' => $this->limitImportString($mapped['intention'], 255),
                        'source' => $this->limitImportString('import:'.$import->file_name, 255),
                        'status' => 'cold',
                        'category' => $this->limitImportString($import->category, 255),
                        'file_name' => $this->limitImportString($import->file_name, 255),
                        'file_format' => $this->limitImportString($import->file_format, 255),
                        'file_headers' => null,
                        'file_records' => null,
                        'raw_attributes' => json_encode(
                            $this->limitImportAttributes($mapped['raw_attributes'] ?? [])
                        ),
                        'value' => null,
                        'assigned_to' => $this->limitImportString((string) $import->user_id, 255),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if ($insertRows !== []) {
                    Lead::query()->insert($insertRows);
                    $imported += count($insertRows);
                }
                $import->update([
                    'processed_rows' => $processed,
                    'imported_count' => $imported,
                ]);
            }

            if ($imported === 0) {
                throw new \RuntimeException('No data rows found in file (all rows empty).');
            }

            $import->update([
                'status' => 'completed',
                'processed_rows' => $processed,
                'imported_count' => $imported,
                'completed_at' => now(),
            ]);
            Log::info('Lead import completed', [
                'import_id' => $import->id,
                'processed_rows' => $processed,
                'imported_count' => $imported,
            ]);
        } catch (\Throwable $e) {
            Lead::where('lead_import_id', $importId)->delete();
            $this->markQueuedImportFailed($importId, $e);
            throw $e;
        } finally {
            if (Storage::exists($import->stored_path)) {
                Storage::delete($import->stored_path);
            }
        }
    }

    public function markQueuedImportFailed(int $importId, \Throwable $exception): void
    {
        $import = LeadImport::find($importId);
        if (! $import) {
            return;
        }

        // Never rethrow from failure handling: a secondary DB error here used
        // to leave imports stuck in "processing" while the UI polled for minutes.
        try {
            Lead::where('lead_import_id', $importId)->delete();
        } catch (\Throwable $cleanupError) {
            Log::warning('Lead import cleanup failed after job error', [
                'import_id' => $importId,
                'error' => $cleanupError->getMessage(),
            ]);
        }

        $message = $this->summarizeImportException($exception);

        try {
            $import->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $statusError) {
            Log::error('Lead import could not be marked failed', [
                'import_id' => $importId,
                'original_error' => $message,
                'status_error' => $statusError->getMessage(),
            ]);
            try {
                DB::table('lead_imports')->where('id', $importId)->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($message, 0, 500),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $ignored) {
                // Last resort already logged above.
            }
        }

        try {
            if ($import->stored_path && Storage::exists($import->stored_path)) {
                Storage::delete($import->stored_path);
            }
        } catch (\Throwable $ignored) {
        }

        Log::error('Lead import job failed', [
            'import_id' => $importId,
            'file_name' => $import->file_name,
            'error' => $message,
            'exception' => get_class($exception),
        ]);
    }

    /** Keep import failure text short enough for lead_imports.error_message. */
    private function summarizeImportException(\Throwable $exception): string
    {
        $message = trim((string) $exception->getMessage());
        if ($message === '') {
            return get_class($exception);
        }

        // Prefer the actionable MySQL column hint over the full SQL dump.
        if (preg_match("/Data too long for column '([^']+)'/", $message, $match)) {
            return "Data too long for column '{$match[1]}'. Shorten that field in the spreadsheet and try again.";
        }

        if (preg_match('/SQLSTATE\[(.*?)\]:\s*([^\(]+)/', $message, $match)) {
            return trim('SQLSTATE['.$match[1].']: '.$match[2]);
        }

        return mb_substr($message, 0, 1000);
    }

    private function limitImportString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return mb_strlen($string) > $max ? mb_substr($string, 0, $max) : $string;
    }

    /**
     * Keep imported spreadsheet extras, but cap each value so one huge cell
     * (e.g. a 500+ char Category dump) cannot break the insert.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private function limitImportAttributes(array $attributes): array
    {
        $limited = [];
        foreach ($attributes as $key => $value) {
            $label = trim((string) $key);
            if ($label === '' || $value === null) {
                continue;
            }
            $string = trim((string) $value);
            if ($string === '') {
                continue;
            }
            $limited[mb_substr($label, 0, 120)] = mb_strlen($string) > 500
                ? mb_substr($string, 0, 500)
                : $string;
        }

        return $limited;
    }

    private function userCanAccessLeadCategory($user, string $category): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! $user->company_id) {
            return false;
        }

        $allowed = \App\Models\Company::query()
            ->whereKey($user->company_id)
            ->first()
            ?->allowedLeadCategoryNames() ?? [];

        return in_array($category, $allowed, true);
    }

    private function assertCanAccessLead($user, Lead $lead): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        if ($user->company_id && (int) $lead->company_id === (int) $user->company_id) {
            return;
        }
        if ($lead->category && $this->userCanAccessLeadCategory($user, (string) $lead->category)) {
            return;
        }
        abort(403, 'Access denied');
    }

    /**
     * Convert string to UTF-8 encoding, handling malformed characters.
     */
    private function convertToUtf8($string)
    {
        if (!is_string($string)) {
            return $string;
        }

        // Remove BOM if present
        $string = str_replace("\xEF\xBB\xBF", '', $string);
        
        // Check if already valid UTF-8
        if (mb_check_encoding($string, 'UTF-8')) {
            return trim($string);
        }

        // Try to detect encoding and convert
        $detectedEncoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
        
        if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
            $converted = mb_convert_encoding($string, 'UTF-8', $detectedEncoding);
            // If conversion failed, use iconv as fallback
            if ($converted === false) {
                $converted = @iconv($detectedEncoding, 'UTF-8//IGNORE', $string);
            }
            return trim($converted !== false ? $converted : $string);
        }

        // If detection failed, try to clean invalid UTF-8 characters
        return trim(mb_convert_encoding($string, 'UTF-8', 'UTF-8'));
    }

    /**
     * Map a CSV/Excel row to lead fields using flexible header matching (IT/EN).
     *
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $row
     * @return array{name: string, email: ?string, phone: ?string, mobile: ?string, age: ?string, gender: ?string, country: ?string, intention: ?string, raw_attributes: array<string, string>}|null
     */
    private function mapImportRowToLead(array $headers, array $row): ?array
    {
        $raw = [];
        foreach ($headers as $i => $headerLabel) {
            $label = is_string($headerLabel) ? trim($headerLabel) : '';
            $cell = isset($row[$i]) ? $this->convertToUtf8((string) $row[$i]) : '';
            $cell = trim($cell);
            if ($label !== '' && $cell !== '') {
                $rawKey = $label;
                $duplicate = 2;
                while (array_key_exists($rawKey, $raw)) {
                    $rawKey = $label.' ('.$duplicate.')';
                    $duplicate++;
                }
                $raw[$rawKey] = $cell;
            }
        }

        if ($raw === []) {
            return null;
        }

        $normPairs = [];
        foreach ($headers as $i => $headerLabel) {
            $norm = $this->normalizeHeaderToken((string) $headerLabel);
            if ($norm === '') {
                continue;
            }
            $cell = isset($row[$i]) ? trim($this->convertToUtf8((string) $row[$i])) : '';
            $normPairs[] = ['norm' => $norm, 'value' => $cell, 'label' => (string) $headerLabel];
        }

        $email = null;
        foreach ($normPairs as $pair) {
            if ($pair['value'] === '') {
                continue;
            }
            if ($this->headerLooksLikeEmail($pair['norm']) && filter_var($pair['value'], FILTER_VALIDATE_EMAIL)) {
                $email = $pair['value'];
                break;
            }
        }

        $phone = null;
        $mobile = null;
        foreach ($normPairs as $pair) {
            if ($pair['value'] === '') {
                continue;
            }
            if ($this->headerLooksLikeMobile($pair['norm'])) {
                if ($mobile === null) {
                    $mobile = $this->normalizePhone($pair['value']);
                }
                continue;
            }
            if ($this->headerLooksLikePhone($pair['norm']) && $phone === null) {
                $phone = $this->normalizePhone($pair['value']);
            }
        }

        // If a file has two generic phone columns, preserve the second one as
        // a candidate — Italian digit rules decide landline vs mobile below.
        if ($phone !== null && $mobile === null) {
            foreach ($normPairs as $pair) {
                if ($pair['value'] === '' || ! $this->headerLooksLikePhone($pair['norm'])) {
                    continue;
                }
                $candidate = $this->normalizePhone($pair['value']);
                if ($candidate !== $phone) {
                    $mobile = $candidate;
                    break;
                }
            }
        }

        // Piero rule: landlines (0…) → Telephone; mobiles (3…) → Mobile.
        $separated = ItalianPhoneNumber::separate($phone, $mobile);
        $phone = $separated['phone'];
        $mobile = $separated['mobile'];

        $dateOfBirth = $this->firstImportValue($normPairs, fn (string $norm): bool => $this->headerLooksLikeDateOfBirth($norm));
        $normalizedDateOfBirth = $this->normalizeDateOfBirth($dateOfBirth);
        $age = $this->firstImportValue($normPairs, fn (string $norm): bool => $this->headerLooksLikeAge($norm));
        if ($age !== null) {
            $age = $this->normalizeAge($age);
        }
        if ($age === null) {
            $age = $this->calculateAgeFromDateValue($dateOfBirth);
        }

        $gender = $this->firstImportValue($normPairs, fn (string $norm): bool => $this->headerLooksLikeGender($norm));
        $country = $this->firstImportValue($normPairs, fn (string $norm): bool => $this->headerLooksLikeCountry($norm));
        $city = $this->firstImportValue($normPairs, fn (string $norm): bool => $this->headerLooksLikeCity($norm));
        $intention = $this->firstImportValue($normPairs, fn (string $norm): bool => $this->headerLooksLikeIntention($norm));

        $name = $this->pickNameFromRow($normPairs);
        if ($name === '') {
            $name = $email ?? $phone ?? $mobile ?? reset($raw) ?: 'Unnamed lead';
        }

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'mobile' => $mobile,
            'age' => $age,
            'gender' => $gender,
            'country' => $country,
            'city' => $city,
            'date_of_birth' => $normalizedDateOfBirth,
            'intention' => $intention,
            'raw_attributes' => $raw,
        ];
    }

    /** @param array<int, array{norm: string, value: string, label: string}> $normPairs */
    private function firstImportValue(array $normPairs, callable $matcher): ?string
    {
        foreach ($normPairs as $pair) {
            if ($pair['value'] !== '' && $matcher($pair['norm'])) {
                return $pair['value'];
            }
        }

        return null;
    }

    private function normalizeHeaderToken(string $header): string
    {
        $h = $this->convertToUtf8($header);
        $h = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $h);
        $h = mb_strtolower(trim($h));

        // Match headers such as Città and Citta while preserving the original
        // header label in raw_attributes.
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($h, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $h = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $h;
            }
        } else {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h);
            if ($transliterated !== false) {
                $h = $transliterated;
            }
        }

        $h = str_replace(['_', '-', '/', '\\', '.', ':'], ' ', $h);
        $h = preg_replace('/\s+/u', ' ', $h) ?? $h;

        return $h;
    }

    private function headerLooksLikeEmail(string $norm): bool
    {
        $compact = str_replace(' ', '', $norm);
        if (in_array($compact, ['mail', 'email'], true)) {
            return true;
        }
        if (str_contains($compact, 'email') || str_contains($compact, 'mail')) {
            return true;
        }
        if (str_contains($norm, 'pec')) {
            return true;
        }

        return false;
    }

    private function headerLooksLikePhone(string $norm): bool
    {
        if ($this->headerLooksLikeMobile($norm)) {
            return false;
        }

        foreach (['telefono', 'telephone', 'phone', 'tel', 'landline', 'fisso', 'fax'] as $token) {
            if (str_contains($norm, $token)) {
                return true;
            }
        }

        return false;
    }

    private function headerLooksLikeMobile(string $norm): bool
    {
        $compact = str_replace(' ', '', $norm);
        foreach (['telefonino', 'cellulare', 'mobile', 'gsm', 'cell', 'whatsapp', 'phone2', 'secondphone', 'secondaryphone'] as $token) {
            if (str_contains($compact, $token)) {
                return true;
            }
        }

        return false;
    }

    private function headerLooksLikeAge(string $norm): bool
    {
        return in_array($norm, ['age', 'eta', 'anni'], true) || str_contains($norm, 'age');
    }

    private function headerLooksLikeDateOfBirth(string $norm): bool
    {
        return str_contains($norm, 'data nascita')
            || str_contains($norm, 'date of birth')
            || str_contains($norm, 'birth date')
            || $norm === 'dob';
    }

    private function headerLooksLikeGender(string $norm): bool
    {
        return in_array($norm, ['gender', 'sesso', 'sex'], true);
    }

    private function headerLooksLikeCountry(string $norm): bool
    {
        return in_array($norm, ['country', 'paese', 'nazione', 'stato'], true)
            || str_contains($norm, 'country');
    }

    private function headerLooksLikeCity(string $norm): bool
    {
        return in_array($norm, ['city', 'citta', 'comune', 'localita', 'municipality'], true)
            || str_contains($norm, 'city')
            || str_contains($norm, 'citta');
    }

    private function headerLooksLikeIntention(string $norm): bool
    {
        return str_contains($norm, 'intention')
            || str_contains($norm, 'intenzione')
            || str_contains($norm, 'interesse')
            || str_contains($norm, 'interest')
            || $norm === 'intent'
            || str_contains($norm, 'lead intent');
    }

    private function normalizeAge(string $value): ?string
    {
        if (preg_match('/\b(\d{1,3})\b/', $value, $matches) !== 1) {
            return null;
        }

        $age = (int) $matches[1];
        return $age >= 0 && $age <= 130 ? (string) $age : null;
    }

    private function calculateAgeFromDateValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $birthDate = null;
        if (is_numeric($raw) && (float) $raw > 1000) {
            try {
                $birthDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
            } catch (\Throwable) {
                $birthDate = null;
            }
        }

        if (! $birthDate) {
            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y', 'd.m.Y'] as $format) {
                $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
                if ($parsed !== false) {
                    $birthDate = $parsed;
                    break;
                }
            }
        }

        if (! $birthDate) {
            try {
                $birthDate = new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        $today = new \DateTimeImmutable('today');
        if ($birthDate > $today) {
            return null;
        }

        $age = $birthDate->diff($today)->y;
        return $age <= 130 ? (string) $age : null;
    }

    /** Convert supported spreadsheet birth-date values to a database-safe ISO date. */
    private function normalizeDateOfBirth(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $birthDate = null;
        if (is_numeric($raw) && (float) $raw > 1000) {
            try {
                $birthDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
            } catch (\Throwable) {
                $birthDate = null;
            }
        }

        if (! $birthDate) {
            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y', 'd.m.Y'] as $format) {
                $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
                if ($parsed !== false) {
                    $birthDate = $parsed;
                    break;
                }
            }
        }

        if (! $birthDate) {
            try {
                $birthDate = new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        $today = new \DateTimeImmutable('today');
        if ($birthDate > $today || $birthDate->diff($today)->y > 130) {
            return null;
        }

        return $birthDate->format('Y-m-d');
    }

    /**
     * Prefer company-style fields, then person name fields, then first non-empty "name-like" column.
     *
     * @param  array<int, array{norm: string, value: string, label: string}>  $normPairs
     */
    private function pickNameFromRow(array $normPairs): string
    {
        $firstName = '';
        $lastName = '';
        foreach ($normPairs as $pair) {
            if ($pair['value'] === '') {
                continue;
            }
            if ($this->isFirstNameHeader($pair['norm']) && $firstName === '') {
                $firstName = $pair['value'];
            }
            if ($this->isLastNameHeader($pair['norm']) && $lastName === '') {
                $lastName = $pair['value'];
            }
        }

        if ($firstName !== '' || $lastName !== '') {
            return trim($firstName.' '.$lastName);
        }

        $priorityFragments = [
            ['ragione sociale'],
            ['insegna'],
            ['denominazione'],
            ['company', 'name'],
            ['company'],
            ['azienda'],
            ['business', 'name'],
            ['nome', 'completo'],
            ['full', 'name'],
            ['cognome', 'nome'],
            ['nome', 'cognome'],
            ['nome', 'e', 'cognome'],
            ['first', 'name'],
            ['last', 'name'],
            ['name'],
            ['contact'],
        ];

        foreach ($priorityFragments as $fragments) {
            foreach ($normPairs as $pair) {
                if ($pair['value'] === '') {
                    continue;
                }
                $ok = true;
                foreach ($fragments as $f) {
                    if (! str_contains($pair['norm'], $f)) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    return $pair['value'];
                }
            }
        }

        foreach ($normPairs as $pair) {
            if ($pair['value'] === '') {
                continue;
            }
            if ($this->headerLooksLikeEmail($pair['norm']) || $this->headerLooksLikePhone($pair['norm']) || $this->headerLooksLikeMobile($pair['norm'])) {
                continue;
            }
            if (str_contains($pair['norm'], 'name') || str_contains($pair['norm'], 'nome') || str_contains($pair['norm'], 'cognome')) {
                return $pair['value'];
            }
        }

        return '';
    }

    private function isFirstNameHeader(string $norm): bool
    {
        return in_array($norm, ['nome', 'first name', 'firstname', 'given name'], true)
            || str_contains($norm, 'first name');
    }

    private function isLastNameHeader(string $norm): bool
    {
        return in_array($norm, ['cognome', 'last name', 'lastname', 'surname', 'family name'], true)
            || str_contains($norm, 'last name')
            || str_contains($norm, 'surname');
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/[^\d+]/', '', $value) ?? '';

        return $digits !== '' ? $digits : trim($value);
    }

    /**
     * Choose CSV delimiter from a header line.
     * Prefer the delimiter that yields real CRM headers (Name/Email/Phone).
     * Italian Excel often uses ';', while Piro exports use ','.
     */
    private function detectCsvDelimiter(string $firstLine): string
    {
        $firstLine = str_replace("\xEF\xBB\xBF", '', $firstLine);
        $firstLine = rtrim($firstLine, "\r\n");
        if ($firstLine === '') {
            return ',';
        }
        // Excel often prepends "sep=;" or "sep=,".
        if (preg_match('/^sep=(.)$/i', $firstLine, $match)) {
            return $match[1];
        }

        $scores = [];
        foreach ([',', ';', "\t"] as $delimiter) {
            $cols = str_getcsv($firstLine, $delimiter);
            $norms = array_map(fn ($h) => $this->normalizeHeaderToken($this->convertToUtf8((string) $h)), $cols);
            $score = count($cols);
            if ($this->headersLookLikeLeadCsv($norms)) {
                $score += 100;
            }
            $scores[$delimiter] = $score;
        }

        arsort($scores);
        $best = array_key_first($scores);

        return is_string($best) && ($scores[$best] ?? 0) > 1 ? $best : ',';
    }

    /**
     * @param  list<string>  $norms
     */
    private function headersLookLikeLeadCsv(array $norms): bool
    {
        $joined = ' '.implode(' ', $norms).' ';
        $hasName = str_contains($joined, ' name ') || str_contains($joined, ' nome ') || str_contains($joined, ' company ');
        $hasContact = str_contains($joined, ' email ') || str_contains($joined, ' phone ') || str_contains($joined, ' telefono ') || str_contains($joined, ' mail ');

        return $hasName && $hasContact;
    }

    /**
     * True when rows were collapsed into column 0 (wrong delimiter), e.g. whole CSV line in Name.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $records
     */
    private function csvLooksCollapsed(array $headers, array $records): bool
    {
        if ($records === []) {
            return false;
        }
        $sample = array_slice($records, 0, min(30, count($records)));
        $collapsed = 0;
        foreach ($sample as $row) {
            if ($this->rowLooksCollapsed($row)) {
                $collapsed++;
            }
        }

        return $collapsed >= max(1, (int) floor(count($sample) * 0.5));
    }

    /**
     * @param  list<string>  $row
     */
    private function rowLooksCollapsed(array $row): bool
    {
        $nonEmpty = 0;
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                $nonEmpty++;
            }
        }
        $first = trim((string) ($row[0] ?? ''));

        return $nonEmpty <= 1
            && (substr_count($first, ',') >= 3 || substr_count($first, ';') >= 3);
    }

    /**
     * Split a collapsed first-cell CSV line back into real columns.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $records
     * @return list<list<string>>
     */
    private function repairCollapsedCsvRecords(array $headers, array $records): array
    {
        $headerCount = max(1, count($headers));
        $repaired = [];

        foreach ($records as $row) {
            if (! $this->rowLooksCollapsed($row)) {
                $repaired[] = $row;
                continue;
            }

            $blob = trim((string) ($row[0] ?? ''));
            $parts = null;
            foreach ([',', ';'] as $delimiter) {
                $candidate = str_getcsv($blob, $delimiter);
                if (count($candidate) >= min(4, $headerCount)) {
                    $parts = $candidate;
                    break;
                }
            }
            if ($parts === null) {
                $repaired[] = $row;
                continue;
            }

            while (count($parts) < $headerCount) {
                $parts[] = '';
            }
            if (count($parts) > $headerCount) {
                $parts = array_slice($parts, 0, $headerCount);
            }
            $repaired[] = array_map(fn ($cell) => $this->convertToUtf8((string) $cell), $parts);
        }

        return $repaired;
    }

    /**
     * @return array{headers: list<string>, records: list<list<string>>}
     */
    private function parseCsvWithDelimiter(string $path, string $delimiter): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \Exception('Unable to open CSV file');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine !== false && preg_match('/^sep=/i', ltrim(str_replace("\xEF\xBB\xBF", '', $firstLine)))) {
                // Excel preamble like "sep=;" — skip it and keep chosen delimiter.
            } else {
                rewind($handle);
            }

            $headers = fgetcsv($handle, null, $delimiter);
            if ($headers === false) {
                throw new \Exception('Unable to read headers from CSV file');
            }

            $headers = array_map(function ($header) {
                return trim(str_replace("\xEF\xBB\xBF", '', $this->convertToUtf8($header)));
            }, $headers);

            $records = [];
            while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
                if (! array_filter($row, fn ($cell) => trim((string) $cell) !== '')) {
                    continue;
                }
                $row = array_map(function ($cell) {
                    return $this->convertToUtf8($cell);
                }, $row);
                while (count($row) < count($headers)) {
                    $row[] = '';
                }
                if (count($row) > count($headers)) {
                    $row = array_slice($row, 0, count($headers));
                }
                $records[] = $row;
            }

            return ['headers' => $headers, 'records' => $records];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse the uploaded file based on format.
     */
    private function parseFile($file, $format, ?string $extension = null)
    {
        $headers = [];
        $records = [];

        if ($format === 'csv') {
            $path = $file->getRealPath();
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new \Exception('Unable to open CSV file');
            }
            $firstLine = fgets($handle);
            fclose($handle);
            if ($firstLine === false) {
                throw new \Exception('Unable to read CSV file');
            }

            $delimiter = $this->detectCsvDelimiter($firstLine);
            $parsed = $this->parseCsvWithDelimiter($path, $delimiter);

            // If Excel/locale sniffer picked ';' but the file is comma CSV (Piro),
            // every row collapses into Name. Detect and re-parse with ','.
            if ($delimiter !== ',' && $this->csvLooksCollapsed($parsed['headers'], $parsed['records'])) {
                $parsed = $this->parseCsvWithDelimiter($path, ',');
            } elseif ($delimiter === ',' && $this->csvLooksCollapsed($parsed['headers'], $parsed['records'])) {
                $retry = $this->parseCsvWithDelimiter($path, ';');
                if (! $this->csvLooksCollapsed($retry['headers'], $retry['records'])) {
                    $parsed = $retry;
                }
            }

            // Last resort: header columns are fine but each data row was stored as
            // one quoted cell containing the full comma/semicolon CSV line.
            if ($this->csvLooksCollapsed($parsed['headers'], $parsed['records'])) {
                $parsed['records'] = $this->repairCollapsedCsvRecords(
                    $parsed['headers'],
                    $parsed['records']
                );
            }

            $headers = $parsed['headers'];
            $records = $parsed['records'];
        } else {
            // Excel format using PhpSpreadsheet
            try {
                $path = $file->getRealPath();
                if (!$path || !is_readable($path)) {
                    throw new \RuntimeException('The uploaded file could not be read by the server.');
                }

                $extension = strtolower($extension ?: $file->getClientOriginalExtension());
                if ($extension === 'xlsx') {
                    return $this->parseXlsxStreaming($path);
                }

                // Normalize every Excel scalar to a string. Excel commonly
                // stores phone numbers, IDs, and other cells as integers.
                // Returning an integer from a string-typed closure causes an
                // Excel-only TypeError during import.
                $normalizeCell = function ($cellValue): string {
                    if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $cellValue = $cellValue->getPlainText();
                    }

                    if (is_array($cellValue) || is_object($cellValue)) {
                        $cellValue = '';
                    }

                    return (string) $this->convertToUtf8($cellValue ?? '');
                };

                // Find the first worksheet without loading all workbook data.
                // Only one sheet is relevant for a lead import.
                $probeReader = IOFactory::createReaderForFile($path);
                $probeReader->setReadDataOnly(true);
                $probeReader->setReadEmptyCells(false);
                $probeReader->setIncludeCharts(false);
                $sheetNames = $probeReader->listWorksheetNames($path);
                $sheetName = $sheetNames[0] ?? null;
                if (!$sheetName) {
                    throw new \Exception('Excel file does not contain a worksheet');
                }

                // Load only the header row first. This avoids PhpSpreadsheet
                // creating cells for the entire workbook before we know the
                // table width.
                $headerReader = IOFactory::createReaderForFile($path);
                $headerReader->setReadDataOnly(true);
                $headerReader->setReadEmptyCells(false);
                $headerReader->setIncludeCharts(false);
                $headerReader->setLoadSheetsOnly($sheetName);
                $headerReader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        return $row === 1;
                    }
                });

                $spreadsheet = $headerReader->load($path);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestColumn = $worksheet->getHighestDataColumn();
                $headerRows = $worksheet->rangeToArray(
                    'A1:'.$highestColumn.'1',
                    null,
                    false,
                    false,
                    false
                );
                $headers = array_map($normalizeCell, $headerRows[0] ?? []);

                // Remove empty trailing headers
                while (!empty($headers) && empty(end($headers))) {
                    array_pop($headers);
                }

                if (empty($headers)) {
                    throw new \Exception('No headers found in Excel file');
                }

                unset($headerRows, $worksheet, $spreadsheet, $headerReader, $probeReader);

                // Read the body in bounded chunks. This supports both legacy
                // XLS and modern XLSX while preventing large/styled workbooks
                // from exhausting the PHP-FPM worker and becoming a browser
                // "network error".
                $chunkSize = 1000;
                $startRow = 2;
                $maxColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));

                while (true) {
                    $endRow = $startRow + $chunkSize - 1;
                    $reader = IOFactory::createReaderForFile($path);
                    $reader->setReadDataOnly(true);
                    $reader->setReadEmptyCells(false);
                    $reader->setIncludeCharts(false);
                    $reader->setLoadSheetsOnly($sheetName);
                    $reader->setReadFilter(new class($startRow, $endRow, count($headers)) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                        public function __construct(
                            private int $startRow,
                            private int $endRow,
                            private int $maxColumn
                        ) {}

                        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                        {
                            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress);
                            return $row >= $this->startRow
                                && $row <= $this->endRow
                                && $column <= $this->maxColumn;
                        }
                    });

                    $spreadsheet = $reader->load($path);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $highestDataRow = $worksheet->getHighestDataRow();

                    if ($highestDataRow < $startRow) {
                        unset($worksheet, $spreadsheet, $reader);
                        break;
                    }

                    foreach ($worksheet->rangeToArrayYieldRows(
                        'A'.$startRow.':'.$maxColumn.$highestDataRow,
                        null,
                        false,
                        false,
                        false
                    ) as $row) {
                        $record = array_map($normalizeCell, array_slice($row, 0, count($headers)));

                        while (count($record) < count($headers)) {
                            $record[] = '';
                        }

                        if (array_filter($record, static fn ($value) => trim($value) !== '')) {
                            $records[] = $record;
                        }
                    }

                    $loadedThrough = $highestDataRow;
                    unset($worksheet, $spreadsheet, $reader);

                    if ($loadedThrough < $endRow) {
                        break;
                    }

                    $startRow = $endRow + 1;
                }
            } catch (\Throwable $e) {
                throw new \Exception('Failed to parse Excel file: ' . $e->getMessage());
            }
        }

        if (empty($headers)) {
            throw new \Exception('No headers found in file');
        }

        return [
            'headers' => $headers,
            'records' => $records
        ];
    }

    /**
     * Parse modern XLSX files through their XML parts in one pass. This avoids
     * reopening a large workbook once per row chunk, which can exceed a PHP
     * request timeout even when the workbook itself is valid.
     *
     * @return array{headers: array<int, string>, records: array<int, array<int, string>>}
     */
    private function parseXlsxStreaming(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The XLSX container could not be opened.');
        }

        try {
            $sharedStrings = $this->readXlsxSharedStrings($zip);
            $sheetEntry = $this->findFirstXlsxWorksheetEntry($zip);
            $sheetXml = $zip->getFromName($sheetEntry);
            if ($sheetXml === false) {
                throw new \RuntimeException('The XLSX worksheet could not be read.');
            }

            $reader = new \XMLReader();
            if (! $reader->XML($sheetXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new \RuntimeException('The XLSX worksheet XML is invalid.');
            }

            $headers = [];
            $records = [];
            $maxColumns = 0;

            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowDepth = $reader->depth;
                $row = [];

                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::END_ELEMENT
                        && $reader->localName === 'row'
                        && $reader->depth === $rowDepth) {
                        break;
                    }

                    if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'c') {
                        continue;
                    }

                    $cellDepth = $reader->depth;
                    $cellReference = (string) $reader->getAttribute('r');
                    $cellType = (string) $reader->getAttribute('t');
                    $cellValue = '';

                    if (! $reader->isEmptyElement) {
                        while ($reader->read()) {
                            if ($reader->nodeType === \XMLReader::END_ELEMENT
                                && $reader->localName === 'c'
                                && $reader->depth === $cellDepth) {
                                break;
                            }

                            if ($reader->nodeType === \XMLReader::ELEMENT
                                && ($reader->localName === 'v' || $reader->localName === 't')) {
                                $cellValue .= $reader->readString();
                            }
                        }
                    }

                    if (! preg_match('/^([A-Z]+)/i', $cellReference, $matches)) {
                        continue;
                    }

                    $columnIndex = $this->xlsxColumnIndex($matches[1]);
                    if ($columnIndex < 0) {
                        continue;
                    }

                    $cellValue = match ($cellType) {
                        's' => isset($sharedStrings[(int) $cellValue]) ? $sharedStrings[(int) $cellValue] : '',
                        'inlineStr' => $cellValue,
                        'b' => $cellValue === '1' ? '1' : '0',
                        default => $cellValue,
                    };
                    $row[$columnIndex] = $this->convertToUtf8($cellValue);
                    $maxColumns = max($maxColumns, $columnIndex + 1);
                }

                $rowWidth = $row === [] ? 0 : (max(array_keys($row)) + 1);
                $normalizedRow = array_fill(0, $rowWidth, '');
                foreach ($row as $columnIndex => $value) {
                    $normalizedRow[$columnIndex] = $value;
                }
                $row = $normalizedRow;

                if ($headers === []) {
                    $headers = $row;
                    while (! empty($headers) && trim((string) end($headers)) === '') {
                        array_pop($headers);
                    }
                    $maxColumns = count($headers);
                    if ($maxColumns === 0) {
                        throw new \RuntimeException('No headers found in XLSX file.');
                    }
                    continue;
                }

                $record = array_slice($row, 0, $maxColumns);
                while (count($record) < $maxColumns) {
                    $record[] = '';
                }
                if (array_filter($record, static fn ($value) => trim((string) $value) !== '')) {
                    $records[] = $record;
                }
            }

            $reader->close();

            return ['headers' => $headers, 'records' => $records];
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function readXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }

        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($root === false) {
            throw new \RuntimeException('The XLSX shared strings are invalid.');
        }

        $out = [];
        $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        foreach ($root->children($mainNamespace)->si as $item) {
            $value = '';
            foreach ($item->children($mainNamespace) as $child) {
                if ($child->getName() === 't') {
                    $value .= (string) $child;
                    continue;
                }
                if ($child->getName() !== 'r') {
                    continue;
                }
                foreach ($child->children($mainNamespace)->t as $text) {
                    $value .= (string) $text;
                }
            }
            $out[] = $value;
        }

        return $out;
    }

    private function findFirstXlsxWorksheetEntry(\ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relationshipsXml === false) {
            throw new \RuntimeException('The XLSX workbook metadata is missing.');
        }

        $workbook = simplexml_load_string($workbookXml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        $relationships = simplexml_load_string($relationshipsXml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($workbook === false || $relationships === false) {
            throw new \RuntimeException('The XLSX workbook metadata is invalid.');
        }

        $workbook->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $firstSheet = ($workbook->xpath('//x:sheets/x:sheet') ?: [])[0] ?? null;
        if ($firstSheet === null) {
            throw new \RuntimeException('The XLSX file contains no worksheets.');
        }

        $relationshipId = (string) ($firstSheet->attributes('r', true)->id ?? '');
        foreach ($relationships->Relationship as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) {
                continue;
            }

            $target = str_replace('\\', '/', (string) $relationship['Target']);
            $target = ltrim($target, '/');
            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        throw new \RuntimeException('The first XLSX worksheet relationship is missing.');
    }

    private function xlsxColumnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Display the specified lead.
     */
    public function show(Request $request, Lead $lead)
    {
        $this->assertCanAccessLead($request->user(), $lead);

        return response()->json($lead);
    }

    /**
     * Update the specified lead.
     */
    public function update(Request $request, Lead $lead)
    {
        $user = $request->user();
        $this->assertCanAccessLead($user, $lead);

        $validated = $request->validate([
            'status' => 'sometimes|string',
            'category' => 'sometimes|string',
            'assigned_to' => 'sometimes|exists:users,id',
        ]);

        if (isset($validated['category']) && ! $user->isSuperAdmin()
            && ! $this->userCanAccessLeadCategory($user, (string) $validated['category'])) {
            abort(403, 'Access denied for this category');
        }

        $lead->update($validated);

        return response()->json($lead);
    }

    /**
     * Remove the specified lead.
     */
    public function destroy(Request $request, Lead $lead)
    {
        $this->assertCanAccessLead($request->user(), $lead);

        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully'], 204);
    }

    /**
     * Export leads to CSV
     */
    public function export(Request $request)
    {
        $user = $request->user();
        if ($user->isSuperAdmin() && $request->filled('company_id')) {
            $request->validate(['company_id' => 'integer|exists:companies,id']);
        }
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : ($user->company_id ?? null);

        if ($user->isSuperAdmin() && $request->filled('company_id')) {
            $query = Lead::where('company_id', $companyId);
        } else {
            $query = Lead::query();
        }

        // Apply filters (same as index method)
        if ($request->has('search') && $request->search) {
            $this->applyLeadSearch($query, (string) $request->search);
        }

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        if ($request->has('category') && $request->category !== 'all') {
            $category = (string) $request->category;
            if (! $user->isSuperAdmin() && ! $this->userCanAccessLeadCategory($user, $category)) {
                abort(403, 'Access denied for this category');
            }
            $query->where('category', $category);
        }

        $this->applyLeadFieldFilters($query, $request);
        $this->applyImportFiltersFromRequest($query, $request);

        // Get all leads (no pagination for export)
        $leads = $query->orderBy('created_at', 'desc')->get();

        // Prepare CSV data
        $csvData = [];
        $headers = ['ID', 'Name', 'Email', 'Phone', 'Mobile', 'Age', 'Gender', 'Country', 'Intention', 'Source', 'Status', 'Category', 'File Name', 'Assigned To', 'Value', 'Created At'];
        $csvData[] = $headers;

        foreach ($leads as $lead) {
            $row = [
                $lead->id,
                $lead->name ?? '',
                $lead->email ?? '',
                $lead->phone ?? '',
                $lead->mobile ?? '',
                $lead->age ?? '',
                $lead->gender ?? '',
                $lead->country ?? '',
                $lead->intention ?? '',
                $lead->source ?? '',
                $lead->status ?? '',
                $lead->category ?? '',
                $lead->file_name ?? '',
                $lead->assigned_to ?? '',
                $lead->value ?? '',
                $lead->created_at ? $lead->created_at->format('Y-m-d H:i:s') : '',
            ];
            $csvData[] = $row;
        }

        // Generate CSV content
        $filename = 'leads_export_' . date('Y-m-d_His') . '.csv';
        $handle = fopen('php://temp', 'r+');
        
        // Add BOM for UTF-8
        fwrite($handle, "\xEF\xBB\xBF");
        
        foreach ($csvData as $row) {
            fputcsv($handle, $row);
        }
        
        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response($csvContent)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
