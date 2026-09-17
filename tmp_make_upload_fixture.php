<?php

require __DIR__.'/vendor/autoload.php';

$path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'crm_live_upload_test.xlsx';
$book = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->fromArray([
    ['Name', 'Email', 'Phone'],
    ['Upload Test', 'upload-test@example.com', 123456789],
], null, 'A1');
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
echo $path;
