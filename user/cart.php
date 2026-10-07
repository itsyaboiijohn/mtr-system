<?php
require_once __DIR__ . '/../api/auth.php';
$userId = require_user_auth($_SERVER['REQUEST_METHOD'] === 'POST');
$database = require_once __DIR__ . '/../api/db_con.php';
require_once __DIR__ . '/../api/customer-assignment.php';
require_once __DIR__ . '/../api/vehicle-availability.php';

// 1. Create cart_items table if it does not exist
$database->exec("CREATE TABLE IF NOT EXISTS cart_items (
    Cart_Item_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NULL,
    session_id VARCHAR(190) NULL,
    item_type ENUM('package', 'custom') NOT NULL DEFAULT 'custom',
    Package_ID INT UNSIGNED NULL,
    Hotel_ID INT UNSIGNED NULL,
	accommodation_type VARCHAR(30) NOT NULL DEFAULT 'none',
	accommodation_name VARCHAR(255) NULL,
	accommodation_address VARCHAR(500) NULL,
	accommodation_maps_link VARCHAR(500) NULL,
	pickup_location VARCHAR(180) NULL,
	departure_location VARCHAR(180) NULL,
	travel_date DATE NULL,
	trip_duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
	requested_vehicle_id INT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    image_url VARCHAR(500) NULL,
    travelers TINYINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cart_user
        FOREIGN KEY (User_ID) REFERENCES users(User_ID)
        ON DELETE CASCADE,
    CONSTRAINT fk_cart_package
        FOREIGN KEY (Package_ID) REFERENCES travel_packages(Travel_Pck_ID)
        ON DELETE SET NULL,
    CONSTRAINT fk_cart_hotel
        FOREIGN KEY (Hotel_ID) REFERENCES hotels(Hotel_ID)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure Hotel_ID column exists if table was created previously
$cCols = $database->query("SHOW COLUMNS FROM cart_items")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('Hotel_ID', $cCols, true)) {
    $database->exec("ALTER TABLE cart_items ADD COLUMN Hotel_ID INT UNSIGNED NULL AFTER Package_ID");
}
if (!in_array('pickup_location', $cCols, true)) {
	$database->exec("ALTER TABLE cart_items ADD COLUMN pickup_location VARCHAR(180) NULL AFTER Hotel_ID");
}
if (!in_array('departure_location', $cCols, true)) {
	$database->exec("ALTER TABLE cart_items ADD COLUMN departure_location VARCHAR(180) NULL AFTER pickup_location");
}
foreach (['accommodation_type' => "VARCHAR(30) NOT NULL DEFAULT 'none'", 'accommodation_name' => 'VARCHAR(255) NULL', 'accommodation_address' => 'VARCHAR(500) NULL', 'accommodation_maps_link' => 'VARCHAR(500) NULL'] as $column => $definition) {
	if (!in_array($column, $cCols, true)) {
		$database->exec("ALTER TABLE cart_items ADD COLUMN {$column} {$definition}");
	}
}
mtrEnsureVehicleCartColumns($database);

$sessionId = session_id();

// 2. Handle POST API requests for cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = (string) ($payload['action'] ?? '');

    if ($action === 'add' || $action === 'sync') {
        $title = trim((string) ($payload['title'] ?? 'My Custom Itinerary'));
        $details = trim((string) ($payload['details'] ?? 'Custom destinations'));
        $imageUrl = trim((string) ($payload['image_url'] ?? '../assets/logo/logo.png'));
        $itemType = in_array($payload['item_type'] ?? '', ['package', 'custom'], true) ? $payload['item_type'] : 'custom';
        $packageId = filter_var($payload['package_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $hotelId = filter_var($payload['hotel_id'] ?? $payload['Hotel_ID'] ?? null, FILTER_VALIDATE_INT) ?: null;
		$accommodationType = in_array($payload['accommodation_type'] ?? 'none', ['listed_hotel', 'custom_hotel', 'friend_or_family', 'other', 'none'], true) ? $payload['accommodation_type'] : 'none';
		$accommodationName = trim((string) ($payload['accommodation_name'] ?? '')) ?: null;
		$accommodationAddress = trim((string) ($payload['accommodation_address'] ?? '')) ?: null;
		$accommodationMapsLink = trim((string) ($payload['accommodation_maps_link'] ?? '')) ?: null;
		$pickupLocation = trim((string) ($payload['pickup_location'] ?? '')) ?: null;
		$departureLocation = trim((string) ($payload['departure_location'] ?? '')) ?: null;
        $travelers = max(1, (int) ($payload['travelers'] ?? 1));
        $unitPrice = filter_var($payload['unit_price'] ?? 0, FILTER_VALIDATE_FLOAT) ?: 0.00;
        $totalPrice = $unitPrice * $travelers;
		$travelDate = trim((string) ($payload['travel_date'] ?? ''));
		$travelDate = $travelDate !== '' ? $travelDate : null;
		$rawDuration = $payload['trip_duration_days'] ?? 1;
		$tripDuration = filter_var($rawDuration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
		$requestedVehicleId = ($payload['requested_vehicle_id'] ?? '') === '' ? null : filter_var($payload['requested_vehicle_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($tripDuration === false || $requestedVehicleId === false) {
			http_response_code(400);
			echo json_encode(['error' => 'Choose a valid trip duration and vehicle.']);
			exit;
		}
		if ($travelDate !== null) {
			$parsedTravelDate = DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
			if (!$parsedTravelDate || $parsedTravelDate->format('Y-m-d') !== $travelDate || $parsedTravelDate < new DateTimeImmutable('today')) {
				http_response_code(400);
				echo json_encode(['error' => 'Choose a valid travel date today or later.']);
				exit;
			}
		}
		if ($itemType === 'custom' && $requestedVehicleId !== null) {
			if ($travelDate === null) {
				http_response_code(400);
				echo json_encode(['error' => 'A travel date is required for the selected vehicle.']);
				exit;
			}
			$availableVehicles = mtrFindAvailableVehicles($database, $travelDate, $tripDuration, $travelers);
			$availableVehicleIds = array_map('intval', array_column($availableVehicles, 'id'));
			if (!in_array((int) $requestedVehicleId, $availableVehicleIds, true)) {
				http_response_code(409);
				echo json_encode(['error' => 'That vehicle is no longer available for your dates and passenger count. Please choose another vehicle.']);
				exit;
			}
		}

		$insertStmt = $database->prepare("INSERT INTO cart_items (User_ID, session_id, item_type, Package_ID, Hotel_ID, accommodation_type, accommodation_name, accommodation_address, accommodation_maps_link, pickup_location, departure_location, travel_date, trip_duration_days, requested_vehicle_id, title, details, image_url, travelers, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
		$insertStmt->execute([$userId, $sessionId, $itemType, $packageId, $hotelId, $accommodationType, $accommodationName, $accommodationAddress, $accommodationMapsLink, $pickupLocation, $departureLocation, $travelDate, $tripDuration, $requestedVehicleId, $title, $details, $imageUrl, $travelers, $unitPrice, $totalPrice]);
        $newItemId = $database->lastInsertId();

        echo json_encode(['success' => true, 'id' => (int) $newItemId]);
        exit;
    }

    if ($action === 'update_plan') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $title = trim((string) ($payload['title'] ?? ''));
        $details = trim((string) ($payload['details'] ?? ''));
        $imageUrl = trim((string) ($payload['image_url'] ?? ''));
        $hotelId = filter_var($payload['hotel_id'] ?? null, FILTER_VALIDATE_INT);
        $hotelId = $hotelId !== false && $hotelId > 0 ? $hotelId : null;
        $accommodationType = (string) ($payload['accommodation_type'] ?? 'none');
        $travelers = filter_var($payload['travelers'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $travelDate = trim((string) ($payload['travel_date'] ?? ''));
        $duration = filter_var($payload['trip_duration_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
        $rawVehicleId = $payload['requested_vehicle_id'] ?? null;
        $vehicleId = $rawVehicleId === '' || $rawVehicleId === null
            ? null
            : filter_var($rawVehicleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pickupLocation = trim((string) ($payload['pickup_location'] ?? ''));
        $departureLocation = trim((string) ($payload['departure_location'] ?? ''));
        $unitPrice = filter_var($payload['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);

        if (!$id || $title === '' || $details === '' || $imageUrl === ''
            || !in_array($accommodationType, ['listed_hotel', 'none'], true)
            || $travelers === false || $duration === false || $vehicleId === false
            || $unitPrice === false || $unitPrice < 0
            || $travelDate === '' || $pickupLocation === '' || $departureLocation === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Complete the required trip details before updating this plan.']);
            exit;
        }
        $parsedTravelDate = DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
        if (!$parsedTravelDate || $parsedTravelDate->format('Y-m-d') !== $travelDate || $parsedTravelDate < new DateTimeImmutable('today')) {
            http_response_code(400);
            echo json_encode(['error' => 'Choose a valid travel date today or later.']);
            exit;
        }

        $itemStatement = $database->prepare("SELECT Cart_Item_ID FROM cart_items WHERE Cart_Item_ID = ? AND item_type = 'custom' AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?) LIMIT 1");
        $itemStatement->execute([$id, $userId, $sessionId]);
        if (!$itemStatement->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'That custom plan is no longer in your cart.']);
            exit;
        }
        if ($hotelId !== null) {
            $hotelCheck = $database->prepare("SELECT Hotel_ID FROM hotels WHERE Hotel_ID = ? AND status = 'active' LIMIT 1");
            $hotelCheck->execute([$hotelId]);
            if (!$hotelCheck->fetchColumn()) {
                http_response_code(400);
                echo json_encode(['error' => 'The selected hotel is no longer available.']);
                exit;
            }
        }
        if ($vehicleId !== null) {
            $availableVehicles = mtrFindAvailableVehicles($database, $travelDate, $duration, $travelers);
            $availableVehicleIds = array_map('intval', array_column($availableVehicles, 'id'));
            if (!in_array((int) $vehicleId, $availableVehicleIds, true)) {
                http_response_code(409);
                echo json_encode(['error' => 'That vehicle is no longer available. Choose another vehicle or submit without a vehicle request.']);
                exit;
            }
        }

        $updateStatement = $database->prepare('UPDATE cart_items SET Hotel_ID = ?, accommodation_type = ?, pickup_location = ?, departure_location = ?, travel_date = ?, trip_duration_days = ?, requested_vehicle_id = ?, title = ?, details = ?, image_url = ?, travelers = ?, unit_price = ?, total_price = ? * ? WHERE Cart_Item_ID = ? AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?)');
        $updateStatement->execute([
            $hotelId,
            $accommodationType,
            $pickupLocation,
            $departureLocation,
            $travelDate,
            $duration,
            $vehicleId,
            $title,
            $details,
            $imageUrl,
            $travelers,
            $unitPrice,
            $unitPrice,
            $travelers,
            $id,
            $userId,
            $sessionId
        ]);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

	if ($action === 'submit_plan') {
		$selectedIds = array_values(array_unique(array_filter(
			array_map('intval', is_array($payload['item_ids'] ?? null) ? $payload['item_ids'] : []),
			static fn ($itemId) => $itemId > 0
		)));
		if (!$selectedIds) {
			http_response_code(400);
			echo json_encode(['error' => 'Select at least one plan to submit for approval.']);
			exit;
		}
		$selectedPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
		$cartStatement = $database->prepare("SELECT Cart_Item_ID, item_type, Package_ID, Hotel_ID, accommodation_type, accommodation_name, accommodation_address, accommodation_maps_link, pickup_location, departure_location, travel_date, trip_duration_days, requested_vehicle_id, title, details, image_url, travelers, total_price FROM cart_items WHERE User_ID = ? AND Cart_Item_ID IN ({$selectedPlaceholders}) ORDER BY created_at DESC");
		$cartStatement->execute(array_merge([$userId], $selectedIds));
		$submittedItems = $cartStatement->fetchAll(PDO::FETCH_ASSOC);
		if (count($submittedItems) !== count($selectedIds)) {
			http_response_code(400);
			echo json_encode(['error' => 'Some selected plans are no longer in your cart. Refresh the page and try again.']);
			exit;
		}
		mtrEnsureVehicleBookingColumns($database);

		$bookingColumns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
		if (!in_array('approval_status', $bookingColumns, true)) {
			$database->exec("ALTER TABLE bookings ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER booking_status");
		}
		if (!in_array('approval_note', $bookingColumns, true)) {
			$database->exec('ALTER TABLE bookings ADD COLUMN approval_note TEXT NULL AFTER approval_status');
		}

		$firstItem = $submittedItems[0];
		$details = (string) ($firstItem['details'] ?? 'Tailored Mindanao travel experience');
		$travelDate = trim((string) ($firstItem['travel_date'] ?? '')) ?: date('Y-m-d');
		if (empty($firstItem['travel_date']) && preg_match('/Date:\s*(\d{4}-\d{2}-\d{2})/i', $details, $dateMatch) && strtotime($dateMatch[1])) {
			$travelDate = $dateMatch[1];
		}
		$durationDays = max(1, (int) ($firstItem['trip_duration_days'] ?? 1));
		$requestedVehicleId = filter_var($firstItem['requested_vehicle_id'] ?? null, FILTER_VALIDATE_INT);
		$requestedVehicleId = $requestedVehicleId !== false && $requestedVehicleId > 0 ? $requestedVehicleId : null;
		if (($firstItem['item_type'] ?? '') === 'custom' && empty($firstItem['travel_date'])) {
			http_response_code(400);
			echo json_encode(['error' => 'Return to your custom plan and choose a travel date before submitting. A vehicle can be confirmed later if none is currently available.']);
			exit;
		}
		$totalAmount = array_sum(array_map(static fn ($item) => (float) $item['total_price'], $submittedItems));
		$guestCount = array_sum(array_map(static fn ($item) => (int) $item['travelers'], $submittedItems));
		foreach ($submittedItems as $item) {
			if (($item['item_type'] ?? '') === 'custom' && empty($item['travel_date'])) {
				http_response_code(400);
				echo json_encode(['error' => 'A travel date is required for every custom trip.']);
				exit;
			}
			if ((string) ($item['travel_date'] ?? '') !== $travelDate
				|| (int) ($item['trip_duration_days'] ?? 1) !== $durationDays
				|| (int) ($item['requested_vehicle_id'] ?? 0) !== (int) ($requestedVehicleId ?? 0)) {
				http_response_code(400);
				echo json_encode(['error' => 'All items in this booking must use the same travel date, duration, and vehicle request.']);
				exit;
			}
		}
		if ($guestCount > 100) {
			http_response_code(400);
			echo json_encode(['error' => 'Passenger count exceeds the supported limit.']);
			exit;
		}
		$title = count($submittedItems) > 1 ? count($submittedItems) . ' Custom Trips' : ($firstItem['title'] ?? 'Custom Travel Plan');
		$pickupLocation = trim((string) ($firstItem['pickup_location'] ?? '')) ?: null;
		$departureLocation = trim((string) ($firstItem['departure_location'] ?? '')) ?: null;
		$accommodationType = (string) ($firstItem['accommodation_type'] ?? 'none');
		$accommodationName = trim((string) ($firstItem['accommodation_name'] ?? '')) ?: null;
		$accommodationAddress = trim((string) ($firstItem['accommodation_address'] ?? '')) ?: null;
		$accommodationMapsLink = trim((string) ($firstItem['accommodation_maps_link'] ?? '')) ?: null;
		if (!in_array($accommodationType, ['listed_hotel', 'custom_hotel', 'friend_or_family', 'other', 'none'], true)) {
			http_response_code(400);
			echo json_encode(['error' => 'Choose a valid accommodation option.']);
			exit;
		}
		if ($accommodationType === 'listed_hotel') {
			$hotelCheck = $database->prepare("SELECT Hotel_ID FROM hotels WHERE Hotel_ID = ? AND status = 'active' LIMIT 1");
			$hotelCheck->execute([(int) ($firstItem['Hotel_ID'] ?? 0)]);
			if (!$hotelCheck->fetchColumn()) {
				http_response_code(400);
				echo json_encode(['error' => 'The selected hotel is no longer available.']);
				exit;
			}
		} elseif ($accommodationType !== 'none' && (!$accommodationName || !$accommodationAddress)) {
			http_response_code(400);
			echo json_encode(['error' => 'Enter the accommodation name and location.']);
			exit;
		}
		$destinationCount = 0;
		if (preg_match('/Destination count:\s*(\d+)/i', $details, $destinationMatch)) {
			$destinationCount = max(0, (int) $destinationMatch[1]);
		}
		if ($destinationCount >= 2 && $accommodationType === 'none') {
			http_response_code(400);
			echo json_encode(['error' => 'Trips with two or more destinations require one primary accommodation location.']);
			exit;
		}

		$bookingColumns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
		if (!in_array('pickup_location', $bookingColumns, true)) {
			$database->exec("ALTER TABLE bookings ADD COLUMN pickup_location VARCHAR(180) NULL");
		}
		if (!in_array('departure_location', $bookingColumns, true)) {
			$database->exec("ALTER TABLE bookings ADD COLUMN departure_location VARCHAR(180) NULL");
		}
		$bookingColumns = $database->query('SHOW COLUMNS FROM bookings')->fetchAll(PDO::FETCH_COLUMN);
		foreach (['accommodation_type' => "VARCHAR(30) NOT NULL DEFAULT 'none'", 'accommodation_name' => 'VARCHAR(255) NULL', 'accommodation_address' => 'VARCHAR(500) NULL', 'accommodation_maps_link' => 'VARCHAR(500) NULL'] as $column => $definition) {
			if (!in_array($column, $bookingColumns, true)) {
				$database->exec("ALTER TABLE bookings ADD COLUMN {$column} {$definition}");
			}
		}
		$reservingVehicle = $requestedVehicleId !== null;
		if ($reservingVehicle) {
			$database->beginTransaction();
			$vehicleLock = $database->prepare("SELECT Company_Vehicle_ID FROM company_vehicles WHERE Company_Vehicle_ID = ? AND status = 'active' FOR UPDATE");
			$vehicleLock->execute([$requestedVehicleId]);
			if (!$vehicleLock->fetchColumn()) {
				$database->rollBack();
				http_response_code(409);
				echo json_encode(['error' => 'That vehicle is no longer active. Please rebuild your plan and select another vehicle.']);
				exit;
			}
			$availableVehicles = mtrFindAvailableVehicles($database, $travelDate, $durationDays, max(1, $guestCount));
			$availableVehicleIds = array_map('intval', array_column($availableVehicles, 'id'));
			if (!in_array($requestedVehicleId, $availableVehicleIds, true)) {
				$database->rollBack();
				http_response_code(409);
				echo json_encode(['error' => 'That vehicle was booked while you were checking out. Please return to your plan and choose another vehicle.']);
				exit;
			}
		}
		$bookingStatement = $database->prepare("INSERT INTO bookings (Customer_ID, Travel_Pck_ID, Hotel_ID, accommodation_type, accommodation_name, accommodation_address, accommodation_maps_link, custom_title, custom_details, custom_image_url, travel_date, trip_duration_days, requested_vehicle_id, guest_count, total_amount, payment_status, booking_status, approval_status, pickup_location, departure_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending', ?, ?)");
		$bookingStatement->execute([$userId, $firstItem['Package_ID'] ?? null, $firstItem['Hotel_ID'] ?? null, $accommodationType, $accommodationName, $accommodationAddress, $accommodationMapsLink, $title, $details, $firstItem['image_url'] ?? null, $travelDate, $durationDays, $requestedVehicleId, max(1, $guestCount), $totalAmount, $pickupLocation, $departureLocation]);
		$bookingId = (int) $database->lastInsertId();
		mtrAssignCustomerToEmployee($database, $userId);
		if (empty($firstItem['Package_ID']) && preg_match('/(?:\d+\s+)?destinations?\s*·\s*(.*?)(?:\s*·\s*(?:Hotel|Accommodation|Itinerary):|$)/i', $details, $destinationMatch)) {
			$destinationNames = array_values(array_filter(array_map('trim', explode(',', $destinationMatch[1]))));
			if ($destinationNames) {
				$destinationPlaceholders = implode(',', array_fill(0, count($destinationNames), '?'));
				$destinationStatement = $database->prepare("SELECT Destination_ID, name FROM destinations WHERE name IN ({$destinationPlaceholders})");
				$destinationStatement->execute($destinationNames);
				$destinationIdsByName = [];
				foreach ($destinationStatement->fetchAll(PDO::FETCH_ASSOC) as $destinationRow) {
					$destinationIdsByName[strtolower(trim($destinationRow['name']))] = (int) $destinationRow['Destination_ID'];
				}
				$linkStatement = $database->prepare('INSERT IGNORE INTO booking_destinations (Booking_ID, Destination_ID, sort_order) VALUES (?, ?, ?)');
				foreach ($destinationNames as $sortOrder => $destinationName) {
					$destinationId = $destinationIdsByName[strtolower($destinationName)] ?? null;
					if ($destinationId) {
						$linkStatement->execute([$bookingId, $destinationId, $sortOrder + 1]);
					}
				}
			}
		}
		$database->prepare("DELETE FROM cart_items WHERE User_ID = ? AND Cart_Item_ID IN ({$selectedPlaceholders})")->execute(array_merge([$userId], $selectedIds));
		if ($reservingVehicle) {
			$database->commit();
		}

		echo json_encode(['success' => true, 'booking_id' => $bookingId]);
		exit;
	}

    if ($action === 'update_quantity') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $travelers = max(1, (int) ($payload['travelers'] ?? 1));
        if ($id) {
			$cartItem = $database->prepare('SELECT requested_vehicle_id FROM cart_items WHERE Cart_Item_ID = ? AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?)');
			$cartItem->execute([$id, $userId, $sessionId]);
			$cartVehicleId = (int) ($cartItem->fetchColumn() ?: 0);
			if ($cartVehicleId > 0) {
				$capacityStatement = $database->prepare("SELECT passenger_capacity FROM company_vehicles WHERE Company_Vehicle_ID = ? AND status = 'active'");
				$capacityStatement->execute([$cartVehicleId]);
				$capacity = (int) $capacityStatement->fetchColumn();
				if (!$capacity || $travelers > $capacity) {
					http_response_code(409);
					echo json_encode(['error' => $capacity ? "This vehicle fits up to {$capacity} passengers." : 'The selected vehicle is no longer active.']);
					exit;
				}
			}
            $stmt = $database->prepare("UPDATE cart_items SET travelers = ?, total_price = unit_price * ? WHERE Cart_Item_ID = ? AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?)");
            $stmt->execute([$travelers, $travelers, $id, $userId, $sessionId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_vehicle') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $rawVehicleId = $payload['vehicle_id'] ?? '';
        $vehicleId = $rawVehicleId === '' || $rawVehicleId === null
            ? null
            : filter_var($rawVehicleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || $vehicleId === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Choose a valid vehicle request.']);
            exit;
        }

        $itemStatement = $database->prepare('SELECT item_type, travel_date, trip_duration_days, travelers FROM cart_items WHERE Cart_Item_ID = ? AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?) LIMIT 1');
        $itemStatement->execute([$id, $userId, $sessionId]);
        $cartItem = $itemStatement->fetch(PDO::FETCH_ASSOC);
        if (!$cartItem) {
            http_response_code(404);
            echo json_encode(['error' => 'That trip is no longer in your cart.']);
            exit;
        }
        if ($vehicleId !== null) {
            if ($cartItem['item_type'] !== 'custom' || empty($cartItem['travel_date'])) {
                http_response_code(400);
                echo json_encode(['error' => 'A vehicle can only be requested for a custom trip with a travel date.']);
                exit;
            }
            $availableVehicles = mtrFindAvailableVehicles(
                $database,
                (string) $cartItem['travel_date'],
                max(1, (int) $cartItem['trip_duration_days']),
                max(1, (int) $cartItem['travelers'])
            );
            $availableVehicleIds = array_map('intval', array_column($availableVehicles, 'id'));
            if (!in_array((int) $vehicleId, $availableVehicleIds, true)) {
                http_response_code(409);
                echo json_encode(['error' => 'That vehicle is no longer available. Choose another vehicle or submit without a vehicle request.']);
                exit;
            }
        }

        $updateStatement = $database->prepare('UPDATE cart_items SET requested_vehicle_id = ? WHERE Cart_Item_ID = ? AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?)');
        $updateStatement->execute([$vehicleId, $id, $userId, $sessionId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_item') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim((string) ($payload['title'] ?? ''));
        $details = trim((string) ($payload['details'] ?? ''));
        $unitPrice = filter_var($payload['unit_price'] ?? 0, FILTER_VALIDATE_FLOAT) ?: 0.00;
        if ($id) {
            $stmt = $database->prepare("UPDATE cart_items SET title = ?, details = ?, unit_price = ?, total_price = ? * travelers WHERE Cart_Item_ID = ? AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?)");
            $stmt->execute([$title, $details, $unitPrice, $unitPrice, $id, $userId, $sessionId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'remove') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $database->prepare("DELETE FROM cart_items WHERE Cart_Item_ID = ? AND ((User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?)");
            $stmt->execute([$id, $userId, $sessionId]);
        } else {
            $stmt = $database->prepare("DELETE FROM cart_items WHERE (User_ID IS NOT NULL AND User_ID = ?) OR session_id = ?");
            $stmt->execute([$userId, $sessionId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// 3. Get cart items from DB for current session/user
$stmt = $database->prepare("
	    SELECT ci.Cart_Item_ID AS id, ci.item_type, ci.Package_ID, ci.Hotel_ID, ci.title, ci.details, ci.image_url, ci.travelers, ci.unit_price, ci.total_price,
			ci.pickup_location, ci.departure_location, ci.travel_date, ci.trip_duration_days, ci.requested_vehicle_id, cv.vehicle_unit AS requested_vehicle_name, cv.passenger_capacity AS requested_vehicle_capacity
	    FROM cart_items ci
		LEFT JOIN company_vehicles cv ON cv.Company_Vehicle_ID = ci.requested_vehicle_id
	    WHERE (ci.User_ID IS NOT NULL AND ci.User_ID = ?) OR (ci.session_id IS NOT NULL AND ci.session_id = ?)
    ORDER BY ci.created_at DESC
");
$stmt->execute([$userId, $sessionId]);
$dbCartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Query active destinations for price lookup
$destPricesStmt = $database->query("SELECT Destination_ID AS id, name, region, CAST(price AS DECIMAL(10,2)) AS price FROM destinations WHERE status = 'active'");
$destPrices = [];
$editDestinations = [];
if ($destPricesStmt) {
    foreach ($destPricesStmt->fetchAll(PDO::FETCH_ASSOC) as $dRow) {
        $destPrices[$dRow['name']] = (float) $dRow['price'];
        $editDestinations[] = [
            'id' => (string) $dRow['id'],
            'name' => $dRow['name'],
            'region' => $dRow['region']
        ];
    }
}
$editHotels = $database->query("SELECT Hotel_ID AS id, name, region FROM hotels WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($editHotels as &$editHotel) {
    $editHotel['id'] = (string) $editHotel['id'];
}
unset($editHotel);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Trip Cart | Mindanao Travel & Tours</title>
	<style>
		:root {
			--bg: #f0faff;
			--card: #ffffff;
			--primary: #0f1724;
			--muted: #6b7280;
			--accent: #0ea5e9;
			--accent-dark: #0369a1;
			--soft-accent: #e6f9ff;
			--line: rgba(15, 23, 42, 0.08);
			--shadow: 0 18px 45px rgba(2, 6, 23, 0.07);
			--radius: 14px;
			font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
		}

		* { box-sizing: border-box; }

		body {
			min-height: 100vh;
			margin: 0;
			background: var(--bg);
			color: var(--primary);
		}

		a { color: inherit; }

		.page {
			width: min(1080px, calc(100% - 40px));
			margin: 0 auto;
			padding: 28px 0 48px;
		}

		.site-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 20px;
			margin-bottom: 54px;
		}

		.brand {
			display: flex;
			align-items: center;
			gap: 12px;
			text-decoration: none;
		}

		.logo {
			display: grid;
			width: 44px;
			height: 44px;
			place-items: center;
			overflow: hidden;
			border: 1px solid rgba(14, 165, 233, 0.12);
			border-radius: 10px;
			background: #fff;
			box-shadow: 0 8px 20px rgba(2, 6, 23, 0.05);
		}

		.logo img { width: 100%; height: 100%; object-fit: contain; }

		.brand strong { display: block; font-size: 15px; }
		.brand span { color: var(--muted); font-size: 12px; }

		.back-link {
			display: inline-flex;
			align-items: center;
			gap: 7px;
			color: var(--accent-dark);
			font-size: 13px;
			font-weight: 700;
			text-decoration: none;
		}

		.back-link:hover { color: var(--accent); }

		.intro { margin-bottom: 24px; }
		.eyebrow {
			margin: 0 0 8px;
			color: var(--accent-dark);
			font-size: 12px;
			font-weight: 800;
			letter-spacing: 0.08em;
			text-transform: uppercase;
		}

		h1 { margin: 0; font-size: clamp(30px, 4vw, 42px); letter-spacing: -0.8px; }
		.intro p { margin: 10px 0 0; color: var(--muted); }

		.cart-layout {
			display: grid;
			grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.8fr);
			gap: 24px;
			align-items: start;
		}

		.card {
			border: 1px solid var(--line);
			border-radius: var(--radius);
			background: var(--card);
			box-shadow: var(--shadow);
		}

		.cart-card { padding: 22px; }
		.card-heading {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 16px;
			margin-bottom: 18px;
		}

		.card-heading h2 { margin: 0; font-size: 18px; }
		.item-count { color: var(--muted); font-size: 13px; }

		.trip-item {
			display: grid;
			grid-template-columns: auto 116px minmax(0, 1fr) auto;
			gap: 16px;
			align-items: center;
			padding: 14px 0;
			border-top: 1px solid var(--line);
		}

		.trip-select { display: grid; place-items: center; cursor: pointer; }
		.trip-select input { width: 20px; height: 20px; margin: 0; accent-color: var(--accent); cursor: pointer; }
		.checkout-button:disabled { opacity: .5; cursor: not-allowed; }

		.trip-image {
			height: 104px;
			border-radius: 10px;
			background-image: url('../assets/Spots/Screenshot%202026-09-05%20182549.png');
			background-position: center;
			background-size: cover;
		}

		.trip-copy h3 { margin: 0 0 6px; font-size: 16px; }
		.trip-copy p { margin: 0 0 9px; color: var(--muted); font-size: 13px; line-height: 1.45; }
		.trip-tag {
			display: inline-flex;
			padding: 5px 8px;
			border-radius: 6px;
			background: var(--soft-accent);
			color: var(--accent-dark);
			font-size: 11px;
			font-weight: 700;
		}
		.trip-schedule { display:block; margin-top:7px; color:var(--accent-dark); font-size:11px; font-weight:700; }
		.vehicle-request { display:grid; gap:9px; margin:14px 0; max-width:620px; }
		.vehicle-request > span { font-size:12px; font-weight:800; }
		.vehicle-options { display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:10px; }
		.vehicle-option { display:grid; grid-template-columns:92px minmax(0, 1fr); gap:11px; align-items:center; min-width:0; padding:9px; border:1px solid var(--line); border-radius:10px; background:#fff; cursor:pointer; transition:border-color .15s, background .15s, box-shadow .15s; }
		.vehicle-option:hover { border-color:#9bd8f5; background:#f8fcff; }
		.vehicle-option:has(input:checked) { border-color:var(--accent); background:#f0faff; box-shadow:0 0 0 2px rgba(14,165,233,.1); }
		.vehicle-option input { position:absolute; width:1px; height:1px; opacity:0; }
		.vehicle-option input:focus-visible + .vehicle-photo { outline:3px solid rgba(14,165,233,.35); outline-offset:2px; }
		.vehicle-photo { display:grid; width:92px; height:72px; place-items:center; overflow:hidden; border-radius:7px; background:#eef6fa; color:var(--muted); font-size:10px; text-align:center; }
		.vehicle-photo img { width:100%; height:100%; object-fit:cover; }
		.vehicle-option-copy { min-width:0; }
		.vehicle-option-copy strong { display:block; overflow:hidden; color:var(--primary); font-size:12px; text-overflow:ellipsis; white-space:nowrap; }
		.vehicle-option-copy small { display:block; margin-top:5px; color:var(--muted); font-size:10px; line-height:1.4; }
		.vehicle-none { display:flex; align-items:center; gap:9px; padding:9px 11px; border:1px dashed var(--line); border-radius:8px; color:var(--muted); font-size:11px; cursor:pointer; }
		.vehicle-none input { margin:0; accent-color:var(--accent); }
		.vehicle-request > small { color:var(--muted); font-size:11px; line-height:1.45; }
		.plan-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
		.plan-actions button { min-height:34px; padding:7px 12px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--accent-dark); font:inherit; font-size:12px; font-weight:800; cursor:pointer; }
		.plan-actions button:hover { background:var(--soft-accent); }
		.plan-actions button[data-action="remove"] { color:#b42318; }

		.trip-price { align-self: start; font-weight: 800; white-space: nowrap; }
		.item-actions { display: flex; align-items: center; gap: 10px; margin-top: 12px; }

		.quantity {
			display: inline-flex;
			align-items: center;
			overflow: hidden;
			border: 1px solid var(--line);
			border-radius: 8px;
		}

		.quantity button {
			width: 28px;
			height: 28px;
			border: 0;
			background: #fff;
			color: var(--accent-dark);
			cursor: pointer;
		}

		.quantity button:hover { background: var(--soft-accent); }
		.quantity span { min-width: 25px; text-align: center; font-size: 13px; }
		.quantity-group { display: grid; gap: 5px; }
		.quantity-label { color: var(--muted); font-size: 11px; font-weight: 700; }

		.remove-button {
			padding: 0;
			border: 0;
			background: transparent;
			color: var(--muted);
			font: inherit;
			font-size: 12px;
			cursor: pointer;
		}

		.remove-button:hover { color: #dc2626; }

		.view-more-btn {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			margin-top: 6px;
			margin-bottom: 4px;
			padding: 4px 10px;
			border: 1px solid var(--line);
			border-radius: 6px;
			background: var(--soft-accent);
			color: var(--accent-dark);
			font: inherit;
			font-size: 12px;
			font-weight: 700;
			cursor: pointer;
			transition: background 0.2s, border-color 0.2s;
		}

		.view-more-btn:hover {
			background: #d8f4ff;
			border-color: var(--accent);
		}

		.view-more-btn .arrow {
			font-size: 10px;
			display: inline-block;
			transition: transform 0.2s ease;
		}

		.view-more-btn.expanded .arrow {
			transform: rotate(180deg);
		}

		.destinations-dropdown {
			margin-top: 8px;
			padding: 10px 14px;
			border: 1px solid var(--line);
			border-radius: 8px;
			background: #f8fdff;
		}

		.destinations-dropdown[hidden] {
			display: none;
		}

		.destinations-list {
			margin: 0;
			padding: 0;
			list-style: none;
			display: grid;
			gap: 6px;
		}

		.destinations-list li {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
			padding: 4px 0;
			border-bottom: 1px dashed var(--line);
			color: var(--primary);
			font-size: 13px;
			font-weight: 600;
		}

		.destinations-list li:last-child {
			border-bottom: 0;
		}

		.dest-info {
			display: flex;
			align-items: center;
			gap: 8px;
			min-width: 0;
		}

		.dest-price {
			color: var(--muted);
			font-size: 11px;
			font-weight: 500;
		}

		.remove-dest-btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 22px;
			height: 22px;
			padding: 0;
			border: 0;
			border-radius: 4px;
			background: transparent;
			color: var(--muted);
			font-size: 13px;
			font-weight: 700;
			line-height: 1;
			cursor: pointer;
			flex-shrink: 0;
			transition: background 0.15s, color 0.15s;
		}

		.remove-dest-btn:hover {
			background: #fee2e2;
			color: #dc2626;
		}

		.destinations-list .dest-icon {
			display: inline-grid;
			width: 20px;
			height: 20px;
			place-items: center;
			border-radius: 50%;
			background: #e0f2fe;
			color: var(--accent-dark);
			font-size: 11px;
			flex-shrink: 0;
		}

		.summary-card { padding: 22px; }
		.summary-card h2 { margin: 0 0 18px; font-size: 18px; }
		.summary-row { display: flex; justify-content: space-between; gap: 14px; margin: 13px 0; color: var(--muted); font-size: 14px; }
		.summary-row strong { color: var(--primary); }
		.summary-total { display: flex; justify-content: space-between; gap: 14px; margin-top: 20px; padding-top: 17px; border-top: 1px solid var(--line); font-size: 17px; font-weight: 800; }

		.checkout-button {
			display: inline-flex;
			width: 100%;
			min-height: 46px;
			align-items: center;
			justify-content: center;
			margin-top: 22px;
			border: 0;
			border-radius: 10px;
			background: var(--accent);
			color: #fff;
			font: inherit;
			font-weight: 800;
			cursor: pointer;
		}

		.checkout-button:hover { background: var(--accent-dark); }
		.secure-note { margin: 12px 0 0; color: var(--muted); font-size: 12px; text-align: center; }

		.empty-state { display: none; padding: 28px 0 12px; color: var(--muted); text-align: center; }
		.empty-state strong { display: block; margin-bottom: 6px; color: var(--primary); }

		.explore-link { display: inline-flex; margin-top: 24px; color: var(--accent-dark); font-size: 13px; font-weight: 700; text-decoration: none; }
		.explore-link:hover { color: var(--accent); }

		@media (max-width: 760px) {
			.page { width: min(100% - 28px, 560px); padding-top: 20px; }
			.site-header { margin-bottom: 38px; }
			.cart-layout { grid-template-columns: 1fr; }
		}

		@media (max-width: 480px) {
			.trip-item { grid-template-columns: auto 82px minmax(0, 1fr); }
			.trip-image { height: 82px; }
			.trip-price { grid-column: 3; grid-row: 1; justify-self: end; }
			.back-link { font-size: 0; gap: 0; }
			.back-link svg { width: 20px; height: 20px; }
		}
	</style>
</head>
<body>
	<main class="page">
		<header class="site-header">
			<a class="brand" href="dashboard.php">
				<span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span>
				<span><strong>Mindanao Travel & Tours</strong><span>Your trip cart</span></span>
			</a>
			<a class="back-link" href="dashboard.php">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
				Back to dashboard
			</a>
		</header>

		<section class="intro" aria-labelledby="cartTitle">
			<p class="eyebrow">Your itinerary</p>
			<h1 id="cartTitle">Trip cart</h1>
			<p>Review your trip and optionally request a car before submitting it for approval.</p>
		</section>

		<div class="cart-layout">
			<section class="card cart-card" aria-labelledby="selectedTripTitle">
				<div class="card-heading">
					<h2 id="selectedTripTitle">Selected trips</h2>
					<span class="item-count" id="itemCount">0 trips</span>
				</div>

				<div id="cartItemsList"></div>
				<div class="empty-state" id="emptyState"><strong>Your trip cart is empty</strong>Choose a package or custom plan from the dashboard to begin.</div>
				<a class="explore-link" href="region_map.php">← Continue exploring Mindanao</a>
			</section>

			<aside class="card summary-card" aria-labelledby="summaryTitle">
				<h2 id="summaryTitle">Trip summary</h2>
				<div class="summary-row"><span id="summaryItemLabel">Subtotal</span><strong id="summarySubtotal">₱ 0.00</strong></div>
				<div class="summary-row"><span>Planning fee</span><strong>Free</strong></div>
				<div class="summary-total"><span>Estimated total</span><span id="summaryTotal">₱ 0.00</span></div>
				<button class="checkout-button" type="button" id="startPlanning" disabled>Submit for approval</button>
				<p class="secure-note">Tick the plan(s) you want to submit. Request an available car or submit without one for vehicle confirmation. No payment is required yet.</p>
			</aside>
		</div>
	</main>

	<script>
		(() => {
			let items = <?= json_encode($dbCartItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
			const destPrices = <?= json_encode($destPrices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || {};
			const editDestinations = <?= json_encode($editDestinations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
			const editHotels = <?= json_encode($editHotels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
			const expandedDropdowns = new Set();
			const selectedItemIds = new Set(items.length === 1 ? [Number(items[0].id)] : []);

			const cartItemsList = document.querySelector('#cartItemsList');
			const emptyState = document.querySelector('#emptyState');
			const itemCount = document.querySelector('#itemCount');
			const summarySubtotal = document.querySelector('#summarySubtotal');
			const summaryTotal = document.querySelector('#summaryTotal');
			const checkoutBtn = document.querySelector('#startPlanning');
			const vehicleOptionsCache = new Map();

			const formatPrice = (value) => `₱ ${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

			const escapeHtml = (str) => {
				const div = document.createElement('div');
				div.textContent = str || '';
				return div.innerHTML;
			};

			const loadVehicleOptions = async (item) => {
				const key = `${item.id}:${item.travel_date}:${item.trip_duration_days}:${item.travelers}`;
				const optionsContainer = document.querySelector(`[data-vehicle-options="${item.id}"]`);
				if (!optionsContainer) return;
				let vehicles = vehicleOptionsCache.get(key);
				if (!vehicles) {
					optionsContainer.innerHTML = '<small>Checking available cars…</small>';
					try {
						const params = new URLSearchParams({
							travel_date: item.travel_date,
							duration_days: item.trip_duration_days,
							passengers: item.travelers
						});
						const response = await fetch(`../api/available-vehicles.php?${params.toString()}`, { cache: 'no-store' });
						const result = await response.json();
						if (!response.ok) throw new Error(result.error || 'Unable to check vehicle availability.');
						vehicles = Array.isArray(result.vehicles) ? result.vehicles : [];
						vehicleOptionsCache.set(key, vehicles);
					} catch (error) {
						console.error('Unable to load cart vehicle options:', error);
						const currentOptions = document.querySelector(`[data-vehicle-options="${item.id}"]`);
						if (currentOptions) currentOptions.innerHTML = '<small>Car choices could not be loaded. Your current request has not been changed.</small>';
						const note = document.querySelector(`[data-vehicle-note="${item.id}"]`);
						if (note) note.textContent = `${error.message} You can still submit for approval without a vehicle request.`;
						return;
					}
				}

				const currentOptions = document.querySelector(`[data-vehicle-options="${item.id}"]`);
				if (!currentOptions) return;
				const selectedId = String(item.requested_vehicle_id || '');
				const selectedVehicleAvailable = vehicles.some(vehicle => String(vehicle.id) === selectedId);
				currentOptions.innerHTML = `<label class="vehicle-none"><input type="radio" name="cartVehicle-${Number(item.id)}" value="" data-vehicle-select="${Number(item.id)}"${!selectedId ? ' checked' : ''}> No car request — assign during approval</label>`
					+ (selectedId && !selectedVehicleAvailable
						? '<small>Your previously requested car is no longer available for these dates. Choose another car or remove the request.</small>'
						: '')
					+ `<div class="vehicle-options">${vehicles.map(vehicle => {
						const photo = vehicle.image_path
							? `<img src="${escapeHtml(`../${vehicle.image_path}`)}" alt="${escapeHtml(vehicle.vehicle_unit)}">`
							: 'Photo unavailable';
						return `<label class="vehicle-option"><input type="radio" name="cartVehicle-${Number(item.id)}" value="${Number(vehicle.id)}" data-vehicle-select="${Number(item.id)}" data-name="${escapeHtml(vehicle.vehicle_unit)}" data-capacity="${Number(vehicle.passenger_capacity)}"${String(vehicle.id) === selectedId ? ' checked' : ''}><span class="vehicle-photo">${photo}</span><span class="vehicle-option-copy"><strong>${escapeHtml(vehicle.vehicle_unit)}</strong><small>${escapeHtml(vehicle.vehicle_color)} · ${escapeHtml(vehicle.plate_number)}<br>${Number(vehicle.passenger_capacity)} passengers</small></span></label>`;
					}).join('')}</div>`;
				const note = document.querySelector(`[data-vehicle-note="${item.id}"]`);
				if (note) {
					note.textContent = vehicles.length
						? 'Optional: request a currently available car. You can submit without one.'
						: 'No cars match these dates and passenger count. Submit without a car request for confirmation during approval.';
				}
			};

			const extractDestinations = (details) => {
				if (!details) return [];
				const destinationMatch = details.match(/^\s*\d+\s+destinations?\s*·\s*(.*?)(?=\s*·\s*(?:Hotel|Hotels|Itinerary):|$)/i);
				let destText = destinationMatch ? destinationMatch[1] : details;
				if (!destinationMatch && details.includes(' · ')) destText = details.split(' · ')[1] || details;
				if (destText.startsWith('Addons: ')) destText = destText.replace('Addons: ', '');
				if (destText.toLowerCase() === 'none') return [];
				return destText.split(',').map((s) => s.trim()).filter(Boolean);
			};

			const createEditablePlanDraft = (item) => {
				const destinationNames = extractDestinations(item.details);
				const selectedDestinations = destinationNames
					.map(name => editDestinations.find(destination => destination.name === name))
					.filter(Boolean);
				if (!selectedDestinations.length) return null;

				const details = String(item.details || '');
				const itineraryText = details.match(/(?:^| · )Itinerary:\s*(.*)$/i)?.[1] || '';
				const itineraryDays = itineraryText.split(/\s*\|\s*/).filter(Boolean);
				const assignments = {};
				for (const destination of selectedDestinations) {
					const escapedName = destination.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
					const escapedRegion = destination.region.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
					const itineraryDay = itineraryDays.find(day => new RegExp(`^Day\\s+(\\d+)\\s*:\\s*.*${escapedRegion}\\s*:\\s*${escapedName}(?:,|$)`, 'i').test(day));
					const dayNumber = itineraryDay?.match(/^Day\s+(\d+)\s*:/i)?.[1];
					if (dayNumber) assignments[destination.id] = Number(dayNumber);
				}

				const regions = [...new Set(selectedDestinations.map(destination => destination.region))];
				const transferDays = itineraryDays.flatMap(day => {
					const match = day.match(/^Day\s+(\d+)\s*:\s*Travel from (.+?) to (.+?)\s*$/i);
					return match ? [{ day: Number(match[1]), from: match[2], to: match[3] }] : [];
				});
				const hotelSection = details.match(/(?:^| · )Hotels?:\s*(.*?)(?=\s*·\s*Itinerary:|$)/i)?.[1] || '';
				const regionHotels = {};
				for (const hotelEntry of hotelSection.split(';')) {
					const separator = hotelEntry.indexOf(':');
					if (separator < 0) continue;
					const region = hotelEntry.slice(0, separator).trim();
					const hotelName = hotelEntry.slice(separator + 1).trim();
					const hotel = editHotels.find(entry => entry.name === hotelName && entry.region === region);
					if (hotel) regionHotels[region] = hotel.id;
				}
				const regionStarts = {};
				for (const region of regions) {
					const regionDays = selectedDestinations
						.filter(destination => destination.region === region)
						.map(destination => assignments[destination.id])
						.filter(Number.isInteger);
					if (regionDays.length) regionStarts[region] = Math.min(...regionDays);
				}
				for (const transfer of transferDays) {
					if (!regionStarts[transfer.to]) regionStarts[transfer.to] = transfer.day + 1;
				}
				return {
					destinationIds: selectedDestinations.map(destination => destination.id),
					regions,
					regionHotels,
					regionStarts,
					transferDays,
					assignments,
					arrivalRestDay: /Day\s+1\s*:\s*Arrival, hotel check-in, and rest/i.test(itineraryText),
					duration: Number(item.trip_duration_days) || 1,
					durationAdjusted: true,
					pickupLocation: item.pickup_location || '',
					departureLocation: item.departure_location || '',
					travelDate: item.travel_date || '',
					passengers: Number(item.travelers) || 1
				};
			};

			const renderCart = () => {
				if (!items || items.length === 0) {
					selectedItemIds.clear();
					cartItemsList.innerHTML = '';
					emptyState.style.display = 'block';
					itemCount.textContent = '0 trips';
					summarySubtotal.textContent = '₱ 0.00';
					summaryTotal.textContent = '₱ 0.00';
					if (checkoutBtn) checkoutBtn.disabled = true;
					return;
				}

				emptyState.style.display = 'none';
				const currentIds = new Set(items.map(item => Number(item.id)));
				[...selectedItemIds].forEach(id => { if (!currentIds.has(id)) selectedItemIds.delete(id); });
				if (checkoutBtn) checkoutBtn.disabled = selectedItemIds.size === 0;

				let grandTotal = 0;

				cartItemsList.innerHTML = items.map((item) => {
					const travelers = Number(item.travelers) || 1;
					const unitPrice = Number(item.unit_price) || 0;
					const itemTotal = unitPrice * travelers;
					const vehicleCapacity = Number(item.requested_vehicle_capacity) || 100;
					const isSelected = selectedItemIds.has(Number(item.id));
					if (isSelected) grandTotal += itemTotal;

					const bgImg = item.image_url ? `style="background-image:url('${escapeHtml(item.image_url)}')" ` : '';
					const destinations = extractDestinations(item.details);
					const hasMultiple = destinations.length >= 2;
					const isExpanded = expandedDropdowns.has(Number(item.id));

					let detailsSummary = escapeHtml(item.details);
					if (hasMultiple) {
						if (item.details.includes(' · ')) {
							detailsSummary = escapeHtml(item.details.split(' · ')[0]);
						} else {
							detailsSummary = `${destinations.length} destinations included`;
						}
					}

					const dropdownHtml = hasMultiple ? `
						<button class="view-more-btn${isExpanded ? ' expanded' : ''}" type="button" data-action="toggle-destinations" data-id="${item.id}" data-count="${destinations.length}">
							<span class="btn-text">${isExpanded ? 'Hide destinations' : `View destinations (${destinations.length})`}</span>
							<span class="arrow">▾</span>
						</button>
						<div class="destinations-dropdown" id="dest-dropdown-${item.id}" ${isExpanded ? '' : 'hidden'}>
							<ul class="destinations-list">
								${destinations.map((d) => `
									<li>
										<span class="dest-info">
											<span class="dest-icon">📍</span>
											<span class="dest-name">${escapeHtml(d)}</span>
											${destPrices[d] ? `<span class="dest-price">(₱ ${Number(destPrices[d]).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})</span>` : ''}
										</span>
										${item.item_type !== 'custom' ? `<button class="remove-dest-btn" type="button" data-action="remove-destination" data-id="${item.id}" data-dest="${escapeHtml(d)}" title="Remove ${escapeHtml(d)}">✕</button>` : ''}
									</li>
								`).join('')}
							</ul>
						</div>
					` : '';

					return `
						<article class="trip-item" data-id="${item.id}">
							<label class="trip-select" title="Select this plan to submit for approval"><input type="checkbox" data-select-plan="${Number(item.id)}" aria-label="Select ${escapeHtml(item.title)} for approval"${isSelected ? ' checked' : ''}></label>
							<div class="trip-image" role="img" aria-label="${escapeHtml(item.title)}" ${bgImg}></div>
							<div class="trip-copy">
								<h3>${escapeHtml(item.title)}</h3>
								<p>${detailsSummary}</p>
								${dropdownHtml}
								<span class="trip-tag">${item.item_type === 'custom' ? 'Custom plan' : 'Package plan'}</span>
								${item.travel_date ? `<small class="trip-schedule">${escapeHtml(item.travel_date)} · ${escapeHtml(item.trip_duration_days)} day(s) · ${escapeHtml(item.requested_vehicle_name || 'No car requested')}</small>` : ''}
								${item.item_type === 'custom' && item.travel_date ? `<div class="vehicle-request"><span>Optional car request</span><div data-vehicle-options="${Number(item.id)}"><small>Checking available cars…</small></div><small data-vehicle-note="${Number(item.id)}">Checking available cars for your trip dates.</small></div>` : ''}
								${item.item_type === 'custom' ? `<div class="plan-actions"><button type="button" data-action="edit-plan" data-id="${Number(item.id)}">Edit plan</button><button type="button" data-action="remove" data-id="${Number(item.id)}">Remove plan</button></div>` : ''}
								<div class="item-actions">
									<div class="quantity-group">
										<span class="quantity-label">Number of pax</span>
										<div class="quantity" aria-label="Number of travelers">
											<button type="button" data-action="decrease" data-id="${item.id}" aria-label="Decrease travelers">−</button>
											<span>${travelers}</span>
											<button type="button" data-action="increase" data-id="${item.id}" aria-label="Increase travelers"${travelers >= vehicleCapacity ? ' disabled' : ''}>+</button>
										</div>
									</div>
									${item.item_type !== 'custom' ? `<button class="remove-button" type="button" data-action="remove" data-id="${item.id}">Remove</button>` : ''}
								</div>
							</div>
							<div class="trip-price">${formatPrice(itemTotal)}</div>
						</article>
					`;
				}).join('');

				itemCount.textContent = `${items.length} trip${items.length === 1 ? '' : 's'}`;
				summarySubtotal.textContent = formatPrice(grandTotal);
				summaryTotal.textContent = formatPrice(grandTotal);
				items.filter(item => item.item_type === 'custom' && item.travel_date).forEach(loadVehicleOptions);
			};

			localStorage.removeItem('mtrCustomPlan');
			localStorage.removeItem('mtrCustomizedPackage');

			cartItemsList.addEventListener('click', async (event) => {
				const editPlanBtn = event.target.closest('button[data-action="edit-plan"]');
				if (editPlanBtn) {
					const itemId = Number(editPlanBtn.dataset.id);
					try {
						const savedPlans = JSON.parse(localStorage.getItem('mtrCartPlanDrafts') || '{}');
						const item = items.find(cartItem => Number(cartItem.id) === itemId);
						const planDraft = item && (
							savedPlans[String(itemId)]
							|| createEditablePlanDraft(item)
						);
						if (!planDraft || !Array.isArray(planDraft.destinationIds) || !planDraft.destinationIds.length) {
							window.alert('This plan’s destinations could not be matched to active destinations, so it cannot be edited. You can remove it and create a new plan.');
							return;
						}
						const editableDraft = {
							...planDraft,
							passengers: Number(item.travelers) || planDraft.passengers,
							travelDate: item.travel_date || planDraft.travelDate,
							duration: Number(item.trip_duration_days) || planDraft.duration,
							pickupLocation: item.pickup_location || planDraft.pickupLocation,
							departureLocation: item.departure_location || planDraft.departureLocation,
							editCartItemId: itemId
						};
						savedPlans[String(itemId)] = editableDraft;
						localStorage.setItem('mtrCartPlanDrafts', JSON.stringify(savedPlans));
						localStorage.setItem('mtrRegionPlanDraft', JSON.stringify(editableDraft));
						const firstRegion = planDraft.regions?.[0] || '';
						window.location.href = `build.php?region=${encodeURIComponent(firstRegion)}&continue=1&edit_cart_item=${itemId}`;
					} catch (error) {
						console.error('Unable to open this plan for editing:', error);
						window.alert('This plan could not be opened for editing. Please try again.');
					}
					return;
				}

				const toggleBtn = event.target.closest('button[data-action="toggle-destinations"]');
				if (toggleBtn) {
					const itemId = Number(toggleBtn.dataset.id);
					const dropdown = document.querySelector(`#dest-dropdown-${itemId}`);
					if (dropdown) {
						const isHidden = dropdown.hasAttribute('hidden');
						if (isHidden) {
							dropdown.removeAttribute('hidden');
							toggleBtn.classList.add('expanded');
							toggleBtn.querySelector('.btn-text').textContent = 'Hide destinations';
							expandedDropdowns.add(itemId);
						} else {
							dropdown.setAttribute('hidden', '');
							toggleBtn.classList.remove('expanded');
							const count = toggleBtn.dataset.count;
							toggleBtn.querySelector('.btn-text').textContent = `View destinations (${count})`;
							expandedDropdowns.delete(itemId);
						}
					}
					return;
				}

				const removeDestBtn = event.target.closest('button[data-action="remove-destination"]');
				if (removeDestBtn) {
					const itemId = Number(removeDestBtn.dataset.id);
					const destToRemove = removeDestBtn.dataset.dest;
					const itemIndex = items.findIndex((i) => Number(i.id) === itemId);
					if (itemIndex === -1) return;

					const item = items[itemIndex];
					const currentDests = extractDestinations(item.details);
					const newDests = currentDests.filter((d) => d !== destToRemove);

					if (newDests.length === 0) {
						items.splice(itemIndex, 1);
						expandedDropdowns.delete(itemId);
						renderCart();
						fetch('cart.php', {
							method: 'POST',
							headers: { 'Content-Type': 'application/json' },
							body: JSON.stringify({ action: 'remove', id: itemId })
						}).catch(err => console.error(err));
						return;
					}

					const removedPrice = destPrices[destToRemove] || 0;
					let newUnitPrice = Number(item.unit_price);
					if (removedPrice > 0) {
						newUnitPrice = Math.max(0, newUnitPrice - removedPrice);
					} else {
						newUnitPrice = Math.max(0, newUnitPrice * (newDests.length / currentDests.length));
					}

					let newTitle = item.title;
					let newDetails = '';

					if (item.item_type === 'custom') {
						newTitle = newDests.length === 1 ? newDests[0] : `Custom Itinerary (${newDests.length} destinations)`;
						newDetails = `${newDests.length} destination${newDests.length === 1 ? '' : 's'} · ${newDests.join(', ')}`;
					} else {
						if (item.details.includes(' · Addons: ')) {
							const prefix = item.details.split(' · Addons: ')[0];
							newDetails = `${prefix} · Addons: ${newDests.join(', ')}`;
						} else {
							newDetails = `${newDests.length} destination${newDests.length === 1 ? '' : 's'} · ${newDests.join(', ')}`;
						}
					}

					items[itemIndex].unit_price = newUnitPrice;
					items[itemIndex].title = newTitle;
					items[itemIndex].details = newDetails;
					items[itemIndex].total_price = newUnitPrice * Number(items[itemIndex].travelers);

					expandedDropdowns.add(itemId);
					renderCart();

					fetch('cart.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({
							action: 'update_item',
							id: itemId,
							title: newTitle,
							details: newDetails,
							unit_price: newUnitPrice
						})
					}).catch(err => console.error(err));
					return;
				}

				const btn = event.target.closest('button[data-action]');
				if (!btn) return;

				const action = btn.dataset.action;
				const itemId = Number(btn.dataset.id);
				const itemIndex = items.findIndex((i) => Number(i.id) === itemId);
				if (itemIndex === -1) return;

				if (action === 'increase' || action === 'decrease') {
					const current = Number(items[itemIndex].travelers) || 1;
					const next = action === 'increase' ? current + 1 : Math.max(1, current - 1);
					items[itemIndex].travelers = next;
					items[itemIndex].total_price = Number(items[itemIndex].unit_price) * next;
					renderCart();

					fetch('cart.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ action: 'update_quantity', id: itemId, travelers: next })
					}).catch(err => console.error(err));
				} else if (action === 'remove') {
					if (!window.confirm('Remove this plan from your cart?')) return;
					try {
						const response = await fetch('cart.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ action: 'remove', id: itemId })
						});
						const result = await response.json();
						if (!response.ok || !result.success) throw new Error(result.error || 'The plan could not be removed.');
						items.splice(itemIndex, 1);
						const savedPlans = JSON.parse(localStorage.getItem('mtrCartPlanDrafts') || '{}');
						delete savedPlans[String(itemId)];
						localStorage.setItem('mtrCartPlanDrafts', JSON.stringify(savedPlans));
						renderCart();
					} catch (error) {
						console.error('Unable to remove cart plan:', error);
						window.alert(error.message);
					}
				}
			});

			cartItemsList.addEventListener('change', async (event) => {
				const planCheckbox = event.target.closest('[data-select-plan]');
				if (planCheckbox) {
					const planId = Number(planCheckbox.dataset.selectPlan);
					if (planCheckbox.checked) selectedItemIds.add(planId); else selectedItemIds.delete(planId);
					renderCart();
					return;
				}
				const vehicleChoice = event.target.closest('[data-vehicle-select]');
				if (!vehicleChoice) return;
				const itemId = Number(vehicleChoice.dataset.vehicleSelect);
				const item = items.find(cartItem => Number(cartItem.id) === itemId);
				if (!item) return;
				const previousVehicleId = String(item.requested_vehicle_id || '');
				const selectedVehicleId = vehicleChoice.value;
				const requestedVehicleName = vehicleChoice.dataset.name || '';
				const requestedVehicleCapacity = Number(vehicleChoice.dataset.capacity) || null;
				const choices = cartItemsList.querySelectorAll(`[data-vehicle-select="${itemId}"]`);
				choices.forEach(choice => { choice.disabled = true; });
				try {
					const response = await fetch('cart.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ action: 'update_vehicle', id: itemId, vehicle_id: selectedVehicleId })
					});
					const result = await response.json();
					if (!response.ok || !result.success) throw new Error(result.error || 'The vehicle request could not be saved.');
					item.requested_vehicle_id = selectedVehicleId || null;
					item.requested_vehicle_name = selectedVehicleId ? requestedVehicleName : null;
					item.requested_vehicle_capacity = selectedVehicleId ? requestedVehicleCapacity : null;
					const note = document.querySelector(`[data-vehicle-note="${itemId}"]`);
					if (note) note.textContent = selectedVehicleId
						? `${item.requested_vehicle_name} requested. Final assignment will be confirmed during approval.`
						: 'No car requested. A vehicle can be confirmed during approval.';
					renderCart();
				} catch (error) {
					const previousChoice = cartItemsList.querySelector(`[data-vehicle-select="${itemId}"][value="${previousVehicleId}"]`);
					if (previousChoice) previousChoice.checked = true;
					choices.forEach(choice => { choice.disabled = false; });
					window.alert(error.message);
				}
			});

			document.querySelector('#startPlanning').addEventListener('click', async () => {
				const button = document.querySelector('#startPlanning');
				if (!selectedItemIds.size) return;
				button.disabled = true;
				try {
					const response = await fetch('cart.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ action: 'submit_plan', item_ids: [...selectedItemIds] })
					});
					const result = await response.json();
					if (!response.ok || !result.success) throw new Error(result.error || 'The plan could not be submitted.');
					window.location.href = 'booking.php';
				} catch (error) {
					button.disabled = false;
					window.alert(error.message);
				}
			});

			renderCart();
		})();
	</script>
</body>
</html>
