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

if (!isset($_POST['ID']) || !isset($_POST['StandardDeptDesc']) || empty(trim($_POST['StandardDeptDesc']))) {
    $response['message'] = 'Department ID or description is missing.';
    echo json_encode($response);
    exit;
}

try {
    $id = (int)$_POST['ID'];
    $desc = trim($_POST['StandardDeptDesc']);
    $sql = "UPDATE tblStandardDepartments SET StandardDeptDesc = ? WHERE ID = ? AND Deleted = 0";
    $params = [$desc, $id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error updating department: ' . print_r(sqlsrv_errors(), true));
    }
    if (sqlsrv_rows_affected($stmt) === 0) {
        throw new Exception('Department not found or already deleted.');
    }
    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['message'] = 'Department updated successfully.';
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('updateStandardDepartment error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>