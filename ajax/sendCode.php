<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Verify email in tblRegistration with Approved = 1
$query = "SELECT id, town FROM tblRegistration WHERE Email = ? AND Approved = 1";
$params = [$email];
$stmt = sqlsrv_query($connSql, $query, $params);
if ($stmt === false || !($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
    echo json_encode(['success' => false, 'message' => 'Email not found or not approved.']);
    exit;
}

// Generate 6-character code
$code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

// Insert into tblSecurityCodes
$query = "INSERT INTO tblSecurityCodes (registrationid, code, expiry) VALUES (?, ?, DATEADD(MINUTE, 30, GETDATE()))";
$params = [$row['id'], $code];
$stmt = sqlsrv_query($connSql, $query, $params);
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Failed to store authentication code.']);
    exit;
}

// Store email and town in session
$_SESSION['auth_email'] = $email;
$_SESSION['auth_town'] = $row['town'];
$_SESSION['auth_registrationid'] = $row['id'];

// Send email
$subject = "Market Basket Authentication Code";
$body = "Your authentication code is: $code\nThis code expires in 30 minutes.";
$headers = "From: No-ReplyMarketBasket@westfordma.gov";
if (mail($email, $subject, $body, $headers)) {
    echo json_encode(['success' => true, 'message' => 'Authentication code sent to your email.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to send email.']);
}

sqlsrv_close($connSql);
?>