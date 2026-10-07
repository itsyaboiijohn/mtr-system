<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_user_auth(bool $jsonResponse = false): int
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId > 0) {
        if (!$jsonResponse && ($_SESSION['user_role'] ?? '') === 'admin') {
            header('Location: ../admin/admin.php');
            exit;
        }

        return $userId;
    }

    if ($jsonResponse || $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'Your session has expired. Please sign in again.']);
        exit;
    }

    header('Location: login.php');
    exit;
}
