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
    $queryHeader = "INSERT INTO tblSalaryHeader (email, town, uploadDate) OUTPUT INSERTED.id VALUES (?, ?, GETDATE())";
    $paramsHeader = [$_SESSION['auth_email'], $_SESSION['auth_town']];
    $stmtHeader = sqlsrv_query($connSql, $queryHeader, $paramsHeader);
    if ($stmtHeader === false) {
        throw new Exception("Error inserting into tblSalaryHeader: " . print_r(sqlsrv_errors(), true));
    }
    $rowHeader = sqlsrv_fetch_array($stmtHeader, SQLSRV_FETCH_ASSOC);
    $headerId = $rowHeader['id'];
    sqlsrv_free_stmt($stmtHeader);

    // Insert into tblSalaryDetail and tblDepartmentJobMapping
    $queryDetail = "INSERT INTO tblSalaryDetail (HeaderID, DataType, DataValue, ColumnID, rowID, StandardDeptID, StandardJobClassID) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $queryMapping = "INSERT INTO tblDepartmentJobMapping (HeaderID, OriginalDeptDesc, StandardDeptID, OriginalJobClassDesc, StandardJobClassID) VALUES (?, ?, ?, ?, ?)";
    $rowID = 0;
    foreach ($data as $row) {
        if (!isset($row['checked']) || !$row['checked']) {
            continue;
        }
        foreach ([1 => 'Department Code Desc', 2 => 'Job Class Code Desc', 3 => 'Scheduled Hours', 4 => 'Hourly Rate', 5 => 'Annual Pay'] as $columnId => $dataType) {
            if (isset($row[$columnId]) && $row[$columnId] !== '') {
                $value = $row[$columnId];
                if ($columnId >= 3) { // Numeric fields
                    $value = preg_replace('/[\$,]/', '', $value);
                    if (!is_numeric($value)) {
                        continue; // Skip invalid numbers
                    }
                }
                $standardDeptID = ($columnId == 1 && isset($row['standardDeptID'])) ? $row['standardDeptID'] : null;
                $standardJobClassID = ($columnId == 2 && isset($row['standardJobClassID'])) ? $row['standardJobClassID'] : null;
                $paramsDetail = [$headerId, $dataType, $value, $columnId, $rowID, $standardDeptID, $standardJobClassID];
                $stmtDetail = sqlsrv_query($connSql, $queryDetail, $paramsDetail);
                if ($stmtDetail === false) {
                    throw new Exception("Error inserting into tblSalaryDetail: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmtDetail);
            }
        }
        // Insert mapping
        $paramsMapping = [
            $headerId,
            $row[1] ?? null,
            isset($row['standardDeptID']) ? $row['standardDeptID'] : null,
            $row[2] ?? null,
            isset($row['standardJobClassID']) ? $row['standardJobClassID'] : null
        ];
        $stmtMapping = sqlsrv_query($connSql, $queryMapping, $paramsMapping);
        if ($stmtMapping === false) {
            throw new Exception("Error inserting into tblDepartmentJobMapping: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmtMapping);
        $rowID++;
    }

    // Commit transaction
    sqlsrv_commit($connSql);

    // Update session expiry
    $_SESSION['session_expiry'] = time() + 43200; // 12 hours

    echo json_encode(['success' => true, 'message' => 'Data submitted successfully.']);
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    echo json_encode(['success' => false, 'message' => 'Error saving data: ' . $e->getMessage()]);
} finally {
    sqlsrv_close($connSql);
}
?>