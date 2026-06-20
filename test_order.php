<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/config.php';
require_once BASE_PATH . '/app/database.php';

// Показываем структуру таблицы
$stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'");
$col = $stmt->fetch();
echo "Статусы в БД: " . $col['Type'] . "<br><br>";

// Показываем все заказы
$stmt = $pdo->query("SELECT id, title, status FROM orders");
while ($row = $stmt->fetch()) {
    echo "Заказ #{$row['id']}: {$row['title']} — статус: <b>{$row['status']}</b><br>";
}