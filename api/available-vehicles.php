<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Only GET requests are allowed.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_user_auth(true);
if (($_SESSION['user_role'] ?? '') !== 'customer') {
    http_response_code(403);
    echo json_encode(['error' => 'Only customers can view available vehicles.']);
    exit;
}

$database = require_once __DIR__ . '/db_con.php';
require_once __DIR__ . '/vehicle-availability.php';

try {
    $travelDate = trim((string) ($_GET['travel_date'] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
    if (!$date || $date->format('Y-m-d') !== $travelDate || $date < new DateTimeImmutable('today')) {
        throw new InvalidArgumentException('Choose a valid travel date today or later.');
    }
    $durationDays = filter_var($_GET['duration_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
    $passengerCount = filter_var($_GET['passengers'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($durationDays === false || $passengerCount === false) {
        throw new InvalidArgumentException('Choose a valid trip duration and passenger count.');
    }

    $imageStatement = $database->prepare('SELECT image_path FROM company_vehicle_img WHERE Company_Vehicle_ID = ? ORDER BY Company_Vehicle_Img_ID');
    $vehicles = array_map(static function ($vehicle) use ($imageStatement) {
        $imageStatement->execute([(int) $vehicle['id']]);
        return [
            'id' => (int) $vehicle['id'],
            'vehicle_unit' => $vehicle['vehicle_unit'],
            'vehicle_color' => $vehicle['vehicle_color'],
            'plate_number' => $vehicle['plate_number'],
            'passenger_capacity' => (int) $vehicle['passenger_capacity'],
            'image_path' => $vehicle['image_path'],
            'image_paths' => $imageStatement->fetchAll(PDO::FETCH_COLUMN)
        ];
    }, mtrFindAvailableVehicles($database, $travelDate, $durationDays, $passengerCount));
    echo json_encode(['vehicles' => $vehicles]);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to check vehicle availability right now.']);
}