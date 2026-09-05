<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use App\Models\Email;
use App\Jobs\SendBulkEmailJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class EmailController extends Controller
{
    use HandlesApiErrors;

    /**
     * List configured bulk email senders.
     */
    public function senders()
    {
        return response()->json([
            'senders' => config('email_senders.senders', []),
        ]);
    }

    /**
     * Resolve sender config by id.
     */
    private function resolveSender(?string $senderId): array
    {
        $senders = config('email_senders.senders', []);
        $default = $senders[count($senders) - 1] ?? [
            'id' => 'default',
            'address' => config('mail.from.address'),
            'name' => config('mail.from.name'),
        ];

        if (!$senderId) {
            return $default;
        }

        foreach ($senders as $sender) {
            if (($sender['id'] ?? '') === $senderId) {
                return $sender;
            }
        }

        return $default;
    }

    /**
     * Load existing email addresses (normalized) from database.
     */
    private function loadExistingEmailAddresses(): array
    {
        return Email::query()
            ->whereNotNull('email_address')
            ->pluck('email_address')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * List all emails with pagination and filtering
     */
    public function index(Request $request)
    {
        $query = Email::query();

        // Filter by category
        if ($request->has('category') && $request->category !== 'all' && $request->category !== '') {
            $query->where('category', $request->category);
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'all' && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Search by email address (search in row_data_json)
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereJsonContains('row_data_json->email', $search)
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $emails = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json($emails);
    }

    /**
     * Upload emails from file
     */
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls',
            'category' => 'required|string|max:255',
        ]);

        $file = $request->file('file');
        $category = $validated['category'];
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $results = $this->parseFile($file, $extension, $category);
            
            return response()->json([
                'message' => 'File processed successfully',
                'total_rows' => $results['total'],
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'skipped' => $results['skipped'] ?? 0,
                'errors' => $results['errors'],
                'skipped_emails' => $results['skipped_emails'] ?? [],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Email upload failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to process file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Parse uploaded file and create email records
     */
    private function parseFile($file, $extension, $category)
    {
        $total = 0;
        $successful = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $skippedEmails = [];

        if (in_array($extension, ['csv', 'txt'])) {
            $data = $this->parseCsvOrTxt($file);
        } elseif (in_array($extension, ['xlsx', 'xls'])) {
            $data = $this->parseExcel($file);
        } else {
            throw new \Exception('Unsupported file format');
        }

        $hasHeaders = isset($data['headers']) && !empty($data['headers']);
        
        // Collect all email addresses first for batch checking
        $allEmails = [];
        
        if ($hasHeaders) {
            // CASE A: File has headers
            $headers = $data['headers'];
            
            // Validate email column exists
            $emailColumnIndex = null;
            foreach ($headers as $index => $header) {
                if (strtolower(trim($header)) === 'email') {
                    $emailColumnIndex = $index;
                    break;
                }
            }

            if ($emailColumnIndex === null) {
                throw new \Exception('Email column not found in file headers. Please ensure your file has an "email" column.');
            }

            // First pass: collect all valid emails
            foreach ($data['records'] as $rowIndex => $record) {
                if (!isset($record[$emailColumnIndex])) {
                    continue;
                }
                
                $emailValue = trim($record[$emailColumnIndex]);
                if (!empty($emailValue) && filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
                    $allEmails[] = strtolower($emailValue);
                }
            }
        } else {
            // CASE B: File has no headers
            foreach ($data['records'] as $rowIndex => $record) {
                $emailValue = is_array($record) ? trim($record[0] ?? '') : trim($record);
                if (!empty($emailValue) && filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
                    $allEmails[] = strtolower($emailValue);
                }
            }
        }

        // Batch check for existing emails
        $existingEmails = $this->loadExistingEmailAddresses();
        $seenInFile = [];

            // Process each record
        if ($hasHeaders) {
            $headers = $data['headers'];
            $emailColumnIndex = null;
            foreach ($headers as $index => $header) {
                if (strtolower(trim($header)) === 'email') {
                    $emailColumnIndex = $index;
                    break;
                }
            }

            foreach ($data['records'] as $rowIndex => $record) {
                $total++;
                
                // Ensure record has enough columns
                if (!isset($record[$emailColumnIndex])) {
                    $failed++;
                    $errors[] = "Row " . ($rowIndex + 2) . ": Missing email value";
                    continue;
                }

                $emailValue = trim($record[$emailColumnIndex]);
                
                // Validate email
                if (empty($emailValue) || !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
                    $failed++;
                    $errors[] = "Row " . ($rowIndex + 2) . ": Invalid email format: " . $emailValue;
                    continue;
                }

                // Check if email already exists (DB or earlier row in same file)
                $emailLower = strtolower($emailValue);
                if (in_array($emailLower, $existingEmails, true) || in_array($emailLower, $seenInFile, true)) {
                    $skipped++;
                    $skippedEmails[] = "Row " . ($rowIndex + 2) . ": Email already exists - " . $emailValue;
                    continue;
                }

                // Build row_data_json mapping headers to values
                $rowData = [];
                foreach ($headers as $index => $header) {
                    $key = strtolower(trim($header));
                    $value = isset($record[$index]) ? $this->convertToUtf8($record[$index]) : null;
                    $rowData[$key] = $value;
                }

                // Ensure email key exists
                $rowData['email'] = $emailValue;

                try {
                    Email::create([
                        'category' => $category,
                        'email_address' => $emailLower,
                        'headers_json' => $headers,
                        'row_data_json' => $rowData,
                        'status' => 'active',
                    ]);
                    $successful++;
                    $existingEmails[] = $emailLower;
                    $seenInFile[] = $emailLower;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Row " . ($rowIndex + 2) . ": " . $e->getMessage();
                    Log::error('Failed to create email record', [
                        'row' => $rowIndex + 2,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } else {
            // CASE B: File has no headers (only email values)
            foreach ($data['records'] as $rowIndex => $record) {
                $total++;
                
                // For files without headers, first column should be email
                $emailValue = is_array($record) ? trim($record[0] ?? '') : trim($record);
                
                // Validate email
                if (empty($emailValue) || !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
                    $failed++;
                    $errors[] = "Row " . ($rowIndex + 1) . ": Invalid email format: " . $emailValue;
                    continue;
                }

                // Check if email already exists (DB or earlier row in same file)
                $emailLower = strtolower($emailValue);
                if (in_array($emailLower, $existingEmails, true) || in_array($emailLower, $seenInFile, true)) {
                    $skipped++;
                    $skippedEmails[] = "Row " . ($rowIndex + 1) . ": Email already exists - " . $emailValue;
                    continue;
                }

                try {
                    Email::create([
                        'category' => $category,
                        'email_address' => $emailLower,
                        'headers_json' => null, // No headers
                        'row_data_json' => ['email' => $emailValue],
                        'status' => 'active',
                    ]);
                    $successful++;
                    $existingEmails[] = $emailLower;
                    $seenInFile[] = $emailLower;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Row " . ($rowIndex + 1) . ": " . $e->getMessage();
                    Log::error('Failed to create email record', [
                        'row' => $rowIndex + 1,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50), // Limit errors to first 50
            'skipped_emails' => array_slice($skippedEmails, 0, 100), // Limit skipped emails to first 100
        ];
    }

    /**
     * Parse CSV or TXT file
     */
    private function parseCsvOrTxt($file)
    {
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');
        
        if ($handle === false) {
            throw new \Exception('Unable to open file');
        }

        $headers = [];
        $records = [];
        $firstRow = fgetcsv($handle);
        
        if ($firstRow === false) {
            fclose($handle);
            throw new \Exception('File is empty');
        }

        // Clean first row
        $firstRow = array_map([$this, 'convertToUtf8'], $firstRow);
        
        // Check if first row looks like headers (contains "email" or similar)
        $firstRowLower = array_map('strtolower', array_map('trim', $firstRow));
        $hasEmailColumn = in_array('email', $firstRowLower);
        
        if ($hasEmailColumn) {
            // First row is headers
            $headers = $firstRow;
            
            // Read data rows
            while (($row = fgetcsv($handle)) !== false) {
                if (array_filter($row)) { // Skip empty rows
                    $row = array_map([$this, 'convertToUtf8'], $row);
                    // Pad or trim row to match headers
                    while (count($row) < count($headers)) {
                        $row[] = '';
                    }
                    if (count($row) > count($headers)) {
                        $row = array_slice($row, 0, count($headers));
                    }
                    $records[] = $row;
                }
            }
        } else {
            // First row is data (no headers)
            // Check if first value is a valid email
            $firstValue = trim($firstRow[0] ?? '');
            if (filter_var($firstValue, FILTER_VALIDATE_EMAIL)) {
                // No headers, treat all rows as email values
                $records[] = $firstRow;
                
                while (($row = fgetcsv($handle)) !== false) {
                    if (array_filter($row)) {
                        $row = array_map([$this, 'convertToUtf8'], $row);
                        $records[] = $row;
                    }
                }
            } else {
                // First row might be headers but without "email" column
                // Try to treat as headers anyway
                $headers = $firstRow;
                while (($row = fgetcsv($handle)) !== false) {
                    if (array_filter($row)) {
                        $row = array_map([$this, 'convertToUtf8'], $row);
                        while (count($row) < count($headers)) {
                            $row[] = '';
                        }
                        if (count($row) > count($headers)) {
                            $row = array_slice($row, 0, count($headers));
                        }
                        $records[] = $row;
                    }
                }
            }
        }

        fclose($handle);

        return [
            'headers' => !empty($headers) ? $headers : null,
            'records' => $records,
        ];
    }

    /**
     * Parse Excel file
     */
    private function parseExcel($file)
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            if ($highestRow < 1) {
                throw new \Exception('Excel file is empty');
            }

            $headers = [];
            $records = [];

            // Get first row
            $firstRow = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                $cell = $worksheet->getCell($columnLetter . '1');
                $cellValue = $cell->getCalculatedValue();
                
                if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $cellValue = $cellValue->getPlainText();
                }
                
                $firstRow[] = $this->convertToUtf8($cellValue ?? '');
            }

            // Remove empty trailing columns
            while (!empty($firstRow) && empty(end($firstRow))) {
                array_pop($firstRow);
            }

            // Check if first row looks like headers
            $firstRowLower = array_map('strtolower', array_map('trim', $firstRow));
            $hasEmailColumn = in_array('email', $firstRowLower);
            
            if ($hasEmailColumn) {
                // First row is headers
                $headers = $firstRow;
                
                // Get data rows (starting from row 2)
                for ($row = 2; $row <= $highestRow; $row++) {
                    $record = [];
                    for ($col = 1; $col <= count($headers); $col++) {
                        $columnLetter = Coordinate::stringFromColumnIndex($col);
                        $cell = $worksheet->getCell($columnLetter . $row);
                        $cellValue = $cell->getCalculatedValue();
                        
                        if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $cellValue = $cellValue->getPlainText();
                        }
                        
                        $record[] = $this->convertToUtf8($cellValue ?? '');
                    }
                    
                    if (array_filter($record)) {
                        $records[] = $record;
                    }
                }
            } else {
                // Check if first value is a valid email
                $firstValue = trim($firstRow[0] ?? '');
                if (filter_var($firstValue, FILTER_VALIDATE_EMAIL)) {
                    // No headers, treat all rows as email values
                    $records[] = $firstRow;
                    
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $record = [];
                        $columnLetter = Coordinate::stringFromColumnIndex(1);
                        $cell = $worksheet->getCell($columnLetter . $row);
                        $cellValue = $cell->getCalculatedValue();
                        
                        if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $cellValue = $cellValue->getPlainText();
                        }
                        
                        $emailValue = $this->convertToUtf8($cellValue ?? '');
                        if (!empty($emailValue)) {
                            $records[] = [$emailValue];
                        }
                    }
                } else {
                    // Treat first row as headers anyway
                    $headers = $firstRow;
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $record = [];
                        for ($col = 1; $col <= count($headers); $col++) {
                            $columnLetter = Coordinate::stringFromColumnIndex($col);
                            $cell = $worksheet->getCell($columnLetter . $row);
                            $cellValue = $cell->getCalculatedValue();
                            
                            if ($cellValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                                $cellValue = $cellValue->getPlainText();
                            }
                            
                            $record[] = $this->convertToUtf8($cellValue ?? '');
                        }
                        
                        if (array_filter($record)) {
                            $records[] = $record;
                        }
                    }
                }
            }

            return [
                'headers' => !empty($headers) ? $headers : null,
                'records' => $records,
            ];

        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            throw new \Exception('Failed to parse Excel file: ' . $e->getMessage());
        }
    }

    /**
     * Convert string to UTF-8 encoding
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
            if ($converted === false) {
                $converted = @iconv($detectedEncoding, 'UTF-8//IGNORE', $string);
            }
            return trim($converted !== false ? $converted : $string);
        }

        return trim(mb_convert_encoding($string, 'UTF-8', 'UTF-8'));
    }

    /**
     * Send bulk emails
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'sender_id' => 'nullable|string|max:100',
            'selection_type' => 'required|in:first_n,selected,category',
            'first_n' => 'required_if:selection_type,first_n|integer|min:1',
            'selected_ids' => 'required_if:selection_type,selected|array',
            'selected_ids.*' => 'integer|exists:emails,id',
            'category_filter' => 'required_if:selection_type,category|string',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:102400|mimes:pdf,xls,xlsx,doc,docx,jpg,jpeg,png',
        ]);

        try {
            $attachments = [];
            $batchId = null;

            if ($request->hasFile('attachments')) {
                $batchId = (string) Str::uuid();
                $attachmentsDir = 'bulk-email/' . $batchId;

                foreach ($request->file('attachments') as $attachment) {
                    $path = $attachment->store($attachmentsDir, 'local');
                    $attachments[] = [
                        'path' => $path,
                        'name' => $attachment->getClientOriginalName(),
                        'mime' => $attachment->getClientMimeType(),
                    ];
                }
            }

            // Build query based on selection type
            $query = Email::query();

            switch ($validated['selection_type']) {
                case 'first_n':
                    // For first N, only get active emails
                    $query->where('status', 'active')
                          ->limit($validated['first_n']);
                    break;
                
                case 'selected':
                    // For manually selected, allow any status (user explicitly selected these)
                    $query->whereIn('id', $validated['selected_ids']);
                    break;
                
                case 'category':
                    // For category filter, only get active emails
                    $query->where('status', 'active')
                          ->where('category', $validated['category_filter']);
                    break;
            }

            $emails = $query->get()->unique(function (Email $email) {
                $address = strtolower(trim((string) ($email->email_address ?? '')));
                if ($address !== '') {
                    return $address;
                }
                $data = $email->row_data_json;
                return is_array($data) && isset($data['email'])
                    ? strtolower(trim((string) $data['email']))
                    : 'id:' . $email->id;
            })->values();

            if ($emails->isEmpty()) {
                return response()->json([
                    'message' => 'No emails found matching the criteria'
                ], 404);
            }

            $sentCount = 0;
            $failedCount = 0;

            if ($batchId) {
                Cache::put(
                    'bulk_email:' . $batchId . ':pending',
                    $emails->count(),
                    now()->addHours(6)
                );
            }

            $sender = $this->resolveSender($validated['sender_id'] ?? null);
            $fromAddress = $sender['address'] ?? config('mail.from.address');
            $fromName = $sender['name'] ?? config('mail.from.name');

            // Dispatch job for each email (or batch them)
            foreach ($emails as $email) {
                try {
                    SendBulkEmailJob::dispatch(
                        $email->id,
                        $validated['subject'],
                        $validated['message'],
                        $attachments,
                        $batchId,
                        $fromAddress,
                        $fromName
                    );
                    $sentCount++;
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error('Failed to queue email', [
                        'email_id' => $email->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'message' => 'Emails queued for sending',
                'total' => $emails->count(),
                'queued' => $sentCount,
                'failed' => $failedCount,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Bulk email send failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to send emails: ' . $e->getMessage()
            ], 500);
        }
    }
}
