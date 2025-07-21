<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

$response = [
    'success' => false,
    'data' => [],
    'message' => 'Not authenticated or session expired.'
];

if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
    echo json_encode($response);
    exit;
}

$headerId = isset($_POST['headerId']) ? (int)$_POST['headerId'] : null;

if (!$headerId) {
    $response['message'] = 'No header ID provided.';
    echo json_encode($response);
    exit;
}

try {
    // Verify the header belongs to the user's town
    $town = $_SESSION['auth_town'] ?? null;
    $email = $_SESSION['auth_email'] ?? null;

    if (!$town || !$email) {
        $response['message'] = 'Town or email not found in session.';
        echo json_encode($response);
        exit;
    }

    $query = "SELECT id FROM tblSalaryHeader WHERE id = ? AND Town = ? AND Email = ?";
    $stmt = sqlsrv_query($connSql, $query, [$headerId, $town, $email]);
    if ($stmt === false) {
        $response['message'] = 'Error verifying header: ' . print_r(sqlsrv_errors(), true);
        echo json_encode($response);
        exit;
    }

    if (!sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $response['message'] = 'Header not found or not authorized.';
        sqlsrv_free_stmt($stmt);
        echo json_encode($response);
        exit;
    }
    sqlsrv_free_stmt($stmt);

    // Retrieve from tblSalaryDetail
    $query = "SELECT ID, HeaderID, rowID, ColumnID, DataValue, DataType, StandardDeptID, StandardJobClassID FROM tblSalaryDetail WHERE HeaderID = ? ORDER BY HeaderID, rowID, ColumnID";
    $stmt = sqlsrv_query($connSql, $query, [$headerId]);
    if ($stmt === false) {
        $response['message'] = 'Error retrieving data: ' . print_r(sqlsrv_errors(), true);
        echo json_encode($response);
        exit;
    }

    $rows = [];
    $currentRow = [];
    $lastRowId = null;
    $expectedColumnId = 1;

    while ($detail = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rowId = $detail['rowID'];
        $columnId = $detail['ColumnID'];
        $dataValue = is_null($detail['DataValue']) ? '' : (string)$detail['DataValue'];

        if ($lastRowId !== $rowId && !empty($currentRow)) {
            if (count($currentRow) >= 5) {
                $currentRow['checked'] = true;
                $rows[] = $currentRow;
            }
            $currentRow = [];
            $expectedColumnId = 1;
        }

        if ($columnId != $expectedColumnId) {
            error_log("Unexpected ColumnID $columnId for rowID $rowId, expected $expectedColumnId");
            $response['message'] = "Unexpected ColumnID $columnId sequence for rowID $rowId, expected $expectedColumnId. sql:$query for header $headerId";
            sqlsrv_free_stmt($stmt);
            echo json_encode($response);
            exit;
        }

        $currentRow[$columnId] = $dataValue;
        if ($columnId == 1) {
            $currentRow['standardDeptID'] = $detail['StandardDeptID'];
        }
        if ($columnId == 2) {
            $currentRow['standardJobClassID'] = $detail['StandardJobClassID'];
        }
        $expectedColumnId++;
        if ($expectedColumnId > 5) {
            $expectedColumnId = 1;
        }
        $lastRowId = $rowId;
    }

    if (!empty($currentRow)) {
        $currentRow['checked'] = true;
        $rows[] = $currentRow;
    }

    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['data'] = $rows;
    $response['message'] = 'Data retrieved successfully.';
    error_log("loadDetailData.php returning: " . json_encode($response));
    echo json_encode($response);
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    echo json_encode($response);
} finally {
    sqlsrv_close($connSql);
}
?>