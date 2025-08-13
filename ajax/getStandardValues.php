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

try {
    // Fetch standard departments
    $sql = "SELECT ID, StandardDeptDesc FROM tblStandardDepartments WHERE Deleted = 0 ORDER BY StandardDeptDesc";
    $stmt = sqlsrv_query($connSql, $sql);
    if ($stmt === false) {
        throw new Exception('Error querying standard departments: ' . print_r(sqlsrv_errors(), true));
    }
    $departments = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = [
            'id' => $row['ID'],
            'StandardDeptDesc' => $row['StandardDeptDesc']
        ];
    }
    sqlsrv_free_stmt($stmt);

    // Fetch standard job classes
    $sql = "SELECT ID, StandardJobClassDesc, StandardDeptID FROM tblStandardJobClasses WHERE Deleted = 0 ORDER BY StandardJobClassDesc";
    $stmt = sqlsrv_query($connSql, $sql);
    if ($stmt === false) {
        throw new Exception('Error querying standard job classes: ' . print_r(sqlsrv_errors(), true));
    }
    $jobClasses = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $jobClasses[] = [
            'id' => $row['ID'],
            'StandardJobClassDesc' => $row['StandardJobClassDesc'],
            'StandardDeptID' => $row['StandardDeptID']
        ];
    }
    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['departments'] = $departments;
    $response['jobClasses'] = $jobClasses;
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('getStandardValues error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>