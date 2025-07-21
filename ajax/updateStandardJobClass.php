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

if (!isset($_POST['ID']) || !isset($_POST['StandardJobClassDesc']) || empty(trim($_POST['StandardJobClassDesc'])) || !isset($_POST['StandardDeptID'])) {
    $response['message'] = 'Job class ID, description, or department is missing.';
    echo json_encode($response);
    exit;
}

try {
    $id = (int)$_POST['ID'];
    $desc = trim($_POST['StandardJobClassDesc']);
    $deptId = (int)$_POST['StandardDeptID'];
    $sql = "SELECT COUNT(*) AS count FROM tblStandardDepartments WHERE ID = ? AND Deleted = 0";
    $stmt = sqlsrv_query($connSql, $sql, [$deptId]);
    if ($stmt === false) {
        throw new Exception('Error verifying department: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row['count'] == 0) {
        throw new Exception('Selected department not found or deleted.');
    }
    sqlsrv_free_stmt($stmt);

    $sql = "UPDATE tblStandardJobClasses SET StandardJobClassDesc = ?, StandardDeptID = ? WHERE ID = ? AND Deleted = 0";
    $params = [$desc, $deptId, $id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error updating job class: ' . print_r(sqlsrv_errors(), true));
    }
    if (sqlsrv_rows_affected($stmt) === 0) {
        throw new Exception('Job class not found or already deleted.');
    }
    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['message'] = 'Job class updated successfully.';
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('updateStandardJobClass error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>