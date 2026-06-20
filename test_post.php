<?php
header('Content-Type: application/json');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'post' => $_POST,
    'raw_input' => file_get_contents('php://input'),
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'none'
]);