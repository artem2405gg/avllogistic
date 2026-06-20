<?php
session_start();
header('Content-Type: application/json');

$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$phone = $_POST['phone'] ?? '';
$password = $_POST['password'] ?? '';
$role = $_POST['role'] ?? 'carrier';

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Email уже используется']);
    exit;
}

$code = md5($email . time() . rand(1000, 9999));
$refCode = strtoupper(substr(md5($email . time()), 0, 8));

$stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, verification_code, ref_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $role, $code, $refCode]);
$userId = (int)$pdo->lastInsertId();

if ($role === 'carrier') {
    $stmt = $pdo->prepare("INSERT INTO subscriptions (user_id, plan, status, expires_at) VALUES (?, 'free', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $stmt->execute([$userId]);
}

// Сохраняем сессию
$_SESSION['user_id'] = $userId;
$_SESSION['user_name'] = $name;
$_SESSION['user_email'] = $email;
$_SESSION['user_role'] = $role;

echo json_encode([
    'success' => true,
    'user' => [
        'id' => $userId,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'phone' => $phone,
        'company_name' => null,
        'inn' => null,
        'rating' => null
    ]
]);