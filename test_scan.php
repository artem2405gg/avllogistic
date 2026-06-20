<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

$orderId = 1; // Замените на ID вашего заказа

echo "<h2>Проверка сканов для заказа #{$orderId}</h2>";

// Проверяем документы
$stmt = $pdo->prepare("SELECT * FROM documents WHERE order_id = ? AND type = 'scan'");
$stmt->execute([$orderId]);
$scans = $stmt->fetchAll();

echo "<p>Найдено сканов в БД: <b>" . count($scans) . "</b></p>";

if (empty($scans)) {
    echo "<p style='color:red;'>❌ Сканы не найдены в базе данных!</p>";
    echo "<p>Проверьте, что файл загружается и запись добавляется в таблицу documents.</p>";
} else {
    foreach ($scans as $scan) {
        echo "<div style='border:1px solid #ccc; padding:10px; margin:10px 0;'>";
        echo "<p>ID: {$scan['id']}</p>";
        echo "<p>Файл: {$scan['file_path']}</p>";
        echo "<p>Дата: {$scan['created_at']}</p>";
        
        $fullPath = __DIR__ . '/' . $scan['file_path'];
        echo "<p>Полный путь: {$fullPath}</p>";
        echo "<p>Файл существует: " . (file_exists($fullPath) ? '✅ да' : '❌ нет') . "</p>";
        
        if (file_exists($fullPath)) {
            echo "<p>Размер: " . filesize($fullPath) . " байт</p>";
            echo "<p><a href='/{$scan['file_path']}' target='_blank'>Открыть файл</a></p>";
        }
        echo "</div>";
    }
}

// Проверяем структуру таблицы
echo "<h3>Структура таблицы documents:</h3>";
$stmt = $pdo->query("DESCRIBE documents");
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th></tr>";
while ($row = $stmt->fetch()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td></tr>";
}
echo "</table>";

// Проверяем права на папку
$scanDir = __DIR__ . '/uploads/scans/';
echo "<h3>Папка для сканов:</h3>";
echo "<p>Путь: {$scanDir}</p>";
echo "<p>Существует: " . (is_dir($scanDir) ? 'да' : 'нет') . "</p>";
echo "<p>Права: " . (is_dir($scanDir) ? substr(sprintf('%o', fileperms($scanDir)), -4) : 'N/A') . "</p>";

if (is_dir($scanDir)) {
    $files = scandir($scanDir);
    echo "<p>Файлов в папке: " . (count($files) - 2) . "</p>";
    foreach ($files as $f) {
        if ($f != '.' && $f != '..') {
            echo "<p>- {$f}</p>";
        }
    }
}