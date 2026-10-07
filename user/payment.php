<?php
require_once __DIR__ . '/../api/auth.php';
$userId = require_user_auth($_SERVER['REQUEST_METHOD'] === 'POST');
$database = require_once __DIR__ . '/../api/db_con.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_booking') {
	$cancelBookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
	$redirectPath = 'booking.php';
	if ($cancelBookingId) {
		$bookingOwnerStatement = $database->prepare("SELECT Booking_ID, Travel_Pck_ID, custom_title, custom_details FROM bookings WHERE Booking_ID = ? AND Customer_ID = ? AND payment_status NOT IN ('paid', 'verified')");
		$bookingOwnerStatement->execute([$cancelBookingId, $userId]);
		$cancelledBooking = $bookingOwnerStatement->fetch(PDO::FETCH_ASSOC);
		if ($cancelledBooking) {
			$isDirectPackageBooking = !empty($cancelledBooking['Travel_Pck_ID'])
				&& trim((string) ($cancelledBooking['custom_title'] ?? '')) === ''
				&& trim((string) ($cancelledBooking['custom_details'] ?? '')) === '';
			if ($isDirectPackageBooking) {
				$redirectPath = 'package.php?id=' . (int) $cancelledBooking['Travel_Pck_ID'];
			}
			$database->beginTransaction();
			$database->prepare('DELETE FROM payments WHERE Booking_ID = ?')->execute([$cancelBookingId]);
			$database->prepare('DELETE FROM booking_assignments WHERE Booking_ID = ?')->execute([$cancelBookingId]);
			$database->prepare('DELETE FROM bookings WHERE Booking_ID = ?')->execute([$cancelBookingId]);
			$database->commit();
		}
	}
	header('Location: ' . $redirectPath);
	exit;
}

$bookingColumns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('approval_status', $bookingColumns, true)) {
	$database->exec("ALTER TABLE bookings ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER booking_status");
}
if (!in_array('approval_note', $bookingColumns, true)) {
	$database->exec('ALTER TABLE bookings ADD COLUMN approval_note TEXT NULL AFTER approval_status');
}

$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
$extensionId = filter_input(INPUT_GET, 'extension_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'extension_id', FILTER_VALIDATE_INT);
$approvedBooking = null;
$extensionRequest = null;
if ($bookingId) {
	$bookingLookup = $database->prepare("SELECT b.*, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Custom Travel Plan') AS package_name FROM bookings b LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID WHERE b.Booking_ID = ? AND b.Customer_ID = ? AND b.approval_status = 'approved' LIMIT 1");
	$bookingLookup->execute([$bookingId, $userId]);
	$approvedBooking = $bookingLookup->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($extensionId) {
	$extensionLookup = $database->prepare("SELECT e.*, b.Booking_ID, b.Customer_ID, b.guest_count, b.payment_status AS booking_payment_status, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan') AS package_name FROM booking_extension_requests e INNER JOIN bookings b ON b.Booking_ID = e.Booking_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID WHERE e.Extension_ID = ? AND e.User_ID = ? AND e.status = 'approved' LIMIT 1");
	$extensionLookup->execute([$extensionId, $userId]);
	$extensionRequest = $extensionLookup->fetch(PDO::FETCH_ASSOC) ?: null;
	$bookingId = $extensionRequest['Booking_ID'] ?? $bookingId;
}

// 1. Ensure tables exist
$database->exec("CREATE TABLE IF NOT EXISTS payment_methods (
    Payment_Method_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    method_type ENUM('gcash', 'bank', 'card', 'other') NOT NULL DEFAULT 'gcash',
    bank_name VARCHAR(120) NULL,
    account_name VARCHAR(160) NOT NULL,
    account_number VARCHAR(120) NOT NULL,
    qr_code_image VARCHAR(500) NULL,
    instructions TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$database->exec("CREATE TABLE IF NOT EXISTS payments (
    Payment_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Booking_ID INT UNSIGNED NULL,
    User_ID INT UNSIGNED NULL,
    session_id VARCHAR(190) NULL,
    method_type VARCHAR(50) NOT NULL,
    customer_name VARCHAR(160) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    reference_number VARCHAR(120) NULL,
    receipt_file VARCHAR(500) NULL,
    status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_user FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE SET NULL,
    CONSTRAINT fk_payment_booking FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$paymentColumns = $database->query('SHOW COLUMNS FROM payments')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('Extension_ID', $paymentColumns, true)) {
	$database->exec("ALTER TABLE payments ADD COLUMN Extension_ID INT UNSIGNED NULL AFTER Booking_ID");
}
if (!in_array('Payment_Method_ID', $paymentColumns, true)) {
	$database->exec("ALTER TABLE payments ADD COLUMN Payment_Method_ID INT UNSIGNED NULL AFTER Extension_ID");
}
try {
	$database->exec("ALTER TABLE payments ADD CONSTRAINT fk_payment_method FOREIGN KEY (Payment_Method_ID) REFERENCES payment_methods(Payment_Method_ID) ON DELETE SET NULL");
} catch (Throwable $error) {
	// The foreign key already exists on databases initialized from the updated schema.
}

// Seed default payment methods if empty
$pmCount = (int) $database->query("SELECT COUNT(*) FROM payment_methods")->fetchColumn();
if ($pmCount === 0) {
    $seedMethods = [
        ['gcash', 'GCash', 'Mindanao Travel & Tours Admin', '0917-555-0184', '../assets/logo/logo.png', 'Scan the QR code or send payment directly to the GCash number above.', 'active'],
        ['bank', 'BDO Unibank', 'Mindanao Travel & Tours Corp.', '0012-3456-7890', '../assets/logo/logo.png', 'Transfer the exact amount to the BDO bank account details above.', 'active']
    ];
    $insertPm = $database->prepare("INSERT INTO payment_methods (method_type, bank_name, account_name, account_number, qr_code_image, instructions, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($seedMethods as $m) {
        $insertPm->execute($m);
    }
}

$sessionId = session_id();

// 2. Query cart items to compute total amount and summary
$cartStmt = $database->prepare("
    SELECT Cart_Item_ID AS id, item_type, Package_ID, Hotel_ID, title, details, image_url, travelers, unit_price, total_price
    FROM cart_items
    WHERE (User_ID IS NOT NULL AND User_ID = ?) OR (session_id IS NOT NULL AND session_id = ?)
    ORDER BY created_at DESC
");
$cartStmt->execute([$userId, $sessionId]);
$cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = 0;
$totalTravelers = 0;
foreach ($cartItems as $c) {
    $grandTotal += (float) $c['total_price'];
    $totalTravelers += (int) $c['travelers'];
}
if ($grandTotal <= 0) {
    $grandTotal = 25000.00;
}
if ($totalTravelers <= 0) {
    $totalTravelers = 1;
}

if ($approvedBooking) {
	$grandTotal = (float) $approvedBooking['total_amount'];
	$totalTravelers = (int) $approvedBooking['guest_count'];
	$summaryTitle = $approvedBooking['package_name'];
}

$summaryTitle = count($cartItems) > 0
    ? (count($cartItems) === 1 ? $cartItems[0]['title'] : count($cartItems) . ' Custom Trips')
    : 'Mindanao Travel Escape';
if ($extensionRequest) {
	$grandTotal = (float) ($extensionRequest['approved_amount'] ?? 0);
	$totalTravelers = (int) $extensionRequest['guest_count'];
	$summaryTitle = 'Extension · ' . $extensionRequest['package_name'];
}
$paymentBookingId = (int) ($extensionRequest['Booking_ID'] ?? $approvedBooking['Booking_ID'] ?? $bookingId);
$accommodationSummary = 'No overnight stay';
if ($approvedBooking) {
	$accommodationSummary = trim((string) ($approvedBooking['accommodation_name'] ?? ''));
	if ($accommodationSummary === '' && !empty($approvedBooking['Hotel_ID'])) {
		$accommodationSummary = 'Selected hotel';
	}
	if (!empty($approvedBooking['accommodation_address'])) {
		$accommodationSummary .= ($accommodationSummary !== '' ? ' · ' : '') . trim((string) $approvedBooking['accommodation_address']);
	}
	$accommodationSummary = $accommodationSummary !== '' ? $accommodationSummary : 'No overnight stay';
}

$accountStatement = $database->prepare('SELECT full_name, email FROM users WHERE User_ID = ? LIMIT 1');
$accountStatement->execute([$userId]);
$account = $accountStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$accountFullName = trim((string) ($account['full_name'] ?? ''));
$accountEmail = strtolower(trim((string) ($account['email'] ?? '')));

// 3. Query active payment methods configured by admin
$activeMethodsStmt = $database->query("SELECT * FROM payment_methods WHERE status = 'active' ORDER BY Payment_Method_ID ASC");
$activeMethods = $activeMethodsStmt ? $activeMethodsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// 4. Handle POST form submission (Upload Receipt & Submit Payment)
$submittedSuccess = false;
$submittedData = [];
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$fullName = trim($_POST['fullName'] ?? '') ?: $accountFullName;
    $selectedMethod = trim($_POST['selectedMethod'] ?? 'gcash');
	$selectedMethodId = filter_input(INPUT_POST, 'selectedMethodId', FILTER_VALIDATE_INT) ?: 0;
    $refNumber = trim($_POST['referenceNumber'] ?? '');

    $receiptFile = $_FILES['receipt'] ?? null;
    $receiptPath = null;

    if ($receiptFile && $receiptFile['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../data/uploads/receipts/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $ext = pathinfo($receiptFile['name'], PATHINFO_EXTENSION) ?: 'png';
        $filename = 'receipt_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($receiptFile['tmp_name'], $targetPath)) {
            $receiptPath = '../data/uploads/receipts/' . $filename;
        }
    }

	if (!$approvedBooking && !$extensionRequest) {
		$errorMessage = 'This plan is not approved for payment yet.';
	} else if ($approvedBooking && !$extensionRequest && $approvedBooking['payment_status'] === 'paid') {
		$errorMessage = 'This booking has already been paid.';
	} else if ($extensionRequest && $extensionRequest['status'] !== 'approved') {
		$errorMessage = 'This extension is not approved for payment yet.';
	} else if (!$fullName) {
		$errorMessage = 'Please provide your full name.';
    } else if (!$receiptPath) {
        $errorMessage = 'Please upload a photo or screenshot of your payment receipt.';
    } else {
		$selectedMethodStatement = $database->prepare("SELECT Payment_Method_ID, method_type FROM payment_methods WHERE Payment_Method_ID = ? AND status = 'active' LIMIT 1");
		$selectedMethodStatement->execute([$selectedMethodId]);
		$selectedMethodRecord = $selectedMethodStatement->fetch(PDO::FETCH_ASSOC);
		if (!$selectedMethodRecord) {
			$errorMessage = 'Please select an active payment method.';
		} else {
			$selectedMethod = $selectedMethodRecord['method_type'];
        $_SESSION['user_name'] = $fullName;
			$_SESSION['user_email'] = $accountEmail;

		$payStmt = $database->prepare("INSERT INTO payments (Booking_ID, Extension_ID, Payment_Method_ID, User_ID, session_id, method_type, customer_name, amount, reference_number, receipt_file, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
		$payStmt->execute([$bookingId, $extensionId ?: null, (int) $selectedMethodRecord['Payment_Method_ID'], $userId, $sessionId, strtoupper($selectedMethod), $fullName, $grandTotal, $refNumber, $receiptPath]);

        $submittedSuccess = true;
        $submittedData = [
			'booking_id' => (int) $bookingId,
            'name' => $fullName,
            'method' => strtoupper($selectedMethod),
            'amount' => $grandTotal,
            'ref' => $refNumber ?: 'N/A',
            'receipt' => $receiptPath,
            'title' => $summaryTitle,
			'status' => 'pending'
        ];
		}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Payment | Mindanao Travel & Tours</title>
	<style>
		:root {
			--bg: #f0faff;
			--card: #fff;
			--primary: #0f1724;
			--muted: #6b7280;
			--accent: #0ea5e9;
			--accent-dark: #0369a1;
			--soft-accent: #e6f9ff;
			--line: rgba(15, 23, 42, 0.09);
			--shadow: 0 18px 45px rgba(2, 6, 23, 0.07);
			font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
		}

		* { box-sizing: border-box; }
		body { min-height: 100vh; margin: 0; background: var(--bg); color: var(--primary); }
		a { color: inherit; }
		.page { width: min(1040px, calc(100% - 40px)); margin: 0 auto; padding: 28px 0 52px; }

		.site-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 52px; }
		.brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
		.logo { display: grid; width: 44px; height: 44px; place-items: center; overflow: hidden; border: 1px solid rgba(14, 165, 233, .12); border-radius: 10px; background: #fff; box-shadow: 0 8px 20px rgba(2, 6, 23, .05); }
		.logo img { width: 100%; height: 100%; object-fit: contain; }
		.brand strong { display: block; font-size: 15px; }
		.brand span { color: var(--muted); font-size: 12px; }
		.back-link { display: inline-flex; align-items: center; gap: 7px; color: var(--accent-dark); font-size: 13px; font-weight: 700; text-decoration: none; }
		.back-form { margin: 0; }
		.back-form .back-link { padding: 0; border: 0; background: transparent; font: inherit; cursor: pointer; }
		.back-link:hover { color: var(--accent); }

		.intro { margin-bottom: 25px; }
		.eyebrow { margin: 0 0 8px; color: var(--accent-dark); font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
		h1 { margin: 0; font-size: clamp(30px, 4vw, 42px); letter-spacing: -.8px; }
		.intro p { margin: 10px 0 0; color: var(--muted); }

		.checkout-layout { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(280px, .75fr); gap: 24px; align-items: start; }
		.card { border: 1px solid var(--line); border-radius: 14px; background: var(--card); box-shadow: var(--shadow); }
		.payment-card { padding: 24px; }
		.payment-card h2, .summary-card h2 { margin: 0 0 20px; font-size: 19px; }
		.method-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 22px; }
		.method-tab { flex: 1; min-width: 100px; padding: 11px 10px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--muted); font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; text-align: center; }
		.method-tab:hover, .method-tab.active { border-color: var(--accent); background: var(--soft-accent); color: var(--accent-dark); }

		.admin-details-box { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; padding: 16px; border: 1px solid rgba(14, 165, 233, 0.2); border-radius: 12px; background: #f8fdff; margin-bottom: 20px; }
		.admin-info { flex: 1; min-width: 200px; }
		.admin-info .info-row { margin-bottom: 8px; font-size: 13px; }
		.admin-info .info-row span { color: var(--muted); font-size: 12px; display: block; margin-bottom: 2px; }
		.admin-info .info-row strong { color: var(--primary); font-size: 14px; }
		.highlight-num { color: var(--accent-dark) !important; font-size: 16px !important; letter-spacing: 0.5px; }

		.qr-code-box { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 10px; border: 1px dashed var(--accent); border-radius: 10px; background: #fff; text-align: center; }
		.qr-code-box img { width: 140px; height: 140px; object-fit: contain; border-radius: 6px; }
		.qr-code-box small { margin-top: 6px; color: var(--muted); font-size: 10px; font-weight: 700; }

		.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
		.field { display: grid; gap: 7px; }
		.field.full { grid-column: 1 / -1; }
		label { font-size: 12px; font-weight: 700; }
		input { width: 100%; min-height: 42px; padding: 10px 11px; border: 1px solid var(--line); border-radius: 8px; outline: none; background: #fff; color: var(--primary); font: inherit; font-size: 13px; }
		input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(14, 165, 233, .12); }
		.field-hint { margin: -2px 0 0; color: var(--muted); font-size: 11px; }

		.pay-button { display: inline-flex; width: 100%; min-height: 46px; align-items: center; justify-content: center; margin-top: 24px; border: 0; border-radius: 9px; background: var(--accent); color: #fff; font: inherit; font-weight: 800; cursor: pointer; }
		.pay-button:hover { background: var(--accent-dark); }
		.secure-note { display: flex; align-items: center; justify-content: center; gap: 6px; margin: 13px 0 0; color: var(--muted); font-size: 12px; text-align: center; }

		.summary-card { overflow: hidden; }
		.summary-image { height: 140px; background: url('../assets/Spots/Screenshot%202026-09-05%20182549.png') center / cover; }
		.summary-content { padding: 21px; }
		.summary-content h2 { margin-bottom: 5px; }
		.summary-subtitle { margin: 0 0 18px; color: var(--muted); font-size: 13px; }
		.summary-row { display: flex; justify-content: space-between; gap: 14px; margin: 13px 0; color: var(--muted); font-size: 13px; }
		.summary-row strong { color: var(--primary); }
		.total-row { display: flex; justify-content: space-between; gap: 14px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--line); font-size: 17px; font-weight: 800; }

		.status-badge-pending { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; background: #fef3c7; color: #b45309; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }

		.confirmation-card { padding: 28px 24px; text-align: center; }
		.confirmation-card h2 { margin: 14px 0 8px; font-size: 22px; }
		.confirmation-card p { margin: 0 0 20px; color: var(--muted); font-size: 13px; line-height: 1.6; }
		.receipt-preview-box { margin: 16px 0; padding: 12px; border: 1px dashed var(--line); border-radius: 10px; background: #fafafa; }
		.receipt-preview-box img { max-width: 100%; max-height: 240px; object-fit: contain; border-radius: 6px; border: 1px solid var(--line); }

		.alert-error { padding: 12px; border-radius: 8px; background: #fee2e2; color: #dc2626; font-size: 13px; font-weight: 600; margin-bottom: 18px; }

		@media (max-width: 720px) {
			.page { width: min(100% - 28px, 560px); padding-top: 20px; }
			.site-header { margin-bottom: 38px; }
			.checkout-layout { grid-template-columns: 1fr; }
		}

		@media (max-width: 460px) {
			.form-grid { grid-template-columns: 1fr; }
			.field.full { grid-column: auto; }
			.method-tabs { grid-template-columns: 1fr; }
			.admin-details-box { flex-direction: column; align-items: stretch; }
			.qr-code-box img { width: 100%; height: auto; }
		}
	</style>
</head>
<body>
	<main class="page">
		<header class="site-header">
			<a class="brand" href="dashboard.php">
				<span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span>
				<span><strong>Mindanao Travel & Tours</strong><span>Secure checkout</span></span>
			</a>
			<?php if (!$submittedSuccess): ?>
			<form class="back-form" method="POST">
				<input type="hidden" name="action" value="cancel_booking">
				<input type="hidden" name="booking_id" value="<?= (int) $bookingId ?>">
				<button class="back-link" type="submit">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
				<?= ($approvedBooking && !trim((string) ($approvedBooking['custom_title'] ?? '')) && !trim((string) ($approvedBooking['custom_details'] ?? '')) && !empty($approvedBooking['Travel_Pck_ID'])) ? 'Cancel and change trip' : 'Cancel' ?>
				</button>
			</form>
			<?php endif; ?>
		</header>

		<?php if ($submittedSuccess): ?>
			<section class="intro">
				<p class="eyebrow">Booking Submitted</p>
				<h1>Payment Pending Verification</h1>
			</section>

			<div class="checkout-layout">
				<section class="card confirmation-card">
					<div><span class="status-badge-pending">⏳ Status: Pending</span></div>
					<h2>Thank you, <?= htmlspecialchars($submittedData['name']) ?>!</h2>
					<p>Your payment receipt has been successfully uploaded and sent to our admin team for verification. We will contact you through your registered account email once approved.</p>

					<?php if ($submittedData['receipt']): ?>
						<div class="receipt-preview-box">
							<p style="margin:0 0 8px;font-size:12px;font-weight:700;color:var(--muted)">Uploaded Proof of Payment:</p>
							<img src="<?= htmlspecialchars($submittedData['receipt']) ?>" alt="Receipt Proof">
						</div>
					<?php endif; ?>

					<div style="margin-top:20px">
						<a href="dashboard.php" class="pay-button" style="text-decoration:none">Return to Dashboard</a>
					</div>
				</section>

				<aside class="card summary-card">
					<div class="summary-image"></div>
					<div class="summary-content">
						<h2>Summary</h2>
						<p class="summary-subtitle"><?= htmlspecialchars($submittedData['title']) ?></p>
						<div class="summary-row"><span>Payment Method</span><strong><?= htmlspecialchars($submittedData['method']) ?></strong></div>
						<div class="summary-row"><span>Reference No.</span><strong><?= htmlspecialchars($submittedData['ref']) ?></strong></div>
						<div class="total-row"><span>Total Amount</span><span>₱ <?= number_format($submittedData['amount'], 2) ?></span></div>
					</div>
				</aside>
			</div>

		<?php elseif (!$approvedBooking && !$extensionRequest): ?>
			<section class="intro">
				<p class="eyebrow">Admin review required</p>
				<h1>Payment is not available yet.</h1>
				<p>Your customized itinerary must be approved by the admin before payment can be submitted.</p>
			</section>
			<div class="checkout-layout">
				<section class="card confirmation-card">
					<span class="status-badge-pending">Status: Pending review</span>
					<h2>Plan submitted for review</h2>
					<p>Return to your bookings to see the admin decision. Your plan will remain there if changes are requested.</p>
					<a href="booking.php" class="pay-button" style="text-decoration:none">View my bookings</a>
				</section>
			</div>

		<?php else: ?>

			<section class="intro" aria-labelledby="paymentTitle">
				<p class="eyebrow">Almost there</p>
				<h1 id="paymentTitle">Complete your booking</h1>
				<p>Select your payment method, transfer the amount to the admin account below, and upload your receipt.</p>
			</section>

			<?php if ($errorMessage): ?>
				<div class="alert-error"><?= htmlspecialchars($errorMessage) ?></div>
			<?php endif; ?>

			<div class="checkout-layout">
				<section class="card payment-card" aria-labelledby="paymentDetailsTitle">
					<h2 id="paymentDetailsTitle">Select Payment Method</h2>

					<?php if ($activeMethods): ?>
						<div class="method-tabs" role="tablist">
							<?php foreach ($activeMethods as $idx => $m): ?>
								<button class="method-tab <?= $idx === 0 ? 'active' : '' ?>" type="button" data-method-id="<?= $m['Payment_Method_ID'] ?>" data-method-type="<?= htmlspecialchars($m['method_type']) ?>">
									<?= htmlspecialchars(strtoupper($m['bank_name'] ?: $m['method_type'])) ?>
								</button>
							<?php endforeach; ?>
						</div>

						<?php foreach ($activeMethods as $idx => $m): ?>
							<div class="admin-details-box pm-panel" id="pm-panel-<?= $m['Payment_Method_ID'] ?>" <?= $idx > 0 ? 'style="display:none"' : '' ?>>
								<div class="admin-info">
									<?php if ($m['bank_name']): ?>
										<div class="info-row"><span>Bank / Wallet Provider:</span> <strong><?= htmlspecialchars($m['bank_name']) ?></strong></div>
									<?php endif; ?>
									<div class="info-row"><span>Account Name:</span> <strong><?= htmlspecialchars($m['account_name']) ?></strong></div>
									<div class="info-row"><span>Account Number / Phone:</span> <strong class="highlight-num"><?= htmlspecialchars($m['account_number']) ?></strong></div>
									<?php if ($m['instructions']): ?>
										<div class="info-row" style="margin-top:10px"><span>Instructions:</span> <p style="margin:2px 0 0;color:var(--muted);font-size:12px"><?= htmlspecialchars($m['instructions']) ?></p></div>
									<?php endif; ?>
								</div>
								<?php if ($m['qr_code_image']): ?>
									<div class="qr-code-box">
										<img src="<?= htmlspecialchars($m['qr_code_image']) ?>" alt="QR Code">
										<small>Scan QR to Pay</small>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<p class="field-hint" style="margin-bottom:18px">Direct bank transfer details available upon confirmation.</p>
					<?php endif; ?>

					<form id="paymentForm" method="POST" enctype="multipart/form-data">
						<input type="hidden" name="booking_id" value="<?= $paymentBookingId ?>">
						<?php if ($extensionRequest): ?><input type="hidden" name="extension_id" value="<?= (int) $extensionRequest['Extension_ID'] ?>">
						<?php endif; ?>
						<input type="hidden" name="selectedMethod" id="selectedMethodInput" value="<?= $activeMethods ? htmlspecialchars($activeMethods[0]['method_type']) : 'gcash' ?>">
						<input type="hidden" name="selectedMethodId" id="selectedMethodIdInput" value="<?= $activeMethods ? (int) $activeMethods[0]['Payment_Method_ID'] : 0 ?>">

						<div class="form-grid">
							<div class="field full">
								<label for="fullName">Full name <span style="color:#dc2626">*</span></label>
								<input id="fullName" name="fullName" type="text" placeholder="e.g. Ana Santos" required>
							</div>
							<div class="field full">
							</div>
							<div class="field full">
								<label for="referenceNumber">Reference / Transaction Number</label>
								<input id="referenceNumber" name="referenceNumber" type="text" placeholder="e.g. 100293848123">
								<p class="field-hint">Provide the reference number from your payment confirmation.</p>
							</div>
							<div class="field full">
								<label for="receipt">Upload Payment Receipt / Proof <span style="color:#dc2626">*</span></label>
								<input id="receipt" name="receipt" type="file" accept="image/jpeg,image/png,image/webp" required>
								<p class="field-hint">Upload a clear photo or screenshot of your transfer receipt.</p>
							</div>
						</div>

						<button class="pay-button" type="submit">Submit Payment Receipt · ₱ <?= number_format($grandTotal, 2) ?></button>
						<p class="secure-note">Your approved plan and payment proof will be submitted directly to the admin.</p>
					</form>
				</section>

				<aside class="card summary-card" aria-labelledby="summaryTitle">
					<div class="summary-image" role="img" aria-label="Mindanao travel destination"></div>
					<div class="summary-content">
						<h2 id="summaryTitle">Booking summary</h2>
						<p class="summary-subtitle"><?= htmlspecialchars($summaryTitle) ?></p>
						<div class="summary-row"><span>Travelers</span><strong><?= $totalTravelers ?> guest<?= $totalTravelers === 1 ? '' : 's' ?></strong></div>
						<div class="summary-row"><span>Accommodation</span><strong><?= htmlspecialchars($accommodationSummary, ENT_QUOTES, 'UTF-8') ?></strong></div>
						<p class="summary-subtitle">Accommodation is arranged and paid for by the tourist. It is not included in this payment.</p>
						<div class="total-row"><span>Total Amount</span><span>₱ <?= number_format($grandTotal, 2) ?></span></div>
					</div>
				</aside>
			</div>

		<?php endif; ?>
	</main>

	<script>
		(() => {
			const tabs = document.querySelectorAll('.method-tab');
			const panels = document.querySelectorAll('.pm-panel');
			const methodInput = document.querySelector('#selectedMethodInput');
			const methodIdInput = document.querySelector('#selectedMethodIdInput');

			tabs.forEach((tab) => {
				tab.addEventListener('click', () => {
					const pmId = tab.dataset.methodId;
					const mType = tab.dataset.methodType;

					tabs.forEach((item) => item.classList.toggle('active', item === tab));
					panels.forEach((panel) => {
						panel.style.display = panel.id === `pm-panel-${pmId}` ? 'flex' : 'none';
					});

					if (methodInput) {
						methodInput.value = mType;
					}
					if (methodIdInput) {
						methodIdInput.value = pmId;
					}
				});
			});
		})();
	</script>
</body>
</html>
