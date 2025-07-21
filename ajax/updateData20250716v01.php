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

$town = $_SESSION['auth_town'] ?? null;
$email = $_SESSION['auth_email'] ?? null;

if (!$town || !$email) {
    $response['message'] = 'Town or email not found in session.';
    echo json_encode($response);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
error_log("Received input: " . print_r($input, true)); // Debug
error_log("Received data: " . print_r($data, true)); // Debug

if (!$data || empty($data['data'])) {
    $response['message'] = 'No data to update.';
    echo json_encode($response);
    exit;
}

try {
    // Begin transaction
    sqlsrv_begin_transaction($connSql);

    $headerId = (int)$data['headerId'];
    $tbldata = $data['data'];

    // Verify header
    $query = "SELECT ID FROM tblSalaryHeader WHERE ID = ? AND Town = ? AND Email = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId, $town, $email]);
    if ($stmt === false) {
        error_log("Error retrieving header: " . print_r(sqlsrv_errors(), true));
        throw new Exception('Error retrieving header: ' . print_r(sqlsrv_errors(), true));
    }

    if (!sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['message'] = 'Header not found or not authorized.';
        sqlsrv_free_stmt($stmt);
        sqlsrv_rollback($connSql);
        echo json_encode($response);
        exit;
    }
    sqlsrv_free_stmt($stmt);

    // Delete existing details and mappings
    $query = "DELETE FROM tblSalaryDetail WHERE HeaderID = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId]);
    if ($stmt === false) {
        error_log("Error deleting details: " . print_r(sqlsrv_errors(), true));
        throw new Exception('Error deleting existing details: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    $query = "DELETE FROM tblDepartmentJobMapping WHERE HeaderID = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId]);
    if ($stmt === false) {
        error_log("Error deleting mappings: " . print_r(sqlsrv_errors(), true));
        throw new Exception('Error deleting existing mappings: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Update tblSalaryHeader timestamp
    $query = "UPDATE tblSalaryHeader SET uploadDate = GETDATE() WHERE ID = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId]);
    if ($stmt === false) {
        error_log("Error updating header: " . print_r(sqlsrv_errors(), true));
        throw new Exception('Error updating header: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    // Insert new details and mappings
    $queryDetail = "INSERT INTO tblSalaryDetail (HeaderID, ColumnID, DataType, DataValue, rowID, StandardDeptID, StandardJobClassID) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $queryMapping = "INSERT INTO tblDepartmentJobMapping (HeaderID, OriginalDeptDesc, StandardDeptID, OriginalJobClassDesc, StandardJobClassID) VALUES (?, ?, ?, ?, ?)";
    $insertedRows = 0;
    $rowID = 0;
    foreach ($tbldata as $row) {
        if (!isset($row['checked']) || !$row['checked']) {
            error_log("Not checked Skipping row: " . print_r($row, true));
            continue;
        }
        error_log("Checked processing row: " . print_r($row, true));
        for ($columnId = 1; $columnId <= 5; $columnId++) {
            $value = $row[$columnId] ?? '';
            if ($value === '') {
                error_log("Empty value for column $columnId in row: " . print_r($row, true));
                continue;
            }
            $dataType = ($columnId <= 2) ? 'string' : 'decimal';
            if ($dataType === 'decimal' && !is_numeric($value)) {
                error_log("Invalid decimal value for column $columnId: $value");
                throw new Exception("Invalid decimal value for column $columnId.");
            }
            $standardDeptID = ($columnId == 1 && isset($row['standardDeptID'])) ? $row['standardDeptID'] : null;
            $standardJobClassID = ($columnId == 2 && isset($row['standardJobClassID'])) ? $row['standardJobClassID'] : null;
            $params = [$headerId, $columnId, $dataType, $value, $rowID, $standardDeptID, $standardJobClassID];
            $stmt = sqlsrv_query($connSql, $queryDetail, $params);
            if ($stmt === false) {
                error_log("Error inserting detail for column $columnId: " . print_r(sqlsrv_errors(), true));
                throw new Exception('Error inserting detail: ' . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($stmt);
            $insertedRows++;
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
            error_log("Error inserting mapping: " . print_r(sqlsrv_errors(), true));
            throw new Exception('Error inserting mapping: ' . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($stmtMapping);
        $rowID++;
    }
    error_log("Inserted $insertedRows values");

    // Commit transaction
    sqlsrv_commit($connSql);

    $response['success'] = true;
    $response['message'] = 'Data updated successfully.';
    echo json_encode($response);
} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    error_log("Error in updateData.php: " . $e->getMessage());
    $response['message'] = 'Error updating data: ' . $e->getMessage();
    echo json_encode($response);
} finally {
    sqlsrv_close($connSql);
}
?>