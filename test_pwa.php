<?php
echo "<h1>Проверка PWA-файлов</h1>";

$files = [
    '/manifest.json' => __DIR__ . '/manifest.json',
    '/sw.js' => __DIR__ . '/sw.js',
];

foreach ($files as $url => $path) {
    echo "<p><strong>$url:</strong> ";
    if (file_exists($path)) {
        echo "✅ Найден (" . filesize($path) . " байт)";
    } else {
        echo "❌ НЕ НАЙДЕН!";
    }
    echo "</p>";
}

echo "<p>Текущая папка: " . __DIR__ . "</p>";
?>