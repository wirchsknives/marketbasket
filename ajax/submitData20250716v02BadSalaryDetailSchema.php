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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    $response['message'] = 'Invalid or missing data.';
    echo json_encode($response);
    exit;
}

try {
    if (!sqlsrv_begin_transaction($connSql)) {
        throw new Exception('Error starting transaction: ' . print_r(sqlsrv_errors(), true));
    }

    $sql = "INSERT INTO tblSalaryHeader (Email, Town, UploadDate) VALUES (?, ?, ?)";
    $params = [$_SESSION['auth_email'], $_SESSION['auth_town'], date('Y-m-d H:i:s')];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error inserting header: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    $sql = "SELECT SCOPE_IDENTITY() AS headerId";
    $stmt = sqlsrv_query($connSql, $sql);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error retrieving header ID: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $headerId = (int)$row['headerId'];
    sqlsrv_free_stmt($stmt);

    foreach ($input as $row) {
        if (!isset($row['checked']) || !$row['checked']) {
            continue;
        }
        $sql = "INSERT INTO tblSalaryDetail (
            HeaderID, OriginalDeptDesc, OriginalJobClassDesc, 
            ScheduledHours, HourlyRate, AnnualPay, 
            StandardDeptID, StandardJobClassID
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [
            $headerId,
            $row[1] ?? null,
            $row[2] ?? null,
            isset($row[3]) ? floatval($row[3]) : null,
            isset($row[4]) ? floatval($row[4]) : null,
            isset($row[5]) ? floatval($row[5]) : null,
            isset($row['standardDeptID']) ? (int)$row['standardDeptID'] : null,
            isset($row['standardJobClassID']) ? (int)$row['standardJobClassID'] : null
        ];
        $stmt = sqlsrv_query($connSql, $sql, $params);
        if ($stmt === false) {
            sqlsrv_rollback($connSql);
            throw new Exception('Error inserting detail: ' . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmt);

        $sql = "INSERT INTO tblDepartmentJobMapping (
            HeaderID, OriginalDeptDesc, StandardDeptID, 
            OriginalJobClassDesc, StandardJobClassID
        ) VALUES (?, ?, ?, ?, ?)";
        $params = [
            $headerId,
            $row[1] ?? null,
            isset($row['standardDeptID']) ? (int)$row['standardDeptID'] : null,
            $row[2] ?? null,
            isset($row['standardJobClassID']) ? (int)$row['standardJobClassID'] : null
        ];
        $stmt = sqlsrv_query($connSql, $sql, $params);
        if ($stmt === false) {
            sqlsrv_rollback($connSql);
            throw new Exception('Error inserting mapping: ' . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmt);
    }

    if (!sqlsrv_commit($connSql)) {
        throw new Exception('Error committing transaction: ' . print_r(sqlsrv_errors(), true));
    }

    $response['success'] = true;
    $response['message'] = 'Data submitted successfully.';
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('submitData error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>