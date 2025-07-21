<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated or session expired.']);
    exit;
}

$data = filter_input(INPUT_POST, 'data', FILTER_SANITIZE_STRING);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'No data provided.']);
    exit;
}
error_log("processCopyPaste.php data:".$data);
// Split into rows
$rows = array_filter(array_map('trim', explode("\n", $data)));
error_log("processCopyPaste.php rows:".$rows);
if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => 'No valid data provided.']);
    exit;
}

// Required columns
$requiredColumns = [
    'Department Code Desc' => 1,
    'Job Class Code Desc' => 2,
    'Scheduled Hours' => 3,
    'Hourly Rate' => 4,
    'Annual Pay' => 5
];

// Parse first row
$firstRow = array_map('trim', str_getcsv($rows[0], ","));
if (count($firstRow) === 1 && strpos($rows[0], "\t") !== false) {
    $firstRow = array_map('trim', explode("\t", $rows[0]));
}

// Check if headers match
$headerMatch = true;
$columnOrder = [];
foreach ($requiredColumns as $name => $id) {
    $index = array_search(strtolower($name), array_map('strtolower', $firstRow));
    if ($index === false) {
        $headerMatch = false;
        break;
    }
    $columnOrder[$index] = $id;
}

$dataRows = $rows;
$startRow = 0;
if ($headerMatch) {
    $startRow = 1;
} elseif (count($firstRow) === 5) {
    $columnOrder = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5];
} else {
    echo json_encode(['success' => false, 'message' => 'Please paste in comma or tab separated data with column headers "Department Code Desc", "Job Class Code Desc", "Scheduled Hours", "Hourly Rate", and "Annual Pay" or five columns of data in that order.']);
    exit;
}

// Parse data
$data = [];
for ($i = $startRow; $i < count($dataRows); $i++) {
    $row = array_map('trim', str_getcsv($dataRows[$i], ","));
    if (count($row) === 1 && strpos($dataRows[$i], "\t") !== false) {
        $row = array_map('trim', explode("\t", $dataRows[$i]));
    }
    if (count($row) >= 5) {
        $rowData = [];
        $hasData = false;
        foreach ($columnOrder as $index => $id) {
            $value = filter_var($row[$index], FILTER_SANITIZE_STRING);
            if ($value !== '') {
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
}

if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'No valid data parsed.']);
    exit;
}

echo json_encode(['success' => true, 'data' => $data, 'email' => $_SESSION['auth_email'], 'town' => $_SESSION['auth_town']]);
?>