<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../api/db_con.php';
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../user/login.php');
    exit;
}

$adminAccount = $database->query("SELECT full_name, email FROM users WHERE role = 'admin' ORDER BY User_ID ASC LIMIT 1")->fetch();
$adminAccount = $adminAccount ?: ['full_name' => 'Admin account', 'email' => ''];

// Fetch hotels data
$database->exec("CREATE TABLE IF NOT EXISTS hotels (
    Hotel_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    location VARCHAR(120) NOT NULL,
    region VARCHAR(120) NULL DEFAULT 'Mindanao',
    description TEXT NULL,
    price_per_night DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    amenities TEXT NULL,
    rating DECIMAL(2, 1) NULL DEFAULT 0.0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$hotels = $database->query(
    "SELECT Hotel_ID AS id, name, location, region, description, price_per_night, amenities, rating, status
     FROM hotels
     ORDER BY created_at DESC"
)->fetchAll();

// Fetch reviews data for ratings display
$database->exec("CREATE TABLE IF NOT EXISTS reviews (
    Review_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NULL UNIQUE,
    full_name VARCHAR(160) NOT NULL,
    review_text TEXT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_user FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$allReviews = $database->query(
    'SELECT Review_ID, User_ID, full_name, review_text, rating, created_at FROM reviews ORDER BY created_at DESC'
)->fetchAll();

$averageReviewRating = 0;
if ($allReviews) {
    $averageReviewRating = array_reduce($allReviews, static fn($sum, $review) => $sum + (int) $review['rating'], 0) / count($allReviews);
}
$averageReviewLabel = number_format((float) $averageReviewRating, 1, '.', '');
$averageReviewStars = str_repeat('★', (int) round($averageReviewRating)) . str_repeat('☆', 5 - (int) round($averageReviewRating));

$recentActivity = [];
try {
    $recentActivity = $database->query("SELECT activity_type, customer_name, activity_label, activity_detail, created_at, activity_link
        FROM (
            SELECT 'booking' AS activity_type, u.full_name AS customer_name,
                CASE
                    WHEN (NULLIF(b.custom_title, '') IS NOT NULL OR NULLIF(b.custom_details, '') IS NOT NULL)
                        AND COALESCE(b.approval_status, 'pending') IN ('pending', 'rejected')
                    THEN 'Custom plan needs approval'
                    ELSE COALESCE(NULLIF(b.custom_title, ''), p.name, 'Travel plan')
                END AS activity_label,
                CONCAT('Booking #', b.Booking_ID) AS activity_detail,
                b.created_at,
                CASE
                    WHEN (NULLIF(b.custom_title, '') IS NOT NULL OR NULLIF(b.custom_details, '') IS NOT NULL)
                        AND COALESCE(b.approval_status, 'pending') IN ('pending', 'rejected')
                    THEN '#custom-plan-approvals'
                    ELSE '#bookings'
                END AS activity_link
            FROM bookings b
            INNER JOIN users u ON u.User_ID = b.Customer_ID
            LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID
        ) AS activity
        ORDER BY created_at DESC
        LIMIT 8")->fetchAll();
} catch (Throwable $error) {
    $recentActivity = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Mindanao Travel Hub</title>
    <style>
        :root {
            --navy: #102a43;
            --blue: #0ea5e9;
            --blue-dark: #0369a1;
            --bg: #f3f8fb;
            --card: #fff;
            --text: #172b4d;
            --muted: #718096;
            --line: #e5edf3;
            --green: #15803d;
            --orange: #c2410c;
            --shadow: 0 12px 30px rgba(16, 42, 67, .07);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); }
        a { color: inherit; text-decoration: none; }
        button, input, select, textarea { font: inherit; }
        button { cursor: pointer; }
        .admin-layout { min-height: 100vh; display: grid; grid-template-columns: 250px 1fr; }
        .sidebar { position: sticky; top: 0; height: 100vh; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: none; -ms-overflow-style: none; background: var(--navy); color: #dbeafe; padding: 24px 16px; }
        .sidebar::-webkit-scrollbar { display: none; }
        .brand { display: flex; align-items: center; gap: 10px; padding: 0 10px 28px; }
        .brand img { width: 42px; height: 42px; padding: 5px; border-radius: 10px; background: #fff; object-fit: contain; }
        .brand strong { display: block; color: #fff; font-size: 15px; }
        .brand span { color: #9fb7cc; font-size: 11px; }
        .nav-label { margin: 18px 10px 8px; color: #8aa5bd; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .nav-link { display: flex; align-items: center; gap: 11px; margin: 4px 0; padding: 11px 12px; border-radius: 9px; color: #c7d8e7; font-size: 14px; }
        .nav-link:hover, .nav-link.active { background: rgba(14, 165, 233, .2); color: #fff; }
        .employee-nav-button { width: 100%; margin-top: 10px; padding: 11px 12px; border: 1px solid rgba(125, 211, 252, .35); border-radius: 9px; background: rgba(14, 165, 233, .18); color: #fff; font: inherit; font-size: 13px; font-weight: 800; text-align: left; cursor: pointer; }
        .employee-nav-button:hover { background: rgba(14, 165, 233, .32); }
        .employee-work-panel { display: none; }
        .admin-user { margin-top: auto; padding: 14px 10px 0; border-top: 1px solid rgba(255,255,255,.12); }
        .admin-user small { color: #9fb7cc; }
        .content { min-width: 0; padding: 28px clamp(18px, 4vw, 48px); }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 28px; }
        .topbar h1 { margin: 0 0 5px; font-size: 26px; }
        .topbar p { margin: 0; color: var(--muted); font-size: 14px; }
        .top-actions { display: flex; align-items: center; gap: 10px; }
        .avatar { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: #dff5ff; color: var(--blue-dark); font-weight: 800; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 38px; padding: 0 14px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--text); font-weight: 700; font-size: 13px; }
        .btn:hover { border-color: #b6d7e8; background: #f8fcff; }
        .btn.primary { border-color: var(--blue); background: var(--blue); color: #fff; }
        .btn.primary:hover { background: var(--blue-dark); }
        .btn.danger { color: #b91c1c; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
        .stat, .panel { background: var(--card); border: 1px solid var(--line); border-radius: 13px; box-shadow: var(--shadow); }
        .stat { padding: 18px; }
        .stat-label { color: var(--muted); font-size: 13px; }
        .stat-value { margin: 8px 0 3px; font-size: 28px; font-weight: 800; }
        .stat-note { color: var(--green); font-size: 12px; }
        .panel { padding: 20px; margin-bottom: 20px; }
        .panel-heading { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 16px; }
        .panel-heading h2 { margin: 0; font-size: 18px; }
        .panel-heading p { margin: 4px 0 0; color: var(--muted); font-size: 13px; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; }
        .search { width: min(290px, 100%); padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; outline: none; }
        .search:focus, .field input:focus, .field textarea:focus, .field select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(14,165,233,.12); outline: none; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 720px; }
        th, td { padding: 13px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; font-size: 13px; }
        th { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
        .package-name { display: flex; align-items: center; gap: 10px; min-width: 230px; }
        .package-thumb { width: 46px; height: 40px; border-radius: 7px; background-size: cover; background-position: center; flex: 0 0 auto; }
        .package-name strong { display: block; }
        .package-name span { color: var(--muted); font-size: 12px; }
        .price { font-weight: 800; }
        #packages .table-wrap { overflow: visible; }
        #packages table { display: block; min-width: 0; }
        #packages thead { display: none; }
        #packages #packageRows { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        #packages #packageRows tr { display: flex; min-height: 100%; flex-direction: column; gap: 12px; padding: 16px; border: 1px solid rgba(16, 42, 67, .08); border-radius: 12px; background: var(--card); box-shadow: 0 8px 20px rgba(16, 42, 67, .05); }
        #packages #packageRows td { display: block; padding: 0; border: 0; font-size: 13px; }
        #packages #packageRows td:first-child { order: 1; }
        #packages #packageRows td:nth-child(2) { order: 2; color: var(--muted); }
        #packages #packageRows td:nth-child(3) { order: 3; }
        #packages #packageRows td:nth-child(4) { order: 4; }
        #packages #packageRows td:last-child { order: 5; margin-top: auto; }
        #packages .package-name { min-width: 0; flex-direction: column; align-items: stretch; gap: 12px; }
        #packages .package-thumb { width: 100%; height: 170px; border-radius: 10px; background-color: #eaf8ff; }
        #packages .package-name strong { font-size: 16px; }
        #packages .package-name span { margin-top: 4px; line-height: 1.45; }
        #packages #packageRows td:nth-child(2)::before { content: "Destinations"; display: block; margin-bottom: 3px; color: var(--muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        #packages #packageRows td:nth-child(3)::before { content: "From / person"; display: block; margin-bottom: 3px; color: var(--muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        #packages #packageRows .price { color: var(--blue-dark); font-size: 18px; }
        #packages #packageRows .actions { justify-content: flex-end; gap: 8px; }
        #packages #packageRows .actions .btn + .btn { margin-left: 8px; }
        #packages #packageRows .icon-btn { width: auto; min-width: 78px; height: 36px; padding: 0 11px; border-radius: 9px; font-size: 0; font-weight: 700; }
        #packages #packageRows .icon-btn::before { margin-right: 6px; font-size: 16px; line-height: 1; }
        #packages #packageRows .icon-btn::after { font-size: 12px; }
        #packages #packageRows .icon-btn.edit { border-color: rgba(3, 105, 161, .2); background: #f0faff; color: var(--blue-dark); }
        #packages #packageRows .icon-btn.edit::before { content: "✎"; }
        #packages #packageRows .icon-btn.edit::after { content: "Edit"; }
        #packages #packageRows .icon-btn.edit:hover { border-color: var(--blue); background: #e0f5ff; }
        #packages #packageRows .icon-btn.delete { border-color: rgba(185, 28, 28, .18); background: #fff7f7; color: #b91c1c; }
        #packages #packageRows .icon-btn.delete::before { content: "×"; font-size: 20px; }
        #packages #packageRows .icon-btn.delete::after { content: "Remove"; }
        #packages #packageRows .icon-btn.delete:hover { border-color: #ef4444; background: #fee2e2; }
        @media (max-width: 1050px) { #packages #packageRows { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) { #packages #packageRows { grid-template-columns: 1fr; } }
        .status { display: inline-block; padding: 5px 9px; border-radius: 999px; background: #dcfce7; color: var(--green); font-size: 11px; font-weight: 700; }
        .driver-status { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .driver-status::before { width: 6px; height: 6px; border-radius: 50%; background: currentColor; content: ""; }
        .driver-status.available { background: #dcfce7; color: var(--green); }
        .driver-status.occupied { background: #fff7ed; color: var(--orange); }
        .driver-status.unavailable { background: #f1f5f9; color: var(--muted); }
        .driver-name { display: flex; align-items: center; gap: 10px; min-width: 190px; }
        .driver-avatar { display: grid; width: 38px; height: 38px; place-items: center; flex: 0 0 auto; border-radius: 50%; background: #dff5ff; color: var(--blue-dark); font-size: 12px; font-weight: 800; }
        .driver-name strong, .driver-name span { display: block; }
        .driver-name span, .guest-cell { color: var(--muted); font-size: 12px; }
        .vehicle-photo-strip { display: flex; flex-wrap: wrap; gap: 5px; min-width: 90px; }
        .vehicle-photo-strip img { display: block; width: 42px; height: 34px; border: 1px solid var(--line); border-radius: 5px; object-fit: cover; }
        .company-vehicle-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .company-vehicle-card { min-width: 0; overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        .company-vehicle-card-image { display: grid; height: 180px; place-items: center; overflow: hidden; background: #eaf8ff; color: var(--muted); font-size: 12px; }
        .company-vehicle-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .company-vehicle-card-body { display: grid; gap: 10px; padding: 14px; }
        .company-vehicle-card-body h3 { margin: 0; font-size: 16px; }
        .company-vehicle-card-subtitle { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.5; }
        .company-vehicle-card-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .company-vehicle-card-meta div { padding: 9px; border-radius: 7px; background: #f5f9fb; }
        .company-vehicle-card-meta span, .company-vehicle-card-meta strong { display: block; }
        .company-vehicle-card-meta span { margin-bottom: 3px; color: var(--muted); font-size: 10px; text-transform: uppercase; }
        .company-vehicle-card-meta strong { overflow-wrap: anywhere; font-size: 12px; text-transform: capitalize; }
        .company-vehicle-card-actions { display: flex; gap: 8px; }
        .company-vehicle-card-actions .btn { flex: 1; }
        .company-vehicle-view-card { width: min(760px, 100%); }
        .company-vehicle-gallery { position: relative; overflow: hidden; border-radius: 8px; background: #eaf8ff; }
        .company-vehicle-slide { display: none; width: 100%; max-height: 460px; aspect-ratio: 4 / 3; object-fit: contain; }
        .company-vehicle-slide.active { display: block; }
        .company-vehicle-gallery-control { position: absolute; top: 50%; z-index: 1; display: grid; width: 36px; height: 36px; place-items: center; transform: translateY(-50%); border: 0; border-radius: 50%; background: rgba(255,255,255,.92); color: var(--blue-dark); font-size: 22px; }
        .company-vehicle-gallery-control.previous { left: 12px; }
        .company-vehicle-gallery-control.next { right: 12px; }
        .company-vehicle-gallery-dots { position: absolute; right: 0; bottom: 12px; left: 0; z-index: 1; display: flex; justify-content: center; gap: 7px; }
        .company-vehicle-gallery-dot { width: 8px; height: 8px; padding: 0; border: 1px solid #fff; border-radius: 50%; background: rgba(255,255,255,.55); }
        .company-vehicle-gallery-dot.active { background: #fff; }
        @media (max-width: 1050px) { .company-vehicle-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .company-vehicle-grid { grid-template-columns: 1fr; } .company-vehicle-card-image { height: 210px; } }
        .driver-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .driver-profile-button { min-height: 32px; padding: 0 10px; border: 1px solid rgba(3, 105, 161, .2); border-radius: 7px; background: #f0faff; color: var(--blue-dark); font-size: 12px; font-weight: 700; white-space: nowrap; }
        .driver-profile-button { position: relative; }
        .approval-dot { position: absolute; top: -4px; right: -4px; width: 10px; height: 10px; border-radius: 50%; background: #dc2626; border: 2px solid #fff; }
        .schedule-modal-list { display: grid; gap: 8px; margin: 12px 0; }
        .schedule-modal-item { padding: 10px 12px; border-left: 3px solid var(--blue); border-radius: 7px; background: #f7fcfe; }
        .schedule-modal-item strong, .schedule-modal-item span { display: block; }
        .schedule-modal-item span { margin-top: 3px; color: var(--muted); font-size: 12px; }
        .driver-profile-button:hover { border-color: var(--blue); background: #e0f5ff; }
        .driver-filter { min-height: 38px; padding: 0 10px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--text); font-size: 13px; }
        .payment-status { display: inline-block; padding: 5px 9px; border-radius: 999px; background: #dcfce7; color: var(--green); font-size: 11px; font-weight: 700; }
        .pending-status { background: #fff7ed; color: var(--orange); }
        .approval-button { min-height: 32px; padding: 0 10px; border: 1px solid var(--blue); border-radius: 7px; background: var(--blue); color: #fff; font-size: 12px; font-weight: 700; }
        .approval-button:hover { background: var(--blue-dark); }
        .approval-button:disabled { border-color: var(--line); background: #f1f5f9; color: var(--muted); cursor: default; }
        .driver-modal-note { margin: -8px 0 18px; color: var(--muted); font-size: 12px; line-height: 1.5; }
        .actions { display: flex; gap: 6px; }
        .icon-btn { width: 32px; height: 32px; border: 1px solid var(--line); border-radius: 7px; background: #fff; color: var(--blue-dark); }
        .icon-btn:hover { background: #eaf8ff; }
        .place-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .place { overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: #fff; transition: transform 180ms ease, box-shadow 180ms ease; }
        .place:hover { position: relative; z-index: 3; transform: scale(1.04); box-shadow: 0 18px 36px rgba(16, 42, 67, .16); }
        .place-image { height: 150px; background-size: cover; background-position: center; }
        .place-carousel { position: relative; height: 150px; overflow: hidden; background: #eaf8ff; }
            .place-carousel-image { position: absolute; inset: 0; background-size: cover; background-position: center; opacity: 0; transition: opacity 220ms ease; }
            .place-carousel-image.active { opacity: 1; }
            .place-carousel-control { position: absolute; top: 50%; z-index: 2; width: 26px; height: 26px; border: 0; border-radius: 50%; background: rgba(255,255,255,.88); color: var(--blue-dark); transform: translateY(-50%); }
            .place-carousel-control.previous { left: 8px; }
            .place-carousel-control.next { right: 8px; }
            .place-carousel-dots { position: absolute; right: 0; bottom: 8px; left: 0; z-index: 2; display: flex; justify-content: center; gap: 4px; }
            .place-carousel-dot { width: 6px; height: 6px; padding: 0; border: 0; border-radius: 50%; background: rgba(255,255,255,.65); }
            .place-carousel-dot.active { background: #fff; }
        .place-body { padding: 12px; }
        .place-body strong { display: block; margin-bottom: 5px; }
        .place-body span { color: var(--muted); font-size: 12px; }
        .place-actions { display: flex; gap: 6px; margin-top: 10px; }
        .hotel-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
        .hotel-card { overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: #fff; transition: transform 180ms ease, box-shadow 180ms ease; }
        .hotel-card:hover { position: relative; z-index: 3; transform: scale(1.04); box-shadow: 0 18px 36px rgba(16, 42, 67, .16); }
        .hotel-image { height: 150px; background: #eaf8ff center / cover no-repeat; }
        .hotel-image.empty { display: grid; place-items: center; background: linear-gradient(135deg, #dff5ff, #f8fcff); color: var(--blue-dark); font-size: 12px; font-weight: 700; }
        .hotel-body { padding: 12px; }
        .hotel-body h3 { margin: 0 0 5px; font-size: 16px; }
        .hotel-location { display: block; margin-bottom: 12px; color: var(--muted); font-size: 12px; }
        .hotel-meta { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .hotel-price { color: var(--blue-dark); font-size: 16px; font-weight: 800; }
        .hotel-rating { color: #b45309; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .hotel-description { display: -webkit-box; min-height: 34px; margin: 10px 0 0; overflow: hidden; color: var(--muted); font-size: 12px; line-height: 1.4; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
        .hotel-card .place-actions { margin-top: 12px; }
        @media (max-width: 1050px) { .hotel-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 460px) { .hotel-grid { grid-template-columns: 1fr; } }
        .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(16,42,67,.5); z-index: 5; }
        .modal.open { display: flex; }
        .modal-card { width: min(620px, 100%); max-height: 90vh; overflow: auto; padding: 22px; border-radius: 14px; background: #fff; box-shadow: 0 25px 70px rgba(2,6,23,.2); }
        .modal-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .modal-heading h2 { margin: 0; font-size: 20px; }
        .close { border: 0; background: transparent; color: var(--muted); font-size: 22px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { display: grid; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        .field label { color: var(--muted); font-size: 12px; font-weight: 700; }
        .field input, .field select, .field textarea { width: 100%; padding: 10px 11px; border: 1px solid var(--line); border-radius: 8px; }
        .field select[multiple] { min-height: 110px; }
        .field textarea { min-height: 82px; resize: vertical; }
        .destination-preview, .price-tier-list { display: grid; gap: 10px; }
        .destination-preview { grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); margin-top: 10px; }
        .destination-preview-item { overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: #f8fcff; }
        .destination-preview-image { height: 64px; background-size: cover; background-position: center; }
        .destination-preview-item span { display: block; padding: 6px; color: var(--muted); font-size: 11px; }
        .price-tier { display: grid; grid-template-columns: 1fr 1fr 1.2fr auto; gap: 8px; align-items: end; }
        .price-tier label { display: grid; gap: 5px; color: var(--muted); font-size: 11px; font-weight: 700; }
        .price-tier input { min-width: 0; }
        .remove-tier { width: 34px; height: 38px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: #b91c1c; }
        @media (max-width: 520px) { .price-tier { grid-template-columns: 1fr 1fr; } .remove-tier { width: 100%; } }
        .photo-grid { display: flex; flex-wrap: wrap; gap: 10px; }
        .photo-thumb { position: relative; width: 84px; height: 74px; overflow: hidden; border: 1px solid var(--line); border-radius: 9px; }
        .photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo-remove { position: absolute; top: 4px; right: 4px; display: grid; place-items: center; width: 20px; height: 20px; border: 0; border-radius: 50%; background: rgba(15, 23, 42, .72); color: #fff; font-size: 12px; line-height: 1; }
        .photo-remove:hover { background: #b91c1c; }
        .photo-add { display: grid; place-items: center; width: 84px; height: 74px; border: 1px dashed var(--line); border-radius: 9px; background: #f8fcff; color: var(--blue-dark); font-size: 22px; font-weight: 700; }
        .photo-add:hover { border-color: var(--blue); background: #eaf8ff; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 9px; margin-top: 20px; }
        #packageModal { padding: 24px; background: rgba(15, 35, 55, .58); backdrop-filter: blur(5px); }
        #packageModal .package-modal-card { display: flex; width: min(820px, 100%); max-height: min(92vh, 900px); flex-direction: column; overflow: hidden; padding: 0; border: 1px solid rgba(226, 237, 245, .9); border-radius: 18px; box-shadow: 0 32px 90px rgba(15, 35, 55, .28); }
        #packageModal .modal-heading { flex: 0 0 auto; align-items: flex-start; margin: 0; padding: 24px 28px 20px; border-bottom: 1px solid var(--line); background: linear-gradient(135deg, #f0faff, #fff 72%); }
        #packageModal .package-modal-title { display: grid; gap: 6px; }
        #packageModal .modal-heading h2 { color: var(--navy); font-size: 22px; letter-spacing: -.025em; }
        #packageModal .package-modal-title p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.5; }
        #packageModal .close { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border: 1px solid var(--line); border-radius: 10px; background: #fff; font-size: 21px; transition: background .18s ease, color .18s ease, border-color .18s ease; }
        #packageModal .close:hover { border-color: #c8deeb; background: #f2faff; color: var(--navy); }
        #packageModal .form-grid { min-height: 0; overflow-y: auto; padding: 24px 28px; gap: 18px 20px; }
        #packageModal .field { align-content: start; gap: 8px; }
        #packageModal .field label { color: #344b61; font-size: 13px; }
        #packageModal .field input, #packageModal .field select, #packageModal .field textarea { min-height: 42px; border-color: #d9e5ed; border-radius: 9px; background-color: #fbfdff; color: var(--text); transition: border-color .18s ease, box-shadow .18s ease, background .18s ease; }
        #packageModal .field input:hover, #packageModal .field select:hover, #packageModal .field textarea:hover { border-color: #b9d3e2; }
        #packageModal .field input:focus, #packageModal .field select:focus, #packageModal .field textarea:focus { border-color: var(--blue); background: #fff; box-shadow: 0 0 0 3px rgba(14, 165, 233, .13); }
        #packageModal .field select[multiple] { min-height: 132px; padding: 8px; background-image: none; }
        #packageModal .visibility-duration { display: grid; grid-template-columns: minmax(0, 1fr) 132px; gap: 8px; }
        #packageModal .field small { color: #7b8da0; font-size: 11px; line-height: 1.5; }
        #packageModal .destination-preview { margin-top: 2px; }
        #packageModal .price-tier-list { gap: 10px; }
        #packageModal .price-tier { padding: 12px; border: 1px solid #e3edf3; border-radius: 11px; background: #f8fbfd; }
        #packageModal .price-tier label { color: #52677b; }
        #packageModal .price-tier input { background: #fff; }
        #packageModal .modal-footer { flex: 0 0 auto; margin: 0; padding: 16px 28px 22px; border-top: 1px solid var(--line); background: #fff; }
        #packageModal .modal-footer .btn { min-width: 112px; min-height: 42px; border-radius: 9px; }
        #packageModal .modal-footer .btn.primary { box-shadow: 0 5px 12px rgba(14, 165, 233, .18); }
        @media (max-width: 560px) {
            #packageModal { align-items: flex-end; padding: 10px; }
            #packageModal .package-modal-card { max-height: 94vh; border-radius: 16px; }
            #packageModal .modal-heading { padding: 19px 18px 16px; }
            #packageModal .modal-heading h2 { font-size: 19px; }
            #packageModal .form-grid { grid-template-columns: 1fr; gap: 16px; padding: 18px; }
            #packageModal .field.full { grid-column: auto; }
            #packageModal .visibility-duration { grid-template-columns: minmax(0, 1fr) 112px; }
            #packageModal .modal-footer { padding: 14px 18px 18px; }
        }
        .dialog-card { width: min(380px, 100%); text-align: left; }
        .dialog-message { margin: 0; font-size: 14px; line-height: 1.6; color: var(--text); }
        .driver-profile-card { width: min(820px, 100%); }
        .driver-profile-head { display: flex; align-items: center; gap: 14px; padding-bottom: 18px; border-bottom: 1px solid var(--line); }
        .driver-profile-avatar { display: grid; width: 58px; height: 58px; place-items: center; flex: 0 0 auto; border-radius: 50%; background: #dff5ff; color: var(--blue-dark); font-size: 18px; font-weight: 800; }
        .driver-profile-head h3 { margin: 0 0 5px; font-size: 21px; }
        .driver-profile-head p { margin: 0; color: var(--muted); font-size: 13px; }
        .driver-profile-status { margin-left: auto; }
        .driver-profile-section { margin-top: 20px; }
        .driver-profile-section h3 { margin: 0 0 11px; font-size: 14px; }
        .driver-profile-details { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        .driver-profile-detail { padding: 11px 12px; border-radius: 8px; background: #f7fcfe; }
        .driver-profile-detail span, .driver-profile-detail strong { display: block; }
        .driver-profile-detail span { margin-bottom: 4px; color: var(--muted); font-size: 11px; }
        .driver-profile-detail strong { overflow-wrap: anywhere; font-size: 13px; }
        .driver-file-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .driver-file-card { overflow: hidden; border: 1px solid var(--line); border-radius: 9px; background: #fff; }
        .driver-file-preview { display: grid; height: 155px; place-items: center; overflow: hidden; background: #f1f8fb; color: var(--muted); font-size: 12px; }
        .driver-file-preview img { width: 100%; height: 100%; object-fit: cover; }
        .driver-file-preview iframe { width: 100%; height: 100%; border: 0; background: #fff; }
        .driver-file-info { padding: 10px; }
        .driver-file-info strong { display: block; margin-bottom: 7px; font-size: 12px; }
        .driver-file-info a { color: var(--blue-dark); font-size: 11px; font-weight: 700; }
        .driver-review-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--line); }
        .driver-review-button { min-height: 36px; padding: 0 12px; border: 1px solid; border-radius: 8px; font-size: 12px; font-weight: 800; }
        .driver-review-button.approve { border-color: #86efac; background: #f0fdf4; color: var(--green); }
        .driver-review-button.approve:hover { background: #dcfce7; }
        .driver-review-button.deny { border-color: #fecaca; background: #fff7f7; color: #b91c1c; }
        .driver-review-button.deny:hover { background: #fee2e2; }
        .itinerary-list { display: grid; gap: 10px; }
        .itinerary-day { display: grid; grid-template-columns: 70px 1fr auto; gap: 8px; align-items: end; padding: 10px; border: 1px solid var(--line); border-radius: 9px; background: #f8fcff; }
        .itinerary-day label { display: grid; gap: 5px; color: var(--muted); font-size: 11px; font-weight: 700; }
        .itinerary-day input { width: 100%; padding: 9px 10px; border: 1px solid var(--line); border-radius: 7px; }
        .itinerary-inclusions { grid-column: 1 / -1; display: grid; gap: 7px; padding-top: 10px; border-top: 1px solid var(--line); }
        .itinerary-inclusion { display: grid; grid-template-columns: 140px minmax(120px, 1fr) auto auto; gap: 7px; align-items: center; }
        .itinerary-inclusion select, .itinerary-inclusion input[type="text"] { min-width: 0; width: 100%; padding: 8px 9px; border: 1px solid var(--line); border-radius: 7px; background: #fff; }
        .itinerary-inclusion .inclusion-included { display: flex; align-items: center; gap: 5px; white-space: nowrap; }
        .itinerary-inclusion .inclusion-included input { width: 16px; height: 16px; padding: 0; accent-color: var(--blue); }
        .add-itinerary-inclusion { display: inline-flex; justify-self: start; align-items: center; gap: 8px; min-height: 36px; padding: 0 12px 0 8px; border: 1px solid #c9dce8; border-radius: 8px; background: #fff; color: #31566f; font-size: 11px; font-weight: 750; transition: border-color 160ms ease, background 160ms ease, color 160ms ease, box-shadow 160ms ease; }
        .add-itinerary-inclusion:hover { border-color: #74b9d8; background: #f0f9fd; color: #126b91; box-shadow: 0 3px 9px rgba(16, 91, 126, .08); }
        .add-itinerary-inclusion:focus-visible { outline: 3px solid rgba(14, 165, 233, .25); outline-offset: 2px; }
        .add-itinerary-inclusion-icon { display: grid; width: 22px; height: 22px; place-items: center; border-radius: 6px; background: #e9f6fb; color: #0878a9; font-size: 17px; font-weight: 500; line-height: 1; }
        .departure-toggle { display: flex !important; grid-template-columns: none !important; align-items: center; gap: 10px; padding: 12px; border: 1px solid #bae6fd; border-radius: 9px; background: #f0faff; color: var(--blue-dark) !important; cursor: pointer; }
        .departure-toggle input { width: 18px; height: 18px; margin: 0; accent-color: var(--blue); }
        .departure-toggle strong { display: block; font-size: 13px; }
        .departure-toggle small { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; font-weight: 400; }
        @media (max-width: 620px) { .itinerary-day, .itinerary-inclusion { grid-template-columns: 1fr; } .itinerary-inclusion .inclusion-included { padding: 4px 0; } }
        @media (max-width: 620px) { .driver-profile-details, .driver-file-grid { grid-template-columns: 1fr; } .driver-profile-status { margin-left: 0; } .driver-profile-head { align-items: flex-start; flex-wrap: wrap; } }
        .admin-layout { display: block; }
        .sidebar { position: fixed; inset: 0 auto 0 0; z-index: 20; width: 270px; height: 100vh; min-height: 100vh; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: none; -ms-overflow-style: none; transform: translateX(-105%); transition: transform 220ms ease; box-shadow: 18px 0 40px rgba(2, 6, 23, .16); }
        .sidebar::-webkit-scrollbar { display: none; }
        .sidebar.open { transform: translateX(0); }
        .drawer-backdrop { position: fixed; inset: 0; z-index: 10; display: none; background: rgba(16, 42, 67, .38); }
        .drawer-backdrop.open { display: block; }
        .menu-button { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; margin-right: 10px; border: 1px solid var(--line); border-radius: 9px; background: #fff; color: var(--navy); font-size: 20px; }
        .heading-group { display: flex; align-items: center; }
        @media (max-width: 1050px) { .stats { grid-template-columns: repeat(2, 1fr); } .place-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 720px) { .sidebar { padding: 14px; } .admin-user { display: block; } .topbar { align-items: flex-start; flex-direction: column; } .top-actions { width: 100%; justify-content: space-between; } .stats { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 460px) { .content { padding: 20px 14px; } .stats, .place-grid, .form-grid { grid-template-columns: 1fr; } .field.full { grid-column: auto; } .panel { padding: 15px; } }
        .top-rating-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid rgba(14, 165, 233, 0.16);
            border-radius: 999px;
            background: linear-gradient(180deg, #ffffff, #f8fdff);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.08);
            color: var(--text);
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }
        .top-rating-stars {
            color: #f59e0b;
            letter-spacing: 1px;
            font-size: 13px;
        }
        .top-rating-score {
            color: var(--blue-dark);
        }
        .content-only > :not(.topbar):not(#packages):not(#places):not(#hotels) { display: none; }
        .content-only .topbar { margin-bottom: 22px; }
        .vehicle-manager-only > :not(.topbar):not(#company-vehicles) { display: none; }
        .vehicle-manager-only .topbar { margin-bottom: 22px; }
        .content:not(.content-only) > #packages,
        .content:not(.content-only) > #places,
        .content:not(.content-only) > #hotels { display: none; }
        .content:not(.content-only):not(.vehicle-manager-only) > #company-vehicles { display: none; }
        .message-user-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:14px; }
        .message-preview { display:-webkit-box; margin:12px 0; overflow:hidden; color:var(--muted); font-size:12px; line-height:1.5; -webkit-box-orient:vertical; -webkit-line-clamp:2; }
        .message-meta { display:flex; align-items:center; justify-content:space-between; gap:8px; color:var(--muted); font-size:11px; }
        .unread-badge { padding:4px 7px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:10px; font-weight:800; }
        .recent-activity { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; margin-bottom:20px; }
        .recent-activity-item { display:block; min-width:0; padding:12px; border:1px solid var(--line); border-radius:9px; background:#fafdff; }
        .recent-activity-item:hover { border-color:#b6d7e8; background:#f1faff; }
        .recent-activity-item header { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .recent-activity-item strong { overflow:hidden; font-size:12px; text-overflow:ellipsis; white-space:nowrap; }
        .recent-activity-item time { color:var(--muted); font-size:10px; white-space:nowrap; }
        .recent-activity-item p { margin:7px 0 0; color:var(--muted); font-size:11px; line-height:1.4; }
        .activity-type { display:inline-block; margin-top:8px; padding:3px 6px; border-radius:999px; background:#e0f5ff; color:var(--blue-dark); font-size:9px; font-weight:800; text-transform:uppercase; }
        .activity-type.message { background:#fef3c7; color:#92400e; }
        .booking-customer { display:flex; align-items:center; gap:10px; min-width:190px; }
        .booking-avatar { display:grid; place-items:center; width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, #dff5ff, #bfe8ff); color:var(--blue-dark); font-size:12px; font-weight:800; }
        .booking-customer strong, .booking-customer span { display:block; }
        .booking-customer span { margin-top:3px; color:var(--muted); font-size:11px; }
        .booking-package { font-weight:700; }
        .booking-proof { display:inline-flex; align-items:center; gap:7px; min-height:34px; padding:0 12px; border:1px solid rgba(14, 165, 233, 0.2); border-radius:8px; background:#f0faff; color:var(--blue-dark); font-size:12px; font-weight:700; }
        .booking-proof:hover { border-color:var(--blue); background:#e0f5ff; }
        .booking-proof.empty { border-style:dashed; background:#fff; color:var(--muted); }
        .booking-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .booking-pill { display:inline-flex; align-items:center; justify-content:center; min-height:32px; padding:0 10px; border-radius:7px; font-size:11px; font-weight:800; border:1px solid transparent; }
        .booking-pill.primary { background:var(--blue); border-color:var(--blue); color:#fff; }
        .booking-pill.primary:hover { background:var(--blue-dark); border-color:var(--blue-dark); }
        .booking-pill:disabled { border-color:var(--line); background:#f1f5f9; color:var(--muted); cursor:not-allowed; }
        .booking-pill.secondary { border-color: rgba(14,165,233,.18); background:#f0faff; color:var(--blue-dark); }
        .booking-pill.secondary:hover { border-color:var(--blue); background:#e0f5ff; }
        .booking-pill.danger { border-color: rgba(185,28,28,.18); background:#fff7f7; color:#b91c1c; }
        .booking-pill.danger:hover { background:#fee2e2; }
        .booking-pill.ghost { background:#fff; border-color:var(--line); color:var(--muted); }
        .refund-form-note { margin:0 0 14px; color:var(--muted); font-size:12px; line-height:1.5; }
        .booking-select { min-width:152px; min-height:32px; padding:0 10px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--text); font-size:12px; }
        .booking-status { display:inline-flex; align-items:center; justify-content:center; min-height:28px; padding:0 10px; border-radius:999px; font-size:11px; font-weight:800; text-transform:capitalize; }
        .booking-status.pending { background:#fff7ed; color:var(--orange); }
        .booking-status.paid, .booking-status.verified { background:#dcfce7; color:var(--green); }
        .booking-status.default { background:#edf2f7; color:var(--muted); }
        .receipt-meta { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:14px; }
        .receipt-meta-item { padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:#f8fbff; }
        .receipt-meta-item span { display:block; margin-bottom:5px; color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .receipt-meta-item strong { display:block; font-size:13px; }
        .receipt-image-box { display:flex; align-items:center; justify-content:center; min-height:250px; padding:12px; border:1px solid var(--line); border-radius:12px; background:linear-gradient(180deg, #f8fbff, #fff); }
        .receipt-image-box img { display:block; max-width:100%; max-height:360px; border-radius:8px; object-fit:contain; box-shadow:0 10px 22px rgba(15, 23, 42, .08); }
        .receipt-modal-card { width:min(560px,100%); text-align:left; }
        .menu-button { position:fixed; top:18px; left:18px; z-index:30; margin:0; box-shadow:0 8px 20px rgba(16, 42, 67, .12); }
        .menu-button[hidden] { display:none; }
        .heading-group { padding-left:52px; }
        @media (max-width:900px) { .message-user-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } .recent-activity { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        @media (max-width:620px) { .message-user-grid, .recent-activity { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <datalist id="mindanaoTransportLocations">
        <option value="General Santos International Airport (GES)"></option>
        <option value="Francisco Bangoy International Airport (DVO), Davao City"></option>
        <option value="Laguindingan Airport (CGY), Cagayan de Oro"></option>
        <option value="Sayak Airport (IAO), Siargao"></option>
        <option value="Surigao Airport (SUG), Surigao City"></option>
        <option value="Zamboanga International Airport (ZAM)"></option>
        <option value="Awang Airport (CBO), Cotabato City"></option>
        <option value="General Santos Port"></option>
        <option value="Sasa Port, Davao City"></option>
        <option value="Surigao City Port"></option>
        <option value="Dapa Port, Siargao"></option>
        <option value="Macabalan Port, Cagayan de Oro"></option>
        <option value="Nasipit Port, Agusan del Norte"></option>
        <option value="Balingoan Port, Misamis Oriental"></option>
        <option value="Benoni Port, Camiguin"></option>
        <option value="Zamboanga Port"></option>
    </datalist>
    <div class="drawer-backdrop" id="drawerBackdrop"></div>
    <div class="admin-layout">
        <aside class="sidebar">
            <div class="brand">
                <img src="../assets/logo/logo.png" alt="MTR logo">
                <div><strong>Mindanao Travel Hub</strong><span>Administration</span></div>
            </div>
            <div class="nav-label">Management</div>
            <nav aria-label="Admin navigation">
                <a class="nav-link<?= empty($adminContentOnly) && empty($adminVehicleManager) ? ' active' : '' ?>" href="admin.php">▦ <span>Dashboard</span></a>
                <a class="nav-link" href="<?= !empty($adminContentOnly) ? 'admin.php#messages' : '#messages' ?>">✉ <span>Messages</span></a>
                <a class="nav-link<?= !empty($adminContentOnly) ? ' active' : '' ?>" href="input.php#packages">▣ <span>Packages, Destinations &amp; Hotels</span></a>
                <a class="nav-link" href="<?= !empty($adminContentOnly) ? 'admin.php#drivers' : '#drivers' ?>">♧ <span>Drivers</span></a>
                <a class="nav-link" href="<?= !empty($adminContentOnly) ? 'admin.php#staff' : '#staff' ?>">♙ <span>Employees & drivers</span></a>
                <a class="nav-link<?= !empty($adminVehicleManager) ? ' active' : '' ?>" href="company_vehicles.php">▣ <span>Company vehicles</span></a>
                <a class="nav-link" href="<?= !empty($adminContentOnly) ? 'admin.php#bookings' : '#bookings' ?>">▤ <span>Bookings</span></a>
                <a class="nav-link" href="<?= !empty($adminContentOnly) ? 'admin.php#refunds' : '#refunds' ?>">↩ <span>Refunds</span></a>
                <a class="nav-link" href="<?= !empty($adminContentOnly) ? 'admin.php#payment-methods' : '#payment-methods' ?>">💳 <span>Payment Methods</span></a>
            </nav>
            <div class="nav-label">Account</div>
            <a class="nav-link" href="driverapplicant.php">♙ <span>Driver applicants</span></a>
            <button class="employee-nav-button" id="addEmployeeButton" type="button">＋ Add employee</button>
            <a class="nav-link" href="../user/logout.php">⇥ <span>Log out</span></a>
            <div class="admin-user"><strong><?= htmlspecialchars($adminAccount['full_name'], ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars($adminAccount['email'], ENT_QUOTES, 'UTF-8') ?></small></div>
        </aside>

        <main class="content<?= !empty($adminContentOnly) ? ' content-only' : (!empty($adminVehicleManager) ? ' vehicle-manager-only' : '') ?>" id="overview">
            <header class="topbar">
            <div class="heading-group"><button class="menu-button" id="menuButton" type="button" aria-label="Open navigation" aria-expanded="false">☰</button><div><h1><?= !empty($adminContentOnly) ? 'Travel packages and destinations' : (!empty($adminVehicleManager) ? 'Company vehicles' : 'Good morning, Admin') ?></h1><p><?= !empty($adminContentOnly) ? 'Manage the places and travel experiences offered to customers.' : (!empty($adminVehicleManager) ? 'Add company vehicles, photos, and driver assignments.' : 'Manage your Mindanao Travel Hub content and bookings.') ?></p></div></div>
                <div class="top-actions">
                    <div class="top-rating-pill" aria-label="Website rating">
                        <span class="top-rating-stars"><?= htmlspecialchars($averageReviewStars ?: '☆☆☆☆☆', ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="top-rating-score"><?= htmlspecialchars($averageReviewLabel ?: '0.0', ENT_QUOTES, 'UTF-8') ?>/5</span>
                    </div>
                    <div class="avatar">AD</div>
                </div>
            </header>

            <section class="stats" aria-label="Dashboard statistics">
                <div class="stat"><div class="stat-label">Active packages</div><div class="stat-value" id="packageCount">0</div><div class="stat-note">Ready to book</div></div>
                <div class="stat"><div class="stat-label">Total bookings</div><div class="stat-value" id="totalBookingCount">0</div><div class="stat-note">Booked plans</div></div>
                <div class="stat"><div class="stat-label">Destinations</div><div class="stat-value" id="placeCount">0</div><div class="stat-note">Across Mindanao</div></div>
                <div class="stat"><div class="stat-label">Total revenue</div><div class="stat-value" id="totalRevenueCount">₱0</div><div class="stat-note">Verified & Paid</div></div>
            </section>

            <section class="panel" id="recent-activity">
                <div class="panel-heading"><div><h2>Recent customer activity</h2><p>See the latest plans booked and support messages from customers.</p></div></div>
                <?php if ($recentActivity): ?>
                    <div class="recent-activity" aria-label="Recent customer activity">
                        <?php foreach ($recentActivity as $activity): ?>
                            <a class="recent-activity-item" href="<?= htmlspecialchars($activity['activity_link'], ENT_QUOTES, 'UTF-8') ?>">
                                <header><strong><?= htmlspecialchars($activity['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong><time datetime="<?= htmlspecialchars($activity['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M d, g:i A', strtotime($activity['created_at'])), ENT_QUOTES, 'UTF-8') ?></time></header>
                                <p><?= htmlspecialchars($activity['activity_label'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($activity['activity_detail'], ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="activity-type<?= $activity['activity_type'] === 'message' ? ' message' : '' ?>"><?= $activity['activity_type'] === 'message' ? 'Message' : ($activity['activity_link'] === '#custom-plan-approvals' ? 'Needs approval' : 'Booking & receipt') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="margin:0;color:var(--muted);font-size:13px;">No recent customer activity yet.</p>
                <?php endif; ?>
            </section>

            <section class="panel" id="packages">
                <div class="panel-heading">
                    <div><h2>Travel packages</h2><p>Edit package details, availability, and prices.</p></div>
                    <div class="toolbar"><input class="search" id="packageSearch" type="search" placeholder="Search packages..."><button class="btn primary" id="newPackageAlt">＋ New package</button></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Package</th><th>Destination</th><th>Price / person</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody id="packageRows">
                            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">No packages...</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel" id="places">
                <div class="panel-heading"><div><h2>Destinations and places</h2><p>Manage the destinations available for package planning.</p></div><button class="btn primary" id="newPlace" type="button">＋ Add place</button></div>
                <div class="place-grid" id="placeGrid">
                    <div style="grid-column: 1 / -1; text-align:center; color:var(--muted); padding:20px;">No destinations...</div>
                </div>
            </section>

            <section class="panel" id="hotels">
                <div class="panel-heading"><div><h2>Hotels</h2><p>Manage hotels available for accommodation.</p></div><button class="btn primary" id="newHotel" type="button">＋ Add hotel</button></div>
                <div class="hotel-grid" id="hotelRows">
                    <?php if (empty($hotels)): ?>
                    <div style="grid-column: 1 / -1; text-align:center;color:var(--muted);padding:20px;">No hotels added yet.</div>
                    <?php endif; ?>
                    <?php foreach ($hotels as $hotel): ?>
                    <article class="hotel-card" data-hotel data-id="<?= htmlspecialchars($hotel['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="hotel-image empty">No hotel image</div>
                        <div class="hotel-body"><h3><?= htmlspecialchars($hotel['name'], ENT_QUOTES, 'UTF-8') ?></h3><span class="hotel-location">⌖ <?= htmlspecialchars($hotel['location'], ENT_QUOTES, 'UTF-8') ?></span><div class="hotel-meta"><strong class="hotel-price">₱<?= number_format($hotel['price_per_night'], 2) ?> / night</strong><span class="hotel-rating"><?= $hotel['rating'] > 0 ? number_format($hotel['rating'], 1) . ' ★' : 'No rating' ?></span></div><p class="hotel-description"><?= htmlspecialchars($hotel['description'] ?: 'Comfortable accommodation for your Mindanao trip.', ENT_QUOTES, 'UTF-8') ?></p><div class="place-actions"><button class="btn edit-hotel" type="button">Edit</button><button class="btn danger delete-hotel" type="button">Remove</button></div></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="panel" id="drivers">
                <div class="panel-heading"><div><h2>Drivers</h2><p>See who is available, with a guest, or unavailable.</p></div><div class="toolbar"><select class="driver-filter" id="driverFilter" aria-label="Filter drivers by status"><option value="all">All drivers</option><option value="available">Available</option><option value="occupied">With guest</option><option value="unavailable">Unavailable</option></select></div></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Driver</th><th>Vehicle</th><th>Max pax</th><th>Status</th><th>Schedule</th><th>Vehicle assignment</th><th>Contact</th></tr></thead>
                        <tbody id="driverRows">
                            <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px;">Loading drivers...</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel" id="staff">
                <div class="panel-heading"><div><h2>Employees and drivers</h2><p>View staff accounts and filter by role.</p></div><div class="toolbar"><select class="driver-filter" id="staffFilter" aria-label="Filter staff by role"><option value="all">All staff</option><option value="employee">Employees</option><option value="driver">Drivers</option></select></div></div>
                <div class="table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Phone</th><th>Vehicle</th><th>Status</th></tr></thead><tbody id="staffRows"></tbody></table></div>
            </section>

            <section class="panel" id="company-vehicles">
                <div class="panel-heading"><div><h2><?= !empty($adminVehicleManager) ? 'Add company vehicle' : 'Company vehicles' ?></h2><p><?= !empty($adminVehicleManager) ? 'Add MTR-owned vehicles with one or more photos, then assign active vehicles to approved drivers.' : 'Review vehicles and manage their approved driver assignments.' ?></p></div></div>
                <?php if (!empty($adminVehicleManager)): ?>
                <form id="companyVehicleForm" class="form-grid" enctype="multipart/form-data" style="margin-bottom:18px">
                    <div class="field"><label for="companyVehicleUnit">Vehicle unit</label><input id="companyVehicleUnit" required placeholder="e.g. Toyota Hiace"></div>
                    <div class="field"><label for="companyVehicleColor">Color</label><input id="companyVehicleColor" required placeholder="e.g. White"></div>
                    <div class="field"><label for="companyVehiclePlate">Plate number</label><input id="companyVehiclePlate" required></div>
                    <div class="field"><label for="companyVehicleCapacity">Passenger capacity</label><input id="companyVehicleCapacity" type="number" min="1" max="100" required></div>
                    <div class="field"><label for="companyVehicleStatus">Status</label><select id="companyVehicleStatus"><option value="active">Active</option><option value="maintenance">Maintenance</option><option value="inactive">Inactive</option></select></div>
                    <div class="field"><label for="companyVehicleImages">Vehicle photos</label><input id="companyVehicleImages" type="file" accept="image/jpeg,image/png,image/webp" multiple required><small>Upload 1 to 10 JPG, PNG, or WEBP photos, up to 8 MB each.</small></div>
                    <div class="field" style="align-self:end"><button class="btn primary" type="submit">Add company vehicle</button></div>
                </form>
                <div class="company-vehicle-grid" id="companyVehicleCards"></div>
                <?php else: ?>
                <div class="table-wrap"><table><thead><tr><th>Vehicle</th><th>Photos</th><th>Plate</th><th>Capacity</th><th>Status</th><th>Assigned driver</th><th>Action</th></tr></thead><tbody id="companyVehicleRows"></tbody></table></div>
                <?php endif; ?>
            </section>

            <section class="panel employee-work-panel" id="custom-plan-approvals">
                <div class="panel-heading"><div><h2>Customized travel plans for approval</h2><p>Admin review for personalized itineraries before payment is allowed.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Custom plan</th><th>Travel date</th><th>Total</th><th>Approval</th><th>Action</th></tr></thead><tbody id="customBookingRows"></tbody></table></div>
            </section>

            <section class="panel employee-work-panel" id="extension-requests">
                <div class="panel-heading"><div><h2>Mid-trip extension requests</h2><p>Review guests who want to add days while already travelling.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Trip</th><th>Extra days</th><th>Request</th><th>Amount</th><th>Action</th></tr></thead><tbody id="extensionRows"></tbody></table></div>
            </section>

            <section class="panel employee-work-panel" id="cancellation-requests">
                <div class="panel-heading"><div><h2>Cancellation requests</h2><p>Review customer cancellation requests before processing full or partial refunds.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Plan details</th><th>Payment</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody id="cancellationRows"></tbody></table></div>
            </section>

            <section class="panel" id="refunds">
                <div class="panel-heading"><div><h2>Refunds</h2><p>Record approved refunds, attach proof of transfer, and email the receipt and reference to the customer.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Booking</th><th>Payment</th><th>Refund receipt</th><th>Status</th><th>Action</th></tr></thead><tbody id="refundRows"></tbody></table></div>
            </section>

            <section class="panel" id="bookings">
                <div class="panel-heading"><div><h2>Recent bookings & receipts</h2><p>Verify uploaded customer payment receipts and confirm traveler plans.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Package</th><th>Travel date</th><th>Total</th><th>Payment Status</th><th>Receipt Proof</th><th>Action</th></tr></thead><tbody id="bookingRows"></tbody></table></div>
            </section>

            <section class="panel employee-work-panel" id="trip-completions">
                <div class="panel-heading"><div><h2>Trip completion verification</h2><p>Review driver photos before marking completed trips.</p></div></div>
                <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Trip</th><th>Travel date</th><th>Driver</th><th>Proof</th><th>Action</th></tr></thead><tbody id="completionRows"></tbody></table></div>
            </section>

            <section class="panel" id="payment-methods">
                <div class="panel-heading">
                    <div><h2>Payment Methods & Accounts</h2><p>Only admin can add, edit, or remove GCash, Bank, and QR code details.</p></div>
                    <button class="btn primary" id="newPaymentMethodBtn" type="button">＋ Add payment method</button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Method</th><th>Bank / Provider</th><th>Account Name</th><th>Account Number</th><th>QR Code</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody id="paymentMethodRows"></tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div class="modal" id="companyVehicleViewModal" role="dialog" aria-modal="true" aria-labelledby="companyVehicleViewTitle">
        <div class="modal-card company-vehicle-view-card">
            <div class="modal-heading"><h2 id="companyVehicleViewTitle">Company vehicle</h2><button class="close" type="button" data-close-vehicle-view aria-label="Close vehicle details">×</button></div>
            <div id="companyVehicleViewBody"></div>
            <div class="modal-footer"><button class="btn" type="button" data-close-vehicle-view>Close</button></div>
        </div>
    </div>

    <div class="modal" id="packageModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <form class="modal-card package-modal-card" id="packageForm">
            <div class="modal-heading"><div class="package-modal-title"><h2 id="modalTitle">Add travel package</h2><p>Set up package details, availability, pricing, and itinerary.</p></div><button class="close" type="button" data-close aria-label="Close package form">×</button></div>
            <div class="form-grid">
                <div class="field full"><label for="packageName">Package name</label><input id="packageName" required placeholder="e.g. Dinagat Islands Hideaway"></div>
                <div class="field full"><label for="packageRegions">Pickup regions</label><select id="packageRegions" multiple required aria-describedby="packageRegionHelp"></select><small id="packageRegionHelp">Select one or more regions this package is available for.</small></div>
                <div class="field full"><label for="destination">Destinations</label><select id="destination" multiple required aria-describedby="destinationHelp"></select><small id="destinationHelp">Hold Ctrl or Command to select one or more destinations.</small><div class="destination-preview" id="packageDestinationPreview"></div></div>
                <div class="field full"><label for="packageHotel">Hotel</label><select id="packageHotel" required><option value="">Select a hotel</option></select></div>
                <div class="field full"><label>Pax price tiers</label><div class="price-tier-list" id="priceTierList"></div><button class="btn" id="addPriceTier" type="button">＋ Add pax tier</button></div>
                <div class="field"><label for="duration">Duration in days</label><input id="duration" type="number" min="1" required placeholder="3"></div>
                <div class="field"><label for="packageVisibilityAmount">Show to customers for</label><div class="visibility-duration"><input id="packageVisibilityAmount" type="number" min="1" max="5256000" step="1" placeholder="No expiry" aria-label="Expiration duration"><select id="packageVisibilityUnit" aria-label="Expiration time unit"><option value="minute">Minutes</option><option value="hour">Hours</option><option value="day" selected>Days</option></select></div><small>Leave blank to keep the package visible until you unpublish it.</small></div>
                <div class="field"><label for="status">Status</label><select id="status"><option value="published">Published</option><option value="draft">Draft</option></select></div>
                <div class="field full"><label for="description">Short description</label><textarea id="description" placeholder="Describe the package activities and inclusions."></textarea></div>
                <div class="field full"><label>Day-by-day activities</label><div class="itinerary-list" id="itineraryList"></div><button class="btn" id="addItineraryDay" type="button">＋ Add day</button></div>
            </div>
            <div class="modal-footer"><button class="btn" type="button" data-close>Cancel</button><button class="btn primary" type="submit">Save package</button></div>
        </form>
    </div>

    <div class="modal" id="destinationModal" role="dialog" aria-modal="true" aria-labelledby="destinationModalTitle">
        <form class="modal-card" id="destinationForm">
            <div class="modal-heading"><h2 id="destinationModalTitle">Add destination</h2><button class="close" type="button" data-destination-close>×</button></div>
            <div class="form-grid">
                <div class="field full"><label for="destinationName">Destination name</label><input id="destinationName" required placeholder="e.g. Dinagat Islands"></div>
                <div class="field"><label for="destinationLocation">Location</label><input id="destinationLocation" required placeholder="e.g. Basilisa, Dinagat Islands"></div>
                <div class="field"><label for="destinationRegion">Region</label><select id="destinationRegion" required><option value="">Select a region</option><option value="Zamboanga Peninsula">Zamboanga Peninsula</option><option value="Northern Mindanao">Northern Mindanao</option><option value="Davao Region">Davao Region</option><option value="SOCCSKSARGEN">SOCCSKSARGEN</option><option value="Caraga">Caraga</option><option value="BARMM">BARMM</option></select></div>
                <div class="field"><label for="destinationCategory">Category</label><select id="destinationCategory" required><option value="island">Islands & beaches</option><option value="nature">Waterfalls & nature</option><option value="culture">Culture & highlands</option></select></div>
                <div class="field"><label for="destinationPrice">Price(Per pax)</label><input id="destinationPrice" type="number" min="0" step="0.01" required placeholder="5000"></div>
                <div class="field full"><label for="destinationDescription">Description</label><textarea id="destinationDescription" placeholder="Describe this destination."></textarea></div>
                <div class="field full">
                    <label>Destination photos</label>
                    <div class="photo-grid" id="destinationPhotoGrid"></div>
                    <input id="destinationImages" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden>
                </div>
            </div>
            <div class="modal-footer"><button class="btn" type="button" data-destination-close>Cancel</button><button class="btn primary" type="submit">Save destination</button></div>
        </form>
    </div>

    

    <div class="modal" id="driverScheduleModal" role="dialog" aria-modal="true" aria-labelledby="driverScheduleTitle">
        <div class="modal-card">
            <div class="modal-heading"><h2 id="driverScheduleTitle">Driver schedule</h2><button class="close" type="button" data-driver-schedule-close>×</button></div>
            <div id="driverScheduleBody" class="schedule-modal-list"></div>
            <div class="modal-footer"><button class="btn" type="button" data-driver-schedule-close>Close</button></div>
        </div>
    </div>

    <div class="modal" id="driverProfileModal" role="dialog" aria-modal="true" aria-labelledby="driverProfileTitle">
        <div class="modal-card driver-profile-card">
            <div class="modal-heading"><h2 id="driverProfileTitle">Driver profile</h2><button class="close" type="button" data-driver-profile-close>×</button></div>
            <div id="driverProfileBody"></div>
            <div class="modal-footer"><button class="btn" type="button" data-driver-profile-close>Close</button></div>
        </div>
    </div>

    <div class="modal" id="employeeModal" role="dialog" aria-modal="true" aria-labelledby="employeeModalTitle">
        <form class="modal-card" id="employeeForm">
            <div class="modal-heading"><h2 id="employeeModalTitle">Add employee</h2><button class="close" type="button" data-employee-close>×</button></div>
            <p style="margin:0 0 18px;color:var(--muted);font-size:12px;line-height:1.5">Create a driver employee account. The employee must still submit documents and be approved before receiving assignments.</p>
            <div class="form-grid">
                <div class="field full"><label for="employeeName">Full name</label><input id="employeeName" required autocomplete="name"></div>
                <div class="field"><label for="employeeEmail">Email address</label><input id="employeeEmail" type="email" required autocomplete="email"></div>
                <div class="field"><label for="employeePhone">Phone number</label><input id="employeePhone" type="tel" autocomplete="tel"></div>
                <div class="field"><label for="employeePassword">Temporary password</label><input id="employeePassword" type="password" minlength="8" required autocomplete="new-password"></div>
                <div class="field"><label for="employeePasswordConfirmation">Confirm password</label><input id="employeePasswordConfirmation" type="password" minlength="8" required autocomplete="new-password"></div>
            </div>
            <div class="modal-footer"><button class="btn" type="button" data-employee-close>Cancel</button><button class="btn primary" type="submit">Create employee account</button></div>
        </form>
    </div>

    <div class="modal" id="hotelModal" role="dialog" aria-modal="true" aria-labelledby="hotelModalTitle">
        <form class="modal-card" id="hotelForm">
            <div class="modal-heading"><h2 id="hotelModalTitle">Add Hotel</h2><button class="close" type="button" data-hotel-close>×</button></div>
            <div class="form-grid">
                <div class="field full"><label for="hotelName">Hotel name</label><input id="hotelName" required placeholder="e.g. Siargao Beach Resort"></div>
                <div class="field"><label for="hotelLocation">Location</label><input id="hotelLocation" required placeholder="e.g. Siargao Island"></div>
                <div class="field"><label for="hotelRegion">Region</label><select id="hotelRegion" required><option value="">Select a region</option><option value="Zamboanga Peninsula">Zamboanga Peninsula</option><option value="Northern Mindanao">Northern Mindanao</option><option value="Davao Region">Davao Region</option><option value="SOCCSKSARGEN">SOCCSKSARGEN</option><option value="Caraga">Caraga</option><option value="BARMM">BARMM</option></select></div>
                <div class="field"><label for="hotelPrice">Price per night</label><input id="hotelPrice" type="number" min="0" step="0.01" required placeholder="2500"></div>
                <div class="field"><label for="hotelRating">Rating</label><input id="hotelRating" type="number" min="0" max="5" step="0.1" value="0" placeholder="4.5"></div>
                <div class="field"><label for="hotelStatus">Status</label><select id="hotelStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div class="field full"><label for="hotelDescription">Description</label><textarea id="hotelDescription" placeholder="Describe the hotel and its amenities."></textarea></div>
                <div class="field full"><label for="hotelAmenities">Amenities</label><textarea id="hotelAmenities" placeholder="e.g. WiFi, Pool, Restaurant, Spa"></textarea></div>
                <div class="field full">
                    <label>Hotel photos</label>
                    <div class="photo-grid" id="hotelPhotoGrid"></div>
                    <input id="hotelImages" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden>
                </div>
            </div>
            <div class="modal-footer"><button class="btn" type="button" data-hotel-close>Cancel</button><button class="btn primary" type="submit">Save hotel</button></div>
        </form>
    </div>

    <div class="modal" id="paymentMethodModal" role="dialog" aria-modal="true" aria-labelledby="pmModalTitle">
        <form class="modal-card" id="paymentMethodForm">
            <div class="modal-heading"><h2 id="pmModalTitle">Add Payment Method</h2><button class="close" type="button" data-pm-close>×</button></div>
            <input type="hidden" id="pmId">
            <div class="form-grid">
                <div class="field"><label for="pmMethodType">Method Type</label><select id="pmMethodType"><option value="gcash">GCash</option><option value="bank">Bank Transfer</option><option value="card">Card / Other</option></select></div>
                <div class="field"><label for="pmBankName">Bank / Provider Name</label><input id="pmBankName" placeholder="e.g. BDO Unibank, GCash, BPI"></div>
                <div class="field full"><label for="pmAccountName">Account Name</label><input id="pmAccountName" required placeholder="e.g. Mindanao Travel & Tours Admin"></div>
                <div class="field full"><label for="pmAccountNumber">Account / Mobile Number</label><input id="pmAccountNumber" required placeholder="e.g. 0917-555-0184 or 0012-3456-7890"></div>
                <div class="field full"><label for="pmStatus">Status</label><select id="pmStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div class="field full"><label for="pmInstructions">Instructions for Customer</label><textarea id="pmInstructions" placeholder="Instructions shown to customers during payment..."></textarea></div>
                <div class="field full">
                    <label for="pmQrCode">QR Code Image</label>
                    <input id="pmQrCode" type="file" accept="image/jpeg,image/png,image/webp">
                </div>
            </div>
            <div class="modal-footer"><button class="btn" type="button" data-pm-close>Cancel</button><button class="btn primary" type="submit">Save Payment Method</button></div>
        </form>
    </div>

    <div class="modal" id="receiptModal" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
        <div class="modal-card receipt-modal-card">
            <div class="modal-heading"><h2 id="receiptModalTitle">Customer Payment Receipt</h2><button class="close" type="button" data-receipt-close>×</button></div>
            <div id="receiptModalBody" style="margin:16px 0"></div>
            <div class="modal-footer" style="justify-content:center">
                <button class="btn" type="button" data-receipt-close>Close</button>
            </div>
        </div>
    </div>

    <div class="modal" id="refundModal" role="dialog" aria-modal="true" aria-labelledby="refundModalTitle">
        <form class="modal-card" id="refundForm" enctype="multipart/form-data">
            <div class="modal-heading"><h2 id="refundModalTitle">Send refund</h2><button class="close" type="button" data-refund-close>×</button></div>
            <p class="refund-form-note" id="refundOriginalAmount"></p>
            <p class="refund-form-note">Enter the bank or e-wallet refund reference and upload a clear JPG, PNG, or WEBP receipt (up to 8 MB). The receipt will be attached to the customer email. If automatic amount reading is unavailable, verify the screenshot amount manually before sending.</p>
            <input type="hidden" id="refundPaymentId">
            <div class="form-grid">
                <div class="field"><label for="refundAmount">Refund amount</label><input id="refundAmount" type="number" min="0.01" step="0.01" required></div>
                <div class="field"><label for="refundReference">Refund reference number</label><input id="refundReference" maxlength="120" required placeholder="Enter the transaction/reference number"></div>
                <div class="field full"><label for="refundProof">Refund receipt screenshot</label><input id="refundProof" type="file" accept="image/jpeg,image/png,image/webp" required></div>
            </div>
            <div class="modal-footer"><button class="btn" type="button" data-refund-close>Cancel</button><button class="btn primary" type="submit">Send refund</button></div>
        </form>
    </div>

    <div class="modal" id="dialogModal" role="alertdialog" aria-modal="true" aria-labelledby="dialogMessage">
        <div class="modal-card dialog-card">
            <p class="dialog-message" id="dialogMessage"></p>
            <div class="modal-footer">
                <button class="btn" id="dialogCancel" type="button">Cancel</button>
                <button class="btn primary" id="dialogConfirm" type="button">OK</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/live-refresh.js"></script>
    <script>
        (() => {
            const dialogModal = document.querySelector('#dialogModal');
            const dialogMessage = document.querySelector('#dialogMessage');
            const dialogCancel = document.querySelector('#dialogCancel');
            const dialogConfirm = document.querySelector('#dialogConfirm');
            let dialogResolve = null;

            const closeDialog = (result) => {
                dialogModal.classList.remove('open');
                if (dialogResolve) { dialogResolve(result); dialogResolve = null; }
            };
            dialogCancel.addEventListener('click', () => closeDialog(false));
            dialogConfirm.addEventListener('click', () => closeDialog(true));
            dialogModal.addEventListener('click', event => { if (event.target === dialogModal) closeDialog(false); });

            const showAlert = (message) => new Promise(resolve => {
                dialogResolve = () => resolve();
                dialogMessage.textContent = message;
                dialogCancel.hidden = true;
                dialogConfirm.textContent = 'OK';
                dialogModal.classList.add('open');
                dialogConfirm.focus();
            });

            const showConfirm = (message) => new Promise(resolve => {
                dialogResolve = resolve;
                dialogMessage.textContent = message;
                dialogCancel.hidden = false;
                dialogConfirm.textContent = 'Confirm';
                dialogModal.classList.add('open');
                dialogConfirm.focus();
            });

            const sidebar = document.querySelector('.sidebar');
            const menuButton = document.querySelector('#menuButton');
            const drawerBackdrop = document.querySelector('#drawerBackdrop');
            const closeDrawer = () => {
                sidebar.classList.remove('open');
                drawerBackdrop.classList.remove('open');
                menuButton.setAttribute('aria-expanded', 'false');
                menuButton.hidden = false;
            };
            menuButton.addEventListener('click', () => {
                sidebar.classList.add('open');
                drawerBackdrop.classList.add('open');
                menuButton.setAttribute('aria-expanded', 'true');
                menuButton.hidden = true;
            });
            drawerBackdrop.addEventListener('click', closeDrawer);
            sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', closeDrawer));
            window.addEventListener('scroll', () => {
                if (sidebar.classList.contains('open')) closeDrawer();
            }, { passive: true });

            const modal = document.querySelector('#packageModal');
            const form = document.querySelector('#packageForm');
            const rows = document.querySelector('#packageRows');
            const search = document.querySelector('#packageSearch');
            const destinationSelect = document.querySelector('#destination');
            const destinationPreview = document.querySelector('#packageDestinationPreview');
            const packageRegionSelect = document.querySelector('#packageRegions');
            const packageHotel = document.querySelector('#packageHotel');
            const priceTierList = document.querySelector('#priceTierList');
            const renderPackageHotelOptions = () => {
                const selectedRegions = Array.from(packageRegionSelect.selectedOptions).map(option => option.value.trim());
                const matchingHotels = !selectedRegions.length
                    ? (resultHotelCache || [])
                    : (resultHotelCache || []).filter(hotel => selectedRegions.includes(String(hotel.region || '').trim()));
                const currentHotel = packageHotel.value;
                packageHotel.innerHTML = '<option value="">Select a hotel</option>';
                if (!matchingHotels.length) {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = selectedRegions.length ? 'No hotels found for the selected region(s)' : 'Select a region first';
                    option.disabled = true;
                    option.selected = true;
                    packageHotel.appendChild(option);
                    return;
                }
                matchingHotels.forEach(hotel => {
                    const option = document.createElement('option');
                    option.value = hotel.id;
                    option.textContent = `${hotel.name} · ${hotel.location}`;
                    packageHotel.appendChild(option);
                });
                if (currentHotel && matchingHotels.some(hotel => String(hotel.id) === String(currentHotel))) {
                    packageHotel.value = String(currentHotel);
                } else {
                    packageHotel.value = '';
                }
            };
            const renderPackageDestinationOptions = (selectedDestinationIds = []) => {
                const selectedRegions = Array.from(packageRegionSelect.selectedOptions).map(option => option.value.trim());
                destinationSelect.innerHTML = '';
                const destinationsInRegions = !selectedRegions.length
                    ? (resultDestinationCache || [])
                    : (resultDestinationCache || []).filter(destination => selectedRegions.includes(String(destination.region || '').trim()));
                if (!destinationsInRegions.length) {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = selectedRegions.length ? 'No destinations found for the selected region(s)' : 'Select a region first';
                    option.disabled = true;
                    option.selected = true;
                    destinationSelect.appendChild(option);
                    renderDestinationPreview();
                    return;
                }
                destinationsInRegions.forEach(destination => {
                    const option = document.createElement('option');
                    option.value = destination.id;
                    option.textContent = destination.name;
                    option.selected = selectedDestinationIds.includes(String(destination.id));
                    destinationSelect.appendChild(option);
                });
                renderDestinationPreview();
            };
            const placeGrid = document.querySelector('#placeGrid');
            const itineraryList = document.querySelector('#itineraryList');
            let resultDestinationCache = [];
            let resultHotelCache = [];
            let resultDriverCache = [];
            let resultVehicleCache = [];
            const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[character]);
            const driverFileUrl = path => path ? `../${path}` : '';
            const receiptFileUrl = path => {
                const value = String(path || '').trim();
                if (!value) return '';
                if (/^(?:https?:|data:|\/)/i.test(value)) return value;
                return `../${value.replace(/^(?:\.\.\/)+/, '')}`;
            };
            const driverInitials = name => String(name || '?').split(/\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase();
            const apiRequest = async (payload) => {
                const response = await fetch('../api/admin-content.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Unable to save this item.');
                return result;
            };

            const renderDestinationPreview = () => {
                destinationPreview.innerHTML = '';
                Array.from(destinationSelect.selectedOptions).forEach(option => {
                    const destination = resultDestinationCache.find(item => String(item.id) === option.value);
                    if (!destination) return;
                    const imagePath = destination.image_paths[0] || '';
                    const preview = document.createElement('div');
                    preview.className = 'destination-preview-item';
                    preview.innerHTML = `<div class="destination-preview-image" style="background-image:url('../${imagePath}')"></div><span>${destination.name}</span>`;
                    destinationPreview.appendChild(preview);
                });
            };

            const addPriceTier = (minPax = '', maxPax = '', price = '') => {
                const tier = document.createElement('div');
                tier.className = 'price-tier';
                tier.innerHTML = `<label>From pax<input class="tier-min" type="number" min="1" max="255" value="${minPax}" required></label><label>To pax<input class="tier-max" type="number" min="1" max="255" value="${maxPax}" required></label><label>Price / pax<input class="tier-price" type="number" min="0" step="0.01" value="${price}" required></label><button class="remove-tier" type="button" aria-label="Remove pax price tier">×</button>`;
                priceTierList.appendChild(tier);
            };

            const resetPriceTiers = () => {
                priceTierList.innerHTML = '';
                addPriceTier(1, 1, 900);
                addPriceTier(2, 2, 800);
                addPriceTier(3, 3, 600);
                addPriceTier(4, 5, 500);
            };
            const itinerarySpotOptions = (selectedSpot = '') => {
                const selectedDestinations = Array.from(destinationSelect.selectedOptions);
                const destinationOptions = selectedDestinations.map(option => `<option value="${escapeHtml(option.textContent)}"${option.textContent === selectedSpot ? ' selected' : ''}>${escapeHtml(option.textContent)}</option>`).join('');
                const previousOption = selectedSpot && !selectedDestinations.some(option => option.textContent === selectedSpot)
                    ? `<option value="${escapeHtml(selectedSpot)}" selected>${escapeHtml(selectedSpot)} (previously selected)</option>`
                    : '';
                return `<option value="">Select a spot</option>${previousOption}${destinationOptions}`;
            };
            const addItineraryInclusion = (day, inclusion = {}) => {
                const item = document.createElement('div');
                item.className = 'itinerary-inclusion';
                const categories = [
                    ['breakfast', 'Breakfast'], ['lunch', 'Lunch'], ['dinner', 'Dinner'],
                    ['activity', 'Activity'], ['entrance_fee', 'Entrance fee'],
                    ['transport', 'Transportation'], ['other', 'Other']
                ];
                const selectedCategory = inclusion.category || 'activity';
                const categoryOptions = categories.map(([value, label]) =>
                    `<option value="${value}"${selectedCategory === value ? ' selected' : ''}>${label}</option>`
                ).join('');
                item.innerHTML = `<select class="inclusion-category" aria-label="Package item type">${categoryOptions}</select><input class="inclusion-name" type="text" maxlength="180" value="${escapeHtml(inclusion.name || '')}" placeholder="e.g. Lunch at the island resort" required><label class="inclusion-included"><input class="inclusion-status" type="checkbox"${inclusion.is_included === false || inclusion.is_included === 0 ? '' : ' checked'}> Included</label><button class="remove-tier remove-itinerary-inclusion" type="button" aria-label="Remove package item">×</button>`;
                day.querySelector('.itinerary-inclusions').appendChild(item);
            };
            const addItineraryDay = (dayNumber = itineraryList.children.length + 1, title = '', activity = '', inclusions = []) => {
                const day = document.createElement('div');
                day.className = 'itinerary-day';
                day.dataset.activity = activity;
                day.innerHTML = `<label>Day<input class="itinerary-number" type="number" min="1" max="365" value="${dayNumber}" required></label><label>Spot<select class="itinerary-title" required>${itinerarySpotOptions(title)}</select></label><button class="remove-tier remove-itinerary-day" type="button" aria-label="Remove itinerary day">×</button><div class="itinerary-inclusions"></div><button class="add-itinerary-inclusion" type="button"><span class="add-itinerary-inclusion-icon" aria-hidden="true">+</span><span>Add meal, activity, or item</span></button>`;
                itineraryList.appendChild(day);
                inclusions.forEach(inclusion => addItineraryInclusion(day, inclusion));
            };
            const resetItinerary = () => { itineraryList.innerHTML = ''; addItineraryDay(1); };
            const refreshItinerarySpots = () => itineraryList.querySelectorAll('.itinerary-day').forEach(day => {
                const spotSelect = day.querySelector('.itinerary-title');
                const selectedSpot = spotSelect.value;
                spotSelect.innerHTML = itinerarySpotOptions(selectedSpot);
            });

            const loadDestinations = async () => {
                const response = await fetch(`../api/admin-content.php?driver_scope=approved&_=${Date.now()}`, { cache: 'no-store' });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Unable to load destinations.');
                resultDestinationCache = result.destinations;
                resultHotelCache = result.hotels || [];
                packageHotel.innerHTML = '<option value="">Select a hotel</option>';
                const selectedRegions = Array.from(packageRegionSelect.selectedOptions).map(option => option.value.trim());
                const matchingHotels = !selectedRegions.length ? resultHotelCache : resultHotelCache.filter(hotel => selectedRegions.includes(String(hotel.region || '').trim()));
                matchingHotels.forEach(hotel => {
                    const option = document.createElement('option');
                    option.value = hotel.id;
                    option.textContent = `${hotel.name} · ${hotel.location}`;
                    packageHotel.appendChild(option);
                });
                destinationSelect.innerHTML = '';
                const destinationsInRegions = !selectedRegions.length ? (result.destinations || []) : (result.destinations || []).filter(destination => selectedRegions.includes(String(destination.region || '').trim()));
                destinationsInRegions.forEach(destination => {
                    const option = document.createElement('option');
                    option.value = destination.id;
                    option.textContent = destination.name;
                    destinationSelect.appendChild(option);
                });
                const regionOptions = Array.from(new Set((result.destinations || []).map(destination => String(destination.region || '').trim()).filter(Boolean))).sort();
                packageRegionSelect.innerHTML = '';
                regionOptions.forEach(region => {
                    const option = document.createElement('option');
                    option.value = region;
                    option.textContent = region;
                    packageRegionSelect.appendChild(option);
                });
                renderPackageHotelOptions();
                renderPackageDestinationOptions([]);
                refreshItinerarySpots();

                placeGrid.innerHTML = '';
                if (!result.destinations || !result.destinations.length) {
                    placeGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align:center; color:var(--muted); padding:20px;">No destinations added yet.</div>';
                } else {
                    result.destinations.forEach(destination => {
                        const place = document.createElement('article');
                        place.className = 'place';
                        place.dataset.destinationId = destination.id;
                        const imagePaths = destination.image_paths.length ? destination.image_paths : [''];
                        const images = imagePaths.map((imagePath, index) => `<div class="place-carousel-image${index === 0 ? ' active' : ''}" style="background-image:url('../${imagePath}')"></div>`).join('');
                        const dots = imagePaths.map((_, index) => `<button class="place-carousel-dot${index === 0 ? ' active' : ''}" type="button" data-carousel-index="${index}" aria-label="Show image ${index + 1}"></button>`).join('');
                        const shortDescription = (destination.description || 'Mindanao destination').trim().split(/\s+/).slice(0, 10).join(' ');
                        const location = destination.location || destination.region || 'Location not set';
                        place.innerHTML = `<div class="place-carousel" data-place-carousel data-current="0">${images}<button class="place-carousel-control previous" type="button" aria-label="Previous destination image">‹</button><button class="place-carousel-control next" type="button" aria-label="Next destination image">›</button><div class="place-carousel-dots">${dots}</div></div><div class="place-body"><strong>${escapeHtml(destination.name)}</strong><span>${escapeHtml(location)} · ${escapeHtml(destination.region || 'Mindanao')} · ${escapeHtml(destination.category || 'island')}</span><span>${escapeHtml(shortDescription)}</span><strong>₱ ${Number(destination.price).toLocaleString('en-PH')}</strong><div class="place-actions"><button class="btn edit-place" type="button">Edit</button><button class="btn danger delete-place" type="button">Remove</button></div></div>`;
                        placeGrid.appendChild(place);
                    });
                }
                document.querySelector('#placeCount').textContent = result.destinations.length;

                resultDriverCache = result.drivers || [];
                window.adminBookingsCache = result.bookings || [];
                const databaseDriverRows = document.querySelector('#driverRows');
                databaseDriverRows.innerHTML = '';
                if (!result.drivers || !result.drivers.length) {
                    databaseDriverRows.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px;">No drivers registered yet.</td></tr>';
                } else {
                    result.drivers.forEach(driver => {
                        const initials = driver.full_name.split(/\s+/).map(namePart => namePart[0]).join('').slice(0, 2).toUpperCase();
                        const assignedBooking = (result.bookings || []).find(booking => Number(booking.driver_id) === Number(driver.id) && !['cancelled', 'completed'].includes(String(booking.booking_status || '').toLowerCase()));
                        const isAssigned = Boolean(driver.assigned_booking_id || assignedBooking);
                        const status = isAssigned ? 'occupied' : (driver.verification_status === 'approved' && Number(driver.is_available) === 1 ? 'available' : 'unavailable');
                        const statusLabel = isAssigned ? 'With guest' : (status === 'available' ? 'Available' : 'Unavailable');
                        const driverPhone = String(driver.phone || '').replace(/\D/g, '');
                        const driverWhatsappNumber = driverPhone.startsWith('0') ? `63${driverPhone.slice(1)}` : driverPhone;
                        const guestLabel = driver.assigned_customer_name
                            ? `${driver.assigned_customer_name} · ${driver.assigned_package_name || 'Booked plan'}`
                            : (assignedBooking ? `${assignedBooking.customer_name} · ${assignedBooking.package_name || 'Booked plan'}` : 'No guest assigned');
                        const row = document.createElement('tr');
                        row.dataset.driver = '';
                        row.dataset.driverId = driver.id;
                        row.dataset.status = status;
                        const vehicleLabel = driver.company_vehicle_unit ? `${driver.company_vehicle_unit} · ${driver.company_vehicle_color}` : 'No company vehicle assigned';
                        const capacityLabel = driver.company_passenger_capacity || '—';
                        const unassignedVehicles = (result.vehicles || []).filter(vehicle => vehicle.status === 'active' && !vehicle.assigned_driver_id);
                        const assignmentControl = driver.company_vehicle_id
                            ? `<span>${escapeHtml(vehicleLabel)}</span>`
                            : `<select class="driver-vehicle-select" data-driver-id="${driver.id}" aria-label="Assign vehicle to ${escapeHtml(driver.full_name)}"><option value="">Assign a vehicle</option>${unassignedVehicles.map(vehicle => `<option value="${vehicle.id}">${escapeHtml(vehicle.vehicle_unit)} · ${escapeHtml(vehicle.plate_number)}</option>`).join('')}</select>`;
                        row.innerHTML = `<td><div class="driver-name"><div class="driver-avatar">${escapeHtml(initials)}</div><div><strong>${escapeHtml(driver.full_name)}</strong><span>${escapeHtml(driver.verification_status)}</span></div></div></td><td>${escapeHtml(vehicleLabel)}</td><td><strong>${escapeHtml(capacityLabel)}</strong>${capacityLabel !== '—' ? ' guests' : ''}</td><td><span class="driver-status ${status}">${escapeHtml(statusLabel)}</span></td><td><button class="driver-profile-button view-driver-schedule" type="button">View schedule</button></td><td>${assignmentControl}</td><td><div class="driver-actions"><button class="driver-profile-button view-driver-profile" type="button">View profile${driver.verification_status === 'pending' ? '<span class="approval-dot" title="Needs approval" aria-label="Needs approval"></span>' : ''}</button><button class="icon-btn" type="button" title="Open WhatsApp with ${escapeHtml(driver.full_name)}"${driverWhatsappNumber ? ` onclick="window.location.href='whatsapp://send?phone=${driverWhatsappNumber}'` : ' disabled'}>☎</button></div></td>`;
                        databaseDriverRows.appendChild(row);
                    });
                }

                const staffRows = document.querySelector('#staffRows');
                const staff = result.staff || [];
                staffRows.innerHTML = staff.length ? staff.map(member => {
                    const isDriver = member.role === 'driver';
                    const vehicle = isDriver ? (member.company_vehicle_unit ? `${member.company_vehicle_unit} · ${member.company_plate_number || 'No plate'}` : 'No vehicle assigned') : '—';
                    const status = isDriver ? (member.verification_status || 'pending') : 'Active account';
                    return `<tr data-staff-role="${escapeHtml(member.role)}"><td><strong>${escapeHtml(member.full_name)}</strong></td><td>${isDriver ? 'Driver' : 'Employee'}</td><td>${escapeHtml(member.email || '—')}</td><td>${escapeHtml(member.phone || '—')}</td><td>${escapeHtml(vehicle)}</td><td>${escapeHtml(status)}</td></tr>`;
                }).join('') : '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">No employees or drivers found.</td></tr>';

                const companyVehicleRows = document.querySelector('#companyVehicleRows');
                const companyVehicleCards = document.querySelector('#companyVehicleCards');
                const companyVehicles = result.vehicles || [];
                resultVehicleCache = companyVehicles;
                if (companyVehicleRows) {
                    companyVehicleRows.innerHTML = companyVehicles.length ? companyVehicles.map(vehicle => {
                    const driverOptions = resultDriverCache.filter(driver => driver.verification_status === 'approved').map(driver => `<option value="${driver.id}"${Number(driver.company_vehicle_id) === Number(vehicle.id) ? ' selected' : ''}>${escapeHtml(driver.full_name)}</option>`).join('');
                    const photos = (vehicle.image_paths || []).map(path => `<a href="../${escapeHtml(path)}" target="_blank" rel="noopener noreferrer"><img src="../${escapeHtml(path)}" alt="${escapeHtml(vehicle.vehicle_unit)} photo"></a>`).join('');
                    return `<tr><td><strong>${escapeHtml(vehicle.vehicle_unit)}</strong><br><small>${escapeHtml(vehicle.vehicle_color)}</small></td><td><div class="vehicle-photo-strip">${photos || '<span class="guest-cell">No photos</span>'}</div></td><td>${escapeHtml(vehicle.plate_number)}</td><td>${escapeHtml(vehicle.passenger_capacity)} guests</td><td>${escapeHtml(vehicle.status)}</td><td><select class="company-vehicle-driver" data-vehicle-id="${vehicle.id}"><option value="">Unassigned</option>${driverOptions}</select></td><td><button class="btn danger delete-company-vehicle" type="button" data-vehicle-id="${vehicle.id}"${vehicle.assigned_driver_id ? ' disabled title="Unassign the vehicle before removing it"' : ''}>Remove</button></td></tr>`;
                    }).join('') : '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">No company vehicles added yet.</td></tr>';
                }
                if (companyVehicleCards) {
                    companyVehicleCards.innerHTML = companyVehicles.length ? companyVehicles.map(vehicle => {
                        const photos = vehicle.image_paths || [];
                        const cover = photos[0] ? `<img src="../${escapeHtml(photos[0])}" alt="${escapeHtml(vehicle.vehicle_unit)}">` : 'No vehicle photo';
                        const driverOptions = resultDriverCache.filter(driver => driver.verification_status === 'approved').map(driver => `<option value="${driver.id}"${Number(driver.company_vehicle_id) === Number(vehicle.id) ? ' selected' : ''}>${escapeHtml(driver.full_name)}</option>`).join('');
                        return `<article class="company-vehicle-card" data-company-vehicle-id="${Number(vehicle.id)}"><div class="company-vehicle-card-image">${cover}</div><div class="company-vehicle-card-body"><div><h3>${escapeHtml(vehicle.vehicle_unit)}</h3><p class="company-vehicle-card-subtitle">${escapeHtml(vehicle.vehicle_color)} · Plate ${escapeHtml(vehicle.plate_number)}</p></div><div class="company-vehicle-card-meta"><div><span>Capacity</span><strong>${escapeHtml(vehicle.passenger_capacity)} guests</strong></div><div><span>Status</span><strong>${escapeHtml(vehicle.status)}</strong></div><div><span>Photos</span><strong>${photos.length}</strong></div><div><span>Assigned driver</span><strong>${escapeHtml(vehicle.assigned_driver_name || 'Unassigned')}</strong></div></div><label class="field"><span>Assign driver</span><select class="company-vehicle-driver" data-vehicle-id="${Number(vehicle.id)}"><option value="">Unassigned</option>${driverOptions}</select></label><div class="company-vehicle-card-actions"><button class="btn view-company-vehicle" type="button" data-vehicle-id="${Number(vehicle.id)}">View</button><button class="btn danger delete-company-vehicle" type="button" data-vehicle-id="${Number(vehicle.id)}"${vehicle.assigned_driver_id ? ' disabled title="Unassign the vehicle before removing it"' : ''}>Remove</button></div></div></article>`;
                    }).join('') : '<div class="empty" style="grid-column:1/-1;text-align:center;color:var(--muted);padding:28px">No company vehicles added yet.</div>';
                }

                const bookingRows = document.querySelector('#bookingRows');
                const customBookingRows = document.querySelector('#customBookingRows');
                bookingRows.innerHTML = '';
                customBookingRows.innerHTML = '';
                const paymentsList = result.payments || [];

                const isCustomBooking = booking => {
                    const customTitle = (booking.custom_title || '').trim();
                    const customDetails = (booking.custom_details || '').trim();
                    const packageName = (booking.package_name || '').trim().toLowerCase();
                    return customTitle !== '' || customDetails !== '' || packageName === 'custom plan' || packageName === 'custom travel plan';
                };
                const bookingApprovalStatus = booking => String(booking.approval_status || '').trim().toLowerCase();

                const customPlanQueue = (result.bookings || []).filter(booking => isCustomBooking(booking) && ['pending', 'rejected'].includes(bookingApprovalStatus(booking)));
                const standardAndApprovedBookings = (result.bookings || []).filter(booking => !isCustomBooking(booking) || bookingApprovalStatus(booking) === 'approved');
                const getApprovalStatusLabel = booking => {
                    if (!isCustomBooking(booking)) {
                        return '<span class="booking-status paid">No approval needed</span>';
                    }
                    const status = bookingApprovalStatus(booking) || 'pending';
                    if (status === 'approved') return '<span class="booking-status paid">Approved</span>';
                    if (status === 'rejected') return '<span class="booking-status default">Rejected</span>';
                    return '<span class="booking-status pending">Pending</span>';
                };

                if (!customPlanQueue.length) {
                    customBookingRows.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No customized plans need approval.</td></tr>';
                } else {
                    customPlanQueue.forEach(booking => {
                        const row = document.createElement('tr');
                        row.dataset.booking = '';
                        row.dataset.bookingStatus = booking.booking_status;
                        row.dataset.bookingId = booking.id;
                        const customerInitials = (booking.customer_name || 'C').split(/\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase();
                        const approvalStatus = bookingApprovalStatus(booking) || 'pending';
                        const reviewControls = approvalStatus === 'pending'
                            ? `<button class="booking-pill primary review-booking-btn" type="button" data-review-status="approved">Approve plan</button><button class="booking-pill danger review-booking-btn" type="button" data-review-status="rejected">Deny plan</button>`
                            : `<span class="booking-status default">Plan ${escapeHtml(booking.approval_status || 'pending')}</span>`;
                        row.innerHTML = `
                            <td>
                                <div class="booking-customer">
                                    <div class="booking-avatar">${escapeHtml(customerInitials)}</div>
                                    <div>
                                        <strong>${escapeHtml(booking.customer_name)}</strong>
                                        <span>${booking.guest_count} travelers</span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="booking-package">${escapeHtml(booking.custom_title || booking.package_name || 'Custom travel plan')}</span><small class="booking-location">Pickup: ${escapeHtml(booking.pickup_location || 'Not provided')}<br>Departure: ${escapeHtml(booking.departure_location || 'Not provided')}<br>Stay: ${escapeHtml(booking.accommodation_name || booking.hotel_name || booking.accommodation_address || 'Not provided')}</small></td>
                            <td>${escapeHtml(booking.travel_date)}</td>
                            <td>₱${Number(booking.total_amount).toLocaleString('en-PH')}</td>
                            <td><span class="booking-status ${approvalStatus === 'rejected' ? 'default' : approvalStatus === 'approved' ? 'paid' : 'pending'}">${escapeHtml(approvalStatus)}</span></td>
                            <td>${reviewControls}</td>
                        `;
                        customBookingRows.appendChild(row);
                    });
                }

                const extensionRows = document.querySelector('#extensionRows');
                const extensionRequests = result.extension_requests || [];
                extensionRows.innerHTML = '';
                if (!extensionRequests.length) {
                    extensionRows.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No extension requests yet.</td></tr>';
                } else {
                    extensionRequests.forEach(extension => {
                        const row = document.createElement('tr');
                        const proofUrl = extension.payment_receipt ? receiptFileUrl(extension.payment_receipt) : '';
                        const amount = Number(extension.approved_amount || extension.requested_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        let action = `<span class="booking-status default">${escapeHtml(extension.status)}</span>`;
                        if (extension.status === 'pending') {
                            action = `<div class="booking-actions"><input class="extension-amount-input" type="number" min="0" step="0.01" value="${Number(extension.requested_amount || 0).toFixed(2)}" aria-label="Approved extension amount"><button class="booking-pill primary review-extension-btn" type="button" data-extension-id="${extension.id}" data-extension-status="approved">Approve</button><button class="booking-pill danger review-extension-btn" type="button" data-extension-id="${extension.id}" data-extension-status="rejected">Reject</button></div>`;
                        } else if (extension.status === 'approved' && extension.payment_id && extension.payment_status === 'pending') {
                            action = `<div class="booking-actions"><button class="booking-proof extension-proof-btn" type="button" data-extension-proof="${escapeHtml(proofUrl)}" data-extension-customer="${escapeHtml(extension.customer_name)}">View payment</button><button class="booking-pill primary verify-extension-payment-btn" type="button" data-payment-id="${extension.payment_id}">Verify payment</button><button class="booking-pill danger reject-extension-payment-btn" type="button" data-payment-id="${extension.payment_id}">Reject payment</button></div>`;
                        }
                        row.innerHTML = `<td><strong>${escapeHtml(extension.customer_name)}</strong></td><td>${escapeHtml(extension.package_name)}</td><td>${extension.extra_days} day(s)</td><td>${escapeHtml(extension.request_details || 'Additional itinerary days')}</td><td>₱${amount}</td><td>${action}</td>`;
                        extensionRows.appendChild(row);
                    });
                }

                const cancellationRows = document.querySelector('#cancellationRows');
                const cancellationRequests = result.cancellation_requests || [];
                cancellationRows.innerHTML = '';
                if (!cancellationRequests.length) {
                    cancellationRows.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No cancellation requests yet.</td></tr>';
                } else {
                    cancellationRequests.forEach(request => {
                        const paymentAmount = Number(request.paid_amount || request.requested_amount || 0);
                        const action = request.status === 'pending'
                            ? `<div class="booking-actions"><button class="booking-pill primary review-cancellation-btn" type="button" data-cancellation-id="${request.id}" data-cancellation-status="approved">Approve</button><button class="booking-pill danger review-cancellation-btn" type="button" data-cancellation-id="${request.id}" data-cancellation-status="rejected">Reject</button></div>`
                            : `<span class="booking-status ${request.status === 'approved' ? 'paid' : 'default'}">${escapeHtml(request.status)}</span>`;
                        const planDetails = `${escapeHtml(request.package_name)}<br><span class="guest-cell">Booking #${request.booking_id} · ${request.guest_count} guests · ${escapeHtml(request.travel_date)}</span>`;
                        const paymentDetails = `₱${paymentAmount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}<br><span class="guest-cell">${escapeHtml(request.method_type || 'Payment')}</span>`;
                        const row = document.createElement('tr');
                        row.innerHTML = `<td><strong>${escapeHtml(request.customer_name)}</strong><br><span class="guest-cell">${escapeHtml(request.email)}</span></td><td>${planDetails}</td><td>${paymentDetails}</td><td>${escapeHtml(request.reason || 'Cancellation requested')}</td><td><span class="booking-status ${request.status === 'pending' ? 'pending' : request.status === 'approved' ? 'paid' : 'default'}">${escapeHtml(request.status)}</span></td><td>${action}</td>`;
                        cancellationRows.appendChild(row);
                    });
                }

                const refundRows = document.querySelector('#refundRows');
                const refundRequests = cancellationRequests.filter(request =>
                    request.status === 'approved' || ['partially_refunded', 'refunded'].includes(request.refund_status)
                );
                refundRows.innerHTML = '';
                if (!refundRequests.length) {
                    refundRows.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No approved refunds to process yet.</td></tr>';
                } else {
                    refundRequests.forEach(request => {
                        const paymentAmount = Number(request.paid_amount || request.requested_amount || 0);
                        const isRefunded = ['partially_refunded', 'refunded'].includes(request.refund_status);
                        const refundDetails = isRefunded
                            ? `<strong>₱${Number(request.refunded_amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong><br><span class="guest-cell">Ref: ${escapeHtml(request.refund_reference || 'Not recorded')}</span><br><span class="guest-cell">Processed: ${request.refunded_at ? escapeHtml(new Date(request.refunded_at).toLocaleString()) : 'Date not recorded'}</span>${request.refund_proof ? `<br><button class="booking-proof view-refund-proof-btn" type="button" data-refund-proof="${escapeHtml(receiptFileUrl(request.refund_proof))}" data-refund-customer="${escapeHtml(request.customer_name)}" data-refund-reference="${escapeHtml(request.refund_reference || 'N/A')}">View receipt</button>` : ''}`
                            : '<span class="guest-cell">Not processed</span>';
                        const refundStatus = request.refund_status === 'refunded'
                            ? '<span class="booking-status paid">Refunded</span>'
                            : request.refund_status === 'partially_refunded'
                                ? '<span class="booking-status paid">Partially refunded</span>'
                                : '<span class="booking-status pending">Awaiting refund</span>';
                        const action = isRefunded
                            ? '<span class="guest-cell">Completed</span>'
                            : request.payment_id
                                ? `<button class="booking-pill danger refund-btn" type="button" data-refund-payment-id="${request.payment_id}" data-refund-amount="${paymentAmount.toFixed(2)}" data-refund-customer="${escapeHtml(request.customer_name)}">Record refund</button>`
                                : '<span class="guest-cell">No verified payment found</span>';
                        const row = document.createElement('tr');
                        row.innerHTML = `<td><strong>${escapeHtml(request.customer_name)}</strong><br><span class="guest-cell">${escapeHtml(request.email)}</span></td><td>${escapeHtml(request.package_name)}<br><span class="guest-cell">Booking #${request.booking_id} · ${escapeHtml(request.travel_date)}</span></td><td>₱${paymentAmount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}<br><span class="guest-cell">${escapeHtml(request.method_type || 'Payment')}</span></td><td>${refundDetails}</td><td>${refundStatus}</td><td>${action}</td>`;
                        refundRows.appendChild(row);
                    });
                }

                if (!standardAndApprovedBookings.length) {
                    bookingRows.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px;">No bookings found.</td></tr>';
                } else {
                    standardAndApprovedBookings.forEach(booking => {
                        const row = document.createElement('tr');
                        row.dataset.booking = '';
                        row.dataset.bookingStatus = booking.booking_status;
                        row.dataset.bookingId = booking.id;
                        const paymentClass = booking.payment_status === 'pending' ? 'payment-status pending-status' : 'payment-status';
                        const matchedPay = paymentsList.find(p => Number(p.booking_id) === Number(booking.id));
                        
                        const receiptBtn = (matchedPay && matchedPay.receipt_file)
                            ? `<button class="btn view-receipt-btn" type="button" data-receipt="${escapeHtml(receiptFileUrl(matchedPay.receipt_file))}" data-ref="${escapeHtml(matchedPay.reference_number || 'N/A')}" data-name="${escapeHtml(booking.customer_name)}">🖼 View Proof</button>`
                            : '<span class="muted" style="font-size:11px">No receipt</span>';

                        const reviewControls = isCustomBooking(booking)
                            ? (bookingApprovalStatus(booking) === 'pending'
                                ? `<button class="booking-pill primary review-booking-btn" type="button" data-review-status="approved">Approve plan</button><button class="booking-pill danger review-booking-btn" type="button" data-review-status="rejected">Deny plan</button>`
                                : `<span class="booking-status default">Plan ${escapeHtml(booking.approval_status || 'approved')}</span>`)
                            : '';
                        const paymentControls = booking.payment_status === 'pending' && matchedPay && (!isCustomBooking(booking) || booking.approval_status === 'approved')
                            ? `<div class="booking-actions"><button class="booking-pill primary verify-pay-btn" type="button" data-payment-id="${matchedPay.id}">Approve payment</button><button class="booking-pill danger reject-pay-btn" type="button" data-payment-id="${matchedPay.id}">Reject payment</button></div>`
                            : '';
                        const generatedReceiptButton = booking.payment_status === 'paid' || booking.payment_status === 'verified'
                            ? `<button class="booking-pill secondary booking-receipt-btn" type="button" data-booking-id="${booking.id}" data-booking-customer="${escapeHtml(booking.customer_name)}" data-booking-package="${escapeHtml(booking.package_name || 'Custom plan')}" data-booking-date="${escapeHtml(booking.travel_date)}" data-booking-guests="${booking.guest_count}" data-booking-total="₱${Number(booking.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}">Booking receipt</button>`
                            : '';
                        const actionBtn = `<div class="booking-actions">${reviewControls}${paymentControls ? paymentControls : ''}${generatedReceiptButton}</div>`;
                        const customerInitials = (booking.customer_name || 'C').split(/\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase();
                        const statusClass = booking.payment_status === 'paid' || booking.payment_status === 'verified' ? 'paid' : booking.payment_status === 'pending' ? 'pending' : 'default';

                        row.innerHTML = `
                            <td>
                                <div class="booking-customer">
                                    <div class="booking-avatar">${escapeHtml(customerInitials)}</div>
                                    <div>
                                        <strong>${escapeHtml(booking.customer_name)}</strong>
                                        <span>${booking.guest_count} travelers</span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="booking-package">${escapeHtml(booking.package_name || 'Custom plan')}</span><small class="booking-location">Pickup: ${escapeHtml(booking.pickup_location || 'Not provided')}<br>Departure: ${escapeHtml(booking.departure_location || 'Not provided')}<br>Stay: ${escapeHtml(booking.accommodation_name || booking.hotel_name || booking.accommodation_address || 'Not provided')}<br>Customer-selected vehicle: ${escapeHtml(booking.requested_vehicle_name || 'No preference')} · ${Number(booking.trip_duration_days) || 1} day(s)</small></td>
                            <td>${escapeHtml(booking.travel_date)}</td>
                            <td>₱${Number(booking.total_amount).toLocaleString('en-PH')}</td>
                            <td><span class="booking-status ${statusClass}">${escapeHtml(booking.payment_status)}</span></td>
                            <td>${matchedPay && matchedPay.receipt_file ? `<button class="booking-proof view-receipt-btn" type="button" data-receipt="${escapeHtml(receiptFileUrl(matchedPay.receipt_file))}" data-ref="${escapeHtml(matchedPay.reference_number || 'N/A')}" data-name="${escapeHtml(booking.customer_name)}">🖼 View proof</button>` : '<span class="booking-proof empty">No receipt</span>'}</td>
                            <td>${actionBtn}</td>
                        `;
                        bookingRows.appendChild(row);
                    });
                }

                const completionRows = document.querySelector('#completionRows');
                const completionQueue = (result.bookings || []).filter(booking => booking.completion_proof && !booking.completion_verified_at);
                completionRows.innerHTML = '';
                if (!completionQueue.length) {
                    completionRows.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No trip completions waiting for verification.</td></tr>';
                } else {
                    completionQueue.forEach(booking => {
                        const proofUrl = receiptFileUrl(booking.completion_proof);
                        const row = document.createElement('tr');
                        row.dataset.completionBookingId = booking.id;
                        row.innerHTML = `<td><strong>${escapeHtml(booking.customer_name)}</strong><br><span class="guest-cell">${booking.guest_count} travelers</span></td><td>${escapeHtml(booking.package_name || 'Custom plan')}</td><td>${escapeHtml(booking.travel_date)}</td><td>${escapeHtml(booking.driver_name || 'Assigned driver')}</td><td><button class="booking-proof completion-proof-btn" type="button" data-completion-proof="${escapeHtml(proofUrl)}" data-completion-customer="${escapeHtml(booking.customer_name)}">View photo</button></td><td class="booking-actions"><button class="booking-pill primary verify-completion-btn" type="button" data-booking-id="${booking.id}" data-completion-status="verified">Verify completed</button><button class="booking-pill danger verify-completion-btn" type="button" data-booking-id="${booking.id}" data-completion-status="rejected">Reject proof</button></td>`;
                        completionRows.appendChild(row);
                    });
                }

                if (document.querySelector('#totalBookingCount')) {
                    document.querySelector('#totalBookingCount').textContent = result.bookings ? result.bookings.length : 0;
                }
                if (document.querySelector('#totalRevenueCount')) {
                    const totalRev = (result.bookings || [])
                        .filter(b => b.payment_status === 'paid' || b.payment_status === 'verified' || b.booking_status === 'confirmed')
                        .reduce((sum, b) => sum + Number(b.total_amount || 0), 0);
                    document.querySelector('#totalRevenueCount').textContent = '₱' + totalRev.toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                }

                resultHotelCache = result.hotels || [];
                const hotelRows = document.querySelector('#hotelRows');
                if (hotelRows) {
                    hotelRows.innerHTML = '';
                    if (!resultHotelCache.length) {
                        hotelRows.innerHTML = '<div style="grid-column: 1 / -1; text-align:center;color:var(--muted);padding:20px;">No hotels added yet.</div>';
                    }
                    resultHotelCache.forEach(hotel => {
                        const card = document.createElement('article');
                        card.className = 'hotel-card';
                        card.dataset.hotel = '';
                        card.dataset.id = hotel.id;
                        const ratingLabel = Number(hotel.rating) > 0 ? `${Number(hotel.rating).toFixed(1)} ★` : 'N/A';
                        const imagePath = hotel.image_paths?.[0] || '';
                        const imageStyle = imagePath ? ` style="background-image:url('${escapeHtml(imagePath.startsWith('http') ? imagePath : `../${imagePath}`)}')"` : '';
                        const imageFallback = imagePath ? '' : 'No hotel image';
                        card.innerHTML = `<div class="hotel-image ${imagePath ? '' : 'empty'}"${imageStyle}>${imageFallback}</div><div class="hotel-body"><h3>${escapeHtml(hotel.name)}</h3><span class="hotel-location">⌖ ${escapeHtml(hotel.location)}</span><div class="hotel-meta"><strong class="hotel-price">₱${Number(hotel.price_per_night).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} / night</strong><span class="hotel-rating">${ratingLabel === 'N/A' ? 'No rating' : ratingLabel}</span></div><p class="hotel-description">${escapeHtml(hotel.description || 'Comfortable accommodation for your Mindanao trip.')}</p><div class="place-actions"><button class="btn edit-hotel" type="button">Edit</button><button class="btn danger delete-hotel" type="button">Remove</button></div></div>`;
                        hotelRows.appendChild(card);
                    });
                }

                const pmRows = document.querySelector('#paymentMethodRows');
                if (pmRows) {
                    pmRows.innerHTML = '';
                    if (!result.payment_methods || !result.payment_methods.length) {
                        pmRows.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px;">No payment methods added yet.</td></tr>';
                    } else {
                        (result.payment_methods || []).forEach(pm => {
                            const tr = document.createElement('tr');
                            tr.dataset.pmData = JSON.stringify(pm);
                            const qrImg = pm.qr_code_image ? `<img src="${pm.qr_code_image}" style="width:36px;height:36px;object-fit:contain;border-radius:4px;border:1px solid #cbd5e1">` : 'No QR';
                            tr.innerHTML = `<td><strong>${(pm.method_type || '').toUpperCase()}</strong></td><td>${pm.bank_name || 'N/A'}</td><td>${pm.account_name}</td><td><strong style="color:var(--accent-dark)">${pm.account_number}</strong></td><td>${qrImg}</td><td><span class="status ${pm.status === 'active' ? '' : 'draft'}">${pm.status}</span></td><td class="actions"><button class="icon-btn edit-pm" type="button" title="Edit payment method">✎</button><button class="icon-btn delete-pm" type="button" title="Delete payment method">×</button></td>`;
                            pmRows.appendChild(tr);
                        });
                    }
                }

                rows.innerHTML = '';
                if (!result.packages || !result.packages.length) {
                    rows.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">No travel packages added yet.</td></tr>';
                } else {
                    result.packages.forEach(packageItem => {
                        const row = document.createElement('tr');
                        row.dataset.package = '';
                        row.dataset.name = packageItem.name;
                        row.dataset.packageId = packageItem.id;
                            row.dataset.hotelId = packageItem.hotel_id || '';
                        row.dataset.durationDays = packageItem.duration_days;
                        row.dataset.visibilityMinutes = packageItem.visibility_minutes ?? '';
                        row.dataset.destinationIds = JSON.stringify(packageItem.destination_ids || []);
                        row.dataset.regionNames = JSON.stringify(packageItem.region_names || []);
                        row.dataset.priceTiers = JSON.stringify(packageItem.price_tiers || []);
                        row.dataset.description = packageItem.description || '';
                        row.dataset.itinerary = JSON.stringify(packageItem.itinerary || []);
                        const imageUrl = packageItem.image_url ? `../${packageItem.image_url}` : '';
                        const description = packageItem.description || 'Curated Mindanao travel experience';
                        row.innerHTML = `<td><div class="package-name"><div class="package-thumb" style="background-image:url('${imageUrl}')"></div><div><strong>${packageItem.name}</strong><span>${packageItem.duration_days} days · ${description}</span></div></div></td><td>${packageItem.destination_name || 'Custom plan'}</td><td class="price">₱ ${Number(packageItem.price_per_person).toLocaleString('en-PH')}</td><td><span class="status">${packageItem.status}</span></td><td class="actions"><button class="btn edit" type="button" title="Edit package">Edit</button><button class="btn danger delete" type="button" title="Remove package">Remove</button></td>`;
                        rows.appendChild(row);
                    });
                }
                document.querySelector('#packageCount').textContent = result.packages ? result.packages.length : 0;
            };

            packageRegionSelect.addEventListener('change', () => {
                renderPackageHotelOptions();
                renderPackageDestinationOptions(Array.from(destinationSelect.selectedOptions).map(option => option.value));
                refreshItinerarySpots();
            });
            destinationSelect.addEventListener('change', () => {
                renderDestinationPreview();
                refreshItinerarySpots();
            });
            document.querySelector('#addPriceTier').addEventListener('click', () => addPriceTier());
            priceTierList.addEventListener('click', event => {
                const removeButton = event.target.closest('.remove-tier');
                if (!removeButton) return;
                if (priceTierList.children.length <= 1) return;
                removeButton.closest('.price-tier').remove();
            });
            document.querySelector('#addItineraryDay').addEventListener('click', () => addItineraryDay());
            itineraryList.addEventListener('click', event => {
                const addInclusionButton = event.target.closest('.add-itinerary-inclusion');
                if (addInclusionButton) {
                    addItineraryInclusion(addInclusionButton.closest('.itinerary-day'));
                    return;
                }
                const removeInclusionButton = event.target.closest('.remove-itinerary-inclusion');
                if (removeInclusionButton) {
                    removeInclusionButton.closest('.itinerary-inclusion').remove();
                    return;
                }
                const removeButton = event.target.closest('.remove-itinerary-day');
                if (removeButton) removeButton.closest('.itinerary-day').remove();
            });

            let destinationsReady = Promise.resolve();
            destinationsReady = loadDestinations().catch(error => {
                console.error(error);
                throw error;
            });
            MTRLive.start(() => loadDestinations().catch(error => console.error(error)), { isBusy: () => Boolean(document.querySelector('.modal.open')) });
            let editingRow = null;

            const openModal = async (row) => {
                await destinationsReady;
                editingRow = row;
                document.querySelector('#modalTitle').textContent = row ? 'Edit travel package' : 'Add travel package';
                if (row) {
                    document.querySelector('#packageName').value = row.dataset.name;
                    packageHotel.value = row.dataset.hotelId || '';
                    document.querySelector('#duration').value = row.dataset.durationDays || row.querySelector('.package-name span').textContent.split('·')[0].trim();
                    const visibilityMinutes = Number(row.dataset.visibilityMinutes);
                    const visibilityAmountInput = document.querySelector('#packageVisibilityAmount');
                    const visibilityUnitSelect = document.querySelector('#packageVisibilityUnit');
                    if (visibilityMinutes > 0) {
                        if (visibilityMinutes % 1440 === 0) {
                            visibilityAmountInput.value = String(visibilityMinutes / 1440);
                            visibilityUnitSelect.value = 'day';
                        } else if (visibilityMinutes % 60 === 0) {
                            visibilityAmountInput.value = String(visibilityMinutes / 60);
                            visibilityUnitSelect.value = 'hour';
                        } else {
                            visibilityAmountInput.value = String(visibilityMinutes);
                            visibilityUnitSelect.value = 'minute';
                        }
                    } else {
                        visibilityAmountInput.value = '';
                        visibilityUnitSelect.value = 'day';
                    }
                    document.querySelector('#status').value = row.querySelector('.status').textContent.toLowerCase();
                    document.querySelector('#description').value = row.dataset.description || '';
                    itineraryList.innerHTML = '';
                    const savedItinerary = JSON.parse(row.dataset.itinerary || '[]');
                    if (savedItinerary.length) savedItinerary.forEach(item => addItineraryDay(item.day_number, item.title, item.activity || '', item.inclusions || []));
                    else resetItinerary();
                    const selectedDestinationIds = JSON.parse(row.dataset.destinationIds || '[]').map(String);
                    const selectedRegionNames = JSON.parse(row.dataset.regionNames || '[]');
                    Array.from(packageRegionSelect.options).forEach(option => {
                        option.selected = selectedRegionNames.includes(option.value);
                    });
                    renderPackageHotelOptions();
                    renderPackageDestinationOptions(selectedDestinationIds);
                    refreshItinerarySpots();
                    priceTierList.innerHTML = '';
                    const savedTiers = JSON.parse(row.dataset.priceTiers || '[]');
                    if (savedTiers.length) {
                        savedTiers.forEach(tier => addPriceTier(tier.min_pax, tier.max_pax, tier.price_per_pax));
                    } else {
                        resetPriceTiers();
                    }
                } else {
                    form.reset();
                    packageHotel.value = '';
                    destinationSelect.selectedIndex = -1;
                    Array.from(packageRegionSelect.options).forEach(option => {
                        option.selected = false;
                    });
                    renderPackageHotelOptions();
                    renderPackageDestinationOptions([]);
                    resetPriceTiers();
                    resetItinerary();
                }
                modal.classList.add('open');
                document.querySelector('#packageName').focus();
            };

            const closeModal = () => modal.classList.remove('open');
            document.querySelectorAll('#newPackage, #newPackageAlt').forEach(button => button.addEventListener('click', () => openModal(null).catch(error => showAlert(error.message))));
            modal.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', closeModal));
            modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });

            rows.addEventListener('click', async event => {
                const edit = event.target.closest('.edit');
                const remove = event.target.closest('.delete');
                const row = event.target.closest('[data-package]');
                if (edit) {
                    try {
                        await openModal(row);
                    } catch (error) {
                        await showAlert(error.message);
                    }
                    return;
                }
                if (remove && await showConfirm('Remove this package from the dashboard?')) {
                    try {
                        if (row.dataset.packageId) {
                            await apiRequest({ type: 'delete_package', id: Number(row.dataset.packageId) });
                        }
                        row.remove();
                        document.querySelector('#packageCount').textContent = rows.querySelectorAll('[data-package]').length;
                    } catch (error) {
                        await showAlert(error.message);
                    }
                }
            });

            form.addEventListener('submit', async event => {
                event.preventDefault();
                const name = document.querySelector('#packageName').value.trim();
                const destinations = Array.from(destinationSelect.selectedOptions);
                const destinationIds = destinations.map(option => Number(option.value));
                const regionNames = Array.from(packageRegionSelect.selectedOptions).map(option => option.value.trim());
                const destination = destinations.map(option => option.textContent).join(', ') || 'No destination';
                const duration = document.querySelector('#duration').value.trim();
                const visibilityAmount = document.querySelector('#packageVisibilityAmount').value.trim();
                const visibilityUnit = document.querySelector('#packageVisibilityUnit').value;
                const status = document.querySelector('#status').value;
                const priceTiers = Array.from(priceTierList.querySelectorAll('.price-tier')).map(tier => ({
                    min_pax: Number(tier.querySelector('.tier-min').value),
                    max_pax: Number(tier.querySelector('.tier-max').value),
                    price_per_pax: Number(tier.querySelector('.tier-price').value)
                }));
                const itinerary = Array.from(itineraryList.querySelectorAll('.itinerary-day')).map(day => ({
                    day_number: Number(day.querySelector('.itinerary-number').value),
                    title: day.querySelector('.itinerary-title').value.trim(),
                    activity: day.dataset.activity || '',
                    inclusions: Array.from(day.querySelectorAll('.itinerary-inclusion')).map(item => ({
                        category: item.querySelector('.inclusion-category').value,
                        name: item.querySelector('.inclusion-name').value.trim(),
                        is_included: item.querySelector('.inclusion-status').checked
                    }))
                }));
                const price = priceTiers[0]?.price_per_pax.toLocaleString('en-PH') || '0';
                try {
                    await apiRequest({
                        type: 'package',
                        id: editingRow?.dataset.packageId ? Number(editingRow.dataset.packageId) : null,
                        name,
                        destination_ids: destinationIds,
                        region_names: regionNames,
                        price_tiers: priceTiers,
                        duration_days: Number(duration),
                        visibility_amount: visibilityAmount === '' ? null : Number(visibilityAmount),
                        visibility_unit: visibilityUnit,
                        description: document.querySelector('#description').value.trim(),
                        itinerary,
                        hotel_id: packageHotel.value ? Number(packageHotel.value) : null,
                        status: status.toLowerCase()
                    });
                } catch (error) {
                    await showAlert(error.message);
                    return;
                }
                await loadDestinations();
                closeModal();
            });

            const destinationModal = document.querySelector('#destinationModal');
            const destinationForm = document.querySelector('#destinationForm');
            const photoGrid = document.querySelector('#destinationPhotoGrid');
            const photoInput = document.querySelector('#destinationImages');
            let existingPhotos = [];
            let pendingPhotoFiles = [];

            const renderPhotoGrid = () => {
                photoGrid.innerHTML = '';
                existingPhotos.forEach(photo => {
                    const thumb = document.createElement('div');
                    thumb.className = 'photo-thumb';
                    thumb.innerHTML = `<img src="../${photo.path}" alt="Destination photo"><button class="photo-remove" type="button" data-existing-id="${photo.id}" title="Remove photo" aria-label="Remove photo">×</button>`;
                    photoGrid.appendChild(thumb);
                });
                pendingPhotoFiles.forEach((file, index) => {
                    const thumb = document.createElement('div');
                    thumb.className = 'photo-thumb';
                    thumb.innerHTML = `<img src="${URL.createObjectURL(file)}" alt="New destination photo"><button class="photo-remove" type="button" data-pending-index="${index}" title="Remove photo" aria-label="Remove photo">×</button>`;
                    photoGrid.appendChild(thumb);
                });
                const addButton = document.createElement('button');
                addButton.type = 'button';
                addButton.className = 'photo-add';
                addButton.id = 'addPhotoButton';
                addButton.title = 'Add photos';
                addButton.setAttribute('aria-label', 'Add photos');
                addButton.textContent = '+';
                photoGrid.appendChild(addButton);
            };

            photoGrid.addEventListener('click', async event => {
                if (event.target.closest('#addPhotoButton')) {
                    photoInput.click();
                    return;
                }
                const removeExisting = event.target.closest('[data-existing-id]');
                if (removeExisting) {
                    if (!await showConfirm('Remove this photo? This cannot be undone.')) return;
                    const imageId = removeExisting.dataset.existingId;
                    try {
                        await apiRequest({ type: 'delete_destination_image', id: imageId });
                        existingPhotos = existingPhotos.filter(photo => String(photo.id) !== String(imageId));
                        renderPhotoGrid();
                        await loadDestinations();
                    } catch (error) {
                        await showAlert(error.message);
                    }
                    return;
                }
                const removePending = event.target.closest('[data-pending-index]');
                if (removePending) {
                    if (!await showConfirm('Remove this photo?')) return;
                    pendingPhotoFiles.splice(Number(removePending.dataset.pendingIndex), 1);
                    renderPhotoGrid();
                }
            });

            photoInput.addEventListener('change', () => {
                pendingPhotoFiles.push(...Array.from(photoInput.files));
                photoInput.value = '';
                renderPhotoGrid();
            });

            const closeDestinationModal = () => destinationModal.classList.remove('open');
            document.querySelector('#newPlace').addEventListener('click', () => {
                destinationForm.reset();
                delete destinationForm.dataset.editingId;
                existingPhotos = [];
                pendingPhotoFiles = [];
                renderPhotoGrid();
                document.querySelector('#destinationModalTitle').textContent = 'Add destination';
                destinationModal.classList.add('open');
                document.querySelector('#destinationName').focus();
            });
            document.querySelectorAll('[data-destination-close]').forEach(button => button.addEventListener('click', closeDestinationModal));
            destinationModal.addEventListener('click', event => { if (event.target === destinationModal) closeDestinationModal(); });
            placeGrid.addEventListener('click', event => {
                const carousel = event.target.closest('[data-place-carousel]');
                if (!carousel) return;
                const images = carousel.querySelectorAll('.place-carousel-image');
                if (images.length < 2) return;
                let current = Number(carousel.dataset.current || 0);
                if (event.target.closest('.next')) current = (current + 1) % images.length;
                if (event.target.closest('.previous')) current = (current - 1 + images.length) % images.length;
                const dot = event.target.closest('.place-carousel-dot');
                if (dot) current = Number(dot.dataset.carouselIndex);
                carousel.dataset.current = current;
                images.forEach((image, index) => image.classList.toggle('active', index === current));
                carousel.querySelectorAll('.place-carousel-dot').forEach((item, index) => item.classList.toggle('active', index === current));
                event.stopPropagation();
            });

            placeGrid.addEventListener('click', async event => {
                const place = event.target.closest('.place');
                if (!place) return;
                const destination = resultDestinationCache.find(item => String(item.id) === place.dataset.destinationId);
                if (!destination) return;
                if (event.target.closest('.delete-place')) {
                    if (!await showConfirm(`Delete ${destination.name}? This cannot be undone.`)) return;
                    try {
                        await apiRequest({ type: 'delete_destination', id: destination.id });
                        await loadDestinations();
                    } catch (error) {
                        await showAlert(error.message);
                    }
                }
                if (event.target.closest('.edit-place')) {
                    document.querySelector('#destinationName').value = destination.name;
                    document.querySelector('#destinationLocation').value = destination.location || destination.region || '';
                    const regionSelect = document.querySelector('#destinationRegion');
                    const existingRegion = destination.region || '';
                    if (existingRegion && !Array.from(regionSelect.options).some(option => option.value === existingRegion)) {
                        regionSelect.add(new Option(`${existingRegion} (existing)`, existingRegion));
                    }
                    regionSelect.value = existingRegion;
                    document.querySelector('#destinationCategory').value = destination.category || 'island';
                    document.querySelector('#destinationPrice').value = destination.price;
                    document.querySelector('#destinationDescription').value = destination.description || '';
                    destinationForm.dataset.editingId = destination.id;
                    existingPhotos = (destination.images || []).map(image => ({ id: image.id, path: image.path }));
                    pendingPhotoFiles = [];
                    renderPhotoGrid();
                    document.querySelector('#destinationModalTitle').textContent = 'Edit destination';
                    destinationModal.classList.add('open');
                    document.querySelector('#destinationName').focus();
                }
            });
            destinationForm.addEventListener('submit', async event => {
                event.preventDefault();
                try {
                    const formData = new FormData();
                    formData.append('type', 'destination');
                    if (destinationForm.dataset.editingId) formData.append('id', destinationForm.dataset.editingId);
                    formData.append('name', document.querySelector('#destinationName').value.trim());
                    formData.append('location', document.querySelector('#destinationLocation').value.trim());
                    formData.append('region', document.querySelector('#destinationRegion').value.trim());
                    formData.append('category', document.querySelector('#destinationCategory').value);
                    formData.append('price', document.querySelector('#destinationPrice').value);
                    formData.append('description', document.querySelector('#destinationDescription').value.trim());
                    pendingPhotoFiles.forEach(file => formData.append('images[]', file));
                    const response = await fetch('../api/admin-content.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Unable to save destination.');
                    await loadDestinations();
                    closeDestinationModal();
                    delete destinationForm.dataset.editingId;
                    pendingPhotoFiles = [];
                    await showAlert('Destination saved successfully.');
                } catch (error) {
                    await showAlert(error.message);
                }
            });

            const hotelModal = document.querySelector('#hotelModal');
            const hotelForm = document.querySelector('#hotelForm');
            const hotelRowsBody = document.querySelector('#hotelRows');
            const hotelPhotoGrid = document.querySelector('#hotelPhotoGrid');
            const hotelPhotoInput = document.querySelector('#hotelImages');
            let existingHotelPhotos = [];
            let pendingHotelPhotoFiles = [];
            const closeHotelModal = () => hotelModal.classList.remove('open');
            const hotelPhotoUrl = path => String(path || '').startsWith('http') ? path : `../${path}`;

            const renderHotelPhotoGrid = () => {
                hotelPhotoGrid.innerHTML = '';
                existingHotelPhotos.forEach(photo => {
                    const thumb = document.createElement('div');
                    thumb.className = 'photo-thumb';
                    const removeButton = photo.id ? `<button class="photo-remove" type="button" data-hotel-existing-id="${photo.id}" title="Remove photo" aria-label="Remove photo">×</button>` : '';
                    thumb.innerHTML = `<img src="${escapeHtml(hotelPhotoUrl(photo.path))}" alt="Hotel photo">${removeButton}`;
                    hotelPhotoGrid.appendChild(thumb);
                });
                pendingHotelPhotoFiles.forEach((file, index) => {
                    const thumb = document.createElement('div');
                    thumb.className = 'photo-thumb';
                    thumb.innerHTML = `<img src="${URL.createObjectURL(file)}" alt="New hotel photo"><button class="photo-remove" type="button" data-hotel-pending-index="${index}" title="Remove photo" aria-label="Remove photo">×</button>`;
                    hotelPhotoGrid.appendChild(thumb);
                });
                const addButton = document.createElement('button');
                addButton.type = 'button';
                addButton.className = 'photo-add';
                addButton.id = 'addHotelPhotoButton';
                addButton.title = 'Add hotel photos';
                addButton.setAttribute('aria-label', 'Add hotel photos');
                addButton.textContent = '+';
                hotelPhotoGrid.appendChild(addButton);
            };

            hotelPhotoGrid.addEventListener('click', async event => {
                if (event.target.closest('#addHotelPhotoButton')) {
                    hotelPhotoInput.click();
                    return;
                }
                const existingButton = event.target.closest('[data-hotel-existing-id]');
                if (existingButton) {
                    if (!await showConfirm('Remove this hotel photo? This cannot be undone.')) return;
                    try {
                        await apiRequest({ type: 'delete_hotel_image', id: existingButton.dataset.hotelExistingId });
                        existingHotelPhotos = existingHotelPhotos.filter(photo => String(photo.id) !== String(existingButton.dataset.hotelExistingId));
                        renderHotelPhotoGrid();
                        await loadDestinations();
                    } catch (error) {
                        await showAlert(error.message);
                    }
                    return;
                }
                const pendingButton = event.target.closest('[data-hotel-pending-index]');
                if (pendingButton) {
                    pendingHotelPhotoFiles.splice(Number(pendingButton.dataset.hotelPendingIndex), 1);
                    renderHotelPhotoGrid();
                }
            });
            hotelPhotoInput.addEventListener('change', () => {
                pendingHotelPhotoFiles.push(...Array.from(hotelPhotoInput.files));
                hotelPhotoInput.value = '';
                renderHotelPhotoGrid();
            });

            document.querySelector('#newHotel').addEventListener('click', () => {
                hotelForm.reset();
                delete hotelForm.dataset.editingId;
                existingHotelPhotos = [];
                pendingHotelPhotoFiles = [];
                renderHotelPhotoGrid();
                document.querySelector('#hotelStatus').value = 'active';
                document.querySelector('#hotelRating').value = '0';
                document.querySelector('#hotelModalTitle').textContent = 'Add Hotel';
                hotelModal.classList.add('open');
                document.querySelector('#hotelName').focus();
            });

            document.querySelectorAll('[data-hotel-close]').forEach(button => button.addEventListener('click', closeHotelModal));
            hotelModal.addEventListener('click', event => { if (event.target === hotelModal) closeHotelModal(); });

            hotelRowsBody.addEventListener('click', async event => {
                const row = event.target.closest('[data-hotel]');
                if (!row) return;
                const hotel = resultHotelCache.find(item => String(item.id) === String(row.dataset.id));
                if (!hotel) return;

                if (event.target.closest('.delete-hotel')) {
                    if (!await showConfirm(`Delete ${hotel.name}? This cannot be undone.`)) return;
                    try {
                        await apiRequest({ type: 'delete_hotel', id: hotel.id });
                        await loadDestinations();
                    } catch (error) {
                        await showAlert(error.message);
                    }
                    return;
                }

                if (event.target.closest('.edit-hotel')) {
                    document.querySelector('#hotelName').value = hotel.name;
                    document.querySelector('#hotelLocation').value = hotel.location;
                    const hotelRegionSelect = document.querySelector('#hotelRegion');
                    const existingHotelRegion = hotel.region || '';
                    if (existingHotelRegion && !Array.from(hotelRegionSelect.options).some(option => option.value === existingHotelRegion)) {
                        hotelRegionSelect.add(new Option(`${existingHotelRegion} (existing)`, existingHotelRegion));
                    }
                    hotelRegionSelect.value = existingHotelRegion;
                    document.querySelector('#hotelPrice').value = hotel.price_per_night;
                    document.querySelector('#hotelRating').value = hotel.rating || 0;
                    document.querySelector('#hotelStatus').value = hotel.status;
                    document.querySelector('#hotelDescription').value = hotel.description || '';
                    document.querySelector('#hotelAmenities').value = hotel.amenities || '';
                    existingHotelPhotos = (hotel.images || []).map(image => ({ id: image.id, path: image.path }));
                    pendingHotelPhotoFiles = [];
                    renderHotelPhotoGrid();
                    hotelForm.dataset.editingId = hotel.id;
                    document.querySelector('#hotelModalTitle').textContent = 'Edit Hotel';
                    hotelModal.classList.add('open');
                    document.querySelector('#hotelName').focus();
                }
            });

            hotelForm.addEventListener('submit', async event => {
                event.preventDefault();
                try {
                    const formData = new FormData();
                    formData.append('type', 'hotel');
                    if (hotelForm.dataset.editingId) formData.append('id', hotelForm.dataset.editingId);
                    formData.append('name', document.querySelector('#hotelName').value.trim());
                    formData.append('location', document.querySelector('#hotelLocation').value.trim());
                    formData.append('region', document.querySelector('#hotelRegion').value.trim());
                    formData.append('price_per_night', document.querySelector('#hotelPrice').value);
                    formData.append('rating', document.querySelector('#hotelRating').value);
                    formData.append('status', document.querySelector('#hotelStatus').value);
                    formData.append('description', document.querySelector('#hotelDescription').value.trim());
                    formData.append('amenities', document.querySelector('#hotelAmenities').value.trim());
                    pendingHotelPhotoFiles.forEach(file => formData.append('images[]', file));
                    const response = await fetch('../api/admin-content.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Unable to save hotel.');
                    await loadDestinations();
                    closeHotelModal();
                    delete hotelForm.dataset.editingId;
                    existingHotelPhotos = [];
                    pendingHotelPhotoFiles = [];
                    await showAlert('Hotel saved successfully.');
                } catch (error) {
                    await showAlert(error.message);
                }
            });

            search.addEventListener('input', () => {
                const query = search.value.toLowerCase();
                rows.querySelectorAll('[data-package]').forEach(row => { row.hidden = !row.dataset.name.toLowerCase().includes(query); });
            });

            const driverFilter = document.querySelector('#driverFilter');
            driverFilter.addEventListener('change', () => {
                const selectedStatus = driverFilter.value;
                document.querySelectorAll('#driverRows [data-driver]').forEach(row => {
                    row.hidden = selectedStatus !== 'all' && row.dataset.status !== selectedStatus;
                });
            });
            document.querySelector('#driverRows')?.addEventListener('change', async event => {
                const select = event.target.closest('.driver-vehicle-select');
                if (!select || !select.value) return;
                try {
                    await apiRequest({ type: 'assign_company_vehicle', vehicle_id: Number(select.value), driver_id: Number(select.dataset.driverId) });
                    await loadDestinations();
                } catch (error) {
                    await showAlert(error.message);
                }
            });
            document.querySelector('#staffFilter')?.addEventListener('change', event => {
                const selectedRole = event.target.value;
                document.querySelectorAll('#staffRows [data-staff-role]').forEach(row => {
                    row.hidden = selectedRole !== 'all' && row.dataset.staffRole !== selectedRole;
                });
            });

            document.querySelector('#companyVehicleForm')?.addEventListener('submit', async event => {
                event.preventDefault();
                const formData = new FormData();
                formData.append('type', 'company_vehicle');
                formData.append('vehicle_unit', document.querySelector('#companyVehicleUnit').value.trim());
                formData.append('vehicle_color', document.querySelector('#companyVehicleColor').value.trim());
                formData.append('plate_number', document.querySelector('#companyVehiclePlate').value.trim());
                formData.append('passenger_capacity', document.querySelector('#companyVehicleCapacity').value);
                formData.append('status', document.querySelector('#companyVehicleStatus').value);
                Array.from(document.querySelector('#companyVehicleImages').files).forEach(file => formData.append('images[]', file));
                try {
                    const response = await fetch('../api/admin-content.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Unable to add company vehicle.');
                    event.target.reset();
                    await loadDestinations();
                    await showAlert('Company vehicle added.');
                } catch (error) {
                    await showAlert(error.message);
                }
            });
            document.querySelector('#companyVehicleRows')?.addEventListener('change', async event => {
                const select = event.target.closest('.company-vehicle-driver');
                if (!select) return;
                try {
                    await apiRequest({ type: 'assign_company_vehicle', vehicle_id: Number(select.dataset.vehicleId), driver_id: select.value ? Number(select.value) : null });
                    await loadDestinations();
                } catch (error) {
                    await showAlert(error.message);
                    await loadDestinations();
                }
            });
            document.querySelector('#companyVehicleRows')?.addEventListener('click', async event => {
                const button = event.target.closest('.delete-company-vehicle');
                if (!button || button.disabled || !await showConfirm('Remove this company vehicle?')) return;
                try {
                    await apiRequest({ type: 'delete_company_vehicle', id: Number(button.dataset.vehicleId) });
                    await loadDestinations();
                } catch (error) {
                    await showAlert(error.message);
                }
            });
            const companyVehicleCards = document.querySelector('#companyVehicleCards');
            const companyVehicleViewModal = document.querySelector('#companyVehicleViewModal');
            const companyVehicleViewBody = document.querySelector('#companyVehicleViewBody');
            const closeCompanyVehicleView = () => companyVehicleViewModal.classList.remove('open');
            document.querySelectorAll('[data-close-vehicle-view]').forEach(button => button.addEventListener('click', closeCompanyVehicleView));
            companyVehicleViewModal.addEventListener('click', event => { if (event.target === companyVehicleViewModal) closeCompanyVehicleView(); });
            companyVehicleCards?.addEventListener('change', async event => {
                const select = event.target.closest('.company-vehicle-driver');
                if (!select) return;
                try {
                    await apiRequest({ type: 'assign_company_vehicle', vehicle_id: Number(select.dataset.vehicleId), driver_id: select.value ? Number(select.value) : null });
                    await loadDestinations();
                } catch (error) {
                    await showAlert(error.message);
                    await loadDestinations();
                }
            });
            companyVehicleCards?.addEventListener('click', async event => {
                const card = event.target.closest('[data-company-vehicle-id]');
                if (!card) return;
                const vehicle = resultVehicleCache.find(item => Number(item.id) === Number(card.dataset.companyVehicleId));
                if (!vehicle) return;
                if (event.target.closest('.view-company-vehicle')) {
                    const imagePaths = vehicle.image_paths || [];
                    const photos = imagePaths.map((path, index) => `<img class="company-vehicle-slide${index === 0 ? ' active' : ''}" src="../${escapeHtml(path)}" alt="${escapeHtml(vehicle.vehicle_unit)} photo ${index + 1}"${index === 0 ? '' : ' hidden'}>`).join('');
                    const controls = imagePaths.length > 1
                        ? `<button class="company-vehicle-gallery-control previous" type="button" aria-label="Previous vehicle photo">‹</button><button class="company-vehicle-gallery-control next" type="button" aria-label="Next vehicle photo">›</button><div class="company-vehicle-gallery-dots">${imagePaths.map((_, index) => `<button class="company-vehicle-gallery-dot${index === 0 ? ' active' : ''}" type="button" data-vehicle-image-index="${index}" aria-label="Show photo ${index + 1}"></button>`).join('')}</div>`
                        : '';
                    document.querySelector('#companyVehicleViewTitle').textContent = vehicle.vehicle_unit;
                    companyVehicleViewBody.innerHTML = `<div class="driver-profile-details" style="margin-bottom:16px"><div class="driver-profile-detail"><span>Color</span><strong>${escapeHtml(vehicle.vehicle_color)}</strong></div><div class="driver-profile-detail"><span>Plate</span><strong>${escapeHtml(vehicle.plate_number)}</strong></div><div class="driver-profile-detail"><span>Capacity</span><strong>${escapeHtml(vehicle.passenger_capacity)} guests</strong></div><div class="driver-profile-detail"><span>Status</span><strong>${escapeHtml(vehicle.status)}</strong></div><div class="driver-profile-detail"><span>Assigned driver</span><strong>${escapeHtml(vehicle.assigned_driver_name || 'Unassigned')}</strong></div></div><div class="company-vehicle-gallery" data-vehicle-carousel data-current="0">${photos || '<p class="guest-cell">No vehicle photos available.</p>'}${controls}</div>`;
                    companyVehicleViewModal.classList.add('open');
                    return;
                }
                const deleteButton = event.target.closest('.delete-company-vehicle');
                if (!deleteButton || deleteButton.disabled || !await showConfirm(`Remove ${vehicle.vehicle_unit}?`)) return;
                try {
                    await apiRequest({ type: 'delete_company_vehicle', id: Number(vehicle.id) });
                    await loadDestinations();
                } catch (error) {
                    await showAlert(error.message);
                }
            });
            companyVehicleViewBody.addEventListener('click', event => {
                const carousel = event.target.closest('[data-vehicle-carousel]');
                if (!carousel) return;
                const images = Array.from(carousel.querySelectorAll('.company-vehicle-slide'));
                if (images.length < 2) return;
                let current = Number(carousel.dataset.current || 0);
                if (event.target.closest('.next')) current = (current + 1) % images.length;
                else if (event.target.closest('.previous')) current = (current - 1 + images.length) % images.length;
                else if (event.target.closest('.company-vehicle-gallery-dot')) current = Number(event.target.closest('.company-vehicle-gallery-dot').dataset.vehicleImageIndex);
                else return;
                carousel.dataset.current = String(current);
                images.forEach((image, index) => {
                    image.classList.toggle('active', index === current);
                    image.hidden = index !== current;
                });
                carousel.querySelectorAll('.company-vehicle-gallery-dot').forEach((dot, index) => dot.classList.toggle('active', index === current));
            });

            const employeeModal = document.querySelector('#employeeModal');
            const employeeForm = document.querySelector('#employeeForm');
            const closeEmployeeModal = () => employeeModal.classList.remove('open');
            document.querySelector('#addEmployeeButton')?.addEventListener('click', () => {
                employeeForm.reset();
                employeeModal.classList.add('open');
                document.querySelector('#employeeName').focus();
            });
            document.querySelectorAll('[data-employee-close]').forEach(button => button.addEventListener('click', closeEmployeeModal));
            employeeModal?.addEventListener('click', event => { if (event.target === employeeModal) closeEmployeeModal(); });
            employeeForm?.addEventListener('submit', async event => {
                event.preventDefault();
                const password = document.querySelector('#employeePassword').value;
                const passwordConfirmation = document.querySelector('#employeePasswordConfirmation').value;
                if (password !== passwordConfirmation) {
                    await showAlert('Passwords do not match.');
                    return;
                }
                const submitButton = employeeForm.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                try {
                    await apiRequest({
                        type: 'create_employee',
                        full_name: document.querySelector('#employeeName').value.trim(),
                        email: document.querySelector('#employeeEmail').value.trim(),
                        phone: document.querySelector('#employeePhone').value.trim(),
                        password
                    });
                    closeEmployeeModal();
                    await showAlert('Employee account created successfully.');
                } catch (error) {
                    await showAlert(error.message);
                } finally {
                    submitButton.disabled = false;
                }
            });

            const driverModal = document.querySelector('#driverModal');
            const driverForm = document.querySelector('#driverForm');
            const driverRows = document.querySelector('#driverRows');
            const closeDriverModal = () => driverModal.classList.remove('open');
            document.querySelector('#newDriver')?.addEventListener('click', () => {
                driverForm.reset();
                document.querySelector('#driverRating').value = '5.0';
                driverModal.classList.add('open');
                document.querySelector('#driverName').focus();
            });
            document.querySelectorAll('[data-driver-close]').forEach(button => button.addEventListener('click', closeDriverModal));
            driverModal?.addEventListener('click', event => { if (event.target === driverModal) closeDriverModal(); });
            driverForm?.addEventListener('submit', event => {
                event.preventDefault();
                const fullName = document.querySelector('#driverName').value.trim();
                const phone = document.querySelector('#driverPhone').value.trim();
                const vehicle = document.querySelector('#vehicleName').value.trim();
                const vehicleColor = document.querySelector('#vehicleColor').value.trim();
                const maxPax = document.querySelector('#maxPax').value;
                const rating = Number(document.querySelector('#driverRating').value).toFixed(1);
                const initials = fullName.split(/\s+/).map(namePart => namePart[0]).join('').slice(0, 2).toUpperCase();
                const row = document.createElement('tr');
                row.dataset.driver = '';
                row.dataset.status = 'available';
                row.dataset.phone = phone;
                row.innerHTML = `<td><div class="driver-name"><div class="driver-avatar">${initials}</div><div><strong>${fullName}</strong><span>${rating} rating · New driver</span></div></div></td><td>${vehicle} · ${vehicleColor}</td><td><strong>${maxPax}</strong> guests</td><td><span class="driver-status available">Available</span></td><td class="guest-cell">No guest assigned</td><td><button class="icon-btn" type="button" title="Contact ${fullName}" aria-label="Contact ${fullName}">☎</button></td>`;
                driverRows.appendChild(row);
                closeDriverModal();
                driverForm.reset();
            });

            const driverProfileModal = document.querySelector('#driverProfileModal');
            const driverProfileBody = document.querySelector('#driverProfileBody');
            const closeDriverProfile = () => driverProfileModal.classList.remove('open');
            document.querySelectorAll('[data-driver-profile-close]').forEach(button => button.addEventListener('click', closeDriverProfile));
            driverProfileModal.addEventListener('click', event => { if (event.target === driverProfileModal) closeDriverProfile(); });
            const renderDriverFile = (label, path) => {
                if (!path) return `<article class="driver-file-card"><div class="driver-file-preview">No file uploaded</div><div class="driver-file-info"><strong>${label}</strong></div></article>`;
                const url = driverFileUrl(path);
                const isPdf = /\.pdf(?:$|\?)/i.test(path);
                const preview = isPdf ? `<iframe src="${escapeHtml(url)}" title="${label} preview"></iframe>` : `<img src="${escapeHtml(url)}" alt="${label}">`;
                return `<article class="driver-file-card"><div class="driver-file-preview">${preview}</div><div class="driver-file-info"><strong>${label}</strong><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">Open in new tab ↗</a></div></article>`;
            };
            const openDriverProfile = driver => {
                const statusLabel = String(driver.verification_status || '').replace(/^./, character => character.toUpperCase());
                driver.vehicle_unit = driver.company_vehicle_unit || '';
                driver.vehicle_color = driver.company_vehicle_color || '';
                driver.plate_number = driver.company_plate_number || '';
                driver.passenger_capacity = driver.company_passenger_capacity || '';
                driverProfileBody.dataset.driverId = driver.id;
                driverProfileBody.innerHTML = `<div class="driver-profile-head"><div class="driver-profile-avatar">${escapeHtml(driverInitials(driver.full_name))}</div><div><h3 id="driverProfileTitle">${escapeHtml(driver.full_name)}</h3><p>${escapeHtml(driver.email || 'No email provided')} · ${escapeHtml(driver.phone || 'No phone provided')}</p></div><span class="status driver-profile-status">${escapeHtml(statusLabel)}</span></div><section class="driver-profile-section"><h3>Driver and vehicle details</h3><div class="driver-profile-details"><div class="driver-profile-detail"><span>Phone</span><strong>${escapeHtml(driver.phone || 'Not provided')}</strong></div><div class="driver-profile-detail"><span>Email</span><strong>${escapeHtml(driver.email || 'Not provided')}</strong></div><div class="driver-profile-detail"><span>Vehicle</span><strong>${escapeHtml(driver.vehicle_unit || 'Not provided')}</strong></div><div class="driver-profile-detail"><span>Color</span><strong>${escapeHtml(driver.vehicle_color || 'Not provided')}</strong></div><div class="driver-profile-detail"><span>Plate number</span><strong>${escapeHtml(driver.plate_number || 'Not provided')}</strong></div><div class="driver-profile-detail"><span>Passenger capacity</span><strong>${escapeHtml(driver.passenger_capacity || '0')} guests</strong></div><div class="driver-profile-detail"><span>Training completed</span><strong>${driver.training_completed ? 'Yes' : 'No'}</strong></div></div></section><section class="driver-profile-section"><h3>Attached files</h3><div class="driver-file-grid">${renderDriverFile('Training certificate', driver.training_certificate_file)}${renderDriverFile('Driver license', driver.license_file)}${renderDriverFile('Test-drive certificate or result', driver.test_drive_file)}</div></section><div class="driver-review-actions"><button class="driver-review-button approve" type="button" data-driver-review="approved">Approve driver</button><button class="driver-review-button deny" type="button" data-driver-review="rejected">Deny driver</button></div>`;
                driverProfileModal.classList.add('open');
            };
            const driverScheduleModal = document.querySelector('#driverScheduleModal');
            const closeDriverSchedule = () => driverScheduleModal.classList.remove('open');
            document.querySelectorAll('[data-driver-schedule-close]').forEach(button => button.addEventListener('click', closeDriverSchedule));
            driverScheduleModal.addEventListener('click', event => { if (event.target === driverScheduleModal) closeDriverSchedule(); });
            driverRows.addEventListener('click', event => {
                const scheduleButton = event.target.closest('.view-driver-schedule');
                if (!scheduleButton) return;
                const driverId = scheduleButton.closest('[data-driver]').dataset.driverId;
                const driver = resultDriverCache.find(item => String(item.id) === String(driverId));
                const trips = (window.adminBookingsCache || []).filter(booking => String(booking.driver_id) === String(driverId) && !['cancelled'].includes(String(booking.booking_status || '').toLowerCase()))
                    .sort((a, b) => String(a.travel_date).localeCompare(String(b.travel_date)));
                document.querySelector('#driverScheduleTitle').textContent = `${driver ? driver.full_name : 'Driver'} schedule`;
                document.querySelector('#driverScheduleBody').innerHTML = trips.length ? trips.map(booking => {
                    const start = new Date(`${booking.travel_date}T00:00:00`);
                    const end = new Date(start);
                    end.setDate(end.getDate() + Math.max(1, Number(booking.trip_duration_days) || 1) - 1);
                    const format = date => date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
                    return `<div class="schedule-modal-item"><strong>${escapeHtml(booking.package_name || 'Booked plan')}</strong><span>${format(start)}${end > start ? ` – ${format(end)}` : ''} · ${escapeHtml(booking.customer_name)} · ${escapeHtml(booking.guest_count)} traveler(s) · ${escapeHtml(booking.booking_status)}</span></div>`;
                }).join('') : '<p class="guest-cell">No trips scheduled for this driver.</p>';
                driverScheduleModal.classList.add('open');
            });
            driverRows.addEventListener('click', event => {
                const profileButton = event.target.closest('.view-driver-profile');
                if (!profileButton) return;
                const row = profileButton.closest('[data-driver]');
                const driver = resultDriverCache.find(item => String(item.id) === String(row.dataset.driverId));
                if (driver) openDriverProfile(driver);
            });
            driverProfileBody.addEventListener('click', async event => {
                const reviewButton = event.target.closest('[data-driver-review]');
                if (!reviewButton) return;
                const driver = resultDriverCache.find(item => String(item.id) === String(driverProfileBody.dataset.driverId));
                if (!driver) return;
                const status = reviewButton.dataset.driverReview;
                const actionLabel = status === 'approved' ? 'approve' : 'deny';
                if (!await showConfirm(`Are you sure you want to ${actionLabel} ${driver.full_name}?`)) return;
                reviewButton.disabled = true;
                try {
                    await apiRequest({ type: 'driver_status', id: Number(driver.id), status });
                    await loadDestinations();
                    const updatedDriver = resultDriverCache.find(item => String(item.id) === String(driver.id));
                    if (updatedDriver) {
                        openDriverProfile(updatedDriver);
                    }
                    await showAlert(status === 'approved' ? 'Driver approved successfully.' : 'Driver denied successfully.');
                } catch (error) {
                    reviewButton.disabled = false;
                    await showAlert(error.message);
                }
            });

            const bookingRows = document.querySelector('#bookingRows');
            const bookingTableContainers = [document.querySelector('#bookingRows'), document.querySelector('#customBookingRows')].filter(Boolean);
            bookingTableContainers.forEach(table => {
                table.addEventListener('click', async event => {
                    const reviewButton = event.target.closest('.review-booking-btn');
                    if (reviewButton) {
                        const row = reviewButton.closest('[data-booking]');
                        const status = reviewButton.dataset.reviewStatus;
                        let note = '';
                        if (status === 'rejected') {
                            note = window.prompt('Why is this plan being denied?')?.trim() || '';
                            if (!note) return;
                        }
                        reviewButton.disabled = true;
                        try {
                            const result = await apiRequest({ type: 'review_booking', id: Number(row.dataset.bookingId), status, note });
                            await loadDestinations();
                            const notificationNote = status === 'approved' && !result.notification_sent ? ' Email notification was not sent; check the mail configuration.' : '';
                            await showAlert((status === 'approved' ? 'Plan approved for payment.' : 'Plan denied with feedback.') + notificationNote);
                        } catch (error) {
                            reviewButton.disabled = false;
                            await showAlert(error.message);
                        }
                        return;
                    }
                });
            });

            // Payment Methods JS Handlers
            const pmModal = document.querySelector('#paymentMethodModal');
            const pmForm = document.querySelector('#paymentMethodForm');
            const closePmModal = () => pmModal.classList.remove('open');

            document.querySelector('#newPaymentMethodBtn')?.addEventListener('click', () => {
                document.querySelector('#pmModalTitle').textContent = 'Add Payment Method';
                document.querySelector('#pmId').value = '';
                document.querySelector('#pmMethodType').value = 'gcash';
                document.querySelector('#pmBankName').value = 'GCash';
                document.querySelector('#pmAccountName').value = '';
                document.querySelector('#pmAccountNumber').value = '';
                document.querySelector('#pmInstructions').value = '';
                document.querySelector('#pmStatus').value = 'active';
                pmModal.classList.add('open');
            });

            document.querySelectorAll('[data-pm-close]').forEach(btn => btn.addEventListener('click', closePmModal));

            pmForm?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData();
                formData.append('type', 'payment_method');
                formData.append('action', 'save');
                formData.append('id', document.querySelector('#pmId').value);
                formData.append('method_type', document.querySelector('#pmMethodType').value);
                formData.append('bank_name', document.querySelector('#pmBankName').value);
                formData.append('account_name', document.querySelector('#pmAccountName').value);
                formData.append('account_number', document.querySelector('#pmAccountNumber').value);
                formData.append('instructions', document.querySelector('#pmInstructions').value);
                formData.append('status', document.querySelector('#pmStatus').value);

                const fileInput = document.querySelector('#pmQrCode');
                if (fileInput && fileInput.files.length > 0) {
                    formData.append('qr_code', fileInput.files[0]);
                }

                try {
                    const res = await fetch('../api/admin-content.php', { method: 'POST', body: formData });
                    const result = await res.json();
                    if (!res.ok) throw new Error(result.error || 'Failed to save payment method.');
                    closePmModal();
                    await loadDestinations();
                    await showAlert(result.message || 'Payment method saved.');
                } catch (err) {
                    await showAlert(err.message);
                }
            });

            document.querySelector('#paymentMethodRows')?.addEventListener('click', async (e) => {
                const editBtn = e.target.closest('.edit-pm');
                const delBtn = e.target.closest('.delete-pm');
                const row = e.target.closest('tr');
                if (!row) return;
                const pm = JSON.parse(row.dataset.pmData || '{}');

                if (editBtn) {
                    document.querySelector('#pmModalTitle').textContent = 'Edit Payment Method';
                    document.querySelector('#pmId').value = pm.id;
                    document.querySelector('#pmMethodType').value = pm.method_type || 'gcash';
                    document.querySelector('#pmBankName').value = pm.bank_name || '';
                    document.querySelector('#pmAccountName').value = pm.account_name || '';
                    document.querySelector('#pmAccountNumber').value = pm.account_number || '';
                    document.querySelector('#pmInstructions').value = pm.instructions || '';
                    document.querySelector('#pmStatus').value = pm.status || 'active';
                    pmModal.classList.add('open');
                } else if (delBtn) {
                    const confirmed = await showConfirm(`Delete payment method "${pm.account_name}"?`);
                    if (confirmed) {
                        try {
                            const res = await fetch('../api/admin-content.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ type: 'payment_method', action: 'delete', id: pm.id })
                            });
                            const result = await res.json();
                            await loadDestinations();
                            await showAlert(result.message || 'Payment method deleted.');
                        } catch (err) {
                            await showAlert(err.message);
                        }
                    }
                }
            });

            // Receipt Modal Handlers
            const receiptModal = document.querySelector('#receiptModal');
            const receiptBody = document.querySelector('#receiptModalBody');
            const closeReceiptModal = () => receiptModal.classList.remove('open');
            document.querySelectorAll('[data-receipt-close]').forEach(btn => btn.addEventListener('click', closeReceiptModal));
            const refundModal = document.querySelector('#refundModal');
            const refundForm = document.querySelector('#refundForm');
            const closeRefundModal = () => refundModal.classList.remove('open');
            document.querySelectorAll('[data-refund-close]').forEach(btn => btn.addEventListener('click', closeRefundModal));
            refundModal.addEventListener('click', event => { if (event.target === refundModal) closeRefundModal(); });
            refundForm.addEventListener('submit', async event => {
                event.preventDefault();
                const formData = new FormData();
                formData.append('type', 'record_refund');
                formData.append('payment_id', document.querySelector('#refundPaymentId').value);
                formData.append('refund_amount', document.querySelector('#refundAmount').value);
                formData.append('refund_reference', document.querySelector('#refundReference').value.trim());
                formData.append('refund_proof', document.querySelector('#refundProof').files[0]);
                try {
                    const response = await fetch('../api/admin-content.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Unable to record refund.');
                    closeRefundModal();
                    await loadDestinations();
                    await showAlert(result.message || 'Refund recorded.');
                } catch (error) {
                    await showAlert(error.message);
                }
            });

            document.querySelector('#refundRows')?.addEventListener('click', async event => {
                const refundButton = event.target.closest('.refund-btn');
                if (refundButton) {
                    document.querySelector('#refundPaymentId').value = refundButton.dataset.refundPaymentId;
                    document.querySelector('#refundAmount').value = refundButton.dataset.refundAmount;
                    document.querySelector('#refundAmount').max = refundButton.dataset.refundAmount;
                    document.querySelector('#refundReference').value = '';
                    document.querySelector('#refundProof').value = '';
                    document.querySelector('#refundOriginalAmount').textContent = `${refundButton.dataset.refundCustomer} · Original payment: ₱${Number(refundButton.dataset.refundAmount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}. Enter the actual full or partial refund, the refund reference, and upload its receipt.`;
                    refundModal.classList.add('open');
                    return;
                }

                const proofButton = event.target.closest('.view-refund-proof-btn');
                if (!proofButton) return;
                receiptBody.innerHTML = `
                    <div class="receipt-meta">
                        <div class="receipt-meta-item"><span>Customer</span><strong>${escapeHtml(proofButton.dataset.refundCustomer)}</strong></div>
                        <div class="receipt-meta-item"><span>Refund reference</span><strong>${escapeHtml(proofButton.dataset.refundReference)}</strong></div>
                    </div>
                    <div class="receipt-image-box">
                        <img src="${escapeHtml(proofButton.dataset.refundProof)}" alt="Refund receipt" onerror="this.replaceWith(Object.assign(document.createElement('p'), { className: 'dialog-message', textContent: 'The refund receipt could not be found.' }))">
                    </div>
                `;
                document.querySelector('#receiptModalTitle').textContent = 'Refund Receipt';
                receiptModal.classList.add('open');
            });

            const handleBookingTableClick = async (e) => {
                const viewBtn = e.target.closest('.view-receipt-btn');
                const completionProofBtn = e.target.closest('.completion-proof-btn');
                const completionVerifyBtn = e.target.closest('.verify-completion-btn');
                const extensionProofBtn = e.target.closest('.extension-proof-btn');
                const reviewExtensionBtn = e.target.closest('.review-extension-btn');
                const verifyExtensionPaymentBtn = e.target.closest('.verify-extension-payment-btn');
                const rejectExtensionPaymentBtn = e.target.closest('.reject-extension-payment-btn');
                const bookingReceiptBtn = e.target.closest('.booking-receipt-btn');
                const verifyBtn = e.target.closest('.verify-pay-btn');

                if (extensionProofBtn) {
                    document.querySelector('#receiptModalTitle').textContent = 'Extension payment proof';
                    receiptBody.innerHTML = `<div class="receipt-meta"><div class="receipt-meta-item"><span>Customer</span><strong>${escapeHtml(extensionProofBtn.dataset.extensionCustomer)}</strong></div></div><div class="receipt-image-box"><img src="${escapeHtml(extensionProofBtn.dataset.extensionProof)}" alt="Extension payment proof" onerror="this.replaceWith(Object.assign(document.createElement('p'), { className: 'dialog-message', textContent: 'The payment proof could not be found.' }))"></div>`;
                    receiptModal.classList.add('open');
                } else if (reviewExtensionBtn) {
                    const row = reviewExtensionBtn.closest('tr');
                    const amountInput = row?.querySelector('.extension-amount-input');
                    const status = reviewExtensionBtn.dataset.extensionStatus;
                    if (!await showConfirm(status === 'approved' ? 'Approve this extension request?' : 'Reject this extension request?')) return;
                    reviewExtensionBtn.disabled = true;
                    try {
                        const result = await apiRequest({ type: 'review_extension', extension_id: Number(reviewExtensionBtn.dataset.extensionId), status, approved_amount: Number(amountInput?.value || 0) });
                        await loadDestinations();
                        const notificationNote = status === 'approved' && !result.notification_sent ? ' Email notification was not sent; check the mail configuration.' : '';
                        await showAlert((status === 'approved' ? 'Extension approved for payment.' : 'Extension request rejected.') + notificationNote);
                    } catch (err) { reviewExtensionBtn.disabled = false; await showAlert(err.message); }
                } else if (verifyExtensionPaymentBtn || rejectExtensionPaymentBtn) {
                    const paymentButton = verifyExtensionPaymentBtn || rejectExtensionPaymentBtn;
                    const status = verifyExtensionPaymentBtn ? 'verified' : 'rejected';
                    if (!await showConfirm(status === 'verified' ? 'Verify this extension payment?' : 'Reject this extension payment?')) return;
                    try {
                        const response = await fetch('../api/admin-content.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'verify_payment', id: paymentButton.dataset.paymentId, status }) });
                        const result = await response.json();
                        if (!response.ok) throw new Error(result.error || 'Unable to update extension payment.');
                        await loadDestinations();
                        const notificationNote = status === 'verified' && !result.notification_sent ? ' Email notification was not sent; check the mail configuration.' : '';
                        await showAlert((result.message || 'Extension payment updated.') + notificationNote);
                    } catch (err) { await showAlert(err.message); }
                } else if (completionProofBtn) {
                    document.querySelector('#receiptModalTitle').textContent = 'Trip completion proof';
                    receiptBody.innerHTML = `<div class="receipt-meta"><div class="receipt-meta-item"><span>Customer</span><strong>${escapeHtml(completionProofBtn.dataset.completionCustomer)}</strong></div></div><div class="receipt-image-box"><img src="${escapeHtml(completionProofBtn.dataset.completionProof)}" alt="Driver trip completion proof" onerror="this.replaceWith(Object.assign(document.createElement('p'), { className: 'dialog-message', textContent: 'The completion photo could not be found.' }))"></div>`;
                    receiptModal.classList.add('open');
                } else if (completionVerifyBtn) {
                    const action = completionVerifyBtn.dataset.completionStatus;
                    if (!await showConfirm(action === 'verified' ? 'Verify this trip as completed?' : 'Reject this completion proof?')) return;
                    completionVerifyBtn.disabled = true;
                    try {
                        await apiRequest({ type: 'verify_completion', booking_id: Number(completionVerifyBtn.dataset.bookingId), status: action });
                        await loadDestinations();
                        await showAlert(action === 'verified' ? 'Trip marked as completed.' : 'Completion proof rejected.');
                    } catch (err) {
                        completionVerifyBtn.disabled = false;
                        await showAlert(err.message);
                    }
                } else if (bookingReceiptBtn) {
                    document.querySelector('#receiptModalTitle').textContent = 'Booking Receipt';
                    receiptBody.innerHTML = `
                        <div class="receipt-meta">
                            <div class="receipt-meta-item"><span>Receipt number</span><strong>MTR-${escapeHtml(bookingReceiptBtn.dataset.bookingId)}</strong></div>
                            <div class="receipt-meta-item"><span>Customer</span><strong>${escapeHtml(bookingReceiptBtn.dataset.bookingCustomer)}</strong></div>
                            <div class="receipt-meta-item"><span>Trip</span><strong>${escapeHtml(bookingReceiptBtn.dataset.bookingPackage)}</strong></div>
                            <div class="receipt-meta-item"><span>Travel date</span><strong>${escapeHtml(bookingReceiptBtn.dataset.bookingDate)}</strong></div>
                            <div class="receipt-meta-item"><span>Guests</span><strong>${escapeHtml(bookingReceiptBtn.dataset.bookingGuests)} traveler(s)</strong></div>
                            <div class="receipt-meta-item"><span>Total paid</span><strong>${escapeHtml(bookingReceiptBtn.dataset.bookingTotal)}</strong></div>
                        </div>
                        <div class="receipt-paid" style="margin-top:14px;padding:11px;border-radius:8px;background:#ecfdf5;color:#047857;font-size:12px;font-weight:800;text-align:center">Payment confirmed by admin</div>
                    `;
                    receiptModal.classList.add('open');
                } else if (viewBtn) {
                    const file = viewBtn.dataset.receipt;
                    const ref = viewBtn.dataset.ref;
                    const name = viewBtn.dataset.name;
                    document.querySelector('#receiptModalTitle').textContent = 'Customer Payment Receipt';
                    receiptBody.innerHTML = `
                        <div class="receipt-meta">
                            <div class="receipt-meta-item">
                                <span>Customer</span>
                                <strong>${escapeHtml(name)}</strong>
                            </div>
                            <div class="receipt-meta-item">
                                <span>Reference</span>
                                <strong>${escapeHtml(ref)}</strong>
                            </div>
                        </div>
                        <div class="receipt-image-box">
                            <img src="${escapeHtml(file)}" alt="Customer payment receipt" onerror="this.replaceWith(Object.assign(document.createElement('p'), { className: 'dialog-message', textContent: 'The receipt file could not be found.' }))">
                        </div>
                    `;
                    receiptModal.classList.add('open');
                } else if (verifyBtn) {
                    const paymentId = verifyBtn.dataset.paymentId;
                    const confirmed = await showConfirm('Verify this customer payment? Any requested vehicle will be assigned with its associated driver.');
                    if (confirmed) {
                        try {
                            const res = await fetch('../api/admin-content.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ type: 'verify_payment', id: paymentId, status: 'verified' })
                            });
                            const result = await res.json();
                            await loadDestinations();
                            const notificationNote = !result.notification_sent ? ' Email notification was not sent; check the mail configuration.' : '';
                            await showAlert((result.message || 'Payment verified and plan approved.') + notificationNote);
                        } catch (err) {
                            await showAlert(err.message);
                        }
                    }
                } else if (e.target.closest('.reject-pay-btn')) {
                    const rejectButton = e.target.closest('.reject-pay-btn');
                    const confirmed = await showConfirm('Reject this customer payment and cancel the booking?');
                    if (!confirmed) return;
                    try {
                        const res = await fetch('../api/admin-content.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'verify_payment', id: rejectButton.dataset.paymentId, status: 'rejected' }) });
                        const result = await res.json();
                        if (!res.ok) throw new Error(result.error || 'Unable to reject payment.');
                        await loadDestinations();
                        await showAlert(result.message || 'Payment rejected.');
                    } catch (err) {
                        await showAlert(err.message);
                    }
                }
            };

            document.querySelector('#cancellationRows')?.addEventListener('click', async event => {
                const reviewButton = event.target.closest('.review-cancellation-btn');
                if (!reviewButton) return;
                const approved = reviewButton.dataset.cancellationStatus === 'approved';
                if (!await showConfirm(approved ? 'Approve this cancellation request? The booking will be cancelled.' : 'Reject this cancellation request?')) return;
                reviewButton.disabled = true;
                try {
                    const response = await fetch('../api/admin-content.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ type: 'review_cancellation', id: reviewButton.dataset.cancellationId, status: reviewButton.dataset.cancellationStatus })
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Unable to review cancellation request.');
                    await loadDestinations();
                    await showAlert(result.message || 'Cancellation request reviewed.');
                } catch (error) {
                    reviewButton.disabled = false;
                    await showAlert(error.message);
                }
            });
            document.querySelector('#bookingRows')?.addEventListener('click', handleBookingTableClick);
            document.querySelector('#customBookingRows')?.addEventListener('click', handleBookingTableClick);
            document.querySelector('#extensionRows')?.addEventListener('click', handleBookingTableClick);
            document.querySelector('#completionRows')?.addEventListener('click', handleBookingTableClick);
        })();
    </script>
</body>
</html>