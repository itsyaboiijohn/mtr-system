<?php
require_once __DIR__ . '/../api/auth.php';
$userId = require_user_auth();
$database = require_once __DIR__ . '/../api/db_con.php';

$packageColumns = $database->query('SHOW COLUMNS FROM travel_packages')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('visibility_days', $packageColumns, true)) {
  $database->exec("ALTER TABLE travel_packages ADD COLUMN visibility_days SMALLINT UNSIGNED NULL AFTER status");
}
if (!in_array('published_at', $packageColumns, true)) {
  $database->exec("ALTER TABLE travel_packages ADD COLUMN published_at DATETIME NULL AFTER visibility_days");
}
if (!in_array('visibility_minutes', $packageColumns, true)) {
  $database->exec("ALTER TABLE travel_packages ADD COLUMN visibility_minutes BIGINT UNSIGNED NULL AFTER visibility_days");
  $database->exec("UPDATE travel_packages SET visibility_minutes = visibility_days * 1440 WHERE visibility_days IS NOT NULL");
}

// 1. Fetch Popular Travel Packages
$popularPackages = $database->query(
  "SELECT p.Travel_Pck_ID AS id, p.name, p.description, p.duration_days, p.price_per_person,
      p.image_url, d.image_url AS destination_image_url
   FROM travel_packages p
   LEFT JOIN destinations d ON d.Destination_ID = p.Destination_ID
   WHERE p.status = 'published'
     AND (p.visibility_minutes IS NULL OR p.published_at IS NULL OR DATE_ADD(p.published_at, INTERVAL p.visibility_minutes MINUTE) > NOW())
   ORDER BY p.created_at DESC"
)->fetchAll();

  $packageImageRows = $database->query(
    "SELECT p.Travel_Pck_ID AS package_id, COALESCE(di.image_path, d.image_url) AS image_path
     FROM travel_packages p
     INNER JOIN travel_package_destinations pd ON pd.Travel_Pck_ID = p.Travel_Pck_ID
     INNER JOIN destinations d ON d.Destination_ID = pd.Destination_ID
     LEFT JOIN destination_images di ON di.Destination_ID = d.Destination_ID
     WHERE p.status = 'published'
       AND (p.visibility_minutes IS NULL OR p.published_at IS NULL OR DATE_ADD(p.published_at, INTERVAL p.visibility_minutes MINUTE) > NOW())
     ORDER BY p.Travel_Pck_ID, di.Destination_Image_ID"
  )->fetchAll();
  $packageImageMap = [];
  foreach ($packageImageRows as $packageImageRow) {
    $imagePath = trim((string) $packageImageRow['image_path']);
    if ($imagePath !== '') {
      $packageImageMap[(int) $packageImageRow['package_id']][] = $imagePath;
    }
  }
  foreach ($popularPackages as &$popularPackage) {
    $packageId = (int) $popularPackage['id'];
    $packageImages = [];
    foreach ([$popularPackage['image_url'], $popularPackage['destination_image_url']] as $imagePath) {
      $imagePath = trim((string) $imagePath);
      if ($imagePath !== '' && !in_array($imagePath, $packageImages, true)) {
        $packageImages[] = $imagePath;
      }
    }
    foreach ($packageImageMap[$packageId] ?? [] as $imagePath) {
      if (!in_array($imagePath, $packageImages, true)) {
        $packageImages[] = $imagePath;
      }
    }
    $popularPackage['image_paths'] = $packageImages ?: ['../assets/logo/logo.png'];
  }
  unset($popularPackage);

// 2. Fetch Recommended Packages dynamically (2 random published packages)
$recommendedPackages = $database->query(
  "SELECT p.Travel_Pck_ID AS id, p.name, p.duration_days, p.description, p.price_per_person
   FROM travel_packages p
   WHERE p.status = 'published'
     AND (p.visibility_minutes IS NULL OR p.published_at IS NULL OR DATE_ADD(p.published_at, INTERVAL p.visibility_minutes MINUTE) > NOW())
   ORDER BY RAND()
   LIMIT 2"
)->fetchAll();

// 3. Fetch Real User Bookings from DB
$userBookings = [];
if ($userId) {
    $bookingStmt = $database->prepare(
      "SELECT b.Booking_ID AS id, b.travel_date, b.guest_count, b.total_amount, b.booking_status, b.payment_status,
              COALESCE(p.name, 'Custom Trip') AS package_name
       FROM bookings b
      LEFT JOIN travel_packages p ON p.Travel_Pck_ID = b.Travel_Pck_ID
       WHERE b.Customer_ID = ?
       ORDER BY b.travel_date DESC
       LIMIT 4"
    );
    $bookingStmt->execute([$userId]);
    $userBookings = $bookingStmt->fetchAll();
}

$upcomingTripsCount = count(array_filter($userBookings, static fn($b) => in_array($b['booking_status'], ['confirmed', 'pending'], true)));

// 4. Fetch Hotels from DB
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

$database->exec("CREATE TABLE IF NOT EXISTS hotel_images (
  Hotel_Image_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  Hotel_ID INT UNSIGNED NOT NULL,
  image_path VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hotel_image_hotel FOREIGN KEY (Hotel_ID) REFERENCES hotels(Hotel_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$hotelCount = (int) $database->query('SELECT COUNT(*) FROM hotels')->fetchColumn();
if ($hotelCount === 0) {
    $seedHotels = [
        ['Siargao Beach Resort', 'Siargao Island', 'Mindanao', 'Surf-ready beachfront resort with island tours, sunset dinners, and easy access to Cloud 9.', 3200.00, 'WiFi, Pool, Restaurant, Beachfront', 4.8, 'active'],
        ['Davao Garden Hotel', 'Davao City', 'Mindanao', 'Modern city stay with garden views, family rooms, and quick access to local food spots.', 2600.00, 'WiFi, Parking, Breakfast, Gym', 4.6, 'active'],
        ['Camiguin Highlands Lodge', 'Camiguin', 'Mindanao', 'A cozy mountainside retreat with hot spring access, nature trails, and scenic balconies.', 2800.00, 'WiFi, Hot Spring Access, Restaurant, View Deck', 4.7, 'active']
    ];

    $insertHotel = $database->prepare(
        'INSERT INTO hotels (name, location, region, description, price_per_night, amenities, rating, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($seedHotels as $hotel) {
        $insertHotel->execute($hotel);
    }
}

$hotels = $database->query(
    "SELECT Hotel_ID AS id, name, location, region, description, price_per_night, amenities, rating
     FROM hotels
     WHERE status = 'active'
     ORDER BY rating DESC, created_at DESC"
)->fetchAll();

$hotelImages = [];
$hotelImagesStmt = $database->query('SELECT Hotel_ID, image_path FROM hotel_images ORDER BY Hotel_ID ASC, Hotel_Image_ID ASC');
foreach ($hotelImagesStmt->fetchAll() as $hotelImage) {
  $hotelImages[(string) $hotelImage['Hotel_ID']][] = $hotelImage['image_path'];
}
foreach ($hotels as &$hotel) {
  $images = $hotelImages[(string) $hotel['id']] ?? [];
  $hotel['image_paths'] = array_values(array_filter(array_map(static function ($path) {
    $path = trim((string) $path);
    return $path !== '' && !preg_match('/^https?:\/\//i', $path) ? '../' . ltrim($path, '/') : $path;
  }, $images)));
}
unset($hotel);

$destinations = $database->query(
  "SELECT Destination_ID AS id, name, location, region, category, description, price, image_url
   FROM destinations
   WHERE status = 'active'
   ORDER BY name"
)->fetchAll();
$destinationImages = $database->query(
  'SELECT Destination_ID AS destination_id, image_path FROM destination_images ORDER BY Destination_ID, Destination_Image_ID'
)->fetchAll();
foreach ($destinations as &$destination) {
  $destination['image_paths'] = [];
  foreach ($destinationImages as $destinationImage) {
    if ((int) $destinationImage['destination_id'] === (int) $destination['id']) {
      $destination['image_paths'][] = $destinationImage['image_path'];
    }
  }
  if (!$destination['image_paths'] && $destination['image_url']) {
    $destination['image_paths'][] = $destination['image_url'];
  }
}
unset($destination);

$database->exec("CREATE TABLE IF NOT EXISTS reviews (
    Review_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NULL UNIQUE,
    full_name VARCHAR(160) NOT NULL,
    review_text TEXT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_user FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reviewText']) && $userId) {
    $reviewText = trim((string) ($_POST['reviewText'] ?? ''));
    $reviewRating = max(1, min(5, (int) ($_POST['rating'] ?? 5)));
    $reviewAuthor = trim((string) ($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Traveler'));

    if ($reviewText !== '') {
        $existingReview = $database->prepare('SELECT Review_ID FROM reviews WHERE User_ID = ? LIMIT 1');
        $existingReview->execute([$userId]);
        $currentReview = $existingReview->fetch();

        if ($currentReview) {
            $reviewUpdate = $database->prepare('UPDATE reviews SET full_name = ?, review_text = ?, rating = ? WHERE User_ID = ?');
            $reviewUpdate->execute([$reviewAuthor !== '' ? $reviewAuthor : 'Traveler', $reviewText, $reviewRating, $userId]);
        } else {
            $reviewInsert = $database->prepare(
                'INSERT INTO reviews (User_ID, full_name, review_text, rating) VALUES (?, ?, ?, ?)'
            );
            $reviewInsert->execute([$userId, $reviewAuthor !== '' ? $reviewAuthor : 'Traveler', $reviewText, $reviewRating]);
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$allReviews = $database->query(
    'SELECT Review_ID, User_ID, full_name, review_text, rating, created_at FROM reviews ORDER BY created_at DESC'
)->fetchAll();

$userReview = null;
if ($userId) {
    $userReviewStmt = $database->prepare('SELECT Review_ID, User_ID, full_name, review_text, rating, created_at FROM reviews WHERE User_ID = ? LIMIT 1');
    $userReviewStmt->execute([$userId]);
    $userReview = $userReviewStmt->fetch();
}

$otherReviews = $allReviews;
if ($userReview) {
    $otherReviews = array_values(array_filter($allReviews, static fn($review) => (int) $review['User_ID'] !== (int) $userReview['User_ID']));
} elseif ($userId) {
    $otherReviews = array_values(array_filter($allReviews, static fn($review) => (int) $review['User_ID'] !== (int) $userId));
}

$averageReviewRating = 0;
if ($allReviews) {
    $averageReviewRating = array_reduce($allReviews, static fn($sum, $review) => $sum + (int) $review['rating'], 0) / count($allReviews);
}
$averageReviewLabel = number_format((float) $averageReviewRating, 1, '.', '');
$averageReviewStars = str_repeat('★', (int) round($averageReviewRating)) . str_repeat('☆', 5 - (int) round($averageReviewRating));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mindanao Travel & Tours — Your Dashboard</title>
    <style>
          :root {
            /* Sky blue & white palette */
            --bg: #F0FAFF; /* very light sky tint */
            --card: #FFFFFF; /* white cards */
            --muted: #6B7280; /* muted text */
            --accent: #0EA5E9; /* sky blue accent */
            --accent-dark: #0369A1; /* darker accent for hover */
            --primary: #0F1724; /* headings */
            --soft-accent: #E6F9FF;
            --shadow: 0 10px 30px rgba(2, 6, 23, 0.06);
            --radius: 12px;
            --max-width: 1200px;
            --gap: 20px;
            --transition: 260ms cubic-bezier(.2, .9, .2, 1);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
          }

          @media (prefers-reduced-motion: reduce) {
            :root {
              --transition: 0ms;
            }
          }

          html, body {
            height: 100%;
          }

          html {
            scroll-behavior: smooth;
          }

          body {
            margin: 0;
            background: var(--bg);
            color: var(--primary);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding: 0 0 36px;
            box-sizing: border-box;
            overflow-x: hidden;
          }

          /* Layout */
          .container {
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 32px 20px 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 340px);
            gap: var(--gap);
            align-items: start;
          }

          header.site-header {
            grid-column: 1 / -1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--gap);
            margin-bottom: 6px;
          }

          .brand {
            display: flex;
            align-items: center;
            gap: 14px;
          }

          .logo {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(14, 165, 233, 0.12);
            box-shadow: var(--shadow);
            overflow: hidden;
          }

          .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
          }

          .brand h1 {
            font-size: 18px;
            margin: 0;
          }

          .brand p {
            margin: 0;
            font-size: 12px;
            color: var(--muted);
          }

          nav.top-nav {
            display: flex;
            gap: 12px;
            align-items: center;
          }

          .search {
            display: flex;
            align-items: center;
            background: var(--card);
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid rgba(15, 23, 42, 0.04);
            box-shadow: 0 6px 14px rgba(2, 6, 23, 0.03);
          }

          .search input {
            border: 0;
            outline: none;
            background: transparent;
            width: 220px;
            font-size: 14px;
          }

          .cart-link {
            display: inline-flex;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(14, 165, 233, 0.16);
            border-radius: 10px;
            background: #fff;
            color: var(--accent-dark);
            text-decoration: none;
            transition: background var(--transition), color var(--transition), transform var(--transition);
          }

          .cart-link:hover {
            background: var(--accent);
            color: #fff;
            transform: translateY(-2px);
          }

          .cart-link svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.8;
          }

          .support-button {
            min-height: 40px;
            padding: 0 12px;
            border: 1px solid rgba(14, 165, 233, 0.16);
            border-radius: 10px;
            background: #fff;
            color: var(--accent-dark);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
          }

          .support-button:hover {
            background: var(--soft-accent);
          }

          .top-rating-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid rgba(14, 165, 233, 0.16);
            border-radius: 999px;
            background: linear-gradient(180deg, #ffffff, #f8fdff);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.08);
            color: var(--primary);
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
            color: var(--accent-dark);
          }

          .user {
            display: flex;
            align-items: center;
            gap: 10px;
          }

          .account-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 4px;
          }

          .account-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 12px;
            border: 1px solid rgba(14, 165, 233, 0.16);
            border-radius: 9px;
            background: #fff;
            color: var(--accent-dark);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
          }

          .account-link:hover {
            background: var(--soft-accent);
          }

          .account-link.logout {
            border-color: transparent;
            background: var(--accent);
            color: #fff;
          }

          .account-link.logout:hover {
            background: var(--accent-dark);
          }

          .avatar {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, #E6F9FF, #FFFFFF);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(14, 165, 233, 0.12);
          }

          .support-modal {
            position: fixed;
            z-index: 20;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 20px;
            background: rgba(15, 23, 36, 0.45);
          }

          .support-modal[hidden] { display: none; }

          .support-card {
            width: min(420px, 100%);
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 25px 70px rgba(2, 6, 23, 0.2);
          }

          .support-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
          }

          .support-heading h2 { margin: 0; font-size: 20px; }
          .support-heading p { margin: 6px 0 0; color: var(--muted); font-size: 12px; line-height: 1.5; }

          .support-close {
            width: 30px;
            height: 30px;
            border: 0;
            border-radius: 50%;
            background: var(--soft-accent);
            color: var(--accent-dark);
            font-size: 20px;
            line-height: 1;
          }

          .support-options { display: grid; gap: 9px; margin-top: 20px; }

          .support-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px;
            border: 1px solid var(--line);
            border-radius: 9px;
            color: var(--accent-dark);
            font-size: 13px;
            font-weight: 700;
          }

          .support-option:hover { border-color: var(--accent); background: var(--soft-accent); }
          .support-option small { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; font-weight: 400; }

          /* Hero */
          .hero {
            grid-column: 1 / -1;
            background: linear-gradient(180deg, var(--card), #fbfdff);
            padding: 22px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(14, 165, 233, 0.07);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: var(--gap);
            transform-origin: center;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity var(--transition) ease, transform var(--transition) ease;
          }

          .hero,
          .package,
          .panel {
            opacity: 1;
            transform: none;
          }

          .hero.revealed {
            opacity: 1;
            transform: none;
          }

          .hero .welcome {
            max-width: 720px;
          }

          .hero h2 {
            margin: 0 0 6px 0;
            font-size: 22px;
          }

          .hero p {
            margin: 0;
            color: var(--muted);
          }

          .hero .cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--accent);
            color: #fff;
            padding: 10px 16px;
            border-radius: 10px;
            border: 0;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: background var(--transition);
          }

          .hero .cta:hover {
            background: var(--accent-dark);
          }

          /* Packages */
          .packages {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--gap);
            margin-bottom: var(--gap);
          }

          .main {
            min-width: 0;
          }

          .main::before {
            content: "Popular travel packages";
            display: block;
            margin: 2px 0 14px;
            color: var(--primary);
            font-size: 20px;
            font-weight: 800;
          }

          .package {
            background: var(--card);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(2, 6, 23, 0.04);

          .packages-empty {
            grid-column: 1 / -1;
            min-height: 230px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
            border: 1px dashed rgba(3, 105, 161, 0.22);
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.6);
            color: var(--muted);
            text-align: center;
          }
            box-shadow: 0 8px 20px rgba(2, 6, 23, 0.04);
            display: flex;
            flex-direction: column;
            gap: 12px;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity var(--transition) ease, transform var(--transition) ease;
          }

          .package {
            opacity: 1;
            transform: none;
          }

          .package.revealed {
            opacity: 1;
            transform: none;
          }

          .package-gallery {
            position: relative;
            height: 170px;
            margin: -16px -16px 2px;
            overflow: hidden;
            border-radius: 12px 12px 0 0;
            background: var(--soft-accent);
          }

          .package-gallery .gallery-slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            animation: packageGallery 12s infinite;
          }

          body.js-enabled .package-gallery .gallery-slide {
            animation: none;
            transition: opacity 500ms ease;
          }

          body.js-enabled .package-gallery .gallery-slide.active,
          body.js-enabled .package-gallery .gallery-slide.is-active {
            opacity: 1;
          }

          .package-gallery .gallery-slide:nth-child(1) {
            animation-delay: 0s;
          }

          .package-gallery .gallery-slide:nth-child(2) {
            animation-delay: 4s;
          }

          .package-gallery .gallery-slide:nth-child(3) {
            animation-delay: 8s;
          }

          @keyframes packageGallery {
            0%, 30% { opacity: 1; }
            34%, 100% { opacity: 0; }
          }

          @media (prefers-reduced-motion: reduce) {
            .package-gallery .gallery-slide {
              animation: none;
            }

            .package-gallery .gallery-slide:first-child {
              opacity: 1;
            }
          }

          .package-gallery::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(2, 6, 23, 0.03), rgba(2, 6, 23, 0.28));
            pointer-events: none;
          }

          .gallery-dots {
            position: absolute;
            left: 50%;
            bottom: 10px;
            z-index: 3;
            display: flex;
            gap: 6px;
            transform: translateX(-50%);
          }

          .gallery-dot {
            width: 7px;
            height: 7px;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            cursor: pointer;
          }

          .gallery-dot.active {
            background: #fff;
          }

          .gallery-control {
            position: absolute;
            top: 50%;
            z-index: 3;
            width: 30px;
            height: 30px;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.85);
            color: var(--accent-dark);
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            opacity: 0;
            transform: translateY(-50%);
            transition: opacity 180ms ease, background 180ms ease;
          }

          .package-gallery:hover .gallery-control,
          .package-gallery:focus-within .gallery-control {
            opacity: 1;
          }

          .gallery-control:hover {
            background: #fff;
          }

          .gallery-control.previous {
            left: 10px;
          }

          .gallery-control.next {
            right: 10px;
          }

          .package h3 {
            margin: 0;
            font-size: 16px;
          }

          .package p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
          }

          .price {
            font-weight: 700;
            font-size: 18px;
          }

          .pkg-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
          }

          .pkg-actions .btn {
            flex: 1;
            min-height: 44px;
            padding: 10px 16px;
            border: 0;
            border-radius: 9px;
            background: var(--accent);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .01em;
          }

          .pkg-actions .btn:hover {
            background: var(--accent-dark);
            box-shadow: 0 7px 16px rgba(14, 165, 233, 0.2);
          }

          .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid rgba(2, 6, 23, 0.04);
            background: transparent;
            color: inherit;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: transform var(--transition), box-shadow var(--transition);
          }

          .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.08);
          }

          .btn.primary {
            background: var(--accent);
            color: #fff;
            border: 0;
          }

          .btn.primary:hover {
            background: var(--accent-dark);
          }

          /* Dashboard sections */
          .main {
            grid-column: 1;
            min-width: 0;
          }

          .main::before {
            content: "Popular travel packages";
            display: block;
            margin: 2px 0 14px;
            color: var(--primary);
            font-size: 20px;
            font-weight: 800;
          }

          .right-col {
            grid-column: 2;
            display: flex;
            flex-direction: column;
            gap: var(--gap);
            min-width: 0;
            align-self: start;
          }

          .panel {
            background: var(--card);
            padding: 16px;
            border: 1px solid rgba(2, 6, 23, 0.04);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
          }

          .panel h3 {
            margin: 0 0 8px;
          }

          .sidebar-feature {
            overflow: hidden;
            padding: 0;
          }

          .sidebar-feature-image {
            height: 150px;
            background-position: center;
            background-size: cover;
          }

          .sidebar-feature-copy {
            padding: 16px;
          }

          .sidebar-feature-copy h3 {
            margin-bottom: 5px;
          }

          .sidebar-feature-copy p {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
          }

          .sidebar-feature-copy .btn {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
          }

          .trip-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 14px;
          }

          .trip-stat {
            padding: 12px;
            border-radius: 10px;
            background: var(--soft-accent);
          }

          .trip-stat strong {
            display: block;
            font-size: 20px;
          }

          .trip-stat span {
            color: var(--muted);
            font-size: 12px;
          }

          .planning-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
          }

          .planning-item {
            display: flex;
            align-items: center;
            gap: 9px;
            color: #475569;
            font-size: 13px;
          }

          .planning-item::before {
            content: "✓";
            display: grid;
            width: 22px;
            height: 22px;
            flex: 0 0 22px;
            place-items: center;
            border-radius: 50%;
            background: var(--soft-accent);
            color: var(--accent-dark);
            font-weight: 800;
          }

          .tourist-spots {
            margin-top: var(--gap);
          }

          .tourist-spots-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
          }

          .tourist-spots-header h3 {
            margin-bottom: 4px;
          }

          .tourist-spots-header p {
            margin: 0;
          }

          .tourist-spots-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: var(--gap);
          }

          .tourist-spot {
            overflow: hidden;
            border: 1px solid rgba(2, 6, 23, 0.04);
            border-radius: var(--radius);
            background: var(--card);
            box-shadow: var(--shadow);
          }

          .tourist-spot-image {
            height: 104px;
            background-position: center;
            background-size: cover;
          }

          .tourist-spot-carousel {
            position: relative;
            height: 170px;
            overflow: hidden;
            background: var(--soft-accent);
          }

          .tourist-spot-carousel-image {
            position: absolute;
            inset: 0;
            background-position: center;
            background-size: cover;
            opacity: 0;
            transition: opacity 220ms ease;
          }

          .tourist-spot-carousel-image.active {
            opacity: 1;
          }

          .tourist-spot-carousel-dots {
            position: absolute;
            right: 0;
            bottom: 8px;
            left: 0;
            z-index: 2;
            display: flex;
            justify-content: center;
            gap: 4px;
          }

          .tourist-spot-carousel-dot {
            width: 6px;
            height: 6px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, .65);
          }

          .tourist-spot-carousel-dot.active {
            background: #fff;
          }

          .tourist-spot-copy {
            padding: 11px;
          }

          .tourist-spot-copy strong {
            display: block;
            margin-bottom: 3px;
            font-size: 14px;
          }

          .tourist-spot-copy span {
            color: var(--muted);
            font-size: 12px;
          }

          .destination-detail-modal { position: fixed; inset: 0; z-index: 30; display: grid; place-items: center; padding: 20px; background: rgba(15, 23, 36, .55); }
          .destination-detail-modal[hidden] { display: none; }
          .destination-detail-card { width: min(620px, 100%); max-height: 90vh; overflow: auto; border-radius: 14px; background: #fff; box-shadow: 0 25px 70px rgba(2, 6, 23, .25); }
          .destination-detail-gallery { position: relative; height: 300px; background: var(--soft-accent); }
          .destination-detail-image { position: absolute; inset: 0; background-position: center; background-size: cover; opacity: 0; transition: opacity 220ms ease; }
          .destination-detail-image.active { opacity: 1; }
          .destination-detail-control { position: absolute; top: 50%; z-index: 2; width: 36px; height: 36px; border: 0; border-radius: 50%; background: rgba(255,255,255,.9); color: var(--accent-dark); transform: translateY(-50%); }
          .destination-detail-control.previous { left: 14px; }
          .destination-detail-control.next { right: 14px; }
          .destination-detail-copy { padding: 22px; }
          .destination-detail-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
          .destination-detail-heading h2 { margin: 0; font-size: 24px; }
          .destination-detail-close { width: 32px; height: 32px; border: 0; border-radius: 50%; background: var(--soft-accent); color: var(--accent-dark); font-size: 20px; }
          .destination-detail-price { margin: 12px 0 0; color: var(--accent-dark); font-size: 20px; font-weight: 800; }
          .destination-detail-description { margin: 12px 0 0; color: var(--muted); line-height: 1.65; }
          .build-plan-button { display: inline-flex; width: 100%; min-height: 44px; align-items: center; justify-content: center; margin-top: 20px; border-radius: 8px; background: var(--accent); color: #fff; font-weight: 800; text-decoration: none; }
          .build-plan-button:hover { background: var(--accent-dark); }

          .hotels {
            margin-top: var(--gap);
          }

          .hotels-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
          }

          .hotels-header h3 {
            margin-bottom: 4px;
          }

          .hotels-header p {
            margin: 0;
          }

          .hotels-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: var(--gap);
          }

          .hotel {
            overflow: hidden;
            border: 1px solid rgba(2, 6, 23, 0.04);
            border-radius: var(--radius);
            background: var(--card);
            box-shadow: var(--shadow);
          }

          .hotel-image {
            height: 104px;
            background-position: center;
            background-size: cover;
          }

          .hotel-carousel {
            position: relative;
            height: 170px;
            overflow: hidden;
            background: var(--soft-accent);
          }

          .hotel-carousel-image {
            position: absolute;
            inset: 0;
            background-position: center;
            background-size: cover;
            opacity: 0;
            transition: opacity 220ms ease;
          }

          .hotel-carousel-image.active {
            opacity: 1;
          }

          .hotel-carousel-dots {
            position: absolute;
            right: 0;
            bottom: 8px;
            left: 0;
            z-index: 2;
            display: flex;
            justify-content: center;
            gap: 4px;
          }

          .hotel-carousel-dot {
            width: 6px;
            height: 6px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, .65);
          }

          .hotel-carousel-dot.active {
            background: #fff;
          }

          .hotel-copy {
            padding: 11px;
          }

          .hotel-copy strong {
            display: block;
            margin-bottom: 3px;
            font-size: 14px;
          }

          .hotel-copy span {
            color: var(--muted);
            font-size: 12px;
          }

          .hotel-price {
            color: var(--accent-dark);
            font-weight: 700;
            font-size: 13px;
            margin-top: 4px;
          }

          .hotel-rating {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #f59e0b;
            font-size: 12px;
            font-weight: 700;
            margin-top: 4px;
          }

          @media (max-width: 640px) {
            .tourist-spots-header {
              align-items: flex-start;
              flex-direction: column;
            }

            .tourist-spots-header .btn {
              flex: 1;
              width: auto;
            }

            .tourist-spots-grid,
            .hotels-grid { grid-template-columns: 1fr; }
          }

          .reviews {
            grid-column: 1 / -1;
            margin-top: 4px;
          }

          .reviews-header {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 18px;
            margin-bottom: 14px;
          }

          .reviews-header h2 {
            margin: 0 0 5px;
            font-size: 22px;
          }

          .review-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--gap);
          }

          .review-card {
            background: var(--card);
            padding: 18px;
            border: 1px solid rgba(2, 6, 23, 0.04);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
          }

          .stars {
            color: #f59e0b;
            letter-spacing: 2px;
            font-size: 15px;
          }

          .review-card p {
            margin: 12px 0 16px;
            color: #475569;
            font-size: 14px;
            line-height: 1.6;
          }

          .review-author {
            display: flex;
            align-items: center;
            gap: 10px;
          }

          .review-avatar {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--soft-accent);
            color: var(--accent-dark);
            font-weight: 800;
          }

          .review-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            margin-top: var(--gap);
          }

          .review-rating-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
          }

          .review-rating-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.02em;
          }

          .review-rating {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            border: 1px solid rgba(14, 165, 233, 0.16);
            border-radius: 999px;
            background: linear-gradient(180deg, #ffffff, #f8fdff);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.08);
          }

          .star-button {
            border: none;
            background: transparent;
            color: #d1d5db;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            padding: 0;
            transition: transform 180ms ease, color 180ms ease, filter 180ms ease;
            text-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
          }

          .star-button:hover,
          .star-button.active {
            color: #fbbf24;
            transform: translateY(-1px) scale(1.08);
            filter: drop-shadow(0 6px 14px rgba(251, 191, 36, 0.35));
          }

          .review-form textarea {
            min-height: 48px;
            resize: vertical;
            padding: 12px;
            border: 1px solid rgba(2, 6, 23, 0.08);
            border-radius: 10px;
            font: inherit;
            outline: none;
          }

          .splash {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            overflow: hidden;
            color: #fff;
            background: #082f49;
          }

          .carousel,
          .slides,
          .slide {
            position: absolute;
            inset: 0;
          }

          .carousel {
            overflow: hidden;
          }

          .slide {
            background-position: center;
            background-size: cover;
            opacity: 0;
            transform: scale(1.04);
            transition: opacity 700ms ease, transform 6s ease;
          }

          .slide.active {
            opacity: 1;
            transform: scale(1);
          }

          .splash::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(3, 31, 52, .78), rgba(3, 31, 52, .25) 65%, rgba(3, 31, 52, .08));
            pointer-events: none;
          }

          .splash-inner {
            position: relative;
            z-index: 2;
            max-width: 560px;
            margin-left: clamp(24px, 8vw, 120px);
            padding: 32px 20px;
          }

          .splash h1 {
            margin: 0;
            font-size: clamp(36px, 5vw, 64px);
            line-height: 1.05;
            letter-spacing: -1.5px;
          }

          .splash p {
            margin: 18px 0 26px;
            color: rgba(255, 255, 255, .88);
            line-height: 1.65;
          }

          .start {
            display: inline-flex;
            padding: 12px 20px;
            border-radius: 12px;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            text-decoration: none;
          }

          .booking-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
          }

          .add-booking-button {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--accent-dark);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
          }

          .add-booking-button:hover {
            color: var(--accent);
          }

          .add-booking-button span {
            display: inline-grid;
            width: 20px;
            height: 20px;
            place-items: center;
            border: 1px solid rgba(14, 165, 233, 0.2);
            border-radius: 50%;
            font-size: 16px;
            line-height: 1;
          }

          .booking-list {
            display: grid;
            gap: 10px;
            margin: 14px 0 0;
            padding: 0;
            list-style: none;
          }

          .booking-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 11px;
            border: 1px solid rgba(2, 6, 23, 0.05);
            border-radius: 10px;
            background: var(--bg);
          }

          .booking-details {
            min-width: 0;
          }

          .booking-name {
            margin-bottom: 3px;
            font-size: 13px;
            font-weight: 700;
          }

          .booking-date {
            color: var(--muted);
            font-size: 11px;
          }

          .booking-status {
            display: inline-flex;
            margin-top: 7px;
            padding: 4px 7px;
            border-radius: 5px;
            background: var(--soft-accent);
            color: var(--accent-dark);
            font-size: 10px;
            font-weight: 700;
          }

          .booking-status.pending {
            background: #fff7ed;
            color: #c2410c;
          }

          .booking-view {
            flex: 0 0 auto;
            padding: 7px 9px;
            border: 1px solid rgba(14, 165, 233, 0.18);
            border-radius: 7px;
            color: var(--accent-dark);
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
          }

          .booking-view:hover {
            background: #fff;
          }

          .site-footer {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-top: 18px;
            padding-bottom: 28px;
            padding-bottom: calc(28px + env(safe-area-inset-bottom));
            color: var(--muted);
            font-size: 13px;
          }

          .social-links {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
          }

          .social-link {
            display: inline-flex;
            width: 38px;
            height: 38px;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(14, 165, 233, 0.16);
            border-radius: 50%;
            background: #fff;
            color: var(--accent-dark);
            transition: transform var(--transition), background var(--transition), color var(--transition);
          }

          .social-link:hover {
            background: var(--accent);
            color: #fff;
            transform: translateY(-3px);
          }

          .social-link svg {
            width: 19px;
            height: 19px;
            fill: currentColor;
          }

          .subtle {
            margin-top: 12px;
            color: rgba(255, 255, 255, .78);
            font-size: 13px;
          }

          .carousel-side {
            position: absolute;
            top: 50%;
            z-index: 3;
            width: 44px;
            height: 44px;
            border: 1px solid rgba(255, 255, 255, .7);
            border-radius: 50%;
            background: rgba(255, 255, 255, .85);
            cursor: pointer;
            transform: translateY(-50%);
          }

          .carousel-side.left { left: 24px; }
          .carousel-side.right { right: 24px; }

          .carousel-side::after {
            content: "";
            position: absolute;
            top: 14px;
            width: 10px;
            height: 10px;
            border-top: 2px solid var(--accent-dark);
            border-right: 2px solid var(--accent-dark);
          }

          .carousel-side.left::after {
            left: 17px;
            transform: rotate(-135deg);
          }

          .carousel-side.right::after {
            left: 14px;
            transform: rotate(45deg);
          }

          .carousel-controls {
            position: absolute;
            bottom: 24px;
            left: 50%;
            z-index: 3;
            display: flex;
            gap: 8px;
            transform: translateX(-50%);
          }

          .dot {
            width: 9px;
            height: 9px;
            padding: 0;
            border: 1px solid #fff;
            border-radius: 50%;
            background: rgba(255, 255, 255, .55);
            cursor: pointer;
          }

          .dot.active {
            background: var(--accent);
          }

          .slide-progress {
            position: absolute;
            right: 24px;
            bottom: 28px;
            z-index: 3;
            width: min(220px, 28vw);
            height: 3px;
            overflow: hidden;
            border-radius: 99px;
            background: rgba(255, 255, 255, .35);
          }

          .slide-progress span {
            display: block;
            width: 0;
            height: 100%;
            background: #fff;
            transition: width 4.8s linear;
          }

          .overlay {
            display: none;
          }

          @media (max-width: 980px) {
            .container {
              grid-template-columns: 1fr;
            }

            .main,
            .right-col {
              grid-column: 1;
            }

            .right-col {
              order: 2;
              position: static;
            }

            .packages {
              grid-template-columns: repeat(2, 1fr);
            }

            .tourist-spots-grid,
            .hotels-grid {
              grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .review-grid {
              grid-template-columns: repeat(2, 1fr);
            }
          }

          @media (max-width: 640px) {
            .container {
              padding: 20px 14px 0;
            }

            header.site-header {
              align-items: flex-start;
              flex-direction: column;
            }

            nav.top-nav {
              width: 100%;
              justify-content: space-between;
              flex-wrap: wrap;
            }

            .search {
              flex: 1 1 220px;
            }

            .search input {
              width: 100%;
            }

            .account-actions {
              margin-left: 0;
            }

            .packages,
            .review-grid,
            .review-form {
              grid-template-columns: 1fr;
            }

            .hero {
              flex-direction: column;
              align-items: flex-start;
            }

            .hero .cta {
              width: 100%;
            }
          }

    </style>
</head>
<body>
    <!-- Intro splash shown on first view -->
    <div class="splash" id="splash">
        <div class="carousel" id="carousel" aria-hidden="true">
            <div class="slides">
                <div class="slide" style="background-image:url('../assets/img/0a5e7b3a23be878e1242105f1224e8b0.jpg')" data-index="0"></div>
                <div class="slide" style="background-image:url('../assets/img/1f807e6640089e9039b10d271d31830d.jpg')" data-index="1"></div>
                <div class="slide" style="background-image:url('../assets/img/74e7388a0df1ab4ff693cbc09192d093.jpg')" data-index="2"></div>
                <div class="slide" style="background-image:url('../assets/img/7d8da0f913d201dff511dced50cf24b6.jpg')" data-index="3"></div>
                <div class="slide" style="background-image:url('../assets/img/92f17b2c38d882abb2f0f5fe8fe61f48.jpg')" data-index="4"></div>
                <div class="slide" style="background-image:url('../assets/img/98cb3091f8b2235d557c61dd1194dab1.jpg')" data-index="5"></div>
                <div class="slide" style="background-image:url('../assets/img/aea0e5e80949850f0bfabafe77cdc087.jpg')" data-index="6"></div>
                <div class="slide" style="background-image:url('../assets/img/download (3).jpg')" data-index="7"></div>
            </div>
            <div class="carousel-side left" id="carouselPrev" aria-hidden="true"></div>
            <div class="carousel-side right" id="carouselNext" aria-hidden="true"></div>
            <div class="carousel-controls" id="carouselDots" aria-hidden="true"></div>
            <div class="slide-progress" aria-hidden="true"><span id="slideProgress"></span></div>
        </div>

        <div class="splash-inner" role="dialog" aria-modal="true" aria-labelledby="splashTitle">
            <h1 id="splashTitle">Explore Mindanao — Islands, Culture, Adventure</h1>
            <p class="muted">Discover curated packages for beaches, volcanoes, and city escapes. Start planning a trip tailored to you — choose a package or customize your own.</p>
            <a class="start" href="#packages">Start Planning</a>
            <div class="subtle">No account required to explore — personalized options available after login.</div>
        </div>

        <div class="scroll-progress" aria-hidden="true"><span id="scrollProgress"></span></div>
    </div>

    <div class="container" role="main" id="mainContainer">
        <header class="site-header">
            <div class="brand">
                <div class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></div>
                <div>
                    <h1>Mindanao Travel & Tours</h1>
                    <p>Your dashboard — plan your Mindanao trip</p>
                </div>
            </div>
            <nav class="top-nav" aria-label="Top navigation">
                <div class="search" role="search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 21l-4.35-4.35" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="11" cy="11" r="6" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <input id="search" type="search" placeholder="Search destinations, packages..." aria-label="Search packages">
                </div>
                <div class="top-rating-pill" aria-label="Website rating">
                    <span class="top-rating-stars"><?= htmlspecialchars($averageReviewStars ?: '☆☆☆☆☆', ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="top-rating-score"><?= htmlspecialchars($averageReviewLabel ?: '0.0', ENT_QUOTES, 'UTF-8') ?>/5</span>
                </div>
                <button class="support-button" id="supportButton" type="button">Customer service</button>
                <a class="cart-link" href="cart.php" aria-label="Open trip cart" title="Trip cart">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H6"/><circle cx="10" cy="19" r="1.2"/><circle cx="17" cy="19" r="1.2"/></svg>
                </a>
                <div class="user" id="profile">
                    
                    <div class="account-actions" aria-label="Account actions">
                        <a class="account-link" href="profile.php">Profile</a>
                        <a class="account-link logout" href="logout.php">Logout</a>
                    </div>
                </div>
            </nav>
        </header>

        <div class="hero">
            <div class="welcome">
                <h2>Discover curated packages across Mindanao</h2>
                <p class="muted">Choose a ready-made plan or customize your own itinerary for islands, culture, and adventure. Simple, flexible, and tailored for you.</p>
            </div>
            
        </div>

        <section class="main">
            <div class="packages" id="packages" aria-label="Available packages">
            <?php foreach ($popularPackages as $package):
              $description = trim((string) ($package['description'] ?: 'Explore Mindanao with a curated travel experience.'));
            ?>
            <article class="package" data-id="package-<?= (int) $package['id'] ?>">
              <div class="package-gallery" data-gallery>
                <?php foreach ($package['image_paths'] as $imageIndex => $imagePath):
                  $imageUrl = preg_match('/^https?:\/\//i', $imagePath) ? $imagePath : '../' . ltrim($imagePath, '/');
                ?>
                <div class="gallery-slide<?= $imageIndex === 0 ? ' active' : '' ?>" style="background-image:url('<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>')"></div>
                <?php endforeach; ?>
                <div class="gallery-dots"></div>
              </div>
              <h3><?= htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8') ?></h3>
              <p class="muted"><?= (int) $package['duration_days'] ?> days · <?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
              <div class="price">₱ <?= number_format((float) $package['price_per_person'], 2) ?></div>
              <div class="pkg-actions">
                <a class="btn" href="package.php?id=<?= (int) $package['id'] ?>">View</a>
              </div>
            </article>
            <?php endforeach; ?>

            <?php if (!$popularPackages): ?>
            <div class="packages-empty" role="status">
              No popular travel packages are available right now.
            </div>
            <?php endif; ?>

            <?php if (false): ?>
                

                


                <?php endif; ?>
            </div>

            <section class="panel tourist-spots" aria-labelledby="touristSpotsTitle">
                <div class="tourist-spots-header">
                  <div>
                    <h3 id="touristSpotsTitle">Tourist Spots in Mindanao</h3>
                    <p class="muted">Find your next island, mountain, or cultural escape.</p>
                  </div>
                </div>
                <div class="tourist-spots-grid" id="touristSpotsGrid"></div>
            </section>

            <section class="panel hotels" aria-labelledby="hotelsTitle">
                <div class="hotels-header">
                  <div>
                    <h3 id="hotelsTitle">Hotels in Mindanao</h3>
                    <p class="muted">Comfortable stays for your Mindanao adventure.</p>
                  </div>
                </div>
                <div class="hotels-grid" id="hotelsGrid">
                    <?php foreach ($hotels as $hotel): ?>
                    <article class="hotel" data-hotel-id="<?= htmlspecialchars($hotel['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="hotel-carousel">
                            <?php if (!empty($hotel['image_paths'])): ?>
                            <div class="hotel-carousel-image active" style="background-image: url('<?= htmlspecialchars($hotel['image_paths'][0], ENT_QUOTES, 'UTF-8') ?>')"></div>
                            <?php else: ?>
                            <div class="hotel-carousel-image active" style="background-image: url('https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=500&q=70')"></div>
                            <?php endif; ?>
                            <div class="hotel-carousel-dots">
                                <button class="hotel-carousel-dot active" type="button" aria-label="Hotel image 1"></button>
                            </div>
                        </div>
                        <div class="hotel-copy">
                            <strong><?= htmlspecialchars($hotel['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($hotel['location'], ENT_QUOTES, 'UTF-8') ?></span>
                            <div class="hotel-price">₱<?= number_format($hotel['price_per_night'], 2) ?> / night</div>
                            <?php if ($hotel['rating'] > 0): ?>
                            <div class="hotel-rating">★ <?= number_format($hotel['rating'], 1) ?></div>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

        </section>

        <aside class="right-col" aria-label="Sidebar widgets">
          <div class="panel sidebar-feature">
            <div class="sidebar-feature-image" style="background-image:url('../assets/logo/logo.png')"></div>
            <div class="sidebar-feature-copy">
              <h3>Make Your Next Escape</h3>
              <p>Turn your favorite Mindanao spots into one easy itinerary.</p>
              <a class="btn primary" href="region_map.php">Build My Plan</a>
            </div>
          </div>

            <div class="panel">
                <h3>Recommended</h3>
                <p class="muted">Based on your interests</p>
                <div style="margin-top:10px;display:flex;flex-direction:column;gap:10px">
                    <?php if ($recommendedPackages): ?>
                      <?php foreach ($recommendedPackages as $rec): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div>
                                <a href="package.php?id=<?= (int) $rec['id'] ?>" style="font-weight:600;text-decoration:none;color:inherit"><?= htmlspecialchars($rec['name'], ENT_QUOTES, 'UTF-8') ?></a>
                                <div class="muted"><?= (int) $rec['duration_days'] ?> days · explore</div>
                            </div>
                            <div class="muted">₱ <?= number_format((float) $rec['price_per_person'], 2) ?></div>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                        <div class="muted" style="font-size:12px">No recommendations right now.</div>
                    <?php endif; ?>
                </div>
            </div>

              <div class="panel">
                <div class="booking-heading">
                  <h3>Your Bookings</h3>
                  <a class="add-booking-button" href="booking.php"><span aria-hidden="true">→</span> View</a>
                </div>
                <p class="muted">Past and upcoming reservations</p>
                <ul class="booking-list">
                  <?php if ($userBookings): ?>
                    <?php foreach ($userBookings as $ub):
                      $statusClass = $ub['booking_status'] === 'pending' ? 'pending' : '';
                      $dateStr = date('M d', strtotime($ub['travel_date']));
                    ?>
                      <li class="booking-item">
                        <div class="booking-details">
                          <div class="booking-name"><?= htmlspecialchars($ub['package_name'], ENT_QUOTES, 'UTF-8') ?></div>
                          <div class="booking-date"><?= $dateStr ?> • <?= (int) $ub['guest_count'] ?> pax</div>
                          <span class="booking-status <?= $statusClass ?>"><?= ucfirst(htmlspecialchars($ub['booking_status'], ENT_QUOTES, 'UTF-8')) ?></span>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <li class="booking-item" style="justify-content:center;color:var(--muted);font-size:12px">
                      No bookings yet. <a href="region_map.php" style="margin-left:4px;color:var(--accent-dark);font-weight:700">Start planning</a>
                    </li>
                  <?php endif; ?>
                </ul>
              </div>

                <div class="panel">
                  <h3>Your Trip Snapshot</h3>
                  <p class="muted">Keep your travel ideas moving.</p>
                  <div class="trip-stats">
                    <div class="trip-stat"><strong id="upcomingTripsCount"><?= $upcomingTripsCount ?></strong><span>upcoming trips</span></div>
                  </div>
                </div>

                
        </aside>

        <section class="reviews" aria-labelledby="reviewsTitle">
            <div class="reviews-header">
                <div>
                    <h2 id="reviewsTitle">Loved by fellow travelers</h2>
                    <p class="muted">See what our guests say about their Mindanao experience.</p>
                </div>
                <div class="stars" aria-label="<?= htmlspecialchars($averageReviewLabel, ENT_QUOTES, 'UTF-8') ?> out of 5 stars">
                    <?= htmlspecialchars($averageReviewStars ?: '☆☆☆☆☆', ENT_QUOTES, 'UTF-8') ?>
                    <span class="muted"> <?= htmlspecialchars($averageReviewLabel ?: '0.0', ENT_QUOTES, 'UTF-8') ?>/5</span>
                </div>
            </div>
            <div class="review-grid" id="reviewGrid">
                <?php if ($userReview): ?>
                    <?php
                        $userReviewName = trim((string) $userReview['full_name']);
                        $userInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $userReviewName) ?: 'ME', 0, 2));
                        $userStars = str_repeat('★', (int) $userReview['rating']) . str_repeat('☆', 5 - (int) $userReview['rating']);
                    ?>
                    <article class="review-card">
                        <div class="stars" aria-label="<?= (int) $userReview['rating'] ?> out of 5 stars"><?= $userStars ?></div>
                        <p>“<?= htmlspecialchars($userReview['review_text'], ENT_QUOTES, 'UTF-8') ?>”</p>
                        <div class="review-author">
                            <div class="review-avatar"><?= htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8') ?></div>
                            <div>
                                <strong><?= htmlspecialchars($userReviewName ?: 'You', ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="muted">Your review</div>
                            </div>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if ($otherReviews): ?>
                    <?php foreach ($otherReviews as $review):
                        $reviewName = trim((string) $review['full_name']);
                        $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $reviewName) ?: 'TR', 0, 2));
                        $stars = str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']);
                    ?>
                        <article class="review-card">
                            <div class="stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars"><?= $stars ?></div>
                            <p>“<?= htmlspecialchars($review['review_text'], ENT_QUOTES, 'UTF-8') ?>”</p>
                            <div class="review-author">
                                <div class="review-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                                <div>
                                    <strong><?= htmlspecialchars($reviewName ?: 'Travel guest', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="muted">Guest review</div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!$userReview && !$otherReviews): ?>
                    <article class="review-card">
                        <div class="stars" aria-label="5 stars">★★★★★</div>
                        <p>“The booking experience was smooth and memorable. We loved how easy it was to plan and adjust our trip.”</p>
                        <div class="review-author"><div class="review-avatar">TR</div><div><strong>Travel guest</strong><div class="muted">First review</div></div></div>
                    </article>
                <?php endif; ?>
            </div>

            <form class="review-form" id="reviewForm" method="POST">
                <div style="grid-column: 1 / -1;">
                    <div class="review-rating-wrap">
                        <span class="review-rating-label"><?= $userReview ? 'Edit your review' : 'Rate your experience' ?></span>
                        <div class="review-rating" aria-label="Select rating">
                            <?php $existingRating = (int) ($userReview['rating'] ?? 5); ?>
                            <?php for ($star = 1; $star <= 5; $star++): ?>
                                <button class="star-button <?= $star <= $existingRating ? 'active' : '' ?>" type="button" data-value="<?= $star ?>" aria-label="Rate <?= $star ?> star<?= $star === 1 ? '' : 's' ?>">★</button>
                            <?php endfor; ?>
                            <input type="hidden" name="rating" id="reviewRatingInput" value="<?= $existingRating ?>">
                        </div>
                    </div>
                </div>
                <textarea id="reviewText" name="reviewText" placeholder="Share your experience with Mindanao Travel & Tours..." required><?= htmlspecialchars($userReview['review_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                <button class="btn primary" type="submit"><?= $userReview ? 'Update review' : 'Post review' ?></button>
            </form>
        </section>

        <footer class="site-footer">
          <div class="social-links" aria-label="Social media links">
            <a class="social-link" href="https://www.facebook.com/" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 21v-8h2.7l.4-3h-3.1V8.1c0-.9.3-1.5 1.6-1.5h1.7V4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.9 1.4-3.9 4V10H8v3h2.6v8h2.9Z"/></svg>
            </a>
            <a class="social-link" href="https://mail.google.com/mail/?view=cm&fs=1&to=bitlgdagret@gmail.com" target="_blank" rel="noopener noreferrer" aria-label="Gmail" title="Gmail">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5.5A2.5 2.5 0 0 1 5.5 3h13A2.5 2.5 0 0 1 21 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 18.5v-13Zm2.5.5a.5.5 0 0 0-.3.9l6.5 5.2a.5.5 0 0 0 .6 0l6.5-5.2a.5.5 0 0 0-.3-.9h-13Zm13.5 3.1-5.4 4.3a2.5 2.5 0 0 1-3.2 0L5 9.1v9.4c0 .3.2.5.5.5h13c.3 0 .5-.2.5-.5V9.1Z"/></svg>
            </a>
            <a class="social-link" href="https://www.instagram.com/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.2 3h9.6A4.2 4.2 0 0 1 21 7.2v9.6a4.2 4.2 0 0 1-4.2 4.2H7.2A4.2 4.2 0 0 1 3 16.8V7.2A4.2 4.2 0 0 1 7.2 3Zm0 2A2.2 2.2 0 0 0 5 7.2v9.6A2.2 2.2 0 0 0 7.2 19h9.6a2.2 2.2 0 0 0 2.2-2.2V7.2A2.2 2.2 0 0 0 16.8 5H7.2Zm4.8 2.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5Zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5Zm5.1-3.2a1.1 1.1 0 1 1-1.1 1.1 1.1 1.1 0 0 1 1.1-1.1Z"/></svg>
            </a>
            <a class="social-link" href="https://www.tiktok.com/" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.2 3h3a5.1 5.1 0 0 0 2.8 2.8v3a8 8 0 0 1-2.8-1v6.7a5.5 5.5 0 1 1-5.5-5.5c.4 0 .8 0 1.2.1v3.1a2.5 2.5 0 1 0 1.3 2.3V3Z"/></svg>
            </a>
          </div>
          <div>—— Designed for travelers ——</div>
        </footer>
    </div>

    <div class="support-modal" id="supportModal" hidden>
      <section class="support-card" role="dialog" aria-modal="true" aria-labelledby="supportTitle">
        <div class="support-heading">
          <div><h2 id="supportTitle">How can we help?</h2><p>Our travel team is ready to help with bookings, changes, and trip questions.</p></div>
          <button class="support-close" id="supportClose" type="button" aria-label="Close customer service">×</button>
        </div>
        <div class="support-options">
          <a class="support-option" href="service.php"><span>💬</span><span>Chat with support<small>Start a conversation with our travel team</small></span></a>
          <a class="support-option" href="https://mail.google.com/mail/?view=cm&fs=1&to=bitlgdagret@gmail.com&su=Booking%20support%20request" target="_blank" rel="noopener noreferrer"><span>✉</span><span>Email our team<small>bitlgdagret@gmail.com</small></span></a>
        </div>
      </section>
    </div>

    <div class="destination-detail-modal" id="destinationDetailModal" hidden>
      <section class="destination-detail-card" role="dialog" aria-modal="true" aria-labelledby="destinationDetailTitle">
        <div class="destination-detail-gallery" id="destinationDetailGallery"></div>
        <div class="destination-detail-copy">
          <div class="destination-detail-heading"><h2 id="destinationDetailTitle"></h2><button class="destination-detail-close" id="destinationDetailClose" type="button" aria-label="Close destination details">×</button></div>
          <div class="destination-detail-price" id="destinationDetailPrice"></div>
          <p class="destination-detail-description" id="destinationDetailDescription"></p>
          <a class="build-plan-button" href="region_map.php">Build my plan</a>
        </div>
      </section>
    </div>

    <div class="destination-detail-modal" id="hotelDetailModal" hidden>
      <section class="destination-detail-card" role="dialog" aria-modal="true" aria-labelledby="hotelDetailTitle">
        <div class="destination-detail-gallery" id="hotelDetailGallery"></div>
        <div class="destination-detail-copy">
          <div class="destination-detail-heading"><h2 id="hotelDetailTitle"></h2><button class="destination-detail-close" id="hotelDetailClose" type="button" aria-label="Close hotel details">×</button></div>
          <div class="destination-detail-price" id="hotelDetailPrice"></div>
          <p class="destination-detail-description" id="hotelDetailLocation"></p>
          <p class="destination-detail-description" id="hotelDetailDescription"></p>
          <p class="destination-detail-description" id="hotelDetailAmenities"></p>
          <a class="build-plan-button" href="region_map.php">Build my plan</a>
        </div>
      </section>
    </div>

    
    <script>
        (() => {
            const body = document.body;
            body.classList.add('js-enabled');

            const supportModal = document.querySelector('#supportModal');
            const supportButton = document.querySelector('#supportButton');
            const supportClose = document.querySelector('#supportClose');
            const closeSupport = () => { supportModal.hidden = true; };
            supportButton.addEventListener('click', () => { supportModal.hidden = false; supportClose.focus(); });
            supportClose.addEventListener('click', closeSupport);
            supportModal.addEventListener('click', (event) => { if (event.target === supportModal) closeSupport(); });
            document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeSupport(); });

            const galleries = document.querySelectorAll('[data-gallery]');
            galleries.forEach((gallery) => {
                const slides = Array.from(gallery.querySelectorAll('.gallery-slide'));
                if (slides.length < 2) return;

                let current = 0;
                const dots = gallery.querySelector('.gallery-dots');
                const controls = [
                    { className: 'previous', label: 'Previous image', symbol: '‹' },
                    { className: 'next', label: 'Next image', symbol: '›' }
                ];

                controls.forEach(({ className, label, symbol }) => {
                    const button = document.createElement('button');
                    button.className = `gallery-control ${className}`;
                    button.type = 'button';
                    button.setAttribute('aria-label', label);
                    button.textContent = symbol;
                    gallery.appendChild(button);
                    button.addEventListener('click', () => {
                        current = (current + (className === 'next' ? 1 : -1) + slides.length) % slides.length;
                        updateGallery();
                        restartGallery();
                    });
                });

                if (dots) {
                    dots.innerHTML = '';
                    slides.forEach((_, slideIndex) => {
                        const dot = document.createElement('button');
                        dot.className = 'gallery-dot';
                        dot.type = 'button';
                        dot.setAttribute('aria-label', `Show image ${slideIndex + 1}`);
                        dots.appendChild(dot);
                        dot.addEventListener('click', () => {
                            current = slideIndex;
                            updateGallery();
                            restartGallery();
                        });
                    });
                }

                const updateGallery = () => {
                    slides.forEach((slide, slideIndex) => {
                        slide.classList.toggle('is-active', slideIndex === current);
                    });
                    if (dots) {
                        dots.querySelectorAll('.gallery-dot').forEach((dot, dotIndex) => {
                            dot.classList.toggle('active', dotIndex === current);
                        });
                    }
                };

                const restartGallery = () => {
                    window.clearInterval(gallery.timer);
                    gallery.timer = window.setInterval(() => {
                        current = (current + 1) % slides.length;
                        updateGallery();
                    }, 4000);
                };

                updateGallery();
                restartGallery();
            });

            const slides = Array.from(document.querySelectorAll('.slide'));
            const dotsContainer = document.querySelector('#carouselDots');
            const previousButton = document.querySelector('#carouselPrev');
            const nextButton = document.querySelector('#carouselNext');
            const slideProgress = document.querySelector('#slideProgress');
            let activeSlide = 0;
            let carouselTimer;

            const showSlide = (index) => {
                activeSlide = (index + slides.length) % slides.length;
                slides.forEach((slide, slideIndex) => {
                    slide.classList.toggle('active', slideIndex === activeSlide);
                });
                if (dotsContainer) {
                    dotsContainer.querySelectorAll('.dot').forEach((dot, dotIndex) => {
                        dot.classList.toggle('active', dotIndex === activeSlide);
                    });
                }
                if (slideProgress) {
                    slideProgress.style.transition = 'none';
                    slideProgress.style.width = '0';
                    window.requestAnimationFrame(() => {
                        slideProgress.style.transition = 'width 4.8s linear';
                        slideProgress.style.width = '100%';
                    });
                }
            };

            if (slides.length) {
                slides.forEach((slide, slideIndex) => {
                    const dot = document.createElement('button');
                    dot.className = 'dot';
                    dot.type = 'button';
                    dot.setAttribute('aria-label', `Show slide ${slideIndex + 1}`);
                    dot.addEventListener('click', () => {
                        showSlide(slideIndex);
                        restartCarousel();
                    });
                    dotsContainer.appendChild(dot);
                });

                const restartCarousel = () => {
                    window.clearInterval(carouselTimer);
                    carouselTimer = window.setInterval(() => showSlide(activeSlide + 1), 5000);
                };

                previousButton.addEventListener('click', () => {
                    showSlide(activeSlide - 1);
                    restartCarousel();
                });
                nextButton.addEventListener('click', () => {
                    showSlide(activeSlide + 1);
                    restartCarousel();
                });
                showSlide(0);
                restartCarousel();
            }

            const reviewRatingInput = document.querySelector('#reviewRatingInput');
            const reviewStars = document.querySelectorAll('.star-button');
            if (reviewRatingInput && reviewStars.length) {
                const syncReviewStars = (value) => {
                    reviewStars.forEach((star) => {
                        const starValue = Number(star.dataset.value);
                        star.classList.toggle('active', starValue <= value);
                    });
                    reviewRatingInput.value = String(value);
                };

                reviewStars.forEach((star) => {
                    star.addEventListener('click', () => {
                        syncReviewStars(Number(star.dataset.value));
                    });
                });
            }

            const search = document.querySelector('#search');
            const packages = Array.from(document.querySelectorAll('.package'));
            if (search) {
                search.addEventListener('input', () => {
                    const query = search.value.trim().toLowerCase();
                    packages.forEach((packageCard) => {
                        const text = packageCard.textContent.toLowerCase();
                        packageCard.hidden = query !== '' && !text.includes(query);
                    });
                });
            }

              const touristSpots = document.querySelector('#touristSpotsGrid');
              const destinationDetailModal = document.querySelector('#destinationDetailModal');
              const destinationDetailGallery = document.querySelector('#destinationDetailGallery');
              const hotelsGrid = document.querySelector('#hotelsGrid');
              const hotelDetailModal = document.querySelector('#hotelDetailModal');
              const hotelDetailGallery = document.querySelector('#hotelDetailGallery');
              const hotelCache = <?= json_encode($hotels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              const showHotelDetails = (hotel) => {
                const imagePaths = hotel.image_paths.length ? hotel.image_paths : [''];
                const images = imagePaths.map((imagePath, index) => `<div class="destination-detail-image${index === 0 ? ' active' : ''}" data-hotel-image-index="${index}" style="background-image:url('${imagePath}')"></div>`).join('');
                hotelDetailGallery.innerHTML = `${images}<button class="destination-detail-control previous" type="button" aria-label="Previous hotel image">‹</button><button class="destination-detail-control next" type="button" aria-label="Next hotel image">›</button>`;
                document.querySelector('#hotelDetailTitle').textContent = hotel.name;
                document.querySelector('#hotelDetailPrice').textContent = `₱ ${Number(hotel.price_per_night).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} / night`;
                document.querySelector('#hotelDetailLocation').textContent = hotel.location || 'Mindanao';
                document.querySelector('#hotelDetailDescription').textContent = hotel.description || 'Comfortable accommodation for your stay.';
                document.querySelector('#hotelDetailAmenities').textContent = hotel.amenities ? `Amenities: ${hotel.amenities}` : 'Amenities: Contact the hotel for available facilities.';
                hotelDetailGallery.dataset.current = '0';
                hotelDetailModal.hidden = false;
              };

              if (hotelsGrid) {
                hotelsGrid.addEventListener('click', event => {
                  if (event.target.closest('.hotel-carousel-dot')) return;
                  const card = event.target.closest('.hotel');
                  const hotel = card && hotelCache.find(item => String(item.id) === card.dataset.hotelId);
                  if (hotel) showHotelDetails(hotel);
                });
              }
              let destinationCache = <?= json_encode($destinations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              const loadTouristSpots = () => {
                if (!touristSpots) return;
                touristSpots.innerHTML = '';
                destinationCache.forEach(destination => {
                  const imagePaths = destination.image_paths.length ? destination.image_paths : [''];
                  const images = imagePaths.map((imagePath, index) => `<div class="tourist-spot-carousel-image${index === 0 ? ' active' : ''}" style="background-image:url('../${imagePath}')"></div>`).join('');
                  const dots = imagePaths.map((_, index) => `<button class="tourist-spot-carousel-dot${index === 0 ? ' active' : ''}" type="button" data-carousel-index="${index}" aria-label="Show image ${index + 1}"></button>`).join('');
                  const card = document.createElement('article');
                  card.className = 'tourist-spot';
                  card.dataset.destinationId = destination.id;
                  const shortDescription = (destination.description || 'Mindanao destination').trim().split(/\s+/).slice(0, 10).join(' ');
                  card.innerHTML = `<div class="tourist-spot-carousel" data-tourist-carousel data-current="0">${images}<div class="tourist-spot-carousel-dots">${dots}</div></div><div class="tourist-spot-copy"><strong>${destination.name}</strong><span>${shortDescription} · ₱ ${Number(destination.price).toLocaleString('en-PH')}</span></div>`;
                  touristSpots.appendChild(card);
                });
              };

              loadTouristSpots();
              touristSpots.addEventListener('click', event => {
                const carousel = event.target.closest('[data-tourist-carousel]');
                if (!carousel) return;
                const images = carousel.querySelectorAll('.tourist-spot-carousel-image');
                if (images.length < 2) return;
                let current = Number(carousel.dataset.current || 0);
                if (event.target.closest('.next')) current = (current + 1) % images.length;
                if (event.target.closest('.previous')) current = (current - 1 + images.length) % images.length;
                const dot = event.target.closest('.tourist-spot-carousel-dot');
                if (dot) current = Number(dot.dataset.carouselIndex);
                carousel.dataset.current = current;
                images.forEach((image, index) => image.classList.toggle('active', index === current));
                carousel.querySelectorAll('.tourist-spot-carousel-dot').forEach((item, index) => item.classList.toggle('active', index === current));
              });

              const showDestinationDetails = (destination) => {
                const imagePaths = destination.image_paths.length ? destination.image_paths : [''];
                const images = imagePaths.map((imagePath, index) => `<div class="destination-detail-image${index === 0 ? ' active' : ''}" data-detail-image-index="${index}" style="background-image:url('../${imagePath}')"></div>`).join('');
                destinationDetailGallery.innerHTML = `${images}<button class="destination-detail-control previous" type="button" aria-label="Previous destination image">‹</button><button class="destination-detail-control next" type="button" aria-label="Next destination image">›</button>`;
                document.querySelector('#destinationDetailTitle').textContent = destination.name;
                document.querySelector('#destinationDetailPrice').textContent = `₱ ${Number(destination.price).toLocaleString('en-PH')}`;
                document.querySelector('#destinationDetailDescription').textContent = destination.description || 'No description available.';
                destinationDetailGallery.dataset.current = '0';
                destinationDetailModal.hidden = false;
              };

              touristSpots.addEventListener('click', event => {
                if (event.target.closest('.tourist-spot-carousel-dot')) return;
                const card = event.target.closest('.tourist-spot');
                const destination = card && destinationCache.find(item => String(item.id) === card.dataset.destinationId);
                if (destination) showDestinationDetails(destination);
              });

              destinationDetailGallery.addEventListener('click', event => {
                const images = destinationDetailGallery.querySelectorAll('.destination-detail-image');
                if (images.length < 2) return;
                let current = Number(destinationDetailGallery.dataset.current || 0);
                if (event.target.closest('.next')) current = (current + 1) % images.length;
                if (event.target.closest('.previous')) current = (current - 1 + images.length) % images.length;
                destinationDetailGallery.dataset.current = current;
                images.forEach((image, index) => image.classList.toggle('active', index === current));
              });

              const closeDestinationDetails = () => { destinationDetailModal.hidden = true; };
              document.querySelector('#destinationDetailClose').addEventListener('click', closeDestinationDetails);
              destinationDetailModal.addEventListener('click', event => { if (event.target === destinationDetailModal) closeDestinationDetails(); });
              document.addEventListener('keydown', event => { if (event.key === 'Escape') closeDestinationDetails(); });

              hotelDetailGallery.addEventListener('click', event => {
                const images = hotelDetailGallery.querySelectorAll('.destination-detail-image');
                if (images.length < 2) return;
                let current = Number(hotelDetailGallery.dataset.current || 0);
                if (event.target.closest('.next')) current = (current + 1) % images.length;
                if (event.target.closest('.previous')) current = (current - 1 + images.length) % images.length;
                hotelDetailGallery.dataset.current = current;
                images.forEach((image, index) => image.classList.toggle('active', index === current));
              });

              const closeHotelDetails = () => { hotelDetailModal.hidden = true; };
              document.querySelector('#hotelDetailClose').addEventListener('click', closeHotelDetails);
              hotelDetailModal.addEventListener('click', event => { if (event.target === hotelDetailModal) closeHotelDetails(); });
              document.addEventListener('keydown', event => { if (event.key === 'Escape') closeHotelDetails(); });

            const revealItems = document.querySelectorAll('.hero, .package, .panel, .review-card');
            if ('IntersectionObserver' in window) {
                const revealObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('revealed');
                            observer.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.08 });
                revealItems.forEach((item) => revealObserver.observe(item));
            } else {
                revealItems.forEach((item) => item.classList.add('revealed'));
            }

            const progress = document.querySelector('#scrollProgress');
            let progressFrame = 0;
            window.addEventListener('scroll', () => {
                if (progressFrame) return;
                progressFrame = window.requestAnimationFrame(() => {
                    const scrollable = document.documentElement.scrollHeight - window.innerHeight;
                    const percent = scrollable > 0 ? (window.scrollY / scrollable) * 100 : 0;
                    if (progress) progress.style.width = `${percent}%`;
                    progressFrame = 0;
                });
            }, { passive: true });
        })();
    </script>

</body>
</html>