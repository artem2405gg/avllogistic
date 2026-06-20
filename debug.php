<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Проверка файлов</h2>";

$files = [
    '/app/config.php',
    '/app/database.php',
    '/templates/header.php',
    '/templates/landing.php',
    '/templates/dashboard.php',
    '/templates/tickets.php',
    '/templates/ticket_create.php',
    '/templates/ticket_view.php',
    '/templates/admin/tickets.php',
    '/templates/admin/ticket_reply.php',
];

foreach ($files as $file) {
    $path = dirname(__DIR__) . $file;
    echo "<p>{$file}: " . (file_exists($path) ? "✅ есть" : "❌ НЕТ") . "</p>";
}

echo "<h2>Проверка БД</h2>";
require_once dirname(__DIR__) . '/app/config.php';
require_once dirname(__DIR__) . '/app/database.php';

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'tickets'");
    echo "<p>Таблица tickets: " . ($stmt->fetch() ? "✅ есть" : "❌ НЕТ") . "</p>";
} catch (Exception $e) {
    echo "<p>❌ Ошибка БД: " . $e->getMessage() . "</p>";
}