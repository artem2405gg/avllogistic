<?php
session_start();
header('Content-Type: application/json');

$email = $_POST['email'] ?? 'artfifa947@gmail.com';
$password = $_POST['password'] ?? '123456';

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Неверный логин или пароль']);
}