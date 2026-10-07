<?php
require_once __DIR__ . '/../api/auth.php';
$userId = require_user_auth();
$database = require_once __DIR__ . '/../api/db_con.php';
require_once __DIR__ . '/../api/vehicle-availability.php';
mtrEnsureVehicleBookingColumns($database);

$bookingColumns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('departure_location', $bookingColumns, true)) {
	$database->exec("ALTER TABLE bookings ADD COLUMN departure_location VARCHAR(180) NULL");
}
foreach (['completion_proof' => 'VARCHAR(500) NULL', 'completion_submitted_at' => 'TIMESTAMP NULL', 'completion_verified_at' => 'TIMESTAMP NULL'] as $column => $definition) {
	if (!in_array($column, $bookingColumns, true)) {
		$database->exec("ALTER TABLE bookings ADD COLUMN {$column} {$definition}");
	}
}

$driverProfileStmt = $database->prepare('SELECT dp.Driver_Profile_ID, dp.is_available, dp.verification_status, cv.Company_Vehicle_ID AS company_vehicle_id, cv.vehicle_unit, cv.vehicle_color, cv.plate_number, cv.passenger_capacity FROM driver_profiles dp LEFT JOIN company_vehicles cv ON cv.Company_Vehicle_ID = dp.company_vehicle_id WHERE dp.User_ID = ? LIMIT 1');
$driverProfileStmt->execute([$userId]);
$driverProfile = $driverProfileStmt->fetch() ?: [];
$driverProfileId = (int) ($driverProfile['Driver_Profile_ID'] ?? 0);
$isAvailable = (bool) ($driverProfile['is_available'] ?? false);
$verificationStatus = (string) ($driverProfile['verification_status'] ?? 'pending');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finish_trip']) && $driverProfileId > 0) {
	$finishBookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
	$finishImage = $_FILES['completion_proof'] ?? null;
	$finishDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'trip-completions';
	if (!$finishBookingId || !$finishImage || $finishImage['error'] !== UPLOAD_ERR_OK) {
		http_response_code(400);
		exit('Upload a completion photo first.');
	}
	$finishCheck = $database->prepare("SELECT b.Booking_ID FROM bookings b INNER JOIN booking_assignments ba ON ba.Booking_ID = b.Booking_ID WHERE b.Booking_ID = ? AND ba.Driver_Profile_ID = ? AND b.booking_status = 'confirmed'");
	$finishCheck->execute([$finishBookingId, $driverProfileId]);
	if (!$finishCheck->fetchColumn()) {
		http_response_code(403);
		exit('This trip cannot be completed from this account.');
	}
	if ($finishImage['size'] > 8 * 1024 * 1024) {
		http_response_code(400);
		exit('The completion photo must be 8 MB or smaller.');
	}
	$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($finishImage['tmp_name']);
	$allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
	if (!isset($allowedTypes[$mimeType])) {
		http_response_code(400);
		exit('Upload a JPG, PNG, or WEBP photo.');
	}
	if (!is_dir($finishDirectory) && !mkdir($finishDirectory, 0750, true)) {
		http_response_code(500);
		exit('The completion photo folder could not be created.');
	}
	$fileName = 'booking-' . $finishBookingId . '-' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
	if (!move_uploaded_file($finishImage['tmp_name'], $finishDirectory . DIRECTORY_SEPARATOR . $fileName)) {
		http_response_code(500);
		exit('The completion photo could not be saved.');
	}
	$finishUpdate = $database->prepare("UPDATE bookings SET completion_proof = ?, completion_submitted_at = CURRENT_TIMESTAMP WHERE Booking_ID = ?");
	$finishUpdate->execute(['data/uploads/trip-completions/' . $fileName, $finishBookingId]);
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['availability']) && $driverProfileId > 0) {
	$isAvailable = (string) $_POST['availability'] === '1';
	$availabilityStmt = $database->prepare('UPDATE driver_profiles SET is_available = ? WHERE Driver_Profile_ID = ? AND User_ID = ?');
	$availabilityStmt->execute([$isAvailable ? 1 : 0, $driverProfileId, $userId]);
}

$assignments = [];
if ($driverProfileId > 0) {
	$assignmentStmt = $database->prepare(
		"SELECT b.Booking_ID AS id, b.Travel_Pck_ID, b.Hotel_ID, h.name AS hotel_name, h.location AS hotel_location, b.accommodation_type, b.accommodation_name, b.accommodation_address, b.accommodation_maps_link, b.travel_date, b.trip_duration_days, b.guest_count, b.total_amount, b.payment_status, b.booking_status, b.completion_proof, b.completion_submitted_at,
		        b.pickup_location, b.departure_location, b.pickup_address, b.pickup_notes,
		        b.custom_details, b.custom_duration_days, u.full_name AS customer_name, u.phone AS customer_phone,
		        COALESCE(p.name, NULLIF(b.custom_title, ''), 'Custom Trip') AS package_name,
		        COALESCE(NULLIF(b.custom_title, ''), p.name, 'Assigned trip') AS trip_title
		 FROM booking_assignments ba
		 INNER JOIN bookings b ON b.Booking_ID = ba.Booking_ID
		 INNER JOIN users u ON u.User_ID = b.Customer_ID
		 LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID
		 LEFT JOIN hotels h ON h.Hotel_ID = b.Hotel_ID
		 WHERE ba.Driver_Profile_ID = ? AND b.booking_status NOT IN ('cancelled', 'completed')
		 ORDER BY b.travel_date ASC, b.Booking_ID ASC"
	);
	$assignmentStmt->execute([$driverProfileId]);
	$assignments = $assignmentStmt->fetchAll();
}

$today = new DateTimeImmutable('today');
$assignmentDates = [];
foreach ($assignments as $index => $assignment) {
	$tripStart = new DateTimeImmutable($assignment['travel_date']);
	$tripDays = max(1, (int) ($assignment['trip_duration_days'] ?? 1));
	$tripEnd = $tripStart->modify('+' . ($tripDays - 1) . ' days');
	$assignments[$index]['trip_start'] = $tripStart;
	$assignments[$index]['trip_end'] = $tripEnd;
	$assignments[$index]['trip_state'] = $tripEnd < $today ? 'overdue' : ($tripStart <= $today ? 'ongoing' : 'upcoming');
	for ($tripDay = $tripStart; $tripDay <= $tripEnd; $tripDay = $tripDay->modify('+1 day')) {
		$assignmentDates[$tripDay->format('Y-m-d')][] = $assignments[$index];
	}
}

// Default to the trip in progress, then the next upcoming one, then the most recent unfinished one.
$selectedAssignment = null;
$requestedBookingId = filter_input(INPUT_GET, 'booking', FILTER_VALIDATE_INT);
foreach ($assignments as $assignment) {
	if ($requestedBookingId && (int) $assignment['id'] === $requestedBookingId) {
		$selectedAssignment = $assignment;
		break;
	}
}
if (!$selectedAssignment) {
	foreach (['ongoing', 'upcoming'] as $preferredState) {
		foreach ($assignments as $assignment) {
			if ($assignment['trip_state'] === $preferredState) {
				$selectedAssignment = $assignment;
				break 2;
			}
		}
	}
}
$selectedAssignment ??= $assignments ? end($assignments) : null;
$selectedDate = $selectedAssignment ? $selectedAssignment['trip_start']->format('Y-m-d') : $today->format('Y-m-d');
if ($selectedAssignment && $selectedAssignment['trip_start'] <= $today && $today <= $selectedAssignment['trip_end']) {
	$selectedDate = $today->format('Y-m-d');
}
$monthParam = (string) ($_GET['month'] ?? '');
$calendarMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam) ? new DateTimeImmutable($monthParam . '-01') : new DateTimeImmutable($selectedDate);
$calendarMonth = $calendarMonth->modify('first day of this month');
$monthStartDay = (int) $calendarMonth->format('N');
$daysInMonth = (int) $calendarMonth->format('t');
$tripPageUrl = static fn (array $params) => '?' . http_build_query(array_filter($params, static fn ($value) => $value !== null));
$guestPhone = trim((string) ($selectedAssignment['customer_phone'] ?? ''));
$guestWhatsappNumber = preg_replace('/\D+/', '', $guestPhone);
if (str_starts_with($guestWhatsappNumber, '0')) {
	$guestWhatsappNumber = '63' . substr($guestWhatsappNumber, 1);
}
$pickupLocation = trim((string) ($selectedAssignment['pickup_location'] ?? ''));
$departureLocation = trim((string) ($selectedAssignment['departure_location'] ?? ''));
$hasPickupLocation = $pickupLocation !== '';
$hasDepartureLocation = $departureLocation !== '';
$pickupLabel = $hasPickupLocation ? $pickupLocation : 'No pick up included';
$departureLabel = $hasDepartureLocation ? $departureLocation : 'No departure included';
$pickupAddress = trim((string) ($selectedAssignment['pickup_address'] ?? ''));
$pickupNotes = trim((string) ($selectedAssignment['pickup_notes'] ?? ''));
$pickupMapQuery = rawurlencode(trim($pickupAddress ?: $pickupLocation));
$departureMapQuery = rawurlencode($departureLocation);
$hotelName = trim((string) ($selectedAssignment['hotel_name'] ?? ''));
$hotelLocation = trim((string) ($selectedAssignment['hotel_location'] ?? ''));
$customAccommodationName = trim((string) ($selectedAssignment['accommodation_name'] ?? ''));
$customAccommodationAddress = trim((string) ($selectedAssignment['accommodation_address'] ?? ''));
$accommodationMapsLink = trim((string) ($selectedAssignment['accommodation_maps_link'] ?? ''));
if ($hotelName === '' && $customAccommodationName !== '') {
	$hotelName = $customAccommodationName;
}
if ($customAccommodationAddress !== '') {
	$hotelLocation = $customAccommodationAddress;
}
$hasHotel = $hotelName !== '' || $hotelLocation !== '';
$hotelLabel = $hasHotel ? trim($hotelName . ($hotelLocation ? ' · ' . $hotelLocation : '')) : 'No accommodation provided';
$destinationStops = [];

if ($selectedAssignment) {
	$bookingDestinationStatement = $database->prepare('SELECT d.name, d.location, d.region FROM booking_destinations bd INNER JOIN destinations d ON d.Destination_ID = bd.Destination_ID WHERE bd.Booking_ID = ? ORDER BY bd.sort_order, d.name');
	$bookingDestinationStatement->execute([(int) $selectedAssignment['id']]);
	$destinationStops = $bookingDestinationStatement->fetchAll(PDO::FETCH_ASSOC);
}
if (!$destinationStops && $selectedAssignment && !empty($selectedAssignment['Travel_Pck_ID'])) {
	$destinationStatement = $database->prepare('SELECT d.name, d.location, d.region FROM travel_package_destinations pd INNER JOIN destinations d ON d.Destination_ID = pd.Destination_ID WHERE pd.Travel_Pck_ID = ? ORDER BY d.name');
	$destinationStatement->execute([(int) $selectedAssignment['Travel_Pck_ID']]);
	$destinationStops = $destinationStatement->fetchAll(PDO::FETCH_ASSOC);
	if (!$destinationStops) {
		$legacyDestinationStatement = $database->prepare('SELECT d.name, d.location, d.region FROM travel_packages p INNER JOIN destinations d ON d.Destination_ID = p.Destination_ID WHERE p.Travel_Pck_ID = ?');
		$legacyDestinationStatement->execute([(int) $selectedAssignment['Travel_Pck_ID']]);
		$destinationStops = $legacyDestinationStatement->fetchAll(PDO::FETCH_ASSOC);
	}
}
if (!$destinationStops && $selectedAssignment) {
	$customDetails = trim((string) ($selectedAssignment['custom_details'] ?? ''));
	if (preg_match('/(?:\d+\s+)?destinations?\s*·\s*(.*?)(?:\s*·\s*Hotel:|$)/i', $customDetails, $destinationMatch)) {
		$customDestinationNames = array_values(array_filter(array_map('trim', explode(',', $destinationMatch[1]))));
		if ($customDestinationNames) {
			$destinationPlaceholders = implode(',', array_fill(0, count($customDestinationNames), '?'));
			$customDestinationStatement = $database->prepare("SELECT name, location, region FROM destinations WHERE name IN ({$destinationPlaceholders})");
			$customDestinationStatement->execute($customDestinationNames);
			$destinationRowsByName = [];
			foreach ($customDestinationStatement->fetchAll(PDO::FETCH_ASSOC) as $destinationRow) {
				$destinationRowsByName[strtolower(trim($destinationRow['name']))] = $destinationRow;
			}
			foreach ($customDestinationNames as $customDestinationName) {
				$destinationKey = strtolower($customDestinationName);
				if (isset($destinationRowsByName[$destinationKey])) {
					$destinationStops[] = $destinationRowsByName[$destinationKey];
				}
			}
		}
	}
}
$routeStops = [];
if ($selectedAssignment && !empty($selectedAssignment['Travel_Pck_ID'])) {
	$routeStatement = $database->prepare('SELECT day_number, title, activity FROM travel_package_itinerary WHERE Travel_Pck_ID = ? ORDER BY day_number');
	$routeStatement->execute([$selectedAssignment['Travel_Pck_ID']]);
	$routeStops = $routeStatement->fetchAll();
}
$paidExtensionStatement = $selectedAssignment ? $database->prepare("SELECT extra_days, request_details FROM booking_extension_requests WHERE Booking_ID = ? AND status = 'paid' ORDER BY paid_at ASC") : null;
if ($paidExtensionStatement) {
	$paidExtensionStatement->execute([(int) $selectedAssignment['id']]);
	$nextRouteDay = $routeStops ? max(array_map(static fn ($stop) => (int) $stop['day_number'], $routeStops)) + 1 : 1;
	$knownDestinationNames = array_fill_keys(array_map(static fn ($stop) => strtolower(trim((string) ($stop['name'] ?? ''))), $destinationStops), true);
	foreach ($paidExtensionStatement->fetchAll(PDO::FETCH_ASSOC) as $paidExtension) {
		$requestDetails = trim((string) ($paidExtension['request_details'] ?? ''));
		if (preg_match('/(?:^|\R)Requested destinations:\s*(.+)/i', $requestDetails, $destinationMatch)) {
			$requestedNames = array_values(array_filter(array_map('trim', explode(',', $destinationMatch[1]))));
			if ($requestedNames) {
				$destinationPlaceholders = implode(',', array_fill(0, count($requestedNames), '?'));
				$extensionDestinationStatement = $database->prepare("SELECT name, location, region FROM destinations WHERE name IN ({$destinationPlaceholders})");
				$extensionDestinationStatement->execute($requestedNames);
				foreach ($extensionDestinationStatement->fetchAll(PDO::FETCH_ASSOC) as $extensionDestination) {
					$destinationKey = strtolower(trim((string) $extensionDestination['name']));
					if ($destinationKey !== '' && !isset($knownDestinationNames[$destinationKey])) {
						$destinationStops[] = $extensionDestination;
						$knownDestinationNames[$destinationKey] = true;
					}
				}
			}
		}
		if (preg_match('/(?:^|\R)Other location:\s*(.+)/i', $requestDetails, $otherLocationMatch)) {
			$otherLocation = trim($otherLocationMatch[1]);
			$destinationKey = strtolower($otherLocation);
			if ($otherLocation !== '' && !isset($knownDestinationNames[$destinationKey])) {
				$destinationStops[] = ['name' => $otherLocation, 'location' => $otherLocation, 'region' => ''];
				$knownDestinationNames[$destinationKey] = true;
			}
		}
		$routeStops[] = ['day_number' => $nextRouteDay, 'title' => 'Approved extension · ' . (int) $paidExtension['extra_days'] . ' extra day(s)', 'activity' => trim((string) ($paidExtension['request_details'] ?? 'Additional activities approved by admin.'))];
		$nextRouteDay += (int) $paidExtension['extra_days'];
	}
}
$mapStopOne = $routeStops[0]['title'] ?? 'Day 1 itinerary';
$mapStopTwo = $routeStops[1]['title'] ?? 'Day 2 itinerary';
$esc = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$driverName = trim((string) ($_SESSION['user_name'] ?? 'Driver'));
$driverInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $driverName), 0, 2)) ?: 'DR';
$calendarCells = [];
for ($day = 1 - ($monthStartDay - 1); $day <= $daysInMonth + (7 - (($daysInMonth + $monthStartDay - 1) % 7)); $day++) {
	$calendarCells[] = $day < 1 || $day > $daysInMonth ? null : $calendarMonth->setDate((int) $calendarMonth->format('Y'), (int) $calendarMonth->format('m'), $day);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Driver Dashboard | Mindanao Travel & Tours</title>
	<style>
		:root { --bg:#f0faff; --card:#fff; --ink:#0f1724; --muted:#64748b; --line:#dce8ef; --accent:#0ea5e9; --accent-dark:#0369a1; --soft:#e6f9ff; --green:#047857; --orange:#c2410c; --shadow:0 18px 45px rgba(2,6,23,.07); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
		* { box-sizing:border-box; }
		body { min-height:100vh; margin:0; background:radial-gradient(circle at 90% 0,#d8f4ff 0,transparent 34%),var(--bg); color:var(--ink); }
		a { color:inherit; text-decoration:none; } button { font:inherit; cursor:pointer; }
		.page { width:min(1160px,calc(100% - 40px)); margin:auto; padding:26px 0 55px; }
		.topbar { display:flex; align-items:center; justify-content:space-between; gap:22px; margin-bottom:46px; }.brand { display:flex; align-items:center; gap:12px; }.logo { display:grid; width:44px; height:44px; place-items:center; overflow:hidden; border:1px solid rgba(14,165,233,.14); border-radius:12px; background:#fff; box-shadow:0 8px 20px rgba(2,6,23,.05); }.logo img { width:100%; height:100%; object-fit:contain; }.brand strong,.brand small { display:block; }.brand strong { font-size:15px; }.brand small { margin-top:3px; color:var(--muted); font-size:12px; }.top-actions { display:flex; align-items:center; gap:12px; }.back-link { color:var(--accent-dark); font-size:13px; font-weight:700; }.back-link:hover { text-decoration:underline; }.avatar { display:grid; width:40px; height:40px; place-items:center; border-radius:50%; background:linear-gradient(135deg,#0ea5e9,#0369a1); color:#fff; font-size:12px; font-weight:800; }
		.intro { display:flex; align-items:flex-end; justify-content:space-between; gap:25px; margin-bottom:26px; }.eyebrow { margin:0 0 8px; color:var(--accent-dark); font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }.intro h1 { margin:0; font-size:clamp(31px,4vw,47px); letter-spacing:-1px; line-height:1.05; }.intro p { max-width:610px; margin:11px 0 0; color:var(--muted); line-height:1.55; }.availability { display:inline-flex; align-items:center; gap:8px; min-height:42px; padding:0 13px; border:1px solid #a7e6c1; border-radius:9px; background:#ecfdf5; color:var(--green); font-size:12px; font-weight:800; white-space:nowrap; }.availability::before { width:7px; height:7px; border-radius:50%; background:#10b981; content:""; }.availability.offline { border-color:#fed7aa; background:#fff7ed; color:var(--orange); }.availability.offline::before { background:#f97316; }
		.dashboard-grid { display:grid; grid-template-columns:minmax(0,1.4fr) minmax(280px,.75fr); gap:22px; align-items:start; }.card { border:1px solid var(--line); border-radius:14px; background:var(--card); box-shadow:var(--shadow); }.assignment { overflow:hidden; }.assignment-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:22px; border-bottom:1px solid var(--line); }.assignment-head h2 { margin:0; font-size:19px; }.assignment-head p { margin:5px 0 0; color:var(--muted); font-size:12px; }.paid-badge { padding:6px 9px; border-radius:5px; background:#ecfdf5; color:var(--green); font-size:10px; font-weight:800; white-space:nowrap; }.guest-profile { display:flex; align-items:center; gap:13px; padding:20px 22px 16px; }.guest-avatar { display:grid; width:52px; height:52px; place-items:center; border-radius:50%; background:#dff5ff; color:var(--accent-dark); font-size:16px; font-weight:800; }.guest-profile h3 { margin:0 0 4px; font-size:17px; }.guest-profile p { margin:0; color:var(--muted); font-size:12px; }.trip-meta { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; padding:0 22px 22px; }.meta-item { padding:12px; border-radius:8px; background:#f7fcfe; }.meta-item span,.meta-item strong { display:block; }.meta-item span { color:var(--muted); font-size:10px; }.meta-item strong { margin-top:4px; color:var(--accent-dark); font-size:13px; }.assignment-actions { display:flex; gap:9px; padding:0 22px 22px; }.button { display:inline-flex; min-height:40px; align-items:center; justify-content:center; padding:0 13px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:800; }.button:hover { border-color:var(--accent); background:var(--soft); }.button.primary { border-color:var(--accent); background:var(--accent); color:#fff; }.button.primary:hover { background:var(--accent-dark); }.button.primary.started { border-color:var(--green); background:var(--green); }
		.trips-card { padding:22px; }.trips-card h2 { margin:0 0 14px; font-size:18px; }.trip-list { display:grid; gap:8px; }.trip-item { display:flex; align-items:center; gap:14px; padding:12px; border:1px solid var(--line); border-radius:10px; background:#f7fcfe; }.trip-item:hover,.trip-item.selected { border-color:var(--accent); background:var(--soft); }.trip-dates { min-width:88px; color:var(--accent-dark); font-size:12px; font-weight:800; }.trip-info { flex:1; min-width:0; }.trip-info strong,.trip-info small { display:block; }.trip-info strong { font-size:13px; }.trip-info small { margin-top:3px; color:var(--muted); font-size:11px; }.trip-state { padding:4px 9px; border-radius:6px; background:#e0f2fe; color:var(--accent-dark); font-size:10px; font-weight:800; text-transform:uppercase; white-space:nowrap; }.trip-state.ongoing { background:#ecfdf5; color:var(--green); }.trip-state.overdue { background:#fff7ed; color:var(--orange); }.calendar-nav { display:flex; gap:6px; margin-left:auto; }.calendar-nav a { display:grid; width:28px; height:28px; place-items:center; border:1px solid var(--line); border-radius:8px; color:var(--accent-dark); font-weight:800; }.calendar-nav a:hover { background:var(--soft); }.schedule-item { color:inherit; }
		.calendar-card { padding:22px; }.calendar-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:18px; }.calendar-head h2 { margin:0; font-size:18px; }.calendar-head p { margin:5px 0 0; color:var(--muted); font-size:12px; }.calendar-legend { display:flex; align-items:center; gap:6px; color:var(--muted); font-size:11px; white-space:nowrap; }.legend-dot { width:8px; height:8px; border-radius:50%; background:var(--accent); }.calendar-week,.calendar-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }.calendar-week { margin-bottom:7px; color:var(--muted); font-size:10px; font-weight:800; text-align:center; }.calendar-day { position:relative; min-height:52px; padding:8px 5px; border:1px solid transparent; border-radius:8px; background:#f7fcfe; color:var(--ink); font-size:12px; text-align:center; }.calendar-day.empty { background:transparent; }.calendar-day.has-assignment { border-color:#a7e6c1; background:#ecfdf5; color:var(--green); font-weight:800; cursor:pointer; }.calendar-day.has-assignment:hover,.calendar-day.selected { border-color:var(--accent); background:var(--soft); color:var(--accent-dark); }.calendar-day.today { box-shadow:inset 0 0 0 2px #bae6fd; }.calendar-day .day-dot { position:absolute; right:7px; bottom:6px; width:5px; height:5px; border-radius:50%; background:var(--accent); }.schedule-list { display:grid; gap:8px; margin-top:17px; }.schedule-item { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:11px 12px; border-left:3px solid var(--accent); border-radius:7px; background:#f7fcfe; }.schedule-item strong,.schedule-item span { display:block; }.schedule-item strong { font-size:12px; }.schedule-item span { margin-top:3px; color:var(--muted); font-size:10px; }.schedule-empty { margin:17px 0 0; color:var(--muted); font-size:12px; }.approval-card { max-width:640px; margin:12vh auto 0; padding:34px; text-align:center; }.approval-icon { display:grid; width:58px; height:58px; place-items:center; margin:0 auto 18px; border-radius:50%; background:var(--soft); color:var(--accent-dark); font-size:26px; }.approval-card h1 { margin:0; font-size:28px; }.approval-card p { margin:12px auto 0; color:var(--muted); line-height:1.6; }.approval-status { display:inline-flex; margin-top:22px; padding:8px 12px; border-radius:6px; background:#fff7ed; color:var(--orange); font-size:11px; font-weight:800; text-transform:uppercase; }.approval-status.rejected { background:#fef2f2; color:#b91c1c; }.approval-actions { display:flex; justify-content:center; margin-top:24px; }
		.location-panel { display:flex; align-items:center; justify-content:space-between; gap:16px; margin:0 22px 12px; padding:14px; border:1px solid #cceaf3; border-radius:10px; background:linear-gradient(135deg,#f7fdff,#eefaff); }.location-panel:last-child { margin-bottom:22px; }.location-panel > div { min-width:0; }.location-panel span { display:block; margin-bottom:5px; color:var(--accent-dark); font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }.location-panel strong { display:block; font-size:13px; overflow-wrap:anywhere; }.location-panel small { display:block; margin-top:4px; color:var(--muted); font-size:11px; }.location-panel.missing { border-color:#fed7aa; background:#fffaf5; }.location-panel.missing span,.location-panel.missing strong { color:var(--orange); }.location-panel .button[aria-disabled="true"] { border-color:var(--line); background:#f1f5f9; color:var(--muted); cursor:not-allowed; pointer-events:none; }.route-card { padding:22px; }.section-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:15px; margin-bottom:20px; }.section-heading h2 { margin:0; font-size:18px; }.section-heading p { margin:5px 0 0; color:var(--muted); font-size:12px; }.route-list { display:grid; gap:0; }.route-stop { position:relative; display:grid; grid-template-columns:28px minmax(0,1fr) auto; gap:11px; padding-bottom:22px; }.route-stop:last-child { padding-bottom:0; }.route-stop:not(:last-child)::before { position:absolute; top:22px; bottom:0; left:8px; width:1px; background:#c9e5ef; content:""; }.stop-dot { position:relative; z-index:1; display:grid; width:17px; height:17px; place-items:center; border:4px solid #dff5ff; border-radius:50%; background:var(--accent); }.route-stop:last-child .stop-dot { background:#fff; border:2px solid var(--accent); }.route-stop strong { display:block; font-size:13px; }.route-stop span { display:block; margin-top:4px; color:var(--muted); font-size:11px; line-height:1.4; }.stop-time { color:var(--accent-dark)!important; font-weight:800; white-space:nowrap; }.route-stop-action { min-width:104px; min-height:34px; padding:0 10px; border:1px solid var(--accent); border-radius:7px; background:var(--soft); color:var(--accent-dark); font-size:11px; font-weight:800; }.route-stop-action:hover { background:#d6f3ff; }.route-stop-action.started { border-color:var(--green); background:#ecfdf5; color:var(--green); }.route-stop-action:disabled { border-color:var(--line); background:#f1f5f9; color:var(--muted); cursor:not-allowed; }
		.finish-trip-card { margin-top:22px; padding:22px; }.finish-trip-card h2 { margin:0; font-size:18px; }.finish-trip-card p { margin:6px 0 14px; color:var(--muted); font-size:12px; line-height:1.5; }.finish-trip-form { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }.finish-trip-form input[type=file] { min-width:220px; padding:8px; border:1px solid var(--line); border-radius:8px; background:#fff; font-size:11px; }.finish-trip-form .button { margin:0; }.completion-pending { color:var(--orange)!important; font-weight:800; }
		.map-card { position:relative; min-height:300px; overflow:hidden; border:1px solid var(--line); border-radius:14px; background:#dff3f5; box-shadow:var(--shadow); }.map-card::before { position:absolute; inset:0; background:linear-gradient(28deg,transparent 24%,rgba(255,255,255,.7) 24.5%,rgba(255,255,255,.7) 25.5%,transparent 26%),linear-gradient(112deg,transparent 48%,rgba(255,255,255,.65) 48.5%,rgba(255,255,255,.65) 49.5%,transparent 50%),repeating-linear-gradient(8deg,transparent 0 38px,rgba(126,190,198,.18) 39px 40px); content:""; }.map-water { position:absolute; top:-45px; right:-45px; width:52%; height:72%; border-radius:48% 0 0 55%; background:rgba(91,190,211,.32); transform:rotate(8deg); }.map-route { position:absolute; inset:0; }.route-line-map { position:absolute; top:38%; left:20%; width:57%; height:34%; border:4px solid var(--accent); border-top-color:transparent; border-left-color:transparent; border-radius:0 0 80% 0; transform:rotate(-18deg); filter:drop-shadow(0 3px 2px rgba(3,105,161,.2)); }.map-marker { position:absolute; z-index:1; display:grid; width:34px; height:34px; place-items:center; border:4px solid #fff; border-radius:50% 50% 50% 0; background:var(--accent); color:#fff; font-size:11px; font-weight:800; box-shadow:0 5px 12px rgba(2,6,23,.18); transform:rotate(-45deg); }.map-marker span { transform:rotate(45deg); }.marker-start { top:24%; left:14%; }.marker-stop { top:38%; left:43%; }.marker-island { top:57%; left:67%; }.marker-end { right:12%; bottom:16%; background:var(--green); }.map-label { position:absolute; z-index:2; padding:6px 8px; border:1px solid rgba(255,255,255,.8); border-radius:6px; background:rgba(255,255,255,.92); color:var(--ink); font-size:10px; font-weight:800; box-shadow:0 3px 10px rgba(2,6,23,.08); }.label-start { top:17%; left:7%; }.label-cloud { top:33%; left:36%; }.label-island { top:52%; left:61%; }.label-end { right:6%; bottom:10%; }.map-overlay { position:absolute; z-index:3; right:16px; bottom:15px; left:16px; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:11px 12px; border:1px solid rgba(255,255,255,.7); border-radius:9px; background:rgba(255,255,255,.9); box-shadow:0 5px 16px rgba(2,6,23,.08); }.map-overlay strong,.map-overlay span { display:block; }.map-overlay strong { font-size:12px; }.map-overlay span { margin-top:3px; color:var(--muted); font-size:10px; }.map-link { display:inline-flex; min-height:33px; align-items:center; padding:0 10px; border-radius:7px; background:var(--accent); color:#fff; font-size:11px; font-weight:800; white-space:nowrap; }.map-link:hover { background:var(--accent-dark); }
		.side-stack { display:grid; gap:22px; }.vehicle-card,.support-card { padding:22px; }.vehicle-card h2,.support-card h2 { margin:0; font-size:18px; }.vehicle-card > p,.support-card > p { margin:6px 0 18px; color:var(--muted); font-size:12px; line-height:1.5; }.vehicle { display:flex; align-items:center; gap:13px; padding:13px; border-radius:9px; background:#f7fcfe; }.vehicle-icon { display:grid; width:42px; height:42px; place-items:center; border-radius:9px; background:var(--soft); color:var(--accent-dark); font-size:21px; }.vehicle strong,.vehicle small { display:block; }.vehicle strong { font-size:13px; }.vehicle small { margin-top:4px; color:var(--muted); font-size:11px; }.capacity { margin-top:17px; }.capacity-label { display:flex; justify-content:space-between; gap:10px; margin-bottom:7px; color:var(--muted); font-size:11px; }.capacity-label strong { color:var(--ink); }.capacity-track { height:7px; overflow:hidden; border-radius:99px; background:#e5f3f8; }.capacity-track span { display:block; width:17%; height:100%; border-radius:inherit; background:var(--accent); }.support-link { display:flex; align-items:center; gap:10px; padding:11px 0; border-top:1px solid var(--line); color:var(--accent-dark); font-size:12px; font-weight:700; }.support-link:first-of-type { border-top:0; }.support-link span { color:var(--muted); font-size:11px; font-weight:400; }.toast { position:fixed; right:22px; bottom:22px; padding:12px 16px; border-radius:8px; background:var(--ink); color:#fff; font-size:12px; opacity:0; pointer-events:none; transform:translateY(12px); transition:opacity .2s,transform .2s; }.toast.show { opacity:1; transform:translateY(0); }
		@media (max-width:820px) { .intro { align-items:flex-start; flex-direction:column; }.dashboard-grid { grid-template-columns:1fr; }.side-stack { grid-template-columns:1fr 1fr; } }
		@media (max-width:560px) { .page { width:min(100% - 28px,520px); padding-top:20px; }.topbar { align-items:flex-start; margin-bottom:36px; }.top-actions { gap:9px; }.back-link { font-size:12px; }.intro h1 { font-size:34px; }.side-stack { grid-template-columns:1fr; }.assignment-head { flex-direction:column; }.trip-meta { grid-template-columns:1fr; }.assignment-actions { flex-direction:column; }.button { width:100%; }.location-panel { align-items:stretch; flex-direction:column; }.route-stop { grid-template-columns:25px minmax(0,1fr); }.stop-time,.route-stop-action { grid-column:2; }.route-stop-action { width:100%; } }
	</style>
</head>
<body>
	<main class="page">
		<?php if ($verificationStatus !== 'approved'): ?>
			<section class="card approval-card" aria-labelledby="approvalTitle">
				<div class="approval-icon"><?= $verificationStatus === 'rejected' ? '!' : '...' ?></div>
				<h1 id="approvalTitle"><?= $verificationStatus === 'rejected' ? 'Driver application rejected' : 'Driver application waiting for approval' ?></h1>
				<span class="approval-status<?= $verificationStatus === 'rejected' ? ' rejected' : '' ?>"><?= $esc($verificationStatus) ?> by admin</span>
				<?php if ($verificationStatus === 'rejected'): ?><p>Your driver application was rejected by the admin. To re-apply or clarify your documents, please visit the MTR office and speak with the operations team.</p><?php else: ?><p>Your driver application is still being reviewed by the admin. Your driver schedule and trip assignments will become available after your application is approved.</p><?php endif; ?>
				<div class="approval-actions"><a class="button" href="../user/logout.php">Log out</a></div>
			</section>
		<?php else: ?>
		<header class="topbar">
			<a class="brand" href="../user/dashboard.html"><span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span><span><strong>Mindanao Travel & Tours</strong><small>Driver partner portal</small></span></a>
			<div class="top-actions"><a class="back-link" href="../user/login.php">Log out</a><div class="avatar"><?= $esc($driverInitials) ?></div></div>
		</header>

		<section class="intro"><div><p class="eyebrow">Driver dashboard</p><h1>Good morning, <?= $esc($driverName) ?>.</h1><p>Here is everything you need for your guest assignments. Keep your schedule updated so the MTR team can coordinate smoothly.</p></div><button class="availability<?= $isAvailable ? '' : ' offline' ?>" id="availabilityButton" type="button" aria-pressed="<?= $isAvailable ? 'true' : 'false' ?>"><?= $isAvailable ? 'Available for trips' : 'Unavailable for trips' ?></button></section>

		<div class="dashboard-grid">
			<div class="main-stack">
				<?php if (count($assignments) > 1): ?>
				<section class="card trips-card" aria-labelledby="tripsTitle">
					<h2 id="tripsTitle">My trips</h2>
					<div class="trip-list">
						<?php foreach ($assignments as $trip): $isSelectedTrip = (int) $trip['id'] === (int) $selectedAssignment['id']; ?>
							<a class="trip-item<?= $isSelectedTrip ? ' selected' : '' ?>" href="<?= $esc($tripPageUrl(['booking' => (int) $trip['id']])) ?>"<?= $isSelectedTrip ? ' aria-current="true"' : '' ?>>
								<span class="trip-dates"><?= $esc($trip['trip_start']->format('M j')) ?> – <?= $esc($trip['trip_end']->format('M j')) ?></span>
								<span class="trip-info"><strong><?= $esc($trip['customer_name']) ?></strong><small><?= $esc($trip['package_name']) ?> · <?= $esc($trip['guest_count']) ?> travelers</small></span>
								<span class="trip-state <?= $esc($trip['trip_state']) ?>"><?= $esc($trip['trip_state'] === 'overdue' ? 'Awaiting completion' : ucfirst($trip['trip_state'])) ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
				<?php endif; ?>
				<section class="card assignment" aria-labelledby="assignmentTitle">
					<?php if ($selectedAssignment): ?>
						<?php $assignmentDate = $selectedAssignment['trip_start']; $assignmentEndDate = $selectedAssignment['trip_end']; ?>
						<div class="assignment-head"><div><h2 id="assignmentTitle"><?= $selectedAssignment['trip_state'] === 'ongoing' ? 'Current assignment' : ($selectedAssignment['trip_state'] === 'overdue' ? 'Assignment awaiting completion' : 'Upcoming assignment') ?></h2><p><?= $esc($assignmentDate->format('l, F j, Y')) ?> · Booking #<?= $esc($selectedAssignment['id']) ?></p></div><span class="paid-badge"><?= $esc(ucfirst($selectedAssignment['payment_status'])) ?> booking</span></div>
						<div class="guest-profile"><div class="guest-avatar"><?= $esc(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $selectedAssignment['customer_name']), 0, 2))) ?></div><div><h3><?= $esc($selectedAssignment['customer_name']) ?></h3><p><?= $esc($selectedAssignment['guest_count']) ?> travelers · <?= $esc($selectedAssignment['package_name']) ?></p></div></div>
						<div class="trip-meta"><div class="meta-item"><span>Travel dates</span><strong><?= $esc($assignmentDate->format('M j')) ?> – <?= $esc($assignmentEndDate->format('M j, Y')) ?></strong></div><div class="meta-item"><span>Guest contact</span><?php if ($guestWhatsappNumber): ?><strong><a class="guest-contact" href="whatsapp://send?phone=<?= $esc($guestWhatsappNumber) ?>"><?= $esc($guestPhone) ?></a></strong><?php else: ?><strong>Not provided</strong><?php endif; ?></div></div>
						<div class="location-panel<?= $hasPickupLocation ? '' : ' missing' ?>"><div><span>Pick up location</span><strong><?= $esc($pickupLabel) ?></strong><?php if ($pickupAddress): ?><small><?= $esc($pickupAddress) ?></small><?php endif; ?><?php if ($pickupNotes): ?><small>Note: <?= $esc($pickupNotes) ?></small><?php endif; ?></div><?php if ($hasPickupLocation): ?><button class="button route-stop-action" type="button" data-route-destination="<?= $esc($pickupAddress ?: $pickupLocation) ?>">Start route</button><?php else: ?><span class="button" aria-disabled="true">No location</span><?php endif; ?></div>
						<div class="location-panel<?= $hasDepartureLocation ? '' : ' missing' ?>"><div><span>Departure location</span><strong><?= $esc($departureLabel) ?></strong></div><?php if ($hasDepartureLocation): ?><button class="button route-stop-action" type="button" data-route-destination="<?= $esc($departureLocation) ?>">Start route</button><?php else: ?><span class="button" aria-disabled="true">No location</span><?php endif; ?></div>
						<div class="location-panel<?= $hasHotel ? '' : ' missing' ?>"><div><span>Guest hotel</span><strong><?= $esc($hotelLabel) ?></strong></div><?php if ($hasHotel): ?><button class="button route-stop-action" type="button" data-route-destination="<?= $esc($hotelLocation ?: $hotelName) ?>"<?= $accommodationMapsLink ? ' data-route-link="' . $esc($accommodationMapsLink) . '"' : '' ?>>Start route</button><?php else: ?><span class="button" aria-disabled="true">No hotel</span><?php endif; ?></div>
						<div class="assignment-actions"><?php if ($guestWhatsappNumber): ?><a class="button" href="whatsapp://send?phone=<?= $esc($guestWhatsappNumber) ?>">WhatsApp guest</a><?php endif; ?></div>
					<?php else: ?>
						<div class="assignment-head"><div><h2 id="assignmentTitle">No active assignments</h2><p>Your schedule will appear here when operations assigns a booking.</p></div></div>
					<?php endif; ?>
				</section>

				<section class="card calendar-card" aria-labelledby="scheduleTitle">
					<div class="calendar-head"><div><h2 id="scheduleTitle">Driver schedule</h2><p><?= $esc($calendarMonth->format('F Y')) ?> · Select a marked date to view assignments</p></div><span class="calendar-nav"><a href="<?= $esc($tripPageUrl(['booking' => $selectedAssignment ? (int) $selectedAssignment['id'] : null, 'month' => $calendarMonth->modify('-1 month')->format('Y-m')])) ?>" aria-label="Previous month">‹</a><a href="<?= $esc($tripPageUrl(['booking' => $selectedAssignment ? (int) $selectedAssignment['id'] : null, 'month' => $calendarMonth->modify('+1 month')->format('Y-m')])) ?>" aria-label="Next month">›</a></span><span class="calendar-legend"><span class="legend-dot"></span> Assigned</span></div>
					<div class="calendar-week"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
					<div class="calendar-grid">
						<?php foreach ($calendarCells as $calendarDay): ?>
							<?php if (!$calendarDay): ?><span class="calendar-day empty"></span><?php else: $dateKey = $calendarDay->format('Y-m-d'); $hasAssignment = isset($assignmentDates[$dateKey]); ?>
								<button class="calendar-day<?= $hasAssignment ? ' has-assignment' : '' ?><?= $dateKey === $selectedDate ? ' selected' : '' ?><?= $dateKey === $today->format('Y-m-d') ? ' today' : '' ?>" type="button" data-date="<?= $esc($dateKey) ?>"<?= $hasAssignment ? '' : ' disabled' ?>><?= $esc($calendarDay->format('j')) ?><?= $hasAssignment ? '<span class="day-dot"></span>' : '' ?></button>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<div class="schedule-list" id="scheduleList">
						<?php foreach ($assignmentDates[$selectedDate] ?? [] as $scheduleAssignment): ?><a class="schedule-item" href="<?= $esc($tripPageUrl(['booking' => (int) $scheduleAssignment['id'], 'month' => $calendarMonth->format('Y-m')])) ?>"><div><strong><?= $esc($scheduleAssignment['package_name']) ?></strong><span><?= $esc($scheduleAssignment['customer_name']) ?> · <?= $esc($scheduleAssignment['guest_count']) ?> travelers</span></div><strong><?= $esc(ucfirst($scheduleAssignment['booking_status'])) ?></strong></a><?php endforeach; ?>
					</div>
					<p class="schedule-empty" id="scheduleEmpty"<?= isset($assignmentDates[$selectedDate]) ? ' hidden' : '' ?>>No assignments on this date.</p>
				</section>

				<section class="card route-card">
					<?php if ($selectedAssignment): ?>
						<div class="section-heading"><div><h2>Trip route</h2><p>Open directions from your current location to each destination or hotel.</p></div><span class="paid-badge"><?= (int) (count($destinationStops) + ($hasHotel ? 1 : 0)) ?> stops</span></div>
						<div class="route-list"><?php if ($hasHotel): ?><div class="route-stop"><span class="stop-dot"></span><div><strong><?= $esc($hotelName ?: $hotelLocation) ?></strong></div><button class="route-stop-action" type="button" data-route-destination="<?= $esc($hotelLocation ?: $hotelName) ?>">Open route</button></div><?php endif; ?><?php foreach ($destinationStops as $destinationStop): ?><div class="route-stop"><span class="stop-dot"></span><div><strong><?= $esc($destinationStop['name']) ?></strong></div><button class="route-stop-action" type="button" data-route-destination="<?= $esc($destinationStop['location'] ?: $destinationStop['name']) ?>">Open route</button></div><?php endforeach; ?></div>
					<?php else: ?>
						<div class="section-heading"><div><h2>Trip route</h2><p>No route is available yet.</p></div><span class="paid-badge">Not assigned</span></div>
						<p class="schedule-empty">You will see the planned destinations and hotel here after operations assigns you a trip.</p>
					<?php endif; ?>
				</section>
				<?php if ($selectedAssignment): ?>
				<section class="card finish-trip-card">
					<h2>Finish trip</h2>
					<?php if (!empty($selectedAssignment['completion_proof'])): ?>
						<p class="completion-pending">Completion proof submitted. Waiting for admin verification.</p>
					<?php else: ?>
						<p>Upload a photo showing the guest at the departure airport, port, or final drop-off point. Admin will verify the trip before it is marked completed.</p>
						<form class="finish-trip-form" method="POST" enctype="multipart/form-data"><input type="hidden" name="finish_trip" value="1"><input type="hidden" name="booking_id" value="<?= (int) $selectedAssignment['id'] ?>"><input type="file" name="completion_proof" accept="image/jpeg,image/png,image/webp" required><button class="button primary" type="submit">Upload proof & finish trip</button></form>
					<?php endif; ?>
				</section>
				<?php endif; ?>
			</div>

			<aside class="side-stack">
				<section class="card vehicle-card"><h2>Your vehicle</h2><p>Keep this information current for the operations team.</p><div class="vehicle"><div class="vehicle-icon">▰</div><div><strong><?= $esc($driverProfile['vehicle_unit'] ?? 'Vehicle not registered') ?></strong><small><?= $esc($driverProfile['vehicle_color'] ?? '—') ?> · Plate <?= $esc($driverProfile['plate_number'] ?? '—') ?></small></div></div><div class="capacity"></div></section>
				<section class="card support-card"><h2>Need assistance?</h2><p>Our operations team is available if plans change or you need help on the road.</p><a class="support-link" href="tel:+639175550184">☎ <span><strong>Call operations</strong><br>+63 917 555 0184</span></a><a class="support-link" href="../user/service.html">✉ <span><strong>Message support</strong><br>Open customer service chat</span></a></section>
			</aside>
		</div>
		<?php endif; ?>
	</main>
	<div class="toast" id="toast" role="status"></div>
	<script src="../assets/js/live-refresh.js"></script>
	<script>
		MTRLive.start(() => location.reload(), { isBusy: () => Array.from(document.querySelectorAll('input[type=file]')).some(input => input.files.length) });
	</script>
	<script>		(() => {
			const availabilityButton = document.querySelector('#availabilityButton');
			const startTrip = document.querySelector('#startTrip');
			const toast = document.querySelector('#toast');
			let toastTimer;
			const showToast = (message) => { toast.textContent = message; toast.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(() => toast.classList.remove('show'), 2600); };
			availabilityButton.addEventListener('click', async () => {
				const nextAvailability = availabilityButton.classList.contains('offline');
				const response = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `availability=${nextAvailability ? '1' : '0'}` });
				if (!response.ok) { showToast('Could not update your availability.'); return; }
				availabilityButton.classList.toggle('offline', !nextAvailability);
				availabilityButton.textContent = nextAvailability ? 'Available for trips' : 'Unavailable for trips';
				availabilityButton.setAttribute('aria-pressed', String(nextAvailability));
				showToast(nextAvailability ? 'You are now available for assignments.' : 'You are now unavailable for assignments.');
			});
			if (startTrip) startTrip.addEventListener('click', () => {
				startTrip.classList.add('started');
				startTrip.textContent = 'Trip in progress';
				startTrip.disabled = true;
				showToast('Trip started. Drive safely, Juan.');
			});
			document.querySelectorAll('.route-stop-action').forEach((button) => button.addEventListener('click', () => {
				const destination = button.dataset.routeDestination;
				if (!destination) return;
				const openDirections = (origin) => {
						const url = button.dataset.routeLink || `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}`;
					window.open(url, '_blank', 'noopener,noreferrer');
					button.classList.add('started');
					button.textContent = 'Route opened';
					showToast(`Directions started to ${destination}.`);
				};
				if (!navigator.geolocation) {
					openDirections('Current location');
					return;
				}
				button.disabled = true;
				navigator.geolocation.getCurrentPosition(
					(position) => openDirections(`${position.coords.latitude},${position.coords.longitude}`),
					() => openDirections('Current location'),
					{ enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
				);
			}));

			const assignmentsByDate = <?= json_encode(array_map(static fn ($items) => array_map(static fn ($item) => [
				'package_name' => $item['package_name'], 'customer_name' => $item['customer_name'],
				'guest_count' => (int) $item['guest_count'], 'booking_status' => ucfirst($item['booking_status']),
				'url' => $tripPageUrl(['booking' => (int) $item['id'], 'month' => $calendarMonth->format('Y-m')])
			], $items), $assignmentDates), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
			const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
			document.querySelectorAll('.calendar-day.has-assignment').forEach((dayButton) => dayButton.addEventListener('click', () => {
				document.querySelectorAll('.calendar-day.selected').forEach((selectedDay) => selectedDay.classList.remove('selected'));
				dayButton.classList.add('selected');
				const scheduleList = document.querySelector('#scheduleList');
				scheduleList.innerHTML = (assignmentsByDate[dayButton.dataset.date] || []).map((assignment) => `<a class="schedule-item" href="${escapeHtml(assignment.url)}"><div><strong>${escapeHtml(assignment.package_name)}</strong><span>${escapeHtml(assignment.customer_name)} · ${assignment.guest_count} travelers</span></div><strong>${escapeHtml(assignment.booking_status)}</strong></a>`).join('');
				document.querySelector('#scheduleEmpty').hidden = Boolean(assignmentsByDate[dayButton.dataset.date]?.length);
			}));
		})();
	</script>
</body>
</html>
