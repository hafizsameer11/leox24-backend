<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LeadController extends Controller
{
    use HandlesApiErrors;

    /**
     * Display a listing of leads.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : ($user->company_id ?? null);

        // Build query based on company_id
        if ($user->isSuperAdmin() && !$request->has('company_id')) {
            // Super admin without company_id filter - show all leads
            $query = Lead::query();
        } elseif ($companyId === null) {
            // User with no company_id - show only leads with null company_id
            $query = Lead::whereNull('company_id');
        } else {
            // User with company_id - show only their company's leads
            $query = Lead::where('company_id', $companyId);
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

        // Apply category filter
        if ($request->has('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        $this->applyImportFiltersFromRequest($query, $request);

        // Use explicit orderBy with index for better performance
        // This prevents MySQL from sorting all rows before pagination
        $leads = $query->orderBy('created_at', 'desc')
                      ->paginate($request->get('per_page', 15));

        return response()->json($leads);
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
                ->orWhere('name', 'like', $like)
                ->orWhere('file_name', 'like', $like)
                ->orWhere(function ($q2) use ($like) {
                    $q2->whereNotNull('file_records')
                        ->where('file_records', 'like', $like);
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

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls',
            'category' => 'required|string|max:255',
            'format' => 'required|string|in:csv,excel',
        ]);

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $format = $request->input('format');
        $category = $request->input('category');

        try {
            $data = $this->parseFile($file, $format);

            if (empty($data['headers']) || empty($data['records'])) {
                return response()->json(['message' => 'File is empty or invalid format'], 400);
            }

            $headers = $data['headers'];
            $records = $data['records'];
            $assigned = (string) $user->id;
            $now = now();

            $insertRows = [];
            foreach ($records as $row) {
                $mapped = $this->mapImportRowToLead($headers, $row);
                if ($mapped === null) {
                    continue;
                }

                $insertRows[] = [
                    'company_id' => $companyId,
                    'name' => $mapped['name'],
                    'email' => $mapped['email'],
                    'phone' => $mapped['phone'],
                    'source' => 'import:'.$fileName,
                    'status' => 'cold',
                    'category' => $category,
                    'file_name' => $fileName,
                    'file_format' => $format,
                    'file_headers' => null,
                    'file_records' => null,
                    'raw_attributes' => json_encode($mapped['raw_attributes']),
                    'value' => null,
                    'assigned_to' => $assigned,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (empty($insertRows)) {
                return response()->json(['message' => 'No data rows found in file (all rows empty).'], 400);
            }

            DB::transaction(function () use ($insertRows) {
                foreach (array_chunk($insertRows, 200) as $chunk) {
                    Lead::query()->insert($chunk);
                }
            });

            return response()->json([
                'message' => 'Import completed',
                'imported_count' => count($insertRows),
                'file_name' => $fileName,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Lead file upload failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to process file: ' . $e->getMessage()], 500);
        }
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
     * @return array{name: string, email: ?string, phone: ?string, raw_attributes: array<string, string>}|null
     */
    private function mapImportRowToLead(array $headers, array $row): ?array
    {
        $raw = [];
        foreach ($headers as $i => $headerLabel) {
            $label = is_string($headerLabel) ? trim($headerLabel) : '';
            $cell = isset($row[$i]) ? $this->convertToUtf8((string) $row[$i]) : '';
            $cell = trim($cell);
            if ($label !== '' && $cell !== '') {
                $raw[$label] = $cell;
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
        foreach ($normPairs as $pair) {
            if ($pair['value'] === '') {
                continue;
            }
            if ($this->headerLooksLikePhone($pair['norm'])) {
                $phone = $this->normalizePhone($pair['value']);
                break;
            }
        }

        $name = $this->pickNameFromRow($normPairs);
        if ($name === '') {
            $name = $email ?? $phone ?? reset($raw) ?: 'Unnamed lead';
        }

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'raw_attributes' => $raw,
        ];
    }

    private function normalizeHeaderToken(string $header): string
    {
        $h = mb_strtolower(trim($header));
        $h = preg_replace('/\s+/u', ' ', $h) ?? $h;

        return $h;
    }

    private function headerLooksLikeEmail(string $norm): bool
    {
        if (in_array($norm, ['mail', 'e-mail', 'email'], true)) {
            return true;
        }
        if (str_contains($norm, 'email') || str_contains($norm, 'e-mail')) {
            return true;
        }
        if (str_contains($norm, 'pec')) {
            return true;
        }

        return false;
    }

    private function headerLooksLikePhone(string $norm): bool
    {
        foreach (['telefono', 'cellulare', 'mobile', 'phone', 'tel', 'fax', 'whatsapp'] as $token) {
            if (str_contains($norm, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer company-style fields, then person name fields, then first non-empty "name-like" column.
     *
     * @param  array<int, array{norm: string, value: string, label: string}>  $normPairs
     */
    private function pickNameFromRow(array $normPairs): string
    {
        $priorityFragments = [
            ['ragione sociale'],
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
            ['nome'],
            ['cognome'],
            ['name'],
            ['titolo'],
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
            if ($this->headerLooksLikeEmail($pair['norm']) || $this->headerLooksLikePhone($pair['norm'])) {
                continue;
            }
            if (str_contains($pair['norm'], 'name') || str_contains($pair['norm'], 'nome') || str_contains($pair['norm'], 'cognome')) {
                return $pair['value'];
            }
        }

        return '';
    }

    private function normalizePhone(string $value): string
    {
        $digits = preg_replace('/[^\d+]/', '', $value) ?? '';

        return $digits !== '' ? $digits : trim($value);
    }

    /**
     * Parse the uploaded file based on format.
     */
    private function parseFile($file, $format)
    {
        $headers = [];
        $records = [];

        if ($format === 'csv') {
            $path = $file->getRealPath();
            $handle = fopen($path, 'r');
            if ($handle !== false) {
                // Get headers (first row)
                $headers = fgetcsv($handle);
                if ($headers === false) {
                    fclose($handle);
                    throw new \Exception('Unable to read headers from CSV file');
                }
                
                // Clean headers - remove BOM, convert to UTF-8, and trim whitespace
                $headers = array_map(function($header) {
                    return $this->convertToUtf8($header);
                }, $headers);
                
                // Get records
                while (($row = fgetcsv($handle)) !== false) {
                    // Only add if row has data
                    if (array_filter($row)) {
                        // Convert each cell to UTF-8
                        $row = array_map(function($cell) {
                            return $this->convertToUtf8($cell);
                        }, $row);
                        
                        // Ensure row has same number of columns as headers (pad or trim)
                        while (count($row) < count($headers)) {
                            $row[] = '';
                        }
                        if (count($row) > count($headers)) {
                            $row = array_slice($row, 0, count($headers));
                        }
                        $records[] = $row;
                    }
                }
                fclose($handle);
            } else {
                throw new \Exception('Unable to open CSV file');
            }
        } else {
            // Excel format using PhpSpreadsheet
            try {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                if ($highestRow < 1) {
                    throw new \Exception('Excel file is empty');
                }

                // Get headers (first row)
                $headers = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                    $cell = $worksheet->getCell($columnLetter . '1');
                    
                    // Get calculated value (handles formulas automatically)
                    $cellValue = $cell->getCalculatedValue();
                    
                    // Handle RichText
                    if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $cellValue = $cellValue->getPlainText();
                    }
                    
                    $headers[] = $this->convertToUtf8($cellValue ?? '');
                }

                // Remove empty trailing headers
                while (!empty($headers) && empty(end($headers))) {
                    array_pop($headers);
                }

                if (empty($headers)) {
                    throw new \Exception('No headers found in Excel file');
                }

                // Get records (starting from row 2)
                for ($row = 2; $row <= $highestRow; $row++) {
                    $record = [];
                    for ($col = 1; $col <= count($headers); $col++) {
                        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                        $cell = $worksheet->getCell($columnLetter . $row);
                        
                        // Get calculated value (handles formulas automatically)
                        $cellValue = $cell->getCalculatedValue();
                        
                        // Handle RichText
                        if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $cellValue = $cellValue->getPlainText();
                        }
                        
                        $record[] = $this->convertToUtf8($cellValue ?? '');
                    }
                    
                    // Only add if row has at least one non-empty value
                    if (array_filter($record)) {
                        $records[] = $record;
                    }
                }
            } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
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
     * Display the specified lead.
     */
    public function show(Request $request, Lead $lead)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $lead->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        return response()->json($lead);
    }

    /**
     * Update the specified lead.
     */
    public function update(Request $request, Lead $lead)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $lead->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $validated = $request->validate([
            'status' => 'sometimes|string',
            'category' => 'sometimes|string',
            'assigned_to' => 'sometimes|exists:users,id',
        ]);

        $lead->update($validated);

        return response()->json($lead);
    }

    /**
     * Remove the specified lead.
     */
    public function destroy(Request $request, Lead $lead)
    {
        $user = $request->user();

        // Check access
        if (!$user->isSuperAdmin() && $lead->company_id !== $user->company_id) {
            abort(403, 'Access denied');
        }

        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully'], 204);
    }

    /**
     * Export leads to CSV
     */
    public function export(Request $request)
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() && $request->has('company_id')
            ? $request->company_id
            : ($user->company_id ?? null);

        // Build query based on company_id (same as index method)
        if ($user->isSuperAdmin() && !$request->has('company_id')) {
            $query = Lead::query();
        } elseif ($companyId === null) {
            $query = Lead::whereNull('company_id');
        } else {
            $query = Lead::where('company_id', $companyId);
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
            $query->where('category', $request->category);
        }

        $this->applyImportFiltersFromRequest($query, $request);

        // Get all leads (no pagination for export)
        $leads = $query->orderBy('created_at', 'desc')->get();

        // Prepare CSV data
        $csvData = [];
        $headers = ['ID', 'Name', 'Email', 'Phone', 'Source', 'Status', 'Category', 'File Name', 'Assigned To', 'Value', 'Created At'];
        $csvData[] = $headers;

        foreach ($leads as $lead) {
            $row = [
                $lead->id,
                $lead->name ?? '',
                $lead->email ?? '',
                $lead->phone ?? '',
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
