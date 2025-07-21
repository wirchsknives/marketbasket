<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

$response = [
    'success' => false,
    'hasData' => false,
    'headers' => [],
    'email' => null,
    'town' => null,
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

try {
    // Check for existing data in tblSalaryHeader for the town
    $query = "SELECT id, Email, Town, uploadDate FROM tblSalaryHeader WHERE Town = ? AND Email = ? ORDER BY uploadDate DESC";
    $params = [$town, $email];
    $stmt = sqlsrv_query($connSql, $query, $params);

    if ($stmt === false) {
        $response['message'] = 'Error checking existing data: ' . print_r(sqlsrv_errors(), true);
        echo json_encode($response);
        exit;
    }

    $headers = [];
    while ($header = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $headers[] = [
            'id' => $header['id'],
            'uploadDate' => $header['uploadDate'] ? $header['uploadDate']->format('Y-m-d H:i:s') : null
        ];
    }
    sqlsrv_free_stmt($stmt);

    if (!empty($headers)) {
        $response['success'] = true;
        $response['hasData'] = true;
        $response['headers'] = $headers;
        $response['email'] = $email;
        $response['town'] = $town;
        $response['message'] = 'Headers retrieved successfully.';
    } else {
        $response['success'] = true;
        $response['hasData'] = false;
        $response['message'] = 'No existing data found for this town.';
    }

    echo json_encode($response);

} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    echo json_encode($response);
}

sqlsrv_close($connSql);
?>