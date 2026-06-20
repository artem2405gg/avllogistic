<?php
session_start();
header('Content-Type: application/json');

$orderId = $_POST['order_id'] ?? 0;
$message = $_POST['message'] ?? '';
$receiverId = $_POST['receiver_id'] ?? 0;

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

// Если receiverId = 0, находим собеседника
if ($receiverId == 0 && !empty($_SESSION['user_id'])) {
    // Ищем перевозчика или заказчика
    $stmt = $pdo->prepare("SELECT user_id FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if ($order) {
        if ($order['user_id'] == $_SESSION['user_id']) {
            // Я заказчик — получатель перевозчик
            $stmt = $pdo->prepare("SELECT carrier_id FROM bids WHERE order_id = ? AND status = 'accepted' LIMIT 1");
            $stmt->execute([$orderId]);
            $bid = $stmt->fetch();
            $receiverId = $bid['carrier_id'] ?? 0;
        } else {
            // Я перевозчик — получатель заказчик
            $receiverId = $order['user_id'];
        }
    }
}

if (empty($message) || $receiverId == 0) {
    echo json_encode(['status' => 'error', 'message' => 'Нет получателя']);
    exit;
}

$stmt = $pdo->prepare("INSERT INTO messages (order_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)");
$stmt->execute([$orderId, $_SESSION['user_id'], $receiverId, $message]);

echo json_encode(['status' => 'ok']);