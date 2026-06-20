<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
session_start();

echo "Я пользователь ID: " . ($_SESSION['user_id'] ?? 'не залогинен') . "<br>";
echo "Роль: " . ($_SESSION['user_role'] ?? 'нет') . "<br><br>";

$orderId = 1;

// Проверяем заказ
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if ($order) {
    echo "Заказ #{$orderId}: {$order['title']}<br>";
    echo "Владелец: {$order['user_id']}<br>";
    
    // Проверяем перевозчика
    $stmt = $pdo->prepare("SELECT * FROM bids WHERE order_id = ? AND status = 'accepted'");
    $stmt->execute([$orderId]);
    $bid = $stmt->fetch();
    
    if ($bid) {
        echo "Перевозчик: {$bid['carrier_id']}<br>";
        echo "Я участник: " . (($order['user_id'] == $_SESSION['user_id'] || $bid['carrier_id'] == $_SESSION['user_id']) ? 'ДА' : 'НЕТ') . "<br>";
    } else {
        echo "Перевозчик не назначен!<br>";
    }
} else {
    echo "Заказ не найден!";
}