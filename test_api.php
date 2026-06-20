<?php
header('Content-Type: application/json');

$email = $_POST['email'] ?? 'artfifa947@gmail.com';
$password = $_POST['password'] ?? '123456';

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

// Ищем пользователя
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    $passwordOK = password_verify($password, $user['password']);
    echo json_encode([
        'found' => true,
        'password_ok' => $passwordOK,
        'user_id' => $user['id'],
        'user_name' => $user['name'],
        'hash' => substr($user['password'], 0, 20) . '...'
    ]);
} else {
    echo json_encode([
        'found' => false,
        'message' => 'Пользователь с таким email не найден',
        'email_searched' => $email
    ]);
}