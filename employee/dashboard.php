<?php
require_once __DIR__ . '/../api/auth.php';
$userId = require_user_auth();
if (($_SESSION['user_role'] ?? '') !== 'employee') {
    http_response_code(403);
    exit('Employee access only.');
}
$database = require_once __DIR__ . '/../api/db_con.php';
require_once __DIR__ . '/../api/customer-assignment.php';

$getUnreadSupportCount = static function (PDO $database, int $employeeId): int {
    $tables = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('support_tickets', $tables, true) || !in_array('support_messages', $tables, true)) {
        return 0;
    }

    $unreadCount = $database->prepare("SELECT COUNT(*)
        FROM support_messages sm
        INNER JOIN support_tickets t ON t.Ticket_ID = sm.Ticket_ID
        INNER JOIN customer_employee_assignments a ON a.Customer_ID = t.User_ID
        WHERE a.Employee_ID = ? AND sm.sender_type = 'customer' AND sm.is_read = 0");
    $unreadCount->execute([$employeeId]);
    return (int) $unreadCount->fetchColumn();
};

if (($_GET['support_unread'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['count' => $getUnreadSupportCount($database, $userId)]);
    exit;
}

$unreadSupportCount = $getUnreadSupportCount($database, $userId);
$employeeName = $_SESSION['user_name'] ?? 'Employee';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Operations | Mindanao Travel Hub</title>
    <style>
        :root { --navy:#102a43; --blue:#0ea5e9; --blue-dark:#0369a1; --bg:#f3f8fb; --card:#fff; --text:#172b4d; --muted:#718096; --line:#e5edf3; --green:#15803d; --red:#b91c1c; --orange:#c2410c; font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        * { box-sizing:border-box; } body { margin:0; background:var(--bg); color:var(--text); } button,select { font:inherit; cursor:pointer; }
        .layout { min-height:100vh; display:grid; grid-template-columns:250px minmax(0,1fr); }.sidebar { position:sticky; top:0; height:100vh; overflow-y:auto; scrollbar-width:none; -ms-overflow-style:none; padding:24px 16px; background:var(--navy); color:#dbeafe; overscroll-behavior:contain; }.sidebar::-webkit-scrollbar { display:none; }
        .brand { display:flex; align-items:center; gap:10px; padding:0 10px 28px; }.brand img { width:42px; height:42px; padding:5px; border-radius:10px; background:#fff; object-fit:contain; }.brand strong,.brand span { display:block; }.brand strong { color:#fff; font-size:15px; }.brand span { color:#9fb7cc; font-size:11px; }.nav-label { margin:18px 10px 8px; color:#8aa5bd; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }.nav-link { display:flex; align-items:center; gap:11px; margin:4px 0; padding:11px 12px; border-radius:9px; color:#c7d8e7; font-size:14px; text-decoration:none; }.nav-link.active,.nav-link:hover { background:rgba(14,165,233,.2); color:#fff; }.nav-link-label { flex:1; }.nav-badge { display:inline-flex; align-items:center; justify-content:center; min-width:19px; height:19px; padding:0 6px; border-radius:999px; background:#ef4444; color:#fff; font-size:11px; font-weight:800; line-height:1; box-shadow:0 0 0 2px var(--navy); }.nav-badge[hidden] { display:none; }.staff { margin-top:auto; padding:14px 10px 0; border-top:1px solid rgba(255,255,255,.12); }.staff small { color:#9fb7cc; }
        .content { min-width:0; padding:28px clamp(18px,4vw,48px); }.topbar { display:flex; justify-content:space-between; align-items:center; gap:20px; margin-bottom:28px; }.topbar h1 { margin:0 0 5px; font-size:26px; }.topbar p { margin:0; color:var(--muted); font-size:14px; }        .panel { margin-bottom:22px; padding:20px; border:1px solid var(--line); border-radius:13px; background:var(--card); box-shadow:0 12px 30px rgba(16,42,67,.07); }.panel-heading { display:flex; justify-content:space-between; gap:18px; align-items:center; margin-bottom:16px; }.panel-heading h2 { margin:0 0 4px; font-size:18px; }.panel-heading p { margin:0; color:var(--muted); font-size:13px; }.support-card { position:relative; overflow:hidden; background:linear-gradient(135deg, #f0faff 0%, #ffffff 35%, #f5fbff 100%); border-color:#d9edf9; }.support-card::before { content:""; position:absolute; inset:0 auto 0 -10%; width:240px; background:radial-gradient(circle, rgba(14,165,233,.12), transparent 70%); pointer-events:none; }.support-header { position:relative; z-index:1; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }.support-brand { display:flex; align-items:center; gap:14px; }.support-icon { display:grid; place-items:center; width:52px; height:52px; border-radius:14px; background:linear-gradient(135deg, #0ea5e9, #0369a1); color:#fff; font-size:22px; box-shadow:0 12px 25px rgba(14,165,233,.25); }.support-copy { min-width:0; }.support-copy h2 { margin:0; font-size:20px; }.support-copy p { margin:6px 0 0; color:var(--muted); font-size:13px; line-height:1.5; }.support-badges { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }.support-badge { display:inline-flex; align-items:center; gap:6px; padding:8px 10px; border:1px solid rgba(14,165,233,.18); border-radius:999px; background:rgba(14,165,233,.06); color:var(--blue-dark); font-size:11px; font-weight:800; letter-spacing:.02em; }.support-metrics { position:relative; z-index:1; display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; margin-top:18px; }.support-metric { padding:14px 14px 12px; border:1px solid var(--line); border-radius:11px; background:rgba(255,255,255,.72); backdrop-filter: blur(2px); }.support-metric-label { display:block; color:var(--muted); font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }.support-metric-value { display:block; margin-top:8px; font-size:24px; font-weight:800; color:var(--navy); }.support-metric small { display:block; margin-top:4px; color:var(--muted); font-size:11px; }.table-wrap { overflow-x:auto; } table { width:100%; min-width:720px; border-collapse:collapse; } th,td { padding:12px 10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:middle; font-size:13px; } th { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.05em; }.status { display:inline-block; padding:5px 8px; border-radius:999px; background:#fff7ed; color:var(--orange); font-size:11px; font-weight:800; }.status.approved,.status.paid { background:#dcfce7; color:var(--green); }.status.rejected { background:#fee2e2; color:var(--red); }.action-group { display:flex; flex-wrap:wrap; gap:7px; align-items:center; }.btn { min-height:34px; padding:0 10px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--blue-dark); font-size:12px; font-weight:800; }.btn.primary { border-color:var(--blue); background:var(--blue); color:#fff; }.btn.primary:hover { background:var(--blue-dark); }.btn.danger { border-color:#fecaca; background:#fff5f5; color:var(--red); }.driver-select { min-height:34px; max-width:190px; padding:0 8px; border:1px solid var(--line); border-radius:7px; color:var(--text); background:#fff; }        .approval-modal { position:fixed; inset:0; z-index:20; display:grid; place-items:center; padding:20px; background:rgba(16,42,67,.55); }.approval-modal[hidden] { display:none; }.approval-dialog { width:min(620px,100%); max-height:90vh; overflow:auto; padding:24px; border-radius:14px; background:#fff; box-shadow:0 24px 70px rgba(16,42,67,.25); }.approval-dialog header { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:18px; }.approval-dialog h2 { margin:0 0 5px; font-size:21px; }.approval-dialog header p { margin:0; color:var(--muted); font-size:13px; }.modal-close { width:32px; height:32px; border:0; border-radius:50%; background:#eef6fa; color:var(--navy); font-size:20px; }.trip-details { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:20px; }.trip-detail { padding:11px 12px; border:1px solid var(--line); border-radius:8px; }.trip-detail.full { grid-column:1 / -1; }.trip-detail small { display:block; margin-bottom:4px; color:var(--muted); font-size:11px; font-weight:800; text-transform:uppercase; }.trip-detail div { white-space:pre-wrap; overflow-wrap:anywhere; }.modal-actions { display:flex; justify-content:flex-end; gap:8px; padding-top:16px; border-top:1px solid var(--line); }
        .empty { padding:22px; color:var(--muted); text-align:center; }.notice { margin-bottom:18px; padding:11px 13px; border-radius:8px; background:#e6f9ff; color:var(--blue-dark); font-size:12px; }.error { background:#fff5f5; color:var(--red); }
        @media (max-width:760px) { .layout { display:block; }.sidebar { position:relative; height:auto; max-height:250px; }.topbar { align-items:flex-start; flex-direction:column; }.trip-details { grid-template-columns:1fr; }.trip-detail.full { grid-column:auto; } }
    </style>
    <style>
        .approval-modal {
            overflow-y: auto;
            padding: 20px;
        }
        .approval-dialog {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr) auto;
            width: min(760px, 100%);
            max-height: calc(100vh - 40px);
            max-height: calc(100dvh - 40px);
            overflow: hidden;
            padding: 0;
        }
        .approval-dialog header {
            align-items: center;
            margin: 0;
            padding: 24px 26px 20px;
            border-bottom: 1px solid var(--line);
            background: #f8fbfe;
        }
        .approval-dialog h2 {
            margin-bottom: 6px;
            font-size: 23px;
            letter-spacing: -.02em;
        }
        .approval-dialog header p {
            line-height: 1.5;
        }
        .approval-dialog header .approval-eyebrow {
            margin: 0 0 7px;
            color: var(--blue-dark);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .1em;
        }
        .modal-close {
            flex: 0 0 36px;
            width: 36px;
            height: 36px;
            font-size: 23px;
        }
        .modal-close:hover {
            background: #dceef8;
        }
        .trip-details {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-content: start;
            gap: 12px;
            min-height: 0;
            overflow-y: auto;
            margin: 0;
            padding: 22px 26px 26px;
        }
        .trip-detail {
            min-width: 0;
            padding: 14px 15px;
            border-color: #e4edf4;
            border-radius: 10px;
            background: #fbfdff;
        }
        .trip-detail small {
            margin-bottom: 7px;
            color: #718096;
            font-size: 10px;
            letter-spacing: .06em;
        }
        .trip-detail div {
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.55;
        }
        .trip-detail.amount {
            border-color: #cdeafa;
            background: #f0f9ff;
        }
        .trip-detail.amount div {
            color: var(--blue-dark);
            font-size: 17px;
            font-weight: 800;
        }
        .plan-details-content {
            display: grid;
            gap: 18px;
            font-weight: 400 !important;
        }
        .plan-detail-section {
            display: grid;
            gap: 9px;
        }
        .plan-detail-heading {
            color: #718096;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .destination-list {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }
        .destination-chip {
            padding: 6px 9px;
            border: 1px solid #d8eaf5;
            border-radius: 7px;
            background: #f4faff;
            color: var(--text);
            font-size: 12px;
            font-weight: 600;
        }
        .hotel-list,
        .itinerary-list {
            display: grid;
            gap: 8px;
        }
        .hotel-item {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 10px;
            padding: 10px 12px;
            border: 1px solid #e4edf4;
            border-radius: 8px;
            background: #fff;
        }
        .hotel-region {
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
        }
        .hotel-name {
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
        }
        .itinerary-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding: 10px 12px;
            border-left: 2px solid #bae6fd;
            border-radius: 0 8px 8px 0;
            background: #fff;
        }
        .itinerary-day {
            padding: 4px 7px;
            border-radius: 6px;
            background: #e0f2fe;
            color: var(--blue-dark);
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
        }
        .itinerary-description {
            padding-top: 2px;
            color: var(--text);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.5;
        }
        .modal-actions {
            padding: 16px 26px;
            border-top: 1px solid var(--line);
            background: #fff;
        }
        .modal-actions .btn {
            min-height: 40px;
            padding: 0 16px;
            font-size: 13px;
        }
        @media (max-width: 600px) {
            .approval-modal {
                align-items: end;
                padding: 12px;
            }
            .approval-dialog {
                max-height: calc(100vh - 24px);
                max-height: calc(100dvh - 24px);
                border-radius: 16px;
            }
            .approval-dialog header {
                padding: 20px 18px 16px;
            }
            .approval-dialog h2 {
                font-size: 20px;
            }
            .trip-details {
                grid-template-columns: 1fr;
                gap: 9px;
                padding: 16px 18px 20px;
            }
            .trip-detail.full {
                grid-column: auto;
            }
            .modal-actions {
                padding: 13px 18px;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><img src="../assets/logo/logo.png" alt="MTR logo"><div><strong>Mindanao Travel Hub</strong><span>Employee operations</span></div></div>
        <div class="nav-label">Work queue</div>
        <a class="nav-link active" href="#plans">&#9635; <span>Plan approvals</span></a>
        <a class="nav-link" href="messages.php" aria-label="Support inbox<?= $unreadSupportCount ? ', ' . $unreadSupportCount . ' unread customer messages' : '' ?>">
            &#9993; <span class="nav-link-label">Support inbox</span>
            <span class="nav-badge" id="supportUnreadBadge"<?= $unreadSupportCount ? '' : ' hidden' ?> aria-label="<?= $unreadSupportCount ?> unread customer messages"><?= $unreadSupportCount > 99 ? '99+' : $unreadSupportCount ?></span>
        </a>
        <a class="nav-link" href="#payments">&#10003; <span>Payment review</span></a>
        <div class="staff"><strong><?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?></strong><br><small>Employee</small></div>
        <a class="nav-link" href="../user/logout.php">&#8617; <span>Log out</span></a>
    </aside>
    <main class="content">
        <header class="topbar"><div><h1>Employee operations</h1><p>Review guest plans and support trip operations.</p></div></header>
        <div class="notice" id="notice">Loading operations queue...</div>
        <section class="panel" id="plans"><div class="panel-heading"><div><h2>Plans awaiting approval</h2><p>Review customized guest plans before payment.</p></div></div><div class="table-wrap"><table><thead><tr><th>Guest</th><th>Plan</th><th>Date</th><th>Total</th><th>Action</th></tr></thead><tbody id="planRows"></tbody></table></div></section>
        <section class="panel" id="extensions"><div class="panel-heading"><div><h2>Mid-trip extensions</h2><p>Review requests for additional days and approve the amount.</p></div></div><div class="table-wrap"><table><thead><tr><th>Guest</th><th>Trip</th><th>Extra days</th><th>Requested amount</th><th>Action</th></tr></thead><tbody id="extensionRows"></tbody></table></div></section>
        <section class="panel" id="completions"><div class="panel-heading"><div><h2>Trip completion verification</h2><p>Review the driver proof before marking a trip complete.</p></div></div><div class="table-wrap"><table><thead><tr><th>Guest</th><th>Trip</th><th>Date</th><th>Proof</th><th>Action</th></tr></thead><tbody id="completionRows"></tbody></table></div></section>
        <section class="panel" id="cancellations"><div class="panel-heading"><div><h2>Cancellation requests</h2><p>Review customer requests. Admin will handle any refund payment.</p></div></div><div class="table-wrap"><table><thead><tr><th>Guest</th><th>Plan</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody id="cancellationRows"></tbody></table></div></section>
    </main>
</div>
<script>
    (() => {
        const badge = document.querySelector('#supportUnreadBadge');
        if (!badge) return;

        const refreshSupportUnread = async () => {
            try {
                const response = await fetch('dashboard.php?support_unread=1&_=' + Date.now(), { cache: 'no-store' });
                const result = await response.json();
                if (!response.ok || !Number.isInteger(result.count) || result.count < 0) {
                    throw new Error(result.error || 'Unable to refresh support notifications.');
                }

                badge.textContent = result.count > 99 ? '99+' : String(result.count);
                badge.hidden = result.count === 0;
                badge.setAttribute('aria-label', `${result.count} unread customer messages`);
                const inboxLink = badge.closest('a');
                inboxLink.setAttribute('aria-label', `Support inbox${result.count ? `, ${result.count} unread customer messages` : ''}`);
            } catch (error) {
                console.error('Support inbox notification refresh failed:', error);
            }
        };

        window.setInterval(() => {
            if (!document.hidden) refreshSupportUnread();
        }, 30000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshSupportUnread();
        });
    })();
</script>
<div class="approval-modal" id="approvalModal" hidden>
    <section class="approval-dialog" role="dialog" aria-modal="true" aria-labelledby="approvalModalTitle" aria-describedby="approvalModalDescription">
        <header><div><p class="approval-eyebrow">BOOKING APPROVAL</p><h2 id="approvalModalTitle">Review travel plan</h2><p id="approvalModalDescription">Check the guest, itinerary, and price before approving this plan.</p></div><button class="modal-close" id="approvalModalClose" type="button" aria-label="Close review dialog">&times;</button></header>
        <div class="trip-details" id="approvalTripDetails"></div>
        <div class="modal-actions"><button class="btn" id="approvalModalCancel" type="button">Cancel</button><button class="btn primary" id="approvalModalConfirm" type="button">Approve plan</button></div>
    </section>
</div>
<script src="../assets/js/live-refresh.js"></script>
<script>
(() => {
    const notice = document.querySelector('#notice');
    const approvalModal = document.querySelector('#approvalModal');
    const approvalTripDetails = document.querySelector('#approvalTripDetails');
    let approvalBooking = null;
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' })[character]);
    let state = { bookings: [], payments: [], drivers: [] };
    const apiRequest = async payload => {
        const response = await fetch('../api/admin-content.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Unable to complete this operation.');
        return result;
    };
    const load = async () => {
        const response = await fetch('../api/admin-content.php?driver_scope=approved&_=' + Date.now(), { cache:'no-store' });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Unable to load the work queue.');
        state = result;
        render();
        notice.textContent = 'Payment approval and refunds are handled by the admin. Your queue covers plans, extensions, completion proofs, and cancellations.';
        notice.classList.remove('error');
    };
    const bookingTitle = booking => booking.package_name || booking.custom_title || 'Travel plan';
    const detailValue = value => String(value ?? '').trim() || 'Not provided';
    const renderPlanDetails = value => {
        const details = String(value ?? '').trim();
        if (!details) return '<span>Not provided</span>';

        const hotelMarker = ' · Hotel: ';
        const itineraryMarker = ' · Itinerary: ';
        const hotelIndex = details.indexOf(hotelMarker);
        const itineraryIndex = details.indexOf(itineraryMarker);
        const firstSectionIndex = [hotelIndex, itineraryIndex].filter(index => index >= 0).sort((a, b) => a - b)[0];
        const destinationText = (firstSectionIndex === undefined ? details : details.slice(0, firstSectionIndex)).trim();
        const hotelText = hotelIndex >= 0
            ? details.slice(hotelIndex + hotelMarker.length, itineraryIndex > hotelIndex ? itineraryIndex : undefined).trim()
            : '';
        const itineraryText = itineraryIndex >= 0 ? details.slice(itineraryIndex + itineraryMarker.length).trim() : '';
        const destinationMatch = destinationText.match(/^(\d+\s+destinations?)\s*·\s*(.*)$/i);

        if (!destinationMatch && !hotelText && !itineraryText) {
            return `<span>${escapeHtml(details)}</span>`;
        }

        const sections = [];
        if (destinationMatch) {
            const destinations = destinationMatch[2].split(/\s*,\s*/).filter(Boolean);
            sections.push(`<section class="plan-detail-section"><strong class="plan-detail-heading">${escapeHtml(destinationMatch[1])}</strong><div class="destination-list">${destinations.map(destination => `<span class="destination-chip">${escapeHtml(destination)}</span>`).join('')}</div></section>`);
        } else if (destinationText) {
            sections.push(`<section class="plan-detail-section"><strong class="plan-detail-heading">Destinations</strong><span>${escapeHtml(destinationText)}</span></section>`);
        }
        if (hotelText) {
            const hotels = hotelText.split(/\s*;\s*/).filter(Boolean).map(hotel => {
                const separator = hotel.indexOf(':');
                const region = separator >= 0 ? hotel.slice(0, separator).trim() : '';
                const name = separator >= 0 ? hotel.slice(separator + 1).trim() : hotel;
                return `<div class="hotel-item">${region ? `<span class="hotel-region">${escapeHtml(region)}</span>` : ''}<span class="hotel-name">${escapeHtml(name)}</span></div>`;
            });
            sections.push(`<section class="plan-detail-section"><strong class="plan-detail-heading">Accommodation</strong><div class="hotel-list">${hotels.join('')}</div></section>`);
        }
        if (itineraryText) {
            const days = itineraryText.split(/\s*\|\s*/).filter(Boolean).map(day => {
                const match = day.match(/^(Day\s+\d+)\s*:\s*(.*)$/i);
                return `<div class="itinerary-item">${match ? `<span class="itinerary-day">${escapeHtml(match[1])}</span><span class="itinerary-description">${escapeHtml(match[2])}</span>` : `<span class="itinerary-description">${escapeHtml(day)}</span>`}</div>`;
            });
            sections.push(`<section class="plan-detail-section"><strong class="plan-detail-heading">Itinerary</strong><div class="itinerary-list">${days.join('')}</div></section>`);
        }
        return `<div class="plan-details-content">${sections.join('')}</div>`;
    };
    const showApprovalModal = booking => {
        approvalBooking = booking;
        const details = [
            ['Guest', booking.customer_name],
            ['Plan', bookingTitle(booking)],
            ['Travel date', booking.travel_date],
            ['Guests', booking.guest_count],
            ['Total amount', `PHP ${Number(booking.total_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`],
            ['Pickup', booking.pickup_address || booking.pickup_location],
            ['Departure', booking.departure_location],
            ['Accommodation', booking.accommodation_name || booking.accommodation_type],
            ['Plan details', booking.custom_details, true]
        ];
        approvalTripDetails.innerHTML = details.map(([label, value, full]) => `<div class="trip-detail${full ? ' full' : ''}${label === 'Total amount' ? ' amount' : ''}"><small>${escapeHtml(label)}</small><div>${label === 'Plan details' ? renderPlanDetails(value) : escapeHtml(detailValue(value))}</div></div>`).join('');
        approvalModal.hidden = false;
        document.querySelector('#approvalModalConfirm').focus();
    };
    const closeApprovalModal = () => { approvalBooking = null; approvalModal.hidden = true; };
    const render = () => {
        const pendingPlans = state.bookings.filter(booking => String(booking.approval_status || 'pending') === 'pending');
        document.querySelector('#planRows').innerHTML = pendingPlans.length ? pendingPlans.map(booking => `<tr><td>${escapeHtml(booking.customer_name)}</td><td>${escapeHtml(bookingTitle(booking))}</td><td>${escapeHtml(booking.travel_date)}</td><td>PHP ${Number(booking.total_amount || 0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td><td><div class="action-group"><button class="btn primary plan-action" data-id="${booking.id}" data-status="approved">Approve</button><button class="btn danger plan-action" data-id="${booking.id}" data-status="rejected">Deny</button></div></td></tr>`).join('') : '<tr><td colspan="5" class="empty">No plans are waiting for approval.</td></tr>';
        const extensions = (state.extension_requests || []).filter(item => item.status === 'pending');
        document.querySelector('#extensionRows').innerHTML = extensions.length ? extensions.map(item => `<tr><td>${escapeHtml(item.customer_name)}</td><td>${escapeHtml(item.package_name)}</td><td>${escapeHtml(item.extra_days)}</td><td>PHP ${Number(item.requested_amount || 0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td><td><div class="action-group"><input class="extension-amount" data-id="${item.id}" type="number" min="0" step="0.01" value="${Number(item.requested_amount || 0).toFixed(2)}" aria-label="Approved extension amount"><button class="btn primary extension-action" data-id="${item.id}" data-status="approved">Approve</button><button class="btn danger extension-action" data-id="${item.id}" data-status="rejected">Reject</button></div></td></tr>`).join('') : '<tr><td colspan="5" class="empty">No extension requests are waiting.</td></tr>';
        const completions = state.bookings.filter(item => item.completion_proof);
        document.querySelector('#completionRows').innerHTML = completions.length ? completions.map(item => `<tr><td>${escapeHtml(item.customer_name)}</td><td>${escapeHtml(bookingTitle(item))}</td><td>${escapeHtml(item.travel_date)}</td><td><a class="btn" href="../${escapeHtml(item.completion_proof)}" target="_blank" rel="noopener">Open proof</a></td><td><div class="action-group"><button class="btn primary completion-action" data-id="${item.id}" data-status="verified">Verify</button><button class="btn danger completion-action" data-id="${item.id}" data-status="rejected">Reject</button></div></td></tr>`).join('') : '<tr><td colspan="5" class="empty">No completion proofs are waiting.</td></tr>';
        const cancellations = (state.cancellation_requests || []).filter(item => item.status === 'pending');
        document.querySelector('#cancellationRows').innerHTML = cancellations.length ? cancellations.map(item => `<tr><td>${escapeHtml(item.customer_name)}</td><td>${escapeHtml(item.package_name)}</td><td>${escapeHtml(item.reason || 'Cancellation requested')}</td><td><span class="status">Pending</span></td><td><div class="action-group"><button class="btn primary cancellation-action" data-id="${item.id}" data-status="approved">Approve request</button><button class="btn danger cancellation-action" data-id="${item.id}" data-status="rejected">Reject</button></div></td></tr>`).join('') : '<tr><td colspan="5" class="empty">No cancellation requests are waiting.</td></tr>';
    };
    document.addEventListener('click', async event => {
        const planButton = event.target.closest('.plan-action');
        const extensionButton = event.target.closest('.extension-action');
        const completionButton = event.target.closest('.completion-action');
        const cancellationButton = event.target.closest('.cancellation-action');
        try {
            if (planButton) {
                if (planButton.dataset.status === 'approved') {
                    const booking = state.bookings.find(item => String(item.id) === planButton.dataset.id);
                    if (booking) showApprovalModal(booking);
                    return;
                }
                const note = planButton.dataset.status === 'rejected' ? window.prompt('Reason for denying this plan:') : '';
                if (planButton.dataset.status === 'rejected' && !note) return;
                await apiRequest({ type:'review_booking', id:Number(planButton.dataset.id), status:planButton.dataset.status, note:note || '' });
                await load();
            }
            if (extensionButton) {
                const amountInput = document.querySelector(`.extension-amount[data-id="${extensionButton.dataset.id}"]`);
                await apiRequest({ type:'review_extension', extension_id:Number(extensionButton.dataset.id), status:extensionButton.dataset.status, approved_amount:Number(amountInput?.value || 0) });
                await load();
            }
            if (completionButton) {
                await apiRequest({ type:'verify_completion', booking_id:Number(completionButton.dataset.id), status:completionButton.dataset.status });
                await load();
            }
            if (cancellationButton) {
                await apiRequest({ type:'review_cancellation', id:Number(cancellationButton.dataset.id), status:cancellationButton.dataset.status });
                await load();
            }
        } catch (error) { notice.textContent = error.message; notice.classList.add('error'); }
    });
    document.querySelector('#approvalModalConfirm').addEventListener('click', async () => {
        if (!approvalBooking) return;
        const bookingId = Number(approvalBooking.id);
        closeApprovalModal();
        try {
            await apiRequest({ type:'review_booking', id:bookingId, status:'approved', note:'' });
            await load();
        } catch (error) { notice.textContent = error.message; notice.classList.add('error'); }
    });
    document.querySelector('#approvalModalClose').addEventListener('click', closeApprovalModal);
    document.querySelector('#approvalModalCancel').addEventListener('click', closeApprovalModal);
    approvalModal.addEventListener('click', event => { if (event.target === approvalModal) closeApprovalModal(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !approvalModal.hidden) closeApprovalModal(); });
    load().catch(error => { notice.textContent = error.message; notice.classList.add('error'); });
    MTRLive.start(() => load().catch(() => {}), { isBusy: () => !approvalModal.hidden });
})();
</script>
</body>
</html>
