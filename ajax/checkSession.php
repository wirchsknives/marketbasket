<?php
session_start();
header('Content-Type: application/json');

$response = [
    'success' => false,
    'authenticated' => false,
    'email' => null,
    'town' => null,
    'message' => 'Not authenticated.'
];

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] && time() <= $_SESSION['session_expiry']) {
    $response['success'] = true;
    $response['authenticated'] = true;
    $response['email'] = $_SESSION['auth_email'] ?? null;
    $response['town'] = $_SESSION['auth_town'] ?? null;
    $response['message'] = 'Authenticated.';
}

echo json_encode($response);
?>