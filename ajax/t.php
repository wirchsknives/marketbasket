<?php
session_start();

    echo json_encode(['success' => true, 'message' => 'it worked.']);
    exit;

?>