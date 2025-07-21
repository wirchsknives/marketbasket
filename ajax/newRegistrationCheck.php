<?php
require_once '../includes/db.php';

header('Content-Type: text/html');

$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

if (!$email ) {
    echo '<div class="alert alert-danger">Invalid input data.</div>';
    exit;
}

// Check if email exists
$query = "SELECT COUNT(*) as count FROM tblRegistration WHERE Email = ?";
$params = array($email);
$stmt = sqlsrv_query($connSql, $query, $params);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if ($row['count'] > 0) {
    echo 'exists';
    exit;
} else {
    echo 'New Registration';
}

sqlsrv_close($connSql);
?>