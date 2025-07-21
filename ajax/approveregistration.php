<?php
require_once '../includes/db.php';

header('Content-Type: text/html');

$email = filter_input(INPUT_GET, 'email', FILTER_SANITIZE_EMAIL);
$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$email || !$id) {
    echo '<div class="alert alert-danger">Invalid parameters.</div>';
    exit;
}

// Verify email and ID match and check approval status
$query = "SELECT Approved, ApprovedDate, Email FROM tblRegistration WHERE id = ? AND Email = ?";
$params = array($id, $email);
$stmt = sqlsrv_query($connSql, $query, $params);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if (!$row) {
    echo '<div class="alert alert-danger">Email and ID do not match.</div>';
    exit;
}

if ($row['Approved']) {
    $approvedDate = $row['ApprovedDate']->format('Y-m-d H:i:s');
    echo "<div class='alert alert-info'>Registration was already approved on $approvedDate.</div>";
    exit;
}

// Update registration
$query = "UPDATE tblRegistration SET Approved = 1, ApprovedDate = GETDATE() WHERE id = ?";
$params = array($id);
sqlsrv_query($connSql, $query, $params);

// Send approval confirmation
$subject = "Market Basket Registration Approved";
$body = "Your registration has been approved.\n";
$body .= "Access the Market Basket system here: https://marketbasket.westfordma.gov/inputMarketBasket.html";
mail($email, $subject, $body, "From: No-ReplyMarketBasket@westfordma.gov");

// Fetch updated row
$query = "SELECT * FROM tblRegistration WHERE id = ?";
$stmt = sqlsrv_query($connSql, $query, array($id));
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

echo '<div class="alert alert-success">Registration approved successfully. Confirmation email sent to registrant.</div>';
echo '<pre>' . print_r($row, true) . '</pre>';

sqlsrv_close($connSql);
?>