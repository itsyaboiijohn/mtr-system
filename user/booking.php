<?php
require_once __DIR__ . '/../api/auth.php';
$userId = require_user_auth();
$database = require_once __DIR__ . '/../api/db_con.php';
require_once __DIR__ . '/../api/vehicle-availability.php';
mtrEnsureVehicleBookingColumns($database);

$database->exec("CREATE TABLE IF NOT EXISTS support_tickets (
	Ticket_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	User_ID INT UNSIGNED NOT NULL,
	Booking_ID INT UNSIGNED NULL,
	subject VARCHAR(255) NOT NULL DEFAULT 'General Inquiry',
	status ENUM('open', 'pending', 'resolved', 'closed') NOT NULL DEFAULT 'open',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT fk_ticket_user_booking_page FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
	CONSTRAINT fk_ticket_booking_booking_page FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$database->exec("CREATE TABLE IF NOT EXISTS cancellation_requests (
	Cancellation_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	Booking_ID INT UNSIGNED NOT NULL,
	Payment_ID INT UNSIGNED NULL,
	User_ID INT UNSIGNED NOT NULL,
	Ticket_ID INT UNSIGNED NOT NULL,
	reason TEXT NULL,
	requested_amount DECIMAL(10, 2) NULL,
	status ENUM('pending', 'approved', 'rejected', 'refund_sent', 'partially_refunded') NOT NULL DEFAULT 'pending',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	reviewed_at TIMESTAMP NULL,
	CONSTRAINT fk_cancellation_booking_booking_page FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
	CONSTRAINT fk_cancellation_payment_booking_page FOREIGN KEY (Payment_ID) REFERENCES payments(Payment_ID) ON DELETE SET NULL,
	CONSTRAINT fk_cancellation_user_booking_page FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
	CONSTRAINT fk_cancellation_ticket_booking_page FOREIGN KEY (Ticket_ID) REFERENCES support_tickets(Ticket_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$database->exec("ALTER TABLE cancellation_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'refund_sent', 'partially_refunded') NOT NULL DEFAULT 'pending'");

// Ensure custom columns exist on bookings table
$bCols = $database->query("SHOW COLUMNS FROM bookings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('custom_title', $bCols, true)) {
    $database->exec("ALTER TABLE bookings ADD COLUMN custom_title VARCHAR(255) NULL");
}
if (!in_array('custom_details', $bCols, true)) {
    $database->exec("ALTER TABLE bookings ADD COLUMN custom_details TEXT NULL");
}
if (!in_array('custom_image_url', $bCols, true)) {
    $database->exec("ALTER TABLE bookings ADD COLUMN custom_image_url VARCHAR(500) NULL");
}
if (!in_array('approval_status', $bCols, true)) {
	$database->exec("ALTER TABLE bookings ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER booking_status");
}
if (!in_array('approval_note', $bCols, true)) {
	$database->exec('ALTER TABLE bookings ADD COLUMN approval_note TEXT NULL AFTER approval_status');
}
$database->exec("CREATE TABLE IF NOT EXISTS booking_extension_requests (
    Extension_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Booking_ID INT UNSIGNED NOT NULL,
    User_ID INT UNSIGNED NOT NULL,
    extra_days TINYINT UNSIGNED NOT NULL DEFAULT 1,
    requested_departure_date DATE NULL,
    request_details TEXT NULL,
    requested_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    approved_amount DECIMAL(10, 2) NULL,
    status ENUM('pending', 'approved', 'rejected', 'paid') NOT NULL DEFAULT 'pending',
    admin_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    CONSTRAINT fk_extension_booking_user_page FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
    CONSTRAINT fk_extension_user_user_page FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$extensionDestinations = $database->query("SELECT Destination_ID, name, location, region FROM destinations WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$extensionRegions = $database->query("SELECT DISTINCT TRIM(region) AS region FROM destinations WHERE status = 'active' AND NULLIF(TRIM(region), '') IS NOT NULL ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
$extensionHotels = $database->query("SELECT Hotel_ID, name, location, region FROM hotels WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_extension') {
	$extensionBookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
	$extraDays = filter_input(INPUT_POST, 'extra_days', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
	$requestedRegion = trim((string) ($_POST['region'] ?? ''));
	$hotelIdInput = trim((string) ($_POST['hotel_id'] ?? ''));
	$selectedHotelId = $hotelIdInput === '' ? null : filter_var($hotelIdInput, FILTER_VALIDATE_INT);
	$requestedDestinationIds = [];
	foreach ((array) ($_POST['destination_ids'] ?? []) as $destinationIdInput) {
		$destinationId = filter_var($destinationIdInput, FILTER_VALIDATE_INT);
		if ($destinationId === false || $destinationId < 1) {
			http_response_code(400);
			exit('Choose valid destinations.');
		}
		$requestedDestinationIds[] = $destinationId;
	}
	$requestedDestinationIds = array_values(array_unique($requestedDestinationIds));
	$otherLocation = trim((string) ($_POST['other_location'] ?? ''));
	if ($selectedHotelId === false || ($selectedHotelId !== null && $selectedHotelId < 1)) {
		http_response_code(400);
		exit('Choose a valid hotel.');
	}
	if (!$extensionBookingId || $extraDays === false || $requestedRegion === '') {
		http_response_code(400);
		exit('Choose a region and at least one extra day.');
	}
	$extensionOwner = $database->prepare("SELECT b.Booking_ID, b.total_amount, b.travel_date, b.booking_status, COALESCE(p.duration_days, b.custom_duration_days, 1) AS duration_days FROM bookings b LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID WHERE b.Booking_ID = ? AND b.Customer_ID = ? AND b.booking_status = 'confirmed' AND b.payment_status = 'paid' LIMIT 1");
	$extensionOwner->execute([$extensionBookingId, $userId]);
	$extensionBooking = $extensionOwner->fetch(PDO::FETCH_ASSOC);
	if (!$extensionBooking) {
		http_response_code(403);
		exit('Only paid and confirmed trips can be extended.');
	}

	$activeRegionStatement = $database->prepare("SELECT TRIM(region) FROM destinations WHERE status = 'active' AND LOWER(TRIM(region)) = LOWER(?) LIMIT 1");
	$activeRegionStatement->execute([$requestedRegion]);
	$requestedRegion = (string) ($activeRegionStatement->fetchColumn() ?: '');
	if ($requestedRegion === '') {
		http_response_code(400);
		exit('Choose an available destination region.');
	}

	$currentRegionStatement = $database->prepare("SELECT DISTINCT TRIM(d.region) FROM destinations d WHERE NULLIF(TRIM(d.region), '') IS NOT NULL AND d.Destination_ID IN (SELECT bd.Destination_ID FROM booking_destinations bd WHERE bd.Booking_ID = ? UNION SELECT pd.Destination_ID FROM travel_package_destinations pd INNER JOIN bookings package_booking ON package_booking.Travel_Pck_ID = pd.Travel_Pck_ID WHERE package_booking.Booking_ID = ?)");
	$currentRegionStatement->execute([$extensionBookingId, $extensionBookingId]);
	$currentPlanRegions = array_map(
		static fn ($region) => strtolower(trim((string) $region)),
		$currentRegionStatement->fetchAll(PDO::FETCH_COLUMN)
	);
	$regionIsInPlan = in_array(strtolower($requestedRegion), $currentPlanRegions, true);

	$selectedHotel = null;
	if ($selectedHotelId !== false && $selectedHotelId !== null) {
		$hotelStatement = $database->prepare("SELECT name, region FROM hotels WHERE Hotel_ID = ? AND status = 'active' LIMIT 1");
		$hotelStatement->execute([$selectedHotelId]);
		$selectedHotel = $hotelStatement->fetch(PDO::FETCH_ASSOC);
		if (!$selectedHotel || strcasecmp(trim((string) $selectedHotel['region']), $requestedRegion) !== 0) {
			http_response_code(400);
			exit('Choose an active hotel in the selected region.');
		}
	}
	if (!$regionIsInPlan && !$selectedHotel) {
		http_response_code(400);
		exit('Choose a hotel when extending your trip to a new region.');
	}

	$requestedDestinations = [];
	if ($requestedDestinationIds) {
		$destinationPlaceholders = implode(',', array_fill(0, count($requestedDestinationIds), '?'));
		$destinationStatement = $database->prepare("SELECT name, region FROM destinations WHERE Destination_ID IN ({$destinationPlaceholders}) AND status = 'active' ORDER BY name");
		$destinationStatement->execute($requestedDestinationIds);
		$destinationRows = $destinationStatement->fetchAll(PDO::FETCH_ASSOC);
		if (count($destinationRows) !== count($requestedDestinationIds)) {
			http_response_code(400);
			exit('One or more selected destinations are no longer available.');
		}
		foreach ($destinationRows as $destinationRow) {
			if (strcasecmp(trim((string) $destinationRow['region']), $requestedRegion) !== 0) {
				http_response_code(400);
				exit('Choose destinations from the selected region.');
			}
			$requestedDestinations[] = $destinationRow['name'];
		}
	}
	$extensionParts = [];
	$extensionParts[] = 'Requested region: ' . $requestedRegion;
	if ($requestedDestinations) {
		$extensionParts[] = 'Requested destinations: ' . implode(', ', $requestedDestinations);
	}
	if ($selectedHotel) {
		$extensionParts[] = 'Requested hotel: ' . $selectedHotel['name'];
	}
	if ($otherLocation !== '') {
		$extensionParts[] = 'Other requested location: ' . $otherLocation;
	}
	$extensionDetails = trim((string) ($_POST['request_details'] ?? ''));
	if ($extensionDetails !== '') {
		$extensionParts[] = 'Additional request: ' . $extensionDetails;
	}
	$extensionDetails = implode("\n", $extensionParts);
	$pendingExtension = $database->prepare("SELECT Extension_ID FROM booking_extension_requests WHERE Booking_ID = ? AND status IN ('pending', 'approved') LIMIT 1");
	$pendingExtension->execute([$extensionBookingId]);
	if ($pendingExtension->fetchColumn()) {
		header('Location: booking.php');
		exit;
	}
	$maxExtraDays = mtrMaxExtensionDays($database, (int) $extensionBookingId);
	if ($maxExtraDays !== null && $extraDays > $maxExtraDays) {
		http_response_code(409);
		exit($maxExtraDays > 0
			? 'Your driver and vehicle are only available for ' . $maxExtraDays . ' more day(s). Please request ' . $maxExtraDays . ' extra day(s) or fewer.'
			: 'Your driver and vehicle are already booked right after your trip, so this trip cannot be extended.');
	}
	$duration = max(1, (int) ($extensionBooking['duration_days'] ?? 1));
	$requestedDepartureDate = (new DateTimeImmutable($extensionBooking['travel_date']))->modify('+' . ($duration + $extraDays - 1) . ' days')->format('Y-m-d');
	$requestedAmount = ((float) $extensionBooking['total_amount'] / $duration) * $extraDays;
	$extensionInsert = $database->prepare('INSERT INTO booking_extension_requests (Booking_ID, User_ID, extra_days, requested_departure_date, request_details, requested_amount) VALUES (?, ?, ?, ?, ?, ?)');
	$extensionInsert->execute([$extensionBookingId, $userId, $extraDays, $requestedDepartureDate !== '' ? $requestedDepartureDate : null, $extensionDetails !== '' ? $extensionDetails : null, $requestedAmount]);
	header('Location: booking.php');
	exit;
}

$userEmail = $_SESSION['user_email'] ?? null;

$extensionRequests = [];
$extensionStatement = $database->prepare("SELECT e.Extension_ID AS id, e.Booking_ID AS booking_id, e.extra_days, e.requested_departure_date, e.request_details, e.requested_amount, e.approved_amount, e.status, e.admin_note, (SELECT pay.status FROM payments pay WHERE pay.Extension_ID = e.Extension_ID ORDER BY pay.created_at DESC LIMIT 1) AS payment_status FROM booking_extension_requests e WHERE e.User_ID = ? ORDER BY e.created_at DESC");
$extensionStatement->execute([$userId]);
$extensionRequests = $extensionStatement->fetchAll(PDO::FETCH_ASSOC);
$extensionByBooking = [];
foreach ($extensionRequests as $extensionRequest) {
	$extensionByBooking[(int) $extensionRequest['booking_id']] = $extensionRequest;
}

$bookings = [];
if ($userId || $userEmail) {
    $stmt = $database->prepare("
		SELECT b.Booking_ID AS id, b.travel_date, b.trip_duration_days, b.requested_vehicle_id, requested_vehicle.vehicle_unit AS requested_vehicle_name, b.guest_count, b.total_amount,
			   CASE WHEN b.Travel_Pck_ID IS NULL AND NULLIF(b.custom_title, '') IS NOT NULL THEN 1 ELSE 0 END AS is_custom_plan,
			   b.booking_status, b.payment_status, b.approval_status, b.approval_note, b.created_at,
			   (SELECT cr.status FROM cancellation_requests cr WHERE cr.Booking_ID = b.Booking_ID ORDER BY cr.created_at DESC LIMIT 1) AS cancellation_status,
			   (SELECT pay.refund_status FROM payments pay WHERE pay.Booking_ID = b.Booking_ID ORDER BY pay.created_at DESC LIMIT 1) AS refund_status,
			   (SELECT pay.status FROM payments pay WHERE pay.Booking_ID = b.Booking_ID ORDER BY pay.created_at DESC LIMIT 1) AS latest_payment_status,
			   COALESCE(p.duration_days, b.trip_duration_days, b.custom_duration_days, 1) AS duration_days, h.name AS hotel_name, b.accommodation_type, b.accommodation_name, b.accommodation_address, b.accommodation_maps_link,
			   GROUP_CONCAT(DISTINCT COALESCE(custom_d.name, d.name) ORDER BY COALESCE(custom_d.name, d.name) SEPARATOR ', ') AS destination_names,
			   GROUP_CONCAT(DISTINCT NULLIF(TRIM(COALESCE(custom_d.region, d.region)), '') ORDER BY NULLIF(TRIM(COALESCE(custom_d.region, d.region)), '') SEPARATOR ', ') AS destination_regions,
			   GROUP_CONCAT(DISTINCT COALESCE(NULLIF((SELECT di.image_path FROM destination_images di WHERE di.Destination_ID = COALESCE(custom_d.Destination_ID, d.Destination_ID) ORDER BY di.Destination_Image_ID LIMIT 1), ''), NULLIF(custom_d.image_url, ''), NULLIF(d.image_url, '')) SEPARATOR '||') AS destination_images,
			   (SELECT hi.image_path FROM hotel_images hi WHERE hi.Hotel_ID = COALESCE(b.Hotel_ID, p.Hotel_ID) ORDER BY hi.Hotel_Image_ID LIMIT 1) AS hotel_image,
               COALESCE(NULLIF(b.custom_title, ''), p.name, 'Custom Travel Plan') AS package_name,
               COALESCE(NULLIF(b.custom_details, ''), p.description, 'Tailored Mindanao travel experience') AS package_desc,
			   driver_user.full_name AS driver_name,
			   driver_user.phone AS driver_phone,
               COALESCE(
                   NULLIF(b.custom_image_url, ''),
                   p.image_url,
                   (SELECT di.image_path FROM destination_images di ORDER BY di.Destination_Image_ID ASC LIMIT 1),
                   (SELECT d.image_url FROM destinations d WHERE d.status = 'active' AND d.image_url IS NOT NULL AND d.image_url <> '' LIMIT 1),
                   'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=800&q=82'
               ) AS image_url
        FROM bookings b
		LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID
		LEFT JOIN hotels h ON h.Hotel_ID = b.Hotel_ID
		LEFT JOIN company_vehicles requested_vehicle ON requested_vehicle.Company_Vehicle_ID = b.requested_vehicle_id
		LEFT JOIN travel_package_destinations pd ON pd.Travel_Pck_ID = p.Travel_Pck_ID
		LEFT JOIN destinations d ON d.Destination_ID = pd.Destination_ID
		LEFT JOIN booking_destinations custom_bd ON custom_bd.Booking_ID = b.Booking_ID
		LEFT JOIN destinations custom_d ON custom_d.Destination_ID = custom_bd.Destination_ID
        LEFT JOIN users u ON u.User_ID = b.Customer_ID
		LEFT JOIN booking_assignments ba ON ba.Booking_ID = b.Booking_ID
		LEFT JOIN driver_profiles dp ON dp.Driver_Profile_ID = ba.Driver_Profile_ID
		LEFT JOIN users driver_user ON driver_user.User_ID = dp.User_ID
        WHERE b.Customer_ID = ? OR (u.email IS NOT NULL AND u.email = ?)
		GROUP BY b.Booking_ID
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$userId ?: 0, $userEmail ?: '']);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($bookings as &$booking) {
	$booking['entrance_pass_token'] = '';
	if (
		(int) ($booking['is_custom_plan'] ?? 0) !== 1
		|| !in_array($booking['payment_status'], ['paid', 'verified'], true)
		|| $booking['booking_status'] !== 'confirmed'
		|| trim((string) ($booking['destination_names'] ?? '')) === ''
	) {
		continue;
	}

	$passLookup = $database->prepare('SELECT token FROM booking_entrance_passes WHERE Booking_ID = ?');
	$passLookup->execute([(int) $booking['id']]);
	$passToken = $passLookup->fetchColumn();
	if (!$passToken) {
		$newPassToken = bin2hex(random_bytes(32));
		$passInsert = $database->prepare('INSERT IGNORE INTO booking_entrance_passes (Booking_ID, token) VALUES (?, ?)');
		$passInsert->execute([(int) $booking['id'], $newPassToken]);
		$passLookup->execute([(int) $booking['id']]);
		$passToken = $passLookup->fetchColumn();
	}
	$booking['entrance_pass_token'] = (string) $passToken;
}
unset($booking);

$destinationLookup = [];
$destinationLookupStatement = $database->query("SELECT d.name,
	COALESCE((SELECT di.image_path FROM destination_images di WHERE di.Destination_ID = d.Destination_ID ORDER BY di.Destination_Image_ID LIMIT 1), d.image_url) AS image_path
	FROM destinations d WHERE d.status = 'active'");
foreach ($destinationLookupStatement->fetchAll(PDO::FETCH_ASSOC) as $destinationRow) {
	$destinationLookup[strtolower(trim($destinationRow['name']))] = trim((string) ($destinationRow['image_path'] ?? ''));
}

$confirmedCount = count(array_filter($bookings, static fn($b) => ($b['approval_status'] ?: $b['booking_status']) === 'approved' || $b['booking_status'] === 'confirmed'));
$pendingCount = count(array_filter($bookings, static fn($b) => ($b['approval_status'] ?: 'pending') === 'pending'));
$rejectedCount = count(array_filter($bookings, static fn($b) => ($b['approval_status'] ?: '') === 'rejected'));
$completedCount = count(array_filter($bookings, static fn($b) => $b['booking_status'] === 'completed'));
$cancelledCount = count(array_filter($bookings, static fn($b) => $b['booking_status'] === 'cancelled'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My Bookings | Mindanao Travel & Tours</title>
	<style>
		:root {
			--bg: #f0faff;
			--card: #fff;
			--primary: #0f1724;
			--muted: #6b7280;
			--accent: #0ea5e9;
			--accent-dark: #0369a1;
			--soft-accent: #e6f9ff;
			--line: rgba(15, 23, 42, 0.08);
			--shadow: 0 18px 45px rgba(2, 6, 23, 0.07);
			font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
		}

		* { box-sizing: border-box; }

		body { min-height: 100vh; margin: 0; background: var(--bg); color: var(--primary); }
		a { color: inherit; }

		.page { width: min(1080px, calc(100% - 40px)); margin: 0 auto; padding: 28px 0 48px; }

		.site-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 20px;
			margin-bottom: 52px;
		}

		.brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
		.logo { display: grid; width: 44px; height: 44px; place-items: center; overflow: hidden; border: 1px solid rgba(14, 165, 233, 0.12); border-radius: 10px; background: #fff; box-shadow: 0 8px 20px rgba(2, 6, 23, 0.05); }
		.logo img { width: 100%; height: 100%; object-fit: contain; }
		.brand strong { display: block; font-size: 15px; }
		.brand span { color: var(--muted); font-size: 12px; }

		.back-link { display: inline-flex; align-items: center; gap: 7px; color: var(--accent-dark); font-size: 13px; font-weight: 700; text-decoration: none; }
		.back-link:hover { color: var(--accent); }

		.intro { margin-bottom: 25px; }
		.eyebrow { margin: 0 0 8px; color: var(--accent-dark); font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
		h1 { margin: 0; font-size: clamp(30px, 4vw, 42px); letter-spacing: -.8px; }
		.intro p { margin: 10px 0 0; color: var(--muted); }

		.status-overview { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
		.status-card { padding: 17px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); box-shadow: 0 8px 22px rgba(2, 6, 23, .04); }
		.status-card strong { display: block; margin-bottom: 4px; font-size: 24px; }
		.status-card span { color: var(--muted); font-size: 12px; }
		.status-card.active { border-color: rgba(14, 165, 233, .35); box-shadow: 0 8px 22px rgba(14, 165, 233, .1); }
		.status-card.active strong { color: var(--accent-dark); }

		.booking-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 14px; }
		.booking-toolbar h2 { margin: 0; font-size: 19px; }
		.filters { display: flex; gap: 5px; padding: 4px; border: 1px solid var(--line); border-radius: 9px; background: #fff; }
		.filter-button { padding: 7px 10px; border: 0; border-radius: 6px; background: transparent; color: var(--muted); font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
		.filter-button:hover, .filter-button.active { background: var(--soft-accent); color: var(--accent-dark); }

		.booking-list { display: grid; gap: 12px; }
		.booking-card { display: grid; grid-template-columns: 112px minmax(0, 1fr) auto; gap: 17px; align-items: center; padding: 13px; border: 1px solid var(--line); border-radius: 13px; background: var(--card); box-shadow: var(--shadow); }
		.booking-image { height: 106px; border-radius: 9px; background: url('../assets/Spots/Screenshot%202026-09-05%20182549.png') center / cover; }
		.booking-image-collage { display: flex; gap: 2px; overflow: hidden; background: none; }
		.booking-image-collage span { flex: 1; background-position: center; background-size: cover; }
		.booking-content h3 { margin: 0 0 6px; font-size: 16px; }
		.booking-content p { margin: 0 0 10px; color: var(--muted); font-size: 13px; line-height: 1.45; }
		.booking-meta { display: flex; flex-wrap: wrap; gap: 10px; color: var(--muted); font-size: 12px; }
		.booking-status { display: inline-flex; align-items: center; gap: 5px; margin-top: 11px; padding: 5px 8px; border-radius: 6px; background: var(--soft-accent); color: var(--accent-dark); font-size: 11px; font-weight: 800; }
		.booking-status::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
		.booking-status.pending { background: #fff7ed; color: #c2410c; }
		.booking-status.cancellation-approved { background: #fff7ed; color: #b45309; }
		.booking-status.completed { background: #ecfdf5; color: #047857; }
		.booking-status.cancelled { background: #fef2f2; color: #b91c1c; }
		.booking-note { margin: 10px 0 0; color: #b45309; font-size: 11px; font-weight: 700; line-height: 1.4; }
		.booking-actions { display: flex; flex-direction: column; align-items: stretch; gap: 8px; min-width: 84px; }
		.view-button, .manage-button, .contact-button { display: inline-flex; justify-content: center; padding: 8px 10px; border-radius: 7px; font-size: 12px; font-weight: 700; text-align: center; text-decoration: none; }
		.view-button { background: var(--accent); color: #fff; }
		.view-button:hover { background: var(--accent-dark); }
		.receipt-button { border: 1px solid rgba(4, 120, 87, .2); background: #ecfdf5; color: #047857; }
		.receipt-button:hover { background: #d1fae5; }
		.manage-button { border: 1px solid var(--line); color: var(--muted); }
		.manage-button:hover { background: var(--soft-accent); color: var(--accent-dark); }
		.contact-button { border: 1px solid rgba(180, 83, 9, 0.2); background: #fff7ed; color: #b45309; }
		.contact-button:hover { background: #ffedd5; }
		.booking-card[hidden] { display: none; }
		.receipt-modal-backdrop { position: fixed; inset: 0; z-index: 30; display: none; place-items: center; overflow-y: auto; padding: 20px; background: rgba(15, 23, 42, .48); }
		.receipt-modal-backdrop.visible { display: grid; }
		.receipt-modal { width: min(900px, 100%); max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); overflow-y: auto; padding: 26px; border: 1px solid var(--line); border-radius: 14px; background: #fff; box-shadow: 0 25px 70px rgba(2, 6, 23, .2); }
		.receipt-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
		.receipt-modal-header h2 { margin: 0; font-size: 21px; }
		.receipt-modal-header p { margin: 5px 0 0; color: var(--muted); font-size: 12px; }
		.receipt-close { border: 0; background: transparent; color: var(--muted); font-size: 24px; line-height: 1; cursor: pointer; }
		.receipt-number { margin-bottom: 16px; color: var(--accent-dark); font-size: 13px; font-weight: 800; }
		.receipt-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; align-items: start; }
		.receipt-layout.has-pass { grid-template-columns: minmax(0, 1.35fr) minmax(250px, .75fr); }
		.receipt-summary { min-width: 0; }
		.receipt-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
		.receipt-item { padding: 11px 12px; border: 1px solid var(--line); border-radius: 9px; background: #f8fbff; }
		.receipt-item span, .receipt-item strong { display: block; }
		.receipt-item span { margin-bottom: 4px; color: var(--muted); font-size: 10px; font-weight: 800; text-transform: uppercase; }
		.receipt-item strong { font-size: 13px; }
		.receipt-coverage { margin-top: 12px; padding: 13px; border: 1px solid #bbf7d0; border-radius: 9px; background: #f0fdf4; color: #166534; }
		.receipt-coverage[hidden] { display: none; }
		.receipt-coverage strong { display: block; margin-bottom: 5px; font-size: 12px; }
		.receipt-coverage p { margin: 0; font-size: 12px; line-height: 1.55; }
		.receipt-coverage-destinations { display: block; margin-top: 7px; color: #14532d; font-size: 11px; font-weight: 700; }
		.receipt-entrance-pass { min-width: 0; padding: 18px 14px; border: 1px solid #cbdff0; border-radius: 10px; background: #f8fbff; text-align: center; }
		.receipt-entrance-pass[hidden] { display: none; }
		.receipt-entrance-pass h3 { margin: 0 0 5px; font-size: 14px; }
		.receipt-entrance-pass p { margin: 0; color: var(--muted); font-size: 11px; line-height: 1.5; overflow-wrap: anywhere; }
		.receipt-qr { display: grid; place-items: center; min-height: 204px; margin: 12px auto; }
		.receipt-qr canvas, .receipt-qr img { display: block; max-width: 100%; height: auto; }
		.receipt-pass-error { color: #b91c1c !important; font-weight: 700; }
		.receipt-paid { margin-top: 16px; padding: 11px; border-radius: 8px; background: #ecfdf5; color: #047857; font-size: 12px; font-weight: 800; text-align: center; }
		@media (max-width: 700px) {
			.receipt-modal-backdrop { align-items: start; padding: 14px; }
			.receipt-modal { width: min(560px, 100%); max-height: calc(100vh - 28px); max-height: calc(100dvh - 28px); padding: 20px; }
			.receipt-layout { grid-template-columns: minmax(0, 1fr); }
			.receipt-entrance-pass { display: grid; justify-items: center; }
			.receipt-entrance-pass[hidden] { display: none; }
			.receipt-entrance-pass h3, .receipt-entrance-pass > p { width: 100%; }
		}
		@media (max-width: 420px) {
			.receipt-modal-backdrop { padding: 8px; }
			.receipt-modal { max-height: calc(100vh - 16px); max-height: calc(100dvh - 16px); padding: 16px; border-radius: 12px; }
			.receipt-modal-header h2 { font-size: 19px; }
			.receipt-grid { grid-template-columns: minmax(0, 1fr); }
			.receipt-qr { min-height: 180px; }
		}
		.receipt-contact-button { display: inline-flex; min-height: 32px; align-items: center; justify-content: center; margin-top: 7px; padding: 0 10px; border: 1px solid rgba(3, 105, 161, .2); border-radius: 7px; background: #f0faff; color: var(--accent-dark); font-size: 11px; font-weight: 800; text-decoration: none; }
		.receipt-contact-button:hover { border-color: var(--accent); background: #e0f5ff; }
		.extension-button { border: 1px solid rgba(3, 105, 161, .2); background: #f0faff; color: var(--accent-dark); }
		.extension-button:hover { background: #e0f5ff; }
		.booking-actions .receipt-button,
		.booking-actions .extension-button,
		.booking-actions .contact-button { display: inline-flex; width: 100%; min-height: 38px; align-items: center; justify-content: center; padding: 0 11px; border: 1px solid rgba(3, 105, 161, .2); border-radius: 8px; background: #f0faff; color: var(--accent-dark); font: inherit; font-size: 12px; font-weight: 800; line-height: 1.25; text-align: center; text-decoration: none; cursor: pointer; }
		.booking-actions .receipt-button:hover,
		.booking-actions .extension-button:hover,
		.booking-actions .contact-button:hover { border-color: var(--accent); background: #e0f5ff; color: var(--accent-dark); }
		.delete-plan-button { width: 100%; min-height: 38px; padding: 0 11px; border: 1px solid rgba(185, 28, 28, .2); border-radius: 8px; background: #fff5f5; color: #b91c1c; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
		.delete-plan-button:hover { border-color: #ef4444; background: #fee2e2; }
		.extension-modal-backdrop { position: fixed; inset: 0; z-index: 31; display: none; place-items: center; overflow-y: auto; padding: 20px; background: rgba(15, 23, 42, .58); overscroll-behavior: contain; }
		.extension-modal-backdrop.visible { display: grid; }
		.extension-modal { width: min(620px, 100%); max-height: calc(100vh - 40px); max-height: calc(100dvh - 40px); overflow-y: auto; padding: 26px; border: 1px solid rgba(255, 255, 255, .6); border-radius: 18px; background: #fff; box-shadow: 0 28px 80px rgba(2, 6, 23, .28); overscroll-behavior: contain; }
		body.extension-modal-open { overflow: hidden; }
		.extension-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
		.extension-modal-heading { min-width: 0; }
		.extension-modal-eyebrow { margin: 0 0 5px !important; color: var(--accent-dark) !important; font-size: 10px !important; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
		.extension-modal h2 { margin: 0 0 6px; font-size: 23px; letter-spacing: -.03em; }
		.extension-modal-header p:last-child { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.55; }
		.extension-close { display: grid; flex: 0 0 auto; width: 34px; height: 34px; place-items: center; border: 1px solid var(--line); border-radius: 9px; background: #fff; color: var(--muted); font: inherit; font-size: 22px; line-height: 1; cursor: pointer; }
		.extension-close:hover { border-color: #cbd5e1; background: #f8fafc; color: var(--primary); }
		.extension-modal label { display: grid; gap: 6px; margin-top: 14px; font-size: 12px; font-weight: 800; }
		.extension-modal label small { color: var(--muted); font-size: 11px; font-weight: 500; }
		.extension-modal input,.extension-modal textarea { width: 100%; min-height: 42px; padding: 10px 12px; border: 1px solid #dbe3eb; border-radius: 9px; background: #fff; color: var(--primary); font: inherit; font-size: 13px; }
		.extension-modal select { width: 100%; min-height: 42px; padding: 10px 12px; border: 1px solid #dbe3eb; border-radius: 9px; background: #fff; color: var(--primary); font: inherit; font-size: 13px; }
		.extension-modal input:focus,.extension-modal textarea:focus { border-color: var(--accent); outline: 3px solid rgba(14, 165, 233, .12); }
		.extension-modal select:focus { border-color: var(--accent); outline: 3px solid rgba(14, 165, 233, .12); }
		.extension-modal textarea { min-height: 80px; resize: vertical; }
		.extension-modal input[readonly] { background: #f8fafc; color: #475569; }
		.extension-destination-option[hidden], .extension-hotel-field[hidden] { display: none !important; }
		.extension-modal label[hidden] { display: none !important; }
		.extension-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
		.extension-form-grid label { margin-top: 16px; }
		.extension-modal-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 22px; padding-top: 16px; border-top: 1px solid var(--line); }
		.extension-modal-actions button { min-height: 40px; padding: 0 15px; border: 0; border-radius: 9px; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
		.extension-modal-actions .cancel { background: #f1f5f9; color: #475569; }
		.extension-modal-actions .cancel:hover { background: #e2e8f0; }
		.extension-modal-actions .submit { background: var(--accent-dark); color: #fff; box-shadow: 0 4px 10px rgba(3, 105, 161, .18); }
		.extension-modal-actions .submit:hover { background: #075985; }
		.extension-destination-list { display: grid; max-height: 150px; gap: 7px; overflow: auto; padding: 10px; border: 1px solid var(--line); border-radius: 8px; background: #f8fbff; }
		.extension-destination-option { display: flex !important; align-items: center; gap: 9px; margin: 0 !important; padding: 7px; border-radius: 7px; font-weight: 600 !important; }
		.extension-destination-option:hover { background: #eff8fc; }
		.extension-destination-option input { width: 16px; min-height: 16px; flex: 0 0 auto; padding: 0; accent-color: var(--accent-dark); }
		.extension-destination-option small { display: block; color: var(--muted); font-weight: 400; }
		.extension-context { margin-bottom: 4px; }
		.extension-context-list { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 0; padding: 0; list-style: none; }
		.extension-context-item { min-width: 0; padding: 12px; border: 1px solid #e2eaf0; border-radius: 11px; background: linear-gradient(145deg, #f8fcff, #f3f8fb); }
		.extension-context-label { display: block; margin-bottom: 6px; color: #64748b; font-size: 10px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
		.extension-context-value { display: block; color: var(--primary); font-size: 12px; font-weight: 700; line-height: 1.45; overflow-wrap: anywhere; }
		.extension-context-note { margin: 9px 0 0; color: var(--muted); font-size: 10px; line-height: 1.5; }
		@media (max-width: 560px) {
			.extension-modal-backdrop { align-items: start; padding: 12px; }
			.extension-modal { max-height: calc(100vh - 24px); max-height: calc(100dvh - 24px); padding: 19px; border-radius: 14px; }
			.extension-context-list { grid-template-columns: minmax(0, 1fr); gap: 7px; }
			.extension-context-item { padding: 10px 12px; }
			.extension-context-label { margin-bottom: 3px; }
			.extension-form-grid { grid-template-columns: minmax(0, 1fr); gap: 0; }
			.extension-form-grid label { margin-top: 12px; }
		}

		.empty-state { display: none; padding: 32px; border: 1px dashed rgba(14, 165, 233, .3); border-radius: 12px; background: rgba(255, 255, 255, .65); color: var(--muted); text-align: center; }

		.plan-modal-backdrop {
			position: fixed;
			inset: 0;
			display: none;
			align-items: center;
			justify-content: center;
			padding: 20px;
			background: rgba(15, 23, 42, 0.46);
			z-index: 20;
		}
		.plan-modal-backdrop.visible { display: flex; }
		.plan-modal {
			width: min(640px, 100%);
			max-height: 88vh;
			overflow: auto;
			background: #fff;
			border: 1px solid var(--line);
			border-radius: 16px;
			box-shadow: 0 28px 70px rgba(2, 6, 23, 0.18);
		}
		.plan-modal-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			padding: 18px 20px 12px;
			border-bottom: 1px solid var(--line);
		}
		.plan-modal-header h2 {
			margin: 0;
			font-size: 22px;
		}
		.plan-close {
			border: 0;
			background: transparent;
			color: var(--muted);
			font-size: 30px;
			line-height: 1;
			cursor: pointer;
		}
		.plan-modal-body {
			padding: 18px 20px 20px;
		}
		.plan-modal-grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 12px;
			margin-bottom: 18px;
		}
		.plan-modal-detail {
			padding: 10px 12px;
			border: 1px solid var(--line);
			border-radius: 10px;
			background: #f9fcff;
		}
		.plan-modal-detail span {
			display: block;
			margin-bottom: 5px;
			color: var(--muted);
			font-size: 11px;
			font-weight: 800;
			letter-spacing: .06em;
			text-transform: uppercase;
		}
		.plan-modal-detail strong {
			display: block;
			font-size: 14px;
		}
		.plan-modal-copy {
			padding: 12px 14px;
			border-radius: 10px;
			background: #f8fbff;
			border: 1px solid var(--line);
			color: var(--primary);
			font-size: 13px;
			line-height: 1.6;
			white-space: pre-wrap;
		}
		.plan-modal-copy strong {
			display: block;
			margin-bottom: 8px;
			font-size: 12px;
			color: var(--accent-dark);
			letter-spacing: .06em;
			text-transform: uppercase;
		}
		#planModalItinerary { display: grid; gap: 8px; }
		#planModalItinerary div { display: grid; grid-template-columns: 58px minmax(0, 1fr); gap: 10px; align-items: start; padding: 9px 0; border-top: 1px solid var(--line); }
		#planModalItinerary div:first-child { padding-top: 0; border-top: 0; }
		#planModalItinerary strong { margin: 0; color: var(--accent-dark); font-size: 12px; letter-spacing: 0; text-transform: none; }
		#planModalItinerary span { color: var(--primary); font-size: 13px; line-height: 1.45; }

		@media (max-width: 700px) {
			.page { width: min(100% - 28px, 560px); padding-top: 20px; }
			.site-header { margin-bottom: 38px; }
			.status-overview { grid-template-columns: repeat(2, 1fr); }
			.booking-toolbar { align-items: flex-start; flex-direction: column; }
			.filters { width: 100%; overflow-x: auto; }
			.filter-button { flex: 1 0 auto; }
		}

		@media (max-width: 480px) {
			.booking-card { grid-template-columns: 76px minmax(0, 1fr); gap: 12px; }
			.booking-image { height: 76px; }
			.booking-actions { grid-column: 2; flex-direction: row; }
			.booking-actions a { flex: 1; }
			.back-link { font-size: 0; gap: 0; }
		}
	</style>
</head>
<body>
	<main class="page">
		<header class="site-header">
			<a class="brand" href="dashboard.php">
				<span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span>
				<span><strong>Mindanao Travel & Tours</strong><span>Booking management</span></span>
			</a>
			<a class="back-link" href="dashboard.php">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
				Back to dashboard
			</a>
		</header>

		<section class="intro" aria-labelledby="bookingTitle">
			<p class="eyebrow">Travel organizer</p>
			<h1 id="bookingTitle">My bookings</h1>
			<p>Keep track of your upcoming adventures and past trips in one place.</p>
		</section>

		<section class="status-overview" aria-label="Booking status overview">
			<div class="status-card active"><strong><?= $confirmedCount ?></strong><span>Confirmed plan<?= $confirmedCount === 1 ? '' : 's' ?></span></div>
			<div class="status-card"><strong><?= $pendingCount ?></strong><span>Pending plan<?= $pendingCount === 1 ? '' : 's' ?></span></div>
			<div class="status-card"><strong><?= $completedCount ?></strong><span>Completed trip<?= $completedCount === 1 ? '' : 's' ?></span></div>
			<div class="status-card"><strong><?= $rejectedCount ?></strong><span>Rejected plan<?= $rejectedCount === 1 ? '' : 's' ?></span></div>
		</section>

		<section aria-labelledby="allBookingsTitle">
			<div class="booking-toolbar">
				<h2 id="allBookingsTitle">All bookings</h2>
				<div class="filters" role="tablist" aria-label="Filter bookings">
					<button class="filter-button active" type="button" data-filter="all" role="tab" aria-selected="true">All</button>
					<button class="filter-button" type="button" data-filter="confirmed" role="tab" aria-selected="false">Confirmed</button>
					<button class="filter-button" type="button" data-filter="pending" role="tab" aria-selected="false">Pending</button>
					<button class="filter-button" type="button" data-filter="completed" role="tab" aria-selected="false">Completed</button>
				</div>
			</div>

			<div class="booking-list" id="bookingList">
				<?php if ($bookings): ?>
					<?php foreach ($bookings as $b):
						$paymentStatus = strtolower((string) ($b['payment_status'] ?? 'pending'));
						$latestPaymentStatus = strtolower((string) ($b['latest_payment_status'] ?? ''));
						if (($b['booking_status'] ?? '') === 'completed') {
							$status = 'completed';
							$statusLabel = 'Completed';
						} elseif ($paymentStatus === 'paid' || $paymentStatus === 'verified') {
							$status = 'confirmed';
							$statusLabel = 'Paid';
						} elseif ($latestPaymentStatus === 'pending') {
							$status = 'pending';
							$statusLabel = 'Payment submitted';
						} else {
							$status = strtolower($b['approval_status'] ?: ($b['booking_status'] ?: 'pending'));
							$statusLabel = $status === 'approved' ? 'Approved for payment' : ucfirst($status);
						}
						$statusClass = $status === 'pending' ? 'pending' : ($status === 'rejected' ? 'cancelled' : ($status === 'completed' ? 'completed' : ''));
						$cancellationStatus = strtolower((string) ($b['cancellation_status'] ?? ''));
						$refundStatus = strtolower((string) ($b['refund_status'] ?? ''));
						if ($refundStatus === 'refunded') {
							$cancellationStatus = 'refund_sent';
						} elseif ($refundStatus === 'partially_refunded') {
							$cancellationStatus = 'partially_refunded';
						}
						if ($cancellationStatus === 'pending') {
							$status = 'pending';
							$statusLabel = 'Cancellation in process';
						} elseif ($cancellationStatus === 'approved') {
							$status = 'pending';
							$statusLabel = 'Cancellation approved';
						} elseif ($cancellationStatus === 'refund_sent') {
							$status = 'cancelled';
							$statusLabel = 'Cancelled and refunded';
						} elseif ($cancellationStatus === 'partially_refunded') {
							$status = 'cancelled';
							$statusLabel = 'Cancelled with partial refund';
						}
						$statusClass = $cancellationStatus === 'approved' ? 'cancellation-approved' : ($cancellationStatus === 'refund_sent' || $cancellationStatus === 'partially_refunded' ? 'cancelled' : ($status === 'pending' ? 'pending' : ($status === 'cancelled' ? 'cancelled' : ($status === 'completed' ? 'completed' : ''))));
						$dateStr = date('M d, Y', strtotime($b['travel_date']));
						$pax = (int) $b['guest_count'];
						$totalFormatted = '₱ ' . number_format((float) $b['total_amount'], 2);
						$rawImg = trim((string) ($b['image_url'] ?? ''));
						if ($rawImg === '' || str_contains($rawImg, 'logo.png')) {
							$rawImg = 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=800&q=82';
						}
						$bgImage = preg_match('/^https?:\/\//i', $rawImg) ? $rawImg : (str_starts_with($rawImg, '../') ? $rawImg : '../' . ltrim($rawImg, '/'));
						$detailText = trim((string) ($b['package_desc'] ?? '')) ?: 'Tailored Mindanao travel experience';
						$destinationText = trim((string) ($b['destination_names'] ?? ''));
						$regionText = trim((string) ($b['destination_regions'] ?? ''));
						$regionNames = array_values(array_filter(array_map('trim', explode(',', $regionText))));
						$regionData = json_encode($regionNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
						if ($destinationText === '' && preg_match('/(?:Addons|destinations?)\s*:\s*(.*?)(?:\s*·\s*Hotel:|$)/i', (string) ($b['custom_details'] ?? ''), $destinationMatch)) {
							$destinationText = trim($destinationMatch[1]);
						}
						$destinationText = $destinationText !== '' && strcasecmp($destinationText, 'None') !== 0 ? $destinationText : 'Destinations will be confirmed with your itinerary.';
						$hotelText = trim((string) ($b['hotel_name'] ?? ''));
						if ($hotelText === '') {
							$hotelText = trim((string) ($b['accommodation_name'] ?? ''));
						}
						if ($hotelText !== '' && trim((string) ($b['accommodation_address'] ?? '')) !== '') {
							$hotelText .= ' · ' . trim((string) $b['accommodation_address']);
						}
						if ($hotelText === '' && preg_match('/Hotel:\s*(.*?)(?:\s*·|$)/i', (string) ($b['custom_details'] ?? ''), $hotelMatch)) {
							$hotelText = trim($hotelMatch[1]);
						}
						$hotelText = $hotelText !== '' ? $hotelText : 'Accommodation will be confirmed with your itinerary.';
						$destinationImagePaths = array_values(array_filter(array_map('trim', explode('||', (string) ($b['destination_images'] ?? '')))));
						if (!$destinationImagePaths && $destinationText !== 'Destinations will be confirmed with your itinerary.') {
							foreach (explode(',', $destinationText) as $destinationName) {
								$imagePath = $destinationLookup[strtolower(trim($destinationName))] ?? '';
								if ($imagePath !== '') $destinationImagePaths[] = $imagePath;
							}
						}
						$destinationImagePaths = array_values(array_unique(array_filter(array_map(static function ($imagePath) {
							$imagePath = trim((string) $imagePath);
							return $imagePath === '' ? '' : (preg_match('/^https?:\/\//i', $imagePath) ? $imagePath : '../' . ltrim($imagePath, '/'));
						}, $destinationImagePaths))));
						if ($destinationImagePaths) {
							$bgImage = $destinationImagePaths[0];
						}
						$collageImages = array_slice($destinationImagePaths, 0, 3);
						$durationDays = max(1, (int) ($b['duration_days'] ?? 1));
						$canPay = $status === 'approved' && $b['payment_status'] !== 'paid';
						$canDelete = !$cancellationStatus && !in_array($paymentStatus, ['paid', 'verified'], true) && ($status === 'pending' || $status === 'approved' || $paymentStatus !== 'paid');
						$bookingExtension = $extensionByBooking[(int) $b['id']] ?? null;
					?>
						<article
							class="booking-card"
							data-status="<?= $status ?>"
							data-title="<?= htmlspecialchars($b['package_name'], ENT_QUOTES, 'UTF-8') ?>"
							data-description="<?= htmlspecialchars($detailText, ENT_QUOTES, 'UTF-8') ?>"
							data-date="<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>"
							data-guests="<?= (int) $pax ?>"
							data-total="<?= htmlspecialchars($totalFormatted, ENT_QUOTES, 'UTF-8') ?>"
							data-status-label="<?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>"
							data-destinations="<?= htmlspecialchars($destinationText, ENT_QUOTES, 'UTF-8') ?>"
							data-hotel="<?= htmlspecialchars($hotelText, ENT_QUOTES, 'UTF-8') ?>"
							data-duration="<?= $durationDays ?>"
						>
							<?php if (count($collageImages) > 1): ?>
							<div class="booking-image booking-image-collage" role="img" aria-label="<?= htmlspecialchars($b['package_name'], ENT_QUOTES, 'UTF-8') ?>"><?php foreach ($collageImages as $collageImage): ?><span style="background-image:url('<?= htmlspecialchars($collageImage, ENT_QUOTES, 'UTF-8') ?>')"></span><?php endforeach; ?></div>
							<?php else: ?>
							<div class="booking-image" role="img" aria-label="<?= htmlspecialchars($b['package_name'], ENT_QUOTES, 'UTF-8') ?>" style="background-image:url('<?= htmlspecialchars($bgImage, ENT_QUOTES, 'UTF-8') ?>')"></div>
							<?php endif; ?>
							<div class="booking-content">
								<h3><?= htmlspecialchars($b['package_name'], ENT_QUOTES, 'UTF-8') ?></h3>
								<p><?= htmlspecialchars($b['package_desc'], ENT_QUOTES, 'UTF-8') ?></p>
								<div class="booking-meta">
									<span><?= $dateStr ?></span>
									<span><?= $pax ?> guest<?= $pax === 1 ? '' : 's' ?></span>
									<?php if (!empty($b['requested_vehicle_name'])): ?>
										<span>Vehicle request: <?= htmlspecialchars($b['requested_vehicle_name'], ENT_QUOTES, 'UTF-8') ?></span>
									<?php elseif (!empty($b['custom_title'])): ?>
										<span>Vehicle assignment pending</span>
									<?php endif; ?>
									<strong><?= $totalFormatted ?></strong>
								</div>
								<span class="booking-status <?= $statusClass ?>"><?= $statusLabel ?></span>
								<?php if ($cancellationStatus === 'pending'): ?>
									<p class="booking-note">Cancellation in process. Admin is reviewing your request.</p>
								<?php elseif ($cancellationStatus === 'approved'): ?>
									<p class="booking-note">Cancellation approved · Waiting for refund</p>
								<?php elseif ($cancellationStatus === 'refund_sent' || $cancellationStatus === 'partially_refunded'): ?>
									<p class="booking-note"><?= $cancellationStatus === 'partially_refunded' ? 'Cancellation approved · Partial refund successful' : 'Cancellation approved · Refund successful' ?></p>
									<p class="booking-note">Your refund receipt and reference number were sent to your Gmail. Please <a href="https://mail.google.com/mail/u/0/#inbox" target="_blank" rel="noopener noreferrer">check your email</a>.</p>
								<?php elseif ($status === 'pending'): ?>
									<p class="booking-note">Your itinerary is waiting for admin review. Payment will unlock after approval.</p>
								<?php elseif ($status === 'rejected'): ?>
									<p class="booking-note">Admin feedback: <?= htmlspecialchars($b['approval_note'] ?: 'Please review and resubmit your itinerary.', ENT_QUOTES, 'UTF-8') ?></p>
								<?php elseif ($canPay): ?>
									<p class="booking-note">Your plan was approved. You can now complete payment.</p>
								<?php elseif ($status === 'confirmed'): ?>
									<p class="booking-note">Payment confirmed. Please <a href="https://mail.google.com/mail/u/0/#inbox" target="_blank" rel="noopener noreferrer">check your email</a> for your complete trip instructions, itinerary, and travel agent information.</p>
								<?php endif; ?>
							</div>
							<div class="booking-actions">
								<?php if ($status === 'confirmed' && !$cancellationStatus): ?>
									<button class="receipt-button" type="button" data-receipt-booking="<?= (int) $b['id'] ?>" data-receipt-title="<?= htmlspecialchars($b['package_name'], ENT_QUOTES, 'UTF-8') ?>" data-receipt-date="<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>" data-receipt-guests="<?= $pax ?>" data-receipt-total="<?= htmlspecialchars($totalFormatted, ENT_QUOTES, 'UTF-8') ?>" data-receipt-driver="<?= htmlspecialchars($b['driver_name'] ? $b['driver_name'] . ' · ' . $b['driver_phone'] : 'Not assigned yet', ENT_QUOTES, 'UTF-8') ?>" data-receipt-driver-phone="<?= htmlspecialchars($b['driver_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-receipt-custom-plan="<?= (int) $b['is_custom_plan'] ?>" data-receipt-destinations="<?= htmlspecialchars($destinationText, ENT_QUOTES, 'UTF-8') ?>" data-receipt-pass-token="<?= htmlspecialchars($b['entrance_pass_token'], ENT_QUOTES, 'UTF-8') ?>">View receipt</button>
									<?php if (!$bookingExtension): ?><button class="extension-button" type="button" data-extension-booking="<?= (int) $b['id'] ?>" data-extension-regions="<?= htmlspecialchars($regionData ?: '[]', ENT_QUOTES, 'UTF-8') ?>" data-extension-region="<?= htmlspecialchars($regionText !== '' ? $regionText : 'Not specified', ENT_QUOTES, 'UTF-8') ?>" data-extension-destinations="<?= htmlspecialchars($destinationText, ENT_QUOTES, 'UTF-8') ?>" data-extension-duration="<?= $durationDays ?>" data-extension-date="<?= htmlspecialchars($b['travel_date'], ENT_QUOTES, 'UTF-8') ?>" data-extension-accommodation="<?= htmlspecialchars($hotelText, ENT_QUOTES, 'UTF-8') ?>">Extend trip</button><?php elseif ($bookingExtension['status'] === 'approved' && ($bookingExtension['payment_status'] ?? '') !== 'pending'): ?><a class="extension-button" href="payment.php?extension_id=<?= (int) $bookingExtension['id'] ?>">Pay extension</a><?php else: ?><span class="booking-note" style="margin:0;text-align:center"><?= htmlspecialchars(($bookingExtension['payment_status'] ?? '') === 'pending' ? 'Payment submitted' : ucfirst($bookingExtension['status']), ENT_QUOTES, 'UTF-8') ?> extension</span><?php endif; ?>
									<button class="contact-button" type="button" data-cancellation-booking="<?= (int) $b['id'] ?>" data-cancellation-title="<?= htmlspecialchars($b['package_name'], ENT_QUOTES, 'UTF-8') ?>">Request cancellation</button>
									<a class="contact-button" href="service.php">Talk to admin</a>
								<?php elseif ($cancellationStatus): ?>
								<?php else: ?>
									<button class="view-button open-plan-btn" type="button">View details</button>
								<?php endif; ?>
								<?php if ($canPay): ?>
									<a class="manage-button" href="payment.php?booking_id=<?= (int) $b['id'] ?>">Pay now</a>
								<?php endif; ?>
								<?php if ($canDelete): ?>
									<form method="POST" action="payment.php" onsubmit="return window.confirm('Delete this unpaid travel plan? This cannot be undone.');">
										<input type="hidden" name="action" value="cancel_booking">
										<input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
										<button class="delete-plan-button" type="submit">Delete plan</button>
									</form>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<div class="empty-state" id="emptyState" <?= $bookings ? '' : 'style="display:block"' ?>>
				<?= $bookings ? 'No bookings match this filter.' : 'You have no bookings yet. Explore our packages or build your plan to get started!' ?>
			</div>
		</section>
	</main>

	<div class="plan-modal-backdrop" id="planModalBackdrop" aria-hidden="true">
		<div class="plan-modal" role="dialog" aria-modal="true" aria-labelledby="planModalTitle">
			<div class="plan-modal-header">
				<h2 id="planModalTitle">Booking details</h2>
				<button class="plan-close" type="button" aria-label="Close booking details">×</button>
			</div>
			<div class="plan-modal-body">
				<div class="plan-modal-grid">
					<div class="plan-modal-detail"><span>Trip</span><strong id="planModalTitleValue">-</strong></div>
					<div class="plan-modal-detail"><span>Status</span><strong id="planModalStatus">-</strong></div>
					<div class="plan-modal-detail"><span>Date</span><strong id="planModalDate">-</strong></div>
					<div class="plan-modal-detail"><span>Guests</span><strong id="planModalGuests">-</strong></div>
					<div class="plan-modal-detail"><span>Total</span><strong id="planModalTotal">-</strong></div>
							<div class="plan-modal-detail"><span>Hotel</span><strong id="planModalHotel">-</strong></div>
				</div>
						<div class="plan-modal-copy"><strong>Destinations</strong><span id="planModalDestinations">-</span></div>
						<div class="plan-modal-copy"><strong>Day-by-day plan</strong><div id="planModalItinerary"></div></div>
				<div class="plan-modal-copy"><strong>Plan overview</strong><span id="planModalDescription">-</span></div>
			</div>
		</div>
	</div>
	<div class="receipt-modal-backdrop" id="receiptModalBackdrop" aria-hidden="true">
		<section class="receipt-modal" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
			<div class="receipt-modal-header"><div><h2 id="receiptModalTitle">Booking receipt</h2><p>Mindanao Travel & Tours</p></div><button class="receipt-close" id="receiptClose" type="button" aria-label="Close booking receipt">×</button></div>
			<div class="receipt-number" id="receiptNumber">Receipt MTR-</div>
			<div class="receipt-layout">
				<div class="receipt-summary">
					<div class="receipt-grid"><div class="receipt-item"><span>Trip</span><strong id="receiptTrip">-</strong></div><div class="receipt-item"><span>Travel date</span><strong id="receiptDate">-</strong></div><div class="receipt-item"><span>Guests</span><strong id="receiptGuests">-</strong></div><div class="receipt-item"><span>Total paid</span><strong id="receiptTotal">-</strong></div><div class="receipt-item"><span>Driver contact</span><strong id="receiptDriver">-</strong><a id="receiptDriverContact" class="receipt-contact-button" href="#" hidden>Open WhatsApp</a></div></div>
					<div class="receipt-coverage" id="receiptCoverage" hidden><strong>Custom plan coverage</strong><p>The company covers entrance fees at the destinations listed below. Show the digital pass at each participating destination; staff must verify the pass online. Food, optional activities, and personal expenses are paid by the guest.</p><span class="receipt-coverage-destinations" id="receiptCoverageDestinations"></span></div>
				</div>
				<div class="receipt-entrance-pass" id="receiptEntrancePass" hidden><h3>Digital entrance pass</h3><p>Scan this code to verify the booking. Each destination records its own entry.</p><div class="receipt-qr" id="receiptQr"></div><p id="receiptPassLink"></p></div>
			</div>
			<div class="receipt-paid">Payment confirmed by admin</div>
		</section>
	</div>
	<div class="extension-modal-backdrop" id="extensionModalBackdrop" aria-hidden="true">
		<form class="extension-modal" method="POST" role="dialog" aria-modal="true" aria-labelledby="extensionModalTitle">
			<div class="extension-modal-header">
				<div class="extension-modal-heading"><p class="extension-modal-eyebrow">Trip extension request</p><h2 id="extensionModalTitle">Extend your trip</h2><p>Review your current trip details, then tell us how you would like to extend your stay.</p></div>
				<button class="extension-close" id="extensionClose" type="button" aria-label="Close extension request">×</button>
			</div>
			<input type="hidden" name="action" value="request_extension"><input type="hidden" name="booking_id" id="extensionBookingId">
			<div class="extension-context" id="extensionContext" aria-label="Current trip details">Trip details will appear here.</div>
			<label>Which region would you like to visit?
				<select name="region" id="extensionRegion" required>
					<option value="">Choose a region first</option>
					<?php foreach ($extensionRegions as $extensionRegion): ?>
						<option value="<?= htmlspecialchars($extensionRegion, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($extensionRegion, ENT_QUOTES, 'UTF-8') ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label id="extensionDestinationsLabel" hidden>Destinations in this region <small>(Optional — select one or more)</small></label>
			<div class="extension-destination-list" id="extensionDestinationList" hidden>
				<?php foreach ($extensionDestinations as $extensionDestination): ?>
					<label class="extension-destination-option" data-region="<?= htmlspecialchars($extensionDestination['region'] ?? '', ENT_QUOTES, 'UTF-8') ?>" hidden><input type="checkbox" name="destination_ids[]" value="<?= (int) $extensionDestination['Destination_ID'] ?>"><span><?= htmlspecialchars($extensionDestination['name'], ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars($extensionDestination['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></small></span></label>
				<?php endforeach; ?>
				<small id="extensionDestinationEmpty" hidden>No active destinations are listed for this region.</small>
			</div>
			<label class="extension-hotel-field" id="extensionHotelField" hidden>Hotel <small id="extensionHotelNote">Choose a region to see available hotels.</small>
				<select name="hotel_id" id="extensionHotel">
					<option value="">Choose a hotel</option>
					<?php foreach ($extensionHotels as $extensionHotel): ?>
						<option value="<?= (int) $extensionHotel['Hotel_ID'] ?>" data-region="<?= htmlspecialchars($extensionHotel['region'] ?? '', ENT_QUOTES, 'UTF-8') ?>" hidden><?= htmlspecialchars($extensionHotel['name'] . (!empty($extensionHotel['location']) ? ' · ' . $extensionHotel['location'] : ''), ENT_QUOTES, 'UTF-8') ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="extension-form-grid">
				<label>Extra days<input type="number" name="extra_days" min="1" max="30" value="1" required></label>
				<label>Final departure date <small>Calculated from your trip duration</small><input type="date" name="requested_departure_date" id="extensionDepartureDate" readonly></label>
			</div>
			<label>Other location <small>(optional)</small><input type="text" name="other_location" maxlength="180" placeholder="Enter another place you want to visit"></label>
			<div class="extension-modal-actions"><button class="cancel" id="extensionCancel" type="button">Cancel</button><button class="submit" type="submit">Send extension request</button></div>
		</form>
	</div>

	<script src="../assets/js/qrcode.js"></script>
	<script src="../assets/js/live-refresh.js"></script>
	<script>
		MTRLive.start(() => location.reload(), { isBusy: () => Boolean(document.querySelector('.plan-modal-backdrop.visible, .receipt-modal-backdrop.visible, .extension-modal-backdrop.visible')) });
	</script>
	<script>
		(() => {
			const cards = Array.from(document.querySelectorAll('.booking-card'));
			const emptyState = document.querySelector('#emptyState');
			const modal = document.querySelector('#planModalBackdrop');
			const closeButton = modal.querySelector('.plan-close');
			const modalTitle = document.querySelector('#planModalTitleValue');
			const modalStatus = document.querySelector('#planModalStatus');
			const modalDate = document.querySelector('#planModalDate');
			const modalGuests = document.querySelector('#planModalGuests');
			const modalTotal = document.querySelector('#planModalTotal');
			const modalHotel = document.querySelector('#planModalHotel');
			const modalDestinations = document.querySelector('#planModalDestinations');
			const modalItinerary = document.querySelector('#planModalItinerary');
			const modalDescription = document.querySelector('#planModalDescription');
			const receiptModal = document.querySelector('#receiptModalBackdrop');
			const receiptClose = document.querySelector('#receiptClose');
			const extensionModal = document.querySelector('#extensionModalBackdrop');
			const extensionCancel = document.querySelector('#extensionCancel');
			const extensionCloseButton = document.querySelector('#extensionClose');
			const extensionDays = extensionModal.querySelector('[name="extra_days"]');
			const extensionDepartureDate = document.querySelector('#extensionDepartureDate');
			const extensionContext = document.querySelector('#extensionContext');
			const extensionRegion = document.querySelector('#extensionRegion');
			const extensionDestinationsLabel = document.querySelector('#extensionDestinationsLabel');
			const extensionDestinationList = document.querySelector('#extensionDestinationList');
			const extensionDestinationOptions = Array.from(extensionDestinationList.querySelectorAll('.extension-destination-option'));
			const extensionDestinationEmpty = document.querySelector('#extensionDestinationEmpty');
			const extensionHotelField = document.querySelector('#extensionHotelField');
			const extensionHotel = document.querySelector('#extensionHotel');
			const extensionHotelNote = document.querySelector('#extensionHotelNote');
			const extensionHotelOptions = Array.from(extensionHotel.querySelectorAll('option[data-region]'));
			let extensionPlanRegions = new Set();
			let extensionStartDate = '';
			let extensionDuration = 1;
			const normalizeRegion = (region) => String(region || '').trim().toLocaleLowerCase();
			const updateExtensionRegionSelection = () => {
				const selectedRegion = extensionRegion.value;
				const normalizedRegion = normalizeRegion(selectedRegion);
				let matchingDestinations = 0;

				extensionDestinationList.hidden = !selectedRegion;
				extensionDestinationsLabel.hidden = !selectedRegion;
				extensionDestinationOptions.forEach((option) => {
					const matchesRegion = Boolean(selectedRegion) && normalizeRegion(option.dataset.region) === normalizedRegion;
					option.hidden = !matchesRegion;
					if (matchesRegion) {
						matchingDestinations += 1;
					} else {
						option.querySelector('input').checked = false;
					}
				});
				extensionDestinationEmpty.hidden = !selectedRegion || matchingDestinations > 0;

				const isRegionInPlan = extensionPlanRegions.has(normalizedRegion);
				extensionHotelField.hidden = !selectedRegion;
				extensionHotel.required = Boolean(selectedRegion) && !isRegionInPlan;
				extensionHotelNote.textContent = isRegionInPlan
					? 'Optional — this region is already included in your current plan.'
					: 'Required when visiting a region outside your current plan.';
				const matchingHotels = extensionHotelOptions.filter((option) => normalizeRegion(option.dataset.region) === normalizedRegion);
				extensionHotelOptions.forEach((option) => {
					option.hidden = !selectedRegion || normalizeRegion(option.dataset.region) !== normalizedRegion;
				});
				if (selectedRegion && !isRegionInPlan && matchingHotels.length === 0) {
					extensionHotelNote.textContent = 'No active hotels are available in this region. Please contact support before submitting.';
				}
				if (extensionHotel.selectedOptions[0]?.hidden) {
					extensionHotel.value = '';
				}
			};
			const updateExtensionDeparture = () => {
				if (!extensionStartDate) return;
				const departureDate = new Date(`${extensionStartDate}T00:00:00`);
				departureDate.setDate(departureDate.getDate() + extensionDuration + Math.max(1, Number(extensionDays.value) || 1) - 1);
				extensionDepartureDate.value = departureDate.toISOString().slice(0, 10);
			};

			const openModal = (card) => {
				if (!card) return;
				modalTitle.textContent = card.dataset.title || 'Travel plan';
				modalStatus.textContent = card.dataset.statusLabel || 'Pending';
				modalDate.textContent = card.dataset.date || 'Not set';
				modalGuests.textContent = `${card.dataset.guests || 0} guest(s)`;
				modalTotal.textContent = card.dataset.total || '₱ 0.00';
				modalHotel.textContent = card.dataset.hotel || 'Accommodation will be confirmed with your itinerary.';
				modalDestinations.textContent = card.dataset.destinations || 'Destinations will be confirmed with your itinerary.';
				modalItinerary.replaceChildren();
				const destinations = (card.dataset.destinations || '').split(',').map(item => item.trim()).filter(Boolean);
				const duration = Math.max(1, Number(card.dataset.duration || 1));
				for (let day = 1; day <= duration; day += 1) {
					const dayRow = document.createElement('div');
					const dayTitle = document.createElement('strong');
					const dayText = document.createElement('span');
					dayTitle.textContent = `Day ${day}`;
					dayText.textContent = destinations.length
						? (destinations[day - 1] || destinations[destinations.length - 1])
						: 'Explore the activities included in your travel plan.';
					dayRow.append(dayTitle, dayText);
					modalItinerary.appendChild(dayRow);
				}
				modalDescription.textContent = card.dataset.description || 'No travel details yet.';
				modal.classList.add('visible');
				modal.setAttribute('aria-hidden', 'false');
			};

			const closeModal = () => {
				modal.classList.remove('visible');
				modal.setAttribute('aria-hidden', 'true');
			};

			document.querySelectorAll('.open-plan-btn').forEach((button) => {
				button.addEventListener('click', () => {
					const card = button.closest('.booking-card');
					openModal(card);
				});
			});
			document.querySelectorAll('[data-receipt-booking]').forEach((button) => {
				button.addEventListener('click', () => {
					document.querySelector('#receiptNumber').textContent = `Receipt MTR-${button.dataset.receiptBooking}`;
					document.querySelector('#receiptTrip').textContent = button.dataset.receiptTitle || 'Travel plan';
					document.querySelector('#receiptDate').textContent = button.dataset.receiptDate || 'Not set';
					document.querySelector('#receiptGuests').textContent = `${button.dataset.receiptGuests || 0} guest(s)`;
					document.querySelector('#receiptTotal').textContent = button.dataset.receiptTotal || '₱ 0.00';
					document.querySelector('#receiptDriver').textContent = button.dataset.receiptDriver || 'Not assigned yet';
					const receiptCoverage = document.querySelector('#receiptCoverage');
					const hasCustomPlanCoverage = button.dataset.receiptCustomPlan === '1';
					receiptCoverage.hidden = !hasCustomPlanCoverage;
					document.querySelector('#receiptCoverageDestinations').textContent = hasCustomPlanCoverage
						? `Destinations: ${button.dataset.receiptDestinations || 'Not listed on this booking'}`
						: '';
					const entrancePass = document.querySelector('#receiptEntrancePass');
					const receiptLayout = document.querySelector('.receipt-layout');
					const qrContainer = document.querySelector('#receiptQr');
					const passLink = document.querySelector('#receiptPassLink');
					const passToken = button.dataset.receiptPassToken || '';
					const hasEntrancePass = hasCustomPlanCoverage && Boolean(passToken);
					entrancePass.hidden = !hasCustomPlanCoverage;
					receiptLayout.classList.toggle('has-pass', hasEntrancePass);
					qrContainer.replaceChildren();
					passLink.textContent = '';
					passLink.classList.remove('receipt-pass-error');
					if (hasCustomPlanCoverage && passToken) {
						const passUrl = new URL('../api/entrance-pass.php', window.location.href);
						passUrl.searchParams.set('token', passToken);
						if (typeof QRCode === 'undefined') {
							passLink.textContent = 'The QR generator could not be loaded. Reload the page or contact support.';
							passLink.classList.add('receipt-pass-error');
						} else {
							new QRCode(qrContainer, {
								text: passUrl.href,
								width: 190,
								height: 190,
								colorDark: '#102a43',
								colorLight: '#ffffff',
								correctLevel: QRCode.CorrectLevel.M
							});
							passLink.textContent = 'Pass link: ';
							const link = document.createElement('a');
							link.href = passUrl.href;
							link.textContent = 'Open verification page';
							link.rel = 'noopener';
							passLink.append(link);
						}
					} else if (hasCustomPlanCoverage) {
						passLink.textContent = 'A digital pass is unavailable because this booking has no linked destinations. Contact support.';
						passLink.classList.add('receipt-pass-error');
					}
					const driverPhone = String(button.dataset.receiptDriverPhone || '').replace(/\D/g, '');
					const whatsappNumber = driverPhone.startsWith('0') ? `63${driverPhone.slice(1)}` : driverPhone;
					const driverContact = document.querySelector('#receiptDriverContact');
					driverContact.hidden = !whatsappNumber;
					driverContact.href = whatsappNumber ? `whatsapp://send?phone=${whatsappNumber}` : '#';
					receiptModal.classList.add('visible');
					receiptModal.setAttribute('aria-hidden', 'false');
				});
			});
			document.querySelectorAll('[data-extension-booking]').forEach((button) => button.addEventListener('click', () => {
				document.querySelector('#extensionBookingId').value = button.dataset.extensionBooking;
				extensionStartDate = button.dataset.extensionDate || '';
				extensionDuration = Math.max(1, Number(button.dataset.extensionDuration || 1));
				extensionPlanRegions = new Set(JSON.parse(button.dataset.extensionRegions || '[]').map(normalizeRegion));
				extensionRegion.value = '';
				extensionHotel.value = '';
				extensionDestinationOptions.forEach((option) => {
					option.querySelector('input').checked = false;
				});
				updateExtensionRegionSelection();
				extensionContext.replaceChildren();
				const contextList = document.createElement('ul');
				contextList.className = 'extension-context-list';
				[
					['Region', button.dataset.extensionRegion || 'Not specified'],
					['Destination', button.dataset.extensionDestinations || 'Not specified'],
					['Hotel', button.dataset.extensionAccommodation || 'Not provided']
				].forEach(([label, value]) => {
					const item = document.createElement('li');
					item.className = 'extension-context-item';
					const content = document.createElement('span');
					const labelElement = document.createElement('span');
					labelElement.className = 'extension-context-label';
					labelElement.textContent = label;
					const valueElement = document.createElement('span');
					valueElement.className = 'extension-context-value';
					valueElement.textContent = value;
					content.append(labelElement, valueElement);
					item.appendChild(content);
					contextList.appendChild(item);
				});
				extensionContext.appendChild(contextList);
				const contextNote = document.createElement('p');
				contextNote.className = 'extension-context-note';
				contextNote.textContent = 'Choose the region for your extension. A hotel is only required when you choose a region outside your current plan.';
				extensionContext.appendChild(contextNote);
				updateExtensionDeparture();
				extensionModal.classList.add('visible');
				extensionModal.setAttribute('aria-hidden', 'false');
				document.body.classList.add('extension-modal-open');
			}));
			extensionDays.addEventListener('input', updateExtensionDeparture);
			extensionRegion.addEventListener('change', updateExtensionRegionSelection);
			document.querySelectorAll('[data-cancellation-booking]').forEach((button) => button.addEventListener('click', () => {
				const bookingId = button.dataset.cancellationBooking;
				const packageTitle = button.dataset.cancellationTitle || 'travel booking';
				if (!window.confirm(`Are you sure you want to request cancellation for ${packageTitle}? Admin approval is required before any refund is processed.`)) return;
				const message = `Cancellation request for Booking #${bookingId} (${packageTitle}). Please review my request and advise me about the refund.`;
				window.location.href = `service.php?cancel_booking=${encodeURIComponent(bookingId)}&cancel_message=${encodeURIComponent(message)}`;
			}));
			const closeExtension = () => { extensionModal.classList.remove('visible'); extensionModal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('extension-modal-open'); };
			extensionCancel.addEventListener('click', closeExtension);
			extensionCloseButton.addEventListener('click', closeExtension);
			extensionModal.addEventListener('click', (event) => { if (event.target === extensionModal) closeExtension(); });
			const closeReceipt = () => { receiptModal.classList.remove('visible'); receiptModal.setAttribute('aria-hidden', 'true'); };
			receiptClose.addEventListener('click', closeReceipt);
			receiptModal.addEventListener('click', (event) => { if (event.target === receiptModal) closeReceipt(); });
			closeButton.addEventListener('click', closeModal);
			modal.addEventListener('click', (event) => {
				if (event.target === modal) closeModal();
			});
			document.addEventListener('keydown', (event) => {
				if (event.key !== 'Escape') return;
				if (modal.classList.contains('visible')) closeModal();
				if (receiptModal.classList.contains('visible')) closeReceipt();
				if (extensionModal.classList.contains('visible')) closeExtension();
			});

			document.querySelectorAll('.filter-button').forEach((button) => {
				button.addEventListener('click', () => {
					const filter = button.dataset.filter;
					let visibleCards = 0;
					document.querySelectorAll('.filter-button').forEach((item) => {
						const isActive = item === button;
						item.classList.toggle('active', isActive);
						item.setAttribute('aria-selected', String(isActive));
					});
					cards.forEach((card) => {
						const isVisible = filter === 'all' || card.dataset.status === filter;
						card.hidden = !isVisible;
						if (isVisible) visibleCards += 1;
					});
					emptyState.style.display = visibleCards ? 'none' : 'block';
				});
			});
		})();
	</script>
</body>
</html>
