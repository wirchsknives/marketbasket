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
    // Fetch departments
    $sql = "SELECT ID, StandardDeptDesc, Deleted FROM tblStandardDepartments ORDER BY StandardDeptDesc";
    $stmt = sqlsrv_query($connSql, $sql);
    if ($stmt === false) {
        throw new Exception('Error fetching departments: ' . print_r(sqlsrv_errors(), true));
    }
    $departments = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = [
            'ID' => $row['ID'],
            'StandardDeptDesc' => $row['StandardDeptDesc'],
            'Deleted' => (bool)$row['Deleted']
        ];
    }
    sqlsrv_free_stmt($stmt);

    // Fetch job classes
    $sql = "SELECT ID, StandardJobClassDesc, StandardDeptID, Deleted FROM tblStandardJobClasses ORDER BY StandardJobClassDesc";
    $stmt = sqlsrv_query($connSql, $sql);
    if ($stmt === false) {
        throw new Exception('Error fetching job classes: ' . print_r(sqlsrv_errors(), true));
    }
    $jobClasses = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $jobClasses[] = [
            'ID' => $row['ID'],
            'StandardJobClassDesc' => $row['StandardJobClassDesc'],
            'StandardDeptID' => $row['StandardDeptID'],
            'Deleted' => (bool)$row['Deleted']
        ];
    }
    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['departments'] = $departments;
    $response['jobClasses'] = $jobClasses;
    $response['message'] = 'Data retrieved successfully.';
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('getAdminData error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>