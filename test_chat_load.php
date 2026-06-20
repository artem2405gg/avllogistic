<?php
session_start();
header('Content-Type: application/json');

$orderId = $_GET['order_id'] ?? 0;

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

$stmt = $pdo->prepare("SELECT m.*, u.name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.order_id = ? ORDER BY m.id ASC");
$stmt->execute([$orderId]);

echo json_encode($stmt->fetchAll());