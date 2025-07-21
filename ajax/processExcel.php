<?php
ini_set('memory_limit', '512M');
session_start();
require_once '../includes/db.php';
require_once '../vendor/autoload.php'; // Composer autoloader

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

header('Content-Type: application/json');

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated or session expired.']);
    exit;
}

if (!isset($_FILES['excelFile']) || $_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    exit;
}

// Validate file type and size (50MB)
$fileInfo = pathinfo($_FILES['excelFile']['name']);
if (strtolower($fileInfo['extension']) !== 'xlsx' || $_FILES['excelFile']['type'] !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
    echo json_encode(['success' => false, 'message' => 'Please upload an .xlsx file.']);
    exit;
}
if ($_FILES['excelFile']['size'] > 50 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 50MB limit.']);
    exit;
}

// Move file to uploads/
$uploadDir = '../Uploads/';
$originalName = $fileInfo['filename'];
$timestamp = date('Ymd_His');
$newFilename = "{$originalName}_Processed{$timestamp}.xlsx";
$tempFile = $uploadDir . $newFilename;
if (!move_uploaded_file($_FILES['excelFile']['tmp_name'], $tempFile)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
    exit;
}

try {
    // Load spreadsheet
    $spreadsheet = IOFactory::load($tempFile);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestRow();
    $highestColumn = $worksheet->getHighestColumn();
    $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

    // Get column headers
    $headers = [];
    for ($col = 1; $col <= $highestColumnIndex; $col++) {
        $headers[$col] = trim($worksheet->getCellByColumnAndRow($col, 1)->getValue());
    }

    // Required columns
    $requiredColumns = [
        'Department Code Desc' => 1,
        'Job Class Code Desc' => 2,
        'Scheduled Hours' => 3,
        'Hourly Rate' => 4,
        'Annual Pay' => 5
    ];

    // Map Excel columns
    $columnMap = [];
    $missingColumns = [];
    foreach ($requiredColumns as $name => $id) {
        $found = false;
        foreach ($headers as $col => $header) {
            if (strtolower($header) === strtolower($name)) {
                $columnMap[$id] = $col;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missingColumns[] = $name;
        }
    }

    if (!empty($missingColumns)) {
        echo json_encode(['success' => false, 'message' => 'Missing required columns: ' . implode(', ', $missingColumns)]);
        exit;
    }

    // Extract data
    $data = [];
    for ($row = 2; $row <= $highestRow; $row++) {
        $rowData = [];
        $hasData = false;
        foreach ($requiredColumns as $id) {
            $col = $columnMap[$id];
            $value = $worksheet->getCellByColumnAndRow($col, $row)->getValue();
            if ($value !== null && $value !== '') {
                $hasData = true;
            }
            if ($id >= 3) { // Scheduled Hours, Hourly Rate, Annual Pay
                $value = preg_replace('/[\$,]/', '', $value);
                $rowData[$id] = is_numeric($value) ? number_format($value, 2, '.', '') : '';
            } else {
                $rowData[$id] = $value;
            }
        }
        if ($hasData) {
            $data[] = $rowData;
        }
    }

    // Return data
    echo json_encode(['success' => true, 'data' => $data, 'email' => $_SESSION['auth_email'], 'town' => $_SESSION['auth_town']]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error processing Excel file: ' . $e->getMessage()]);
} finally {
    // File retained
}

sqlsrv_close($connSql);
?>