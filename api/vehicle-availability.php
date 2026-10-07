<?php

function mtrEnsureVehicleBookingColumns(PDO $database): void
{
    $columns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('trip_duration_days', $columns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN trip_duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER travel_date');
    }
    if (!in_array('requested_vehicle_id', $columns, true)) {
        $database->exec('ALTER TABLE bookings ADD COLUMN requested_vehicle_id INT UNSIGNED NULL AFTER trip_duration_days');
    }
}

function mtrEnsureVehicleCartColumns(PDO $database): void
{
    $columns = $database->query('SHOW COLUMNS FROM cart_items')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('travel_date', $columns, true)) {
        $database->exec('ALTER TABLE cart_items ADD COLUMN travel_date DATE NULL AFTER departure_location');
    }
    if (!in_array('trip_duration_days', $columns, true)) {
        $database->exec('ALTER TABLE cart_items ADD COLUMN trip_duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER travel_date');
    }
    if (!in_array('requested_vehicle_id', $columns, true)) {
        $database->exec('ALTER TABLE cart_items ADD COLUMN requested_vehicle_id INT UNSIGNED NULL AFTER trip_duration_days');
    }
}

// Returns how many extra days a booking can be extended before its driver+vehicle unit
// is needed by another booking, or null when the booking has no unit to protect.
function mtrMaxExtensionDays(PDO $database, int $bookingId): ?int
{
    mtrEnsureVehicleBookingColumns($database);
    $statement = $database->prepare('SELECT b.travel_date, b.trip_duration_days, COALESCE(dp.company_vehicle_id, b.requested_vehicle_id) AS vehicle_id
        FROM bookings b
        LEFT JOIN booking_assignments ba ON ba.Booking_ID = b.Booking_ID
        LEFT JOIN driver_profiles dp ON dp.Driver_Profile_ID = ba.Driver_Profile_ID
        WHERE b.Booking_ID = ?');
    $statement->execute([$bookingId]);
    $booking = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$booking || empty($booking['vehicle_id'])) {
        return null;
    }

    $currentEnd = (new DateTimeImmutable($booking['travel_date']))->modify('+' . max(1, (int) $booking['trip_duration_days']) . ' days');
    $next = $database->prepare("SELECT MIN(b.travel_date)
        FROM bookings b
        LEFT JOIN booking_assignments ba ON ba.Booking_ID = b.Booking_ID
        LEFT JOIN driver_profiles assigned_driver ON assigned_driver.Driver_Profile_ID = ba.Driver_Profile_ID
        WHERE (assigned_driver.company_vehicle_id = ? OR b.requested_vehicle_id = ?)
            AND b.Booking_ID <> ?
            AND b.booking_status NOT IN ('cancelled', 'completed')
            AND COALESCE(b.approval_status, 'pending') <> 'rejected'
            AND b.travel_date >= ?");
    $next->execute([(int) $booking['vehicle_id'], (int) $booking['vehicle_id'], $bookingId, $currentEnd->format('Y-m-d')]);
    $nextStart = $next->fetchColumn();
    if (!$nextStart) {
        return null;
    }

    return max(0, (int) $currentEnd->diff(new DateTimeImmutable($nextStart))->days);
}

function mtrFindAvailableVehicles(PDO $database, string $travelDate, int $durationDays, int $passengerCount, ?int $excludeBookingId = null): array
{
    mtrEnsureVehicleBookingColumns($database);
    $travelEnd = (new DateTimeImmutable($travelDate))->modify('+' . $durationDays . ' days')->format('Y-m-d');
    $statement = $database->prepare("SELECT cv.Company_Vehicle_ID AS id, cv.vehicle_unit, cv.vehicle_color, cv.plate_number, cv.passenger_capacity, dp.Driver_Profile_ID AS driver_id,
            (SELECT image_path FROM company_vehicle_img WHERE Company_Vehicle_ID = cv.Company_Vehicle_ID ORDER BY Company_Vehicle_Img_ID LIMIT 1) AS image_path
        FROM company_vehicles cv
        INNER JOIN driver_profiles dp ON dp.company_vehicle_id = cv.Company_Vehicle_ID
        INNER JOIN users u ON u.User_ID = dp.User_ID AND u.role = 'driver'
        WHERE cv.status = 'active'
            AND dp.verification_status = 'approved'
            AND dp.is_available = 1
            AND cv.passenger_capacity >= ?
            AND NOT EXISTS (
                SELECT 1
                FROM bookings b
                LEFT JOIN booking_assignments ba ON ba.Booking_ID = b.Booking_ID
                LEFT JOIN driver_profiles assigned_driver ON assigned_driver.Driver_Profile_ID = ba.Driver_Profile_ID
                WHERE (assigned_driver.company_vehicle_id = cv.Company_Vehicle_ID OR b.requested_vehicle_id = cv.Company_Vehicle_ID)
                    AND b.booking_status NOT IN ('cancelled', 'completed')
                    AND COALESCE(b.approval_status, 'pending') <> 'rejected'
                    AND b.travel_date < ?
                    AND DATE_ADD(b.travel_date, INTERVAL b.trip_duration_days DAY) > ?
                    AND (? IS NULL OR b.Booking_ID <> ?)
            )
        ORDER BY cv.passenger_capacity ASC, cv.vehicle_unit ASC");
    $statement->execute([$passengerCount, $travelEnd, $travelDate, $excludeBookingId, $excludeBookingId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}