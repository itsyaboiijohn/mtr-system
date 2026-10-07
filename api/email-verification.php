<?php
require_once __DIR__ . '/notifications.php';

const MTR_OTP_TTL_MINUTES = 10;
const MTR_OTP_MAX_ATTEMPTS = 5;
const MTR_OTP_RESEND_SECONDS = 60;

function mtrEnsureVerificationSchema(PDO $database): void
{
    $columns = $database->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email_verified', $columns, true)) {
        // Existing accounts stay verified so they are not locked out.
        $database->exec('ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1');
    }
    $database->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        User_ID INT UNSIGNED NOT NULL PRIMARY KEY,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_sent_at DATETIME NOT NULL,
        CONSTRAINT fk_email_verification_user FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Returns 'sent', 'failed', or 'wait' (resend cooldown still active). */
function mtrIssueEmailOtp(PDO $database, int $userId, string $email, string $fullName): string
{
    $lookup = $database->prepare('SELECT last_sent_at FROM email_verifications WHERE User_ID = ? AND last_sent_at > (NOW() - INTERVAL ' . MTR_OTP_RESEND_SECONDS . ' SECOND)');
    $lookup->execute([$userId]);
    if ($lookup->fetchColumn()) {
        return 'wait';
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $database->prepare('REPLACE INTO email_verifications (User_ID, code_hash, expires_at, attempts, last_sent_at) VALUES (?, ?, NOW() + INTERVAL ' . MTR_OTP_TTL_MINUTES . ' MINUTE, 0, NOW())')
        ->execute([$userId, password_hash($code, PASSWORD_DEFAULT)]);

    $body = '<p>Hello ' . mtrEmailName($fullName) . ',</p>'
        . '<p>Your Mindanao Travel Hub verification code is:</p>'
        . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px">' . $code . '</p>'
        . '<p>This code expires in ' . MTR_OTP_TTL_MINUTES . ' minutes. If you did not create an account, you can ignore this email.</p>';

    return mtrSendNotificationEmail($email, $fullName, 'Your verification code', $body) ? 'sent' : 'failed';
}
