<?php
header('Content-Type: application/json');

$tests = [];

// Проверка сессии
session_start();
$_SESSION['webview_test'] = 'ok';
$tests['session'] = $_SESSION['webview_test'] ?? 'error';

// Проверка кук
$tests['cookies'] = $_COOKIE;

// Проверка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tests['post'] = 'работает';
    $tests['post_data'] = $_POST;
} else {
    $tests['post'] = 'отправьте POST-запрос для проверки';
}

// Проверка User-Agent
$tests['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'не определён';

// Проверка HTTPS
$tests['https'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'да' : 'нет';

echo json_encode($tests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);