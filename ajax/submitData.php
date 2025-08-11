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

    // Insert into tblSalaryDetails and tblDepartmentJobMapping
    $queryDetails = "INSERT INTO tblSalaryDetails (headerid, DepartmentCodeDesc, JobClassCodeDesc, ScheduledHours, HourlyRate, AnnualPay, Degree, StandardDeptID, StandardJobClassID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $queryMapping = "INSERT INTO tblDepartmentJobMapping (HeaderID, OriginalDeptDesc, StandardDeptID, OriginalJobClassDesc, StandardJobClassID) VALUES (?, ?, ?, ?, ?)";
    $rowID = 0; // Kept for mapping table consistency
    foreach ($data as $row) {
        if (!isset($row['checked']) || !$row['checked']) {
            continue;
        }

        // Map and validate data
        $departmentCodeDesc = isset($row[1]) && $row[1] !== '' ? $row[1] : '';
        $jobClassCodeDesc = isset($row[2]) && $row[2] !== '' ? $row[2] : '';
        $scheduledHours = isset($row[3]) && $row[3] !== '' ? preg_replace('/[\$,]/', '', $row[3]) : '';
        $hourlyRate = isset($row[4]) && $row[4] !== '' ? preg_replace('/[\$,]/', '', $row[4]) : '0.00';
        if (!is_numeric($hourlyRate)) {
            throw new Exception("Invalid HourlyRate for row $rowID");
        }
        $hourlyRate = floatval($hourlyRate);
        $annualPay = isset($row[5]) && $row[5] !== '' ? preg_replace('/[\$,]/', '', $row[5]) : '0.00';
        if (!is_numeric($annualPay)) {
            throw new Exception("Invalid AnnualPay for row $rowID");
        }
        $annualPay = floatval($annualPay);
        $degree = isset($row[6]) && $row[6] !== '' ? $row[6] : ''; // Assuming Degree maps to row[6] or a new field
        $standardDeptID = isset($row['standardDeptID']) ? $row['standardDeptID'] : null;
        $standardJobClassID = isset($row['standardJobClassID']) ? $row['standardJobClassID'] : null;

        // Validate required fields
        if ($departmentCodeDesc === '' || $jobClassCodeDesc === '' || $scheduledHours === '') {
            throw new Exception("Missing required fields for row $rowID");
        }

        // Insert into tblSalaryDetails
        $paramsDetails = [$headerId, $departmentCodeDesc, $jobClassCodeDesc, $scheduledHours, $hourlyRate, $annualPay, $degree, $standardDeptID, $standardJobClassID];
        $stmtDetails = sqlsrv_query($connSql, $queryDetails, $paramsDetails);
        if ($stmtDetails === false) {
            throw new Exception("Error inserting into tblSalaryDetails: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmtDetails);

        // Insert into tblDepartmentJobMapping
        $paramsMapping = [
            $headerId,
            $departmentCodeDesc,
            $standardDeptID,
            $jobClassCodeDesc,
            $standardJobClassID
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