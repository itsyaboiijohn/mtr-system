<?php
// Lightweight change signature used by pages to detect new data without a manual refresh.
require_once __DIR__ . '/auth.php';
$userId = require_user_auth(true);
$role = $_SESSION['user_role'] ?? '';
$database = require __DIR__ . '/db_con.php';
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$fingerprint = static function (PDO $database, string $sql, array $params = []): string {
    try {
        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        return implode(',', array_map('strval', $stmt->fetch(PDO::FETCH_NUM) ?: []));
    } catch (Throwable $error) {
        return 'err';
    }
};

$bookingCols = "COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', b.Booking_ID, b.booking_status, b.approval_status, b.payment_status, b.travel_date, b.trip_duration_days, b.total_amount, IFNULL(b.completion_proof, ''), IFNULL(b.completion_verified_at, '')))), 0)";
$parts = [];

if ($role === 'admin' || $role === 'employee') {
    $scope = $role === 'employee' ? 'AND EXISTS (SELECT 1 FROM customer_employee_assignments a WHERE a.Customer_ID = %s AND a.Employee_ID = ' . (int) $userId . ')' : '';
    $parts[] = $fingerprint($database, "SELECT $bookingCols FROM bookings b WHERE 1=1 " . sprintf($scope, 'b.Customer_ID'));
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', e.Extension_ID, e.status))), 0) FROM booking_extension_requests e WHERE 1=1 " . sprintf($scope, 'e.User_ID'));
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', c.Cancellation_ID, c.status))), 0) FROM cancellation_requests c WHERE 1=1 " . sprintf($scope, 'c.User_ID'));
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', p.Payment_ID, p.status, IFNULL(p.refund_status, '')))), 0) FROM payments p WHERE 1=1 " . sprintf($scope, 'p.User_ID'));
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', Booking_ID, Driver_Profile_ID))), 0) FROM booking_assignments");
} elseif ($role === 'driver') {
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', b.Booking_ID, b.booking_status, b.payment_status, b.travel_date, b.trip_duration_days, IFNULL(b.completion_proof, '')))), 0) FROM booking_assignments ba INNER JOIN driver_profiles dp ON dp.Driver_Profile_ID = ba.Driver_Profile_ID INNER JOIN bookings b ON b.Booking_ID = ba.Booking_ID WHERE dp.User_ID = ?", [$userId]);
} else {
    $parts[] = $fingerprint($database, "SELECT $bookingCols FROM bookings b WHERE b.Customer_ID = ?", [$userId]);
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', Extension_ID, status))), 0) FROM booking_extension_requests WHERE User_ID = ?", [$userId]);
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', Payment_ID, status, IFNULL(refund_status, '')))), 0) FROM payments WHERE User_ID = ?", [$userId]);
    $parts[] = $fingerprint($database, "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', Cancellation_ID, status))), 0) FROM cancellation_requests WHERE User_ID = ?", [$userId]);
}

echo json_encode(['signature' => md5(implode(';', $parts))]);
