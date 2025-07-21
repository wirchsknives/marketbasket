<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

// Prevent debug output
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$response = [
    'success' => false,
    'message' => 'An error occurred.'
];

try {
    if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated'] || time() > $_SESSION['session_expiry']) {
        throw new Exception('Not authenticated or session expired. Please log in again.');
    }

    // Parse JSON input
    $input = file_get_contents('php://input');
    $params = json_decode($input, true);
    $action = $params['action'] ?? $_GET['action'] ?? null;

    if (!$action) {
        throw new Exception('No action specified.');
    }

    error_log("Action: $action");

    if ($action === 'summary') {
        // Fetch summary data
        $query = "
            SELECT SH.id, SH.town, YEAR(SH.uploadDate) AS UYear, SD.DataValue AS DepartmentCodeDesc, COUNT(*) AS DeptCount
            FROM dbo.tblSalaryHeader AS SH
            INNER JOIN dbo.tblSalaryDetail AS SD ON SH.id = SD.headerid
            WHERE SD.ColumnID = 1
            GROUP BY SH.id, SH.town, YEAR(SH.uploadDate), SD.DataValue
        ";
        $stmt = sqlsrv_query($connSql, $query);

        if ($stmt === false) {
            error_log("Error fetching summary: " . print_r(sqlsrv_errors(), true));
            throw new Exception('Error fetching summary data.');
        }

        $data = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $data[] = [
                'id' => $row['id'],
                'town' => $row['town'],
                'year' => $row['UYear'],
                'departmentCodeDesc' => $row['DepartmentCodeDesc'],
                'deptCount' => $row['DeptCount']
            ];
        }

        sqlsrv_free_stmt($stmt);
        $response['success'] = true;
        $response['data'] = $data;
        $response['message'] = 'Summary data retrieved successfully.';
    } elseif ($action === 'details') {
        // Get headerIds from JSON
        $headerIds = $params['headerIds'] ?? [];

        if (empty($headerIds)) {
            throw new Exception('No datasets selected.');
        }

        error_log("Header IDs: " . json_encode($headerIds));

        // Prepare dynamic placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($headerIds), '?'));
        $query = "
            SELECT d.HeaderID, d.rowID, d.ColumnID, d.DataValue, h.town, YEAR(h.uploadDate) AS UYear, d.DataType
            FROM tblSalaryDetail d
            JOIN tblSalaryHeader h ON d.HeaderID = h.ID
            WHERE d.HeaderID IN ($placeholders)
            ORDER BY d.HeaderID, d.rowID, d.ColumnID
        ";

        $stmt = sqlsrv_query($connSql, $query, $headerIds);
        if ($stmt === false) {
            error_log("Error fetching details: " . print_r(sqlsrv_errors(), true));
            throw new Exception('Error fetching detail data.');
        }

        $data = [];
        $currentRow = [];
        $lastKey = null;

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $headerId = $row['HeaderID'];
            $rowId = $row['rowID']; // Use rowID instead of id
            $columnId = $row['ColumnID'];
            $dataValue = is_null($row['DataValue']) ? '' : (string)$row['DataValue'];
            $town = $row['town'];
            $uyear = $row['UYear'];
            // Create a unique key for each record (headerId + rowID)
            $currentKey = "$headerId-$rowId";
            if ($lastKey !== $currentKey  && !empty($currentRow)) {
                if (count($currentRow) >= 5) {
                    $currentRow['town'] = $town;
                    $currentRow['UYear'] = $uyear;
                    $data[] = $currentRow;
                }
                $currentRow = [];
            }

            $currentRow["$columnId"] = $dataValue;
            $lastKey = $currentKey;
        }

        // Add the last row if complete
        if (!empty($currentRow) && count($currentRow) >= 5) {
            $currentRow['town'] = $town;
            $currentRow['UYear'] = $uyear;
            $data[] = $currentRow;
        }

        // Normalize data
        $normalizedData = [];
        foreach ($data as $rowData) {
            $row = [];
            for ($i = 1; $i <= 5; $i++) {
                $row["$i"] = $rowData[$i] ?? '';
            }
            $row['town'] = $rowData['town'] ?? '';
            $row['UYear'] = $rowData['UYear'] ?? '';
            $normalizedData[] = $row;
        }

        $response['success'] = true;
        $response['data'] = $normalizedData;
        $response['message'] = 'Detail data retrieved successfully.';
        sqlsrv_free_stmt($stmt);
    } else {
        throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    error_log("Error in loadAnalysisData.php: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

sqlsrv_close($connSql);
error_log("loadAnalysisData.php response:".json_encode($response));
echo json_encode($response);
?>