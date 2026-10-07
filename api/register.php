<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

$database = require_once __DIR__ . '/db_con.php';
require_once __DIR__ . '/email-verification.php';
$databaseDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
$uploadDirectory = $databaseDirectory . DIRECTORY_SEPARATOR . 'uploads';

function textValue(string $name): string
{
    return trim((string) ($_POST[$name] ?? ''));
}

function saveUpload(string $field, array $allowedMimeTypes, string $uploadDirectory): string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please upload all required driver documents.');
    }
    if ($_FILES[$field]['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Each uploaded document must be 8 MB or smaller.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException('One of the uploaded documents has an unsupported file type.');
    }

    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
        throw new RuntimeException('A document could not be saved. Please try again.');
    }
    return $fileName;
}

try {
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
        throw new RuntimeException('The upload directory could not be created.');
    }

    $database->exec("CREATE TABLE IF NOT EXISTS users (
        User_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        role ENUM('customer', 'driver', 'employee', 'admin') NOT NULL,
        full_name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        phone VARCHAR(40),
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $database->exec("CREATE TABLE IF NOT EXISTS driver_profiles (
        Driver_Profile_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        User_ID INT UNSIGNED NOT NULL UNIQUE,
        training_certificate_file VARCHAR(255) NULL,
        license_file VARCHAR(255) NULL,
        test_drive_file VARCHAR(255) NULL,
        company_vehicle_id INT UNSIGNED NULL,
        training_completed TINYINT(1) NOT NULL DEFAULT 0,
        is_available TINYINT(1) NOT NULL DEFAULT 1,
        verification_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_driver_user FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $driverColumns = $database->query('SHOW COLUMNS FROM driver_profiles')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['training_certificate_file' => 'VARCHAR(255) NULL', 'license_file' => 'VARCHAR(255) NULL', 'test_drive_file' => 'VARCHAR(255) NULL'] as $column => $definition) {
        if (in_array($column, $driverColumns, true)) {
            $database->exec("ALTER TABLE driver_profiles MODIFY COLUMN {$column} {$definition}");
        }
    }
    foreach (['training_certificate_number', 'license_number', 'test_drive_certificate_number'] as $legacyColumn) {
        if (in_array($legacyColumn, $driverColumns, true)) {
            $database->exec("ALTER TABLE driver_profiles DROP COLUMN {$legacyColumn}");
        }
    }
    if (!in_array('company_vehicle_id', $driverColumns, true)) {
        $database->exec('ALTER TABLE driver_profiles ADD COLUMN company_vehicle_id INT UNSIGNED NULL AFTER test_drive_file');
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

    $role = textValue('role');
    $fullName = textValue('full_name');
    $email = strtolower(textValue('email'));
    $phone = textValue('phone');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!in_array($role, ['customer', 'driver'], true)) {
        throw new RuntimeException('Please choose an account type.');
    }
    if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please provide your name and a valid email address.');
    }
    if (strlen($password) < 8 || $password !== $passwordConfirmation) {
        throw new RuntimeException('Passwords must match and contain at least 8 characters.');
    }

    mtrEnsureVerificationSchema($database);
    $database->beginTransaction();
    $statement = $database->prepare('INSERT INTO users (role, full_name, email, phone, password_hash, email_verified) VALUES (?, ?, ?, ?, ?, 0)');
    $statement->execute([$role, $fullName, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $database->lastInsertId();

    if ($role === 'driver') {
        $trainingCompleted = ($_POST['training_completed'] ?? '') === 'yes';
        if (!$trainingCompleted) {
            throw new RuntimeException('Training confirmation is required.');
        }

        $certificateFile = saveUpload('training_certificate_image', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], $uploadDirectory);
        $licenseFile = saveUpload('license_image', ['image/jpeg', 'image/png', 'image/webp'], $uploadDirectory);
        $testDriveFile = saveUpload('test_drive_image', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], $uploadDirectory);
        $statement = $database->prepare('INSERT INTO driver_profiles (User_ID, training_certificate_file, license_file, test_drive_file, training_completed) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$userId, $certificateFile, $licenseFile, $testDriveFile, 1]);
    }

    $database->commit();
    $otpResult = mtrIssueEmailOtp($database, $userId, $email, $fullName);
    echo json_encode(['success' => true, 'requires_verification' => true, 'email' => $email, 'email_sent' => $otpResult === 'sent', 'message' => 'Account created. Please verify your email.']);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    http_response_code($error instanceof PDOException && str_contains($error->getMessage(), 'UNIQUE constraint failed') ? 409 : 400);
    echo json_encode(['error' => $error instanceof PDOException && str_contains($error->getMessage(), 'UNIQUE constraint failed') ? 'An account with that email already exists.' : $error->getMessage()]);
}
