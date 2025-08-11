<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated or session expired.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['headerId']) || !isset($_POST['data'])) {
    error_log("updateData.php REQUEST_METHOD:".$_SERVER['REQUEST_METHOD']." headerid:".isset($_POST['headerId'])." data:".isset($_POST['data']));
    echo json_encode(['success' => false, 'message' => 'Invalid request or missing data.']);
    exit;
}

try {
    sqlsrv_begin_transaction($connSql);

    // Verify tblSalaryHeader record
    $headerId = intval($_POST['headerId']);
    $queryHeader = "SELECT id FROM tblSalaryHeader WHERE id = ? AND email = ? AND town = ?";
    $paramsHeader = [$headerId, $_SESSION['auth_email'], $_SESSION['auth_town']];
    $stmtHeader = sqlsrv_query($connSql, $queryHeader, $paramsHeader);

    if ($stmtHeader === false || sqlsrv_fetch($stmtHeader) === false) {
        throw new Exception("Invalid header ID or no matching salary header record.");
    }
    sqlsrv_free_stmt($stmtHeader);

    // Process and validate data for tblSalaryDetails
    $data = json_decode($_POST['data'], true);
    if (!is_array($data)) {
        throw new Exception("Invalid data format.");
    }

    // Separate checked and unchecked rows
    $existingRows = []; // Assume we fetch existing rows to compare
    $queryExisting = "SELECT rowId, DepartmentCodeDesc, JobClassCodeDesc, ScheduledHours, HourlyRate, AnnualPay, Degree, StandardDeptID, StandardJobClassID, deleted 
                      FROM tblSalaryDetails WHERE headerid = ?";
    $stmtExisting = sqlsrv_query($connSql, $queryExisting, [$headerId]);
    while ($row = sqlsrv_fetch_array($stmtExisting, SQLSRV_FETCH_ASSOC)) {
        $existingRows[$row['rowId']] = $row;
    }
    sqlsrv_free_stmt($stmtExisting);

    // Update existing rows to mark unchecked as deleted
    foreach ($existingRows as $rowId => $row) {
        $isChecked = false;
        foreach ($data as $item) {
            if (isset($item['rowId']) && $item['rowId'] == $rowId && isset($item['checked']) && $item['checked']) {
                $isChecked = true;
                break;
            }
        }
        if (!$isChecked && !$row['deleted']) { // Only update if not already deleted
            $queryUpdate = "UPDATE tblSalaryDetails SET deleted = 1 WHERE headerid = ? AND rowId = ?";
            $paramsUpdate = [$headerId, $rowId];
            $stmtUpdate = sqlsrv_query($connSql, $queryUpdate, $paramsUpdate);
            if ($stmtUpdate === false) {
                throw new Exception("Error marking row as deleted: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmtUpdate);
        }
    }

    // Insert or update checked rows
    foreach ($data as $item) {
        if (isset($item['rowId']) && isset($item['checked']) && $item['checked']) {
            $rowId = intval($item['rowId']);
            $departmentCodeDesc = isset($item['DepartmentCodeDesc']) ? trim($item['DepartmentCodeDesc']) : '';
            $jobClassCodeDesc = isset($item['JobClassCodeDesc']) ? trim($item['JobClassCodeDesc']) : '';
            $scheduledHours = isset($item['ScheduledHours']) ? trim(preg_replace('/[\$,]/', '', $item['ScheduledHours'])) : '';
            $hourlyRate = isset($item['HourlyRate']) ? trim(preg_replace('/[\$,]/', '', $item['HourlyRate'])) : '0.00';
            $annualPay = isset($item['AnnualPay']) ? trim(preg_replace('/[\$,]/', '', $item['AnnualPay'])) : '0.00';
            $degree = isset($item['Degree']) ? trim($item['Degree']) : '';
            $standardDeptID = isset($item['StandardDeptID']) ? $item['StandardDeptID'] : null;
            $standardJobClassID = isset($item['StandardJobClassID']) ? $item['StandardJobClassID'] : null;

            if ($departmentCodeDesc === '' || $jobClassCodeDesc === '' || ($scheduledHours === '' && !is_numeric($scheduledHours))) {
                throw new Exception("Missing or invalid required fields for row $rowId.");
            }

            $scheduledHours = floatval($scheduledHours);
            $hourlyRate = floatval($hourlyRate);
            $annualPay = floatval($annualPay);

            // Check if row exists and update, otherwise insert
            if (isset($existingRows[$rowId])) {
                $queryUpdate = "UPDATE tblSalaryDetails 
                                SET DepartmentCodeDesc = ?, JobClassCodeDesc = ?, ScheduledHours = ?, HourlyRate = ?, AnnualPay = ?, Degree = ?, StandardDeptID = ?, StandardJobClassID = ?, deleted = 0 
                                WHERE headerid = ? AND rowId = ?";
                $paramsUpdate = [$departmentCodeDesc, $jobClassCodeDesc, $scheduledHours, $hourlyRate, $annualPay, $degree, $standardDeptID, $standardJobClassID, $headerId, $rowId];
                $stmtUpdate = sqlsrv_query($connSql, $queryUpdate, $paramsUpdate);
                if ($stmtUpdate === false) {
                    throw new Exception("Error updating row $rowId: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmtUpdate);
            } else {
                $queryInsert = "INSERT INTO tblSalaryDetails (headerid, rowId, DepartmentCodeDesc, JobClassCodeDesc, ScheduledHours, HourlyRate, AnnualPay, Degree, StandardDeptID, StandardJobClassID, deleted) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                $paramsInsert = [$headerId, $rowId, $departmentCodeDesc, $jobClassCodeDesc, $scheduledHours, $hourlyRate, $annualPay, $degree, $standardDeptID, $standardJobClassID];
                $stmtInsert = sqlsrv_query($connSql, $queryInsert, $paramsInsert);
                if ($stmtInsert === false) {
                    throw new Exception("Error inserting row $rowId: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmtInsert);
            }
        }
    }

    sqlsrv_commit($connSql);
    echo json_encode(['success' => true, 'message' => 'Data updated successfully.']);
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    echo json_encode(['success' => false, 'message' => 'Error updating data: ' . $e->getMessage()]);
} finally {
    if (isset($stmtHeader) || isset($stmtExisting) || isset($stmtUpdate) || isset($stmtInsert)) {
        sqlsrv_free_stmt($stmtHeader);
        sqlsrv_free_stmt($stmtExisting);
        sqlsrv_free_stmt($stmtUpdate);
        sqlsrv_free_stmt($stmtInsert);
    }
    sqlsrv_close($connSql);
}
?>