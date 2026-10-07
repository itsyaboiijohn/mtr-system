<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

$database = require_once __DIR__ . '/db_con.php';
require_once __DIR__ . '/email-verification.php';

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string) ($payload['action'] ?? '');
$email = strtolower(trim((string) ($payload['email'] ?? '')));

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

try {
    mtrEnsureVerificationSchema($database);
    $statement = $database->prepare('SELECT User_ID AS id, role, full_name, email, email_verified FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);
    $user = $statement->fetch();
    if (!$user || (int) $user['email_verified'] === 1) {
        fail(400, 'This account does not need verification. Please sign in.');
    }
    $userId = (int) $user['id'];

    if ($action === 'resend') {
        $result = mtrIssueEmailOtp($database, $userId, $user['email'], $user['full_name']);
        if ($result === 'wait') {
            fail(429, 'Please wait a minute before requesting another code.');
        }
        if ($result === 'failed') {
            fail(500, 'The email could not be sent. Please check the mail configuration and try again.');
        }
        echo json_encode(['success' => true, 'message' => 'A new code was sent to your email.']);
        exit;
    }

    if ($action !== 'verify') {
        fail(400, 'Invalid request.');
    }

    $code = preg_replace('/\D/', '', (string) ($payload['code'] ?? ''));
    $record = $database->prepare('SELECT code_hash, attempts, expires_at < NOW() AS expired FROM email_verifications WHERE User_ID = ?');
    $record->execute([$userId]);
    $verification = $record->fetch();
    if (!$verification) {
        fail(400, 'No code was requested. Please request a new one.');
    }
    if ((int) $verification['expired'] === 1) {
        fail(400, 'This code has expired. Please request a new one.');
    }
    if ((int) $verification['attempts'] >= MTR_OTP_MAX_ATTEMPTS) {
        fail(429, 'Too many incorrect attempts. Please request a new code.');
    }
    if (strlen($code) !== 6 || !password_verify($code, $verification['code_hash'])) {
        $database->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE User_ID = ?')->execute([$userId]);
        fail(400, 'Incorrect code. Please try again.');
    }

    $database->prepare('UPDATE users SET email_verified = 1 WHERE User_ID = ?')->execute([$userId]);
    $database->prepare('DELETE FROM email_verifications WHERE User_ID = ?')->execute([$userId]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];

    echo json_encode(['success' => true, 'user' => ['role' => $user['role']]]);
} catch (Throwable $error) {
    fail(500, 'Unable to verify right now. Please try again.');
}
