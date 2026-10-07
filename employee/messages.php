<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$database = require_once __DIR__ . '/../api/db_con.php';
require_once __DIR__ . '/../api/customer-assignment.php';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['user_role'] ?? '');

if ($userId <= 0 || $userRole !== 'employee') {
    header('Location: ../user/login.php');
    exit;
}

if (($_GET['poll'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $pollTicketId = filter_input(INPUT_GET, 'ticket_id', FILTER_VALIDATE_INT);
    $lastMessageId = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT);

    if (!$pollTicketId || $lastMessageId === false || $lastMessageId === null || $lastMessageId < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid conversation or message cursor.']);
        exit;
    }

    $pollAccess = $database->prepare("SELECT Ticket_ID FROM support_tickets t
        INNER JOIN customer_employee_assignments a ON a.Customer_ID = t.User_ID
        WHERE t.Ticket_ID = ? AND a.Employee_ID = ? LIMIT 1");
    $pollAccess->execute([$pollTicketId, $userId]);
    if (!$pollAccess->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'That conversation could not be found.']);
        exit;
    }

    $newMessagesStmt = $database->prepare("SELECT Message_ID, message, created_at
        FROM support_messages
        WHERE Ticket_ID = ? AND Message_ID > ? AND sender_type = 'customer'
        ORDER BY Message_ID ASC");
    $newMessagesStmt->execute([$pollTicketId, $lastMessageId]);
    $newMessages = $newMessagesStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($newMessages) {
        $database->prepare("UPDATE support_messages SET is_read = 1 WHERE Ticket_ID = ? AND sender_type = 'customer' AND Message_ID <= ?")
            ->execute([$pollTicketId, (int) end($newMessages)['Message_ID']]);
    }

    echo json_encode(['messages' => array_map(static function (array $message): array {
        return [
            'id' => (int) $message['Message_ID'],
            'text' => (string) $message['message'],
            'time' => date('g:i A', strtotime((string) $message['created_at']))
        ];
    }, $newMessages)]);
    exit;
}

$database->exec("CREATE TABLE IF NOT EXISTS support_tickets (
    Ticket_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL,
    Booking_ID INT UNSIGNED NULL,
    subject VARCHAR(255) NOT NULL DEFAULT 'General Inquiry',
    status ENUM('open', 'pending', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_user FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_booking FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mtrBackfillCustomerEmployeeAssignments($database);

$database->exec("CREATE TABLE IF NOT EXISTS support_messages (
    Message_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Ticket_ID INT UNSIGNED NULL,
    Sender_ID INT UNSIGNED NOT NULL,
    sender_type ENUM('customer', 'admin', 'employee') NOT NULL DEFAULT 'customer',
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_message_sender_employee FOREIGN KEY (Sender_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$messageColumns = $database->query('SHOW COLUMNS FROM support_messages')->fetchAll(PDO::FETCH_COLUMN);
if (in_array('sender_type', array_map('strtolower', $messageColumns), true)) {
    $database->exec("ALTER TABLE support_messages MODIFY COLUMN sender_type ENUM('customer', 'admin', 'employee') NOT NULL DEFAULT 'customer'");
}

$selectedTicketId = filter_input(INPUT_GET, 'ticket_id', FILTER_VALIDATE_INT) ?: 0;
$errorMessage = '';
$wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedTicketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT) ?: 0;
    $reply = trim((string) ($_POST['message'] ?? ''));

    if (!$selectedTicketId || $reply === '' || mb_strlen($reply) > 5000) {
        $errorMessage = 'Select a conversation and enter a message up to 5,000 characters.';
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(422);
            echo json_encode(['error' => $errorMessage]);
            exit;
        }
    } else {
        $ticketCheck = $database->prepare("SELECT t.Ticket_ID FROM support_tickets t
            INNER JOIN customer_employee_assignments a ON a.Customer_ID = t.User_ID
            WHERE t.Ticket_ID = ? AND a.Employee_ID = ? LIMIT 1");
        $ticketCheck->execute([$selectedTicketId, $userId]);
        if (!$ticketCheck->fetchColumn()) {
            $errorMessage = 'That conversation could not be found.';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(404);
                echo json_encode(['error' => $errorMessage]);
                exit;
            }
        } else {
            $insertReply = $database->prepare("INSERT INTO support_messages (Ticket_ID, Sender_ID, sender_type, message, is_read) VALUES (?, ?, 'employee', ?, 0)");
            $insertReply->execute([$selectedTicketId, $userId, $reply]);
            $messageId = (int) $database->lastInsertId();
            $database->prepare("UPDATE support_tickets SET status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE Ticket_ID = ?")->execute([$selectedTicketId]);
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'message' => [
                        'id' => $messageId,
                        'text' => $reply,
                        'time' => date('g:i A')
                    ]
                ]);
                exit;
            }
            header('Location: messages.php?ticket_id=' . $selectedTicketId);
            exit;
        }
    }
}

$conversationStatement = $database->prepare("SELECT t.Ticket_ID AS ticket_id, t.status, t.updated_at, u.User_ID AS user_id, u.full_name, u.email,
    (SELECT sm.message FROM support_messages sm WHERE sm.Ticket_ID = t.Ticket_ID ORDER BY sm.created_at DESC, sm.Message_ID DESC LIMIT 1) AS last_message,
    (SELECT COUNT(*) FROM support_messages sm WHERE sm.Ticket_ID = t.Ticket_ID AND sm.sender_type = 'customer' AND sm.is_read = 0) AS unread_count
    FROM support_tickets t
    INNER JOIN users u ON u.User_ID = t.User_ID
    INNER JOIN customer_employee_assignments a ON a.Customer_ID = t.User_ID AND a.Employee_ID = ?
    ORDER BY t.updated_at DESC, t.Ticket_ID DESC");
$conversationStatement->execute([$userId]);
$conversations = $conversationStatement->fetchAll();

$selectedConversation = null;
$messages = [];
if ($selectedTicketId) {
    $selectedStmt = $database->prepare("SELECT t.Ticket_ID AS ticket_id, t.status, u.full_name, u.email FROM support_tickets t INNER JOIN users u ON u.User_ID = t.User_ID INNER JOIN customer_employee_assignments a ON a.Customer_ID = t.User_ID WHERE t.Ticket_ID = ? AND a.Employee_ID = ? LIMIT 1");
    $selectedStmt->execute([$selectedTicketId, $userId]);
    $selectedConversation = $selectedStmt->fetch();
    if ($selectedConversation) {
        $messageStmt = $database->prepare('SELECT Message_ID, message, sender_type, created_at FROM support_messages WHERE Ticket_ID = ? ORDER BY created_at ASC, Message_ID ASC');
        $messageStmt->execute([$selectedTicketId]);
        $messages = $messageStmt->fetchAll();
        $database->prepare("UPDATE support_messages SET is_read = 1 WHERE Ticket_ID = ? AND sender_type = 'customer'")->execute([$selectedTicketId]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Messages | Mindanao Travel Hub</title>
    <style>
        :root {
            --navy:#102a43;
            --blue:#0ea5e9;
            --blue-dark:#0369a1;
            --bg:#edf4f9;
            --panel:#f8fbff;
            --card:#ffffff;
            --card-strong:#f3f8ff;
            --text:#102a43;
            --muted:#60708a;
            --line:#dfeaf3;
            --shadow:0 18px 45px rgba(16,42,67,.08);
            --success:#eafbf2;
            --success-text:#1a7b4d;
            --warning:#fff7ee;
            --warning-text:#b7661e;
            --danger:#fff1f2;
            --danger-text:#b42318;
            --radius:18px;
            font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
        }

        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body {
            margin:0;
            background:
                radial-gradient(circle at top left, rgba(14,165,233,.12), transparent 30%),
                linear-gradient(180deg, #f4f8fc 0%, #eef4fa 100%);
            color:var(--text);
        }
        a { color:inherit; text-decoration:none; }
        button, textarea { font:inherit; }

        .page {
            width:min(1220px, calc(100% - 36px));
            margin:auto;
            padding:28px 0 54px;
        }

        .topbar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            margin-bottom:26px;
            padding:14px 18px;
            border:1px solid rgba(15, 23, 42, 0.04);
            border-radius:18px;
            background:rgba(255,255,255,.7);
            backdrop-filter:blur(8px);
            box-shadow:0 10px 25px rgba(15,23,42,.04);
        }

        .brand {
            display:flex;
            align-items:center;
            gap:12px;
        }

        .brand img {
            width:44px;
            height:44px;
            padding:7px;
            border-radius:12px;
            background:linear-gradient(135deg,#ffffff,#edf7ff);
            border:1px solid var(--line);
            object-fit:contain;
        }

        .brand strong,
        .brand small { display:block; }
        .brand strong {
            font-size:15px;
            letter-spacing:.01em;
        }
        .brand small {
            margin-top:2px;
            color:var(--muted);
            font-size:11px;
            letter-spacing:.08em;
            text-transform:uppercase;
        }

        .back-link {
            display:inline-flex;
            align-items:center;
            gap:8px;
            color:var(--blue-dark);
            font-size:13px;
            font-weight:700;
            padding:9px 12px;
            border-radius:10px;
            transition:all .2s ease;
        }
        .back-link:hover { background:#edf7ff; }

        .intro {
            margin-bottom:24px;
        }
        .eyebrow {
            margin:0 0 8px;
            color:var(--blue-dark);
            font-size:11px;
            font-weight:800;
            letter-spacing:.14em;
            text-transform:uppercase;
        }
        .intro h1 {
            margin:0;
            font-size:clamp(30px,4vw,40px);
            line-height:1.1;
        }
        .intro p {
            margin:10px 0 0;
            color:var(--muted);
            font-size:14px;
        }

        .layout {
            display:grid;
            grid-template-columns:340px minmax(0,1fr);
            gap:18px;
            align-items:stretch;
        }

        .card {
            border:1px solid var(--line);
            border-radius:var(--radius);
            background:rgba(255,255,255,.9);
            box-shadow:var(--shadow);
        }

        .inbox {
            padding:18px;
            overflow:hidden;
        }
        .inbox h2 {
            margin:0 0 14px;
            font-size:17px;
        }

        .conversation-list {
            display:grid;
            gap:10px;
            max-height:720px;
            overflow:auto;
            padding-right:4px;
        }
        .conversation-list::-webkit-scrollbar { width:8px; }
        .conversation-list::-webkit-scrollbar-thumb {
            background:#d7e4f0;
            border-radius:999px;
        }

        .conversation {
            display:block;
            padding:14px 14px 12px;
            border:1px solid var(--line);
            border-radius:14px;
            background:linear-gradient(180deg,#fff,#f8fbff);
            transition:all .2s ease;
        }
        .conversation:hover,
        .conversation.active {
            border-color:#bcdff8;
            background:linear-gradient(180deg,#f3faff,#edf7ff);
            transform:translateY(-1px);
            box-shadow:0 8px 18px rgba(14,165,233,.08);
        }

        .conversation-head {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
        }
        .conversation-head strong {
            font-size:14px;
            line-height:1.3;
        }

        .badge {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:24px;
            height:22px;
            padding:0 7px;
            border-radius:999px;
            background:var(--blue);
            color:#fff;
            font-size:11px;
            font-weight:800;
            box-shadow:0 6px 14px rgba(14,165,233,.22);
        }

        .conversation small {
            display:block;
            margin-top:5px;
            color:var(--muted);
            font-size:11px;
            line-height:1.4;
        }

        .conversation p {
            margin:8px 0 0;
            color:var(--text);
            font-size:12px;
            line-height:1.5;
            display:-webkit-box;
            -webkit-box-orient:vertical;
            -webkit-line-clamp:2;
            overflow:hidden;
        }

        .chat {
            display:flex;
            height:620px;
            max-height:calc(100vh - 40px);
            min-height:420px;
            flex-direction:column;
            overflow:hidden;
            padding:0;
            background:var(--card);
        }

        .chat-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:15px;
            padding:20px 22px;
            border-bottom:1px solid var(--line);
            margin:0;
        }

        .chat-meta {
            display:flex;
            align-items:center;
            gap:12px;
        }

        .chat-avatar {
            width:42px;
            height:42px;
            display:grid;
            place-items:center;
            border-radius:12px;
            background:linear-gradient(135deg,#dbeeff,#edf8ff);
            color:var(--blue-dark);
            font-weight:800;
            border:1px solid #cfeaff;
        }

        .chat-header h2 {
            margin:0;
            font-size:17px;
            line-height:1.2;
        }
        .chat-header p {
            margin:4px 0 0;
            color:var(--muted);
            font-size:11px;
        }

        .status-pill {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:6px 8px;
            border-radius:5px;
            background:var(--success);
            color:var(--success-text);
            font-size:10px;
            font-weight:800;
            text-transform:capitalize;
            white-space:nowrap;
        }
        .status-pill.pending,
        .status-pill.open {
            background:var(--warning);
            color:var(--warning-text);
        }
        .status-pill.closed {
            background:#f1f5f9;
            color:#475569;
        }

        .messages {
            display:flex;
            flex:1;
            min-height:0;
            flex-direction:column;
            gap:13px;
            padding:22px;
            overflow-y:auto;
            overscroll-behavior:contain;
            background:#fbfeff;
        }

        .message {
            display:flex;
            max-width:76%;
            flex-direction:column;
            align-self:flex-start;
        }
        .message.outgoing {
            align-self:flex-end;
            align-items:flex-end;
        }

        .bubble {
            padding:11px 13px;
            border-radius:12px 12px 12px 3px;
            background:#fff;
            color:var(--text);
            font-size:12px;
            line-height:1.5;
            white-space:pre-wrap;
            word-break:break-word;
            box-shadow:0 4px 14px rgba(2,6,23,.05);
        }
        .message.outgoing .bubble {
            border-radius:12px 12px 3px 12px;
            background:var(--blue);
            color:#fff;
        }
        .message time {
            margin-top:4px;
            color:#94a3b8;
            font-size:10px;
        }

        .empty {
            padding:24px 18px;
            color:var(--muted);
            font-size:13px;
            text-align:center;
        }

        .composer {
            display:flex;
            align-items:flex-end;
            gap:9px;
            padding:14px 22px 20px;
        }

        .composer textarea {
            width:100%;
            min-height:43px;
            max-height:100px;
            resize:none;
            padding:12px;
            border:1px solid var(--line);
            border-radius:9px;
            outline:none;
            background:#fff;
            color:var(--text);
            font:inherit;
            font-size:12px;
        }
        .composer textarea:focus {
            border-color:var(--blue);
            box-shadow:0 0 0 3px rgba(14,165,233,.12);
        }

        .send-button {
            display:grid;
            width:43px;
            height:43px;
            flex:0 0 43px;
            place-items:center;
            border:0;
            border-radius:9px;
            background:var(--blue);
            color:#fff;
            cursor:pointer;
        }
        .send-button:hover { background:var(--blue-dark); }
        .send-button svg {
            width:18px;
            height:18px;
            fill:none;
            stroke:currentColor;
            stroke-linecap:round;
            stroke-linejoin:round;
            stroke-width:2;
        }

        .error {
            margin:0 0 14px;
            padding:12px 14px;
            border-radius:12px;
            background:var(--danger);
            color:var(--danger-text);
            border:1px solid rgba(180,35,24,.08);
            font-size:12px;
            font-weight:600;
        }

        @media (max-width: 920px) {
            .layout { grid-template-columns:1fr; }
            .chat { height:560px; min-height:unset; }
            .messages { min-height:0; }
            .conversation-list { max-height:320px; }
        }

        @media (max-width: 560px) {
            .page { width:min(100% - 18px, 1220px); padding-top:20px; }
            .topbar { padding:12px 14px; }
            .brand strong { font-size:13px; }
            .brand small { letter-spacing:.05em; }
            .chat-header {
                flex-direction:column;
                align-items:flex-start;
            }
            .messages { padding:17px; }
            .composer { padding:12px 17px 17px; }
            .message { max-width:88%; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div class="brand">
            <img src="../assets/logo/logo.png" alt="MTR logo">
            <div><strong>Mindanao Travel Hub</strong><small>Employee operations</small></div>
        </div>
        <a class="back-link" href="dashboard.php">← Back to dashboard</a>
    </div>

    <div class="intro">
        <p class="eyebrow">Support</p>
        <h1>Customer inbox</h1>
        <p>Review customer tickets and send follow-ups from the employee desk.</p>
    </div>

    <div class="layout">
        <aside class="card inbox">
            <h2>Conversations</h2>
            <div class="conversation-list">
                <?php if (!$conversations): ?>
                    <p class="empty">No conversations yet.</p>
                <?php else: ?>
                    <?php foreach ($conversations as $conversation): ?>
                        <a class="conversation <?= $selectedTicketId === (int) $conversation['ticket_id'] ? 'active' : '' ?>" href="messages.php?ticket_id=<?= (int) $conversation['ticket_id'] ?>">
                            <div class="conversation-head">
                                <strong><?= htmlspecialchars($conversation['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ((int) $conversation['unread_count']): ?><span class="badge"><?= (int) $conversation['unread_count'] ?></span><?php endif; ?>
                            </div>
                            <small><?= htmlspecialchars($conversation['email'], ENT_QUOTES, 'UTF-8') ?></small>
                            <p><?= htmlspecialchars($conversation['last_message'] ?: 'No messages yet.', ENT_QUOTES, 'UTF-8') ?></p>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <main class="card chat">
            <?php if ($errorMessage): ?>
                <p class="error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if (!$selectedConversation): ?>
                <div class="empty">Select a customer conversation to view the thread.</div>
            <?php else: ?>
                <div class="chat-header">
                    <div class="chat-meta">
                        <div class="chat-avatar"><?= htmlspecialchars(strtoupper(substr($selectedConversation['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
                        <div>
                            <h2><?= htmlspecialchars($selectedConversation['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($selectedConversation['email'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                    <span class="status-pill <?= htmlspecialchars((string) $selectedConversation['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $selectedConversation['status'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="messages">
                    <?php if (!$messages): ?>
                        <p class="empty">No messages in this thread yet.</p>
                    <?php else: ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="message <?= $message['sender_type'] === 'customer' ? '' : 'outgoing' ?>" data-message-id="<?= (int) $message['Message_ID'] ?>" data-message-sender="<?= htmlspecialchars((string) $message['sender_type'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="bubble"><?= htmlspecialchars((string) $message['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                <time><?= htmlspecialchars(date('g:i A', strtotime((string) $message['created_at'])), ENT_QUOTES, 'UTF-8') ?></time>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form class="composer" method="POST" action="messages.php?ticket_id=<?= (int) $selectedTicketId ?>" data-ticket-id="<?= (int) $selectedTicketId ?>">
                    <input type="hidden" name="ticket_id" value="<?= (int) $selectedTicketId ?>">
                    <textarea name="message" rows="1" placeholder="Write a reply to the customer..." maxlength="5000" aria-label="Message" required></textarea>
                    <button class="send-button" type="submit" aria-label="Send reply">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 16 8-16 8 3-8-3-8Zm3 8h13"/></svg>
                    </button>
                </form>
            <?php endif; ?>
        </main>
    </div>
</div>
<script>
    const composer = document.querySelector('.composer');
    const replyInput = composer?.querySelector('textarea');
    const messageList = document.querySelector('.messages');
    if (replyInput && composer && messageList) {
        let lastCustomerMessageId = Array.from(messageList.querySelectorAll('[data-message-sender="customer"]'))
            .reduce((lastId, message) => Math.max(lastId, Number(message.dataset.messageId) || 0), 0);
        let polling = false;
        messageList.scrollTop = messageList.scrollHeight;

        const appendMessage = (message, outgoing) => {
            if (messageList.querySelector(`[data-message-id="${message.id}"]`)) return;
            messageList.querySelector('.empty')?.remove();

            const row = document.createElement('div');
            row.className = `message${outgoing ? ' outgoing' : ''}`;
            row.dataset.messageId = String(message.id);
            row.dataset.messageSender = outgoing ? 'employee' : 'customer';

            const bubble = document.createElement('div');
            bubble.className = 'bubble';
            bubble.textContent = message.text;

            const time = document.createElement('time');
            time.textContent = message.time;
            row.append(bubble, time);
            messageList.append(row);
            if (!outgoing) {
                lastCustomerMessageId = Math.max(lastCustomerMessageId, Number(message.id));
            }
            messageList.scrollTop = messageList.scrollHeight;
        };

        const showError = (message) => {
            let error = document.querySelector('.error');
            if (!error) {
                error = document.createElement('p');
                error.className = 'error';
                composer.before(error);
            }
            error.textContent = message;
        };

        const pollMessages = async () => {
            if (polling || document.hidden) return;
            polling = true;
            try {
                const params = new URLSearchParams({
                    poll: '1',
                    ticket_id: composer.dataset.ticketId,
                    after: String(lastCustomerMessageId)
                });
                const response = await fetch(`messages.php?${params}`, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Unable to check for new messages.');
                result.messages.forEach((message) => appendMessage(message, false));
            } catch (error) {
                console.error('Employee chat polling failed:', error);
            } finally {
                polling = false;
            }
        };

        replyInput.addEventListener('input', () => {
            replyInput.style.height = '43px';
            replyInput.style.height = `${Math.min(replyInput.scrollHeight, 100)}px`;
        });

        composer.addEventListener('submit', async (event) => {
            event.preventDefault();
            const reply = replyInput.value.trim();
            if (!reply) return;

            const sendButton = composer.querySelector('.send-button');
            sendButton.disabled = true;
            try {
                const response = await fetch(composer.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: new FormData(composer)
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Unable to send your reply.');
                appendMessage(result.message, true);
                replyInput.value = '';
                replyInput.style.height = '43px';
                document.querySelector('.error')?.remove();
                pollMessages();
            } catch (error) {
                showError(error.message);
            } finally {
                sendButton.disabled = false;
                replyInput.focus();
            }
        });

        window.setInterval(pollMessages, 3000);
    }
</script>
</body>
</html>
