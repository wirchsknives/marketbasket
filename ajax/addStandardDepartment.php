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

if (!isset($_POST['StandardDeptDesc']) || empty(trim($_POST['StandardDeptDesc']))) {
    $response['message'] = 'Department description is required.';
    echo json_encode($response);
    exit;
}

try {
    $desc = trim($_POST['StandardDeptDesc']);
    $sql = "INSERT INTO tblStandardDepartments (StandardDeptDesc, Deleted) VALUES (?, 0)";
    $params = [$desc];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error adding department: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['message'] = 'Department added successfully.';
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('addStandardDepartment error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>