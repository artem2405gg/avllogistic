<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

function __($key) { return $key; }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/config.php';
require_once BASE_PATH . '/app/database.php';

// ========== ФУНКЦИЯ ОТПРАВКИ ПИСЕМ ==========
function sendEmail($to, $subject, $message) {
    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    return mail($to, $subject, $message, $headers, "-f " . MAIL_FROM);
}

// ========== АВТОРИЗАЦИЯ ==========
function checkAuth() {
    if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

function register() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name']); $email = trim($_POST['email']);
        $phone = trim($_POST['phone']); $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = $_POST['role']; $company = trim($_POST['company_name'] ?? ''); $inn = trim($_POST['inn'] ?? '');
        $manualRefCode = trim($_POST['ref_code'] ?? '');
        if (!empty($manualRefCode) && empty($_SESSION['ref_by'])) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE ref_code = ?");
            $stmt->execute([$manualRefCode]); $referrer = $stmt->fetch();
            if ($referrer) { $_SESSION['ref_by'] = $referrer['id']; $_SESSION['ref_code'] = $manualRefCode; }
        }
        
        // Проверка уникальности
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $stmt->execute([$email]);
        if ($stmt->fetch()) { $error = "❌ Пользователь с таким email уже существует"; require_once BASE_PATH . '/templates/register.php'; return; }
        if (!empty($phone)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND phone != ''"); $stmt->execute([$phone]);
            if ($stmt->fetch()) { $error = "❌ Пользователь с таким телефоном уже зарегистрирован"; require_once BASE_PATH . '/templates/register.php'; return; }
        }
        if (!empty($inn)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE inn = ? AND inn != ''"); $stmt->execute([$inn]);
            if ($stmt->fetch()) { $error = "❌ Пользователь с таким ИНН уже зарегистрирован"; require_once BASE_PATH . '/templates/register.php'; return; }
        }
        if (!empty($company)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE company_name = ? AND company_name != ''"); $stmt->execute([$company]);
            if ($stmt->fetch()) { $error = "❌ Компания с таким названием уже зарегистрирована"; require_once BASE_PATH . '/templates/register.php'; return; }
        }
        
        $code = md5($email . time() . rand(1000, 9999));
        $refCode = strtoupper(substr(md5($email . time()), 0, 8));
        $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, company_name, inn, verification_code, ref_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $phone, $password, $role, $company, $inn, $code, $refCode]);
        $userId = $pdo->lastInsertId();
        
        if ($role === 'carrier') {
            $stmt = $pdo->prepare("INSERT INTO subscriptions (user_id, plan, status, expires_at) VALUES (?, 'free', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY))");
            $stmt->execute([$userId]);
        }
        if (!empty($_SESSION['ref_by'])) {
            $stmt = $pdo->prepare("UPDATE users SET ref_by = ? WHERE id = ?"); $stmt->execute([$_SESSION['ref_by'], $userId]);
            $stmt = $pdo->prepare("UPDATE users SET bonus_days = bonus_days + 14 WHERE id = ?"); $stmt->execute([$_SESSION['ref_by']]);
            $stmt = $pdo->prepare("UPDATE subscriptions SET expires_at = DATE_ADD(expires_at, INTERVAL 14 DAY) WHERE user_id = ? AND status = 'active' ORDER BY expires_at DESC LIMIT 1"); $stmt->execute([$_SESSION['ref_by']]);
            $stmt = $pdo->prepare("UPDATE subscriptions SET expires_at = DATE_ADD(expires_at, INTERVAL 5 DAY) WHERE user_id = ? AND status = 'active' ORDER BY expires_at DESC LIMIT 1"); $stmt->execute([$userId]);
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'ref', 'Новый реферал!', ?, '/ref')"); $stmt->execute([$_SESSION['ref_by'], "Пользователь {$name} зарегистрировался по вашей ссылке. +14 дней!"]);
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'ref', 'Бонус за реферала!', ?, '/ref')"); $stmt->execute([$userId, "Вы зарегистрировались по реферальной ссылке. +5 дней!"]);
            unset($_SESSION['ref_by'], $_SESSION['ref_code']);
        }
        sendEmail($email, "Подтверждение регистрации AVL Logistic", "Здравствуйте, {$name}!\n\nДля подтверждения email перейдите по ссылке:\n" . SITE_URL . "/verify?code={$code}\n\nС уважением,\nAVL Logistic");
        $_SESSION['user_id'] = $userId; $_SESSION['user_name'] = $name; $_SESSION['user_email'] = $email; $_SESSION['user_role'] = $role; $_SESSION['email_verified'] = false;
        header('Location: /dashboard?verify_email=1'); exit;
    }
    require_once BASE_PATH . '/templates/register.php';
}

function login() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email']); $password = $_POST['password'];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?"); $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id']; $_SESSION['user_name'] = $user['name']; $_SESSION['user_email'] = $user['email']; $_SESSION['user_role'] = $user['role']; $_SESSION['email_verified'] = (bool)$user['email_verified'];
            header('Location: /dashboard'); exit;
        } else { $error = "Неверный email или пароль"; }
    }
    require_once BASE_PATH . '/templates/login.php';
}

function logout() { session_destroy(); header('Location: /'); exit; }

function verifyEmail() {
    global $pdo;
    $code = $_GET['code'] ?? '';
    if (empty($code)) { header('Location: /'); exit; }
    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE verification_code = ? AND email_verified = 0");
    $stmt->execute([$code]); $user = $stmt->fetch();
    if ($user) { $stmt = $pdo->prepare("UPDATE users SET email_verified = 1, verification_code = NULL WHERE id = ?"); $stmt->execute([$user['id']]); $_SESSION['email_verified'] = true; $_SESSION['user_id'] = $user['id']; $_SESSION['user_name'] = $user['name']; $_SESSION['user_email'] = $user['email']; $_SESSION['user_role'] = $user['role']; require_once BASE_PATH . '/templates/verify_success.php'; }
    else { echo "<div style='text-align:center;padding:40px;'><h2>Ссылка недействительна</h2><a href='/'>На главную</a></div>"; }
}

function forgotPassword() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $email = trim($_POST['email']); $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?"); $stmt->execute([$email]); $user = $stmt->fetch();
        if ($user) { $code = md5($email . time()); $stmt = $pdo->prepare("UPDATE users SET reset_code = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?"); $stmt->execute([$code, $user['id']]); sendEmail($email, "Восстановление пароля", "Ссылка: " . SITE_URL . "/reset?code={$code}"); }
        $success = "Если email существует, письмо отправлено."; }
    require_once BASE_PATH . '/templates/forgot_password.php';
}

function resetPassword() {
    global $pdo; $code = $_GET['code'] ?? ''; if (empty($code)) { header('Location: /login'); exit; }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_code = ? AND reset_expires > NOW()"); $stmt->execute([$code]); $user = $stmt->fetch();
    if (!$user) { $error = "Ссылка недействительна."; require_once BASE_PATH . '/templates/reset_password.php'; return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { $password = $_POST['password']; if (strlen($password) < 6) { $error = "Минимум 6 символов"; require_once BASE_PATH . '/templates/reset_password.php'; return; } $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_code = NULL WHERE id = ?"); $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]); $success = "Пароль изменён! <a href='/login'>Войти</a>"; require_once BASE_PATH . '/templates/reset_password.php'; return; }
    require_once BASE_PATH . '/templates/reset_password.php';
}

// ========== РЕФЕРАЛЬНАЯ ПРОГРАММА ==========
function refPage() { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT ref_code, bonus_days FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]); $user = $stmt->fetch(); if (empty($user['ref_code'])) { $code = strtoupper(substr(md5($_SESSION['user_id'] . time()), 0, 8)); $stmt = $pdo->prepare("UPDATE users SET ref_code = ? WHERE id = ?"); $stmt->execute([$code, $_SESSION['user_id']]); $user['ref_code'] = $code; } $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE ref_by = ?"); $stmt->execute([$_SESSION['user_id']]); $refCount = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT name, email FROM users WHERE ref_by = ? ORDER BY id DESC"); $stmt->execute([$_SESSION['user_id']]); $refUsers = $stmt->fetchAll(); $refLink = SITE_URL . "/r/{$user['ref_code']}"; $bonusDays = $user['bonus_days']; require_once BASE_PATH . '/templates/ref_page.php'; }
function refRegister($code) { global $pdo; $stmt = $pdo->prepare("SELECT id FROM users WHERE ref_code = ?"); $stmt->execute([$code]); $referrer = $stmt->fetch(); if ($referrer) { $_SESSION['ref_by'] = $referrer['id']; $_SESSION['ref_code'] = $code; } header('Location: /register'); exit; }

// ========== ЗАКАЗЫ ==========
function createOrder() { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT email_verified, inn FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]); $user = $stmt->fetch(); if (empty($user['inn'])) { $_SESSION['error'] = "Для создания заказа необходимо указать ИНН."; header('Location: /profile/edit?required=inn'); exit; } if (!$user['email_verified']) { $error = "⚠️ Подтвердите email."; require_once BASE_PATH . '/templates/order_create.php'; return; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { $stmt = $pdo->prepare("INSERT INTO orders (user_id, title, cargo_type, weight, volume, pickup_address, delivery_address, pickup_date, delivery_date, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"); $stmt->execute([$_SESSION['user_id'], $_POST['title'], $_POST['cargo_type'], $_POST['weight'], $_POST['volume'], $_POST['pickup_address'], $_POST['delivery_address'], $_POST['pickup_date'], $_POST['delivery_date'], $_POST['price']]); header('Location: /orders/my'); exit; } require_once BASE_PATH . '/templates/order_create.php'; }
function listOrders() { checkAuth(); global $pdo; $where = "WHERE o.status = 'new'"; $params = []; if (!empty($_GET['city_from'])) { $where .= " AND o.pickup_address LIKE ?"; $params[] = '%' . $_GET['city_from'] . '%'; } if (!empty($_GET['city_to'])) { $where .= " AND o.delivery_address LIKE ?"; $params[] = '%' . $_GET['city_to'] . '%'; } $stmt = $pdo->prepare("SELECT o.*, u.company_name, u.rating FROM orders o JOIN users u ON o.user_id = u.id $where ORDER BY o.id DESC LIMIT 20"); $stmt->execute($params); $orders = $stmt->fetchAll(); require_once BASE_PATH . '/templates/orders_list.php'; }
function viewOrder($id) { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT o.*, u.name, u.company_name, u.rating, u.phone FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?"); $stmt->execute([$id]); $order = $stmt->fetch(); if (!$order) { header('Location: /orders'); exit; } if ($order['status'] == 'unloaded') { $stmt = $pdo->prepare("SELECT created_at FROM order_status_history WHERE order_id = ? AND status = 'unloaded' ORDER BY id DESC LIMIT 1"); $stmt->execute([$id]); $unloadedTime = $stmt->fetch(); if ($unloadedTime && (time() - strtotime($unloadedTime['created_at'])) / 3600 >= 24) { $stmt = $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?"); $stmt->execute([$id]); $order['status'] = 'completed'; } } $stmt = $pdo->prepare("SELECT b.*, u.company_name, u.rating FROM bids b JOIN users u ON b.carrier_id = u.id WHERE b.order_id = ? ORDER BY b.id ASC"); $stmt->execute([$id]); $bids = $stmt->fetchAll(); $myBid = null; if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'carrier') { $stmt = $pdo->prepare("SELECT * FROM bids WHERE order_id = ? AND carrier_id = ?"); $stmt->execute([$id, $_SESSION['user_id']]); $myBid = $stmt->fetch(); } require_once BASE_PATH . '/templates/order_view.php'; }
function myOrders() { checkAuth(); global $pdo; if ($_SESSION['user_role'] === 'owner') { $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC"); } else { $stmt = $pdo->prepare("SELECT o.*, b.status as bid_status FROM orders o JOIN bids b ON o.id = b.order_id WHERE b.carrier_id = ? ORDER BY o.id DESC"); } $stmt->execute([$_SESSION['user_id']]); $orders = $stmt->fetchAll(); require_once BASE_PATH . '/templates/my_orders.php'; }

// ========== ОТКЛИКИ ==========
function placeBid($orderId) {
    checkAuth(); global $pdo;
    if ($_SESSION['user_role'] !== 'carrier') { header('Location: /orders'); exit; }
    $stmt = $pdo->prepare("SELECT email_verified FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]); $user = $stmt->fetch();
    if (!$user['email_verified']) { header('Location: /dashboard?verify_email=1'); exit; }
    $stmt = $pdo->prepare("SELECT plan FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > NOW()"); $stmt->execute([$_SESSION['user_id']]); $activeSub = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM bids WHERE carrier_id = ?"); $stmt->execute([$_SESSION['user_id']]); $bidsCount = $stmt->fetch()['cnt'];
    $limit = 3; if ($activeSub) { if ($activeSub['plan'] == 'basic') $limit = 50; elseif ($activeSub['plan'] == 'pro') $limit = 999999; }
    if ($bidsCount >= $limit) { if (!$activeSub || $activeSub['plan'] == 'free') { $_SESSION['error'] = "Бесплатные отклики закончились ({$bidsCount}/3)."; } else { $_SESSION['error'] = "Лимит исчерпан ({$bidsCount}/{$limit})."; } header('Location: /pricing?limit=1'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $price = $_POST['price'];
        $stmt = $pdo->prepare("INSERT INTO bids (order_id, carrier_id, price) VALUES (?, ?, ?)"); $stmt->execute([$orderId, $_SESSION['user_id'], $price]);
        $stmt = $pdo->prepare("SELECT o.*, u.name as owner_name, u.email as owner_email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?"); $stmt->execute([$orderId]); $orderInfo = $stmt->fetch();
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'bid', 'Новый отклик', ?, ?)"); $stmt->execute([$orderInfo['user_id'], "На заказ «{$orderInfo['title']}» отклик на {$price} ₽ от {$_SESSION['user_name']}.", "/orders/view/{$orderId}"]);
        sendEmail($orderInfo['owner_email'], "Новый отклик на заказ «{$orderInfo['title']}»", "Здравствуйте, {$orderInfo['owner_name']}!\n\nНа ваш заказ «{$orderInfo['title']}» новый отклик.\n\nПеревозчик: {$_SESSION['user_name']}\nЦена: {$price} ₽\n\n" . SITE_URL . "/orders/view/{$orderId}\n\nС уважением,\nAVL Logistic");
        header("Location: /orders/view/$orderId"); exit;
    }
}
function acceptBid() { checkAuth(); global $pdo; if ($_SERVER['REQUEST_METHOD'] === 'POST') { $bidId = $_POST['bid_id']; $stmt = $pdo->prepare("SELECT * FROM bids WHERE id = ?"); $stmt->execute([$bidId]); $bid = $stmt->fetch(); if (!$bid) die("Отклик не найден"); $stmt = $pdo->prepare("SELECT user_id FROM orders WHERE id = ?"); $stmt->execute([$bid['order_id']]); $order = $stmt->fetch(); if ($order['user_id'] != $_SESSION['user_id']) die("Нет доступа"); $pdo->beginTransaction(); $stmt = $pdo->prepare("UPDATE bids SET status = 'accepted' WHERE id = ?"); $stmt->execute([$bidId]); $stmt = $pdo->prepare("UPDATE bids SET status = 'rejected' WHERE order_id = ? AND id != ?"); $stmt->execute([$bid['order_id'], $bidId]); $stmt = $pdo->prepare("UPDATE orders SET status = 'accepted' WHERE id = ?"); $stmt->execute([$bid['order_id']]); $pdo->commit(); $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status, changed_by) VALUES (?, 'accepted', ?)"); $stmt->execute([$bid['order_id'], $_SESSION['user_id']]); $stmt = $pdo->prepare("SELECT o.*, u.name as carrier_name, u.email as carrier_email FROM orders o JOIN users u ON u.id = ? WHERE o.id = ?"); $stmt->execute([$bid['carrier_id'], $bid['order_id']]); $info = $stmt->fetch(); $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'bid_accepted', 'Вас выбрали!', ?, ?)"); $stmt->execute([$bid['carrier_id'], "Вы выбраны исполнителем заказа «{$info['title']}».", "/orders/view/{$bid['order_id']}"]); sendEmail($info['carrier_email'], "Вас выбрали исполнителем!", "Здравствуйте, {$info['carrier_name']}!\n\nВы выбраны исполнителем заказа «{$info['title']}».\n\nМаршрут: {$info['pickup_address']} → {$info['delivery_address']}\nЦена: {$bid['price']} ₽\n\n" . SITE_URL . "/orders/view/{$bid['order_id']}\n\nС уважением,\nAVL Logistic"); header("Location: /orders/view/" . $bid['order_id']); exit; } }

// ========== СТАТУСЫ ==========
function updateOrderStatus($orderId) { checkAuth(); global $pdo; if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /orders/view/' . $orderId); exit; } $newStatus = $_POST['status'] ?? ''; $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?"); $stmt->execute([$orderId]); $order = $stmt->fetch(); if (!$order) { header('Location: /orders/my'); exit; } $isOwner = ($order['user_id'] == $_SESSION['user_id']); $stmt = $pdo->prepare("SELECT carrier_id FROM bids WHERE order_id = ? AND status = 'accepted'"); $stmt->execute([$orderId]); $bid = $stmt->fetch(); $isCarrier = ($bid && $bid['carrier_id'] == $_SESSION['user_id']); if (!$isOwner && !$isCarrier) die("Нет доступа"); $allowed = ['new' => ['accepted', 'cancelled'], 'accepted' => ['pickup', 'cancelled'], 'pickup' => ['loaded', 'cancelled'], 'loaded' => ['in_transit'], 'in_transit' => ['arrived'], 'arrived' => ['unloaded'], 'unloaded' => ['completed']]; if (!isset($allowed[$order['status']]) || !in_array($newStatus, $allowed[$order['status']])) die("Нельзя"); if ($isOwner && !in_array($newStatus, ['accepted', 'completed', 'cancelled'])) die("Нельзя"); if ($isCarrier && !in_array($newStatus, ['pickup', 'loaded', 'in_transit', 'arrived', 'unloaded', 'cancelled'])) die("Нельзя"); $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?"); $stmt->execute([$newStatus, $orderId]); $stmt = $pdo->prepare("INSERT INTO order_status_history (order_id, status, changed_by) VALUES (?, ?, ?)"); $stmt->execute([$orderId, $newStatus, $_SESSION['user_id']]); header('Location: /orders/view/' . $orderId . '?status_updated=1'); exit; }

// ========== ЗАГРУЗКА СКАНА ==========
function uploadScan($orderId) { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?"); $stmt->execute([$orderId]); $order = $stmt->fetch(); if (!$order) die("Не найден"); $stmt = $pdo->prepare("SELECT carrier_id FROM bids WHERE order_id = ? AND status = 'accepted'"); $stmt->execute([$orderId]); $bid = $stmt->fetch(); if (!$bid || $bid['carrier_id'] != $_SESSION['user_id']) die("Нет доступа"); if (!isset($_FILES['scan']) || $_FILES['scan']['error'] !== UPLOAD_ERR_OK) { header('Location: /orders/view/' . $orderId . '?upload_error=1'); exit; } $file = $_FILES['scan']; if (!in_array($file['type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'])) die("PDF, JPG, PNG"); if ($file['size'] > 10 * 1024 * 1024) die("Макс 10 МБ"); $uploadDir = BASE_PATH . '/public_html/uploads/scans/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true); $ext = pathinfo($file['name'], PATHINFO_EXTENSION); $fileName = 'scan_order_' . $orderId . '_' . time() . '.' . $ext; if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) { $stmt = $pdo->prepare("INSERT INTO documents (order_id, type, file_path) VALUES (?, 'scan', ?)"); $stmt->execute([$orderId, 'uploads/scans/' . $fileName]); header('Location: /orders/view/' . $orderId . '?scan_uploaded=1'); exit; } else { die("Не удалось сохранить"); } }

// ========== ДОКУМЕНТЫ ==========
function viewDocument($orderId, $type) { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT o.*, u1.name as o_name, u1.company_name as o_comp, u1.inn as o_inn, u2.name as c_name, u2.company_name as c_comp, u2.inn as c_inn, u2.nds_rate, b.price FROM orders o JOIN users u1 ON o.user_id = u1.id JOIN bids b ON o.id = b.order_id AND b.status = 'accepted' JOIN users u2 ON b.carrier_id = u2.id WHERE o.id = ?"); $stmt->execute([$orderId]); $data = $stmt->fetch(); if (!$data) die("Документ не найден"); if ($type === 'contract') showContractHtml($data); elseif ($type === 'waybill') showWaybillHtml($data); elseif ($type === 'act') showActHtml($data); }
function showContractHtml($data) { ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Договор-заявка №<?= $data['id'] ?></title><style>@media print{.no-print{display:none}body{margin:0;padding:20px}}body{font-family:Arial;max-width:800px;margin:0 auto;padding:20px}.header{text-align:center;font-size:18px;font-weight:bold;margin-bottom:20px}table{width:100%;border-collapse:collapse;margin:15px 0}td{padding:8px;border:1px solid #000}.label{background:#f5f5f5;width:30%;font-weight:bold}.no-print{background:#007bff;color:white;border:none;padding:12px 30px;font-size:16px;border-radius:5px;cursor:pointer;margin-bottom:20px}.signatures{margin-top:50px}.signatures td{border:none;padding:30px 10px;vertical-align:bottom}</style></head><body><button class="no-print" onclick="window.print()">🖨️ Сохранить как PDF</button><div class="header">ДОГОВОР-ЗАЯВКА № <?= $data['id'] ?><br>на перевозку груза автомобильным транспортом</div><p><strong>1. Предмет договора:</strong> Перевозчик обязуется доставить вверенный ему Заказчиком груз в пункт назначения и выдать его уполномоченному лицу, а Заказчик обязуется уплатить за перевозку установленную плату.</p><table><tr><td class="label">Заказчик:</td><td><?= htmlspecialchars($data['o_comp'] ?: $data['o_name']) ?>, ИНН <?= htmlspecialchars($data['o_inn']) ?></td></tr><tr><td class="label">Перевозчик:</td><td><?= htmlspecialchars($data['c_comp'] ?: $data['c_name']) ?>, ИНН <?= htmlspecialchars($data['c_inn']) ?></td></tr><tr><td class="label">Маршрут:</td><td><?= htmlspecialchars($data['pickup_address']) ?> → <?= htmlspecialchars($data['delivery_address']) ?></td></tr><tr><td class="label">Груз:</td><td><?= htmlspecialchars($data['cargo_type']) ?>, <?= $data['weight'] ?> т, <?= $data['volume'] ?> м³</td></tr><tr><td class="label">Дата погрузки:</td><td><?= $data['pickup_date'] ?></td></tr><tr><td class="label">Дата доставки:</td><td><?= $data['delivery_date'] ?></td></tr><tr><td class="label">Стоимость:</td><td><strong><?= number_format($data['price'], 2, ',', ' ') ?> ₽</strong></td></tr></table><p><strong>2. Порядок расчётов:</strong> Оплата производится в течение 3 рабочих дней после выгрузки и подписания акта выполненных работ.</p><p><strong>3. Ответственность:</strong> Перевозчик несёт полную материальную ответственность за сохранность груза с момента погрузки до момента выгрузки.</p><p><strong>4. Форс-мажор:</strong> Стороны освобождаются от ответственности при обстоятельствах непреодолимой силы.</p><table class="signatures"><tr><td>Заказчик: _______________<br><?= htmlspecialchars($data['o_name']) ?><br>М.П.</td><td>Перевозчик: _______________<br><?= htmlspecialchars($data['c_name']) ?><br>М.П.</td></tr></table></body></html><?php }
function showWaybillHtml($data) { ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Транспортная накладная №<?= $data['id'] ?></title><style>@media print{.no-print{display:none}body{margin:0;padding:20px}}body{font-family:Arial;max-width:800px;margin:0 auto;padding:20px}h3{text-align:center}table{width:100%;border-collapse:collapse;margin:10px 0}td{padding:6px;border:1px solid #000}td:first-child{font-weight:bold;width:40%}.no-print{background:#007bff;color:white;border:none;padding:12px 30px;font-size:16px;border-radius:5px;cursor:pointer;margin-bottom:20px}</style></head><body><button class="no-print" onclick="window.print()">🖨️ Сохранить как PDF</button><h3>ТРАНСПОРТНАЯ НАКЛАДНАЯ № <?= $data['id'] ?></h3><table><tr><td>Грузоотправитель</td><td><?= htmlspecialchars($data['o_comp'] ?: $data['o_name']) ?></td></tr><tr><td>Грузополучатель</td><td><?= htmlspecialchars($data['delivery_address']) ?></td></tr><tr><td>Перевозчик</td><td><?= htmlspecialchars($data['c_comp'] ?: $data['c_name']) ?></td></tr><tr><td>Госномер ТС</td><td>____________________</td></tr><tr><td>ФИО водителя</td><td>____________________</td></tr><tr><td>Груз</td><td><?= htmlspecialchars($data['cargo_type']) ?>, <?= $data['weight'] ?> т, <?= $data['volume'] ?> м³</td></tr><tr><td>Адрес погрузки</td><td><?= htmlspecialchars($data['pickup_address']) ?></td></tr><tr><td>Дата погрузки</td><td><?= $data['pickup_date'] ?></td></tr><tr><td>Адрес выгрузки</td><td><?= htmlspecialchars($data['delivery_address']) ?></td></tr><tr><td>Дата выгрузки</td><td><?= $data['delivery_date'] ?></td></tr><tr><td>Стоимость</td><td><?= number_format($data['price'], 2, ',', ' ') ?> ₽</td></tr></table><p>Приём груза: _____________ Дата: _____________ Подпись: _____________</p><p>Сдача груза: _____________ Дата: _____________ Подпись: _____________</p></body></html><?php }
function showActHtml($data) { $price = $data['price']; $ndsRate = (int)($data['nds_rate'] ?? 20); if ($ndsRate > 0) { $nds = round($price * $ndsRate / (100 + $ndsRate), 2); $priceWithoutNds = $price - $nds; } else { $nds = 0; $priceWithoutNds = $price; } ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Акт №<?= $data['id'] ?></title><style>@media print{.no-print{display:none}body{margin:0;padding:20px}}body{font-family:Arial;max-width:800px;margin:0 auto;padding:20px}h3{text-align:center}table{width:100%;border-collapse:collapse;margin:10px 0}td{padding:6px;border:1px solid #000}td:first-child{font-weight:bold;width:40%}.no-print{background:#007bff;color:white;border:none;padding:12px 30px;font-size:16px;border-radius:5px;cursor:pointer;margin-bottom:20px}</style></head><body><button class="no-print" onclick="window.print()">🖨️ Сохранить как PDF</button><h3>АКТ ВЫПОЛНЕННЫХ РАБОТ № <?= $data['id'] ?></h3><p>от <?= date('d.m.Y') ?></p><p>Услуги выполнены полностью. Стороны претензий не имеют.</p><table><tr><td>Исполнитель:</td><td><?= htmlspecialchars($data['c_comp'] ?: $data['c_name']) ?>, ИНН <?= htmlspecialchars($data['c_inn']) ?></td></tr><tr><td>Заказчик:</td><td><?= htmlspecialchars($data['o_comp'] ?: $data['o_name']) ?>, ИНН <?= htmlspecialchars($data['o_inn']) ?></td></tr><tr><td>Услуга:</td><td>Перевозка «<?= htmlspecialchars($data['cargo_type']) ?>» <?= htmlspecialchars($data['pickup_address']) ?> → <?= htmlspecialchars($data['delivery_address']) ?></td></tr><tr><td>Вес:</td><td><?= $data['weight'] ?> т</td></tr><tr><td>Даты:</td><td><?= $data['pickup_date'] ?> — <?= $data['delivery_date'] ?></td></tr><?php if ($ndsRate > 0): ?><tr><td>Без НДС:</td><td><?= number_format($priceWithoutNds, 2, ',', ' ') ?> ₽</td></tr><tr><td>НДС <?= $ndsRate ?>%:</td><td><?= number_format($nds, 2, ',', ' ') ?> ₽</td></tr><?php endif; ?><tr><td><strong>Итого:</strong></td><td><strong><?= number_format($price, 2, ',', ' ') ?> ₽</strong><?= $ndsRate > 0 ? ' (в т.ч. НДС)' : ' (НДС не облагается)' ?></td></tr></table><table style="margin-top:40px;"><tr><td>Исполнитель: _______________<br><?= htmlspecialchars($data['c_name']) ?><br>М.П.</td><td>Заказчик: _______________<br><?= htmlspecialchars($data['o_name']) ?><br>М.П.</td></tr></table></body></html><?php }

// ========== ЧАТ ==========
function chatView($orderId) { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT o.*, u.name as o_name, u.id as o_id FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?"); $stmt->execute([$orderId]); $order = $stmt->fetch(); if (!$order) { header('Location: /orders/my'); exit; } if ($_SESSION['user_id'] == $order['o_id']) { $stmt = $pdo->prepare("SELECT u.id, u.name, u.company_name FROM users u JOIN bids b ON u.id = b.carrier_id WHERE b.order_id = ? AND b.status = 'accepted'"); $stmt->execute([$orderId]); $companion = $stmt->fetch(); } else { $companion = ['id' => $order['o_id'], 'name' => $order['o_name'], 'company_name' => '']; } if (!$companion) die("Собеседник не найден"); $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE order_id = ? AND receiver_id = ? AND is_read = 0"); $stmt->execute([$orderId, $_SESSION['user_id']]); require_once BASE_PATH . '/templates/chat.php'; }
function sendMessage($orderId) { checkAuth(); global $pdo; if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit; $msg = trim($_POST['message'] ?? ''); $receiver = (int)$_POST['receiver_id']; $filePath = null; $fileType = null; if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) { $file = $_FILES['file']; $allowedTypes = ['image/jpeg' => 'image', 'image/png' => 'image', 'image/jpg' => 'image', 'image/gif' => 'image', 'image/webp' => 'image', 'application/pdf' => 'document']; if (isset($allowedTypes[$file['type']]) && $file['size'] <= 20 * 1024 * 1024) { $uploadDir = BASE_PATH . '/public_html/uploads/chat/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true); $ext = pathinfo($file['name'], PATHINFO_EXTENSION); $fileName = 'chat_' . $orderId . '_' . time() . '_' . rand(100,999) . '.' . $ext; if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) { $filePath = 'uploads/chat/' . $fileName; $fileType = $allowedTypes[$file['type']]; } } } if (empty($msg) && empty($filePath)) exit; $stmt = $pdo->prepare("INSERT INTO messages (order_id, sender_id, receiver_id, message, file_path, file_type) VALUES (?, ?, ?, ?, ?, ?)"); $stmt->execute([$orderId, $_SESSION['user_id'], $receiver, $msg, $filePath, $fileType]); $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM messages WHERE order_id = ? AND receiver_id = ? AND is_read = 0"); $stmt->execute([$orderId, $receiver]); $unread = $stmt->fetch()['cnt']; if ($unread == 1) { $stmt = $pdo->prepare("SELECT o.title, u.name, u.email FROM orders o JOIN users u ON u.id = ? WHERE o.id = ?"); $stmt->execute([$receiver, $orderId]); $info = $stmt->fetch(); if ($info && !empty($info['email'])) { sendEmail($info['email'], "Новое сообщение на AVL Logistic", "Здравствуйте, {$info['name']}!\n\nУ вас новое сообщение в чате по заказу «{$info['title']}».\n\n" . SITE_URL . "/chat/view/{$orderId}\n\nС уважением,\nAVL Logistic"); } } echo json_encode(['status' => 'ok']); exit; }
function loadMessages($orderId) { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT m.*, u.name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.order_id = ? ORDER BY m.id ASC"); $stmt->execute([$orderId]); echo json_encode($stmt->fetchAll()); exit; }
function messagesList() { checkAuth(); global $pdo; $userId = $_SESSION['user_id']; $stmt = $pdo->prepare("SELECT o.id, o.title, u.name as companion_name, (SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.receiver_id = ? AND m.is_read = 0) as unread FROM orders o JOIN bids b ON o.id = b.order_id AND b.status = 'accepted' JOIN users u ON b.carrier_id = u.id WHERE o.user_id = ? ORDER BY o.id DESC"); $stmt->execute([$userId, $userId]); $ownerChats = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT o.id, o.title, u.name as companion_name, (SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.receiver_id = ? AND m.is_read = 0) as unread FROM orders o JOIN bids b ON o.id = b.order_id AND b.status = 'accepted' JOIN users u ON o.user_id = u.id WHERE b.carrier_id = ? ORDER BY o.id DESC"); $stmt->execute([$userId, $userId]); $carrierChats = $stmt->fetchAll(); $chats = array_merge($ownerChats, $carrierChats); $unique = []; foreach ($chats as $chat) { $unique[$chat['id']] = $chat; } $chats = array_values($unique); usort($chats, function($a, $b) { return $b['id'] - $a['id']; }); require_once BASE_PATH . '/templates/messages_list.php'; }

// ========== РЕЙТИНГИ ==========
function createReview($orderId) { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'completed'"); $stmt->execute([$orderId]); $order = $stmt->fetch(); if (!$order) die("Заказ не найден"); $isOwner = ($order['user_id'] == $_SESSION['user_id']); $reviewedId = null; if ($isOwner) { $stmt = $pdo->prepare("SELECT carrier_id FROM bids WHERE order_id = ? AND status = 'accepted'"); $stmt->execute([$orderId]); $bid = $stmt->fetch(); $reviewedId = $bid['carrier_id'] ?? null; } else { $reviewedId = $order['user_id']; } if (!$reviewedId) die("Не найден"); $stmt = $pdo->prepare("SELECT id FROM reviews WHERE order_id = ? AND reviewer_id = ?"); $stmt->execute([$orderId, $_SESSION['user_id']]); if ($stmt->fetch()) { header('Location: /orders/view/' . $orderId . '?already_reviewed=1'); exit; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { $rating = (int)$_POST['rating']; $comment = trim($_POST['comment'] ?? ''); if ($rating < 1 || $rating > 5) { $error = "Оценка от 1 до 5"; require_once BASE_PATH . '/templates/review_create.php'; return; } $stmt = $pdo->prepare("INSERT INTO reviews (order_id, reviewer_id, reviewed_id, rating, comment) VALUES (?, ?, ?, ?, ?)"); $stmt->execute([$orderId, $_SESSION['user_id'], $reviewedId, $rating, $comment]); $stmt = $pdo->prepare("SELECT AVG(rating) as avg FROM reviews WHERE reviewed_id = ?"); $stmt->execute([$reviewedId]); $stmt = $pdo->prepare("UPDATE users SET rating = ? WHERE id = ?"); $stmt->execute([round($stmt->fetch()['avg'], 1), $reviewedId]); header('Location: /orders/view/' . $orderId . '?reviewed=1'); exit; } require_once BASE_PATH . '/templates/review_create.php'; }

// ========== ПОДПИСКА ==========
function activateSubscription() { checkAuth(); global $pdo; if ($_SESSION['user_role'] !== 'carrier') { header('Location: /pricing'); exit; } $plan = $_POST['plan'] ?? ''; if (!in_array($plan, ['free', 'basic', 'pro'])) { header('Location: /pricing'); exit; } if ($plan === 'free') { $pdo->prepare("UPDATE subscriptions SET status = 'expired' WHERE user_id = ? AND status = 'active'")->execute([$_SESSION['user_id']]); $pdo->prepare("INSERT INTO subscriptions (user_id, plan, status, expires_at) VALUES (?, 'free', 'active', DATE_ADD(NOW(), INTERVAL 1 MONTH))")->execute([$_SESSION['user_id']]); $_SESSION['success'] = "Бесплатный тариф!"; header('Location: /pricing?activated=1'); exit; } $stmt = $pdo->prepare("SELECT id FROM invoices WHERE user_id = ? AND status = 'pending'"); $stmt->execute([$_SESSION['user_id']]); if ($stmt->fetch()) { $_SESSION['error'] = "Уже есть счёт."; header('Location: /pricing?error=1'); exit; } $prices = ['basic' => 1990, 'pro' => 4990]; $amount = $prices[$plan] ?? 0; $details = PAYMENT_DETAILS . "\n\nТариф «{$plan}» AVL Logistic"; $stmt = $pdo->prepare("INSERT INTO invoices (user_id, plan, amount, status, payment_details) VALUES (?, ?, ?, 'pending', ?)"); $stmt->execute([$_SESSION['user_id'], $plan, $amount, $details]); $_SESSION['success'] = "Счёт на {$amount} ₽!"; header('Location: /pricing?invoiced=1'); exit; }
function userInvoices() { checkAuth(); global $pdo; $invoices = $pdo->prepare("SELECT * FROM invoices WHERE user_id = ? ORDER BY id DESC"); $invoices->execute([$_SESSION['user_id']]); $pdo->prepare("UPDATE invoices SET viewed = 1 WHERE user_id = ? AND viewed = 0")->execute([$_SESSION['user_id']]); require_once BASE_PATH . '/templates/invoices.php'; }
function notificationsPage() { checkAuth(); global $pdo; $notifications = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 30"); $notifications->execute([$_SESSION['user_id']]); $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$_SESSION['user_id']]); require_once BASE_PATH . '/templates/notifications.php'; }

// ========== ПРОФИЛЬ ==========
function viewProfile() { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]); $profile = $stmt->fetch(); if ($_SESSION['user_role'] === 'owner') { $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM orders WHERE user_id = ?"); $stmt->execute([$_SESSION['user_id']]); $totalOrders = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM orders WHERE user_id = ? AND status = 'completed'"); $stmt->execute([$_SESSION['user_id']]); $completedOrders = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COALESCE(SUM(price), 0) as total FROM orders WHERE user_id = ? AND status = 'completed'"); $stmt->execute([$_SESSION['user_id']]); $totalSpent = $stmt->fetch()['total']; $rating = $profile['rating']; } else { $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM bids WHERE carrier_id = ? AND status = 'accepted'"); $stmt->execute([$_SESSION['user_id']]); $totalDeals = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM bids b JOIN orders o ON b.order_id = o.id WHERE b.carrier_id = ? AND b.status = 'accepted' AND o.status = 'completed'"); $stmt->execute([$_SESSION['user_id']]); $completedDeals = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COALESCE(SUM(b.price), 0) as total FROM bids b JOIN orders o ON b.order_id = o.id WHERE b.carrier_id = ? AND b.status = 'accepted' AND o.status = 'completed'"); $stmt->execute([$_SESSION['user_id']]); $totalEarned = $stmt->fetch()['total']; $rating = $profile['rating']; } require_once BASE_PATH . '/templates/profile.php'; }
function editProfile() { checkAuth(); global $pdo; if ($_SERVER['REQUEST_METHOD'] === 'POST') { $name = trim($_POST['name']); $phone = trim($_POST['phone']); $company = trim($_POST['company_name'] ?? ''); $inn = trim($_POST['inn'] ?? ''); $bank = trim($_POST['bank_details'] ?? ''); $ndsRate = trim($_POST['nds_rate'] ?? '20'); $newPass = $_POST['new_password'] ?? ''; if (empty($name)) { $error = "Имя обязательно"; } else { if (!empty($phone)) { $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ? AND phone != ''"); $stmt->execute([$phone, $_SESSION['user_id']]); if ($stmt->fetch()) { $error = "❌ Этот телефон уже используется"; } } if (!isset($error) && !empty($inn)) { $stmt = $pdo->prepare("SELECT id FROM users WHERE inn = ? AND id != ? AND inn != ''"); $stmt->execute([$inn, $_SESSION['user_id']]); if ($stmt->fetch()) { $error = "❌ Этот ИНН уже используется"; } } if (!isset($error) && !empty($company)) { $stmt = $pdo->prepare("SELECT id FROM users WHERE company_name = ? AND id != ? AND company_name != ''"); $stmt->execute([$company, $_SESSION['user_id']]); if ($stmt->fetch()) { $error = "❌ Это название компании уже используется"; } } if (!isset($error)) { if (!empty($newPass)) { if (strlen($newPass) < 6) { $error = "Пароль минимум 6 символов"; } else { $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, company_name=?, inn=?, nds_rate=?, bank_details=?, password=? WHERE id=?"); $stmt->execute([$name, $phone, $company, $inn, $ndsRate, $bank, password_hash($newPass, PASSWORD_DEFAULT), $_SESSION['user_id']]); $_SESSION['user_name'] = $name; header('Location: /profile?updated=1'); exit; } } else { $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, company_name=?, inn=?, nds_rate=?, bank_details=? WHERE id=?"); $stmt->execute([$name, $phone, $company, $inn, $ndsRate, $bank, $_SESSION['user_id']]); $_SESSION['user_name'] = $name; header('Location: /profile?updated=1'); exit; } } } } $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]); $profile = $stmt->fetch(); require_once BASE_PATH . '/templates/profile_edit.php'; }

// ========== ОПЛАТА ==========
function requestPayment($orderId) { checkAuth(); global $pdo; if ($_SESSION['user_role'] !== 'carrier') die("Только перевозчик"); $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'unloaded'"); $stmt->execute([$orderId]); $order = $stmt->fetch(); if (!$order) die("Не найден"); $stmt = $pdo->prepare("SELECT carrier_id FROM bids WHERE order_id = ? AND status = 'accepted'"); $stmt->execute([$orderId]); $bid = $stmt->fetch(); if (!$bid || $bid['carrier_id'] != $_SESSION['user_id']) die("Не ваш"); $stmt = $pdo->prepare("SELECT bank_details FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]); $carrier = $stmt->fetch(); if (empty($carrier['bank_details'])) { $_SESSION['error'] = "Укажите реквизиты!"; header('Location: /profile/edit'); exit; } $pdo->prepare("UPDATE orders SET payment_status = 'pending' WHERE id = ?")->execute([$orderId]); header('Location: /orders/view/' . $orderId . '?payment_requested=1'); exit; }
function confirmPayment($orderId) { checkAuth(); global $pdo; if ($_SESSION['user_role'] !== 'carrier') die("Только перевозчик"); $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND (payment_status = 'pending' OR payment_status = 'paid')"); $stmt->execute([$orderId]); $order = $stmt->fetch(); if (!$order) die("Не найден"); if ($order['payment_status'] == 'pending') { $pdo->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([$orderId]); header('Location: /orders/view/' . $orderId . '?payment_marked=1'); exit; } if ($order['payment_status'] == 'paid') { $pdo->prepare("UPDATE orders SET payment_status = 'confirmed' WHERE id = ?")->execute([$orderId]); header('Location: /orders/view/' . $orderId . '?payment_confirmed=1'); exit; } }

// ========== ТЕХПОДДЕРЖКА ==========
function listTickets() { checkAuth(); global $pdo; $tickets = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC"); $tickets->execute([$_SESSION['user_id']]); require_once BASE_PATH . '/templates/tickets.php'; }
function createTicket() { checkAuth(); global $pdo; if ($_SERVER['REQUEST_METHOD'] === 'POST') { $subject = trim($_POST['subject']); $message = trim($_POST['message']); if (empty($subject) || empty($message)) { $error = "Заполните все поля"; } else { $stmt = $pdo->prepare("INSERT INTO tickets (user_id, subject, message) VALUES (?, ?, ?)"); $stmt->execute([$_SESSION['user_id'], $subject, $message]); $_SESSION['success'] = "Тикет создан!"; header('Location: /support'); exit; } } require_once BASE_PATH . '/templates/ticket_create.php'; }
function viewTicket($id) { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND user_id = ?"); $stmt->execute([$id, $_SESSION['user_id']]); $ticket = $stmt->fetch(); if (!$ticket) { header('Location: /support'); exit; } require_once BASE_PATH . '/templates/ticket_view.php'; }
function replyTicket($id) { checkAuth(); global $pdo; if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /support/view/' . $id); exit; } $message = trim($_POST['message'] ?? ''); if (empty($message)) { header('Location: /support/view/' . $id); exit; } $stmt = $pdo->prepare("SELECT id, subject FROM tickets WHERE id = ? AND user_id = ? AND status = 'open'"); $stmt->execute([$id, $_SESSION['user_id']]); $ticket = $stmt->fetch(); if (!$ticket) { header('Location: /support'); exit; } $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message) VALUES (?, ?, 0, ?)"); $stmt->execute([$id, $_SESSION['user_id'], $message]); $stmt = $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?"); $stmt->execute([$id]); header('Location: /support/view/' . $id); exit; }
function closeTicket($id) { checkAuth(); global $pdo; $stmt = $pdo->prepare("UPDATE tickets SET status = 'closed' WHERE id = ? AND user_id = ? AND status = 'open'"); $stmt->execute([$id, $_SESSION['user_id']]); header('Location: /support/view/' . $id); exit; }

// ========== АДМИН ==========
function checkAdmin() { checkAuth(); global $pdo; $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?"); $stmt->execute([$_SESSION['user_id']]); if (!$stmt->fetch()['is_admin']) { header('Location: /dashboard'); exit; } }
function adminDashboard() { global $pdo; $stats = []; $stats['users'] = $pdo->query("SELECT COUNT(*) as c FROM users")->fetch()['c']; $stats['owners'] = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role='owner'")->fetch()['c']; $stats['carriers'] = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role='carrier'")->fetch()['c']; $stats['orders'] = $pdo->query("SELECT COUNT(*) as c FROM orders")->fetch()['c']; $stats['completed'] = $pdo->query("SELECT COUNT(*) as c FROM orders WHERE status='completed'")->fetch()['c']; $stats['active'] = $pdo->query("SELECT COUNT(*) as c FROM orders WHERE status NOT IN ('completed','cancelled')")->fetch()['c']; $stats['messages'] = $pdo->query("SELECT COUNT(*) as c FROM messages")->fetch()['c']; $stats['pendingInvoices'] = $pdo->query("SELECT COUNT(*) as c FROM invoices WHERE status='pending'")->fetch()['c']; $latestUsers = $pdo->query("SELECT * FROM users ORDER BY id DESC LIMIT 5")->fetchAll(); $latestOrders = $pdo->query("SELECT o.*, u.name as owner_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC LIMIT 5")->fetchAll(); require_once BASE_PATH . '/templates/admin/dashboard.php'; }
function adminUsers() { global $pdo; $s = $_GET['search'] ?? ''; $w = "WHERE 1=1"; $p = []; if ($s) { $w .= " AND (name LIKE ? OR email LIKE ?)"; $p[] = "%$s%"; $p[] = "%$s%"; } $users = $pdo->prepare("SELECT * FROM users $w ORDER BY id DESC LIMIT 50"); $users->execute($p); require_once BASE_PATH . '/templates/admin/users.php'; }
function adminViewUser($id) { checkAdmin(); global $pdo; $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $stmt->execute([$id]); $user = $stmt->fetch(); if (!$user) { header('Location: /admin/users'); exit; } if ($user['role'] == 'owner') { $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 20"); $stmt->execute([$id]); $orders = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM orders WHERE user_id = ?"); $stmt->execute([$id]); $totalOrders = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM orders WHERE user_id = ? AND status = 'completed'"); $stmt->execute([$id]); $completedOrders = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COALESCE(SUM(price), 0) as total FROM orders WHERE user_id = ?"); $stmt->execute([$id]); $totalAmount = $stmt->fetch()['total']; } else { $stmt = $pdo->prepare("SELECT o.*, b.price as bid_price, b.status as bid_status FROM orders o JOIN bids b ON o.id = b.order_id WHERE b.carrier_id = ? ORDER BY o.id DESC LIMIT 20"); $stmt->execute([$id]); $orders = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM bids WHERE carrier_id = ?"); $stmt->execute([$id]); $totalBids = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM bids WHERE carrier_id = ? AND status = 'accepted'"); $stmt->execute([$id]); $acceptedBids = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT COALESCE(SUM(b.price), 0) as total FROM bids b JOIN orders o ON b.order_id = o.id WHERE b.carrier_id = ? AND b.status = 'accepted' AND o.status = 'completed'"); $stmt->execute([$id]); $totalEarned = $stmt->fetch()['total']; } $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY started_at DESC LIMIT 5"); $stmt->execute([$id]); $subscriptions = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT * FROM invoices WHERE user_id = ? ORDER BY id DESC LIMIT 10"); $stmt->execute([$id]); $invoices = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT r.*, u.name as reviewer_name FROM reviews r JOIN users u ON r.reviewer_id = u.id WHERE r.reviewed_id = ? ORDER BY r.id DESC"); $stmt->execute([$id]); $reviews = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 10"); $stmt->execute([$id]); $tickets = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE ref_by = ?"); $stmt->execute([$id]); $refCount = $stmt->fetch()['cnt']; $stmt = $pdo->prepare("SELECT name, email FROM users WHERE ref_by = ? ORDER BY id DESC LIMIT 20"); $stmt->execute([$id]); $refUsers = $stmt->fetchAll(); require_once BASE_PATH . '/templates/admin/user_view.php'; }
function adminOrders() { global $pdo; $orders = $pdo->query("SELECT o.*, u.name as owner_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC LIMIT 50")->fetchAll(); require_once BASE_PATH . '/templates/admin/orders.php'; }
function adminViewOrder($id) { checkAdmin(); global $pdo; $stmt = $pdo->prepare("SELECT o.*, u.name as owner_name, u.email as owner_email, u.phone as owner_phone, u.company_name as owner_company, u.rating as owner_rating FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?"); $stmt->execute([$id]); $order = $stmt->fetch(); if (!$order) { header('Location: /admin/orders'); exit; } $stmt = $pdo->prepare("SELECT u.*, b.price as bid_price, b.status as bid_status FROM bids b JOIN users u ON b.carrier_id = u.id WHERE b.order_id = ? AND b.status = 'accepted' LIMIT 1"); $stmt->execute([$id]); $carrier = $stmt->fetch(); $stmt = $pdo->prepare("SELECT b.*, u.name, u.company_name, u.rating, u.phone, u.email FROM bids b JOIN users u ON b.carrier_id = u.id WHERE b.order_id = ? ORDER BY b.id DESC"); $stmt->execute([$id]); $bids = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT h.*, u.name, u.role FROM order_status_history h JOIN users u ON h.changed_by = u.id WHERE h.order_id = ? ORDER BY h.id ASC"); $stmt->execute([$id]); $history = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT m.*, u.name as sender_name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.order_id = ? ORDER BY m.id ASC"); $stmt->execute([$id]); $messages = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT * FROM documents WHERE order_id = ? ORDER BY id DESC"); $stmt->execute([$id]); $documents = $stmt->fetchAll(); $stmt = $pdo->prepare("SELECT r.*, u.name as reviewer_name FROM reviews r JOIN users u ON r.reviewer_id = u.id WHERE r.order_id = ?"); $stmt->execute([$id]); $reviews = $stmt->fetchAll(); require_once BASE_PATH . '/templates/admin/order_view.php'; }
function adminInvoices() { global $pdo; $invoices = $pdo->query("SELECT i.*, u.name, u.email FROM invoices i JOIN users u ON i.user_id = u.id ORDER BY i.id DESC")->fetchAll(); require_once BASE_PATH . '/templates/admin/invoices.php'; }
function adminTickets() { global $pdo; $tickets = $pdo->query("SELECT t.*, u.name, u.email FROM tickets t JOIN users u ON t.user_id = u.id ORDER BY t.status ASC, t.id DESC")->fetchAll(); require_once BASE_PATH . '/templates/admin/tickets.php'; }
function adminReplyTicket($id) { global $pdo; if ($_SERVER['REQUEST_METHOD'] === 'POST') { $reply = trim($_POST['reply']); if (!empty($reply)) { $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, user_id, is_admin, message) VALUES (?, NULL, 1, ?)"); $stmt->execute([$id, $reply]); $pdo->prepare("UPDATE tickets SET admin_reply = ?, status = 'open', updated_at = NOW() WHERE id = ?")->execute([$reply, $id]); $stmt = $pdo->prepare("SELECT user_id, subject FROM tickets WHERE id = ?"); $stmt->execute([$id]); $ticket = $stmt->fetch(); $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'support', 'Новый ответ', ?, '/support')")->execute([$ticket['user_id'], "Ответ на «{$ticket['subject']}»."]); } header('Location: /admin/tickets'); exit; } $stmt = $pdo->prepare("SELECT t.*, u.name FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?"); $stmt->execute([$id]); $ticket = $stmt->fetch(); $stmt = $pdo->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id ASC"); $stmt->execute([$id]); $messages = $stmt->fetchAll(); require_once BASE_PATH . '/templates/admin/ticket_reply.php'; }
function adminCloseTicket($id) { checkAdmin(); global $pdo; $pdo->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?")->execute([$id]); header('Location: /admin/tickets'); exit; }
function adminMailing() { checkAdmin(); global $pdo; if ($_SERVER['REQUEST_METHOD'] === 'POST') { $subject = trim($_POST['subject'] ?? ''); $message = trim($_POST['message'] ?? ''); $target = $_POST['target'] ?? 'all'; if (empty($subject) || empty($message)) { $error = "Заполните тему и сообщение"; } else { $adminName = $_SESSION['user_name'] ?? 'Администратор'; if ($target == 'carriers') { $stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'carrier'"); } elseif ($target == 'owners') { $stmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'owner'"); } else { $stmt = $pdo->query("SELECT id, name, email FROM users"); } $users = $stmt->fetchAll(); $sent = 0; foreach ($users as $user) { sendEmail($user['email'], $subject, "Здравствуйте, {$user['name']}!\n\n{$message}\n\nС уважением,\n{$adminName}\nAVL Logistic"); $notifTitle = "📢 {$adminName}: {$subject}"; $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'mailing', ?, ?, '/notifications')"); $stmt->execute([$user['id'], $notifTitle, $message]); $sent++; } $success = "Рассылка отправлена! Получателей: {$sent}"; } } require_once BASE_PATH . '/templates/admin/mailing.php'; }
function adminPayInvoice($id) { global $pdo; $inv = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND status='pending'"); $inv->execute([$id]); $inv = $inv->fetch(); if (!$inv) { header('Location: /admin/invoices'); exit; } $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$id]); $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status='active'")->execute([$inv['user_id']]); $pdo->prepare("INSERT INTO subscriptions (user_id, plan, status, expires_at) VALUES (?,?, 'active', DATE_ADD(NOW(), INTERVAL 1 MONTH))")->execute([$inv['user_id'], $inv['plan']]); header('Location: /admin/invoices?paid=1'); exit; }
function adminCancelInvoice($id) { global $pdo; $pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=? AND status='pending'")->execute([$id]); header('Location: /admin/invoices?cancelled=1'); exit; }

// ========== РОУТЕР ==========
$url = $_GET['url'] ?? 'home'; $url = rtrim($url, '/'); $parts = explode('/', $url);
$page = $parts[0] ?? 'home'; $action = $parts[1] ?? 'index'; $id = $parts[2] ?? null;

switch ($page) {
    case 'r': if (!empty($action)) { refRegister($action); break; } header('Location: /'); exit;
    case 'register': register(); break;
    case 'login': login(); break;
    case 'logout': logout(); break;
    case 'verify': verifyEmail(); break;
    case 'forgot': forgotPassword(); break;
    case 'reset': resetPassword(); break;
    case 'ref': refPage(); break;
    case 'orders': if ($action == 'create') createOrder(); elseif ($action == 'view' && $id) viewOrder($id); elseif ($action == 'my') myOrders(); else listOrders(); break;
    case 'bids': if ($action == 'place' && $id) placeBid($id); elseif ($action == 'accept') acceptBid(); break;
    case 'status': if ($action == 'update' && $id) updateOrderStatus($id); break;
    case 'upload': if ($action == 'scan' && $id) uploadScan($id); break;
    case 'documents': if ($action == 'view' && $id) viewDocument($id, $_GET['type'] ?? 'contract'); break;
    case 'chat': if ($action == 'send' && $id) sendMessage($id); elseif ($action == 'load' && $id) loadMessages($id); elseif ($action == 'view' && $id) chatView($id); break;
    case 'messages': messagesList(); break;
    case 'review': if ($action == 'create' && $id) createReview($id); break;
    case 'subscribe': activateSubscription(); break;
    case 'invoices': userInvoices(); break;
    case 'notifications': notificationsPage(); break;
    case 'profile': if ($action == 'edit') editProfile(); else viewProfile(); break;
    case 'payment': if ($action == 'request' && $id) requestPayment($id); elseif ($action == 'confirm' && $id) confirmPayment($id); break;
    case 'support':
        if ($action == 'create') createTicket();
        elseif ($action == 'view' && $id) viewTicket($id);
        elseif ($action == 'reply' && $id) replyTicket($id);
        elseif ($action == 'close' && $id) closeTicket($id);
        else listTickets(); break;
    case 'api':
        if ($action == 'counters') {
            header('Content-Type: application/json'); checkAuth(); global $pdo;
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM messages m JOIN orders o ON m.order_id = o.id JOIN bids b ON o.id = b.order_id AND b.status = 'accepted' WHERE m.receiver_id = ? AND m.is_read = 0 AND (o.user_id = ? OR b.carrier_id = ?)");
            $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]); $msg = $stmt->fetch()['cnt'];
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$_SESSION['user_id']]); $not = $stmt->fetch()['cnt'];
            echo json_encode(['messages' => $msg, 'notifications' => $not]); exit;
        } elseif ($action == 'latest_orders') {
            header('Content-Type: application/json');
            global $pdo;
            $stmt = $pdo->query("SELECT o.id, o.title, o.cargo_type, o.weight, o.pickup_address, o.delivery_address, o.price, o.created_at, u.company_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status = 'new' ORDER BY o.id DESC LIMIT 3");
            echo json_encode($stmt->fetchAll()); exit;
        } elseif ($action == 'login') {
            header('Content-Type: application/json');
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            
            global $pdo;
            $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && !empty($password) && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Неверный логин или пароль']);
            }
            exit;
        } elseif ($action == 'orders_list') {
            header('Content-Type: application/json');
            checkAuth();
            global $pdo;
            $stmt = $pdo->query("SELECT o.*, u.company_name, u.rating FROM orders o JOIN users u ON o.user_id = u.id WHERE o.status = 'new' ORDER BY o.id DESC LIMIT 50");
            echo json_encode($stmt->fetchAll()); exit;
        } elseif ($action == 'my_orders') {
            header('Content-Type: application/json');
            checkAuth();
            global $pdo;
            $userId = $_SESSION['user_id'];
            if ($_SESSION['user_role'] == 'owner') {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC");
            } else {
                $stmt = $pdo->prepare("SELECT o.*, b.status as bid_status, b.price as bid_price FROM orders o JOIN bids b ON o.id = b.order_id WHERE b.carrier_id = ? ORDER BY o.id DESC");
            }
            $stmt->execute([$userId]);
            echo json_encode($stmt->fetchAll()); exit;
        } elseif ($action == 'chat_list') {
            header('Content-Type: application/json');
            checkAuth();
            global $pdo;
            $userId = $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT o.id, o.title, u.name as companion_name, (SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.receiver_id = ? AND m.is_read = 0) as unread FROM orders o JOIN bids b ON o.id = b.order_id AND b.status = 'accepted' JOIN users u ON b.carrier_id = u.id WHERE o.user_id = ? UNION SELECT o.id, o.title, u.name as companion_name, (SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.receiver_id = ? AND m.is_read = 0) as unread FROM orders o JOIN bids b ON o.id = b.order_id AND b.status = 'accepted' JOIN users u ON o.user_id = u.id WHERE b.carrier_id = ? ORDER BY id DESC");
            $stmt->execute([$userId, $userId, $userId, $userId]);
            echo json_encode($stmt->fetchAll()); exit;
        } elseif ($action == 'profile') {
            header('Content-Type: application/json');
            checkAuth();
            global $pdo;
            $stmt = $pdo->prepare("SELECT id, name, email, phone, role, company_name, inn, rating FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            echo json_encode($stmt->fetch()); exit;
        }
        
        elseif ($action == 'register') {
    header('Content-Type: application/json');
    
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'carrier';
    
    global $pdo;
    
    // Проверка email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Email уже используется']);
        exit;
    }
    
    $code = md5($email . time() . rand(1000, 9999));
    $refCode = strtoupper(substr(md5($email . time()), 0, 8));
    
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, verification_code, ref_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $role, $code, $refCode]);
    $userId = $pdo->lastInsertId();
    
    if ($role === 'carrier') {
        $stmt = $pdo->prepare("INSERT INTO subscriptions (user_id, plan, status, expires_at) VALUES (?, 'free', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $stmt->execute([$userId]);
    }
    
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $role;
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'role' => $role
        ]
    ]);
    exit;
}
        elseif ($action == 'create_order') {
    header('Content-Type: application/json');
    checkAuth();
    global $pdo;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Неверные данные']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, title, cargo_type, weight, volume, pickup_address, delivery_address, pickup_date, delivery_date, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        $input['title'] ?? '',
        $input['cargo_type'] ?? '',
        $input['weight'] ?? '0',
        $input['volume'] ?? '0',
        $input['pickup_address'] ?? '',
        $input['delivery_address'] ?? '',
        $input['pickup_date'] ?? '',
        $input['delivery_date'] ?? '',
        $input['price'] ?? '0'
    ]);
    
    echo json_encode(['success' => true, 'order_id' => $pdo->lastInsertId()]);
    exit;
}
        break;
    case 'page': if ($action == 'carriers') require_once BASE_PATH . '/templates/page_carriers.php'; elseif ($action == 'owners') require_once BASE_PATH . '/templates/page_owners.php'; break;
    case 'admin': checkAdmin();
        if ($action == 'users') adminUsers();
        elseif ($action == 'user' && $id) adminViewUser($id);
        elseif ($action == 'orders') adminOrders();
        elseif ($action == 'order' && $id) adminViewOrder($id);
        elseif ($action == 'invoices') adminInvoices();
        elseif ($action == 'tickets') adminTickets();
        elseif ($action == 'ticket' && $id) adminReplyTicket($id);
        elseif ($action == 'close_ticket' && $id) adminCloseTicket($id);
        elseif ($action == 'mailing') adminMailing();
        elseif ($action == 'pay_invoice' && $id) adminPayInvoice($id);
        elseif ($action == 'cancel_invoice' && $id) adminCancelInvoice($id);
        else adminDashboard(); break;
    case 'pricing': require_once BASE_PATH . '/templates/pricing.php'; break;
    case 'dashboard': require_once BASE_PATH . '/templates/dashboard.php'; break;
    default: if (isset($_SESSION['user_id'])) require_once BASE_PATH . '/templates/dashboard.php'; else require_once BASE_PATH . '/templates/landing.php'; break;
}