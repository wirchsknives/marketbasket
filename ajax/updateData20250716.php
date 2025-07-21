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
// Log session details
error_log("Session - Town: $town, Email: $email");
$input = file_get_contents('php://input');
$data = json_decode($input, true);
error_log("Received input: " . print_r($input, true)); // Debug
error_log("Received data: " . print_r($data, true)); // Debug

if (!$data || empty($data)) {
    $response['message'] = 'No data to update.';
    echo json_encode($response);
    exit;
}

try {
    // Begin transaction
    sqlsrv_begin_transaction($connSql);

    // Get existing ID from tblSalaryHeader
    $query = "SELECT ID FROM tblSalaryHeader WHERE Town = ? AND Email = ?";
    $stmt = sqlsrv_query($connSql, $query, [$town, $email]);

    if ($stmt === false) {
        error_log("Error retrieving header: " . print_r(sqlsrv_errors(), true)); // Debug
        throw new Exception('Error retrieving header: ' . print_r(sqlsrv_errors(), true));
    }

    $header = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$header) {
        $response['message'] = 'No existing record found to update.';
        echo json_encode($response);
        sqlsrv_rollback($connSql);
        exit;
    }

    $headerId = $header['ID'];
error_log("HeaderID: $headerId"); // Debug
    // Delete existing details for this HeaderID
    $query = "DELETE FROM tblSalaryDetail WHERE HeaderID = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId]);

    if ($stmt === false) {
        error_log("Error deleting details: " . print_r(sqlsrv_errors(), true)); // Debug
        throw new Exception('Error deleting existing details: ' . print_r(sqlsrv_errors(), true));
    }

    sqlsrv_free_stmt($stmt);

    // Update tblSalaryHeader (update timestamp)
    $query = "UPDATE tblSalaryHeader SET uploadDate = GETDATE() WHERE ID = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId]);

    if ($stmt === false) {
        error_log("Error updating header: " . print_r(sqlsrv_errors(), true)); // Debug
        throw new Exception('Error updating header: ' . print_r(sqlsrv_errors(), true));
    }

    sqlsrv_free_stmt($stmt);

    // Insert new details
     $insertedRows = 0;
    $tbldata = $data['data'];
    $rowID = 0;
    foreach ($tbldata as $row) {
        if (!isset($row['checked']) || !$row['checked']) {
            error_log("Not checked Skipping row: " . print_r($row, true)); // Debug
            continue; // Skip unchecked rows
        }
        error_log("Checked processing row: " . print_r($row, true)); // Debug
        for ($columnId = 1; $columnId <= 5; $columnId++) {
            $value = $row[$columnId] ?? '';
            if ($value === ''){
                error_log("Empty value for column $columnId in row: " . print_r($row, true)); // Debug
                continue;
            }

            // Set DataType based on columnId
            $dataType = ($columnId <= 2) ? 'string' : 'decimal';
            if ($dataType === 'decimal' && !is_numeric($value)) {
                error_log("Invalid decimal value for column $columnId: $value"); // Debug
                throw new Exception("Invalid decimal value for column $columnId.");
            }
            $query = "INSERT INTO tblSalaryDetail (HeaderID, ColumnID, DataType, DataValue, rowID) VALUES (?, ?, ?, ?, ?)";
            $params = [$headerId, $columnId, $dataType, $value, $rowID];
            $stmt = sqlsrv_query($connSql, $query, $params);

            if ($stmt === false) {
                error_log("Error inserting detail for column $columnId: " . print_r(sqlsrv_errors(), true)); // Debug
                throw new Exception('Error inserting detail: ' . print_r(sqlsrv_errors(), true));
            }

            sqlsrv_free_stmt($stmt);
            $insertedRows++;
        }
        $rowID = $rowID + 1;
    }
    error_log("Inserted $insertedRows values"); // Debug
    // Commit transaction
    sqlsrv_commit($connSql);

    $response['success'] = true;
    $response['message'] = 'Data updated successfully.';

    echo json_encode($response);

} catch (Exception $e) {
    sqlsrv_rollback($connSql);
    error_log("Error in updateData.php: " . $e->getMessage()); // Debug
    $response['message'] = 'Error updating data: ' . $e->getMessage();
    echo json_encode($response);
}

sqlsrv_close($connSql);
?>