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
if (!$input || !isset($input['headerId']) || !is_array($input['data'])) {
    $response['message'] = 'Invalid or missing data.';
    echo json_encode($response);
    exit;
}

try {
    $headerId = (int)$input['headerId'];

    // Verify header exists
    $sql = "SELECT COUNT(*) AS count FROM tblSalaryHeader WHERE ID = ? AND Email = ?";
    $params = [$headerId, $_SESSION['email']];
    $stmt = sqlsrv_query($connSql, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error verifying header: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($row['count'] == 0) {
        throw new Exception('Header not found or not authorized.');
    }
    sqlsrv_free_stmt($stmt);

    // Begin transaction
    if (!sqlsrv_begin_transaction($connSql)) {
        throw new Exception('Error starting transaction: ' . print_r(sqlsrv_errors(), true));
    }

    // Delete existing details and mappings
    $sql = "DELETE FROM tblSalaryDetail WHERE HeaderID = ?";
    $stmt = sqlsrv_query($connSql, $sql, [$headerId]);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error deleting existing details: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    $sql = "DELETE FROM tblDepartmentJobMapping WHERE HeaderID = ?";
    $stmt = sqlsrv_query($connSql, $sql, [$headerId]);
    if ($stmt === false) {
        sqlsrv_rollback($connSql);
        throw new Exception('Error deleting existing mappings: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Insert updated data
    foreach ($input['data'] as $row) {
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
    $response['message'] = 'Data updated successfully.';
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('updateData error: ' . $e->getMessage());
} finally {
    sqlsrv_close($connSql);
}

echo json_encode($response);
?>