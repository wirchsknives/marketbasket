<?php
require_once '../includes/db.php';

header('Content-Type: text/html');

$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
$town = filter_input(INPUT_POST, 'town', FILTER_SANITIZE_STRING);
$note = filter_input(INPUT_POST, 'note', FILTER_SANITIZE_STRING);

if (!$email || !$town) {
    echo '<div class="alert alert-danger">Invalid input data.</div>';
    exit;
}

// Check if email exists
$query = "SELECT COUNT(*) as count FROM tblRegistration WHERE Email = ?";
$params = array($email);
$stmt = sqlsrv_query($connSql, $query, $params);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if ($row['count'] > 0) {
    echo '<div class="alert alert-warning">A registration already exists for this email address.</div>';
    exit;
}

// Insert new registration and get the new ID
$query = "INSERT INTO tblRegistration (Email, Town, Note) OUTPUT INSERTED.id VALUES (?, ?, ?)";
$params = array($email, $town, $note);
$stmt = sqlsrv_query($connSql, $query, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    error_log("Insert failed: " . print_r($errors, true));
    echo '<div class="alert alert-danger">Failed to register. Please try again.</div>';
    exit;
}

// Fetch the inserted ID
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$newId = $row['id'];

// Check for existing town registrations
$priorRegistrations = '';
$query = "SELECT Email, Note FROM tblRegistration WHERE Town = ? AND Email != ?";
$params = array($town, $email);
$stmt = sqlsrv_query($connSql, $query, $params);
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $priorRegistrations .= "Previous Registration:\nEmail: {$row['Email']}\nNote: {$row['Note']}\n\n";
}

// Get CC email
$query = "SELECT value FROM tblConfiguration WHERE parameter = 'registrationemail'";
$stmt = sqlsrv_query($connSql, $query);
$ccEmail = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['value'];

// Prepare user email
$subject = "New market basket registration for $email";
$body = "Registration Details:\nEmail: $email\nTown: $town\nNote: $note\n\n";
if ($priorRegistrations) {
    $body .= "Prior registrations for $town:\n$priorRegistrations";
}
$headers = "From: No-ReplyMarketBasket@westfordma.gov\r\n";
$headers .= "CC: $ccEmail\r\n";
mail($email, $subject, $body, $headers);

// Prepare approval email
$approvalSubject = "Approve market basket registration $email";
$approvalBody = "Please approve the following registration:\nEmail: $email\nTown: $town\nNote: $note\n\n";
$approvalBody .= "Click here to approve: https://marketbasket.westfordma.gov/ajax/approveregistration.php?email=" . urlencode($email) . "&id=$newId";
mail($ccEmail, $approvalSubject, $approvalBody, "From: No-ReplyMarketBasket@westfordma.gov");

echo '<div class="alert alert-success">Registration submitted successfully. You will receive a confirmation email upon approval.</div>';

sqlsrv_close($connSql);
?>