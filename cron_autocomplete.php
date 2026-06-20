<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

// Находим все заказы в статусе "unloaded" старше 24 часов
$stmt = $pdo->query("
    SELECT o.id, o.user_id, h.created_at as unloaded_at
    FROM orders o
    JOIN order_status_history h ON o.id = h.order_id AND h.status = 'unloaded'
    WHERE o.status = 'unloaded'
    AND h.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
");

$completed = 0;
while ($order = $stmt->fetch()) {
    $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?")->execute([$order['id']]);
    $pdo->prepare("INSERT INTO order_status_history (order_id, status, changed_by) VALUES (?, 'completed', ?)")->execute([$order['id'], $order['user_id']]);
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'order', 'Сделка завершена', 'Заказ #{$order['id']} автоматически завершён.', '/orders/view/{$order['id']}')")->execute([$order['user_id']]);
    $completed++;
}

echo "Завершено заказов: $completed";