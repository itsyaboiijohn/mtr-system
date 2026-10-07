<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

$database = require_once __DIR__ . '/db_con.php';
$payload = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim((string) ($payload['email'] ?? '')));
$password = (string) ($payload['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Please enter a valid email and password.']);
    exit;
}

try {
    require_once __DIR__ . '/email-verification.php';
    mtrEnsureVerificationSchema($database);
    $statement = $database->prepare('SELECT User_ID AS id, role, full_name, email, password_hash, email_verified FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);
    $user = $statement->fetch();

    $passwordIsValid = $user && password_verify($password, $user['password_hash']);

    $passwordInfo = $user ? password_get_info($user['password_hash']) : [];

    if ($user && !$passwordIsValid && empty($passwordInfo['algo'])) {
        $passwordIsValid = hash_equals($user['password_hash'], $password);

        if ($passwordIsValid) {
            $upgrade = $database->prepare('UPDATE users SET password_hash = ? WHERE User_ID = ?');
            $upgrade->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
    }

    if (!$passwordIsValid) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    if ((int) $user['email_verified'] !== 1) {
        $otpResult = mtrIssueEmailOtp($database, (int) $user['id'], $user['email'], $user['full_name']);
        http_response_code(403);
        echo json_encode(['error' => 'Please verify your email first.', 'requires_verification' => true, 'email' => $user['email'], 'email_sent' => $otpResult !== 'failed']);
        exit;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    session_regenerate_id(true);

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int) $user['id'],
            'role' => $user['role'],
            'full_name' => $user['full_name'],
            'email' => $user['email']
        ]
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to sign in right now. Please try again.']);
}