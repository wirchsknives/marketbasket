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
    $response['message'] = 'Job class ID or replacement ID is missing.';
    echo json_encode($response);
    exit;
}

try {
    $id = (int)$_POST['ID'];
    $replacementId = (int)$_POST['ReplacementID'];

    // Verify job class and replacement exist and are not deleted
    $sql = "SELECT StandardDeptID FROM tblStandardJobClasses WHERE ID = ? AND Deleted = 0";
    $params = [$id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error checking job class: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Job class not found or already deleted.');
    }
    $originalDeptId = $row['StandardDeptID'];
    sqlsrv_free_stmt($stmt);

    // Verify replacement job class is in the same department
    $sql = "SELECT COUNT(*) AS count FROM tblStandardJobClasses WHERE ID = ? AND Deleted = 0 AND StandardDeptID = ?";
    $params = [$replacementId, $originalDeptId];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error checking replacement job class: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row['count'] == 0) {
        throw new Exception('Replacement job class not found, deleted, or not in the same department.');
    }
    sqlsrv_free_stmt($stmt);

    // Begin transaction
    if (!sqlsrv_begin_transaction($connSql)) {
        throw new Exception('Error starting transaction: ' . print_r(sqlsrv_errors(), true));
    }

    // Update tblDepartmentJobMapping
    $sql = "UPDATE tblDepartmentJobMapping SET StandardJobClassID = ? WHERE StandardJobClassID = ?";
    $params = [$replacementId, $id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error updating department job mappings: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Update tblSalaryDetail
    $sql = "UPDATE tblSalaryDetail SET StandardJobClassID = ? WHERE StandardJobClassID = ?";
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error updating salary details: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Mark job class as deleted
    $sql = "UPDATE tblStandardJobClasses SET Deleted = 1 WHERE ID = ?";
    $params = [$id];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error marking job class as deleted: ' . print_r(sqlsrv_errors(), true));
    }
    if (sqlsrv_rows_affected($stmt) === 0) {
        sqlsrv_rollback($connSql);
        throw new Exception('Job class not found.');
    }
    sqlsrv_free_stmt($stmt);

    // Commit transaction
    if (!sqlsrv_commit($connSql)) {
        throw new Exception('Error committing transaction: ' . print_r(sqlsrv_errors(), true));
    }

    $response['success'] = true;
    $response['message'] = 'Job class marked as deleted and mappings reassigned.';
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('deleteStandardJobClass error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>