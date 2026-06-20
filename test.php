<?php
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "BASE_PATH (dirname): " . dirname(__DIR__) . "<br>";

$appPath = dirname(__DIR__) . '/app/config.php';
echo "Ожидаемый путь к config.php: " . $appPath . "<br>";

if (file_exists($appPath)) {
    echo "Файл config.php НАЙДЕН!";
} else {
    echo "Файл config.php НЕ НАЙДЕН! Проверьте FTP.";
}