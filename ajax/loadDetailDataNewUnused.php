<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => 'Not authenticated or session expired.'
];

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode($response);
    exit;
}

$headerId = $_POST['headerId'] ?? null;

if (!$headerId) {
    $response['message'] = 'No header ID provided.';
    echo json_encode($response);
    exit;
}

try {
    $query = "SELECT ColumnID, DataValue FROM tblSalaryDetail WHERE HeaderID = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId]);

    if ($stmt === false) {
        error_log("Error retrieving data: " . print_r(sqlsrv_errors(), true)); // Debug
        $response['message'] = 'Error retrieving data.';
        echo json_encode($response);
        exit;
    }

    $data = [];
    $currentRow = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $columnId = $row['ColumnID'];
        $dataValue = $row['DataValue'];
        $currentRow[$columnId] = $dataValue;
        if ($columnId == 5) {
            $currentRow['checked'] = true; // Add checked property
            $data[] = $currentRow;
            $currentRow = [];
        }
    }

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($connSql);

    $response['success'] = true;
    $response['data'] = $data;
    $response['message'] = 'Data retrieved successfully.';

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in loadDetailData.php: " . $e->getMessage()); // Debug
    $response['message'] = 'Error: ' . $e->getMessage();
    echo json_encode($response);
}
?>