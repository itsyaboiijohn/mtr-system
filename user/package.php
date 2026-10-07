<?php
require_once __DIR__ . '/../api/auth.php';
require_once __DIR__ . '/../api/db_con.php';
require_once __DIR__ . '/../api/customer-assignment.php';
$database->exec("CREATE TABLE IF NOT EXISTS travel_package_itinerary (
    Travel_Pck_Itinerary_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Travel_Pck_ID INT UNSIGNED NOT NULL,
    day_number TINYINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    activity TEXT NOT NULL,
    CONSTRAINT fk_itinerary_package FOREIGN KEY (Travel_Pck_ID) REFERENCES travel_packages(Travel_Pck_ID) ON DELETE CASCADE,
    UNIQUE KEY uq_package_day (Travel_Pck_ID, day_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$database->exec("CREATE TABLE IF NOT EXISTS travel_package_itinerary_items (
    Travel_Pck_Itinerary_Item_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Travel_Pck_Itinerary_ID INT UNSIGNED NOT NULL,
    category ENUM('breakfast', 'lunch', 'dinner', 'activity', 'entrance_fee', 'transport', 'other') NOT NULL DEFAULT 'other',
    name VARCHAR(180) NOT NULL,
    is_included TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_itinerary_item_day FOREIGN KEY (Travel_Pck_Itinerary_ID) REFERENCES travel_package_itinerary(Travel_Pck_Itinerary_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$packageColumns = $database->query('SHOW COLUMNS FROM travel_packages')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('include_departure_day', $packageColumns, true)) {
    $database->exec("ALTER TABLE travel_packages ADD COLUMN include_departure_day TINYINT(1) NOT NULL DEFAULT 1 AFTER duration_days");
}
if (!in_array('departure_spot', $packageColumns, true)) {
    $database->exec("ALTER TABLE travel_packages ADD COLUMN departure_spot VARCHAR(180) NULL AFTER include_departure_day");
}
if (!in_array('pickup_location', $packageColumns, true)) {
    $database->exec("ALTER TABLE travel_packages ADD COLUMN pickup_location VARCHAR(180) NULL AFTER departure_spot");
}
if (!in_array('visibility_days', $packageColumns, true)) {
    $database->exec("ALTER TABLE travel_packages ADD COLUMN visibility_days SMALLINT UNSIGNED NULL AFTER status");
}
if (!in_array('published_at', $packageColumns, true)) {
    $database->exec("ALTER TABLE travel_packages ADD COLUMN published_at DATETIME NULL AFTER visibility_days");
}
if (!in_array('visibility_minutes', $packageColumns, true)) {
    $database->exec("ALTER TABLE travel_packages ADD COLUMN visibility_minutes BIGINT UNSIGNED NULL AFTER visibility_days");
    $database->exec("UPDATE travel_packages SET visibility_minutes = visibility_days * 1440 WHERE visibility_days IS NOT NULL");
}

$pickupRegionKeywords = [
        'Zamboanga Peninsula' => ['zamboanga', 'dipolog', 'isabela', 'pagadian', 'zamboanga city'],
        'Northern Mindanao' => ['cagayan de oro', 'misamis oriental', 'misamis occidental', 'iligan', 'bukidnon', 'camiguin', 'laguindingan', 'cdo'],
        'Davao Region' => ['davao', 'tagum', 'mati', 'panabo', 'digos', 'bangoy', 'francisco bangoy'],
        'SOCCSKSARGEN' => ['general santos', 'gensan', 'koronadal', 'tacurong', 'kidapawan', 'south cotabato', 'cotabato'],
        'Caraga' => ['surigao', 'butuan', 'tandag', 'bislig', 'siargao', 'dapa', 'san francisco'],
        'BARMM' => ['basilan', 'lamitan', 'marawi', 'tawi-tawi', 'sulu', 'maguindanao', 'bongao']
];
function mtrResolvePickupRegion(string $location): ?string
{
    global $pickupRegionKeywords;
    $normalized = strtolower(trim($location));
    if ($normalized === '') {
        return null;
    }
    foreach ($pickupRegionKeywords as $region => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($normalized, strtolower($keyword))) {
                return $region;
            }
        }
        if (str_contains($normalized, strtolower($region))) {
            return $region;
        }
    }
    return null;
}

$userId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = require_user_auth(true);
}

$packageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$packageId) {
    http_response_code(400);
    exit('A valid package is required.');
}

$packageStatement = $database->prepare(
    "SELECT p.Travel_Pck_ID, p.Hotel_ID, p.name, p.description, p.duration_days, p.include_departure_day, p.departure_spot, p.pickup_location, p.price_per_person,
            p.image_url, d.name AS legacy_destination_name, h.name AS hotel_name, h.location AS hotel_location, h.region AS hotel_region,
            h.description AS hotel_description, h.price_per_night AS hotel_price_per_night
     FROM travel_packages p
     LEFT JOIN destinations d ON d.Destination_ID = p.Destination_ID
     LEFT JOIN hotels h ON h.Hotel_ID = p.Hotel_ID
     WHERE p.Travel_Pck_ID = ? AND p.status = 'published'
       AND (p.visibility_minutes IS NULL OR p.published_at IS NULL OR DATE_ADD(p.published_at, INTERVAL p.visibility_minutes MINUTE) > NOW())
     LIMIT 1"
);
$packageStatement->execute([$packageId]);
$package = $packageStatement->fetch();
if (!$package) {
    http_response_code(404);
    exit('Package not found.');
}

$hotelImages = [];
if (!empty($package['Hotel_ID'])) {
    $hotelImageStatement = $database->prepare('SELECT image_path FROM hotel_images WHERE Hotel_ID = ? ORDER BY Hotel_Image_ID');
    $hotelImageStatement->execute([(int) $package['Hotel_ID']]);
    foreach ($hotelImageStatement->fetchAll(PDO::FETCH_COLUMN) as $hotelImagePath) {
        $hotelImagePath = trim((string) $hotelImagePath);
        if ($hotelImagePath !== '') {
            $hotelImages[] = preg_match('/^https?:\/\//i', $hotelImagePath)
                ? $hotelImagePath
                : '../' . ltrim($hotelImagePath, '/');
        }
    }
}

$packageRegionStatement = $database->prepare('SELECT region FROM travel_package_regions WHERE Travel_Pck_ID = ? ORDER BY region');
$packageRegionStatement->execute([$packageId]);
$packageRegionNames = array_values(array_filter(array_map(static fn ($region) => trim((string) $region), $packageRegionStatement->fetchAll(PDO::FETCH_COLUMN))));

$itineraryStatement = $database->prepare('SELECT day_number, title, activity FROM travel_package_itinerary WHERE Travel_Pck_ID = ? ORDER BY day_number');
$itineraryStatement->execute([$packageId]);
$itinerary = $itineraryStatement->fetchAll();
$itineraryItemsStatement = $database->prepare('SELECT i.day_number, item.category, item.name, item.is_included
    FROM travel_package_itinerary_items item
    INNER JOIN travel_package_itinerary i ON i.Travel_Pck_Itinerary_ID = item.Travel_Pck_Itinerary_ID
    WHERE i.Travel_Pck_ID = ?
    ORDER BY i.day_number, item.Travel_Pck_Itinerary_Item_ID');
$itineraryItemsStatement->execute([$packageId]);
$itineraryItemsByDay = [];
foreach ($itineraryItemsStatement->fetchAll() as $itineraryItem) {
    $dayNumber = (int) $itineraryItem['day_number'];
    $itineraryItemsByDay[$dayNumber][] = $itineraryItem;
}
foreach ($itinerary as &$itineraryDay) {
    $itineraryDay['inclusions'] = $itineraryItemsByDay[(int) $itineraryDay['day_number']] ?? [];
}
unset($itineraryDay);
if (!$itinerary) {
    for ($day = 1; $day <= (int) $package['duration_days']; $day++) {
        $itinerary[] = [
            'day_number' => $day,
            'title' => $day === 1 ? 'Arrival & welcome' : 'Explore and enjoy',
            'activity' => $day === 1 ? 'Arrive, settle in, and begin your selected Mindanao experience.' : 'Discover the destinations and activities included in this package.'
        ];
    }
}
if ((int) $package['include_departure_day'] === 1) {
    $lastDay = $itinerary ? max(array_map(static fn ($item) => (int) $item['day_number'], $itinerary)) : 0;
    $itinerary[] = ['day_number' => $lastDay + 1, 'title' => 'Departure' . ($package['departure_spot'] ? ' · ' . $package['departure_spot'] : ''), 'activity' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $travelers = max(1, (int) ($payload['travelers'] ?? 1));
    $travelDate = trim((string) ($payload['travel_date'] ?? ''));
    if ($travelDate === '' || !strtotime($travelDate)) {
        $travelDate = date('Y-m-d');
    }

    $tierStatement = $database->prepare('SELECT price_per_pax FROM travel_package_price_tiers WHERE Travel_Pck_ID = ? AND min_pax <= ? AND max_pax >= ? ORDER BY min_pax LIMIT 1');
    $tierStatement->execute([$packageId, $travelers, $travelers]);
    $pricePerPax = (float) ($tierStatement->fetchColumn() ?: $package['price_per_person']);
    $bookingColumns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('approval_status', $bookingColumns, true)) {
        $database->exec("ALTER TABLE bookings ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER booking_status");
    }
    if (!in_array('approval_note', $bookingColumns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN approval_note TEXT NULL AFTER approval_status');
    }
    if (!in_array('pickup_location', $bookingColumns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN pickup_location VARCHAR(180) NULL');
    }
    if (!in_array('pickup_address', $bookingColumns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN pickup_address VARCHAR(500) NULL');
    }
    if (!in_array('pickup_notes', $bookingColumns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN pickup_notes TEXT NULL');
    }
        if (!in_array('departure_location', $bookingColumns, true)) {
            $database->exec("ALTER TABLE bookings ADD COLUMN departure_location VARCHAR(180) NULL");
        }
    $pickupLocation = trim((string) ($payload['pickup_location'] ?? ''));
    $departureLocation = trim((string) ($payload['departure_location'] ?? ''));
    $pickupAddress = trim((string) ($payload['pickup_address'] ?? ''));
    $pickupNotes = trim((string) ($payload['pickup_notes'] ?? ''));
    $pickupSource = $pickupLocation !== '' ? $pickupLocation : $pickupAddress;
    $pickupRegion = mtrResolvePickupRegion($pickupSource);
    $pickupTransferDay = $pickupRegion === null || !in_array($pickupRegion, $packageRegionNames, true);
    if ($pickupSource === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a pickup location or a custom pickup address so we can prepare the correct travel schedule.']);
        exit;
    }
    $tripDurationDays = max(
        (int) $package['duration_days'],
        $itinerary ? max(array_map(static fn ($item) => (int) $item['day_number'], $itinerary)) : 1
    ) + ($pickupTransferDay ? 1 : 0);
    if ($tripDurationDays > 65535) {
        http_response_code(400);
        echo json_encode(['error' => 'This itinerary is too long to book.']);
        exit;
    }
    require_once __DIR__ . '/../api/vehicle-availability.php';
    mtrEnsureVehicleBookingColumns($database);
    foreach (['accommodation_type' => "VARCHAR(30) NOT NULL DEFAULT 'none'", 'accommodation_name' => 'VARCHAR(255) NULL', 'accommodation_address' => 'VARCHAR(500) NULL', 'accommodation_maps_link' => 'VARCHAR(500) NULL'] as $column => $definition) {
        if (!in_array($column, $bookingColumns, true)) {
            $database->exec("ALTER TABLE bookings ADD COLUMN {$column} {$definition}");
        }
    }
    $bookingStatement = $database->prepare("INSERT INTO bookings (Customer_ID, Travel_Pck_ID, Hotel_ID, accommodation_type, accommodation_name, accommodation_address, accommodation_maps_link, travel_date, trip_duration_days, guest_count, total_amount, payment_status, booking_status, approval_status, pickup_location, departure_location, pickup_address, pickup_notes) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 'pending', 'pending', 'pending', ?, ?, ?, ?)");
    $bookingStatement->execute([
        $userId,
        $packageId,
        $package['Hotel_ID'],
        $package['Hotel_ID'] ? 'listed_hotel' : 'none',
        $package['hotel_name'] ?: null,
        $package['hotel_location'] ?: null,
        $travelDate,
        $tripDurationDays,
        $travelers,
        $pricePerPax * $travelers,
        $pickupLocation ?: null,
        $departureLocation ?: null,
        $pickupAddress ?: null,
        $pickupNotes ?: null
    ]);
    mtrAssignCustomerToEmployee($database, $userId);
    echo json_encode(['success' => true, 'booking_id' => (int) $database->lastInsertId()]);
    exit;
}

$destinationStatement = $database->prepare(
    "SELECT d.Destination_ID, d.name, d.location, d.description, d.image_url
     FROM destinations d
     INNER JOIN travel_package_destinations pd ON pd.Destination_ID = d.Destination_ID
     WHERE pd.Travel_Pck_ID = ? AND d.status = 'active'
     ORDER BY d.name"
);
$destinationStatement->execute([$packageId]);
$destinations = $destinationStatement->fetchAll();
if (!$destinations && $package['legacy_destination_name']) {
    $destinations[] = ['name' => $package['legacy_destination_name'], 'description' => null, 'image_url' => null, 'location' => ''];
}
$destinationsByName = [];
foreach ($destinations as $destination) {
    $destinationsByName[strtolower(trim((string) $destination['name']))] = $destination;
}
$tripRouteStops = array_values(array_map(static function ($day) use ($destinationsByName) {
    $destination = $destinationsByName[strtolower(trim((string) $day['title']))] ?? null;
    return [
        'day_number' => (int) $day['day_number'],
        'title' => (string) $day['title'],
        'location' => $destination ? (string) ($destination['location'] ?? '') : ''
    ];
}, array_values(array_filter($itinerary, static fn ($day) => !str_starts_with(strtolower((string) $day['title']), 'departure')))));

$destinationIds = array_values(array_filter(array_map(static fn ($destination) => $destination['Destination_ID'] ?? null, $destinations)));
$images = [];
if ($destinationIds) {
    $placeholders = implode(',', array_fill(0, count($destinationIds), '?'));
    $imageStatement = $database->prepare("SELECT image_path FROM destination_images WHERE Destination_ID IN ($placeholders) ORDER BY Destination_Image_ID");
    $imageStatement->execute($destinationIds);
    $images = array_column($imageStatement->fetchAll(), 'image_path');
}
if ($package['image_url']) {
    array_unshift($images, $package['image_url']);
}
$images = array_values(array_unique(array_filter($images)));
if (!$images) {
    $images = ['assets/logo/logo.png'];
}

$tierStatement = $database->prepare('SELECT min_pax, max_pax, price_per_pax FROM travel_package_price_tiers WHERE Travel_Pck_ID = ? ORDER BY min_pax');
$tierStatement->execute([$packageId]);
$priceTiers = $tierStatement->fetchAll();
if (!$priceTiers) {
    $priceTiers = [['min_pax' => 1, 'max_pax' => 255, 'price_per_pax' => $package['price_per_person']]];
}
$maxPax = min(50, max(array_map(static fn ($tier) => (int) $tier['max_pax'], $priceTiers)));
$destinationNames = array_values(array_filter(array_map(static fn ($destination) => $destination['name'] ?? null, $destinations)));
$destinationLabel = implode(' · ', $destinationNames) ?: 'Mindanao, Philippines';
$description = trim((string) ($package['description'] ?: 'Explore Mindanao with a curated travel experience.'));
?>
<!DOCTYPE html>
<html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Package Details — Mindanao Travel & Tours</title>
            <style>
                            :root {
                    --bg: #F0FAFF;
                    --card: #fff;
                    --ink: #0F1724;
                    --muted: #64748B;
                    --line: #DCE8EF;
                    --sky: #0EA5E9;
                    --sky-dark: #0369A1;
                    --shadow: 0 12px 30px rgba(2, 6, 23, .07);
                    font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                    }

                    * {
                    box-sizing: border-box;
                    }

                    html {
                    scroll-behavior: smooth;
                    }

                    body {
                    margin: 0;
                    background: var(--bg);
                    color: var(--ink);
                    }

                    .page {
                    max-width: 1180px;
                    margin: auto;
                    padding: 24px 20px 50px;
                    }

                    /* Topbar */
                    .topbar {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-bottom: 22px;
                    }

                    .brand {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    color: inherit;
                    text-decoration: none;
                    }

                    .logo {
                    width: 44px;
                    height: 44px;
                    border-radius: 12px;
                    background: #fff;
                    border: 1px solid rgba(14, 165, 233, .15);
                    overflow: hidden;
                    box-shadow: var(--shadow);
                    }

                    .logo img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                    }

                    .brand strong {
                    display: block;
                    }

                    .brand small {
                    color: var(--muted);
                    }

                    .back {
                    color: var(--sky-dark);
                    font-weight: 700;
                    text-decoration: none;
                    }

                    .back:hover {
                    text-decoration: underline;
                    }

                    /* Hero Section */
                    .hero {
                    display: grid;
                    grid-template-columns: 1.35fr .65fr;
                    gap: 22px;
                    align-items: stretch;
                    }

                    /* Gallery */
                    .gallery {
                    position: relative;
                    min-height: 440px;
                    border-radius: 20px;
                    overflow: hidden;
                    background: #cceffc;
                    box-shadow: var(--shadow);
                    }

                    .gallery-image {
                    position: absolute;
                    inset: 0;
                    background-size: cover;
                    background-position: center;
                    opacity: 0;
                    transition: opacity 500ms ease;
                    }

                    .gallery-image.active {
                    opacity: 1;
                    }

                    .gallery::after {
                    content: "";
                    position: absolute;
                    inset: 0;
                    background: linear-gradient(0deg, rgba(3, 31, 52, .62), transparent 55%);
                    pointer-events: none;
                    }

                    .gallery-caption {
                    position: absolute;
                    left: 26px;
                    bottom: 24px;
                    z-index: 2;
                    color: #fff;
                    }

                    .gallery-caption span {
                    color: #BAE6FD;
                    font-size: 12px;
                    font-weight: 800;
                    letter-spacing: 1.5px;
                    text-transform: uppercase;
                    }

                    .gallery-caption h1 {
                    max-width: 580px;
                    margin: 8px 0 0;
                    font-size: clamp(28px, 4vw, 48px);
                    line-height: 1.05;
                    }

                    .gallery-arrow {
                    position: absolute;
                    top: 50%;
                    z-index: 3;
                    width: 40px;
                    height: 40px;
                    border: 0;
                    border-radius: 50%;
                    background: rgba(255, 255, 255, .9);
                    color: var(--sky-dark);
                    cursor: pointer;
                    transform: translateY(-50%);
                    font-size: 20px;
                    }

                    .gallery-arrow.prev {
                    left: 16px;
                    }

                    .gallery-arrow.next {
                    right: 16px;
                    }

                    .gallery-dots {
                    position: absolute;
                    right: 22px;
                    bottom: 16px;
                    z-index: 3;
                    display: flex;
                    gap: 6px;
                    }

                    .gallery-dot {
                    width: 7px;
                    height: 7px;
                    border: 0;
                    border-radius: 50%;
                    background: rgba(255, 255, 255, .55);
                    cursor: pointer;
                    }

                    .gallery-dot.active {
                    background: #fff;
                    }

                    /* Booking Card */
                    .booking-card {
                    padding: 24px;
                    border: 1px solid rgba(2, 6, 23, .05);
                    border-radius: 20px;
                    background: var(--card);
                    box-shadow: var(--shadow);
                    }

                    .booking-card h2 {
                    margin: 0 0 6px;
                    }

                    .price {
                    margin: 20px 0;
                    font-size: 28px;
                    font-weight: 800;
                    }

                    .muted {
                    color: var(--muted);
                    font-size: 14px;
                    }

                    .field {
                    display: block;
                    margin: 14px 0;
                    }

                    .field span {
                    display: block;
                    margin-bottom: 6px;
                    font-size: 13px;
                    font-weight: 700;
                    }

                    .field input,
                    .field select {
                    width: 100%;
                    height: 44px;
                    padding: 0 11px;
                    border: 1px solid var(--line);
                    border-radius: 10px;
                    background: #fff;
                    font: inherit;
                    }

                    .included-hotel {
                    margin: 18px 0;
                    padding: 15px;
                    border: 1px solid rgba(14, 165, 233, .18);
                    border-radius: 12px;
                    background: linear-gradient(135deg, #f0faff, #fff);
                    }

                    .included-hotel-label {
                    margin-bottom: 6px;
                    color: var(--sky-dark);
                    font-size: 11px;
                    font-weight: 800;
                    letter-spacing: .06em;
                    text-transform: uppercase;
                    }

                    .included-hotel strong,
                    .included-hotel span {
                    display: block;
                    }

                    .included-hotel strong {
                    margin-bottom: 4px;
                    font-size: 15px;
                    }

                    .included-hotel span,
                    .included-hotel p {
                    color: var(--muted);
                    font-size: 12px;
                    line-height: 1.5;
                    }

                    .included-hotel p {
                    margin: 8px 0 0;
                    }

                    .hotel-details {
                    margin-top: 22px;
                    padding: 24px;
                    border: 1px solid rgba(2, 6, 23, .05);
                    border-radius: 16px;
                    background: var(--card);
                    box-shadow: var(--shadow);
                    }

                    .hotel-details-heading {
                    margin-bottom: 18px;
                    }

                    .hotel-details-heading h2 {
                    margin: 0 0 5px;
                    font-size: 22px;
                    }

                    .hotel-details-heading p {
                    margin: 0;
                    color: var(--muted);
                    font-size: 13px;
                    }

                    .hotel-photo-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
                    gap: 12px;
                    margin-bottom: 20px;
                    }

                    .hotel-photo {
                    position: relative;
                    display: block;
                    width: 100%;
                    padding: 0;
                    min-height: 190px;
                    overflow: hidden;
                    border: 1px solid #e2eaf0;
                    border-radius: 12px;
                    background: #eaf7fc;
                    cursor: zoom-in;
                    }

                    .hotel-photo img {
                    display: block;
                    width: 100%;
                    height: 100%;
                    min-height: 190px;
                    object-fit: cover;
                    }

                    .hotel-photo-empty {
                    display: grid;
                    min-height: 190px;
                    place-items: center;
                    border: 1px dashed #cbdde8;
                    border-radius: 12px;
                    background: #f7fbfd;
                    color: var(--muted);
                    font-size: 13px;
                    }

                    .hotel-photo:focus-visible {
                    outline: 3px solid rgba(14, 165, 233, .55);
                    outline-offset: 3px;
                    }

                    .hotel-photo-hint {
                    position: absolute;
                    right: 10px;
                    bottom: 10px;
                    padding: 6px 9px;
                    border: 1px solid rgba(255, 255, 255, .3);
                    border-radius: 999px;
                    background: rgba(9, 31, 47, .7);
                    color: #fff;
                    font-size: 10px;
                    font-weight: 700;
                    opacity: 0;
                    transform: translateY(3px);
                    transition: opacity 160ms ease, transform 160ms ease;
                    }

                    .hotel-photo:hover .hotel-photo-hint,
                    .hotel-photo:focus-visible .hotel-photo-hint {
                    opacity: 1;
                    transform: translateY(0);
                    }

                    .hotel-lightbox[hidden] {
                    display: none;
                    }

                    .hotel-lightbox {
                    position: fixed;
                    z-index: 30;
                    inset: 0;
                    display: grid;
                    place-items: center;
                    padding: 28px;
                    background: rgba(5, 17, 27, .88);
                    backdrop-filter: blur(8px);
                    }

                    .hotel-lightbox-content {
                    position: relative;
                    display: grid;
                    width: min(1100px, 100%);
                    max-height: 100%;
                    grid-template-rows: minmax(0, 1fr) auto;
                    justify-items: center;
                    gap: 14px;
                    }

                    .hotel-lightbox-image {
                    display: block;
                    max-width: 100%;
                    max-height: calc(100vh - 120px);
                    border: 1px solid rgba(255, 255, 255, .18);
                    border-radius: 12px;
                    object-fit: contain;
                    box-shadow: 0 24px 80px rgba(0, 0, 0, .38);
                    }

                    .hotel-lightbox-caption {
                    color: rgba(255, 255, 255, .88);
                    font-size: 13px;
                    text-align: center;
                    }

                    .hotel-lightbox-close,
                    .hotel-lightbox-nav {
                    position: absolute;
                    display: grid;
                    place-items: center;
                    border: 1px solid rgba(255, 255, 255, .28);
                    border-radius: 50%;
                    background: rgba(255, 255, 255, .12);
                    color: #fff;
                    cursor: pointer;
                    transition: background 160ms ease, transform 160ms ease;
                    }

                    .hotel-lightbox-close:hover,
                    .hotel-lightbox-nav:hover {
                    background: rgba(255, 255, 255, .24);
                    transform: scale(1.04);
                    }

                    .hotel-lightbox-close {
                    top: 18px;
                    right: 20px;
                    width: 44px;
                    height: 44px;
                    font-size: 25px;
                    }

                    .hotel-lightbox-nav {
                    top: 50%;
                    width: 48px;
                    height: 48px;
                    font-size: 28px;
                    transform: translateY(-50%);
                    }

                    .hotel-lightbox-nav:hover {
                    transform: translateY(-50%) scale(1.04);
                    }

                    .hotel-lightbox-prev {
                    left: max(20px, calc((100vw - 1180px) / 2));
                    }

                    .hotel-lightbox-next {
                    right: max(20px, calc((100vw - 1180px) / 2));
                    }

                    .hotel-lightbox-close:focus-visible,
                    .hotel-lightbox-nav:focus-visible {
                    outline: 3px solid #7dd3fc;
                    outline-offset: 3px;
                    }

                    .hotel-details-description {
                    max-width: 900px;
                    margin: 0;
                    color: #475569;
                    line-height: 1.7;
                    white-space: pre-line;
                    }

                    .pickup-plan-note {
                    margin: 10px 0 0;
                    padding: 10px 12px;
                    border-radius: 9px;
                    background: #fff7ed;
                    color: #9a3412;
                    font-size: 12px;
                    line-height: 1.5;
                    }

                    .total {
                    display: flex;
                    justify-content: space-between;
                    margin: 20px 0;
                    padding-top: 16px;
                    border-top: 1px solid var(--line);
                    font-weight: 800;
                    }

                    .button {
                    width: 100%;
                    height: 47px;
                    border: 0;
                    border-radius: 10px;
                    background: var(--sky);
                    color: #fff;
                    font: inherit;
                    font-weight: 800;
                    cursor: pointer;
                    }

                    .button:hover {
                    background: var(--sky-dark);
                    }

                    .button.secondary {
                    margin-top: 10px;
                    border: 1px solid var(--sky);
                    background: transparent;
                    color: var(--sky-dark);
                    }

                    .button.secondary:hover {
                    background: #E6F9FF;
                    }

                    /* Content Layout */
                    .content {
                    display: grid;
                    grid-template-columns: 1fr 320px;
                    gap: 22px;
                    margin-top: 22px;
                    }

                    .section,
                    .side-card {
                    padding: 22px;
                    border: 1px solid rgba(2, 6, 23, .05);
                    border-radius: 16px;
                    background: var(--card);
                    box-shadow: var(--shadow);
                    }

                    .section h2,
                    .side-card h3 {
                    margin: 0 0 12px;
                    }

                    .section p {
                    color: #475569;
                    line-height: 1.65;
                    }

                    /* Itinerary */
                    .itinerary {
                    display: grid;
                    gap: 12px;
                    }

                    .day {
                    display: grid;
                    grid-template-columns: 70px 1fr;
                    gap: 14px;
                    padding: 14px 0;
                    border-top: 1px solid var(--line);
                    }

                    .day:first-child {
                    border-top: 0;
                    }

                    .day strong {
                    color: var(--sky-dark);
                    }

                    .day h3 {
                    margin: 0 0 4px;
                    font-size: 15px;
                    }

                    /* Checklist */
                    .checks {
                    display: grid;
                    gap: 9px;
                    padding: 0;
                    margin: 0;
                    list-style: none;
                    }

                    .checks li::before {
                    content: "✓";
                    margin-right: 8px;
                    color: var(--sky);
                    font-weight: 800;
                    }

                    /* Side Stack */
                    .side-stack {
                    display: grid;
                    gap: 22px;
                    }

                    .notice {
                    padding: 14px;
                    border-radius: 10px;
                    background: #E6F9FF;
                    color: var(--sky-dark);
                    font-size: 13px;
                    line-height: 1.5;
                    }

                    /* Toast */
                    .toast {
                    position: fixed;
                    left: 50%;
                    bottom: 24px;
                    padding: 12px 18px;
                    border-radius: 10px;
                    background: #0F1724;
                    color: #fff;
                    opacity: 0;
                    pointer-events: none;
                    transform: translate(-50%, 20px);
                    transition: opacity 200ms, transform 200ms;
                    }

                    .toast.show {
                    opacity: 1;
                    transform: translate(-50%, 0);
                    }

                    /* Refined package detail layout */
                    body {
                    background:
                        radial-gradient(ellipse at 12% 0%, rgba(14, 165, 233, .08), transparent 34rem),
                        #f5f8fb;
                    color: #12263a;
                    }

                    .page {
                    max-width: 1280px;
                    padding: 24px 28px 64px;
                    }

                    .topbar {
                    min-height: 58px;
                    margin-bottom: 24px;
                    }

                    .brand {
                    gap: 13px;
                    }

                    .logo {
                    width: 48px;
                    height: 48px;
                    border-radius: 14px;
                    box-shadow: 0 5px 16px rgba(15, 46, 69, .08);
                    }

                    .brand strong {
                    color: #12304a;
                    font-size: 15px;
                    letter-spacing: -.01em;
                    }

                    .brand small {
                    display: block;
                    margin-top: 2px;
                    font-size: 12px;
                    }

                    .back {
                    display: inline-flex;
                    min-height: 40px;
                    align-items: center;
                    gap: 7px;
                    padding: 0 14px;
                    border: 1px solid #dbe7ef;
                    border-radius: 10px;
                    background: rgba(255, 255, 255, .88);
                    color: #24546f;
                    font-size: 13px;
                    transition: border-color 160ms ease, background 160ms ease, transform 160ms ease;
                    }

                    .back:hover {
                    border-color: #afd7e9;
                    background: #fff;
                    text-decoration: none;
                    transform: translateY(-1px);
                    }

                    .hero {
                    grid-template-columns: minmax(0, 1.55fr) minmax(340px, .75fr);
                    gap: 24px;
                    }

                    .gallery {
                    min-height: 520px;
                    border: 1px solid rgba(255, 255, 255, .65);
                    border-radius: 22px;
                    box-shadow: 0 20px 48px rgba(15, 46, 69, .14);
                    }

                    .gallery::after {
                    background: linear-gradient(0deg, rgba(7, 27, 45, .78), rgba(7, 27, 45, .02) 67%);
                    }

                    .gallery-caption {
                    right: 28px;
                    bottom: 30px;
                    left: 30px;
                    }

                    .gallery-caption span {
                    display: inline-flex;
                    max-width: 100%;
                    padding: 7px 10px;
                    border: 1px solid rgba(255, 255, 255, .3);
                    border-radius: 999px;
                    background: rgba(8, 34, 53, .3);
                    color: #e0f5ff;
                    font-size: 11px;
                    letter-spacing: .09em;
                    backdrop-filter: blur(8px);
                    }

                    .gallery-caption h1 {
                    max-width: 640px;
                    margin-top: 14px;
                    font-size: clamp(34px, 4.4vw, 56px);
                    letter-spacing: -.04em;
                    text-shadow: 0 3px 18px rgba(0, 0, 0, .18);
                    }

                    .gallery-arrow {
                    width: 44px;
                    height: 44px;
                    border: 1px solid rgba(255, 255, 255, .55);
                    background: rgba(255, 255, 255, .88);
                    box-shadow: 0 5px 16px rgba(0, 0, 0, .13);
                    transition: transform 160ms ease, background 160ms ease;
                    }

                    .gallery-arrow:hover {
                    background: #fff;
                    transform: translateY(-50%) scale(1.05);
                    }

                    .gallery-dot {
                    width: 8px;
                    height: 8px;
                    }

                    .booking-card {
                    position: sticky;
                    top: 20px;
                    padding: 24px;
                    border: 1px solid #e1eaf0;
                    border-radius: 20px;
                    box-shadow: 0 16px 38px rgba(15, 46, 69, .09);
                    }

                    .booking-card > .muted:first-child {
                    color: #60788a;
                    font-size: 12px;
                    font-weight: 700;
                    letter-spacing: .05em;
                    text-transform: uppercase;
                    }

                    .price {
                    margin: 8px 0 4px;
                    color: #0b6390;
                    font-size: 32px;
                    letter-spacing: -.035em;
                    }

                    .booking-card > .muted:nth-of-type(2) {
                    font-size: 12px;
                    }

                    .included-hotel {
                    margin: 18px 0 20px;
                    padding: 16px;
                    border-color: #d8eaf3;
                    background: linear-gradient(145deg, #f0f9fd, #fbfdff);
                    }

                    .included-hotel-label {
                    margin-bottom: 8px;
                    font-size: 10px;
                    letter-spacing: .09em;
                    }

                    .included-hotel strong {
                    color: #163b55;
                    font-size: 16px;
                    }

                    .field {
                    margin: 15px 0;
                    }

                    .field span {
                    margin-bottom: 7px;
                    color: #344e62;
                    font-size: 12px;
                    font-weight: 700;
                    }

                    .field input,
                    .field select,
                    .field textarea {
                    width: 100%;
                    min-height: 44px;
                    padding: 10px 12px;
                    border: 1px solid #d9e4eb;
                    border-radius: 10px;
                    background: #fbfdff;
                    color: #183247;
                    font: inherit;
                    font-size: 13px;
                    transition: border-color 160ms ease, box-shadow 160ms ease, background 160ms ease;
                    }

                    .field input:focus,
                    .field select:focus,
                    .field textarea:focus {
                    border-color: #46b1df;
                    outline: none;
                    background: #fff;
                    box-shadow: 0 0 0 3px rgba(14, 165, 233, .12);
                    }

                    .field input::placeholder,
                    .field textarea::placeholder {
                    color: #9aaab6;
                    }

                    .field textarea {
                    min-height: 76px;
                    resize: vertical;
                    }

                    .pickup-custom-toggle {
                    display: flex;
                    align-items: flex-start;
                    gap: 10px;
                    margin: 14px 0 8px;
                    padding: 12px;
                    border: 1px solid #d8e6ed;
                    border-radius: 10px;
                    background: #f8fcfe;
                    color: #344e62;
                    cursor: pointer;
                    }

                    .pickup-custom-toggle.is-selected {
                    border-color: #8bcbe4;
                    background: #f0faff;
                    }

                    .pickup-custom-toggle input {
                    width: 17px;
                    height: 17px;
                    flex: 0 0 auto;
                    margin: 2px 0 0;
                    accent-color: var(--sky-dark);
                    }

                    .pickup-custom-toggle strong,
                    .pickup-custom-toggle small {
                    display: block;
                    }

                    .pickup-custom-toggle strong {
                    font-size: 12px;
                    line-height: 1.45;
                    }

                    .pickup-custom-toggle small {
                    margin-top: 3px;
                    color: var(--muted);
                    font-size: 11px;
                    line-height: 1.5;
                    }

                    .pickup-location-field[hidden],
                    .pickup-custom-address[hidden] {
                    display: none;
                    }

                    .pickup-plan-note {
                    border: 1px solid #fed7aa;
                    background: #fffaf3;
                    line-height: 1.6;
                    }

                    .total {
                    margin: 22px 0 16px;
                    padding-top: 17px;
                    border-color: #e4edf2;
                    color: #18364c;
                    font-size: 14px;
                    }

                    #total {
                    color: #075f8b;
                    font-size: 17px;
                    }

                    .button {
                    height: 50px;
                    border-radius: 11px;
                    background: linear-gradient(135deg, #0ea5e9, #087db5);
                    box-shadow: 0 8px 18px rgba(14, 165, 233, .2);
                    transition: box-shadow 160ms ease, transform 160ms ease, background 160ms ease;
                    }

                    .button:hover {
                    background: linear-gradient(135deg, #0b91ce, #056b9e);
                    box-shadow: 0 10px 22px rgba(14, 165, 233, .25);
                    transform: translateY(-1px);
                    }

                    .button:disabled {
                    cursor: wait;
                    opacity: .72;
                    transform: none;
                    }

                    .content {
                    grid-template-columns: minmax(0, 1fr);
                    gap: 22px;
                    margin-top: 24px;
                    }

                    .section,
                    .trip-route,
                    .hotel-details {
                    padding: 28px;
                    border: 1px solid #e1eaf0;
                    border-radius: 18px;
                    box-shadow: 0 12px 30px rgba(15, 46, 69, .06);
                    }

                    .section h2,
                    .trip-route-heading h2,
                    .hotel-details-heading h2 {
                    color: #17384f;
                    font-size: 23px;
                    letter-spacing: -.025em;
                    }

                    .section p {
                    margin-top: 0;
                    color: #586e7f;
                    font-size: 14px;
                    line-height: 1.8;
                    }

                    .section h2[style] {
                    margin-top: 32px !important;
                    padding-top: 26px;
                    border-top: 1px solid #e8eff3;
                    }

                    .trip-route {
                    margin-top: 22px;
                    padding: 26px 28px;
                    border: 1px solid #e1eaf0;
                    border-radius: 18px;
                    background: linear-gradient(135deg, #fff, #f8fcfe);
                    box-shadow: 0 12px 30px rgba(15, 46, 69, .06);
                    }

                    .trip-route-heading {
                    margin-bottom: 20px;
                    }

                    .trip-route-heading h2 {
                    margin: 0 0 6px;
                    color: #17384f;
                    font-size: 22px;
                    letter-spacing: -.025em;
                    }

                    .trip-route-heading p,
                    .trip-route-note {
                    margin: 0;
                    color: #6c7f8f;
                    font-size: 12px;
                    line-height: 1.6;
                    }

                    .trip-route-stops {
                    display: grid;
                    padding: 0;
                    margin: 0;
                    list-style: none;
                    }

                    .trip-route-stop {
                    position: relative;
                    display: grid;
                    grid-template-columns: 30px minmax(0, 1fr);
                    gap: 12px;
                    min-height: 62px;
                    }

                    .trip-route-stop:not(:last-child)::before {
                    position: absolute;
                    top: 26px;
                    bottom: -2px;
                    left: 11px;
                    width: 2px;
                    background: #d6e7ef;
                    content: "";
                    }

                    .trip-route-marker {
                    z-index: 1;
                    display: grid;
                    width: 24px;
                    height: 24px;
                    place-items: center;
                    border: 2px solid #fff;
                    border-radius: 50%;
                    background: #dceef5;
                    color: #23617a;
                    font-size: 10px;
                    font-weight: 800;
                    box-shadow: 0 0 0 1px #c5dce7;
                    }

                    .trip-route-stop.start .trip-route-marker,
                    .trip-route-stop.end .trip-route-marker {
                    background: #0878a9;
                    color: #fff;
                    box-shadow: 0 0 0 1px #0878a9;
                    }

                    .trip-route-stop-copy {
                    min-width: 0;
                    padding: 2px 0 18px;
                    }

                    .trip-route-stop-copy strong,
                    .trip-route-stop-copy span {
                    display: block;
                    overflow-wrap: anywhere;
                    }

                    .trip-route-stop-copy strong {
                    color: #27465a;
                    font-size: 13px;
                    }

                    .trip-route-stop-copy span {
                    margin-top: 3px;
                    color: #7b8e9c;
                    font-size: 10px;
                    font-weight: 700;
                    letter-spacing: .04em;
                    text-transform: uppercase;
                    }

                    .trip-route-note {
                    padding-top: 12px;
                    border-top: 1px solid #e8eff3;
                    }

                    .itinerary {
                    gap: 0;
                    margin-top: 8px;
                    }

                    .day {
                    grid-template-columns: 82px minmax(0, 1fr);
                    gap: 18px;
                    padding: 18px 0;
                    border-color: #e8eff3;
                    }

                    .day strong {
                    display: inline-flex;
                    width: max-content;
                    height: 28px;
                    align-items: center;
                    padding: 0 10px;
                    border: 1px solid #d5edf7;
                    border-radius: 999px;
                    background: #eff9fd;
                    color: #0878a9;
                    font-size: 10px;
                    letter-spacing: .07em;
                    }

                    .day h3 {
                    margin: 2px 0 6px;
                    color: #203d52;
                    font-size: 15px;
                    }

                    .day .muted {
                    color: #6c7f8f;
                    font-size: 13px;
                    line-height: 1.65;
                    }

                    .package-inclusions {
                    display: grid;
                    gap: 7px;
                    padding: 0;
                    margin: 12px 0 0;
                    list-style: none;
                    }

                    .package-inclusions li {
                    display: grid;
                    grid-template-columns: 92px minmax(0, 1fr) auto;
                    align-items: center;
                    gap: 9px;
                    padding: 8px 10px;
                    border: 1px solid #e5edf2;
                    border-radius: 8px;
                    background: #f9fcfd;
                    color: #3e5668;
                    font-size: 12px;
                    }

                    .package-inclusion-category {
                    color: #637889;
                    font-size: 10px;
                    font-weight: 800;
                    text-transform: uppercase;
                    letter-spacing: .04em;
                    }

                    .package-inclusion-status {
                    padding: 4px 7px;
                    border-radius: 999px;
                    font-size: 10px;
                    white-space: nowrap;
                    }

                    .package-inclusion-status.included {
                    background: #e8f8ef;
                    color: #167647;
                    }

                    .package-inclusion-status.excluded {
                    background: #fff4e8;
                    color: #a65d12;
                    }

                    .hotel-details {
                    margin-top: 22px;
                    }

                    .hotel-details-heading {
                    margin-bottom: 20px;
                    }

                    .hotel-details-heading h2 {
                    margin-bottom: 7px;
                    }

                    .hotel-details-heading p {
                    display: flex;
                    align-items: center;
                    gap: 7px;
                    color: #647b8d;
                    font-size: 13px;
                    }

                    .hotel-photo-grid {
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                    gap: 14px;
                    margin-bottom: 22px;
                    }

                    .hotel-photo {
                    min-height: 220px;
                    border: 1px solid #e2eaf0;
                    border-radius: 14px;
                    box-shadow: 0 7px 18px rgba(15, 46, 69, .08);
                    }

                    .hotel-photo img {
                    min-height: 220px;
                    transition: transform 350ms ease;
                    }

                    .hotel-photo:hover img {
                    transform: scale(1.035);
                    }

                    .hotel-photo-empty {
                    min-height: 220px;
                    border-color: #d5e3eb;
                    border-radius: 14px;
                    }

                    .hotel-details-description {
                    max-width: 960px;
                    color: #586e7f;
                    font-size: 14px;
                    line-height: 1.8;
                    }

                    .side-stack:empty {
                    display: none;
                    }

                    .toast {
                    z-index: 10;
                    border: 1px solid rgba(255, 255, 255, .12);
                    box-shadow: 0 12px 32px rgba(2, 6, 23, .2);
                    }

                    /* Responsive */
                    @media (max-width: 850px) {
                    .hero,
                    .content {
                        grid-template-columns: 1fr;
                    }

                    .gallery {
                        min-height: 430px;
                    }

                    .side-stack {
                        grid-template-columns: 1fr 1fr;
                    }

                    .booking-card {
                        position: static;
                        order: initial;
                    }
                    }

                    @media (max-width: 560px) {
                    .page {
                        padding: 14px 14px 38px;
                    }

                    .topbar {
                        align-items: center;
                        gap: 10px;
                        margin-bottom: 18px;
                    }

                    .topbar .brand small {
                        display: none;
                    }

                    .brand {
                        gap: 9px;
                    }

                    .logo {
                        width: 40px;
                        height: 40px;
                        border-radius: 11px;
                    }

                    .brand strong {
                        font-size: 13px;
                    }

                    .back {
                        min-height: 36px;
                        padding: 0 10px;
                        font-size: 11px;
                        white-space: nowrap;
                    }

                    .hero {
                        gap: 16px;
                    }

                    .gallery {
                        min-height: 360px;
                        border-radius: 17px;
                    }

                    .side-stack {
                        grid-template-columns: 1fr;
                    }

                    .gallery-caption {
                        right: 18px;
                        left: 18px;
                        bottom: 20px;
                    }

                    .gallery-caption h1 {
                        margin-top: 11px;
                        font-size: 32px;
                    }

                    .booking-card,
                    .section,
                    .trip-route,
                    .hotel-details {
                        padding: 19px;
                        border-radius: 15px;
                    }

                    .content {
                        margin-top: 16px;
                    }

                    .section h2,
                    .hotel-details-heading h2 {
                        font-size: 20px;
                    }

                    .day {
                        grid-template-columns: 66px minmax(0, 1fr);
                        gap: 10px;
                        padding: 15px 0;
                    }

                    .day strong {
                        padding: 0 8px;
                        font-size: 9px;
                    }

                    .package-inclusions li {
                    grid-template-columns: 1fr auto;
                    gap: 5px 8px;
                    }

                    .package-inclusion-category {
                    grid-column: 1 / -1;
                    }

                    .hotel-photo-grid {
                        grid-template-columns: 1fr;
                    }

                    .hotel-photo,
                    .hotel-photo img,
                    .hotel-photo-empty {
                        min-height: 210px;
                    }

                    .hotel-lightbox {
                        padding: 16px;
                    }

                    .hotel-lightbox-image {
                        max-height: calc(100vh - 100px);
                    }

                    .hotel-lightbox-close {
                        top: 10px;
                        right: 10px;
                    }

                    .hotel-lightbox-nav {
                        width: 40px;
                        height: 40px;
                    }

                    .hotel-lightbox-prev {
                        left: 8px;
                    }

                    .hotel-lightbox-next {
                        right: 8px;
                    }
                    }
            </style>
        </head>
    <body>
        <datalist id="mindanaoTransportLocations">
            <option value="General Santos International Airport (GES)"></option>
            <option value="Francisco Bangoy International Airport (DVO), Davao City"></option>
            <option value="Laguindingan Airport (CGY), Cagayan de Oro"></option>
            <option value="Sayak Airport (IAO), Siargao"></option>
            <option value="Surigao City Port"></option>
            <option value="Dapa Port, Siargao"></option>
            <option value="Sasa Port, Davao City"></option>
            <option value="Macabalan Port, Cagayan de Oro"></option>
            <option value="Benoni Port, Camiguin"></option>
        </datalist>
        <main class="page">
            <header class="topbar">
                <a class="brand" href="dashboard.php">
                    <div class="logo">
                        <img src="../assets/logo/logo.png" alt="MTR logo">
                    </div>
                    <div>
                        <strong>Mindanao Travel & Tours</strong>
                        <small>Plan your next escape</small>
                    </div>
                </a>
                <a class="back" href="dashboard.php">← Back to Dashboard</a>
            </header>

            <section class="hero">
                <div class="gallery" id="gallery" aria-label="Package photos">
                    <?php foreach ($images as $index => $image):
                        $imageUrl = preg_match('/^https?:\/\//i', $image) ? $image : '../' . ltrim($image, '/');
                    ?>
                    <div class="gallery-image<?= $index === 0 ? ' active' : '' ?>" style="background-image:url('<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>')"></div>
                    <?php endforeach; ?>
                    <button class="gallery-arrow prev" id="prev" aria-label="Previous image">‹</button>
                    <button class="gallery-arrow next" id="next" aria-label="Next image">›</button>
                    <div class="gallery-dots" id="dots"></div>
                    <div class="gallery-caption">
                        <span id="location"><?= htmlspecialchars($destinationLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <h1 id="packageTitle"><?= htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8') ?></h1>
                    </div>
                </div>
                <aside class="booking-card">
                    <div class="muted">Package from</div>
                    <div class="price" id="packagePrice">₱ <?= number_format((float) $priceTiers[0]['price_per_pax'], 2) ?></div>
                    <div class="muted">Flexible plans · Local support · Secure booking</div>
                    <div class="included-hotel">
                        <div class="included-hotel-label">Hotel included with this package</div>
                        <?php if ($package['hotel_name']): ?>
                        <strong><?= htmlspecialchars($package['hotel_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(implode(' · ', array_filter([$package['hotel_location'], $package['hotel_region']])), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                        <strong>Hotel details unavailable</strong>
                        <span>Please contact us before booking this package.</span>
                        <?php endif; ?>
                    </div>
                    <label class="field">
                        <span>Travel date</span>
                        <input id="date" type="date">
                    </label>
                    <label class="field">
                        <span>Travelers</span>
                        <select id="travelers">
                            <?php for ($pax = 1; $pax <= $maxPax; $pax++): ?>
                            <option value="<?= $pax ?>"<?= $pax === min(2, $maxPax) ? ' selected' : '' ?>><?= $pax ?> <?= $pax === 1 ? 'traveler' : 'travelers' ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label class="field pickup-location-field" id="pickupLocationField">
                        <span>Pickup location or airport</span>
                        <input id="pickupLocation" list="mindanaoTransportLocations" type="text" maxlength="180" placeholder="e.g. Siargao Airport" required>
                    </label>
                    <label class="pickup-custom-toggle" for="customPickupToggle">
                        <input id="customPickupToggle" type="checkbox" aria-controls="customPickupAddressField">
                        <span><strong>Would you like pickup from a custom location, such as your hotel?</strong><small>Select this to enter a specific hotel or pickup address instead.</small></span>
                    </label>
                    <div class="pickup-custom-address" id="customPickupAddressField" hidden>
                        <label class="field">
                            <span>Pickup address or hotel</span>
                            <input id="pickupAddress" type="text" maxlength="500" placeholder="Enter the hotel name or complete pickup address">
                        </label>
                        <label class="field">
                            <span>Pickup notes <small>(optional)</small></span>
                            <textarea id="pickupNotes" maxlength="1000" rows="2" placeholder="Add a meeting point, arrival time, or other instructions"></textarea>
                        </label>
                    </div>
                    <label class="field">
                        <span>Departure location</span>
                        <input id="departureLocation" list="mindanaoTransportLocations" type="text" maxlength="180" placeholder="e.g. Dapa Port, Siargao" required>
                    </label>
                    <p class="pickup-plan-note" id="pickupPlanNote" hidden></p>
                    <div class="total">
                        <span>Estimated total</span>
                        <span id="total">₱ <?= number_format((float) $priceTiers[0]['price_per_pax'] * min(2, $maxPax), 2) ?></span>
                    </div>
                    <button class="button" id="book">Book this package</button>
                </aside>
            </section>

            <section class="content">
                <div class="section">
                    <h2>About this package</h2>
                    <p id="description"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 style="margin-top:26px"><?= $itinerary ? 'Day-by-day itinerary' : 'Sample itinerary' ?></h2>
                    <div class="itinerary">
                        <?php foreach ($itinerary as $itineraryDay): ?>
                        <div class="day package-day" data-package-day="<?= (int) $itineraryDay['day_number'] ?>">
                            <strong>DAY <?= (int) $itineraryDay['day_number'] ?></strong>
                            <div>
                                <h3><?= htmlspecialchars($itineraryDay['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <?php if (!empty($itineraryDay['activity'])): ?><div class="muted"><?= htmlspecialchars($itineraryDay['activity'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                <?php if (!empty($itineraryDay['inclusions'])): ?>
                                <ul class="package-inclusions">
                                    <?php foreach ($itineraryDay['inclusions'] as $inclusion): ?>
                                    <li>
                                        <span class="package-inclusion-category"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $inclusion['category'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars($inclusion['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong class="package-inclusion-status<?= (int) $inclusion['is_included'] === 1 ? ' included' : ' excluded' ?>"><?= (int) $inclusion['is_included'] === 1 ? 'Included' : 'Not included' ?></strong>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="side-stack"></div>
            </section>
            <?php if ($package['hotel_name']): ?>
            <section class="hotel-details" aria-labelledby="hotelDetailsTitle">
                <div class="hotel-details-heading">
                    <h2 id="hotelDetailsTitle">Stay at <?= htmlspecialchars($package['hotel_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars(implode(' · ', array_filter([$package['hotel_location'], $package['hotel_region']])), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="hotel-photo-grid">
                    <?php if ($hotelImages): foreach ($hotelImages as $hotelImage): ?>
                    <button class="hotel-photo" type="button" data-hotel-photo="<?= htmlspecialchars($hotelImage, ENT_QUOTES, 'UTF-8') ?>" aria-label="Enlarge photo of <?= htmlspecialchars($package['hotel_name'], ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($hotelImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($package['hotel_name'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="hotel-photo-hint" aria-hidden="true">View photo</span>
                    </button>
                    <?php endforeach; else: ?>
                    <div class="hotel-photo-empty">Hotel photos are not available yet.</div>
                    <?php endif; ?>
                </div>
                <p class="hotel-details-description"><?= htmlspecialchars(trim((string) $package['hotel_description']) ?: 'Hotel description is not available yet.', ENT_QUOTES, 'UTF-8') ?></p>
            </section>
            <?php endif; ?>
            <section class="trip-route" aria-labelledby="tripRouteTitle">
                <div class="trip-route-heading">
                    <h2 id="tripRouteTitle">Trip route</h2>
                    <p>Planned stop sequence based on this package itinerary.</p>
                </div>
                <ol class="trip-route-stops" id="tripRouteStops" aria-live="polite"></ol>
                <p class="trip-route-note">Route order is an itinerary overview and may not represent exact road or travel directions.</p>
            </section>
        </main>
        <?php if ($package['hotel_name'] && $hotelImages): ?>
        <div class="hotel-lightbox" id="hotelLightbox" role="dialog" aria-modal="true" aria-label="Hotel photo viewer" aria-describedby="hotelLightboxCaption" tabindex="-1" hidden>
            <button class="hotel-lightbox-close" id="hotelLightboxClose" type="button" aria-label="Close photo viewer">×</button>
            <button class="hotel-lightbox-nav hotel-lightbox-prev" id="hotelLightboxPrev" type="button" aria-label="Previous hotel photo">‹</button>
            <div class="hotel-lightbox-content">
                <img class="hotel-lightbox-image" id="hotelLightboxImage" src="" alt="">
                <div class="hotel-lightbox-caption" id="hotelLightboxCaption"></div>
            </div>
            <button class="hotel-lightbox-nav hotel-lightbox-next" id="hotelLightboxNext" type="button" aria-label="Next hotel photo">›</button>
        </div>
        <?php endif; ?>
        <div class="toast" id="toast" role="status"></div>
        <script>
            (() => {
                const bookButton = document.querySelector('#book');
                const date = document.querySelector('#date');
                const travelers = document.querySelector('#travelers');
                const pickupLocation = document.querySelector('#pickupLocation');
                const pickupLocationField = document.querySelector('#pickupLocationField');
                const customPickupToggle = document.querySelector('#customPickupToggle');
                const customPickupAddressField = document.querySelector('#customPickupAddressField');
                const departureLocation = document.querySelector('#departureLocation');
                const pickupAddress = document.querySelector('#pickupAddress');
                const pickupNotes = document.querySelector('#pickupNotes');
                const total = document.querySelector('#total');
                const packagePrice = document.querySelector('#packagePrice');
                const pickupPlanNote = document.querySelector('#pickupPlanNote');
                const itinerary = document.querySelector('.itinerary');
                const packageDays = Array.from(document.querySelectorAll('.package-day'));
                const tripRouteList = document.querySelector('#tripRouteStops');
                const tripRouteItinerary = <?= json_encode($tripRouteStops, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                const packageRegions = <?= json_encode($packageRegionNames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                const pickupRegionKeywords = <?= json_encode($pickupRegionKeywords, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                let pickupTransferDayElement = null;
                const priceTiers = <?= json_encode($priceTiers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                const packageId = <?= (int) $packageId ?>;
                const formatPrice = value => `₱ ${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                const getPricePerPax = pax => {
                    const tier = priceTiers.find(item => pax >= Number(item.min_pax) && pax <= Number(item.max_pax));
                    return tier ? Number(tier.price_per_pax) : Number(priceTiers[priceTiers.length - 1].price_per_pax);
                };
                const updateTotal = () => {
                    const pax = Number(travelers.value);
                    const pricePerPax = getPricePerPax(pax);
                    packagePrice.textContent = formatPrice(pricePerPax);
                    total.textContent = formatPrice(pricePerPax * pax);
                };

                travelers.addEventListener('change', updateTotal);
                updateTotal();
                const resolvePickupRegion = location => {
                    const normalized = location.trim().toLowerCase();
                    if (!normalized) return null;
                    for (const [region, keywords] of Object.entries(pickupRegionKeywords)) {
                        if (keywords.some(keyword => normalized.includes(keyword.toLowerCase())) || normalized.includes(region.toLowerCase())) {
                            return region;
                        }
                    }
                    return null;
                };
                const getPickupSource = () => customPickupToggle.checked ? pickupAddress.value.trim() : pickupLocation.value.trim();
                const renderTripRoute = () => {
                    const stops = [
                        { name: getPickupSource() || 'Enter your pickup point above', type: 'Pickup', marker: 'S', className: 'start' },
                        ...tripRouteItinerary.map((day, index) => ({
                            name: day.title,
                            type: `Day ${Number(day.day_number) + (pickupTransferDayElement?.isConnected ? 1 : 0)} · Stop ${index + 1}${day.location ? ` · ${day.location}` : ''}`,
                            marker: String(index + 1),
                            className: ''
                        })),
                        { name: departureLocation.value.trim() || 'Enter your departure point above', type: 'Departure', marker: 'E', className: 'end' }
                    ];
                    tripRouteList.replaceChildren();
                    stops.forEach(stop => {
                        const item = document.createElement('li');
                        item.className = `trip-route-stop ${stop.className}`;
                        const marker = document.createElement('span');
                        marker.className = 'trip-route-marker';
                        marker.setAttribute('aria-hidden', 'true');
                        marker.textContent = stop.marker;
                        const copy = document.createElement('div');
                        copy.className = 'trip-route-stop-copy';
                        const name = document.createElement('strong');
                        name.textContent = stop.name;
                        const type = document.createElement('span');
                        type.textContent = stop.type;
                        copy.append(name, type);
                        item.append(marker, copy);
                        tripRouteList.appendChild(item);
                    });
                };
                const updatePickupFields = () => {
                    pickupLocationField.hidden = customPickupToggle.checked;
                    customPickupAddressField.hidden = !customPickupToggle.checked;
                    pickupLocation.required = !customPickupToggle.checked;
                    pickupAddress.required = customPickupToggle.checked;
                    customPickupToggle.setAttribute('aria-expanded', String(customPickupToggle.checked));
                    customPickupToggle.closest('.pickup-custom-toggle').classList.toggle('is-selected', customPickupToggle.checked);
                    updatePickupPlan();
                };
                const updatePickupPlan = () => {
                    const pickupSource = getPickupSource();
                    const pickupRegion = resolvePickupRegion(pickupSource);
                    const needsTransferDay = pickupSource !== ''
                        && (pickupRegion === null || !packageRegions.includes(pickupRegion));
                    packageDays.forEach(day => {
                        const baseDay = Number(day.dataset.packageDay);
                        day.querySelector('strong').textContent = `DAY ${baseDay + (needsTransferDay ? 1 : 0)}`;
                    });
                    if (needsTransferDay) {
                        if (!pickupTransferDayElement) {
                            pickupTransferDayElement = document.createElement('div');
                            pickupTransferDayElement.className = 'day';
                            pickupTransferDayElement.innerHTML = '<strong>DAY 1</strong><div><h3>Pickup and travel to the package region</h3><div class="muted">This day is reserved for travel from your pickup location before the package itinerary begins.</div></div>';
                        }
                        itinerary.prepend(pickupTransferDayElement);
                        pickupPlanNote.hidden = false;
                        pickupPlanNote.textContent = pickupRegion
                            ? `Your pickup location is outside the package region (${pickupRegion}). Day 1 is reserved for travel; the package itinerary starts on Day 2.`
                            : 'We could not identify your pickup region. Day 1 is reserved for travel to the package region; the itinerary starts on Day 2.';
                    } else {
                        pickupTransferDayElement?.remove();
                        pickupPlanNote.hidden = true;
                        pickupPlanNote.textContent = '';
                    }
                    renderTripRoute();
                };
                pickupLocation.addEventListener('input', updatePickupPlan);
                pickupAddress.addEventListener('input', updatePickupPlan);
                departureLocation.addEventListener('input', renderTripRoute);
                customPickupToggle.addEventListener('change', updatePickupFields);
                updatePickupFields();

                const hotelPhotoButtons = Array.from(document.querySelectorAll('[data-hotel-photo]'));
                const hotelLightbox = document.querySelector('#hotelLightbox');
                if (hotelLightbox && hotelPhotoButtons.length) {
                    const lightboxImage = document.querySelector('#hotelLightboxImage');
                    const lightboxCaption = document.querySelector('#hotelLightboxCaption');
                    const closeLightboxButton = document.querySelector('#hotelLightboxClose');
                    const previousPhotoButton = document.querySelector('#hotelLightboxPrev');
                    const nextPhotoButton = document.querySelector('#hotelLightboxNext');
                    let activeHotelPhoto = 0;
                    let lastFocusedHotelPhoto = null;
                    const showHotelPhoto = index => {
                        activeHotelPhoto = (index + hotelPhotoButtons.length) % hotelPhotoButtons.length;
                        const photo = hotelPhotoButtons[activeHotelPhoto];
                        lightboxImage.src = photo.dataset.hotelPhoto;
                        lightboxImage.alt = photo.querySelector('img').alt;
                        lightboxCaption.textContent = `${<?= json_encode($package['hotel_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>} · Photo ${activeHotelPhoto + 1} of ${hotelPhotoButtons.length}`;
                    };
                    const openHotelLightbox = index => {
                        lastFocusedHotelPhoto = hotelPhotoButtons[index];
                        showHotelPhoto(index);
                        hotelLightbox.hidden = false;
                        document.body.style.overflow = 'hidden';
                        closeLightboxButton.focus();
                    };
                    const closeHotelLightbox = () => {
                        hotelLightbox.hidden = true;
                        document.body.style.overflow = '';
                        lastFocusedHotelPhoto?.focus();
                    };
                    hotelPhotoButtons.forEach((button, index) => button.addEventListener('click', () => openHotelLightbox(index)));
                    closeLightboxButton.addEventListener('click', closeHotelLightbox);
                    previousPhotoButton.addEventListener('click', () => showHotelPhoto(activeHotelPhoto - 1));
                    nextPhotoButton.addEventListener('click', () => showHotelPhoto(activeHotelPhoto + 1));
                    hotelLightbox.addEventListener('click', event => {
                        if (event.target === hotelLightbox) closeHotelLightbox();
                    });
                    hotelLightbox.addEventListener('keydown', event => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            closeHotelLightbox();
                        } else if (event.key === 'ArrowLeft' && hotelPhotoButtons.length > 1) {
                            showHotelPhoto(activeHotelPhoto - 1);
                        } else if (event.key === 'ArrowRight' && hotelPhotoButtons.length > 1) {
                            showHotelPhoto(activeHotelPhoto + 1);
                        }
                    });
                    if (hotelPhotoButtons.length < 2) {
                        previousPhotoButton.hidden = true;
                        nextPhotoButton.hidden = true;
                    }
                }

                const galleryImages = Array.from(document.querySelectorAll('.gallery-image'));
                const dots = document.querySelector('#dots');
                let currentImage = 0;
                const showImage = index => {
                    currentImage = (index + galleryImages.length) % galleryImages.length;
                    galleryImages.forEach((image, imageIndex) => image.classList.toggle('active', imageIndex === currentImage));
                    dots.querySelectorAll('.gallery-dot').forEach((dot, dotIndex) => dot.classList.toggle('active', dotIndex === currentImage));
                };
                galleryImages.forEach((_, imageIndex) => {
                    const dot = document.createElement('button');
                    dot.className = `gallery-dot${imageIndex === 0 ? ' active' : ''}`;
                    dot.type = 'button';
                    dot.setAttribute('aria-label', `Show image ${imageIndex + 1}`);
                    dot.addEventListener('click', () => showImage(imageIndex));
                    dots.appendChild(dot);
                });
                document.querySelector('#prev').addEventListener('click', () => showImage(currentImage - 1));
                document.querySelector('#next').addEventListener('click', () => showImage(currentImage + 1));
                if (galleryImages.length < 2) {
                    document.querySelector('#prev').hidden = true;
                    document.querySelector('#next').hidden = true;
                }

                const openPayment = async () => {
                    if (!date.value) {
                        date.focus();
                        return;
                    }
                    const pickupSource = getPickupSource();
                    if (!pickupSource) {
                        (customPickupToggle.checked ? pickupAddress : pickupLocation).focus();
                        window.alert(customPickupToggle.checked ? 'Enter your custom pickup address or hotel.' : 'Enter your pickup location or airport.');
                        return;
                    }
                    if (!departureLocation.value.trim()) {
                        departureLocation.focus();
                        window.alert('Enter your departure location.');
                        return;
                    }
                    bookButton.disabled = true;
                    try {
                        const response = await fetch(`package.php?id=${packageId}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ travel_date: date.value, travelers: travelers.value, pickup_location: customPickupToggle.checked ? '' : pickupLocation.value, departure_location: departureLocation.value, pickup_address: customPickupToggle.checked ? pickupAddress.value : '', pickup_notes: customPickupToggle.checked ? pickupNotes.value : '' })
                        });
                        const result = await response.json();
                        if (!response.ok || !result.success) throw new Error(result.error || 'Unable to create this booking.');
                        window.location.href = `payment.php?booking_id=${result.booking_id}`;
                    } catch (error) {
                        window.alert(error.message);
                        bookButton.disabled = false;
                    }
                };

                bookButton.addEventListener('click', openPayment);
            })();
        </script>
    </body>
    </html>