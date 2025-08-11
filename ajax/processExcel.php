<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated or session expired.']);
    exit;
}

if (!isset($_FILES['excelFile']) && !isset($_POST['action'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or action specified.']);
    exit;
}

require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

try {
    sqlsrv_begin_transaction($connSql);

    if (isset($_FILES['excelFile'])) {
        // Initial file processing
        $queryHeader = "INSERT INTO tblSalaryHeader (email, town, uploadDate) OUTPUT INSERTED.id VALUES (?, ?, GETDATE())";
        $paramsHeader = [$_SESSION['auth_email'], $_SESSION['auth_town']];
        $stmtHeader = sqlsrv_query($connSql, $queryHeader, $paramsHeader);
        if ($stmtHeader === false) {
            throw new Exception("Error inserting into tblSalaryHeader: " . print_r(sqlsrv_errors(), true));
        }
        $rowHeader = sqlsrv_fetch_array($stmtHeader, SQLSRV_FETCH_ASSOC);
        $headerId = $rowHeader['id'];
        sqlsrv_free_stmt($stmtHeader);

        $inputFileName = $_FILES['excelFile']['tmp_name'];
        $reader = new Xlsx();
        $spreadsheet = $reader->load($inputFileName);
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        error_log("Sheet Data: " . print_r($sheetData, true));

        if (empty($sheetData) || !isset($sheetData[1])) {
            throw new Exception("No data found in the Excel sheet");
        }

        $requiredColumns = [
            'Department Code Desc' => null,
            'Job Class Code Desc' => null,
            'Scheduled Hours' => null,
            'Hourly Rate' => null,
            'Annual Pay' => null
        ];
        $headerRow = $sheetData[1];
        $columnMap = [];
        foreach ($headerRow as $col => $value) {
            $value = trim(strtolower($value));
            foreach ($requiredColumns as $header => $mappedCol) {
                if ($value === strtolower($header)) {
                    $columnMap[$header] = $col;
                    break;
                }
            }
        }
        error_log("Column Map: " . print_r($columnMap, true));
        foreach ($requiredColumns as $header => $mappedCol) {
            if (!isset($columnMap[$header])) {
                error_log("Warning: Required column '$header' not found, using default empty value");
                $columnMap[$header] = null;
            }
        }

        $processedData = [];
        $rowID = 0; // Start index at 0 for array
        foreach ($sheetData as $index => $row) {
            if ($index <= 1) {
                continue;
            }

            $departmentCodeDesc = isset($columnMap['Department Code Desc']) && isset($row[$columnMap['Department Code Desc']]) ? trim($row[$columnMap['Department Code Desc']]) : '';
            $jobClassCodeDesc = isset($columnMap['Job Class Code Desc']) && isset($row[$columnMap['Job Class Code Desc']]) ? trim($row[$columnMap['Job Class Code Desc']]) : '';
            $scheduledHours = isset($columnMap['Scheduled Hours']) && isset($row[$columnMap['Scheduled Hours']]) ? trim(preg_replace('/[\$,]/', '', $row[$columnMap['Scheduled Hours']])) : '';
            $hourlyRate = isset($columnMap['Hourly Rate']) && isset($row[$columnMap['Hourly Rate']]) ? trim(preg_replace('/[\$,]/', '', $row[$columnMap['Hourly Rate']])) : '0.00';
            $annualPay = isset($columnMap['Annual Pay']) && isset($row[$columnMap['Annual Pay']]) ? trim(preg_replace('/[\$,]/', '', $row[$columnMap['Annual Pay']])) : '0.00';

            error_log("Row $rowID - DepartmentCodeDesc: '$departmentCodeDesc', JobClassCodeDesc: '$jobClassCodeDesc', ScheduledHours: '$scheduledHours'");

            if (($departmentCodeDesc === '' || $jobClassCodeDesc === '') || ($scheduledHours === '' && !is_numeric($scheduledHours))) {
                throw new Exception("Missing or invalid required fields for row $rowID");
            }

            $processedData[$rowID] = [
                1 => $departmentCodeDesc,
                2 => $jobClassCodeDesc,
                3 => $scheduledHours,
                4 => $hourlyRate,
                5 => $annualPay
            ];
            $rowID++;
        }

        sqlsrv_commit($connSql);
        echo json_encode(['success' => true, 'message' => 'Excel data processed successfully.', 'data' => $processedData, 'headerId' => $headerId, 'email' => $_SESSION['auth_email'], 'town' => $_SESSION['auth_town']]);
    } elseif (isset($_POST['action']) && $_POST['action'] === 'saveSelected') {
        // Save selected rows
        $headerId = $_POST['headerId'];
        $selectedRows = array_map('intval', (array)$_POST['selectedRows']);
        $processedData = json_decode($_SESSION['processed_data'], true);

        if (!isset($processedData)) {
            throw new Exception("No processed data available to save.");
        }

        $queryDetails = "INSERT INTO tblSalaryDetails (headerid, DepartmentCodeDesc, JobClassCodeDesc, ScheduledHours, HourlyRate, AnnualPay, Degree, StandardDeptID, StandardJobClassID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        foreach ($processedData as $rowId => $row) {
            if (in_array($rowId + 1, $selectedRows)) { // Adjust index to match rowId
                $scheduledHours = floatval($row[3]);
                $hourlyRate = floatval($row[4]);
                $annualPay = floatval($row[5]);
                $paramsDetails = [$headerId, $row[1], $row[2], $scheduledHours, $hourlyRate, $annualPay, '', null, null];
                $stmtDetails = sqlsrv_query($connSql, $queryDetails, $paramsDetails);
                if ($stmtDetails === false) {
                    throw new Exception("Error inserting into tblSalaryDetails: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmtDetails);
            }
        }

        sqlsrv_commit($connSql);
        echo json_encode(['success' => true, 'message' => 'Selected rows saved successfully.']);
    }
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    echo json_encode(['success' => false, 'message' => 'Error processing Excel: ' . $e->getMessage()]);
} finally {
    if (isset($processedData)) {
        $_SESSION['processed_data'] = json_encode($processedData);
    }
    sqlsrv_close($connSql);
}
?>