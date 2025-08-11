<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated or session expired.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['headerId'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request or missing headerId.']);
    exit;
}

try {
    $headerId = intval($_POST['headerId']);

    // Select data from tblSalaryDetails
    $query = "SELECT id, DepartmentCodeDesc, JobClassCodeDesc, ScheduledHours, HourlyRate, AnnualPay 
              FROM tblSalaryDetails 
              WHERE headerid = ? AND deleted = 0";
    $params = array($headerId);
    $stmt = sqlsrv_query($connSql, $query, $params);

    if ($stmt === false) {
        throw new Exception("Error querying tblSalaryDetails: " . print_r(sqlsrv_errors(), true));
    }

    $data = array();
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = [
            1 => $row['DepartmentCodeDesc'],
            2 => $row['JobClassCodeDesc'],
            3 => (string)$row['ScheduledHours'], // Ensure string format
            4 => (string)$row['HourlyRate'],    // Ensure string format
            5 => (string)$row['AnnualPay']      // Ensure string format
        ];
    }
    sqlsrv_free_stmt($stmt);

    echo json_encode(['success' => true, 'data' => $data, 'message' => 'Data loaded successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error loading data: ' . $e->getMessage()]);
} finally {
    if (isset($stmt) && is_resource($stmt)) {
        sqlsrv_free_stmt($stmt);
    }
    sqlsrv_close($connSql);
}
?>