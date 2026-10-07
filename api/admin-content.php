<?php
header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Only GET and POST requests are allowed.']);
    exit;
}

$database = require_once __DIR__ . '/db_con.php';
require_once __DIR__ . '/customer-assignment.php';
require_once __DIR__ . '/vehicle-availability.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/auth.php';
$adminUserId = require_user_auth(true);
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'employee'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only authorized staff can access this area.']);
    exit;
}
mtrEnsureVehicleBookingColumns($database);

function mtrDetectRefundAmount(string $filePath, string $mimeType): array
{
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['status' => 'unsupported', 'amount' => null, 'text' => 'OCR supports image receipts only.'];
    }
    $configuredPath = getenv('TESSERACT_PATH') ?: '';
    $candidates = array_filter([$configuredPath, 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe', 'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe', 'tesseract']);
    $tesseract = '';
    foreach ($candidates as $candidate) {
        if ($candidate === 'tesseract' || is_file($candidate)) {
            $tesseract = $candidate;
            break;
        }
    }
    if ($tesseract === '') {
        return ['status' => 'unavailable', 'amount' => null, 'text' => 'Tesseract OCR is not installed.'];
    }
    $command = escapeshellarg($tesseract) . ' ' . escapeshellarg($filePath) . ' stdout 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    $text = trim(implode("\n", $output));
    if ($exitCode !== 0 || $text === '') {
        return ['status' => 'unreadable', 'amount' => null, 'text' => $text];
    }
    $amounts = [];
    if (preg_match_all('/(?:PHP|P|₱)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $matches)) {
        foreach ($matches[1] as $value) {
            $amounts[] = (float) str_replace(',', '', $value);
        }
    }
    if (!$amounts && preg_match_all('/\b([0-9][0-9,]*\.[0-9]{2})\b/', $text, $matches)) {
        foreach ($matches[1] as $value) {
            $amounts[] = (float) str_replace(',', '', $value);
        }
    }
    return ['status' => $amounts ? 'matched_candidate' : 'amount_not_found', 'amount' => $amounts[0] ?? null, 'text' => $text];
}

$bookingColumns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('approval_status', $bookingColumns, true)) {
    $database->exec("ALTER TABLE bookings ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER booking_status");
}
if (!in_array('approval_note', $bookingColumns, true)) {
    $database->exec('ALTER TABLE bookings ADD COLUMN approval_note TEXT NULL AFTER approval_status');
}
if (!in_array('pickup_location', $bookingColumns, true)) {
    $database->exec("ALTER TABLE bookings ADD COLUMN pickup_location VARCHAR(180) NULL");
}
if (!in_array('pickup_address', $bookingColumns, true)) {
    $database->exec("ALTER TABLE bookings ADD COLUMN pickup_address VARCHAR(500) NULL");
}
if (!in_array('pickup_notes', $bookingColumns, true)) {
    $database->exec("ALTER TABLE bookings ADD COLUMN pickup_notes TEXT NULL");
}
foreach (['completion_proof' => 'VARCHAR(500) NULL', 'completion_submitted_at' => 'TIMESTAMP NULL', 'completion_verified_at' => 'TIMESTAMP NULL'] as $column => $definition) {
    if (!in_array($column, $bookingColumns, true)) {
        $database->exec("ALTER TABLE bookings ADD COLUMN {$column} {$definition}");
    }
}
$database->exec("CREATE TABLE IF NOT EXISTS app_migrations (
    migration_key VARCHAR(120) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$packageApprovalMigration = $database->prepare('SELECT migration_key FROM app_migrations WHERE migration_key = ?');
$packageApprovalMigration->execute(['package-bookings-require-employee-approval']);
if (!$packageApprovalMigration->fetchColumn()) {
    $database->beginTransaction();
    try {
        $database->exec("UPDATE bookings
            SET approval_status = 'pending'
            WHERE approval_status = 'approved'
              AND booking_status = 'pending'
              AND payment_status NOT IN ('paid', 'verified')
              AND Travel_Pck_ID IS NOT NULL
              AND COALESCE(custom_title, '') = ''
              AND COALESCE(custom_details, '') = ''");
        $database->prepare('INSERT INTO app_migrations (migration_key) VALUES (?)')->execute(['package-bookings-require-employee-approval']);
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }
}

$database->exec("CREATE TABLE IF NOT EXISTS destinations (
    Destination_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    location VARCHAR(160) NULL,
    region VARCHAR(120) NULL DEFAULT 'Mindanao',
    category VARCHAR(50) NULL DEFAULT 'island',
    description TEXT NULL,
    days VARCHAR(50) NULL DEFAULT '1-2 days',
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    image_url VARCHAR(500) NULL,
    tags VARCHAR(255) NULL DEFAULT '',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$destinationColumns = $database->query("SHOW COLUMNS FROM destinations")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('location', $destinationColumns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN location VARCHAR(160) NULL AFTER name");
}
if (!in_array('pickup_location', $destinationColumns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN pickup_location VARCHAR(180) NULL AFTER location");
}
if (!in_array('departure_location', $destinationColumns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN departure_location VARCHAR(180) NULL AFTER pickup_location");
}

$database->exec("CREATE TABLE IF NOT EXISTS destination_images (
    Destination_Image_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Destination_ID INT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_destination_image_destination FOREIGN KEY (Destination_ID) REFERENCES destinations(Destination_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$database->exec("CREATE TABLE IF NOT EXISTS travel_packages (
    Travel_Pck_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Destination_ID INT UNSIGNED NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    duration_days TINYINT UNSIGNED NOT NULL,
    price_per_person DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('published', 'draft', 'archived') NOT NULL DEFAULT 'draft',
    image_url VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_package_destination FOREIGN KEY (Destination_ID) REFERENCES destinations(Destination_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$database->exec("CREATE TABLE IF NOT EXISTS travel_package_destinations (
    Travel_Pck_ID INT UNSIGNED NOT NULL,
    Destination_ID INT UNSIGNED NOT NULL,
    PRIMARY KEY (Travel_Pck_ID, Destination_ID),
    CONSTRAINT fk_package_destination_package FOREIGN KEY (Travel_Pck_ID) REFERENCES travel_packages(Travel_Pck_ID) ON DELETE CASCADE,
    CONSTRAINT fk_package_destination_destination FOREIGN KEY (Destination_ID) REFERENCES destinations(Destination_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$database->exec("CREATE TABLE IF NOT EXISTS travel_package_regions (
    Travel_Pck_ID INT UNSIGNED NOT NULL,
    region VARCHAR(120) NOT NULL,
    PRIMARY KEY (Travel_Pck_ID, region),
    CONSTRAINT fk_package_region_package FOREIGN KEY (Travel_Pck_ID) REFERENCES travel_packages(Travel_Pck_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$database->exec("CREATE TABLE IF NOT EXISTS travel_package_price_tiers (
    Travel_Pck_Price_Tier_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Travel_Pck_ID INT UNSIGNED NOT NULL,
    min_pax TINYINT UNSIGNED NOT NULL,
    max_pax TINYINT UNSIGNED NOT NULL,
    price_per_pax DECIMAL(10, 2) NOT NULL,
    CONSTRAINT fk_package_price_tier_package FOREIGN KEY (Travel_Pck_ID) REFERENCES travel_packages(Travel_Pck_ID) ON DELETE CASCADE,
    CONSTRAINT chk_package_price_tier_range CHECK (max_pax >= min_pax)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

$database->exec("CREATE TABLE IF NOT EXISTS hotels (
    Hotel_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    location VARCHAR(120) NOT NULL,
    region VARCHAR(120) NULL DEFAULT 'Mindanao',
    description TEXT NULL,
    price_per_night DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    amenities TEXT NULL,
    rating DECIMAL(2, 1) NULL DEFAULT 0.0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$packageColumns = $database->query("SHOW COLUMNS FROM travel_packages")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('Hotel_ID', $packageColumns, true)) {
    $database->exec("ALTER TABLE travel_packages ADD COLUMN Hotel_ID INT UNSIGNED NULL AFTER Destination_ID");
}
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

$database->exec("CREATE TABLE IF NOT EXISTS hotel_images (
    Hotel_Image_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Hotel_ID INT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hotel_image_hotel FOREIGN KEY (Hotel_ID) REFERENCES hotels(Hotel_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$database->exec("CREATE TABLE IF NOT EXISTS support_tickets (
    Ticket_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL,
    subject VARCHAR(180) NOT NULL DEFAULT 'General Inquiry',
    status ENUM('open', 'pending', 'closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_user_admin_early FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$isEmployee = ($_SESSION['user_role'] ?? '') === 'employee';
if ($isEmployee) {
    mtrBackfillCustomerEmployeeAssignments($database);
}
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
    CONSTRAINT fk_cancellation_booking_admin_early FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
    CONSTRAINT fk_cancellation_payment_admin_early FOREIGN KEY (Payment_ID) REFERENCES payments(Payment_ID) ON DELETE SET NULL,
    CONSTRAINT fk_cancellation_user_admin_early FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    CONSTRAINT fk_cancellation_ticket_admin_early FOREIGN KEY (Ticket_ID) REFERENCES support_tickets(Ticket_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$database->exec("ALTER TABLE cancellation_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'refund_sent', 'partially_refunded') NOT NULL DEFAULT 'pending'");

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

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $destinations = $database->query("SELECT Destination_ID AS id, name, location, pickup_location, departure_location, region, category, description, price, image_url, status FROM destinations ORDER BY name")->fetchAll();
        $destinationImages = $database->query('SELECT Destination_Image_ID AS id, Destination_ID AS destination_id, image_path FROM destination_images ORDER BY Destination_Image_ID')->fetchAll();
        foreach ($destinations as &$destination) {
            $destination['image_paths'] = [];
            $destination['images'] = [];
            foreach ($destinationImages as $destinationImage) {
                if ((int) $destinationImage['destination_id'] === (int) $destination['id']) {
                    $destination['image_paths'][] = $destinationImage['image_path'];
                    $destination['images'][] = ['id' => (int) $destinationImage['id'], 'path' => $destinationImage['image_path']];
                }
            }
            if (!$destination['image_paths'] && $destination['image_url']) {
                $destination['image_paths'][] = $destination['image_url'];
            }
        }
        unset($destination);
        $database->exec("UPDATE travel_packages
            SET status = 'draft', published_at = NULL
            WHERE status = 'published'
              AND visibility_minutes IS NOT NULL
              AND published_at IS NOT NULL
              AND DATE_ADD(published_at, INTERVAL visibility_minutes MINUTE) <= NOW()");
        $packages = $database->query("SELECT p.Travel_Pck_ID AS id, p.Destination_ID AS legacy_destination_id, p.Hotel_ID AS hotel_id, h.name AS hotel_name, p.name, p.description, p.duration_days, p.visibility_minutes, p.published_at, p.include_departure_day, p.departure_spot, p.pickup_location, p.price_per_person, p.image_url, p.status,
            GROUP_CONCAT(DISTINCT COALESCE(d.name, legacy_d.name) ORDER BY COALESCE(d.name, legacy_d.name) SEPARATOR ', ') AS destination_name
            FROM travel_packages p
            LEFT JOIN hotels h ON h.Hotel_ID = p.Hotel_ID
            LEFT JOIN travel_package_destinations pd ON pd.Travel_Pck_ID = p.Travel_Pck_ID
            LEFT JOIN destinations d ON d.Destination_ID = pd.Destination_ID
            LEFT JOIN destinations legacy_d ON legacy_d.Destination_ID = p.Destination_ID
            WHERE p.status <> 'archived'
            GROUP BY p.Travel_Pck_ID
            ORDER BY p.created_at DESC")->fetchAll();
        $packageDestinations = $database->query('SELECT Travel_Pck_ID, Destination_ID FROM travel_package_destinations')->fetchAll();
        $packageRegions = $database->query('SELECT Travel_Pck_ID, region FROM travel_package_regions ORDER BY region')->fetchAll();
        $packageTiers = $database->query('SELECT Travel_Pck_ID, min_pax, max_pax, price_per_pax FROM travel_package_price_tiers ORDER BY min_pax')->fetchAll();
        $packageItineraries = $database->query('SELECT Travel_Pck_ID, day_number, title, activity FROM travel_package_itinerary ORDER BY day_number')->fetchAll();
        $packageItineraryItems = $database->query('SELECT i.Travel_Pck_ID, i.day_number, item.category, item.name, item.is_included
            FROM travel_package_itinerary_items item
            INNER JOIN travel_package_itinerary i ON i.Travel_Pck_Itinerary_ID = item.Travel_Pck_Itinerary_ID
            ORDER BY i.day_number, item.Travel_Pck_Itinerary_Item_ID')->fetchAll();
        foreach ($packages as &$package) {
            $package['destination_ids'] = array_values(array_map(
                static fn ($item) => (int) $item['Destination_ID'],
                array_filter($packageDestinations, static fn ($item) => (int) $item['Travel_Pck_ID'] === (int) $package['id'])
            ));
            if (!$package['destination_ids'] && $package['legacy_destination_id']) {
                $package['destination_ids'] = [(int) $package['legacy_destination_id']];
            }
            $package['region_names'] = array_values(array_map(
                static fn ($item) => (string) $item['region'],
                array_filter($packageRegions, static fn ($item) => (int) $item['Travel_Pck_ID'] === (int) $package['id'])
            ));
            $package['price_tiers'] = array_values(array_map(
                static fn ($item) => ['min_pax' => (int) $item['min_pax'], 'max_pax' => (int) $item['max_pax'], 'price_per_pax' => (float) $item['price_per_pax']],
                array_filter($packageTiers, static fn ($item) => (int) $item['Travel_Pck_ID'] === (int) $package['id'])
            ));
            $package['itinerary'] = array_values(array_map(
                static function ($item) use ($packageItineraryItems, $package) {
                    $items = array_filter($packageItineraryItems, static fn ($inclusion) =>
                        (int) $inclusion['Travel_Pck_ID'] === (int) $package['id']
                        && (int) $inclusion['day_number'] === (int) $item['day_number']
                    );
                    return [
                        'day_number' => (int) $item['day_number'],
                        'title' => $item['title'],
                        'activity' => $item['activity'],
                        'inclusions' => array_values(array_map(static fn ($inclusion) => [
                            'category' => $inclusion['category'],
                            'name' => $inclusion['name'],
                            'is_included' => (bool) $inclusion['is_included']
                        ], $items))
                    ];
                },
                array_filter($packageItineraries, static fn ($item) => (int) $item['Travel_Pck_ID'] === (int) $package['id'])
            ));
        }
        unset($package);
        $driverScope = (string) ($_GET['driver_scope'] ?? 'approved');
        $driverStatusFilter = $driverScope === 'applicants' ? "AND dp.verification_status IN ('pending', 'rejected')" : "AND dp.verification_status = 'approved'";
        $drivers = $database->query("SELECT dp.Driver_Profile_ID AS id, dp.User_ID AS user_id, u.full_name, u.email, u.phone, dp.training_certificate_file, dp.license_file, dp.test_drive_file, dp.training_completed, dp.is_available, dp.verification_status, dp.created_at, dp.company_vehicle_id, cv.vehicle_unit AS company_vehicle_unit, cv.vehicle_color AS company_vehicle_color, cv.plate_number AS company_plate_number, cv.passenger_capacity AS company_passenger_capacity,
            (SELECT b.Booking_ID FROM booking_assignments ba2 INNER JOIN bookings b ON b.Booking_ID = ba2.Booking_ID WHERE ba2.Driver_Profile_ID = dp.Driver_Profile_ID AND b.booking_status NOT IN ('cancelled', 'completed') ORDER BY b.created_at DESC LIMIT 1) AS assigned_booking_id,
            (SELECT cu.full_name FROM booking_assignments ba3 INNER JOIN bookings b3 ON b3.Booking_ID = ba3.Booking_ID INNER JOIN users cu ON cu.User_ID = b3.Customer_ID WHERE ba3.Driver_Profile_ID = dp.Driver_Profile_ID AND b3.booking_status NOT IN ('cancelled', 'completed') ORDER BY b3.created_at DESC LIMIT 1) AS assigned_customer_name,
            (SELECT COALESCE(p3.name, NULLIF(b4.custom_title, ''), 'Booked plan') FROM booking_assignments ba4 INNER JOIN bookings b4 ON b4.Booking_ID = ba4.Booking_ID LEFT JOIN travel_packages p3 ON p3.Travel_Pck_ID = b4.Travel_Pck_ID WHERE ba4.Driver_Profile_ID = dp.Driver_Profile_ID AND b4.booking_status NOT IN ('cancelled', 'completed') ORDER BY b4.created_at DESC LIMIT 1) AS assigned_package_name
            FROM driver_profiles dp INNER JOIN users u ON u.User_ID = dp.User_ID LEFT JOIN company_vehicles cv ON cv.Company_Vehicle_ID = dp.company_vehicle_id WHERE u.role = 'driver' {$driverStatusFilter} ORDER BY u.full_name")->fetchAll();
        foreach ($drivers as &$driver) {
            foreach (['training_certificate_file', 'license_file', 'test_drive_file'] as $fileField) {
                $driver[$fileField] = $driver[$fileField] ? 'data/uploads/' . ltrim($driver[$fileField], '/\\') : null;
            }
        }
        unset($driver);
        $staff = $database->query("SELECT u.User_ID AS id, u.full_name, u.email, u.phone, u.role, u.created_at, dp.Driver_Profile_ID AS driver_profile_id, dp.verification_status, dp.is_available, cv.vehicle_unit AS company_vehicle_unit, cv.plate_number AS company_plate_number FROM users u LEFT JOIN driver_profiles dp ON dp.User_ID = u.User_ID LEFT JOIN company_vehicles cv ON cv.Company_Vehicle_ID = dp.company_vehicle_id WHERE u.role IN ('employee', 'driver') ORDER BY u.full_name")->fetchAll();
        $employeeBookingFilter = $isEmployee ? "WHERE EXISTS (SELECT 1 FROM customer_employee_assignments cea WHERE cea.Customer_ID = b.Customer_ID AND cea.Employee_ID = " . (int) $adminUserId . ")" : '';
        $bookings = $database->query("SELECT b.Booking_ID AS id, b.custom_title, b.custom_details, b.travel_date, b.trip_duration_days, b.requested_vehicle_id, requested_vehicle.vehicle_unit AS requested_vehicle_name, b.guest_count, b.total_amount, b.payment_status, b.booking_status, b.approval_status, b.approval_note, b.pickup_location, b.departure_location, b.pickup_address, b.pickup_notes, b.accommodation_type, b.accommodation_name, b.accommodation_address, b.accommodation_maps_link, h.name AS hotel_name, h.location AS hotel_location, b.completion_proof, b.completion_submitted_at, b.completion_verified_at, u.full_name AS customer_name, COALESCE(p.name, NULLIF(b.custom_title, ''), 'Custom plan') AS package_name, ba.Driver_Profile_ID AS driver_id, dp.company_vehicle_id AS assigned_vehicle_id, du.full_name AS driver_name FROM bookings b INNER JOIN users u ON u.User_ID = b.Customer_ID LEFT JOIN hotels h ON h.Hotel_ID = b.Hotel_ID LEFT JOIN company_vehicles requested_vehicle ON requested_vehicle.Company_Vehicle_ID = b.requested_vehicle_id LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID LEFT JOIN booking_assignments ba ON ba.Booking_ID = b.Booking_ID LEFT JOIN driver_profiles dp ON dp.Driver_Profile_ID = ba.Driver_Profile_ID LEFT JOIN users du ON du.User_ID = dp.User_ID {$employeeBookingFilter} ORDER BY b.created_at DESC")->fetchAll();
        $paymentMethods = $database->query("SELECT Payment_Method_ID AS id, method_type, bank_name, account_name, account_number, qr_code_image, instructions, status FROM payment_methods ORDER BY Payment_Method_ID ASC")->fetchAll();
        $employeeUserFilter = $isEmployee ? "WHERE EXISTS (SELECT 1 FROM customer_employee_assignments cea WHERE cea.Customer_ID = e.User_ID AND cea.Employee_ID = " . (int) $adminUserId . ")" : '';
        $employeeCancellationFilter = $isEmployee ? "WHERE EXISTS (SELECT 1 FROM customer_employee_assignments cea WHERE cea.Customer_ID = cr.User_ID AND cea.Employee_ID = " . (int) $adminUserId . ")" : '';
        $employeePaymentFilter = $isEmployee ? "WHERE EXISTS (
            SELECT 1 FROM bookings b
            INNER JOIN customer_employee_assignments cea ON cea.Customer_ID = b.Customer_ID
            WHERE b.Booking_ID = p.Booking_ID AND cea.Employee_ID = " . (int) $adminUserId . "
        ) OR EXISTS (
            SELECT 1 FROM booking_extension_requests er
            INNER JOIN customer_employee_assignments cea ON cea.Customer_ID = er.User_ID
            WHERE er.Extension_ID = p.Extension_ID AND cea.Employee_ID = " . (int) $adminUserId . "
        )" : '';
        $payments = $database->query("SELECT p.Payment_ID AS id, p.Booking_ID AS booking_id, p.Extension_ID AS extension_id, p.method_type, p.customer_name, u.email, p.amount, p.reference_number, p.receipt_file, p.status, p.refund_status, p.refunded_amount, p.refund_reference, p.refund_proof, p.created_at FROM payments p LEFT JOIN users u ON u.User_ID = p.User_ID {$employeePaymentFilter} ORDER BY p.created_at DESC")->fetchAll();
        $extensionRequests = $database->query("SELECT e.Extension_ID AS id, e.Booking_ID AS booking_id, e.extra_days, e.requested_departure_date, e.request_details, e.requested_amount, e.approved_amount, e.status, e.admin_note, e.created_at, u.full_name AS customer_name, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan') AS package_name, pay.Payment_ID AS payment_id, pay.receipt_file AS payment_receipt, pay.status AS payment_status FROM booking_extension_requests e INNER JOIN bookings b ON b.Booking_ID = e.Booking_ID INNER JOIN users u ON u.User_ID = e.User_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID LEFT JOIN payments pay ON pay.Extension_ID = e.Extension_ID {$employeeUserFilter} ORDER BY e.created_at DESC")->fetchAll();
        $cancellationRequests = $database->query("SELECT cr.Cancellation_ID AS id, cr.Booking_ID AS booking_id, cr.Ticket_ID AS ticket_id, cr.reason, cr.requested_amount, cr.status, cr.created_at, u.full_name AS customer_name, u.email, b.travel_date, b.guest_count, b.total_amount, b.payment_status, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan') AS package_name, pay.Payment_ID AS payment_id, pay.amount AS paid_amount, pay.method_type, pay.reference_number, pay.refund_status, pay.refunded_amount, pay.refund_reference, pay.refund_proof, pay.refunded_at FROM cancellation_requests cr INNER JOIN users u ON u.User_ID = cr.User_ID INNER JOIN bookings b ON b.Booking_ID = cr.Booking_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID LEFT JOIN payments pay ON pay.Payment_ID = cr.Payment_ID {$employeeCancellationFilter} ORDER BY cr.created_at DESC")->fetchAll();
        $hotels = $database->query("SELECT Hotel_ID AS id, name, location, region, description, price_per_night, amenities, rating, status FROM hotels ORDER BY created_at DESC")->fetchAll();
        $hotelImages = $database->query('SELECT Hotel_Image_ID AS id, Hotel_ID AS hotel_id, image_path FROM hotel_images ORDER BY Hotel_Image_ID')->fetchAll();
        foreach ($hotels as &$hotel) {
            $hotel['image_paths'] = [];
            $hotel['images'] = [];
            foreach ($hotelImages as $hotelImage) {
                if ((int) $hotelImage['hotel_id'] === (int) $hotel['id']) {
                    $hotel['image_paths'][] = $hotelImage['image_path'];
                    $hotel['images'][] = ['id' => (int) $hotelImage['id'], 'path' => $hotelImage['image_path']];
                }
            }
        }
        unset($hotel);
        $vehicles = $database->query("SELECT cv.Company_Vehicle_ID AS id, cv.vehicle_unit, cv.vehicle_color, cv.plate_number, cv.passenger_capacity, cv.status, dp.Driver_Profile_ID AS assigned_driver_id, u.full_name AS assigned_driver_name FROM company_vehicles cv LEFT JOIN driver_profiles dp ON dp.company_vehicle_id = cv.Company_Vehicle_ID LEFT JOIN users u ON u.User_ID = dp.User_ID ORDER BY cv.vehicle_unit")->fetchAll();
        $vehicleImages = $database->query('SELECT Company_Vehicle_ID AS vehicle_id, image_path FROM company_vehicle_img ORDER BY Company_Vehicle_Img_ID')->fetchAll();
        foreach ($vehicles as &$vehicle) {
            $vehicle['image_paths'] = [];
            foreach ($vehicleImages as $vehicleImage) {
                if ((int) $vehicleImage['vehicle_id'] === (int) $vehicle['id']) {
                    $vehicle['image_paths'][] = $vehicleImage['image_path'];
                }
            }
        }
        unset($vehicle);

        echo json_encode([
            'destinations' => $destinations,
            'packages' => $packages,
            'drivers' => $drivers,
            'staff' => $staff,
            'bookings' => $bookings,
            'payment_methods' => $paymentMethods,
            'payments' => $payments,
            'extension_requests' => $extensionRequests,
            'cancellation_requests' => $cancellationRequests,
            'hotels' => $hotels,
            'vehicles' => $vehicles
        ]);
        exit;
    }

    $rawInput = json_decode(file_get_contents('php://input'), true);
    $payload = array_merge($_POST, is_array($rawInput) ? $rawInput : []);
    $type = (string) ($payload['type'] ?? '');
    if (($_SESSION['user_role'] ?? '') === 'employee' && !in_array($type, ['review_booking', 'review_extension', 'verify_completion', 'review_cancellation'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Employees can only approve plans, review extensions, verify trip completion, and review cancellations.']);
        exit;
    }

    if ($type === 'destination') {
        $destinationId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($payload['name'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));
        $region = trim((string) ($payload['region'] ?? ''));
        $category = trim((string) ($payload['category'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $price = filter_var($payload['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $imageFiles = $_FILES['images'] ?? null;
        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Spots';
        $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if ($name === '' || $location === '' || $region === '' || $category === '' || $price === false || $price < 0) {
            throw new InvalidArgumentException('Destination name, location, region, category, and a valid price are required.');
        }
        $isEditing = $destinationId !== false && $destinationId > 0;
        $newImageCount = $imageFiles && isset($imageFiles['name']) && is_array($imageFiles['name'])
            ? count(array_filter($imageFiles['name']))
            : 0;

        if (!$isEditing && $newImageCount < 1) {
            throw new InvalidArgumentException('Upload at least one destination photo.');
        }
        if ($newImageCount > 10) {
            throw new InvalidArgumentException('Upload up to 10 photos per destination.');
        }
        if ($newImageCount > 0 && !is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
            throw new RuntimeException('The image folder could not be created.');
        }

        $database->beginTransaction();

        if ($isEditing) {
            $statement = $database->prepare('UPDATE destinations SET name = ?, location = ?, region = ?, category = ?, description = ?, price = ? WHERE Destination_ID = ?');
            $statement->execute([$name, $location, $region, $category, $description ?: null, $price, $destinationId]);
            $exists = $database->prepare('SELECT Destination_ID FROM destinations WHERE Destination_ID = ?');
            $exists->execute([$destinationId]);
            if (!$exists->fetch()) {
                throw new InvalidArgumentException('Destination was not found.');
            }
        } else {
            $statement = $database->prepare('INSERT INTO destinations (name, location, region, category, description, price) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$name, $location, $region, $category, $description ?: null, $price]);
            $destinationId = (int) $database->lastInsertId();
        }

        if ($newImageCount > 0) {
            $imageStatement = $database->prepare('INSERT INTO destination_images (Destination_ID, image_path) VALUES (?, ?)');
            foreach ($imageFiles['name'] as $index => $originalName) {
                if ($originalName === '') {
                    continue;
                }
                if ($imageFiles['error'][$index] !== UPLOAD_ERR_OK || $imageFiles['size'][$index] > 8 * 1024 * 1024) {
                    throw new InvalidArgumentException('Each photo must be 8 MB or smaller.');
                }
                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($imageFiles['tmp_name'][$index]);
                if (!isset($allowedMimeTypes[$mimeType])) {
                    throw new InvalidArgumentException('Photos must be JPG, PNG, or WEBP files.');
                }
                $fileName = 'destination-' . $destinationId . '-' . bin2hex(random_bytes(8)) . '.' . $allowedMimeTypes[$mimeType];
                if (!move_uploaded_file($imageFiles['tmp_name'][$index], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
                    throw new RuntimeException('A photo could not be saved.');
                }
                $imageStatement->execute([$destinationId, 'assets/Spots/' . $fileName]);
            }
        }

        $database->commit();
        echo json_encode(['success' => true, 'id' => $destinationId, 'message' => $isEditing ? 'Destination updated.' : 'Destination added.']);
        exit;
    }

    if ($type === 'delete_destination_image') {
        $imageId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($imageId === false || $imageId < 1) {
            throw new InvalidArgumentException('A valid photo is required.');
        }

        $statement = $database->prepare('SELECT image_path FROM destination_images WHERE Destination_Image_ID = ?');
        $statement->execute([$imageId]);
        $image = $statement->fetch();
        if (!$image) {
            throw new InvalidArgumentException('Photo was not found.');
        }

        $deleteStatement = $database->prepare('DELETE FROM destination_images WHERE Destination_Image_ID = ?');
        $deleteStatement->execute([$imageId]);

        $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image['image_path']);
        if (is_file($filePath)) {
            unlink($filePath);
        }

        echo json_encode(['success' => true, 'message' => 'Photo removed.']);
        exit;
    }

    if ($type === 'delete_destination') {
        $destinationId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($destinationId === false || $destinationId < 1) {
            throw new InvalidArgumentException('A valid destination is required.');
        }

        $database->prepare('DELETE FROM destination_images WHERE Destination_ID = ?')->execute([$destinationId]);
        $database->prepare('DELETE FROM travel_package_destinations WHERE Destination_ID = ?')->execute([$destinationId]);
        $database->prepare('UPDATE travel_packages SET Destination_ID = NULL WHERE Destination_ID = ?')->execute([$destinationId]);

        $statement = $database->prepare('DELETE FROM destinations WHERE Destination_ID = ?');
        $statement->execute([$destinationId]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('Destination was not found.');
        }
        echo json_encode(['success' => true, 'message' => 'Destination deleted.']);
        exit;
    }

    if ($type === 'delete_package') {
        $packageId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($packageId === false || $packageId < 1) {
            throw new InvalidArgumentException('A valid package is required.');
        }
        $statement = $database->prepare('DELETE FROM travel_packages WHERE Travel_Pck_ID = ?');
        $statement->execute([$packageId]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('Package was not found.');
        }
        echo json_encode(['success' => true, 'message' => 'Package deleted.']);
        exit;
    }

    if ($type === 'package') {
        $name = trim((string) ($payload['name'] ?? ''));
        $packageId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $destinationIds = array_values(array_unique(array_filter(array_map(
            static fn ($value) => filter_var($value, FILTER_VALIDATE_INT),
            (array) ($payload['destination_ids'] ?? [])
        ), static fn ($value) => $value !== false && $value > 0)));
        $regionNames = array_values(array_unique(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            (array) ($payload['region_names'] ?? [])
        ), static fn ($value) => $value !== '')));
        $durationDays = filter_var($payload['duration_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        $rawVisibilityAmount = $payload['visibility_amount'] ?? null;
        $visibilityAmount = $rawVisibilityAmount === null || $rawVisibilityAmount === '' ? null : filter_var($rawVisibilityAmount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5256000]]);
        $visibilityUnit = (string) ($payload['visibility_unit'] ?? 'day');
        $visibilityUnitMultipliers = ['minute' => 1, 'hour' => 60, 'day' => 1440];
        if ($visibilityAmount !== null && $visibilityAmount !== false && isset($visibilityUnitMultipliers[$visibilityUnit])) {
            $visibilityMinutes = $visibilityAmount * $visibilityUnitMultipliers[$visibilityUnit];
            if ($visibilityMinutes > 5256000) {
                $visibilityAmount = false;
                $visibilityMinutes = null;
            }
        } else {
            $visibilityMinutes = null;
        }
        $priceTiers = (array) ($payload['price_tiers'] ?? []);
        $hotelId = filter_var($payload['hotel_id'] ?? null, FILTER_VALIDATE_INT);
        $description = trim((string) ($payload['description'] ?? ''));
        $status = (string) ($payload['status'] ?? 'draft');
        $itinerary = (array) ($payload['itinerary'] ?? []);
        $includeDepartureDay = !empty($payload['include_departure_day']) ? 1 : 0;

        if ($name === '' || $durationDays === false || ($rawVisibilityAmount !== null && $rawVisibilityAmount !== '' && ($visibilityAmount === false || !isset($visibilityUnitMultipliers[$visibilityUnit]))) || !$destinationIds || !$regionNames || !$priceTiers || !in_array($status, ['published', 'draft', 'archived'], true)) {
            throw new InvalidArgumentException('Complete the package name, destinations, regions, duration, price tiers, and status, and enter a valid visibility period when used.');
        }
        if ($hotelId === false || $hotelId < 1) {
            throw new InvalidArgumentException('Select a hotel for this package.');
        }
        $normalizedItinerary = [];
        $itineraryItemCategories = ['breakfast', 'lunch', 'dinner', 'activity', 'entrance_fee', 'transport', 'other'];
        foreach ($itinerary as $item) {
            $dayNumber = filter_var($item['day_number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
            $title = trim((string) ($item['title'] ?? ''));
            $activity = trim((string) ($item['activity'] ?? ''));
            if ($dayNumber === false || $title === '') {
                throw new InvalidArgumentException('Each itinerary day needs a spot.');
            }
            $normalizedItinerary[$dayNumber] = [
                'day_number' => $dayNumber,
                'title' => $title,
                'activity' => $activity,
                'inclusions' => []
            ];
            foreach ((array) ($item['inclusions'] ?? []) as $inclusion) {
                $category = (string) ($inclusion['category'] ?? '');
                $inclusionName = trim((string) ($inclusion['name'] ?? ''));
                if (!in_array($category, $itineraryItemCategories, true) || $inclusionName === '' || strlen($inclusionName) > 180) {
                    throw new InvalidArgumentException('Each meal, activity, or other package item needs a valid type and name.');
                }
                if (count($normalizedItinerary[$dayNumber]['inclusions']) >= 20) {
                    throw new InvalidArgumentException('Each itinerary day can have up to 20 package items.');
                }
                $normalizedItinerary[$dayNumber]['inclusions'][] = [
                    'category' => $category,
                    'name' => $inclusionName,
                    'is_included' => !empty($inclusion['is_included']) ? 1 : 0
                ];
            }
        }
        ksort($normalizedItinerary);

        if ($hotelId !== false && $hotelId > 0) {
            $hotelStatement = $database->prepare("SELECT Hotel_ID FROM hotels WHERE Hotel_ID = ? AND status = 'active'");
            $hotelStatement->execute([$hotelId]);
            if (!$hotelStatement->fetchColumn()) {
                throw new InvalidArgumentException('The selected hotel is no longer available.');
            }
        } else {
            $hotelId = null;
        }

        $normalizedTiers = [];
        foreach ($priceTiers as $tier) {
            $minPax = filter_var($tier['min_pax'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 255]]);
            $maxPax = filter_var($tier['max_pax'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 255]]);
            $tierPrice = filter_var($tier['price_per_pax'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($minPax === false || $maxPax === false || $maxPax < $minPax || $tierPrice === false || $tierPrice < 0) {
                throw new InvalidArgumentException('Each pax tier needs a valid range and price.');
            }
            $normalizedTiers[] = [$minPax, $maxPax, $tierPrice];
        }
        usort($normalizedTiers, static fn ($left, $right) => $left[0] <=> $right[0]);
        foreach ($normalizedTiers as $index => $tier) {
            if ($index > 0 && $tier[0] <= $normalizedTiers[$index - 1][1]) {
                throw new InvalidArgumentException('Pax price ranges cannot overlap.');
            }
        }

        $destinationPlaceholders = implode(',', array_fill(0, count($destinationIds), '?'));
        $destinationStatement = $database->prepare("SELECT Destination_ID FROM destinations WHERE status = 'active' AND Destination_ID IN ($destinationPlaceholders)");
        $destinationStatement->execute($destinationIds);
        if (count($destinationStatement->fetchAll()) !== count($destinationIds)) {
            throw new InvalidArgumentException('One or more selected destinations are no longer available.');
        }
        $imageStatement = $database->prepare("SELECT COALESCE(di.image_path, d.image_url) AS image_url
            FROM destinations d
            LEFT JOIN destination_images di ON di.Destination_ID = d.Destination_ID
            WHERE d.Destination_ID = ?
            ORDER BY di.Destination_Image_ID
            LIMIT 1");
        $imageStatement->execute([$destinationIds[0]]);
        $packageImage = $imageStatement->fetchColumn() ?: null;

        $database->beginTransaction();
        $basePrice = $normalizedTiers[0][2];
        $isEditing = $packageId !== false && $packageId > 0;
        if ($isEditing) {
            $existingPackage = $database->prepare('SELECT status, visibility_minutes, published_at FROM travel_packages WHERE Travel_Pck_ID = ?');
            $existingPackage->execute([$packageId]);
            $existingPublication = $existingPackage->fetch();
            if (!$existingPublication) {
                throw new InvalidArgumentException('Package was not found.');
            }
            $existingVisibilityMinutes = $existingPublication['visibility_minutes'] === null ? null : (int) $existingPublication['visibility_minutes'];
            $restartVisibilityPeriod = $existingPublication['status'] !== 'published'
                || $existingVisibilityMinutes !== $visibilityMinutes
                || $existingPublication['published_at'] === null;
            $statement = $database->prepare("UPDATE travel_packages
                SET name = ?, description = ?, duration_days = ?, include_departure_day = ?, price_per_person = ?, status = ?, visibility_minutes = ?,
                    published_at = CASE
                        WHEN ? = 0 THEN NULL
                        WHEN ? = 1 THEN CURRENT_TIMESTAMP
                        ELSE published_at
                    END,
                    image_url = ?, Hotel_ID = ?
                WHERE Travel_Pck_ID = ?");
            $statement->execute([$name, $description ?: null, $durationDays, $includeDepartureDay, $basePrice, $status, $visibilityMinutes, $status === 'published' ? 1 : 0, $restartVisibilityPeriod ? 1 : 0, $packageImage, $hotelId, $packageId]);
            if ($statement->rowCount() === 0) {
                $exists = $database->prepare('SELECT Travel_Pck_ID FROM travel_packages WHERE Travel_Pck_ID = ?');
                $exists->execute([$packageId]);
                if (!$exists->fetch()) {
                    throw new InvalidArgumentException('Package was not found.');
                }
            }
            $database->prepare('DELETE FROM travel_package_destinations WHERE Travel_Pck_ID = ?')->execute([$packageId]);
            $database->prepare('DELETE FROM travel_package_regions WHERE Travel_Pck_ID = ?')->execute([$packageId]);
            $database->prepare('DELETE FROM travel_package_price_tiers WHERE Travel_Pck_ID = ?')->execute([$packageId]);
            $database->prepare('DELETE FROM travel_package_itinerary WHERE Travel_Pck_ID = ?')->execute([$packageId]);
        } else {
            $statement = $database->prepare("INSERT INTO travel_packages (name, description, duration_days, include_departure_day, price_per_person, status, visibility_minutes, published_at, image_url, Hotel_ID)
                VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 'published', CURRENT_TIMESTAMP, NULL), ?, ?)");
            $statement->execute([$name, $description ?: null, $durationDays, $includeDepartureDay, $basePrice, $status, $visibilityMinutes, $status, $packageImage, $hotelId]);
            $packageId = (int) $database->lastInsertId();
        }
        $destinationStatement = $database->prepare('INSERT INTO travel_package_destinations (Travel_Pck_ID, Destination_ID) VALUES (?, ?)');
        foreach ($destinationIds as $destinationId) {
            $destinationStatement->execute([$packageId, $destinationId]);
        }
        $regionStatement = $database->prepare('INSERT INTO travel_package_regions (Travel_Pck_ID, region) VALUES (?, ?)');
        foreach ($regionNames as $regionName) {
            $regionStatement->execute([$packageId, $regionName]);
        }
        $tierStatement = $database->prepare('INSERT INTO travel_package_price_tiers (Travel_Pck_ID, min_pax, max_pax, price_per_pax) VALUES (?, ?, ?, ?)');
        foreach ($normalizedTiers as [$minPax, $maxPax, $tierPrice]) {
            $tierStatement->execute([$packageId, $minPax, $maxPax, $tierPrice]);
        }
        $itineraryStatement = $database->prepare('INSERT INTO travel_package_itinerary (Travel_Pck_ID, day_number, title, activity) VALUES (?, ?, ?, ?)');
        $itineraryItemStatement = $database->prepare('INSERT INTO travel_package_itinerary_items (Travel_Pck_Itinerary_ID, category, name, is_included) VALUES (?, ?, ?, ?)');
        $itineraryIdStatement = $database->prepare('SELECT Travel_Pck_Itinerary_ID FROM travel_package_itinerary WHERE Travel_Pck_ID = ? AND day_number = ?');
        foreach ($normalizedItinerary as $day) {
            $itineraryStatement->execute([$packageId, $day['day_number'], $day['title'], $day['activity']]);
            if ($day['inclusions']) {
                $itineraryIdStatement->execute([$packageId, $day['day_number']]);
                $itineraryId = (int) $itineraryIdStatement->fetchColumn();
                foreach ($day['inclusions'] as $inclusion) {
                    $itineraryItemStatement->execute([$itineraryId, $inclusion['category'], $inclusion['name'], $inclusion['is_included']]);
                }
            }
        }
        $database->commit();
        echo json_encode(['success' => true, 'id' => $packageId, 'message' => $isEditing ? 'Package updated.' : 'Package added.']);
        exit;
    }

    if ($type === 'payment_method') {
        $rawPmId = $payload['id'] ?? null;
        $pmId = $rawPmId === null || $rawPmId === '' ? null : filter_var($rawPmId, FILTER_VALIDATE_INT);
        $action = (string) ($payload['action'] ?? 'save');

        if ($pmId === false || ($pmId !== null && $pmId < 1)) {
            throw new InvalidArgumentException('A valid payment method is required.');
        }

        if ($action === 'delete') {
            if ($pmId === null) {
                throw new InvalidArgumentException('A payment method is required.');
            }
            $existing = $database->prepare('SELECT qr_code_image FROM payment_methods WHERE Payment_Method_ID = ?');
            $existing->execute([$pmId]);
            $existingMethod = $existing->fetch();
            if (!$existingMethod) {
                throw new InvalidArgumentException('Payment method was not found.');
            }
            $database->prepare('DELETE FROM payment_methods WHERE Payment_Method_ID = ?')->execute([$pmId]);
            if (!empty($existingMethod['qr_code_image'])) {
                $relativePath = ltrim(str_replace(['../', '..\\'], '', $existingMethod['qr_code_image']), '/\\');
                $oldFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                if (is_file($oldFile) && str_contains($existingMethod['qr_code_image'], 'data/uploads/qrcodes/')) {
                    unlink($oldFile);
                }
            }
            echo json_encode(['success' => true, 'message' => 'Payment method removed.']);
            exit;
        }

        $methodType = in_array($payload['method_type'] ?? '', ['gcash', 'bank', 'card', 'other'], true) ? $payload['method_type'] : 'gcash';
        $bankName = trim((string) ($payload['bank_name'] ?? ''));
        $accountName = trim((string) ($payload['account_name'] ?? ''));
        $accountNumber = trim((string) ($payload['account_number'] ?? ''));
        $instructions = trim((string) ($payload['instructions'] ?? ''));
        $status = in_array($payload['status'] ?? '', ['active', 'inactive'], true) ? $payload['status'] : 'active';

        if ($accountName === '' || $accountNumber === '') {
            throw new InvalidArgumentException('Account name and account number are required.');
        }

        $qrPath = null;
        $oldQrPath = null;
        if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['qr_code']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('The QR code upload failed.');
            }
            if ($_FILES['qr_code']['size'] > 8 * 1024 * 1024) {
                throw new InvalidArgumentException('The QR code must be 8 MB or smaller.');
            }
            $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['qr_code']['tmp_name']);
            if (!isset($allowedMimeTypes[$mimeType])) {
                throw new InvalidArgumentException('The QR code must be a JPG, PNG, or WEBP image.');
            }
            $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'qrcodes';
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0750, true)) {
                    throw new RuntimeException('The QR code folder could not be created.');
                }
            }
            $filename = 'qr_' . bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$mimeType];
            $target = $dir . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($_FILES['qr_code']['tmp_name'], $target)) {
                throw new RuntimeException('The QR code could not be saved.');
            }
            $qrPath = '../data/uploads/qrcodes/' . $filename;
        }

        if ($pmId !== null) {
            $existing = $database->prepare('SELECT qr_code_image FROM payment_methods WHERE Payment_Method_ID = ?');
            $existing->execute([$pmId]);
            $existingMethod = $existing->fetch();
            if (!$existingMethod) {
                throw new InvalidArgumentException('Payment method was not found.');
            }
            $oldQrPath = $existingMethod['qr_code_image'];
            if ($qrPath) {
                $stmt = $database->prepare('UPDATE payment_methods SET method_type = ?, bank_name = ?, account_name = ?, account_number = ?, qr_code_image = ?, instructions = ?, status = ? WHERE Payment_Method_ID = ?');
                $stmt->execute([$methodType, $bankName, $accountName, $accountNumber, $qrPath, $instructions, $status, $pmId]);
            } else {
                $stmt = $database->prepare('UPDATE payment_methods SET method_type = ?, bank_name = ?, account_name = ?, account_number = ?, instructions = ?, status = ? WHERE Payment_Method_ID = ?');
                $stmt->execute([$methodType, $bankName, $accountName, $accountNumber, $instructions, $status, $pmId]);
            }
            if ($qrPath && $oldQrPath && str_contains($oldQrPath, 'data/uploads/qrcodes/')) {
                $relativePath = ltrim(str_replace(['../', '..\\'], '', $oldQrPath), '/\\');
                $oldFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                if (is_file($oldFile)) {
                    unlink($oldFile);
                }
            }
        } else {
            $stmt = $database->prepare('INSERT INTO payment_methods (method_type, bank_name, account_name, account_number, qr_code_image, instructions, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$methodType, $bankName, $accountName, $accountNumber, $qrPath, $instructions, $status]);
        }

        echo json_encode(['success' => true, 'message' => 'Payment method saved.']);
        exit;
    }

    if ($type === 'review_booking') {
        $bookingId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($payload['status'] ?? '');
        $note = trim((string) ($payload['note'] ?? ''));
        if ($bookingId === false || $bookingId < 1 || !in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('A valid booking and review status are required.');
        }
        if ($status === 'rejected' && $note === '') {
            throw new InvalidArgumentException('Provide a reason when denying a plan.');
        }
        $bookingLookup = $database->prepare("SELECT b.Booking_ID AS booking_id, b.Customer_ID AS customer_id, b.approval_status, u.full_name AS customer_name, u.email, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Custom travel plan') AS package_name FROM bookings b INNER JOIN users u ON u.User_ID = b.Customer_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID WHERE b.Booking_ID = ?");
        $bookingLookup->execute([$bookingId]);
        $booking = $bookingLookup->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new InvalidArgumentException('Booking was not found.');
        }
        if ($isEmployee) {
            mtrRequireAssignedCustomer($database, (int) $booking['customer_id'], $adminUserId);
        }
        $statement = $database->prepare("UPDATE bookings SET approval_status = ?, approval_note = ?, booking_status = 'pending' WHERE Booking_ID = ?");
        $statement->execute([$status, $note ?: null, $bookingId]);
        $notificationSent = false;
        if ($status === 'approved' && $booking['approval_status'] !== 'approved') {
            $booking['booking_id'] = $bookingId;
            $notificationSent = mtrSendPlanApprovalEmail($booking);
        }
        echo json_encode(['success' => true, 'notification_sent' => $notificationSent, 'message' => $status === 'approved' ? 'Plan approved for payment.' : 'Plan denied and returned to the customer with feedback.']);
        exit;
    }

    if ($type === 'verify_completion') {
        $bookingId = filter_var($payload['booking_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($payload['status'] ?? '');
        if ($bookingId === false || $bookingId < 1 || !in_array($status, ['verified', 'rejected'], true)) {
            throw new InvalidArgumentException('A valid trip and verification status are required.');
        }
        $bookingCheck = $database->prepare("SELECT Booking_ID, Customer_ID, completion_proof FROM bookings WHERE Booking_ID = ? AND completion_proof IS NOT NULL AND completion_proof <> ''");
        $bookingCheck->execute([$bookingId]);
        $completion = $bookingCheck->fetch();
        if (!$completion) {
            throw new InvalidArgumentException('Trip completion proof was not found.');
        }
        if ($isEmployee) {
            mtrRequireAssignedCustomer($database, (int) $completion['Customer_ID'], $adminUserId);
        }
        if ($status === 'verified') {
            $statement = $database->prepare("UPDATE bookings SET booking_status = 'completed', completion_verified_at = CURRENT_TIMESTAMP WHERE Booking_ID = ?");
            $statement->execute([$bookingId]);
            echo json_encode(['success' => true, 'message' => 'Trip completion verified.']);
            exit;
        }
        $statement = $database->prepare('UPDATE bookings SET completion_proof = NULL, completion_submitted_at = NULL, completion_verified_at = NULL WHERE Booking_ID = ?');
        $statement->execute([$bookingId]);
        echo json_encode(['success' => true, 'message' => 'Completion proof rejected. The driver can upload a new photo.']);
        exit;
    }

    if ($type === 'review_extension') {
        $extensionId = filter_var($payload['extension_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($payload['status'] ?? '');
        $approvedAmount = filter_var($payload['approved_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $note = trim((string) ($payload['admin_note'] ?? ''));
        if ($extensionId === false || $extensionId < 1 || !in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('A valid extension request and status are required.');
        }
        if ($status === 'approved' && ($approvedAmount === false || $approvedAmount < 0)) {
            throw new InvalidArgumentException('Enter a valid approved amount.');
        }
        $extensionLookup = $database->prepare("SELECT e.Extension_ID AS extension_id, e.User_ID AS customer_id, e.extra_days, e.requested_departure_date, u.full_name AS customer_name, u.email, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan') AS package_name FROM booking_extension_requests e INNER JOIN bookings b ON b.Booking_ID = e.Booking_ID INNER JOIN users u ON u.User_ID = e.User_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID WHERE e.Extension_ID = ? LIMIT 1");
        $extensionRecord = $extensionLookup->execute([$extensionId]) ? ($extensionLookup->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        if (!$extensionRecord) {
            throw new InvalidArgumentException('Extension request was not found.');
        }
        if ($isEmployee) {
            mtrRequireAssignedCustomer($database, (int) $extensionRecord['customer_id'], $adminUserId);
        }
        if ($status === 'approved') {
            $approvalBookingId = (int) $database->query('SELECT Booking_ID FROM booking_extension_requests WHERE Extension_ID = ' . (int) $extensionId)->fetchColumn();
            $maxExtraDays = mtrMaxExtensionDays($database, $approvalBookingId);
            if ($maxExtraDays !== null && (int) $extensionRecord['extra_days'] > $maxExtraDays) {
                throw new InvalidArgumentException('The driver and vehicle are only free for ' . $maxExtraDays . ' more day(s) because of another booking. Reject this request or ask the customer to request fewer days.');
            }
        }
        $statement = $database->prepare('UPDATE booking_extension_requests SET status = ?, approved_amount = ?, admin_note = ?, reviewed_at = CURRENT_TIMESTAMP WHERE Extension_ID = ? AND status = \'pending\'');
        $statement->execute([$status, $status === 'approved' ? $approvedAmount : null, $note ?: null, $extensionId]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('Extension request was not found or already reviewed.');
        }
        $notificationSent = false;
        if ($status === 'approved' && $extensionRecord) {
            $extensionRecord['approved_amount'] = $approvedAmount;
            $notificationSent = mtrSendExtensionApprovalEmail($extensionRecord);
        }
        echo json_encode(['success' => true, 'notification_sent' => $notificationSent, 'message' => $status === 'approved' ? 'Extension approved for payment.' : 'Extension request rejected.']);
        exit;
    }

    if ($type === 'verify_payment') {
        $paymentId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $status = in_array($payload['status'] ?? '', ['verified', 'rejected', 'pending'], true) ? $payload['status'] : 'verified';
        if ($paymentId) {
            $bookingLookup = $database->prepare("SELECT pay.Payment_ID, pay.Booking_ID, pay.Extension_ID, pay.amount, pay.reference_number, pay.receipt_file, b.total_amount, b.custom_title, b.travel_date, b.trip_duration_days, b.requested_vehicle_id, u.full_name AS customer_name, u.email, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan') AS package_name FROM payments pay LEFT JOIN bookings b ON b.Booking_ID = pay.Booking_ID LEFT JOIN users u ON u.User_ID = b.Customer_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID WHERE pay.Payment_ID = ?");
            $bookingLookup->execute([$paymentId]);
            $paymentRecord = $bookingLookup->fetch(PDO::FETCH_ASSOC) ?: [];
            $bookingId = $paymentRecord['Booking_ID'] ?? null;
            $extensionId = (int) ($paymentRecord['Extension_ID'] ?? 0);
            if ($status === 'verified' && $extensionId > 0) {
                $extensionDays = (int) $database->query('SELECT extra_days FROM booking_extension_requests WHERE Extension_ID = ' . $extensionId)->fetchColumn();
                $maxExtraDays = mtrMaxExtensionDays($database, (int) $bookingId);
                if ($maxExtraDays !== null && $extensionDays > $maxExtraDays) {
                    throw new InvalidArgumentException('The driver and vehicle are no longer free for the extra days because of another booking.');
                }
                $extensionUpdate = $database->prepare("UPDATE booking_extension_requests SET status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE Extension_ID = ? AND status = 'approved'");
                $extensionUpdate->execute([$extensionId]);
                if ($extensionUpdate->rowCount() === 0) {
                    throw new InvalidArgumentException('This extension is not approved for payment.');
                }
                $database->prepare('UPDATE bookings SET total_amount = total_amount + ?, trip_duration_days = trip_duration_days + ? WHERE Booking_ID = ?')->execute([(float) $paymentRecord['amount'], $extensionDays, $bookingId]);
            }
            if ($status === 'verified' && $extensionId === 0) {
                $bookingDetails = $database->prepare('SELECT guest_count, travel_date, trip_duration_days, requested_vehicle_id FROM bookings WHERE Booking_ID = ?');
                $bookingDetails->execute([$bookingId]);
                $bookingDetails = $bookingDetails->fetch(PDO::FETCH_ASSOC);
                if (!$bookingDetails) {
                    throw new InvalidArgumentException('The booking for this payment could not be found.');
                }
                $driverId = null;
                if (!empty($bookingDetails['requested_vehicle_id'])) {
                    $availableVehicles = mtrFindAvailableVehicles($database, (string) $bookingDetails['travel_date'], max(1, (int) $bookingDetails['trip_duration_days']), (int) $bookingDetails['guest_count'], (int) $bookingId);
                    foreach ($availableVehicles as $vehicle) {
                        if ((int) $vehicle['id'] === (int) $bookingDetails['requested_vehicle_id']) {
                            $driverId = (int) $vehicle['driver_id'];
                            break;
                        }
                    }
                    if (!$driverId) {
                        throw new InvalidArgumentException('The customer-selected vehicle is no longer available for these dates or passenger count.');
                    }
                }
            }
            $database->prepare('UPDATE payments SET status = ? WHERE Payment_ID = ?')->execute([$status, $paymentId]);
            $notificationSent = false;
            if ($bookingId) {
                $bookingPaymentStatus = ($extensionId > 0) ? null : ($status === 'verified' ? 'paid' : ($status === 'rejected' ? 'failed' : 'pending'));
                $bookingStatus = $status === 'verified' ? 'confirmed' : ($status === 'rejected' ? 'cancelled' : 'pending');
                if ($extensionId > 0) {
                    if ($status === 'rejected') {
                        $database->prepare("UPDATE booking_extension_requests SET status = 'rejected', admin_note = 'Extension payment rejected.' WHERE Extension_ID = ?")->execute([$extensionId]);
                    }
                } else {
                    $database->prepare('UPDATE bookings SET payment_status = ?, booking_status = ? WHERE Booking_ID = ?')->execute([$bookingPaymentStatus, $bookingStatus, $bookingId]);
                }
                if ($status === 'verified' && $extensionId === 0 && $driverId !== null) {
                    $database->prepare('INSERT INTO booking_assignments (Booking_ID, Driver_Profile_ID) VALUES (?, ?) ON DUPLICATE KEY UPDATE Driver_Profile_ID = VALUES(Driver_Profile_ID)')->execute([$bookingId, $driverId]);
                } elseif ($extensionId === 0 && ($status === 'rejected' || $status === 'verified')) {
                    $database->prepare('DELETE FROM booking_assignments WHERE Booking_ID = ?')->execute([$bookingId]);
                }
                if ($status === 'verified') {
                    $notificationLookup = $database->prepare("SELECT b.Booking_ID AS booking_id, b.total_amount, b.travel_date, b.guest_count, b.pickup_location, b.pickup_address, b.departure_location, b.accommodation_name, b.accommodation_address, b.custom_details, requested_vehicle.vehicle_unit AS requested_vehicle_name, COALESCE(h.name, b.accommodation_name) AS hotel_name, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan') AS package_name, u.email, u.full_name AS customer_name, pay.amount, pay.reference_number, pay.receipt_file, dpUser.full_name AS driver_name, dpUser.phone AS driver_phone, (SELECT GROUP_CONCAT(DISTINCT d.name ORDER BY d.name SEPARATOR ', ') FROM booking_destinations bd INNER JOIN destinations d ON d.Destination_ID = bd.Destination_ID WHERE bd.Booking_ID = b.Booking_ID) AS destination_names, (SELECT full_name FROM users WHERE role = 'admin' ORDER BY User_ID ASC LIMIT 1) AS agent_name, (SELECT email FROM users WHERE role = 'admin' ORDER BY User_ID ASC LIMIT 1) AS agent_email, (SELECT phone FROM users WHERE role = 'admin' ORDER BY User_ID ASC LIMIT 1) AS agent_phone FROM bookings b INNER JOIN users u ON u.User_ID = b.Customer_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID LEFT JOIN hotels h ON h.Hotel_ID = b.Hotel_ID LEFT JOIN company_vehicles requested_vehicle ON requested_vehicle.Company_Vehicle_ID = b.requested_vehicle_id LEFT JOIN payments pay ON pay.Payment_ID = ? LEFT JOIN booking_assignments ba ON ba.Booking_ID = b.Booking_ID LEFT JOIN driver_profiles dp ON dp.Driver_Profile_ID = ba.Driver_Profile_ID LEFT JOIN users dpUser ON dpUser.User_ID = dp.User_ID WHERE b.Booking_ID = ?");
                    $notificationLookup->execute([$paymentId, $bookingId]);
                    $notificationBooking = $notificationLookup->fetch(PDO::FETCH_ASSOC);
                    if ($notificationBooking) {
                        $notificationSent = mtrSendPaymentVerifiedEmail($notificationBooking);
                    }
                }
            }
        }
        $message = $extensionId > 0
            ? ($status === 'verified' ? 'Extension payment approved.' : ($status === 'rejected' ? 'Extension payment rejected.' : 'Extension payment status updated.'))
            : ($status === 'verified' ? (!empty($driverId) ? 'Payment approved and the customer-selected vehicle assigned.' : 'Payment approved.') : ($status === 'rejected' ? 'Payment rejected and booking cancelled.' : 'Payment status updated.'));
        echo json_encode(['success' => true, 'notification_sent' => $notificationSent ?? false, 'message' => $message]);
        exit;
    }

    if ($type === 'record_refund') {
        $paymentId = filter_var($payload['payment_id'] ?? null, FILTER_VALIDATE_INT);
        $refundAmount = filter_var($payload['refund_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $refundReference = trim((string) ($payload['refund_reference'] ?? ''));
        $refundProof = $_FILES['refund_proof'] ?? null;
        if ($paymentId === false || $paymentId < 1 || $refundAmount === false || $refundAmount <= 0 || !is_finite((float) $refundAmount)) {
            throw new InvalidArgumentException('Enter a valid refund amount.');
        }
        if ($refundReference === '' || strlen($refundReference) > 120) {
            throw new InvalidArgumentException('Enter the refund transaction reference (up to 120 characters).');
        }
        if (!$refundProof || $refundProof['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload the refund proof first.');
        }
        if ($refundProof['size'] > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('The refund screenshot must be 8 MB or smaller.');
        }
        $paymentLookup = $database->prepare("SELECT pay.Payment_ID, pay.Booking_ID, pay.amount, pay.status, pay.refund_status, pay.customer_name, u.email, COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan') AS package_name FROM payments pay LEFT JOIN bookings b ON b.Booking_ID = pay.Booking_ID LEFT JOIN users u ON u.User_ID = b.Customer_ID LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID WHERE pay.Payment_ID = ? LIMIT 1");
        $paymentLookup->execute([$paymentId]);
        $paymentRecord = $paymentLookup->fetch(PDO::FETCH_ASSOC);
        if (!$paymentRecord || $paymentRecord['status'] !== 'verified') {
            throw new InvalidArgumentException('Only verified payments can be refunded.');
        }
        if ((float) $refundAmount > (float) $paymentRecord['amount']) {
            throw new InvalidArgumentException('The refund cannot exceed the original payment amount.');
        }
        if (in_array($paymentRecord['refund_status'], ['partially_refunded', 'refunded'], true)) {
            throw new InvalidArgumentException('This payment already has a recorded refund.');
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($refundProof['tmp_name']);
        $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowedTypes[$mimeType])) {
            throw new InvalidArgumentException('Refund proof must be a JPG, PNG, or WEBP image.');
        }
        $ocrResult = ['status' => 'not_checked', 'amount' => null];
        if (function_exists('exec')) {
            $ocrResult = mtrDetectRefundAmount($refundProof['tmp_name'], $mimeType);
        }
        if ($ocrResult['status'] === 'matched_candidate' && $ocrResult['amount'] !== null && abs((float) $ocrResult['amount'] - (float) $refundAmount) >= 0.01) {
            throw new InvalidArgumentException('The refund amount does not match the amount shown on the receipt.');
        }
        $refundDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'refunds';
        if (!is_dir($refundDirectory) && !mkdir($refundDirectory, 0750, true)) {
            throw new RuntimeException('The refund proof folder could not be created.');
        }
        $fileName = 'payment-' . $paymentId . '-' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
        if (!move_uploaded_file($refundProof['tmp_name'], $refundDirectory . DIRECTORY_SEPARATOR . $fileName)) {
            throw new RuntimeException('The refund proof could not be saved.');
        }
        $refundStatus = abs((float) $refundAmount - (float) $paymentRecord['amount']) < 0.01 ? 'refunded' : 'partially_refunded';
        $refundPath = 'data/uploads/refunds/' . $fileName;
        $database->prepare('UPDATE payments SET refund_status = ?, refunded_amount = ?, refund_reference = ?, refund_proof = ?, refunded_at = CURRENT_TIMESTAMP, refund_detected_amount = ?, refund_ocr_status = ? WHERE Payment_ID = ?')->execute([$refundStatus, $refundAmount, $refundReference, $refundPath, $ocrResult['amount'], $ocrResult['status'] === 'matched_candidate' ? 'matched' : 'not_checked', $paymentId]);
        $cancellationStatus = $refundStatus === 'refunded' ? 'refund_sent' : 'partially_refunded';
        $cancellationUpdate = $database->prepare('UPDATE cancellation_requests SET status = ?, reviewed_at = CURRENT_TIMESTAMP WHERE status = \'approved\' AND (Payment_ID = ? OR Booking_ID = ?)');
        $cancellationUpdate->execute([$cancellationStatus, $paymentId, $paymentRecord['Booking_ID'] ?: 0]);
        if (!empty($paymentRecord['Booking_ID'])) {
            $database->prepare("UPDATE bookings SET payment_status = 'refunded', booking_status = 'cancelled' WHERE Booking_ID = ?")->execute([$paymentRecord['Booking_ID']]);
            $database->prepare('DELETE FROM booking_assignments WHERE Booking_ID = ?')->execute([$paymentRecord['Booking_ID']]);
        }
        $proofUrl = mtrNotificationUrl($refundPath);
        $refundCustomerName = mtrEmailName((string) ($paymentRecord['customer_name'] ?? 'Customer'));
        $refundPackageName = mtrEmailName((string) ($paymentRecord['package_name'] ?? 'Travel plan'));
        $refundReferenceLabel = mtrEmailName($refundReference ?: 'N/A');
        $refundProofLink = $proofUrl
            ? '<p>To access or download the official proof of transfer, click the link below:</p><p><a href="' . htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:11px 16px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:7px;font-weight:bold">View Refund Proof</a></p>'
            : '';
        $emailSent = mtrSendNotificationEmail(
            (string) ($paymentRecord['email'] ?? ''),
            (string) ($paymentRecord['customer_name'] ?? ''),
            'Notice of Refund Processed - Mindanao Travel Hub',
            '<p>Dear ' . $refundCustomerName . ',</p><p>This email confirms that a refund has been issued for your cancelled booking. Below is the summary of your transaction:</p><hr><h2 style="color:#0369a1">REFUND RECEIPT</h2><p><strong>Item:</strong> ' . $refundPackageName . '<br><strong>Amount Refunded:</strong> PHP ' . number_format((float) $refundAmount, 2) . '<br><strong>Reference No.:</strong> ' . $refundReferenceLabel . '<br><strong>Date Processed:</strong> ' . date('F j, Y g:i A') . '</p><p>A copy of the refund receipt is attached to this email.</p>' . $refundProofLink . '<p>If you have any questions or need further assistance, you may also reach our admin directly via <strong>WhatsApp</strong> at <strong>09765756031</strong>.</p><p>Thank you for choosing Mindanao Travel Hub. We hope to serve you again in the future!</p><p>Warm regards,<br><strong>Customer Support Team</strong><br><em>Mindanao Travel Hub</em></p>',
            [[
                'path' => $refundDirectory . DIRECTORY_SEPARATOR . $fileName,
                'name' => 'refund-receipt-' . $paymentId . '.' . $allowedTypes[$mimeType],
                'mime_type' => $mimeType
            ]]
        );
        echo json_encode(['success' => true, 'email_sent' => $emailSent, 'message' => ($refundStatus === 'refunded' ? 'Full refund sent.' : 'Partial refund sent.') . ($emailSent ? ' Customer email sent.' : ' Refund saved, but customer email could not be sent.')]);
        exit;
    }

    if ($type === 'review_cancellation') {
        $cancellationId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($payload['status'] ?? '');
        if ($cancellationId === false || $cancellationId < 1 || !in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('A valid cancellation request and status are required.');
        }
        $requestLookup = $database->prepare('SELECT Cancellation_ID, Booking_ID, Ticket_ID, User_ID, status FROM cancellation_requests WHERE Cancellation_ID = ? LIMIT 1');
        $requestLookup->execute([$cancellationId]);
        $request = $requestLookup->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['status'] !== 'pending') {
            throw new InvalidArgumentException('This cancellation request was already reviewed.');
        }
        if ($isEmployee) {
            mtrRequireAssignedCustomer($database, (int) $request['User_ID'], $adminUserId);
        }
        $database->beginTransaction();
        try {
            $database->prepare('UPDATE cancellation_requests SET status = ?, reviewed_at = CURRENT_TIMESTAMP WHERE Cancellation_ID = ?')->execute([$status, $cancellationId]);
            $database->commit();
        } catch (Throwable $error) {
            $database->rollBack();
            throw $error;
        }
        echo json_encode(['success' => true, 'message' => $status === 'approved' ? 'Cancellation approved. Process the refund from the booking record.' : 'Cancellation request rejected.']);
        exit;
    }

    if ($type === 'assign_driver') {
        $bookingId = filter_var($payload['booking_id'] ?? null, FILTER_VALIDATE_INT);
        $driverId = filter_var($payload['driver_id'] ?? null, FILTER_VALIDATE_INT);
        if ($bookingId === false || $bookingId < 1 || $driverId === false || $driverId < 1) {
            throw new InvalidArgumentException('A valid booking and driver are required.');
        }

        $approvedDriver = $database->prepare("SELECT dp.Driver_Profile_ID FROM driver_profiles dp LEFT JOIN company_vehicles cv ON cv.Company_Vehicle_ID = dp.company_vehicle_id WHERE dp.Driver_Profile_ID = ? AND dp.verification_status = 'approved' AND dp.is_available = 1 AND cv.status = 'active'");
        $approvedDriver->execute([$driverId]);
        if (!$approvedDriver->fetchColumn()) {
            throw new InvalidArgumentException('Only approved and available drivers can be assigned to bookings.');
        }

        $bookingExists = $database->prepare('SELECT Booking_ID FROM bookings WHERE Booking_ID = ?');
        $bookingExists->execute([$bookingId]);
        if (!$bookingExists->fetchColumn()) {
            throw new InvalidArgumentException('Booking was not found.');
        }

        $assignment = $database->prepare('INSERT INTO booking_assignments (Booking_ID, Driver_Profile_ID) VALUES (?, ?) ON DUPLICATE KEY UPDATE Driver_Profile_ID = VALUES(Driver_Profile_ID)');
        $assignment->execute([$bookingId, $driverId]);
        echo json_encode(['success' => true, 'message' => 'Approved driver assigned.']);
        exit;
    }

    if ($type === 'driver_status') {
        $driverId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($payload['status'] ?? '');
        if ($driverId === false || $driverId < 1 || !in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('A valid driver and approval status are required.');
        }

        $statement = $database->prepare('UPDATE driver_profiles SET verification_status = ? WHERE Driver_Profile_ID = ?');
        $statement->execute([$status, $driverId]);
        if ($statement->rowCount() === 0) {
            $exists = $database->prepare('SELECT Driver_Profile_ID FROM driver_profiles WHERE Driver_Profile_ID = ?');
            $exists->execute([$driverId]);
            if (!$exists->fetch()) {
                throw new InvalidArgumentException('Driver was not found.');
            }
        }

        echo json_encode(['success' => true, 'message' => $status === 'approved' ? 'Driver approved.' : 'Driver denied.']);
        exit;
    }

    if ($type === 'company_vehicle') {
        $vehicleId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $unit = trim((string) ($payload['vehicle_unit'] ?? ''));
        $color = trim((string) ($payload['vehicle_color'] ?? ''));
        $plate = trim((string) ($payload['plate_number'] ?? ''));
        $capacity = filter_var($payload['passenger_capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $status = in_array($payload['status'] ?? '', ['active', 'maintenance', 'inactive'], true) ? $payload['status'] : 'active';
        if ($unit === '' || $color === '' || $plate === '' || $capacity === false) {
            throw new InvalidArgumentException('Vehicle unit, color, plate number, and passenger capacity are required.');
        }
        $imageFiles = $_FILES['images'] ?? null;
        $uploadImages = [];
        if ($imageFiles && isset($imageFiles['name']) && is_array($imageFiles['name'])) {
            foreach ($imageFiles['name'] as $index => $originalName) {
                if ($originalName === '') {
                    continue;
                }
                if (($imageFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($imageFiles['size'][$index] ?? 0) > 8 * 1024 * 1024) {
                    throw new InvalidArgumentException('Each vehicle photo must be 8 MB or smaller and upload successfully.');
                }
                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($imageFiles['tmp_name'][$index]);
                $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowedMimeTypes[$mimeType])) {
                    throw new InvalidArgumentException('Vehicle photos must be JPG, PNG, or WEBP files.');
                }
                $uploadImages[] = [
                    'tmp_name' => $imageFiles['tmp_name'][$index],
                    'extension' => $allowedMimeTypes[$mimeType]
                ];
            }
        }
        $isEditing = $vehicleId !== false && $vehicleId > 0;
        if (!$isEditing && !$uploadImages) {
            throw new InvalidArgumentException('Upload at least one photo before adding a company vehicle.');
        }
        if (count($uploadImages) > 10) {
            throw new InvalidArgumentException('Upload up to 10 photos per vehicle.');
        }

        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Vehicles';
        if ($uploadImages && !is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('The vehicle photo folder could not be created.');
        }

        $movedFiles = [];
        $database->beginTransaction();
        try {
            if ($isEditing) {
                $statement = $database->prepare('UPDATE company_vehicles SET vehicle_unit = ?, vehicle_color = ?, plate_number = ?, passenger_capacity = ?, status = ? WHERE Company_Vehicle_ID = ?');
                $statement->execute([$unit, $color, $plate, $capacity, $status, $vehicleId]);
                $vehicleExists = $database->prepare('SELECT Company_Vehicle_ID FROM company_vehicles WHERE Company_Vehicle_ID = ?');
                $vehicleExists->execute([$vehicleId]);
                if (!$vehicleExists->fetchColumn()) {
                    throw new InvalidArgumentException('Company vehicle was not found.');
                }
            } else {
                $statement = $database->prepare('INSERT INTO company_vehicles (vehicle_unit, vehicle_color, plate_number, passenger_capacity, status) VALUES (?, ?, ?, ?, ?)');
                $statement->execute([$unit, $color, $plate, $capacity, $status]);
                $vehicleId = (int) $database->lastInsertId();
            }

            $imageStatement = $database->prepare('INSERT INTO company_vehicle_img (Company_Vehicle_ID, image_path) VALUES (?, ?)');
            foreach ($uploadImages as $uploadImage) {
                $fileName = 'company-vehicle-' . $vehicleId . '-' . bin2hex(random_bytes(8)) . '.' . $uploadImage['extension'];
                $targetPath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
                if (!move_uploaded_file($uploadImage['tmp_name'], $targetPath)) {
                    throw new RuntimeException('A vehicle photo could not be saved.');
                }
                $movedFiles[] = $targetPath;
                $imageStatement->execute([$vehicleId, 'assets/Vehicles/' . $fileName]);
            }
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            foreach ($movedFiles as $movedFile) {
                if (is_file($movedFile)) {
                    unlink($movedFile);
                }
            }
            throw $error;
        }
        echo json_encode(['success' => true, 'message' => 'Company vehicle saved.']);
        exit;
    }

    if ($type === 'create_employee') {
        $fullName = trim((string) ($payload['full_name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new InvalidArgumentException('Name, valid email, and a password of at least 8 characters are required.');
        }
        $database->beginTransaction();
        try {
            $userStatement = $database->prepare("INSERT INTO users (role, full_name, email, phone, password_hash) VALUES ('employee', ?, ?, ?, ?)");
            $userStatement->execute([$fullName, $email, $phone ?: null, password_hash($password, PASSWORD_DEFAULT)]);
            $database->commit();
        } catch (Throwable $error) {
            $database->rollBack();
            if ($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'duplicate')) {
                throw new InvalidArgumentException('An account with that email already exists.');
            }
            throw $error;
        }
        echo json_encode(['success' => true, 'message' => 'Employee account created and queued for document review.']);
        exit;
    }

    if ($type === 'assign_company_vehicle') {
        $driverId = filter_var($payload['driver_id'] ?? null, FILTER_VALIDATE_INT);
        $vehicleId = filter_var($payload['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
        if ($driverId === false || $driverId < 1) {
            throw new InvalidArgumentException('A valid driver is required.');
        }
        $driverCheck = $database->prepare("SELECT Driver_Profile_ID FROM driver_profiles WHERE Driver_Profile_ID = ? AND verification_status = 'approved'");
        $driverCheck->execute([$driverId]);
        if (!$driverCheck->fetchColumn()) {
            throw new InvalidArgumentException('Only approved drivers can receive a company vehicle.');
        }
        if ($vehicleId === false || $vehicleId < 1) {
            $database->prepare('UPDATE driver_profiles SET company_vehicle_id = NULL WHERE Driver_Profile_ID = ?')->execute([$driverId]);
            echo json_encode(['success' => true, 'message' => 'Company vehicle unassigned.']);
            exit;
        }
        $vehicleCheck = $database->prepare("SELECT Company_Vehicle_ID FROM company_vehicles WHERE Company_Vehicle_ID = ? AND status = 'active'");
        $vehicleCheck->execute([$vehicleId]);
        if (!$vehicleCheck->fetchColumn()) {
            throw new InvalidArgumentException('Only active company vehicles can be assigned.');
        }
        $database->beginTransaction();
        try {
            $database->prepare('UPDATE driver_profiles SET company_vehicle_id = NULL WHERE company_vehicle_id = ?')->execute([$vehicleId]);
            $database->prepare('UPDATE driver_profiles SET company_vehicle_id = NULL WHERE Driver_Profile_ID = ?')->execute([$driverId]);
            $database->prepare('UPDATE driver_profiles SET company_vehicle_id = ? WHERE Driver_Profile_ID = ?')->execute([$vehicleId, $driverId]);
            $database->commit();
        } catch (Throwable $error) {
            $database->rollBack();
            throw $error;
        }
        echo json_encode(['success' => true, 'message' => 'Company vehicle assigned.']);
        exit;
    }

    if ($type === 'delete_company_vehicle') {
        $vehicleId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($vehicleId === false || $vehicleId < 1) {
            throw new InvalidArgumentException('A valid company vehicle is required.');
        }
        $assignedCheck = $database->prepare('SELECT Driver_Profile_ID FROM driver_profiles WHERE company_vehicle_id = ? LIMIT 1');
        $assignedCheck->execute([$vehicleId]);
        if ($assignedCheck->fetchColumn()) {
            throw new InvalidArgumentException('Unassign this vehicle from its driver before removing it.');
        }
        $imageStatement = $database->prepare('SELECT image_path FROM company_vehicle_img WHERE Company_Vehicle_ID = ?');
        $imageStatement->execute([$vehicleId]);
        $imagePaths = $imageStatement->fetchAll(PDO::FETCH_COLUMN);
        $database->prepare('DELETE FROM company_vehicles WHERE Company_Vehicle_ID = ?')->execute([$vehicleId]);
        foreach ($imagePaths as $imagePath) {
            $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Company vehicle removed.']);
        exit;
    }


    if ($type === 'hotel') {
        $hotelId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($payload['name'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));
        $region = trim((string) ($payload['region'] ?? 'Mindanao'));
        $description = trim((string) ($payload['description'] ?? ''));
        $pricePerNight = filter_var($payload['price_per_night'] ?? null, FILTER_VALIDATE_FLOAT);
        $amenities = trim((string) ($payload['amenities'] ?? ''));
        $rating = filter_var($payload['rating'] ?? null, FILTER_VALIDATE_FLOAT);
        $imageFiles = $_FILES['images'] ?? null;
        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Hotels';
        $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $status = in_array($payload['status'] ?? '', ['active', 'inactive'], true) ? $payload['status'] : 'active';

        if ($name === '' || $location === '' || $pricePerNight === false || $pricePerNight < 0) {
            throw new InvalidArgumentException('Hotel name, location, and valid price are required.');
        }

        $newImageCount = $imageFiles && isset($imageFiles['name']) && is_array($imageFiles['name'])
            ? count(array_filter($imageFiles['name']))
            : 0;
        if ($newImageCount > 10) {
            throw new InvalidArgumentException('Upload up to 10 photos per hotel.');
        }
        if ($newImageCount > 0 && !is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
            throw new RuntimeException('The hotel image folder could not be created.');
        }

        $database->beginTransaction();
        $isEditing = $hotelId !== false && $hotelId > 0;

        if ($isEditing) {
            $statement = $database->prepare('UPDATE hotels SET name = ?, location = ?, region = ?, description = ?, price_per_night = ?, amenities = ?, rating = ?, status = ? WHERE Hotel_ID = ?');
            $statement->execute([$name, $location, $region ?: null, $description ?: null, $pricePerNight, $amenities ?: null, $rating ?: 0, $status, $hotelId]);
            $exists = $database->prepare('SELECT Hotel_ID FROM hotels WHERE Hotel_ID = ?');
            $exists->execute([$hotelId]);
            if (!$exists->fetch()) {
                throw new InvalidArgumentException('Hotel was not found.');
            }
        } else {
            $statement = $database->prepare('INSERT INTO hotels (name, location, region, description, price_per_night, amenities, rating, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$name, $location, $region ?: null, $description ?: null, $pricePerNight, $amenities ?: null, $rating ?: 0, $status]);
            $hotelId = (int) $database->lastInsertId();
        }

        if ($newImageCount > 0) {
            $imageStatement = $database->prepare('INSERT INTO hotel_images (Hotel_ID, image_path) VALUES (?, ?)');
            foreach ($imageFiles['name'] as $index => $originalName) {
                if ($originalName === '') {
                    continue;
                }
                if ($imageFiles['error'][$index] !== UPLOAD_ERR_OK || $imageFiles['size'][$index] > 8 * 1024 * 1024) {
                    throw new InvalidArgumentException('Each hotel photo must be 8 MB or smaller.');
                }
                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($imageFiles['tmp_name'][$index]);
                if (!isset($allowedMimeTypes[$mimeType])) {
                    throw new InvalidArgumentException('Hotel photos must be JPG, PNG, or WEBP files.');
                }
                $fileName = 'hotel-' . $hotelId . '-' . bin2hex(random_bytes(8)) . '.' . $allowedMimeTypes[$mimeType];
                if (!move_uploaded_file($imageFiles['tmp_name'][$index], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
                    throw new RuntimeException('A hotel photo could not be saved.');
                }
                $imageStatement->execute([$hotelId, 'assets/Hotels/' . $fileName]);
            }
        }

        $database->commit();
        echo json_encode(['success' => true, 'id' => $hotelId, 'message' => $isEditing ? 'Hotel updated.' : 'Hotel added.']);
        exit;
    }

    if ($type === 'delete_hotel_image') {
        $imageId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($imageId === false || $imageId < 1) {
            throw new InvalidArgumentException('A valid hotel photo is required.');
        }

        $statement = $database->prepare('SELECT image_path FROM hotel_images WHERE Hotel_Image_ID = ?');
        $statement->execute([$imageId]);
        $image = $statement->fetch();
        if (!$image) {
            throw new InvalidArgumentException('Hotel photo was not found.');
        }

        $database->prepare('DELETE FROM hotel_images WHERE Hotel_Image_ID = ?')->execute([$imageId]);
        $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $image['image_path']);
        if (is_file($filePath)) {
            unlink($filePath);
        }
        echo json_encode(['success' => true, 'message' => 'Hotel photo removed.']);
        exit;
    }

    if ($type === 'delete_hotel') {
        $hotelId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($hotelId === false || $hotelId < 1) {
            throw new InvalidArgumentException('A valid hotel is required.');
        }

        $database->prepare('DELETE FROM hotel_images WHERE Hotel_ID = ?')->execute([$hotelId]);
        $database->prepare('UPDATE bookings SET Hotel_ID = NULL WHERE Hotel_ID = ?')->execute([$hotelId]);
        $database->prepare('UPDATE cart_items SET Hotel_ID = NULL WHERE Hotel_ID = ?')->execute([$hotelId]);

        $statement = $database->prepare('DELETE FROM hotels WHERE Hotel_ID = ?');
        $statement->execute([$hotelId]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('Hotel was not found.');
        }
        echo json_encode(['success' => true, 'message' => 'Hotel deleted.']);
        exit;
    }


    throw new InvalidArgumentException('Unsupported content type.');
} catch (InvalidArgumentException $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $error->getMessage() ?: 'Unable to save admin content right now.']);
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
    CONSTRAINT fk_extension_booking_admin_api FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
    CONSTRAINT fk_extension_user_admin_api FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$paymentColumns = $database->query('SHOW COLUMNS FROM payments')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('Extension_ID', $paymentColumns, true)) {
    $database->exec("ALTER TABLE payments ADD COLUMN Extension_ID INT UNSIGNED NULL AFTER Booking_ID");
}
if (!in_array('Payment_Method_ID', $paymentColumns, true)) {
    $database->exec("ALTER TABLE payments ADD COLUMN Payment_Method_ID INT UNSIGNED NULL AFTER Extension_ID");
}
foreach (['refund_status' => "ENUM('none', 'pending', 'partially_refunded', 'refunded') NOT NULL DEFAULT 'none'", 'refunded_amount' => 'DECIMAL(10, 2) NULL', 'refund_reference' => 'VARCHAR(120) NULL', 'refund_proof' => 'VARCHAR(500) NULL', 'refunded_at' => 'TIMESTAMP NULL'] as $column => $definition) {
    if (!in_array($column, $paymentColumns, true)) {
        $database->exec("ALTER TABLE payments ADD COLUMN {$column} {$definition}");
    }
}
foreach (['refund_detected_amount' => 'DECIMAL(10, 2) NULL', 'refund_ocr_status' => "VARCHAR(30) NULL"] as $column => $definition) {
    if (!in_array($column, $paymentColumns, true)) {
        $database->exec("ALTER TABLE payments ADD COLUMN {$column} {$definition}");
    }
}
try {
    $database->exec("ALTER TABLE payments ADD CONSTRAINT fk_payment_method FOREIGN KEY (Payment_Method_ID) REFERENCES payment_methods(Payment_Method_ID) ON DELETE SET NULL");
} catch (Throwable $error) {
    // The foreign key already exists on databases initialized from the updated schema.
}
    $database->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        Ticket_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        User_ID INT UNSIGNED NOT NULL,
        subject VARCHAR(180) NOT NULL DEFAULT 'General Inquiry',
        status ENUM('open', 'pending', 'closed') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_ticket_user_admin FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
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
    CONSTRAINT fk_cancellation_booking_admin FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
    CONSTRAINT fk_cancellation_payment_admin FOREIGN KEY (Payment_ID) REFERENCES payments(Payment_ID) ON DELETE SET NULL,
    CONSTRAINT fk_cancellation_user_admin FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    CONSTRAINT fk_cancellation_ticket_admin FOREIGN KEY (Ticket_ID) REFERENCES support_tickets(Ticket_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");