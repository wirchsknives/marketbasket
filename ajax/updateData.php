<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

// Log the full POST data for debugging
error_log("updateData.php REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . " POST: " . var_export($_POST, true));

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated or session expired.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Attempt to parse from $_POST
$headerId = isset($_POST['headerId']) ? trim($_POST['headerId']) : '';

// If $_POST is empty, read from php://input (for JSON body)
$input = file_get_contents('php://input');
$inputData = json_decode($input, true);
if (empty($headerId)) {
    $headerId = isset($inputData['headerId']) ? trim($inputData['headerId']) : '';
}
$data = isset($inputData['data']) ? $inputData['data'] : [];

// Log parsed data
error_log("updateData.php line 24 headerId: " . $headerId);
error_log("updateData.php line 24 data: " . print_r($data, true));

if (empty($headerId) || !is_array($data) || empty($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. headerId or data is missing or empty.']);
    exit;
}

try {
    sqlsrv_begin_transaction($connSql);

    // Verify tblSalaryHeader record
    $headerIdInt = intval($headerId);
error_log("updateData.php line 44 headerId: " . $headerIdInt);    
    $queryHeader = "SELECT id FROM tblSalaryHeader WHERE id = ? AND email = ? AND town = ?";
    $paramsHeader = [$headerIdInt, $_SESSION['auth_email'], $_SESSION['auth_town']];
    $stmtHeader = sqlsrv_query($connSql, $queryHeader, $paramsHeader);

    if ($stmtHeader === false || sqlsrv_fetch($stmtHeader) === false) {
        throw new Exception("Invalid header ID or no matching salary header record.");
    }
    sqlsrv_free_stmt($stmtHeader);

    // Process and validate data for tblSalaryDetails
    // Separate checked and unchecked rows
    $existingRows = [];
    $queryExisting = "SELECT id as rowId, DepartmentCodeDesc, JobClassCodeDesc, ScheduledHours, HourlyRate, AnnualPay, Degree, StandardDeptID, StandardJobClassID, deleted 
                      FROM tblSalaryDetails WHERE headerid = ?";
    $stmtExisting = sqlsrv_query($connSql, $queryExisting, [$headerIdInt]);
    while ($row = sqlsrv_fetch_array($stmtExisting, SQLSRV_FETCH_ASSOC)) {
        $existingRows[$row['rowId']] = $row;
    }
    sqlsrv_free_stmt($stmtExisting);

    // Update existing rows to mark unchecked as deleted
    $i = 0;
    foreach ($existingRows as $rowId => $row) {
        $isChecked = false;
//        foreach ($data as $item) {
//            if (isset($row['rowId']) && $row['rowId'] == $rowId && isset($item['checked']) && $item['checked'] == false ) {
              if (isset($row['rowId']) && $row['rowId'] == $rowId && isset($data[$i]['checked']) && $data[$i]['checked'] == 1 ) {
error_log("updateData.php line 70 row:".$row['rowId']." checked:".$data[$i]['checked']);                
                $isChecked = true;
//                break;
            } else {
error_log("updateData.php line 74 rowId:".$rowId." rowidArray:".$row['rowId']." checked:".$data[$i]['checked']." isChecked:".var_export($isChecked, true));                                
            }
//        }
        if (!$isChecked && !$row['deleted']) {
error_log("updateData.php line 78 Setting header:".$headerIdInt." id:".$rowId);            
            $queryUpdate = "UPDATE tblSalaryDetails SET deleted = 1 WHERE headerid = ? AND id = ?";
            $paramsUpdate = [$headerIdInt, $rowId];
            $stmtUpdate = sqlsrv_query($connSql, $queryUpdate, $paramsUpdate);
            if ($stmtUpdate === false) {
                throw new Exception("Error marking row as deleted: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmtUpdate);
        }
        $i = $i + 1;
    }

    // Insert or update checked rows
    foreach ($data as $item) {
error_log("updateData.php line 94 item: " . print_r($item, true));
        if (isset($item['checked']) && $item['checked'] ) {
            $rowId = intval($item['10']);
            $rowId = isset($item['10']) ? intval($item['10']) : 0; // 0 for new rows
            $departmentCodeDesc = isset($item['1']) ? trim($item['1']) : '';
            $jobClassCodeDesc = isset($item['2']) ? trim($item['2']) : '';
            $scheduledHours = isset($item['3']) ? trim(preg_replace('/[\$,]/', '', $item['3'])) : '';
            $hourlyRate = isset($item['4']) ? trim(preg_replace('/[\$,]/', '', $item['4'])) : '0.00';
            $annualPay = isset($item['5']) ? trim(preg_replace('/[\$,]/', '', $item['5'])) : '0.00';
            $degree = isset($item['6']) ? trim($item['6']) : '';
            if (isset($item['standardDeptID'])){
                $standardDeptID = isset($item['standardDeptID']) ? $item['standardDeptID'] : null;
                $standardJobClassID = isset($item['standardJobClassID']) ? $item['standardJobClassID'] : null;
            } else {
                $standardDeptID = isset($item['7']) ? $item['7'] : null;
                $standardJobClassID = isset($item['8']) ? $item['8'] : null;
            }
            

            if ($departmentCodeDesc === '' || $jobClassCodeDesc === '' || ($scheduledHours === '' && !is_numeric($scheduledHours))) {
                throw new Exception("Missing or invalid required fields for row $rowId.");
            }

            $scheduledHours = floatval($scheduledHours);
            $hourlyRate = floatval($hourlyRate);
            $annualPay = floatval($annualPay);

            //if (isset($existingRows[$rowId])) {
            if ($rowId > 0 && isset($existingRows[$rowId])) {
                $queryUpdate = "UPDATE tblSalaryDetails 
                                SET DepartmentCodeDesc = ?, JobClassCodeDesc = ?, ScheduledHours = ?, HourlyRate = ?, AnnualPay = ?, Degree = ?, StandardDeptID = ?, StandardJobClassID = ?, deleted = 0 
                                WHERE headerid = ? AND id = ?";
                $paramsUpdate = [$departmentCodeDesc, $jobClassCodeDesc, $scheduledHours, $hourlyRate, $annualPay, $degree, $standardDeptID, $standardJobClassID, $headerIdInt, $rowId];
                $stmtUpdate = sqlsrv_query($connSql, $queryUpdate, $paramsUpdate);
                if ($stmtUpdate === false) {
error_log("updatData.php row 121 sql error standardDeptID:".$standardDeptID." standardJobClassID:".$standardJobClassID);                    
                    throw new Exception("Error updating row $rowId: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmtUpdate);
            } else {
                $queryInsert = "INSERT INTO tblSalaryDetails (headerid, DepartmentCodeDesc, JobClassCodeDesc, ScheduledHours, HourlyRate, AnnualPay, Degree, StandardDeptID, StandardJobClassID, deleted) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                $paramsInsert = [$headerIdInt, $departmentCodeDesc, $jobClassCodeDesc, $scheduledHours, $hourlyRate, $annualPay, $degree, $standardDeptID, $standardJobClassID];
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
      //  sqlsrv_free_stmt($stmtHeader);
      //  sqlsrv_free_stmt($stmtExisting);
      //  sqlsrv_free_stmt($stmtUpdate);
      //  sqlsrv_free_stmt($stmtInsert);
    }
    sqlsrv_close($connSql);
}
?>