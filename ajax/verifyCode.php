<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

$code = filter_input(INPUT_POST, 'code', FILTER_SANITIZE_STRING);
$email = isset($_SESSION['auth_email']) ? $_SESSION['auth_email'] : '';
$registrationid = isset($_SESSION['auth_registrationid']) ? $_SESSION['auth_registrationid'] : 0;

if (!$code || strlen($code) !== 6 || !$email || !$registrationid) {
    echo json_encode(['success' => false, 'message' => 'Check your inbox and enter the six character number that was emailed to you.']);
    exit;
}

// Verify code and expiry
$query = "SELECT id FROM tblSecurityCodes WHERE registrationid = ? AND code = ? AND expiry > GETDATE() AND verifiedDate IS NULL";
$params = [$registrationid, $code];
$stmt = sqlsrv_query($connSql, $query, $params);
if ($stmt === false || !($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
    echo json_encode(['success' => false, 'message' => 'Check your inbox and enter the six character number that was emailed to you.']);
    exit;
}

// Update verifiedDate
$query = "UPDATE tblSecurityCodes SET verifiedDate = GETDATE() WHERE id = ?";
$params = [$row['id']];
sqlsrv_query($connSql, $query, $params);

$_SESSION['authenticated'] = true;
$_SESSION['session_expiry'] = time() + 43200; // 12 hours
echo json_encode(['success' => true, 'message' => 'Authentication successful.']);

sqlsrv_close($connSql);
?>