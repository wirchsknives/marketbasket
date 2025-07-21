<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated or session expired.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

try {
    // Start transaction
    sqlsrv_begin_transaction($connSql);

    // Insert into tblSalaryHeader
    $queryHeader = "INSERT INTO tblSalaryHeader (email, town) OUTPUT INSERTED.id VALUES (?, ?)";
    $paramsHeader = [$_SESSION['auth_email'], $_SESSION['auth_town']];
    $stmtHeader = sqlsrv_query($connSql, $queryHeader, $paramsHeader);
    if ($stmtHeader === false) {
        throw new Exception("Error inserting into tblSalaryHeader: " . print_r(sqlsrv_errors(), true));
    }
    $rowHeader = sqlsrv_fetch_array($stmtHeader, SQLSRV_FETCH_ASSOC);
    $headerId = $rowHeader['id'];

    // Insert into tblSalaryDetail
    $columnMap = [
        'Department Code Desc' => 1,
        'Job Class Code Desc' => 2,
        'Scheduled Hours' => 3,
        'Hourly Rate' => 4,
        'Annual Pay' => 5
    ];
    $rowID = 0;
    foreach ($data as $row) {
        if (!isset($row['checked']) || !$row['checked']) {
            continue;
        }
        foreach ($columnMap as $dataType => $columnId) {
            if (isset($row[$columnId]) && $row[$columnId] !== '') {
                $value = $row[$columnId];
                if ($columnId >= 3) { // Numeric fields
                    $value = preg_replace('/[\$,]/', '', $value);
                    if (!is_numeric($value)) {
                        continue; // Skip invalid numbers
                    }
                }
                $queryDetail = "INSERT INTO tblSalaryDetail (headerid, DataType, DataValue, columnId, rowID) VALUES (?, ?, ?, ?, ?)";
                $paramsDetail = [$headerId, $dataType, $value, $columnId, $rowID];
                $stmtDetail = sqlsrv_query($connSql, $queryDetail, $paramsDetail);
                if ($stmtDetail === false) {
                    throw new Exception("Error inserting into tblSalaryDetail: " . print_r(sqlsrv_errors(), true));
                }
            }
        }
        $rowID = $rowID + 1;
    }

    // Commit transaction
    sqlsrv_commit($connSql);

    // Update session expiry
    $_SESSION['session_expiry'] = time() + 43200; // 12 hours

    echo json_encode(['success' => true, 'message' => 'Data submitted successfully.']);

} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    echo json_encode(['success' => false, 'message' => 'Error saving data: ' . $e->getMessage()]);
}

sqlsrv_close($connSql);
?>