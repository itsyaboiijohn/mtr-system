<?php
require_once __DIR__ . '/../api/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	set_exception_handler(static function (Throwable $error): void {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(500);
		error_log($error->getMessage());
		echo json_encode(['error' => 'The message could not be send. Please try again.']);
		exit;
	});
}

$database = require_once __DIR__ . '/../api/db_con.php';
require_once __DIR__ . '/../api/customer-assignment.php';
$userId = require_user_auth($_SERVER['REQUEST_METHOD'] === 'POST');

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
$database->exec("ALTER TABLE cancellation_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'refund_sent', 'partially_refunded') NOT NULL DEFAULT 'pending'");

$database->exec("CREATE TABLE IF NOT EXISTS support_messages (
    Message_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Ticket_ID INT UNSIGNED NOT NULL,
    Sender_ID INT UNSIGNED NOT NULL,
    sender_type ENUM('customer', 'admin', 'employee') NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_message_ticket FOREIGN KEY (Ticket_ID) REFERENCES support_tickets(Ticket_ID) ON DELETE CASCADE,
    CONSTRAINT fk_message_sender FOREIGN KEY (Sender_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$database->exec("CREATE TABLE IF NOT EXISTS cancellation_requests (
	Cancellation_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	Booking_ID INT UNSIGNED NOT NULL,
	Payment_ID INT UNSIGNED NULL,
	User_ID INT UNSIGNED NOT NULL,
	Ticket_ID INT UNSIGNED NOT NULL,
	reason TEXT NULL,
	requested_amount DECIMAL(10, 2) NULL,
	status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	reviewed_at TIMESTAMP NULL,
	CONSTRAINT fk_cancellation_booking_service FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
	CONSTRAINT fk_cancellation_payment_service FOREIGN KEY (Payment_ID) REFERENCES payments(Payment_ID) ON DELETE SET NULL,
	CONSTRAINT fk_cancellation_user_service FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
	CONSTRAINT fk_cancellation_ticket_service FOREIGN KEY (Ticket_ID) REFERENCES support_tickets(Ticket_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$messageColumns = $database->query('SHOW COLUMNS FROM support_messages')->fetchAll(PDO::FETCH_COLUMN);
$messageColumnNames = array_map('strtolower', $messageColumns);
if (!in_array('ticket_id', $messageColumnNames, true)) {
	$database->exec("ALTER TABLE support_messages ADD COLUMN Ticket_ID INT UNSIGNED NULL AFTER Message_ID");
	$ticketIndex = $database->query("SHOW INDEX FROM support_messages WHERE Key_name = 'idx_support_messages_ticket'")->fetch();
	if ($ticketIndex) {
		$database->exec("ALTER TABLE support_messages DROP INDEX idx_support_messages_ticket");
	}
	$database->exec("ALTER TABLE support_messages ADD INDEX idx_support_messages_ticket (Ticket_ID)");
}
if (!in_array('sender_type', $messageColumnNames, true)) {
	$database->exec("ALTER TABLE support_messages ADD COLUMN sender_type ENUM('customer', 'admin', 'employee') NOT NULL DEFAULT 'customer' AFTER Sender_ID");
} else {
	$database->exec("ALTER TABLE support_messages MODIFY COLUMN sender_type ENUM('customer', 'admin', 'employee') NOT NULL DEFAULT 'customer'");
}
if (in_array('receiver_id', $messageColumnNames, true)) {
	$database->exec("ALTER TABLE support_messages MODIFY COLUMN Receiver_ID INT UNSIGNED NULL");
}

$ticketStmt = $database->prepare("SELECT Ticket_ID FROM support_tickets WHERE User_ID = ? ORDER BY updated_at DESC, Ticket_ID DESC LIMIT 1");
$assignedEmployeeId = mtrAssignCustomerToEmployee($database, $userId);
$ticketStmt->execute([$userId]);
$ticketId = (int) ($ticketStmt->fetchColumn() ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	header('Content-Type: application/json; charset=utf-8');
	$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
	$messageText = trim((string) ($payload['message'] ?? ''));
	$cancellationBookingId = filter_var($payload['cancellation_booking_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

	if ($messageText === '' || mb_strlen($messageText) > 5000) {
		http_response_code(422);
		echo json_encode(['error' => 'Please enter a message up to 5,000 characters.']);
		exit;
	}

	if (!$ticketId) {
		$createTicket = $database->prepare("INSERT INTO support_tickets (User_ID, subject, status) VALUES (?, 'General Inquiry', 'open')");
		$createTicket->execute([$userId]);
		$ticketId = (int) $database->lastInsertId();
	}

	if (in_array('receiver_id', $messageColumnNames, true)) {
		$receiverId = $assignedEmployeeId ?? (int) $database->query("SELECT User_ID FROM users WHERE role = 'admin' ORDER BY User_ID ASC LIMIT 1")->fetchColumn();
		$insertMessage = $database->prepare("INSERT INTO support_messages (Ticket_ID, Sender_ID, receiver_id, sender_type, message) VALUES (?, ?, ?, 'customer', ?)");
		$insertMessage->execute([$ticketId, $userId, $receiverId > 0 ? $receiverId : null, $messageText]);
	} else {
		$insertMessage = $database->prepare("INSERT INTO support_messages (Ticket_ID, Sender_ID, sender_type, message) VALUES (?, ?, 'customer', ?)");
		$insertMessage->execute([$ticketId, $userId, $messageText]);
	}
	$messageId = (int) $database->lastInsertId();
	if ($cancellationBookingId > 0) {
		$cancellationLookup = $database->prepare("SELECT b.Booking_ID, pay.Payment_ID, pay.amount FROM bookings b LEFT JOIN payments pay ON pay.Payment_ID = (SELECT p2.Payment_ID FROM payments p2 WHERE p2.Booking_ID = b.Booking_ID ORDER BY p2.created_at DESC LIMIT 1) WHERE b.Booking_ID = ? AND b.Customer_ID = ? AND b.payment_status IN ('paid', 'verified') LIMIT 1");
		$cancellationLookup->execute([$cancellationBookingId, $userId]);
		$cancellationRecord = $cancellationLookup->fetch(PDO::FETCH_ASSOC);
		if ($cancellationRecord) {
			$database->prepare('INSERT INTO cancellation_requests (Booking_ID, Payment_ID, User_ID, Ticket_ID, reason, requested_amount) VALUES (?, ?, ?, ?, ?, ?)')->execute([$cancellationRecord['Booking_ID'], $cancellationRecord['Payment_ID'] ?: null, $userId, $ticketId, $messageText, $cancellationRecord['amount']]);
		}
	}
	$database->prepare("UPDATE support_tickets SET status = 'open', updated_at = CURRENT_TIMESTAMP WHERE Ticket_ID = ? AND User_ID = ?")->execute([$ticketId, $userId]);

	echo json_encode(['success' => true, 'message' => [
		'id' => $messageId,
		'text' => $messageText,
		'is_user' => true,
		'created_at' => date(DATE_ATOM)
	]]);
	exit;
}

if (($_GET['poll'] ?? '') === '1') {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	$lastMessageId = filter_input(INPUT_GET, 'after', FILTER_VALIDATE_INT);
	if ($lastMessageId === false || $lastMessageId === null || $lastMessageId < 0) {
		http_response_code(400);
		echo json_encode(['error' => 'Invalid message cursor.']);
		exit;
	}

	$newMessages = [];
	if ($ticketId) {
		$newMessagesStmt = $database->prepare("SELECT Message_ID, message, created_at
			FROM support_messages
			WHERE Ticket_ID = ? AND Message_ID > ? AND sender_type IN ('admin', 'employee')
			ORDER BY Message_ID ASC");
		$newMessagesStmt->execute([$ticketId, $lastMessageId]);
		$newMessages = $newMessagesStmt->fetchAll(PDO::FETCH_ASSOC);
		if ($newMessages) {
			$database->prepare("UPDATE support_messages SET is_read = 1 WHERE Ticket_ID = ? AND sender_type IN ('admin', 'employee') AND Message_ID <= ?")
				->execute([$ticketId, (int) end($newMessages)['Message_ID']]);
		}
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

$messages = [];
if ($ticketId) {
	$messageStmt = $database->prepare('SELECT Message_ID, Sender_ID, sender_type, message, created_at FROM support_messages WHERE Ticket_ID IN (SELECT Ticket_ID FROM support_tickets WHERE User_ID = ?) ORDER BY created_at ASC, Message_ID ASC');
	$messageStmt->execute([$userId]);
	$messages = $messageStmt->fetchAll();
	$database->prepare("UPDATE support_messages SET is_read = 1 WHERE Ticket_ID IN (SELECT Ticket_ID FROM support_tickets WHERE User_ID = ?) AND sender_type IN ('admin', 'employee')")->execute([$userId]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Customer Service | Mindanao Travel & Tours</title>
	<style>
		:root { --bg:#f0faff; --card:#fff; --ink:#0f1724; --muted:#64748b; --line:#dce8ef; --accent:#0ea5e9; --accent-dark:#0369a1; --soft:#e6f9ff; --green:#047857; --shadow:0 18px 45px rgba(2,6,23,.07); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
		* { box-sizing:border-box; }
		body { min-height:100vh; margin:0; background:radial-gradient(circle at 90% 0,#d8f4ff 0,transparent 34%),var(--bg); color:var(--ink); }
		a { color:inherit; text-decoration:none; } button,input { font:inherit; } button { cursor:pointer; }
		.page { width:min(1120px,calc(100% - 40px)); margin:auto; padding:26px 0 55px; }
		.topbar { display:flex; align-items:center; justify-content:space-between; gap:22px; margin-bottom:42px; }.brand { display:flex; align-items:center; gap:12px; }.logo { display:grid; width:44px; height:44px; place-items:center; overflow:hidden; border:1px solid rgba(14,165,233,.14); border-radius:12px; background:#fff; box-shadow:0 8px 20px rgba(2,6,23,.05); }.logo img { width:100%; height:100%; object-fit:contain; }.brand strong,.brand small { display:block; }.brand strong { font-size:15px; }.brand small { margin-top:3px; color:var(--muted); font-size:12px; }.back-link { color:var(--accent-dark); font-size:13px; font-weight:700; }.back-link:hover { text-decoration:underline; }
		.intro { max-width:680px; margin-bottom:25px; }.eyebrow { margin:0 0 8px; color:var(--accent-dark); font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }.intro h1 { margin:0; font-size:clamp(30px,4vw,44px); letter-spacing:-.9px; line-height:1.05; }.intro p { margin:11px 0 0; color:var(--muted); line-height:1.55; }
		.chat-layout { display:grid; grid-template-columns:270px minmax(0,1fr); gap:22px; align-items:stretch; }.card { border:1px solid var(--line); border-radius:14px; background:var(--card); box-shadow:var(--shadow); }
		.support-card { padding:22px; }.support-card h2 { margin:0; font-size:18px; }.support-card > p { margin:7px 0 20px; color:var(--muted); font-size:12px; line-height:1.5; }.agent { display:flex; align-items:center; gap:10px; padding:12px; border-radius:9px; background:#f7fcfe; }.agent-avatar { display:grid; width:40px; height:40px; place-items:center; border-radius:50%; background:linear-gradient(135deg,#0ea5e9,#0369a1); color:#fff; font-size:13px; font-weight:800; }.agent strong,.agent small { display:block; }.agent strong { font-size:12px; }.agent small { margin-top:3px; color:var(--green); font-size:10px; font-weight:700; }.agent small::before { content:""; display:inline-block; width:6px; height:6px; margin-right:5px; border-radius:50%; background:#10b981; }.support-details { display:grid; gap:13px; margin-top:22px; padding-top:18px; border-top:1px solid var(--line); }.detail { display:flex; gap:10px; color:var(--muted); font-size:11px; line-height:1.4; }.detail strong { display:block; color:var(--ink); font-size:12px; }.detail-icon { color:var(--accent-dark); font-size:16px; }
		.chat-card { display:flex; height:620px; max-height:calc(100vh - 40px); min-height:420px; flex-direction:column; overflow:hidden; }.chat-header { display:flex; align-items:center; justify-content:space-between; gap:15px; padding:20px 22px; border-bottom:1px solid var(--line); }.chat-header h2 { margin:0; font-size:17px; }.chat-header p { margin:4px 0 0; color:var(--muted); font-size:11px; }.secure-label { padding:6px 8px; border-radius:5px; background:#ecfdf5; color:var(--green); font-size:10px; font-weight:800; white-space:nowrap; }.messages { display:flex; flex:1; min-height:0; flex-direction:column; gap:13px; padding:22px; overflow-y:auto; overscroll-behavior:contain; background:#fbfeff; }.message { display:flex; max-width:76%; flex-direction:column; align-self:flex-start; }.message.user { align-self:flex-end; align-items:flex-end; }.bubble { overflow-wrap:anywhere; white-space:pre-wrap; padding:11px 13px; border-radius:12px 12px 12px 3px; background:#fff; color:var(--ink); font-size:12px; line-height:1.5; box-shadow:0 4px 14px rgba(2,6,23,.05); }.message.user .bubble { border-radius:12px 12px 3px 12px; background:var(--accent); color:#fff; }.message time { margin-top:4px; color:#94a3b8; font-size:10px; }.typing { display:none; align-items:center; gap:4px; width:max-content; padding:10px 12px; border-radius:12px 12px 12px 3px; background:#fff; box-shadow:0 4px 14px rgba(2,6,23,.05); }.typing.visible { display:flex; }.typing span { width:5px; height:5px; border-radius:50%; background:#94a3b8; animation:bounce 1s infinite ease-in-out; }.typing span:nth-child(2) { animation-delay:.12s; }.typing span:nth-child(3) { animation-delay:.24s; } @keyframes bounce { 0%,60%,100% { transform:translateY(0); } 30% { transform:translateY(-3px); } }
		.quick-replies { display:flex; flex-wrap:wrap; gap:7px; padding:14px 22px 0; }.quick-reply { padding:8px 10px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--accent-dark); font-size:11px; font-weight:700; }.quick-reply:hover { border-color:var(--accent); background:var(--soft); }.composer { display:flex; align-items:flex-end; gap:9px; padding:14px 22px 20px; }.composer textarea { width:100%; min-height:43px; max-height:100px; resize:none; padding:12px; border:1px solid var(--line); border-radius:9px; outline:none; background:#fff; color:var(--ink); font:inherit; font-size:12px; }.composer textarea:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(14,165,233,.12); }.send-button { display:grid; width:43px; height:43px; flex:0 0 43px; place-items:center; border:0; border-radius:9px; background:var(--accent); color:#fff; }.send-button:hover { background:var(--accent-dark); }.send-button svg { width:18px; height:18px; fill:none; stroke:currentColor; stroke-linecap:round; stroke-linejoin:round; stroke-width:2; }
		@media (max-width:760px) { .page { width:min(100% - 28px,560px); padding-top:20px; }.topbar { align-items:flex-start; margin-bottom:35px; }.chat-layout { grid-template-columns:1fr; }.support-card { padding:18px; }.support-details { grid-template-columns:1fr 1fr; }.chat-card { height:570px; } }
		@media (max-width:480px) { .topbar { flex-direction:column; }.topbar,.back-link { width:100%; }.back-link { text-align:right; }.intro h1 { font-size:34px; }.support-details { grid-template-columns:1fr; }.chat-header { align-items:flex-start; flex-direction:column; }.messages { padding:17px; }.quick-replies { padding:12px 17px 0; }.composer { padding:12px 17px 17px; }.message { max-width:88%; } }
	</style>
</head>
<body>
	<main class="page">
		<header class="topbar">
			<a class="brand" href="dashboard.php"><span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span><span><strong>Mindanao Travel & Tours</strong><small>Here when you need us</small></span></a>
			<a class="back-link" href="dashboard.php">← Back to dashboard</a>
		</header>

		<section class="intro"><p class="eyebrow">Customer service</p><h1>Let us help shape your trip.</h1><p>Ask about packages, bookings, destinations, or anything else you need before setting off.</p></section>

		<div class="chat-layout">
			<aside class="card support-card">
				<h2>MTR support team</h2>
				<p>Usually replies within a few minutes during business hours.</p>
				<div class="agent"><div class="agent-avatar">MS</div><div><strong>Mia Santos</strong><small>Available now</small></div></div>
				<div class="support-details">
					<div class="detail"><span class="detail-icon">✉</span><span><strong>Email</strong>bitlgdagret@gmail.com</span></div>
					<div class="detail"><span class="detail-icon">☎</span><span><strong>Phone</strong>+63 917 555 0184</span></div>
					<div class="detail"><span class="detail-icon">⌚</span><span><strong>Hours</strong>Mon-Sat, 8:00 AM-6:00 PM</span></div>
				</div>
			</aside>

			<section class="card chat-card" aria-labelledby="chatTitle">
				<header class="chat-header"><div><h2 id="chatTitle">Chat with MTR support</h2><p>Have a question? Start the conversation below.</p></div><span class="secure-label">Private chat</span></header>
				<div class="messages" id="messages" aria-live="polite">
					<?php foreach ($messages as $message): ?>
						<div class="message <?= $message['sender_type'] === 'customer' ? 'user' : '' ?>" data-message-id="<?= (int) $message['Message_ID'] ?>"><div class="bubble"><?= htmlspecialchars($message['message'], ENT_QUOTES, 'UTF-8') ?></div><time><?= htmlspecialchars(date('g:i A', strtotime($message['created_at'])), ENT_QUOTES, 'UTF-8') ?></time></div>
					<?php endforeach; ?>
					<?php if (!$messages): ?><div class="message"><div class="bubble">Welcome to MTR support. How can we help with your next Mindanao adventure?</div><time>Now</time></div><?php endif; ?>
					<div class="typing" id="typing" aria-label="MTR support is typing"><span></span><span></span><span></span></div>
				</div>
				<div class="quick-replies" aria-label="Suggested questions"><button class="quick-reply" type="button">Check my booking</button><button class="quick-reply" type="button">Change travel dates</button><button class="quick-reply" type="button">Ask about payment</button></div>
				<form class="composer" id="chatForm"><textarea id="messageInput" rows="1" placeholder="Type your message..." aria-label="Message"></textarea><button class="send-button" type="submit" aria-label="Send message"><svg viewBox="0 0 24 24"><path d="m4 4 16 8-16 8 3-8-3-8Zm3 8h13"/></svg></button></form>
			</section>
		</div>
	</main>
	<script>
		(() => {
			const form = document.querySelector('#chatForm');
			const input = document.querySelector('#messageInput');
			const messages = document.querySelector('#messages');
			const typing = document.querySelector('#typing');
			const quickReplies = document.querySelectorAll('.quick-reply');
			let lastMessageId = Array.from(messages.querySelectorAll('[data-message-id]'))
				.reduce((lastId, message) => Math.max(lastId, Number(message.dataset.messageId) || 0), 0);
			const timeLabel = () => new Intl.DateTimeFormat('en-PH', { hour:'numeric', minute:'2-digit' }).format(new Date());
			const addMessage = (text, isUser, id = 0, time = timeLabel()) => {
				const message = document.createElement('div');
				message.className = `message${isUser ? ' user' : ''}`;
				if (id) {
					message.dataset.messageId = String(id);
					lastMessageId = Math.max(lastMessageId, Number(id));
				}
				const bubble = document.createElement('div');
				bubble.className = 'bubble';
				const timestamp = document.createElement('time');
				timestamp.textContent = time;
				message.append(bubble, timestamp);
				bubble.textContent = text;
				messages.insertBefore(message, typing);
				messages.scrollTop = messages.scrollHeight;
			};
			messages.scrollTop = messages.scrollHeight;
			const pollMessages = async () => {
				try {
					const response = await fetch(`service.php?poll=1&after=${lastMessageId}`, {
						headers: { 'Accept':'application/json' },
						cache: 'no-store'
					});
					if (!response.ok) throw new Error('Unable to check for new messages.');
					const result = await response.json();
					result.messages.forEach((message) => {
						addMessage(message.text, false, message.id, message.time);
					});
				} catch (error) {
					console.error('Chat message polling failed:', error);
				}
			};
			const sendMessage = async (text, cancellationBookingId = 0) => {
				const cleanText = text.trim();
				if (!cleanText) return;
				try {
					const response = await fetch('service.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ message:cleanText, cancellation_booking_id: cancellationBookingId || 0 }) });
					const contentType = response.headers.get('content-type') || '';
					if (!contentType.includes('application/json')) throw new Error('The server returned an unexpected response. Please sign in again and retry.');
					const result = await response.json();
					if (!response.ok || !result.success) throw new Error(result.error || 'Unable to send your message.');
					addMessage(result.message.text, true, result.message.id);
					input.value = '';
					input.style.height = '43px';
				} catch (error) {
					addMessage(error.message, false);
				}
			};
			const cancellationParams = new URLSearchParams(window.location.search);
			const cancellationMessage = cancellationParams.get('cancel_message');
			const cancellationBookingId = cancellationParams.get('cancel_booking') || 0;
			if (cancellationMessage) {
				input.value = cancellationMessage;
				sendMessage(cancellationMessage, cancellationBookingId).then(() => window.history.replaceState({}, document.title, 'service.php'));
			}
			form.addEventListener('submit', (event) => { event.preventDefault(); sendMessage(input.value); });
			quickReplies.forEach((button) => button.addEventListener('click', () => sendMessage(button.textContent)));
			input.addEventListener('input', () => { input.style.height = '43px'; input.style.height = `${Math.min(input.scrollHeight, 100)}px`; });
			window.setInterval(pollMessages, 3000);
		})();
	</script>
</body>
</html>
