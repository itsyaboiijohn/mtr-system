<?php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$databaseName = getenv('DB_NAME') ?: 'mtr-system';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$charset = 'utf8mb4';

$serverDsn = "mysql:host={$host};port={$port};charset={$charset}";
$databaseDsn = "mysql:host={$host};port={$port};dbname=" . rawurlencode($databaseName) . ";charset={$charset}";

try {
	$serverConnection = new PDO($serverDsn, $username, $password, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false
	]);
	$serverConnection->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $databaseName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

	$database = new PDO($databaseDsn, $username, $password, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false
	]);

	// Auto-align table primary keys and foreign key column names if using older schema
	try {
		$tables = $database->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
		$hasTable = fn($tbl) => in_array($tbl, $tables, true);
		$getCols = function($tbl) use ($database) {
			try {
				return $database->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $tbl) . "`")->fetchAll(PDO::FETCH_COLUMN);
			} catch (Throwable $e) {
				return [];
			}
		};

		if ($hasTable('users')) {
			$cols = $getCols('users');
			if (in_array('id', $cols, true) && !in_array('User_ID', $cols, true)) {
				@$database->exec("ALTER TABLE users CHANGE id User_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			@$database->exec("ALTER TABLE users MODIFY COLUMN role ENUM('customer', 'driver', 'employee', 'admin') NOT NULL");
		}

		if ($hasTable('driver_profiles')) {
			$cols = $getCols('driver_profiles');
			if (in_array('id', $cols, true) && !in_array('Driver_Profile_ID', $cols, true)) {
				@$database->exec("ALTER TABLE driver_profiles CHANGE id Driver_Profile_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('user_id', $cols, true) && !in_array('User_ID', $cols, true)) {
				@$database->exec("ALTER TABLE driver_profiles CHANGE user_id User_ID INT UNSIGNED NOT NULL");
			}
			if (!in_array('is_available', $cols, true)) {
				@$database->exec("ALTER TABLE driver_profiles ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1 AFTER training_completed");
			}
			foreach (['training_certificate_file' => 'VARCHAR(255) NULL', 'ltfrb_number' => 'VARCHAR(120) NULL', 'license_file' => 'VARCHAR(255) NULL', 'test_drive_file' => 'VARCHAR(255) NULL'] as $column => $definition) {
				if (in_array($column, $cols, true)) @ $database->exec("ALTER TABLE driver_profiles MODIFY COLUMN {$column} {$definition}");
			}
			foreach (['training_certificate_number', 'license_number', 'test_drive_certificate_number'] as $legacyColumn) {
				if (in_array($legacyColumn, $cols, true)) {
					@$database->exec("ALTER TABLE driver_profiles DROP COLUMN {$legacyColumn}");
				}
			}
			if (!in_array('company_vehicle_id', $cols, true)) @$database->exec("ALTER TABLE driver_profiles ADD COLUMN company_vehicle_id INT UNSIGNED NULL AFTER test_drive_file");
		foreach (['plate_number', 'vehicle_unit', 'vehicle_color', 'passenger_capacity', 'vehicle_file'] as $legacyColumn) {
				if (in_array($legacyColumn, $cols, true)) {
					@$database->exec("ALTER TABLE driver_profiles DROP COLUMN {$legacyColumn}");
				}
			}
		}

		$database->exec("CREATE TABLE IF NOT EXISTS company_vehicles (
			Company_Vehicle_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			vehicle_unit VARCHAR(120) NOT NULL,
			vehicle_color VARCHAR(80) NOT NULL,
			plate_number VARCHAR(40) NOT NULL UNIQUE,
			passenger_capacity TINYINT UNSIGNED NOT NULL,
			status ENUM('active', 'maintenance', 'inactive') NOT NULL DEFAULT 'active',
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$database->exec("CREATE TABLE IF NOT EXISTS company_vehicle_img (
			Company_Vehicle_Img_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			Company_Vehicle_ID INT UNSIGNED NOT NULL,
			image_path VARCHAR(500) NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			CONSTRAINT fk_company_vehicle_img_vehicle FOREIGN KEY (Company_Vehicle_ID) REFERENCES company_vehicles(Company_Vehicle_ID) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$vehicleImageColumns = $database->query('SHOW COLUMNS FROM company_vehicle_img')->fetchAll(PDO::FETCH_ASSOC);
		$vehicleImageColumnNames = array_column($vehicleImageColumns, 'Field');
		$legacyVehicleImageId = array_values(array_intersect(['company_img_id', 'Vehicle_Img_ID', 'vehicle_img_id'], $vehicleImageColumnNames))[0] ?? null;
		if ($legacyVehicleImageId && !in_array('Company_Vehicle_Img_ID', $vehicleImageColumnNames, true)) {
			$database->exec("ALTER TABLE company_vehicle_img CHANGE `{$legacyVehicleImageId}` Company_Vehicle_Img_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
		}
		if (in_array('company_vehicle_id', $vehicleImageColumnNames, true) && !in_array('Company_Vehicle_ID', $vehicleImageColumnNames, true)) {
			$database->exec('ALTER TABLE company_vehicle_img CHANGE company_vehicle_id Company_Vehicle_ID INT UNSIGNED NOT NULL');
		}
		$vehicleImageColumns = $database->query('SHOW COLUMNS FROM company_vehicle_img')->fetchAll(PDO::FETCH_ASSOC);
		$vehicleImageColumnNames = array_column($vehicleImageColumns, 'Field');
		$vehicleImageVehicleColumn = array_values(array_filter($vehicleImageColumns, static fn ($column) => $column['Field'] === 'Company_Vehicle_ID'))[0] ?? null;
		if ($vehicleImageVehicleColumn && stripos((string) $vehicleImageVehicleColumn['Type'], 'unsigned') === false) {
			$database->exec('ALTER TABLE company_vehicle_img MODIFY Company_Vehicle_ID INT UNSIGNED NOT NULL');
		}
		$imagePathColumn = array_values(array_filter($vehicleImageColumns, static fn ($column) => $column['Field'] === 'image_path'))[0] ?? null;
		if ($imagePathColumn && stripos((string) $imagePathColumn['Type'], 'blob') !== false) {
			$longImagePaths = (int) $database->query('SELECT COUNT(*) FROM company_vehicle_img WHERE LENGTH(image_path) > 500')->fetchColumn();
			if ($longImagePaths === 0) {
				$database->exec('ALTER TABLE company_vehicle_img MODIFY image_path VARCHAR(500) NOT NULL');
			}
		}
		if (!in_array('created_at', $vehicleImageColumnNames, true)) {
			$database->exec('ALTER TABLE company_vehicle_img ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
		}
		$vehicleImageForeignKey = $database->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_vehicle_img' AND COLUMN_NAME = 'Company_Vehicle_ID' AND REFERENCED_TABLE_NAME = 'company_vehicles'");
		$vehicleImageForeignKey->execute();
		if (!(int) $vehicleImageForeignKey->fetchColumn()) {
			$orphanedVehicleImages = (int) $database->query('SELECT COUNT(*) FROM company_vehicle_img i LEFT JOIN company_vehicles v ON v.Company_Vehicle_ID = i.Company_Vehicle_ID WHERE v.Company_Vehicle_ID IS NULL')->fetchColumn();
			if ($orphanedVehicleImages === 0) {
				$database->exec('ALTER TABLE company_vehicle_img ADD CONSTRAINT fk_company_vehicle_img_vehicle FOREIGN KEY (Company_Vehicle_ID) REFERENCES company_vehicles(Company_Vehicle_ID) ON DELETE CASCADE');
			}
		}

		if ($hasTable('destinations')) {
			$cols = $getCols('destinations');
			if (in_array('id', $cols, true) && !in_array('Destination_ID', $cols, true)) {
				@$database->exec("ALTER TABLE destinations CHANGE id Destination_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
		}

		if ($hasTable('destination_images')) {
			$cols = $getCols('destination_images');
			if (in_array('id', $cols, true) && !in_array('Destination_Image_ID', $cols, true)) {
				@$database->exec("ALTER TABLE destination_images CHANGE id Destination_Image_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('destination_id', $cols, true) && !in_array('Destination_ID', $cols, true)) {
				@$database->exec("ALTER TABLE destination_images CHANGE destination_id Destination_ID INT UNSIGNED NOT NULL");
			}
		}

		if ($hasTable('travel_packages')) {
			$cols = $getCols('travel_packages');
			if (in_array('id', $cols, true) && !in_array('Travel_Pck_ID', $cols, true)) {
				@$database->exec("ALTER TABLE travel_packages CHANGE id Travel_Pck_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('destination_id', $cols, true) && !in_array('Destination_ID', $cols, true)) {
				@$database->exec("ALTER TABLE travel_packages CHANGE destination_id Destination_ID INT UNSIGNED NULL");
			}
		}

		if ($hasTable('travel_package_destinations')) {
			$cols = $getCols('travel_package_destinations');
			if (in_array('package_id', $cols, true) && !in_array('Travel_Pck_ID', $cols, true)) {
				@$database->exec("ALTER TABLE travel_package_destinations CHANGE package_id Travel_Pck_ID INT UNSIGNED NOT NULL");
			}
			if (in_array('destination_id', $cols, true) && !in_array('Destination_ID', $cols, true)) {
				@$database->exec("ALTER TABLE travel_package_destinations CHANGE destination_id Destination_ID INT UNSIGNED NOT NULL");
			}
		}

		if ($hasTable('travel_package_price_tiers')) {
			$cols = $getCols('travel_package_price_tiers');
			if (in_array('id', $cols, true) && !in_array('Travel_Pck_Price_Tier_ID', $cols, true)) {
				@$database->exec("ALTER TABLE travel_package_price_tiers CHANGE id Travel_Pck_Price_Tier_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('package_id', $cols, true) && !in_array('Travel_Pck_ID', $cols, true)) {
				@$database->exec("ALTER TABLE travel_package_price_tiers CHANGE package_id Travel_Pck_ID INT UNSIGNED NOT NULL");
			}
		}

		if ($hasTable('bookings')) {
			$cols = $getCols('bookings');
			if (in_array('id', $cols, true) && !in_array('Booking_ID', $cols, true)) {
				@$database->exec("ALTER TABLE bookings CHANGE id Booking_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('customer_id', $cols, true) && !in_array('Customer_ID', $cols, true)) {
				@$database->exec("ALTER TABLE bookings CHANGE customer_id Customer_ID INT UNSIGNED NOT NULL");
			} elseif (in_array('user_id', $cols, true) && !in_array('Customer_ID', $cols, true)) {
				@$database->exec("ALTER TABLE bookings CHANGE user_id Customer_ID INT UNSIGNED NOT NULL");
			} elseif (in_array('User_ID', $cols, true) && !in_array('Customer_ID', $cols, true)) {
				@$database->exec("ALTER TABLE bookings CHANGE User_ID Customer_ID INT UNSIGNED NOT NULL");
			}
			if (in_array('package_id', $cols, true) && !in_array('Travel_Pck_ID', $cols, true) && !in_array('Package_ID', $cols, true)) {
				@$database->exec("ALTER TABLE bookings CHANGE package_id Travel_Pck_ID INT UNSIGNED NULL");
			}
			if (in_array('Package_ID', $cols, true) && !in_array('Travel_Pck_ID', $cols, true)) {
				@$database->exec("ALTER TABLE bookings CHANGE Package_ID Travel_Pck_ID INT UNSIGNED NULL");
			}
			if (!in_array('Hotel_ID', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN Hotel_ID INT UNSIGNED NULL");
			}
			if (!in_array('custom_title', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN custom_title VARCHAR(255) NULL");
			}
			if (!in_array('custom_details', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN custom_details TEXT NULL");
			}
			if (!in_array('custom_image_url', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN custom_image_url VARCHAR(500) NULL");
			}
			if (!in_array('custom_duration_days', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN custom_duration_days TINYINT UNSIGNED NULL");
			}
			if (!in_array('pickup_location', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN pickup_location VARCHAR(180) NULL");
			}
			if (!in_array('pickup_address', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN pickup_address VARCHAR(500) NULL");
			}
			if (!in_array('pickup_notes', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN pickup_notes TEXT NULL");
			}
			if (!in_array('accommodation_type', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN accommodation_type VARCHAR(30) NOT NULL DEFAULT 'none'");
			}
			if (!in_array('accommodation_name', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN accommodation_name VARCHAR(255) NULL");
			}
			if (!in_array('accommodation_address', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN accommodation_address VARCHAR(500) NULL");
			}
			if (!in_array('accommodation_maps_link', $cols, true)) {
				@$database->exec("ALTER TABLE bookings ADD COLUMN accommodation_maps_link VARCHAR(500) NULL");
			}
		}

		if ($hasTable('booking_assignments')) {
			$cols = $getCols('booking_assignments');
			if (in_array('id', $cols, true) && !in_array('Booking_Assignment_ID', $cols, true)) {
				@$database->exec("ALTER TABLE booking_assignments CHANGE id Booking_Assignment_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('booking_id', $cols, true) && !in_array('Booking_ID', $cols, true)) {
				@$database->exec("ALTER TABLE booking_assignments CHANGE booking_id Booking_ID INT UNSIGNED NOT NULL");
			}
			if (in_array('driver_profile_id', $cols, true) && !in_array('Driver_Profile_ID', $cols, true)) {
				@$database->exec("ALTER TABLE booking_assignments CHANGE driver_profile_id Driver_Profile_ID INT UNSIGNED NOT NULL");
			}
		}

		if ($hasTable('cart_items')) {
			$cols = $getCols('cart_items');
			if (in_array('id', $cols, true) && !in_array('Cart_Item_ID', $cols, true)) {
				@$database->exec("ALTER TABLE cart_items CHANGE id Cart_Item_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('user_id', $cols, true) && !in_array('User_ID', $cols, true)) {
				@$database->exec("ALTER TABLE cart_items CHANGE user_id User_ID INT UNSIGNED NULL");
			}
			if (in_array('package_id', $cols, true) && !in_array('Package_ID', $cols, true)) {
				@$database->exec("ALTER TABLE cart_items CHANGE package_id Package_ID INT UNSIGNED NULL");
			}
			if (!in_array('Hotel_ID', $cols, true)) {
				@$database->exec("ALTER TABLE cart_items ADD COLUMN Hotel_ID INT UNSIGNED NULL");
			}
		}

		if ($hasTable('payments')) {
			$cols = $getCols('payments');
			if (in_array('customer_email', $cols, true)) {
				@$database->exec("ALTER TABLE payments DROP COLUMN customer_email");
			}
			if (in_array('id', $cols, true) && !in_array('Payment_ID', $cols, true)) {
				@$database->exec("ALTER TABLE payments CHANGE id Payment_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('booking_id', $cols, true) && !in_array('Booking_ID', $cols, true)) {
				@$database->exec("ALTER TABLE payments CHANGE booking_id Booking_ID INT UNSIGNED NULL");
			}
			if (in_array('user_id', $cols, true) && !in_array('User_ID', $cols, true)) {
				@$database->exec("ALTER TABLE payments CHANGE user_id User_ID INT UNSIGNED NULL");
			}
		}

		if ($hasTable('hotels')) {
			$cols = $getCols('hotels');
			if (in_array('id', $cols, true) && !in_array('Hotel_ID', $cols, true)) {
				@$database->exec("ALTER TABLE hotels CHANGE id Hotel_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
		}

		if ($hasTable('hotel_images')) {
			$cols = $getCols('hotel_images');
			if (in_array('id', $cols, true) && !in_array('Hotel_Image_ID', $cols, true)) {
				@$database->exec("ALTER TABLE hotel_images CHANGE id Hotel_Image_ID INT UNSIGNED NOT NULL AUTO_INCREMENT");
			}
			if (in_array('hotel_id', $cols, true) && !in_array('Hotel_ID', $cols, true)) {
				@$database->exec("ALTER TABLE hotel_images CHANGE hotel_id Hotel_ID INT UNSIGNED NOT NULL");
			}
		}

		$database->exec("CREATE TABLE IF NOT EXISTS booking_destinations (
			Booking_ID INT UNSIGNED NOT NULL,
			Destination_ID INT UNSIGNED NOT NULL,
			sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY (Booking_ID, Destination_ID),
			CONSTRAINT fk_booking_destination_booking FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
			CONSTRAINT fk_booking_destination_destination FOREIGN KEY (Destination_ID) REFERENCES destinations(Destination_ID) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$database->exec("CREATE TABLE IF NOT EXISTS booking_entrance_passes (
			Booking_ID INT UNSIGNED NOT NULL PRIMARY KEY,
			token CHAR(64) NOT NULL UNIQUE,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			CONSTRAINT fk_entrance_pass_booking FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
		$database->exec("CREATE TABLE IF NOT EXISTS booking_entrance_redemptions (
			Booking_ID INT UNSIGNED NOT NULL,
			Destination_ID INT UNSIGNED NOT NULL,
			redeemed_by INT UNSIGNED NOT NULL,
			redeemed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (Booking_ID, Destination_ID),
			CONSTRAINT fk_entrance_redemption_booking FOREIGN KEY (Booking_ID) REFERENCES bookings(Booking_ID) ON DELETE CASCADE,
			CONSTRAINT fk_entrance_redemption_destination FOREIGN KEY (Destination_ID) REFERENCES destinations(Destination_ID) ON DELETE CASCADE,
			CONSTRAINT fk_entrance_redemption_user FOREIGN KEY (redeemed_by) REFERENCES users(User_ID) ON DELETE RESTRICT
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	} catch (Throwable $migrationError) {
		// Suppress auto-migration errors
	}
} catch (PDOException $error) {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'Database connection failed. Check MySQL and your database credentials.']);
	exit;
}

return $database;
