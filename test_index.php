<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Имитируем запуск index.php
$_GET['url'] = 'home';

ob_start();
require __DIR__ . '/index.php';
$output = ob_get_clean();

if (empty($output)) {
    echo "Пустой вывод — возможно ошибка в роутере.";
} else {
    echo "OK";
}