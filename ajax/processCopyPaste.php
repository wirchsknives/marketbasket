<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => 'Not authenticated or session expired.',
    'data' => [],
    'email' => null,
    'town' => null
];

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode($response);
    exit;
}

if (!isset($_POST['data']) || empty(trim($_POST['data']))) {
    $response['message'] = 'No data provided.';
    echo json_encode($response);
    exit;
}

try {
    $rawData = trim($_POST['data']);
    $rows = array_filter(explode("\n", $rawData), 'trim'); // Split by newline, remove empty rows

    if (empty($rows)) {
        $response['message'] = 'No valid data found.';
        echo json_encode($response);
        exit;
    }

    // Expected columns
    $requiredColumns = [
        'Department Code Desc' => 1,
        'Job Class Code Desc' => 2,
        'Scheduled Hours' => 3,
        'Hourly Rate' => 4,
        'Annual Pay' => 5
    ];

    // Parse headers (first row) and detect delimiter
    $firstRow = array_shift($rows);
    $delimiter = (substr_count($firstRow, "\t") >= 4) ? "\t" : ","; // At least 4 tabs for 5 columns, else assume comma

    // Handle comma-separated data with possible quotes
    if ($delimiter === ",") {
        $headers = str_getcsv($firstRow, $delimiter);
        $headers = array_map('trim', $headers);
    } else {
        $headers = array_map('trim', explode($delimiter, $firstRow));
    }

    $columnMap = [];
    $missingColumns = [];

    foreach ($requiredColumns as $name => $id) {
        $found = false;
        foreach ($headers as $index => $header) {
            if (strtolower($header) === strtolower($name)) {
                $columnMap[$id] = $index;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missingColumns[] = $name;
        }
    }

    if (!empty($missingColumns)) {
        $response['message'] = 'Missing required columns: ' . implode(', ', $missingColumns);
        echo json_encode($response);
        exit;
    }

    // Parse data rows
    $data = [];
    foreach ($rows as $row) {
        if ($delimiter === ",") {
            $columns = str_getcsv($row, $delimiter);
            $columns = array_map('trim', $columns);
        } else {
            $columns = array_map('trim', explode($delimiter, $row));
        }

        if (count($columns) < count($requiredColumns)) {
            continue; // Skip rows with insufficient columns
        }

        $rowData = [];
        $hasData = false;
        foreach ($requiredColumns as $id) {
            $colIndex = $columnMap[$id];
            $value = $columns[$colIndex] ?? '';
            if ($value !== '') {
                $hasData = true;
            }
            if ($id >= 3) { // Scheduled Hours, Hourly Rate, Annual Pay
                $value = preg_replace('/[\$,]/', '', $value); // Remove $ and commas
                $rowData[$id] = is_numeric($value) ? number_format(floatval($value), 2, '.', '') : '';
            } else {
                $rowData[$id] = $value;
            }
        }
        if ($hasData) {
            $rowData['checked'] = true;
            $data[] = $rowData;
        }
    }

    if (empty($data)) {
        $response['message'] = 'No valid data rows found.';
        echo json_encode($response);
        exit;
    }

    $response['success'] = true;
    $response['message'] = 'Data processed successfully.';
    $response['data'] = $data;
    $response['email'] = $_SESSION['auth_email'] ?? null;
    $response['town'] = $_SESSION['auth_town'] ?? null;

    echo json_encode($response);

} catch (Exception $e) {
    $response['message'] = 'Error processing data: ' . $e->getMessage();
    echo json_encode($response);
}

sqlsrv_close($connSql);
?>