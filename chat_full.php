<?php
session_start();
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

$orderId = $_GET['order_id'] ?? 0;

// Получаем заказ
$stmt = $pdo->prepare("SELECT o.*, u.name as owner_name, u.id as owner_id FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /orders/my');
    exit;
}

// Определяем собеседника
if ($_SESSION['user_id'] == $order['owner_id']) {
    $stmt = $pdo->prepare("SELECT u.id, u.name, u.company_name FROM users u JOIN bids b ON u.id = b.carrier_id WHERE b.order_id = ? AND b.status = 'accepted'");
    $stmt->execute([$orderId]);
    $companion = $stmt->fetch();
} else {
    $companion = ['id' => $order['owner_id'], 'name' => $order['owner_name'], 'company_name' => ''];
}

if (!$companion) die("Собеседник не найден");

// Отмечаем сообщения прочитанными
$stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE order_id = ? AND receiver_id = ? AND is_read = 0");
$stmt->execute([$orderId, $_SESSION['user_id']]);

$companionName = htmlspecialchars($companion['company_name'] ?: $companion['name']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Чат — <?= htmlspecialchars($order['title']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #f8fafc;
            height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }
        
        /* Шапка */
        .chat-nav {
            background: #0a0a0a;
            color: white;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            height: 52px;
            flex-shrink: 0;
            border-bottom: 2px solid #f59e0b;
        }
        
        .chat-nav .back {
            color: white;
            text-decoration: none;
            font-size: 20px;
            padding: 4px 8px;
        }
        
        .chat-nav .title {
            font-weight: 700;
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chat-nav .subtitle {
            font-size: 11px;
            color: #94a3b8;
        }
        
        /* Сообщения */
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: #f1f5f9;
        }
        
        .msg {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
            position: relative;
        }
        
        .msg.mine {
            align-self: flex-end;
            background: #0a0a0a;
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .msg.other {
            align-self: flex-start;
            background: white;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 4px;
        }
        
        .msg .time {
            font-size: 9px;
            opacity: 0.5;
            margin-top: 4px;
            text-align: right;
        }
        
        /* Поле ввода */
        .input-area {
            padding: 10px 14px;
            background: white;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 8px;
            flex-shrink: 0;
            padding-bottom: max(10px, env(safe-area-inset-bottom));
        }
        
        .input-area input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 24px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            outline: none;
            background: #f8fafc;
        }
        
        .input-area input:focus {
            border-color: #f59e0b;
            background: white;
        }
        
        .input-area button {
            width: 44px;
            height: 44px;
            background: #f59e0b;
            color: #0a0a0a;
            border: none;
            border-radius: 50%;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <div class="chat-nav">
        <a href="/chat/view/<?= $orderId ?>" class="back">←</a>
        <div>
            <div class="title"><?= htmlspecialchars($order['title']) ?></div>
            <div class="subtitle"><?= $companionName ?></div>
        </div>
    </div>
    
    <div class="messages" id="messages">
        <div style="text-align:center;color:#94a3b8;padding:20px;">Загрузка...</div>
    </div>
    
    <div class="input-area">
        <input type="text" id="msgInput" placeholder="Сообщение..." autocomplete="off">
        <button onclick="sendMsg()">➤</button>
    </div>

    <script>
        var orderId = <?= $orderId ?>;
        var myId = <?= $_SESSION['user_id'] ?>;
        var lastCount = 0;
        
        function loadMsg() {
            fetch('/chat/load/' + orderId)
                .then(function(r) { return r.json(); })
                .then(function(msgs) {
                    if (msgs.length === lastCount) return;
                    lastCount = msgs.length;
                    
                    var html = '';
                    msgs.forEach(function(m) {
                        var isMine = m.sender_id == myId;
                        html += '<div class="msg ' + (isMine ? 'mine' : 'other') + '">' +
                            (m.message || '') +
                            '<div class="time">' + m.created_at.substring(11, 16) + '</div>' +
                            '</div>';
                    });
                    
                    var container = document.getElementById('messages');
                    var wasAtBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 50;
                    container.innerHTML = html || '<div style="text-align:center;color:#94a3b8;padding:20px;">Нет сообщений</div>';
                    if (wasAtBottom) container.scrollTop = container.scrollHeight;
                });
        }
        
        function sendMsg() {
            var input = document.getElementById('msgInput');
            var msg = input.value.trim();
            if (!msg) return;
            
            var formData = new FormData();
            formData.append('message', msg);
            formData.append('receiver_id', 0);
            
            fetch('/chat/send/' + orderId, { method: 'POST', body: formData })
                .then(function() { input.value = ''; loadMsg(); });
            
            input.focus();
        }
        
        loadMsg();
        setInterval(loadMsg, 3000);
        
        document.getElementById('msgInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') sendMsg();
        });
    </script>
</body>
</html>