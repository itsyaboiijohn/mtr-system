<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$database = require_once __DIR__ . '/db_con.php';
$token = strtolower(trim((string) ($_GET['token'] ?? $_POST['token'] ?? '')));
$staffRoles = ['admin', 'employee', 'driver'];
$staffId = in_array($_SESSION['user_role'] ?? '', $staffRoles, true) ? (int) ($_SESSION['user_id'] ?? 0) : 0;
$message = '';
$messageType = 'info';

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    $pass = null;
} else {
    $passStatement = $database->prepare("SELECT b.Booking_ID AS booking_id, b.travel_date, b.trip_duration_days, b.guest_count, b.payment_status, b.booking_status, b.approval_status, COALESCE(NULLIF(b.custom_title, ''), 'Custom travel plan') AS plan_title
        FROM booking_entrance_passes pass
        INNER JOIN bookings b ON b.Booking_ID = pass.Booking_ID
        WHERE pass.token = ? AND b.Travel_Pck_ID IS NULL AND NULLIF(b.custom_title, '') IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM cancellation_requests cr
                WHERE cr.Booking_ID = b.Booking_ID
                    AND cr.status IN ('pending', 'approved', 'refund_sent', 'partially_refunded')
            )
            AND NOT EXISTS (
                SELECT 1 FROM payments p
                WHERE p.Booking_ID = b.Booking_ID
                    AND p.refund_status IN ('partially_refunded', 'refunded')
            )
        LIMIT 1");
    $passStatement->execute([$token]);
    $pass = $passStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pass) {
        http_response_code(404);
    }
}

$destinations = [];
$passState = 'invalid';
$canRedeem = false;
$localTimezone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $localTimezone);
if ($pass) {
    $destinationStatement = $database->prepare("SELECT d.Destination_ID AS id, d.name, d.location, redemption.redeemed_at
        FROM booking_destinations bd
        INNER JOIN destinations d ON d.Destination_ID = bd.Destination_ID
        LEFT JOIN booking_entrance_redemptions redemption
            ON redemption.Booking_ID = bd.Booking_ID AND redemption.Destination_ID = bd.Destination_ID
        WHERE bd.Booking_ID = ?
        ORDER BY bd.sort_order, d.name");
    $destinationStatement->execute([(int) $pass['booking_id']]);
    $destinations = $destinationStatement->fetchAll(PDO::FETCH_ASSOC);

    $tripStart = new DateTimeImmutable((string) $pass['travel_date'], $localTimezone);
    $tripEnd = $tripStart->modify('+' . max(1, (int) $pass['trip_duration_days']) . ' days');
    $paymentConfirmed = in_array($pass['payment_status'], ['paid', 'verified'], true)
        && $pass['booking_status'] === 'confirmed'
        && $pass['approval_status'] === 'approved';

    if (!$destinations || !$paymentConfirmed) {
        $passState = 'invalid';
    } elseif ($today < $tripStart) {
        $passState = 'upcoming';
    } elseif ($today >= $tripEnd) {
        $passState = 'expired';
    } else {
        $passState = 'valid';
        $canRedeem = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pass) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $destinationId = filter_var($_POST['destination_id'] ?? null, FILTER_VALIDATE_INT);
    if ($staffId <= 0) {
        http_response_code(403);
        $message = 'Sign in with an authorized staff or driver account to record entry.';
        $messageType = 'error';
    } elseif (!isset($_SESSION['entrance_pass_csrf']) || !hash_equals($_SESSION['entrance_pass_csrf'], $csrfToken)) {
        http_response_code(403);
        $message = 'This form has expired. Reload the pass and try again.';
        $messageType = 'error';
    } elseif (!$canRedeem || $destinationId === false || $destinationId < 1) {
        http_response_code(400);
        $message = 'This pass cannot be redeemed now or the destination is invalid.';
        $messageType = 'error';
    } else {
        $destinationIsIncluded = false;
        foreach ($destinations as $destination) {
            if ((int) $destination['id'] === $destinationId) {
                $destinationIsIncluded = true;
                break;
            }
        }
        if (!$destinationIsIncluded) {
            http_response_code(400);
            $message = 'That destination is not included on this pass.';
            $messageType = 'error';
        } else {
            $redeemStatement = $database->prepare('INSERT IGNORE INTO booking_entrance_redemptions (Booking_ID, Destination_ID, redeemed_by) VALUES (?, ?, ?)');
            $redeemStatement->execute([(int) $pass['booking_id'], $destinationId, $staffId]);
            $message = $redeemStatement->rowCount() === 1
                ? 'Entry recorded for this destination.'
                : 'Entry was already recorded for this destination.';
            $messageType = $redeemStatement->rowCount() === 1 ? 'success' : 'info';
            $destinationStatement->execute([(int) $pass['booking_id']]);
            $destinations = $destinationStatement->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

if ($staffId > 0 && empty($_SESSION['entrance_pass_csrf'])) {
    $_SESSION['entrance_pass_csrf'] = bin2hex(random_bytes(32));
}
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$passLabels = [
    'valid' => 'Valid for entry',
    'upcoming' => 'Upcoming booking',
    'expired' => 'Pass expired',
    'invalid' => 'Pass not valid'
];
$passClass = $passState === 'valid' ? 'valid' : ($passState === 'upcoming' ? 'upcoming' : 'invalid');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Entrance pass verification | Mindanao Travel Hub</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; background: #f1f6fa; color: #172b4d; font: 15px/1.5 system-ui, sans-serif; }
        main { width: min(640px, 100%); margin: 30px auto; padding: 26px; border: 1px solid #e2eaf0; border-radius: 16px; background: #fff; box-shadow: 0 16px 40px rgba(16,42,67,.09); }
        h1 { margin: 0; font-size: 24px; }
        .brand { margin-bottom: 5px; color: #0369a1; font-size: 11px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
        .status { margin: 18px 0; padding: 12px 14px; border-radius: 9px; font-weight: 800; }
        .status.valid { background: #dcfce7; color: #166534; }
        .status.upcoming { background: #e0f2fe; color: #075985; }
        .status.invalid { background: #fee2e2; color: #991b1b; }
        .ticket-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 18px 0; }
        .ticket-item { min-width: 0; padding: 12px; border: 1px solid #e5edf3; border-radius: 9px; background: #f8fbfe; }
        .ticket-item span { display: block; color: #718096; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .ticket-item strong { display: block; margin-top: 4px; overflow-wrap: anywhere; font-size: 13px; }
        .destination { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 13px 0; border-top: 1px solid #e5edf3; }
        .destination small { display: block; color: #718096; }
        .redeemed { color: #166534; font-size: 12px; font-weight: 800; text-align: right; }
        form { margin: 0; }
        button { min-height: 38px; padding: 0 12px; border: 0; border-radius: 8px; background: #0284c7; color: #fff; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
        button:disabled { background: #94a3b8; cursor: not-allowed; }
        .notice { margin: 14px 0; padding: 11px 12px; border-radius: 8px; background: #e0f2fe; color: #075985; font-size: 13px; }
        .notice.error { background: #fee2e2; color: #991b1b; }
        .notice.success { background: #dcfce7; color: #166534; }
        .fine-print { margin: 18px 0 0; color: #64748b; font-size: 12px; }
        @media (max-width: 520px) { body { padding: 12px; } main { margin: 12px auto; padding: 19px; } .ticket-grid { grid-template-columns: 1fr 1fr; } .destination { grid-template-columns: 1fr; } .redeemed { text-align: left; } }
    </style>
</head>
<body>
<main>
    <p class="brand">Mindanao Travel &amp; Tours</p>
    <h1>Entrance pass</h1>
    <?php if (!$pass): ?>
        <p class="status invalid">Pass not found. Ask the guest to contact Mindanao Travel &amp; Tours.</p>
    <?php else: ?>
        <p class="status <?= $passClass ?>"><?= $escape($passLabels[$passState]) ?></p>
        <div class="ticket-grid">
            <div class="ticket-item"><span>Receipt</span><strong>MTR-<?= (int) $pass['booking_id'] ?></strong></div>
            <div class="ticket-item"><span>Travel date</span><strong><?= $escape(date('M j, Y', strtotime($pass['travel_date']))) ?></strong></div>
            <div class="ticket-item"><span>Plan</span><strong><?= $escape($pass['plan_title']) ?></strong></div>
            <div class="ticket-item"><span>Guests</span><strong><?= (int) $pass['guest_count'] ?> guest(s)</strong></div>
        </div>
        <?php if ($message): ?><p class="notice <?= $escape($messageType) ?>"><?= $escape($message) ?></p><?php endif; ?>
        <?php if (!$destinations): ?>
            <p class="notice error">No destinations are attached to this booking, so there are no entries to validate.</p>
        <?php else: ?>
            <h2>Covered destinations</h2>
            <?php foreach ($destinations as $destination): ?>
                <div class="destination">
                    <div><strong><?= $escape($destination['name']) ?></strong><small><?= $escape($destination['location'] ?: 'Location not provided') ?></small></div>
                    <?php if ($destination['redeemed_at']): ?>
                        <span class="redeemed">Entry recorded · <?= $escape(date('M j, Y g:i A', strtotime($destination['redeemed_at']))) ?></span>
                    <?php elseif ($staffId > 0): ?>
                        <form method="post">
                            <input type="hidden" name="token" value="<?= $escape($token) ?>">
                            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['entrance_pass_csrf']) ?>">
                            <input type="hidden" name="destination_id" value="<?= (int) $destination['id'] ?>">
                            <button type="submit" <?= $canRedeem ? '' : 'disabled' ?>>Record entry</button>
                        </form>
                    <?php else: ?>
                        <span class="redeemed">Awaiting entry</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <p class="fine-print">This digital pass is verifiable through Mindanao Travel &amp; Tours. Destination staff must have internet access to check it. Food and optional activities are not included.</p>
        <?php if ($staffId <= 0): ?><p class="fine-print">Authorized staff can <a href="../user/login.php">sign in</a> to record entries.</p><?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
