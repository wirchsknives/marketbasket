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

if (!isset($_POST['ID']) || !isset($_POST['ReplacementID'])) {
    $response['message'] = 'Department ID or replacement ID is missing.';
    echo json_encode($response);
    exit;
}

try {
    $id = (int)$_POST['ID'];
    $replacementId = (int)$_POST['ReplacementID'];

    // Verify department and replacement exist and are not deleted
    $sql = "SELECT COUNT(*) AS count FROM tblStandardDepartments WHERE ID = ? AND Deleted = 0";
    $params = [$id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error checking department: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row['count'] == 0) {
        throw new Exception('Department not found or already deleted.');
    }
    sqlsrv_free_stmt($stmt);

    $sql = "SELECT COUNT(*) AS count FROM tblStandardDepartments WHERE ID = ? AND Deleted = 0";
    $params = [$replacementId];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error checking replacement department: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row['count'] == 0) {
        throw new Exception('Replacement department not found or deleted.');
    }
    sqlsrv_free_stmt($stmt);

    // Begin transaction
    if (!sqlsrv_begin_transaction($connSql)) {
        throw new Exception('Error starting transaction: ' . print_r(sqlsrv_errors(), true));
    }

    // Update tblStandardJobClasses to set StandardDeptID to NULL
    $sql = "UPDATE tblStandardJobClasses SET StandardDeptID = NULL WHERE StandardDeptID = ? AND Deleted = 0";
    $params = [$id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error updating job classes: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Update tblDepartmentJobMapping
    $sql = "UPDATE tblDepartmentJobMapping SET StandardDeptID = ? WHERE StandardDeptID = ?";
    $params = [$replacementId, $id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error updating department mappings: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Update tblSalaryDetail
    $sql = "UPDATE tblSalaryDetail SET StandardDeptID = ? WHERE StandardDeptID = ?";
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error updating salary details: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Mark department as deleted
    $sql = "UPDATE tblStandardDepartments SET Deleted = 1 WHERE ID = ?";
    $params = [$id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error marking department as deleted: ' . print_r(sqlsrv_errors(), true));
    }
    if (sqlsrv_rows_affected($stmt) === 0) {
        sqlsrv_rollback($connSql);
        throw new Exception('Department not found.');
    }
    sqlsrv_free_stmt($stmt);

    // Commit transaction
    if (!sqlsrv_commit($connSql)) {
        throw new Exception('Error committing transaction: ' . print_r(sqlsrv_errors(), true));
    }

    $response['success'] = true;
    $response['message'] = 'Department marked as deleted, job classes updated, and mappings reassigned.';
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('deleteStandardDepartment error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>