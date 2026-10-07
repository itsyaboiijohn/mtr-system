<?php
require_once __DIR__ . '/../api/auth.php';
require_user_auth();
$database = require_once __DIR__ . '/../api/db_con.php';
$availableRegions = ['Zamboanga Peninsula', 'Northern Mindanao', 'Davao Region', 'SOCCSKSARGEN', 'Caraga', 'BARMM'];
$selectedRegion = trim((string) ($_GET['region'] ?? ''));
if (!in_array($selectedRegion, $availableRegions, true)) {
    $selectedRegion = '';
}

// 1. Create destinations table if it does not exist
$database->exec("CREATE TABLE IF NOT EXISTS destinations (
    Destination_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    location VARCHAR(160) NULL,
    region VARCHAR(120) NULL DEFAULT 'Mindanao',
    category VARCHAR(50) NULL DEFAULT 'island',
    description TEXT NULL,
    days VARCHAR(50) NULL DEFAULT '1-2 days',
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    image_url VARCHAR(500) NULL,
    tags VARCHAR(255) NULL DEFAULT '',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2. Ensure extra columns exist if table was created previously without them
$columns = $database->query("SHOW COLUMNS FROM destinations")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('region', $columns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN region VARCHAR(120) NULL DEFAULT 'Mindanao'");
}
if (!in_array('location', $columns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN location VARCHAR(160) NULL AFTER name");
}
if (!in_array('category', $columns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN category VARCHAR(50) NULL DEFAULT 'island'");
}
if (!in_array('days', $columns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN days VARCHAR(50) NULL DEFAULT '1-2 days'");
}
if (!in_array('tags', $columns, true)) {
    $database->exec("ALTER TABLE destinations ADD COLUMN tags VARCHAR(255) NULL DEFAULT ''");
}

// 3. Seed default destinations if the table is currently empty
$count = (int) $database->query("SELECT COUNT(*) FROM destinations")->fetchColumn();

if ($count === 0) {
    $seedDestinations = [
        [
            'name' => 'Siargao Island',
            'region' => 'Surigao del Norte',
            'category' => 'island',
            'description' => 'A laid-back island known for Cloud 9 surf breaks, palm-lined roads, island hopping, and Sugba Lagoon.',
            'days' => '2-3 days',
            'price' => 8200.00,
            'image_url' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Surfing,Island hopping'
        ],
        [
            'name' => 'Sugba Lagoon',
            'region' => 'Siargao',
            'category' => 'nature',
            'description' => 'Swim, paddle, and float through bright turquoise water surrounded by the quiet limestone landscape of Del Carmen.',
            'days' => '1 day',
            'price' => 4200.00,
            'image_url' => 'https://images.unsplash.com/photo-1476673160081-cf065607f449?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Lagoon,Boat ride'
        ],
        [
            'name' => 'Camiguin Island',
            'region' => 'Camiguin',
            'category' => 'island',
            'description' => 'A compact island of volcanoes, hot springs, white sandbars, and heritage sites made for slow exploring.',
            'days' => '2-3 days',
            'price' => 7600.00,
            'image_url' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Hot springs,White Island'
        ],
        [
            'name' => 'Dahican Beach',
            'region' => 'Mati, Davao Oriental',
            'category' => 'island',
            'description' => 'A long, open beach with clear water, a relaxed surf scene, and local skimboarding culture.',
            'days' => '1-2 days',
            'price' => 5100.00,
            'image_url' => 'https://images.unsplash.com/photo-1506953823976-52e1fdc0149a?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Beach,Skimboarding'
        ],
        [
            'name' => 'Tinago Falls',
            'region' => 'Iligan City',
            'category' => 'nature',
            'description' => 'A hidden waterfall tucked inside a lush gorge, reached by a scenic descent into cool blue water.',
            'days' => '1 day',
            'price' => 3900.00,
            'image_url' => 'https://images.unsplash.com/photo-1433086966358-54859d0ed716?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Waterfall,Nature'
        ],
        [
            'name' => 'Maria Cristina Falls',
            'region' => 'Iligan City',
            'category' => 'nature',
            'description' => 'Mindanao’s iconic twin falls and one of the island’s most powerful natural landmarks.',
            'days' => 'Half day',
            'price' => 2800.00,
            'image_url' => 'https://images.unsplash.com/photo-1544735716-392fe2489ffa?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Waterfall,Landmark'
        ],
        [
            'name' => 'Enchanted River',
            'region' => 'Hinatuan, Surigao del Sur',
            'category' => 'nature',
            'description' => 'A vivid blue river flowing toward the sea, famous for its clear water and mysterious depth.',
            'days' => '1 day',
            'price' => 4700.00,
            'image_url' => 'https://images.unsplash.com/photo-1437482078695-73f5ca6c96e2?auto=format&fit=crop&w=800&q=82',
            'tags' => 'River,Swimming'
        ],
        [
            'name' => 'Lake Sebu',
            'region' => 'South Cotabato',
            'category' => 'culture',
            'description' => 'Mountain lake country where T’boli culture, weaving traditions, and misty hills meet.',
            'days' => '2 days',
            'price' => 6200.00,
            'image_url' => 'https://images.unsplash.com/photo-1473445361085-b9a07f55608b?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Culture,Lake'
        ],
        [
            'name' => 'Bukidnon Highlands',
            'region' => 'Bukidnon',
            'category' => 'culture',
            'description' => 'Fresh highland air, rolling farms, pine trails, and wide views across the central mountains.',
            'days' => '2 days',
            'price' => 5800.00,
            'image_url' => 'https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Mountains,Farms'
        ],
        [
            'name' => 'Mount Apo',
            'region' => 'Davao Region',
            'category' => 'culture',
            'description' => 'The Philippines’ highest peak, offering rewarding trails, forest scenery, and a true highland adventure.',
            'days' => '2-3 days',
            'price' => 9400.00,
            'image_url' => 'https://images.unsplash.com/photo-1464278533981-50106e6176b1?auto=format&fit=crop&w=800&q=82',
            'tags' => 'Trekking,Mountain'
        ]
    ];

    $insertStmt = $database->prepare("INSERT INTO destinations (name, region, category, description, days, price, image_url, tags, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    foreach ($seedDestinations as $item) {
        $insertStmt->execute([
            $item['name'],
            $item['region'],
            $item['category'],
            $item['description'],
            $item['days'],
            $item['price'],
            $item['image_url'],
            $item['tags']
        ]);
    }
}

// 4. Create hotels table if it does not exist
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
    CONSTRAINT fk_hotel_image_hotel_build FOREIGN KEY (Hotel_ID) REFERENCES hotels(Hotel_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 4. Query hotels from the database
$hotelsStmt = $database->query("
        SELECT Hotel_ID AS id, name, location, region, description, price_per_night, amenities, rating,
            (SELECT hi.image_path FROM hotel_images hi WHERE hi.Hotel_ID = hotels.Hotel_ID ORDER BY hi.Hotel_Image_ID LIMIT 1) AS image_path
    FROM hotels
    WHERE status = 'active'
    ORDER BY rating DESC, created_at DESC
");

$dbHotels = [];
foreach ($hotelsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $imagePath = $row['image_path'] ?? '';
    if ($imagePath !== '' && !preg_match('/^https?:\/\//i', $imagePath) && strpos($imagePath, '../') !== 0) {
        $imagePath = '../' . ltrim($imagePath, '/');
    }

    $dbHotels[] = [
        'id' => (string) $row['id'],
        'name' => $row['name'],
        'location' => $row['location'],
        'region' => $row['region'] ?? 'Mindanao',
        'description' => $row['description'] ?? '',
        'price_per_night' => (float) $row['price_per_night'],
        'amenities' => $row['amenities'] ?? '',
        'rating' => (float) ($row['rating'] ?? 0),
        'image' => $imagePath
    ];
}

// 5. Query all active destinations from the database
$destinationsStmt = $database->prepare("
    SELECT d.Destination_ID AS id,
           d.name,
           COALESCE(NULLIF(d.location, ''), NULLIF(d.region, ''), 'Mindanao') AS location,
           COALESCE(NULLIF(d.region, ''), 'Mindanao') AS region,
           COALESCE(NULLIF(d.category, ''), 'island') AS category,
           COALESCE(d.description, '') AS description,
           COALESCE(NULLIF(d.days, ''), '1-2 days') AS days,
           CAST(d.price AS DECIMAL(10,2)) AS price,
           COALESCE(
               (SELECT di.image_path FROM destination_images di WHERE di.Destination_ID = d.Destination_ID ORDER BY di.Destination_Image_ID LIMIT 1),
               NULLIF(d.image_url, ''),
               '../assets/logo/logo.png'
           ) AS image,
           COALESCE(d.tags, '') AS tags
    FROM destinations d
        WHERE d.status = 'active'
    ORDER BY d.Destination_ID ASC
");
$destinationsStmt->execute();

$destinationImages = [];
$imagesStmt = $database->query("SELECT Destination_ID, image_path FROM destination_images ORDER BY Destination_ID ASC, Destination_Image_ID ASC");
foreach ($imagesStmt->fetchAll(PDO::FETCH_ASSOC) as $imageRow) {
    $destinationImages[(string) $imageRow['Destination_ID']][] = $imageRow['image_path'];
}

$dbSpots = [];
foreach ($destinationsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $imagePath = $row['image'];
    if (!preg_match('/^https?:\/\//i', $imagePath) && strpos($imagePath, '../') !== 0) {
        $imagePath = '../' . ltrim($imagePath, '/');
    }

    $tagsArray = array_values(array_filter(array_map('trim', explode(',', $row['tags']))));
    if (empty($tagsArray)) {
        $tagsArray = ['Destination'];
    }

    $dbSpots[] = [
        'id' => (string) $row['id'],
        'name' => $row['name'],
        'location' => $row['location'],
        'region' => $row['region'],
        'category' => strtolower($row['category']),
        'image' => $imagePath,
        'images' => array_values(array_filter(array_map(function ($path) {
            if (!preg_match('/^https?:\/\//i', $path) && strpos($path, '../') !== 0) {
                return '../' . ltrim($path, '/');
            }
            return $path;
        }, $destinationImages[(string) $row['id']] ?? [$imagePath]))),
        'description' => $row['description'],
        'days' => $row['days'],
        'price' => (float) $row['price'],
        'tags' => $tagsArray
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Build Your Own Plan | Mindanao Travel & Tours</title>
    <style>
        :root { --bg:#f0faff; --card:#fff; --ink:#0f1724; --muted:#64748b; --line:#dce8ef; --accent:#0ea5e9; --accent-dark:#0369a1; --soft:#e6f9ff; --green:#047857; --shadow:0 18px 45px rgba(2,6,23,.07); --font:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { min-height:100vh; margin:0; background:radial-gradient(circle at 90% 0,#d8f4ff 0,transparent 34%),var(--bg); color:var(--ink); font-family:var(--font); }
        button,input { font:inherit; } button { cursor:pointer; } a { color:inherit; text-decoration:none; }
        .page { width:min(1160px,calc(100% - 40px)); margin:auto; padding:26px 0 55px; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:22px; margin-bottom:52px; }
        .brand { display:flex; align-items:center; gap:12px; }.logo { display:grid; width:44px; height:44px; place-items:center; overflow:hidden; border:1px solid rgba(14,165,233,.14); border-radius:12px; background:#fff; box-shadow:0 8px 20px rgba(2,6,23,.05); }.logo img { width:100%; height:100%; object-fit:contain; }.brand strong,.brand small { display:block; }.brand strong { font-size:15px; }.brand small { margin-top:3px; color:var(--muted); font-size:12px; }
        .top-actions { display:flex; align-items:center; gap:18px; }.back-link { color:var(--accent-dark); font-size:13px; font-weight:700; }.back-link:hover { text-decoration:underline; }.cart-link { display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:800; }.cart-link:hover { border-color:var(--accent); background:var(--soft); }.cart-count { display:grid; width:19px; height:19px; place-items:center; border-radius:50%; background:var(--accent); color:#fff; font-size:10px; }
        .intro { max-width:720px; margin-bottom:27px; }.eyebrow { margin:0 0 8px; color:var(--accent-dark); font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; } h1 { margin:0; font-size:clamp(31px,4vw,48px); letter-spacing:-1px; line-height:1.05; }.intro p { margin:12px 0 0; color:var(--muted); line-height:1.6; }
        .region-context-link { display:inline-flex; margin-top:10px; color:var(--accent-dark); font-size:12px; font-weight:800; text-decoration:underline; }
        .wizard-back-button,.wizard-next-button,.add-region-button { min-height:38px; padding:0 13px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--ink); font-size:12px; font-weight:800; }
        .wizard-next-button { border-color:var(--accent); background:var(--accent); color:#fff; }
        .wizard-next-button:hover { background:var(--accent-dark); }
        .wizard-next-button:disabled,.add-region-button:disabled { opacity:.5; cursor:not-allowed; }
        .wizard-back-button:hover,.add-region-button:hover { border-color:var(--accent); background:var(--soft); }
        .builder.wizard-single { grid-template-columns:1fr; }
        [hidden] { display:none !important; }
        .toolbar { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:16px; }.toolbar h2 { margin:0; font-size:19px; }.toolbar-note { color:var(--muted); font-size:12px; }.filters { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:22px; }.filter { padding:8px 12px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--muted); font-size:12px; font-weight:700; }.filter:hover,.filter.active { border-color:var(--accent); background:var(--soft); color:var(--accent-dark); }
        .builder { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:22px; align-items:start; }.spot-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }.spot-card { overflow:hidden; border:1px solid var(--line); border-radius:13px; background:var(--card); box-shadow:var(--shadow); }.spot-image { position:relative; height:165px; background-position:center; background-size:cover; }.spot-image::after { position:absolute; inset:0; background:linear-gradient(180deg,transparent 48%,rgba(15,23,36,.5)); content:""; }.region { position:absolute; z-index:1; top:12px; left:12px; padding:5px 8px; border-radius:5px; background:rgba(255,255,255,.9); color:var(--accent-dark); font-size:10px; font-weight:800; }.spot-body { padding:15px; }.spot-body h3 { margin:0 0 5px; font-size:17px; }.spot-body p { min-height:38px; margin:0 0 13px; color:var(--muted); font-size:12px; line-height:1.5; }.spot-meta { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:14px; color:var(--muted); font-size:11px; }.spot-meta strong { color:var(--ink); }.spot-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; }.spot-button { min-height:36px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--accent-dark); font-size:11px; font-weight:800; }.spot-button:hover { background:var(--soft); }.spot-button.add { border-color:var(--accent); background:var(--accent); color:#fff; }.spot-button.add:hover { background:var(--accent-dark); }.spot-button.add.selected { border-color:var(--green); background:#ecfdf5; color:var(--green); }
        .plan-card { position:sticky; top:18px; padding:22px; border:1px solid var(--line); border-radius:13px; background:var(--card); box-shadow:var(--shadow); }.plan-card h2 { margin:0 0 5px; font-size:19px; }.plan-subtitle { margin:0 0 18px; color:var(--muted); font-size:12px; line-height:1.5; }.plan-list { display:grid; gap:9px; max-height:340px; overflow:auto; padding-right:2px; }.plan-empty { padding:18px 12px; border:1px dashed #b9d9e6; border-radius:8px; background:#f8fdff; color:var(--muted); font-size:12px; line-height:1.5; text-align:center; }.plan-item { display:grid; grid-template-columns:38px 1fr auto; gap:10px; align-items:center; padding:8px; border-radius:8px; background:#f7fbfd; }.plan-thumb { width:38px; height:38px; border-radius:6px; background-position:center; background-size:cover; }.plan-item strong,.plan-item small { display:block; }.plan-item strong { font-size:12px; }.plan-item small { margin-top:3px; color:var(--muted); font-size:10px; }.remove-spot { border:0; background:transparent; color:var(--muted); font-size:18px; }.remove-spot:hover { color:#dc2626; }.plan-divider { margin:19px 0 14px; border-top:1px solid var(--line); }.plan-row { display:flex; justify-content:space-between; gap:12px; margin:10px 0; color:var(--muted); font-size:12px; }.plan-row strong { color:var(--ink); }.plan-total { display:flex; justify-content:space-between; margin-top:17px; padding-top:15px; border-top:1px solid var(--line); font-size:16px; font-weight:800; }.plan-total strong { color:var(--accent-dark); }.primary-button { display:inline-flex; width:100%; min-height:45px; align-items:center; justify-content:center; margin-top:20px; border:0; border-radius:8px; background:var(--accent); color:#fff; font-weight:800; }.primary-button:hover { background:var(--accent-dark); }.primary-button:disabled { background:#b7c6ce; cursor:not-allowed; }.plan-note { margin:11px 0 0; color:var(--muted); font-size:10px; line-height:1.5; text-align:center; }
        .modal-backdrop { position:fixed; z-index:10; inset:0; display:grid; place-items:center; padding:20px; background:rgba(15,23,36,.5); }.modal-backdrop[hidden] { display:none; }.modal { position:relative; width:min(540px,100%); overflow:hidden; border-radius:14px; background:#fff; box-shadow:0 25px 70px rgba(2,6,23,.2); }.modal-gallery { position:relative; background:#0f1724; }.modal-image { height:280px; background-position:center; background-size:cover; }.gallery-button { position:absolute; top:50%; display:grid; width:34px; height:34px; place-items:center; border:0; border-radius:50%; background:rgba(15,23,36,.72); color:#fff; font-size:22px; line-height:1; transform:translateY(-50%); }.gallery-button:hover { background:var(--accent-dark); }.gallery-button.previous { left:12px; }.gallery-button.next { right:12px; }.gallery-counter { position:absolute; right:12px; bottom:12px; padding:5px 8px; border-radius:5px; background:rgba(15,23,36,.72); color:#fff; font-size:11px; font-weight:700; }.gallery-thumbs { display:flex; gap:7px; overflow:auto; padding:9px 12px 0; background:#0f1724; }.gallery-thumb { flex:0 0 48px; height:36px; padding:0; border:2px solid transparent; border-radius:5px; background-position:center; background-size:cover; opacity:.65; }.gallery-thumb.active { border-color:#fff; opacity:1; }.modal-copy { padding:24px; }.modal-copy h2 { margin:6px 0 8px; font-size:26px; }.modal-copy p { margin:0; color:var(--muted); font-size:13px; line-height:1.65; }.modal-info { display:flex; flex-wrap:wrap; gap:8px; margin:17px 0 0; }.modal-info span { padding:7px 9px; border-radius:6px; background:var(--soft); color:var(--accent-dark); font-size:11px; font-weight:700; }.close-modal { position:absolute; z-index:1; top:12px; right:13px; width:32px; height:32px; border:0; border-radius:50%; background:rgba(15,23,36,.72); color:#fff; font-size:21px; line-height:1; }.toast { position:fixed; z-index:20; right:22px; bottom:22px; padding:12px 16px; border-radius:8px; background:var(--ink); color:#fff; font-size:12px; opacity:0; pointer-events:none; transform:translateY(12px); transition:opacity .2s,transform .2s; }.toast.show { opacity:1; transform:translateY(0); }
        .plan-location-field { display:grid; gap:6px; margin:12px 0; }
        .plan-location-field span { color:var(--muted); font-size:11px; font-weight:800; }
        .plan-location-field input, .plan-location-field select { height:40px; }
        .itinerary-editor { display:none; gap:9px; margin:14px 0; padding-top:14px; border-top:1px solid var(--line); }
        .itinerary-editor.visible { display:grid; }
        .itinerary-editor-heading { color:var(--muted); font-size:11px; font-weight:800; }
        .itinerary-day { display:grid; grid-template-columns:minmax(0,1fr) minmax(110px,.8fr); gap:8px; align-items:center; }
        .itinerary-day span { color:var(--muted); font-size:11px; font-weight:800; }
        .itinerary-day select { width:100%; height:38px; padding:0 8px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--ink); font:inherit; font-size:12px; }
        .itinerary-note { margin:0; color:var(--muted); font-size:10px; line-height:1.45; }
        .transport-step { margin-top:30px; padding:20px; border:1px solid var(--line); border-radius:13px; background:var(--card); box-shadow:var(--shadow); }
        .transport-step h2 { margin:0 0 5px; font-size:19px; }
        .transport-step > p { margin:0 0 16px; color:var(--muted); font-size:12px; line-height:1.5; }
        .trip-details-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
        .trip-detail-field { display:grid; gap:6px; color:var(--muted); font-size:11px; font-weight:800; }
        .trip-detail-field input { width:100%; min-height:40px; padding:9px 10px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--ink); }
        .vehicle-options { margin:12px 0 0; }
        .vehicle-carousel { position:relative; }
        .vehicle-track { display:flex; gap:12px; overflow-x:auto; scroll-snap-type:x mandatory; scroll-behavior:smooth; padding:2px; scrollbar-width:thin; }
        .vehicle-option { flex:0 0 min(280px,85%); scroll-snap-align:start; display:grid; grid-template-rows:auto 1fr; border:1px solid var(--line); border-radius:9px; background:#fff; color:var(--ink); text-align:left; overflow:hidden; }
        .vehicle-option:hover,.vehicle-option.selected { border-color:var(--accent); background:var(--soft); }
        .vehicle-photo { position:relative; height:160px; background:#e8e8e8 center/cover no-repeat; }
        .vehicle-photo-empty { display:grid; place-items:center; color:var(--muted); font-size:11px; }
        .vehicle-photo-nav,.vehicle-nav { position:absolute; top:50%; transform:translateY(-50%); width:30px; height:30px; border:0; border-radius:50%; background:rgba(0,0,0,.55); color:#fff; font-size:20px; line-height:1; cursor:pointer; z-index:2; }
        .vehicle-photo-nav.prev { left:8px; } .vehicle-photo-nav.next { right:8px; }
        .vehicle-nav.prev { left:-10px; } .vehicle-nav.next { right:-10px; }
        .vehicle-photo-count { position:absolute; right:8px; bottom:8px; padding:2px 7px; border-radius:99px; background:rgba(0,0,0,.55); color:#fff!important; font-size:10px!important; }
        .vehicle-info { display:grid; gap:6px; padding:13px; align-content:start; }
        .vehicle-info .spot-button { margin-top:6px; }
        .vehicle-option strong { font-size:13px; }
        .vehicle-option span { color:var(--muted); font-size:11px; }
        .vehicle-message { min-height:18px; margin:14px 0 0!important; color:var(--muted); font-size:11px!important; }
        .transport-step .primary-button { max-width:340px; margin:15px 0 0; }
        [hidden] { display:none !important; }
        .wizard-stepper { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin:0 0 22px; }
        .wizard-step { display:flex; width:100%; min-width:0; align-items:center; gap:9px; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:rgba(255,255,255,.8); color:var(--muted); font-size:12px; font-weight:800; text-align:left; }
        .wizard-step:not(:disabled) { cursor:pointer; }
        .wizard-step:not(:disabled):hover { border-color:var(--accent); background:var(--soft); }
        .wizard-step:disabled { cursor:not-allowed; opacity:.65; }
        .wizard-step:focus-visible { outline:3px solid rgba(14,165,233,.35); outline-offset:2px; }
        .wizard-step strong { display:grid; width:25px; height:25px; flex:0 0 25px; place-items:center; border-radius:50%; background:#e4eef0; color:var(--muted); font-size:11px; }
        .wizard-step.active { border-color:var(--accent); background:#fff; color:var(--ink); }
        .wizard-step.active strong,.wizard-step.complete strong { background:var(--accent); color:#fff; }
        .wizard-step.complete { border-color:#b8d9d6; color:var(--accent-dark); }
        .region-picker { display:grid; grid-template-columns:minmax(170px,250px) 1fr; align-items:center; gap:10px 14px; margin-bottom:14px; padding:12px 14px; border:1px solid var(--line); border-radius:9px; background:rgba(255,255,255,.8); }
        .region-picker label { display:grid; gap:6px; color:var(--muted); font-size:11px; font-weight:800; }
        .region-picker select { width:100%; min-height:40px; padding:0 10px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--ink); font:inherit; font-size:12px; }
        .selected-regions { display:flex; flex-wrap:wrap; gap:6px; }
        .selected-region-tag { padding:5px 8px; border-radius:5px; background:var(--soft); color:var(--accent-dark); font-size:10px; font-weight:800; }
        .builder.wizard-single { grid-template-columns:1fr; }
        .wizard-step-footer { display:flex; justify-content:flex-end; gap:9px; margin-top:15px; }
        .wizard-secondary { min-height:40px; padding:0 13px; border:1px solid var(--line); border-radius:7px; background:#fff; color:var(--accent-dark); font-size:12px; font-weight:800; }
        @media (max-width:560px) { .trip-details-grid { grid-template-columns:1fr; }.transport-step { padding:15px; } }
        @media (max-width:850px) { .builder { grid-template-columns:1fr; }.plan-card { position:static; }.spot-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width:560px) { .page { width:min(100% - 28px,500px); padding-top:20px; }.topbar { align-items:flex-start; margin-bottom:38px; }.top-actions { align-items:flex-end; flex-direction:column-reverse; gap:9px; }.back-link { font-size:12px; }.spot-grid { grid-template-columns:1fr; }.spot-image { height:185px; }.toolbar { align-items:flex-start; flex-direction:column; gap:7px; }.filters { flex-wrap:nowrap; overflow:auto; padding-bottom:3px; }.filter { flex:0 0 auto; }.modal-image { height:155px; }.wizard-stepper { gap:5px; }.wizard-step { flex-direction:column; justify-content:center; gap:4px; padding:8px 4px; font-size:10px; text-align:center; }.region-picker { grid-template-columns:1fr; }.wizard-step-footer { flex-wrap:wrap; }.wizard-step-footer > button { flex:1; } }
    </style>
</head>
<body>
<datalist id="mindanaoTransportLocations">
    <option value="General Santos International Airport (GES)"></option>
    <option value="Francisco Bangoy International Airport (DVO), Davao City"></option>
    <option value="Laguindingan Airport (CGY), Cagayan de Oro"></option>
    <option value="Sayak Airport (IAO), Siargao"></option>
    <option value="Surigao City Port"></option>
    <option value="Dapa Port, Siargao"></option>
    <option value="Sasa Port, Davao City"></option>
    <option value="Macabalan Port, Cagayan de Oro"></option>
    <option value="Benoni Port, Camiguin"></option>
</datalist>
<main class="page">
    <header class="topbar">
        <a class="brand" href="dashboard.php"><span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span><span><strong>Mindanao Travel & Tours</strong><small>Build your own adventure</small></span></a>
        <div class="top-actions"><a class="back-link" href="dashboard.php">← Back to dashboard</a><a class="cart-link" href="cart.php">Trip cart </a></div>
    </header>
    <section class="intro"><p class="eyebrow">Create your own plan</p><h1><?= $selectedRegion !== '' ? 'Explore ' . htmlspecialchars($selectedRegion, ENT_QUOTES, 'UTF-8') : 'Pick the places you want to remember.' ?></h1><p><?= $selectedRegion !== '' ? 'Choose destinations in ' . htmlspecialchars($selectedRegion, ENT_QUOTES, 'UTF-8') . ' for your custom itinerary.' : 'Explore Mindanao\'s most loved destinations, open the details, then add the spots that belong in your own itinerary.' ?></p><?php if ($selectedRegion !== ''): ?><a class="region-context-link" href="region_map.php">← Choose a different region</a><?php endif; ?></section>
    <nav class="wizard-stepper" id="wizardStepper" aria-label="Custom trip steps">
        <button class="wizard-step active" type="button" data-wizard-step-indicator="1" aria-current="step"><strong>1</strong><span>Destinations</span></button>
        <button class="wizard-step" type="button" data-wizard-step-indicator="2" disabled><strong>2</strong><span>Accommodation</span></button>
        <button class="wizard-step" type="button" data-wizard-step-indicator="3" disabled><strong>3</strong><span>Trip details</span></button>
    </nav>
    <div class="region-picker" id="regionPicker">
        <label for="planRegionSelect">Choose a region for this part of your plan<select id="planRegionSelect"><option value="">Select a region</option><?php foreach ($availableRegions as $regionName): ?><option value="<?= htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') ?>"<?= $selectedRegion === $regionName ? ' selected' : '' ?>><?= htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
        <div class="selected-regions" id="selectedRegionsList" aria-label="Regions included in your plan"></div>
    </div>
    <div class="toolbar" id="destinationToolbar"><h2><?= $selectedRegion !== '' ? 'Destinations in ' . htmlspecialchars($selectedRegion, ENT_QUOTES, 'UTF-8') : 'Choose destinations' ?></h2><span class="toolbar-note" id="resultCount">0 destinations</span></div>
    <div class="filters" role="tablist" aria-label="Filter destinations"><button class="filter active" type="button" data-filter="all">All places</button><button class="filter" type="button" data-filter="island">Islands & beaches</button><button class="filter" type="button" data-filter="nature">Waterfalls & nature</button><button class="filter" type="button" data-filter="culture">Culture & highlands</button></div>
    <div class="builder" id="destinationBuilder">
        <section class="spot-grid" id="spotGrid" aria-label="Mindanao tourist spots"></section>
        <aside class="plan-card" aria-labelledby="planTitle"><h2 id="planTitle">Your custom plan</h2><p class="plan-subtitle">Choose at least one destination. Your driver and private car can take you between every stop.</p><div class="plan-list" id="planList"><div class="plan-empty">Your plan is waiting for its first stop.<br>Browse the destinations and add one here.</div></div><div class="plan-divider"></div><div class="plan-row"><span>Selected spots</span><strong id="selectedCount">0</strong></div><div class="plan-row"><span>Transport & driver</span><strong class="included">Included</strong></div><div class="plan-divider"></div><label class="plan-location-field"><span>Pickup location</span><input id="pickupLocation" list="mindanaoTransportLocations" type="text" maxlength="180" placeholder="e.g. General Santos Airport" required></label><label class="plan-location-field"><span>Departure location</span><input id="departureLocation" list="mindanaoTransportLocations" type="text" maxlength="180" placeholder="e.g. General Santos Port" required></label><div class="itinerary-editor" id="itineraryEditor"><div class="itinerary-editor-heading">Day-by-day itinerary</div><div id="itineraryFields"></div>        <p class="itinerary-note">Assign each destination to a day. A day can have one or two destinations, but no more than two.</p></div><div class="plan-row"><span>Required hotel</span><strong id="selectedHotelName">Select a hotel in your region</strong></div><div class="plan-total"><span>Starting estimate</span><strong id="planTotal">₱ 0</strong></div><button class="primary-button" id="addToCart" type="button" disabled>Add plan to cart</button><p class="plan-note" id="planNote">Select at least one destination to add your itinerary to the cart.</p></aside>
    </div>
    <div class="toolbar" id="hotelToolbar" style="margin-top:32px"><h2>Available Hotels</h2><span class="toolbar-note" id="hotelCount">0 hotels</span></div>
    <p class="plan-note" id="hotelSelectionNote" hidden>Select a hotel in the selected region to continue.</p>
    <div class="builder" id="hotelBuilder">
        <section class="spot-grid" id="hotelGrid" aria-label="Mindanao hotels"></section>
    </div>
    <div class="wizard-step-footer" id="accommodationActions" hidden>
        <button class="wizard-back-button" id="backToDestinations" type="button">Back to destinations</button>
        <button class="wizard-next-button" id="continueToTrip" type="button">Continue to trip details</button>
    </div>
    <section class="transport-step" id="transportStep" aria-labelledby="transportTitle" hidden>
        <h2 id="transportTitle">Choose your travel date and vehicle</h2>
        <p>Tell us when you are traveling and how many people are going. Only vehicles available for your full trip and large enough for your group will be shown.</p>
        <div class="trip-details-grid">
            <label class="trip-detail-field" for="travelDate">Travel date<input id="travelDate" type="date" min="<?= date('Y-m-d') ?>" required></label>
            <label class="trip-detail-field" for="tripDuration">Trip duration (days)<input id="tripDuration" type="number" min="1" max="30" value="1" required></label>
            <label class="trip-detail-field" for="passengerCount">Passengers<input id="passengerCount" type="number" min="1" max="100" value="1" required></label>
        </div>
        <p class="itinerary-note" id="tripDurationNote">Duration defaults to one day per selected destination. Schedule no more than two destinations per day.</p>
        <div class="vehicle-options" id="vehicleOptions" aria-live="polite"></div>
        <p class="vehicle-message" id="vehicleMessage">Select a travel date, duration, and passenger count to check available vehicles.</p>
        <div class="wizard-step-footer"><button class="wizard-back-button" id="backToAccommodation" type="button">Back to accommodation</button><button class="add-region-button" id="addAnotherRegion" type="button">＋ Add another region</button><button class="primary-button" id="addToCartBottom" type="button" disabled>Add plan to cart</button></div>
    </section>
</main>
<div class="modal-backdrop" id="detailsModal" hidden><section class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><button class="close-modal" id="closeModal" type="button" aria-label="Close details">×</button><div class="modal-gallery"><div class="modal-image" id="modalImage"></div><button class="gallery-button previous" id="previousImage" type="button" aria-label="Previous image">‹</button><button class="gallery-button next" id="nextImage" type="button" aria-label="Next image">›</button><span class="gallery-counter" id="galleryCounter"></span><div class="gallery-thumbs" id="galleryThumbs"></div></div><div class="modal-copy"><p class="eyebrow" id="modalRegion">Mindanao</p><h2 id="modalTitle">Destination</h2><p id="modalDescription"></p><div class="modal-info" id="modalInfo"></div><button class="primary-button" id="modalAdd" type="button">Add to my plan</button></div></section></div>
<div class="toast" id="toast" role="status"></div>
<script>
(() => {
    const spots = <?= json_encode($dbSpots, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const hotels = <?= json_encode($dbHotels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const draftKey = 'mtrRegionPlanDraft';
    const urlParams = new URLSearchParams(window.location.search);
    let editCartItemId = Number(urlParams.get('edit_cart_item')) || null;
    const continuingDraft = urlParams.get('continue') === '1';
    let draft = null;
    if (editCartItemId) {
        try {
            const savedPlans = JSON.parse(localStorage.getItem('mtrCartPlanDrafts') || '{}');
            draft = savedPlans[String(editCartItemId)] || null;
            if (draft) localStorage.setItem(draftKey, JSON.stringify(draft));
        } catch (error) {
            console.error('Unable to restore the cart plan for editing:', error);
        }
    }
    if (!draft && continuingDraft) {
        try {
            draft = JSON.parse(localStorage.getItem(draftKey) || 'null');
            if (!editCartItemId && draft?.editCartItemId) editCartItemId = Number(draft.editCartItemId);
        } catch (error) {
            console.error('Unable to restore multi-region trip draft:', error);
            localStorage.removeItem(draftKey);
        }
    } else if (!editCartItemId && !continuingDraft) {
        localStorage.removeItem(draftKey);
    }
    const selected = new Map((Array.isArray(draft?.destinationIds) ? draft.destinationIds : [])
        .map(id => spots.find(spot => spot.id === String(id)))
        .filter(Boolean)
        .map(spot => [spot.id, spot]));
    let activeRegion = <?= json_encode($selectedRegion, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const selectedRegions = new Set([...(Array.isArray(draft?.regions) ? draft.regions : []), ...(activeRegion ? [activeRegion] : [])]);
    const regionHotels = new Map(Object.entries(draft?.regionHotels || {}));
    const regionOrder = Array.isArray(draft?.regions) ? [...draft.regions] : [];
    const regionStarts = { ...(draft?.regionStarts || {}) };
    const transferDays = Array.isArray(draft?.transferDays) ? [...draft.transferDays] : [];
    let currentWizardStep = 1;
    let requiresAdditionalRegionDestination = false;
    let regionDestinationBaseline = new Set();
    let selectedHotel = regionHotels.get(activeRegion) || null;
    let arrivalRestDay = Boolean(draft?.arrivalRestDay);
    let activeSpot = null;
    let activeImages = [];
    let activeImageIndex = 0;
    let toastTimer;
    let availableVehicles = [];
    let selectedVehicleId = '';
    let availabilityRequest = 0;
    const grid = document.querySelector('#spotGrid');
    const planList = document.querySelector('#planList');
    const pickupLocation = document.querySelector('#pickupLocation');
    const departureLocation = document.querySelector('#departureLocation');
    const itineraryEditor = document.querySelector('#itineraryEditor');
    const itineraryFields = document.querySelector('#itineraryFields');
    const itineraryAssignments = new Map();
    const travelDate = document.querySelector('#travelDate');
    const tripDuration = document.querySelector('#tripDuration');
    const passengerCount = document.querySelector('#passengerCount');
    const vehicleOptions = document.querySelector('#vehicleOptions');
    const vehicleMessage = document.querySelector('#vehicleMessage');
    const regionSelect = document.querySelector('#planRegionSelect');
    const selectedRegionsList = document.querySelector('#selectedRegionsList');
    const destinationToolbar = document.querySelector('#destinationToolbar');
    const destinationBuilder = document.querySelector('#destinationBuilder');
    const hotelToolbar = document.querySelector('#hotelToolbar');
    const hotelBuilder = document.querySelector('#hotelBuilder');
    const transportStep = document.querySelector('#transportStep');
    transportStep.insertBefore(itineraryEditor, vehicleOptions);
    const addToCartButton = document.querySelector('#addToCart');
    const addAnotherRegionButton = document.querySelector('#addAnotherRegion');
    if (draft) {
        pickupLocation.value = draft.pickupLocation || '';
        departureLocation.value = draft.departureLocation || '';
        travelDate.value = draft.travelDate || '';
        tripDuration.value = String(draft.duration || 1);
        if (draft.durationAdjusted) tripDuration.dataset.userAdjusted = 'true';
        passengerCount.value = String(draft.passengers || 1);
        for (const [id, day] of Object.entries(draft.assignments || {})) {
            if (Number.isInteger(Number(day))) itineraryAssignments.set(String(id), Number(day));
        }
        if (draft.pendingTransfer && activeRegion && !regionOrder.includes(activeRegion)) {
            transferDays.push({ day: Number(draft.pendingTransfer.day), from: draft.pendingTransfer.from, to: activeRegion });
            regionOrder.push(activeRegion);
            regionStarts[activeRegion] = Number(draft.pendingTransfer.day) + 1;
            draft.pendingTransfer = null;
            localStorage.setItem(draftKey, JSON.stringify({ ...draft, regions: regionOrder, regionStarts, transferDays, pendingTransfer: null }));
        }
    } else if (activeRegion) {
        regionOrder.push(activeRegion);
    }
    const regionNames = <?= json_encode($availableRegions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const normalizeRegion = value => String(value || '').trim().toLowerCase();
    const getPickupRegion = () => {
        const pickup = normalizeRegion(pickupLocation.value);
        const aliasesByRegion = {
            'Zamboanga Peninsula': ['zamboanga peninsula', 'zamboanga city', 'zamboanga', 'dipolog', 'dapitan', 'pagadian', 'isabela city', 'zam'],
            'Northern Mindanao': ['northern mindanao', 'cagayan de oro', 'cdo', 'iligan', 'bukidnon', 'camiguin', 'misamis oriental', 'misamis occidental', 'cgy'],
            'Davao Region': ['davao region', 'davao city', 'davao', 'digos', 'mati', 'tagum', 'panabo', 'samal', 'davao oriental', 'davao del norte', 'davao del sur', 'davao de oro', 'davao occidental', 'dvo'],
            'SOCCSKSARGEN': ['soccsksargen', 'general santos', 'gensan', 'koronadal', 'kidapawan', 'tacurong', 'south cotabato', 'sarangani', 'sultan kudarat', 'north cotabato', 'ges'],
            'Caraga': ['caraga', 'butuan', 'siargao', 'surigao', 'agusan', 'dinagat', 'bislig', 'tandag', 'iao'],
            BARMM: ['barmm', 'bangsamoro', 'maguindanao', 'lanao del sur', 'basilan', 'sulu', 'tawi-tawi', 'cotabato city']
        };
        return Object.entries(aliasesByRegion).find(([, aliases]) => aliases.some(alias => alias.length <= 3
            ? new RegExp(`(?:^|[^a-z0-9])${alias}(?:$|[^a-z0-9])`).test(pickup)
            : pickup.includes(alias)))?.[0] || '';
    };
    const hasArrivalRestDay = () => {
        const pickupRegion = getPickupRegion();
        const firstRegion = regionOrder[0] || activeRegion;
        return Boolean(pickupLocation.value.trim() && firstRegion && (!pickupRegion || normalizeRegion(pickupRegion) !== normalizeRegion(firstRegion)));
    };
    const getRegionStartDay = region => Number(regionStarts[region]) || (arrivalRestDay ? 2 : 1);
    const isReservedDay = day => Boolean(arrivalRestDay && day === 1) || transferDays.some(transfer => Number(transfer.day) === day);
    const syncCurrentHotel = () => {
        if (selectedHotel) regionHotels.set(activeRegion, selectedHotel);
        else regionHotels.delete(activeRegion);
    };
    const createPlanDraft = (pendingTransfer = null) => {
        syncCurrentHotel();
        return {
            destinationIds: [...selected.keys()],
            regions: [...new Set([...regionOrder, activeRegion].filter(Boolean))],
            regionHotels: Object.fromEntries(regionHotels),
            regionStarts,
            transferDays,
            assignments: Object.fromEntries(itineraryAssignments),
            arrivalRestDay,
            duration: Number(tripDuration.value) || 1,
            durationAdjusted: tripDuration.dataset.userAdjusted === 'true',
            pickupLocation: pickupLocation.value.trim(),
            departureLocation: departureLocation.value.trim(),
            travelDate: travelDate.value,
            passengers: Number(passengerCount.value) || 1,
            pendingTransfer,
            editCartItemId
        };
    };
    const saveRegionDraft = pendingTransfer => {
        localStorage.setItem(draftKey, JSON.stringify(createPlanDraft(pendingTransfer)));
    };
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
    const formatPrice = (value) => `₱ ${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const showToast = (message) => { const toast=document.querySelector('#toast'); toast.textContent=message; toast.classList.add('show'); clearTimeout(toastTimer); toastTimer=setTimeout(()=>toast.classList.remove('show'),2600); };
    const renderSpots = (filter='all') => {
        const regionSpots = activeRegion ? spots.filter(spot => normalizeRegion(spot.region) === normalizeRegion(activeRegion)) : [];
        const visible = regionSpots.filter(spot => filter === 'all' || spot.category === filter);
        document.querySelector('#resultCount').textContent = `${visible.length} destination${visible.length === 1 ? '' : 's'}`;
        if (!activeRegion) {
            grid.innerHTML = '<div class="plan-empty" style="grid-column:1/-1">Choose a region to see its destinations.</div>';
            return;
        }
        grid.innerHTML = visible.length ? visible.map((spot) => `<article class="spot-card"><div class="spot-image" style="background-image:url('${spot.image}')"><span class="region">${spot.location || spot.region}</span></div><div class="spot-body"><h3>${spot.name}</h3><p>${spot.description}</p><div class="spot-meta"><span>${spot.region} · ${spot.category}</span><strong>from ${formatPrice(spot.price)}</strong></div><div class="spot-actions"><button class="spot-button" type="button" data-details="${spot.id}">View details</button><button class="spot-button add ${selected.has(spot.id) ? 'selected' : ''}" type="button" data-add="${spot.id}">${selected.has(spot.id) ? 'Added ✓' : 'Add to plan'}</button></div></div></article>`).join('') : '<div class="plan-empty" style="grid-column:1/-1">No destinations are listed in this region for the selected filter.</div>';
    };
    const renderHotels = () => {
        const hotelGrid = document.querySelector('#hotelGrid');
        const matchingHotels = activeRegion
            ? hotels.filter(hotel => normalizeRegion(hotel.region) === normalizeRegion(activeRegion))
            : [];
        const heading = document.querySelector('#hotelToolbar h2');
        heading.textContent = activeRegion ? `Available Hotels in ${activeRegion}` : 'Available Hotels';
        document.querySelector('#hotelCount').textContent = `${matchingHotels.length} hotel${matchingHotels.length === 1 ? '' : 's'}`;
        if (selectedHotel && !matchingHotels.some(hotel => hotel.id === selectedHotel)) {
            selectedHotel = null;
        }
        hotelGrid.innerHTML = matchingHotels.length
            ? matchingHotels.map((hotel) => `<article class="spot-card"><div class="spot-image" style="background-image:url('${hotel.image}')"><span class="region">${hotel.location}</span></div><div class="spot-body"><h3>${hotel.name}</h3><p>${hotel.description || 'Comfortable accommodation for your stay.'}</p><div class="spot-meta"><span>${hotel.rating > 0 ? hotel.rating + ' ★' : 'Not rated'}</span><strong>${formatPrice(hotel.price_per_night)} / night</strong></div><div class="spot-actions"><button class="spot-button" type="button" data-hotel-details="${hotel.id}">View details</button><button class="spot-button add ${selectedHotel === hotel.id ? 'selected' : ''}" type="button" data-hotel-select="${hotel.id}">${selectedHotel === hotel.id ? 'Selected ✓' : 'Select hotel'}</button></div></div></article>`).join('')
            : `<div class="plan-empty" style="grid-column:1/-1">${activeRegion ? 'No hotels are listed in this region yet.' : 'Choose a region to see its hotels.'}</div>`;
    };
    const renderItineraryFields = (items) => {
        const itemIds = new Set(items.map(spot => String(spot.id)));
        for (const id of itineraryAssignments.keys()) {
            if (!itemIds.has(id)) itineraryAssignments.delete(id);
        }
        const duration = Math.min(30, Math.max(1, Number(tripDuration.value) || 1));
        const firstDestinationDay = arrivalRestDay ? 2 : 1;
        const destinationsPerDay = new Map();
        const regionsPerDay = new Map();
        const destinationsPerRegion = new Map();
        items.forEach((spot, index) => {
            const id = String(spot.id);
            const regionIndex = destinationsPerRegion.get(spot.region) || 0;
            let day = itineraryAssignments.get(id);
            if (!Number.isInteger(day) || day < firstDestinationDay || day > duration || isReservedDay(day) || (destinationsPerDay.get(day) || 0) >= 2 || (regionsPerDay.has(day) && regionsPerDay.get(day) !== spot.region)) {
                const preferredDay = getRegionStartDay(spot.region) + regionIndex;
                day = Array.from({ length: Math.max(0, duration - firstDestinationDay + 1) }, (_, dayIndex) => firstDestinationDay + dayIndex)
                    .sort((dayA, dayB) => Math.abs(dayA - preferredDay) - Math.abs(dayB - preferredDay))
                    .find(dayNumber => !isReservedDay(dayNumber) && (destinationsPerDay.get(dayNumber) || 0) < 2 && (!regionsPerDay.has(dayNumber) || regionsPerDay.get(dayNumber) === spot.region));
            }
            if (day === undefined) day = Math.min(duration, getRegionStartDay(spot.region) + Math.floor(index / 2));
            itineraryAssignments.set(id, day);
            destinationsPerDay.set(day, (destinationsPerDay.get(day) || 0) + 1);
            regionsPerDay.set(day, spot.region);
            destinationsPerRegion.set(spot.region, regionIndex + 1);
        });
        if (items.length < 2) {
            itineraryEditor.classList.remove('visible');
            itineraryFields.innerHTML = '';
            return;
        }
        itineraryEditor.classList.add('visible');
        itineraryFields.innerHTML = items.map(spot => {
            const id = String(spot.id);
            const day = itineraryAssignments.get(id);
            const options = Array.from({ length: duration }, (_, index) => {
                const dayNumber = index + 1;
                const full = destinationsPerDay.get(dayNumber) >= 2 && dayNumber !== day;
                const otherRegion = regionsPerDay.has(dayNumber) && regionsPerDay.get(dayNumber) !== spot.region;
                const reservedForArrival = isReservedDay(dayNumber);
                const disabled = full || otherRegion || reservedForArrival;
                const transfer = transferDays.find(item => Number(item.day) === dayNumber);
                const reservedLabel = arrivalRestDay && dayNumber === 1 ? ' (arrival and rest)' : transfer ? ` (travel to ${escapeHtml(transfer.to)})` : '';
                return `<option value="${dayNumber}"${dayNumber === day ? ' selected' : ''}${disabled ? ' disabled' : ''}>Day ${dayNumber}${full ? ' (full)' : otherRegion ? ' (another region)' : reservedLabel}</option>`;
            }).join('');
            return `<label class="itinerary-day"><span>${escapeHtml(spot.name)}</span><select data-itinerary-destination="${escapeHtml(id)}" aria-label="Schedule ${escapeHtml(spot.name)}">${options}</select></label>`;
        }).join('');
    };
    const getItinerary = () => {
        const days = new Map();
        if (arrivalRestDay) days.set(1, ['Arrival, hotel check-in, and rest']);
        for (const transfer of transferDays) days.set(Number(transfer.day), [`Travel from ${transfer.from} to ${transfer.to}`]);
        for (const spot of selected.values()) {
            const day = itineraryAssignments.get(String(spot.id));
            if (!Number.isInteger(day)) continue;
            if (!days.has(day)) days.set(day, []);
            days.get(day).push(`${spot.region}: ${spot.name}`);
        }
        return [...days.entries()]
            .sort(([dayA], [dayB]) => dayA - dayB)
            .map(([day, destinations]) => `Day ${day}: ${destinations.join(', ')}`);
    };
    const adjustDurationToItinerary = () => {
        let lastScheduledDay = arrivalRestDay ? 1 : 0;
        for (const transfer of transferDays) {
            lastScheduledDay = Math.max(lastScheduledDay, Number(transfer.day));
        }
        for (const day of itineraryAssignments.values()) {
            if (Number.isInteger(day)) lastScheduledDay = Math.max(lastScheduledDay, day);
        }
        if (lastScheduledDay > 0 && lastScheduledDay < Number(tripDuration.value)) {
            tripDuration.value = String(lastScheduledDay);
            tripDuration.dataset.userAdjusted = 'true';
        }
    };
    const renderPlan = (refreshItinerary = true) => {
        const items = [...selected.values()];
        const hasRestDay = hasArrivalRestDay();
        if (hasRestDay !== arrivalRestDay) {
            const dayShift = hasRestDay ? 1 : -1;
            for (const [id, day] of itineraryAssignments) itineraryAssignments.set(id, day + dayShift);
            for (const transfer of transferDays) transfer.day = Number(transfer.day) + dayShift;
            for (const region of Object.keys(regionStarts)) regionStarts[region] = Number(regionStarts[region]) + dayShift;
            if (tripDuration.dataset.userAdjusted) {
                tripDuration.value = String(Math.max(1, Number(tripDuration.value) + dayShift));
            }
            arrivalRestDay = hasRestDay;
        }
        const regionCounts = new Map();
        for (const spot of items) regionCounts.set(spot.region, (regionCounts.get(spot.region) || 0) + 1);
        const lastSightseeingDay = [...regionCounts.entries()].reduce((latestDay, [region, count]) => {
            const regionEndDay = getRegionStartDay(region) + Math.ceil(count / 2) - 1;
            return Math.max(latestDay, regionEndDay);
        }, arrivalRestDay ? 1 : 0);
        const lastTransferDay = transferDays.reduce((latestDay, transfer) => Math.max(latestDay, Number(transfer.day)), 0);
        const minimumDuration = Math.max(1, lastSightseeingDay, lastTransferDay);
        const suggestedDuration = Math.max(1, Math.min(30, items.length + transferDays.length + (arrivalRestDay ? 1 : 0)));
        if (!tripDuration.dataset.userAdjusted) tripDuration.value = suggestedDuration;
        tripDuration.min = String(Math.max(1, minimumDuration));
        if (Number(tripDuration.value) < minimumDuration) tripDuration.value = String(minimumDuration);
        document.querySelector('#tripDurationNote').textContent = arrivalRestDay
            ? `Day 1 is for arrival, hotel check-in, and rest. Each region change adds a dedicated transfer day; schedule up to two destinations per sightseeing day. Duration adjusts to your final scheduled day.`
            : `Duration defaults to one day per selected destination. Each region change adds a dedicated transfer day; schedule no more than two destinations per day. Duration adjusts to your final scheduled day.`;
        const cartCount = document.querySelector('#cartCount');
        if (cartCount) cartCount.textContent = items.length;
        document.querySelector('#selectedCount').textContent = items.length;
        if (refreshItinerary) renderItineraryFields(items);
        document.querySelector('#planTotal').textContent = formatPrice(items.reduce((sum, spot) => sum + spot.price, 0));
        const selectedHotelRecord = hotels.find(hotel => hotel.id === selectedHotel && normalizeRegion(hotel.region) === normalizeRegion(activeRegion));
        const currentRegionCount = regionCounts.get(activeRegion) || 0;
        const currentRegionIndex = regionOrder.findIndex(region => normalizeRegion(region) === normalizeRegion(activeRegion));
        const hasRegionTransfer = transferDays.some(transfer => normalizeRegion(transfer.from) === normalizeRegion(activeRegion));
        const hotelRequired = currentRegionCount > 1 || currentRegionIndex > 0 || hasRegionTransfer || (arrivalRestDay && currentRegionIndex === 0);
        const requiredRegions = [...regionCounts.entries()].filter(([region, count]) => {
            const index = regionOrder.findIndex(item => normalizeRegion(item) === normalizeRegion(region));
            const hasTransfer = transferDays.some(transfer => normalizeRegion(transfer.from) === normalizeRegion(region));
            return count > 1 || index > 0 || hasTransfer || (arrivalRestDay && index === 0);
        }).map(([region]) => region);
        const accommodationValid = requiredRegions.every(region => {
            const hotelId = region === activeRegion ? selectedHotel : regionHotels.get(region);
            return hotels.some(hotel => hotel.id === hotelId && normalizeRegion(hotel.region) === normalizeRegion(region));
        });
        const itinerary = getItinerary();
        const itineraryDestinationIds = new Set(Array.from(itineraryFields.querySelectorAll('select')).map(select => select.dataset.itineraryDestination));
        const dayCounts = new Map();
        for (const day of itineraryAssignments.values()) dayCounts.set(day, (dayCounts.get(day) || 0) + 1);
        const itineraryComplete = items.length < 2 || itineraryDestinationIds.size === items.length && [...dayCounts.values()].every(count => count <= 2) && items.every(spot => {
            const day = itineraryAssignments.get(String(spot.id));
            return Number.isInteger(day) && day >= getRegionStartDay(spot.region) && day <= Number(tripDuration.value) && !isReservedDay(day);
        });
        const tripDetailsValid = Boolean(travelDate.value) && Number(tripDuration.value) >= Math.max(1, minimumDuration) && Number(passengerCount.value) > 0;
        const selectedVehicle = availableVehicles.find(vehicle => String(vehicle.id) === selectedVehicleId);
        const canAddPlan = items.length > 0 && accommodationValid && itineraryComplete && tripDetailsValid;
        const hasRegionSelection = items.length > 0 && (!requiresAdditionalRegionDestination || [...selected.keys()].some(id => !regionDestinationBaseline.has(id)));
        addToCartButton.disabled = currentWizardStep === 1 ? !hasRegionSelection : currentWizardStep === 2 ? !accommodationValid : !canAddPlan;
        addToCartButton.textContent = currentWizardStep === 1 ? 'Continue to accommodation' : currentWizardStep === 2 ? 'Continue to trip details' : editCartItemId ? 'Update cart plan' : 'Add plan to cart';
        document.querySelector('#continueToTrip').disabled = !accommodationValid;
        document.querySelector('#addToCartBottom').disabled = !canAddPlan;
        document.querySelector('#hotelSelectionNote').hidden = currentWizardStep !== 2 || !hotelRequired;
        document.querySelector('#planNote').textContent = currentWizardStep === 1
            ? (!activeRegion ? 'Choose a region, then add at least one destination.' : !hasRegionSelection ? 'Add a destination from this region before continuing.' : `${items.length} destination${items.length === 1 ? '' : 's'} selected across ${selectedRegions.size} region${selectedRegions.size === 1 ? '' : 's'}.`)
            : currentWizardStep === 2 && !accommodationValid
            ? 'Select a hotel from the selected region. A hotel is required for multiple destinations or an out-of-region pickup.'
            : !tripDetailsValid
            ? 'Choose a travel date, trip duration, and passenger count below.'
            : !selectedVehicle
            ? 'No vehicle is available right now. You can still add this trip to your cart and request vehicle confirmation.'
            : !accommodationValid
                ? 'Select a hotel from the selected region. A hotel is required for multiple destinations or an out-of-region pickup.'
            : !itineraryComplete
                ? 'Assign every destination to a day before adding this plan.'
                : items.length
                    ? 'No payment required yet. Review your itinerary in the cart first.'
                    : 'Select at least one destination to add your itinerary to the cart.';
        if (selectedHotelRecord) {
            document.querySelector('#selectedHotelName').textContent = selectedHotelRecord.name;
        } else {
            document.querySelector('#selectedHotelName').textContent = hotelRequired
                ? 'Select a hotel in your region'
                : 'Optional — no hotel selected';
        }
        document.querySelector('#selectedHotelName').closest('.plan-row').querySelector('span').textContent = hotelRequired ? `Required hotel (${activeRegion})` : `Hotel (${activeRegion || 'region'} optional)`;
        planList.innerHTML = items.length ? items.map((spot) => `<div class="plan-item"><span class="plan-thumb" style="background-image:url('${spot.image}')"></span><span><strong>${spot.name}</strong><small>${formatPrice(spot.price)}</small></span><button class="remove-spot" type="button" data-remove="${spot.id}" aria-label="Remove ${spot.name}">×</button></div>`).join('') : '<div class="plan-empty">Your plan is waiting for its first stop.<br>Browse the destinations and add one here.</div>';
    };
    const renderSelectedRegions = () => {
        selectedRegionsList.innerHTML = [...selectedRegions].map(region => {
            const count = [...selected.values()].filter(spot => normalizeRegion(spot.region) === normalizeRegion(region)).length;
            return `<span class="selected-region-tag">${escapeHtml(region)} · ${count}</span>`;
        }).join('') || '<span class="toolbar-note">No regions added yet</span>';
    };
    const setWizardStep = step => {
        currentWizardStep = step;
        regionPicker.hidden = step !== 1;
        destinationToolbar.hidden = step !== 1;
        document.querySelector('.filters').hidden = step !== 1;
        grid.hidden = step !== 1;
        destinationBuilder.classList.toggle('wizard-single', step !== 1);
        hotelToolbar.hidden = step !== 2;
        hotelBuilder.hidden = step !== 2;
        hotelBuilder.classList.add('wizard-single');
        transportStep.hidden = step !== 3;
        pickupLocation.closest('label').hidden = step !== 3;
        departureLocation.closest('label').hidden = step !== 3;
        itineraryEditor.hidden = step !== 3;
        document.querySelector('#selectedHotelName').closest('.plan-row').hidden = step === 1;
        document.querySelector('.plan-card > .plan-note').hidden = step !== 2;
        addToCartButton.hidden = step !== 1;
        document.querySelector('#accommodationActions').hidden = step !== 2;
        document.querySelectorAll('[data-wizard-step-indicator]').forEach(indicator => {
            const indicatorStep = Number(indicator.dataset.wizardStepIndicator);
            indicator.classList.toggle('active', indicatorStep === step);
            indicator.classList.toggle('complete', indicatorStep < step);
            indicator.disabled = indicatorStep > step;
            if (indicatorStep === step) indicator.setAttribute('aria-current', 'step');
            else indicator.removeAttribute('aria-current');
        });
        const introTitle = document.querySelector('.intro h1');
        const introDescription = document.querySelector('.intro h1 + p');
        if (step === 1) {
            introTitle.textContent = activeRegion ? `Destinations in ${activeRegion}` : 'Choose destinations across Mindanao';
            introDescription.textContent = 'Choose places in one region, then add more regions to the same trip if you like.';
        } else if (step === 2) {
            const currentRegionCount = [...selected.values()].filter(spot => normalizeRegion(spot.region) === normalizeRegion(activeRegion)).length;
            const currentRegionIndex = regionOrder.findIndex(region => normalizeRegion(region) === normalizeRegion(activeRegion));
            const hasRegionTransfer = transferDays.some(transfer => normalizeRegion(transfer.from) === normalizeRegion(activeRegion));
            const hotelRequired = currentRegionCount > 1 || currentRegionIndex > 0 || hasRegionTransfer || (arrivalRestDay && currentRegionIndex === 0);
            introTitle.textContent = hotelRequired ? 'Choose a hotel' : 'Hotel (optional)';
            introDescription.textContent = hotelRequired
                ? `Select a hotel listed in ${activeRegion || 'your selected region'} to continue.`
                : 'Choose a hotel in your selected region if you want to include one.';
        } else {
            introTitle.textContent = 'Set up your trip';
            introDescription.textContent = 'Add pickup and departure details, choose your date and group size, then select an available vehicle.';
        }
        renderSpots('all');
        renderPlan();
    };
    document.querySelectorAll('[data-wizard-step-indicator]').forEach(indicator => {
        indicator.addEventListener('click', () => {
            const targetStep = Number(indicator.dataset.wizardStepIndicator);
            if (targetStep <= currentWizardStep) setWizardStep(targetStep);
        });
    });
    const vehicleImageIndex = new Map();
    const vehicleImages = (vehicle) => {
        const paths = (vehicle.image_paths && vehicle.image_paths.length ? vehicle.image_paths : [vehicle.image_path]).filter(Boolean);
        return paths.map(path => /^https?:\/\//i.test(path) || path.startsWith('../') ? path : '../' + path.replace(/^\/+/, ''));
    };
    const renderAvailableVehicles = () => {
        const previousScroll = vehicleOptions.querySelector('.vehicle-track')?.scrollLeft || 0;
        if (!availableVehicles.length) { vehicleOptions.innerHTML = ''; return; }
        const cards = availableVehicles.map(vehicle => {
            const id = Number(vehicle.id);
            const images = vehicleImages(vehicle);
            const index = Math.min(vehicleImageIndex.get(id) || 0, Math.max(0, images.length - 1));
            const isSelected = String(vehicle.id) === selectedVehicleId;
            const image = images.length
                ? `<div class="vehicle-photo" style="background-image:url('${escapeHtml(images[index])}')">${images.length > 1 ? `<button class="vehicle-photo-nav prev" type="button" data-vehicle-photo="${id}" data-dir="-1" aria-label="Previous photo">‹</button><button class="vehicle-photo-nav next" type="button" data-vehicle-photo="${id}" data-dir="1" aria-label="Next photo">›</button><span class="vehicle-photo-count">${index + 1} / ${images.length}</span>` : ''}</div>`
                : '<div class="vehicle-photo vehicle-photo-empty">No photo available</div>';
            return `<article class="vehicle-option${isSelected ? ' selected' : ''}">${image}<div class="vehicle-info"><strong>${escapeHtml(vehicle.vehicle_unit)}</strong><span>${escapeHtml(vehicle.vehicle_color)} · Up to ${Number(vehicle.passenger_capacity)} passengers</span><button class="spot-button add${isSelected ? ' selected' : ''}" type="button" data-vehicle-id="${id}" aria-pressed="${isSelected}">${isSelected ? 'Selected ✓' : 'Select vehicle'}</button></div></article>`;
        }).join('');
        const nav = availableVehicles.length > 1 ? '<button class="vehicle-nav prev" type="button" data-vehicle-scroll="-1" aria-label="Previous vehicle">‹</button><button class="vehicle-nav next" type="button" data-vehicle-scroll="1" aria-label="Next vehicle">›</button>' : '';
        vehicleOptions.innerHTML = `<div class="vehicle-carousel">${nav}<div class="vehicle-track">${cards}</div></div>`;
        vehicleOptions.querySelector('.vehicle-track').scrollLeft = previousScroll;
    };
    const refreshAvailableVehicles = async () => {
        const requestId = ++availabilityRequest;
        selectedVehicleId = '';
        availableVehicles = [];
        renderAvailableVehicles();
        if (!travelDate.value || !Number(tripDuration.value) || !Number(passengerCount.value)) {
            vehicleMessage.textContent = 'Select a travel date, duration, and passenger count to check available vehicles.';
            renderPlan();
            return;
        }
        vehicleMessage.textContent = 'Checking vehicles for these trip details...';
        renderPlan();
        const params = new URLSearchParams({
            travel_date: travelDate.value,
            duration_days: tripDuration.value,
            passengers: passengerCount.value
        });
        try {
            const response = await fetch(`../api/available-vehicles.php?${params.toString()}`, { cache: 'no-store' });
            const result = await response.json();
            if (requestId !== availabilityRequest) return;
            if (!response.ok) throw new Error(result.error || 'Unable to check vehicle availability.');
            availableVehicles = result.vehicles || [];
            vehicleMessage.textContent = availableVehicles.length
                ? 'Select one of the vehicles available for your trip.'
                : 'No vehicles are available right now. You can still add your trip to the cart; vehicle confirmation will be pending.';
            renderAvailableVehicles();
        } catch (error) {
            if (requestId !== availabilityRequest) return;
            vehicleMessage.textContent = error.message;
        }
        renderPlan();
    };
    const toggleSpot = (id) => {
        const previousDuration = tripDuration.value;
        const spot = spots.find(item => item.id === id);
        if (selected.has(id)) {
            selected.delete(id);
            showToast(`${spot.name} removed from your plan`);
        } else {
            selected.set(id, spot);
            showToast(`${spot.name} added to your plan`);
        }
        renderSpots(document.querySelector('.filter.active').dataset.filter);
        renderSelectedRegions();
        renderHotels();
        renderPlan();
        if (travelDate.value && tripDuration.value !== previousDuration) refreshAvailableVehicles();
    };
    const selectHotel = (id) => { const hotel=hotels.find((item)=>item.id===id); if (!hotel || normalizeRegion(hotel.region) !== normalizeRegion(activeRegion)) return; if (selectedHotel === id) { selectedHotel = null; regionHotels.delete(activeRegion); showToast(`${hotel.name} deselected`); } else { selectedHotel = id; regionHotels.set(activeRegion, id); showToast(`${hotel.name} selected`); } renderHotels(); renderPlan(); };
    regionSelect.addEventListener('change', () => {
        activeRegion = regionSelect.value;
        selectedHotel = regionHotels.get(activeRegion) || null;
        if (activeRegion && !regionOrder.includes(activeRegion)) regionOrder.push(activeRegion);
        if (activeRegion && !regionStarts[activeRegion]) regionStarts[activeRegion] = transferDays.length
            ? Number(transferDays[transferDays.length - 1].day) + 1
            : arrivalRestDay ? 2 : 1;
        if (activeRegion && regionNames.includes(activeRegion)) selectedRegions.add(activeRegion);
        document.querySelectorAll('.filter').forEach(button => button.classList.toggle('active', button.dataset.filter === 'all'));
        renderSelectedRegions();
        renderHotels();
        setWizardStep(1);
    });
    document.querySelector('#backToDestinations').addEventListener('click', () => setWizardStep(1));
    document.querySelector('#continueToTrip').addEventListener('click', () => {
        const hotel = hotels.find(item => item.id === selectedHotel && normalizeRegion(item.region) === normalizeRegion(activeRegion));
        const currentRegionDestinations = [...selected.values()].filter(spot => normalizeRegion(spot.region) === normalizeRegion(activeRegion)).length;
        const activeRegionIndex = regionOrder.findIndex(region => normalizeRegion(region) === normalizeRegion(activeRegion));
        const hasRegionTransfer = transferDays.some(transfer => normalizeRegion(transfer.from) === normalizeRegion(activeRegion));
        const requiresHotel = currentRegionDestinations > 1 || activeRegionIndex > 0 || hasRegionTransfer || (arrivalRestDay && activeRegionIndex === 0);
        if (requiresHotel && !hotel) {
            showToast('Choose a hotel from the selected region for this trip.');
            document.querySelector('#hotelGrid').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        setWizardStep(3);
        if (travelDate.value) refreshAvailableVehicles();
    });
    document.querySelector('#backToAccommodation').addEventListener('click', () => setWizardStep(2));
    addAnotherRegionButton.addEventListener('click', () => {
        const currentRegionDestinations = [...selected.values()].filter(spot => normalizeRegion(spot.region) === normalizeRegion(activeRegion));
        const currentHotel = hotels.find(hotel => hotel.id === selectedHotel && normalizeRegion(hotel.region) === normalizeRegion(activeRegion));
        if (!currentRegionDestinations.length) {
            showToast('Add a destination in this region before adding another region.');
            return;
        }
        if (!currentHotel) {
            setWizardStep(2);
            showToast('Select a hotel for this region before continuing.');
            return;
        }
        if (!regionOrder.includes(activeRegion)) regionOrder.push(activeRegion);
        const transferDay = Number(tripDuration.value) + 1;
        saveRegionDraft({ day: transferDay, from: activeRegion });
        window.location.href = 'region_map.php?add_region=1';
    });
    travelDate.addEventListener('change', refreshAvailableVehicles);
    pickupLocation.addEventListener('change', () => {
        const previousDuration = tripDuration.value;
        renderPlan();
        if (travelDate.value && tripDuration.value !== previousDuration) refreshAvailableVehicles();
        if (hasArrivalRestDay() && !hotels.some(hotel => hotel.id === selectedHotel && normalizeRegion(hotel.region) === normalizeRegion(activeRegion))) {
            setWizardStep(2);
            document.querySelector('#hotelToolbar').scrollIntoView({ behavior: 'smooth', block: 'start' });
            showToast('Choose a hotel for your arrival and rest day.');
        }
    });
    tripDuration.addEventListener('input', () => {
        tripDuration.dataset.userAdjusted = 'true';
        renderPlan();
        refreshAvailableVehicles();
    });
    passengerCount.addEventListener('input', refreshAvailableVehicles);
    itineraryFields.addEventListener('change', event => {
        const select = event.target.closest('[data-itinerary-destination]');
        if (!select) return;
        const previousDuration = tripDuration.value;
        itineraryAssignments.set(select.dataset.itineraryDestination, Number(select.value));
        renderPlan();
        if (travelDate.value && tripDuration.value !== previousDuration) refreshAvailableVehicles();
    });
    vehicleOptions.addEventListener('click', event => {
        const scroller = event.target.closest('[data-vehicle-scroll]');
        if (scroller) {
            const track = vehicleOptions.querySelector('.vehicle-track');
            track.scrollBy({ left: Number(scroller.dataset.vehicleScroll) * track.clientWidth, behavior: 'smooth' });
            return;
        }
        const photoNav = event.target.closest('[data-vehicle-photo]');
        if (photoNav) {
            const id = Number(photoNav.dataset.vehiclePhoto);
            const vehicle = availableVehicles.find(item => Number(item.id) === id);
            const count = vehicleImages(vehicle).length;
            vehicleImageIndex.set(id, ((vehicleImageIndex.get(id) || 0) + Number(photoNav.dataset.dir) + count) % count);
            renderAvailableVehicles();
            return;
        }
        const option = event.target.closest('[data-vehicle-id]');
        if (!option) return;
        selectedVehicleId = option.dataset.vehicleId;
        renderAvailableVehicles();
        renderPlan();
    });
    const renderGallery = () => { const image = activeImages[activeImageIndex]; document.querySelector('#modalImage').style.backgroundImage=`url('${image}')`; document.querySelector('#galleryCounter').textContent=`${activeImageIndex + 1} / ${activeImages.length}`; document.querySelector('#previousImage').hidden=activeImages.length < 2; document.querySelector('#nextImage').hidden=activeImages.length < 2; document.querySelector('#galleryThumbs').hidden=activeImages.length < 2; document.querySelector('#galleryThumbs').innerHTML=activeImages.map((thumb, index)=>`<button class="gallery-thumb${index === activeImageIndex ? ' active' : ''}" type="button" data-gallery-index="${index}" style="background-image:url('${thumb}')" aria-label="View image ${index + 1}"></button>`).join(''); };
    const openDetails = (id) => { activeSpot=spots.find((spot)=>spot.id===id); activeImages=activeSpot.images?.length ? activeSpot.images : [activeSpot.image]; activeImageIndex=0; renderGallery(); document.querySelector('#modalRegion').textContent=activeSpot.region; document.querySelector('#modalTitle').textContent=activeSpot.name; document.querySelector('#modalDescription').textContent=activeSpot.description; document.querySelector('#modalInfo').innerHTML=`<span>${activeSpot.days}</span><span>From ${formatPrice(activeSpot.price)}</span>${activeSpot.tags.map((tag)=>`<span>${tag}</span>`).join('')}`; const add=document.querySelector('#modalAdd'); add.textContent=selected.has(activeSpot.id)?'Remove from my plan':'Add to my plan'; document.querySelector('#detailsModal').hidden=false; add.focus(); };
    const openHotelDetails = (id) => { const hotel=hotels.find((item)=>item.id===id); document.querySelector('#modalRegion').textContent=hotel.location; document.querySelector('#modalTitle').textContent=hotel.name; document.querySelector('#modalDescription').textContent=hotel.description || 'Comfortable accommodation for your stay.'; document.querySelector('#modalImage').style.backgroundImage=`url('${hotel.image}')`; document.querySelector('#modalInfo').innerHTML=`<span>${hotel.rating > 0 ? hotel.rating + ' ★' : 'Not rated'}</span><span>${formatPrice(hotel.price_per_night)} / night</span>${hotel.amenities ? hotel.amenities.split(',').map(a=>`<span>${a.trim()}</span>`).join('') : ''}`; const add=document.querySelector('#modalAdd'); add.textContent=selectedHotel === hotel.id?'Remove hotel':'Select hotel'; add.onclick=()=>{ selectHotel(hotel.id); document.querySelector('#detailsModal').hidden=true; }; document.querySelector('#detailsModal').hidden=false; add.focus(); };
    grid.addEventListener('click',(event)=>{ const detail=event.target.closest('[data-details]'); const add=event.target.closest('[data-add]'); if(detail) openDetails(detail.dataset.details); if(add) toggleSpot(add.dataset.add); });
    document.querySelector('#hotelGrid').addEventListener('click',(event)=>{ const detail=event.target.closest('[data-hotel-details]'); const select=event.target.closest('[data-hotel-select]'); if(detail) openHotelDetails(detail.dataset.hotelDetails); if(select) selectHotel(select.dataset.hotelSelect); });
    planList.addEventListener('click',(event)=>{ const remove=event.target.closest('[data-remove]'); if(remove) toggleSpot(remove.dataset.remove); });
    document.querySelectorAll('.filter').forEach((button)=>button.addEventListener('click',()=>{ document.querySelectorAll('.filter').forEach((item)=>item.classList.toggle('active',item===button)); renderSpots(button.dataset.filter); }));
    document.querySelector('#closeModal').addEventListener('click',()=>document.querySelector('#detailsModal').hidden=true);
    document.querySelector('#detailsModal').addEventListener('click',(event)=>{ if(event.target.id==='detailsModal') event.currentTarget.hidden=true; });
    document.addEventListener('keydown',(event)=>{ if(event.key==='Escape') document.querySelector('#detailsModal').hidden=true; });
    document.querySelector('#modalAdd').addEventListener('click',()=>{ if(activeSpot) toggleSpot(activeSpot.id); document.querySelector('#detailsModal').hidden=true; });
    document.querySelector('#previousImage').addEventListener('click',()=>{ activeImageIndex=(activeImageIndex - 1 + activeImages.length) % activeImages.length; renderGallery(); });
    document.querySelector('#nextImage').addEventListener('click',()=>{ activeImageIndex=(activeImageIndex + 1) % activeImages.length; renderGallery(); });
    document.querySelector('#galleryThumbs').addEventListener('click',(event)=>{ const thumb=event.target.closest('[data-gallery-index]'); if(thumb){ activeImageIndex=Number(thumb.dataset.galleryIndex); renderGallery(); } });
    document.querySelector('#addToCartBottom').addEventListener('click', () => document.querySelector('#addToCart').click());
    document.querySelector('#addToCart').addEventListener('click', async () => {
        const selectedSpots = [...selected.values()];
        if (currentWizardStep === 1) {
            if (!activeRegion) {
                showToast('Choose a region before selecting destinations.');
                regionSelect.focus();
                return;
            }
            if (!selectedSpots.length || (requiresAdditionalRegionDestination && selectedSpots.length <= regionDestinationBaseline)) {
                showToast('Add at least one destination from this region to continue.');
                return;
            }
            requiresAdditionalRegionDestination = false;
            setWizardStep(2);
            return;
        }
        if (!selectedSpots.length) return;
        if (!travelDate.value) {
            showToast('Choose a travel date first.');
            document.querySelector('#transportStep').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (!pickupLocation.value.trim() || !departureLocation.value.trim()) {
            showToast('Choose a pickup and departure location first.');
            (!pickupLocation.value.trim() ? pickupLocation : departureLocation).focus();
            return;
        }
        syncCurrentHotel();
        const missingHotelRegion = regionOrder.find((region, index) => {
            const regionSpots = selectedSpots.filter(spot => normalizeRegion(spot.region) === normalizeRegion(region));
            const hasTransfer = transferDays.some(transfer => normalizeRegion(transfer.from) === normalizeRegion(region));
            const hotelRequired = regionSpots.length > 1 || index > 0 || hasTransfer || (arrivalRestDay && index === 0);
            return hotelRequired && !hotels.some(hotel => hotel.id === regionHotels.get(region) && normalizeRegion(hotel.region) === normalizeRegion(region));
        });
        if (missingHotelRegion) {
            showToast(`Choose a hotel in ${missingHotelRegion} before adding this trip.`);
            if (normalizeRegion(missingHotelRegion) !== normalizeRegion(activeRegion)) {
                activeRegion = missingHotelRegion;
                regionSelect.value = missingHotelRegion;
                selectedHotel = regionHotels.get(activeRegion) || null;
                renderHotels();
            }
            setWizardStep(2);
            document.querySelector('#hotelToolbar').scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        const itinerary = getItinerary();
        const itineraryDestinationIds = new Set(Array.from(itineraryFields.querySelectorAll('select')).map(select => select.dataset.itineraryDestination));
        const itineraryDayCounts = new Map();
        for (const spot of selectedSpots) {
            const day = itineraryAssignments.get(String(spot.id));
            itineraryDayCounts.set(day, (itineraryDayCounts.get(day) || 0) + 1);
        }
        const itineraryRegionsByDay = new Map();
        for (const spot of selectedSpots) {
            const day = itineraryAssignments.get(String(spot.id));
            if (itineraryRegionsByDay.has(day) && itineraryRegionsByDay.get(day) !== spot.region) itineraryRegionsByDay.set(day, false);
            else itineraryRegionsByDay.set(day, spot.region);
        }
        const itineraryComplete = (selectedSpots.length < 2 || itineraryDestinationIds.size === selectedSpots.length) &&
            [...itineraryDayCounts.values()].every(count => count <= 2) &&
            [...itineraryRegionsByDay.values()].every(region => region !== false) &&
            selectedSpots.every(spot => {
            const day = itineraryAssignments.get(String(spot.id));
            return Number.isInteger(day) && day >= getRegionStartDay(spot.region) && day <= Number(tripDuration.value) && !isReservedDay(day);
        });
        if (!itineraryComplete) {
            showToast('Assign every destination to a valid sightseeing day, with no more than two destinations per day.');
            return;
        }

        const title = `Custom Itinerary (${selectedSpots.length} destinations, ${regionOrder.length} regions)`;
        const spotNames = selectedSpots.map((spot) => spot.name).join(', ');
        let details = `${selectedSpots.length} destination${selectedSpots.length === 1 ? '' : 's'} · ${spotNames}`;
        const hotelDetails = regionOrder.map(region => {
            const hotel = hotels.find(item => item.id === regionHotels.get(region) && normalizeRegion(item.region) === normalizeRegion(region));
            return hotel ? `${region}: ${hotel.name}` : '';
        }).filter(Boolean);
        if (hotelDetails.length) details += ` · Hotel: ${hotelDetails.join('; ')}`;
        if (itinerary.length) details += ` · Itinerary: ${itinerary.join(' | ')}`;
        
        const total = selectedSpots.reduce((sum, spot) => sum + spot.price, 0);
        const imageUrl = selectedSpots[0]?.image || '../assets/logo/logo.png';

        const firstSelectedHotelId = regionOrder.map(region => regionHotels.get(region)).find(Boolean) || null;
        const planDraft = createPlanDraft();

        try {
            const response = await fetch('cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: editCartItemId ? 'update_plan' : 'add',
                    id: editCartItemId,
                    item_type: 'custom',
                    hotel_id: firstSelectedHotelId,
                    accommodation_type: firstSelectedHotelId ? 'listed_hotel' : 'none',
                    title: title,
                    details: details,
                    image_url: imageUrl,
                    travelers: Number(passengerCount.value),
                    travel_date: travelDate.value,
                    trip_duration_days: Number(tripDuration.value),
                    requested_vehicle_id: selectedVehicleId ? Number(selectedVehicleId) : null,
                    pickup_location: pickupLocation.value.trim(),
                    departure_location: departureLocation.value.trim(),
                    unit_price: total
                })
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.error || (editCartItemId ? 'The cart plan could not be updated.' : 'The itinerary could not be added to your cart.'));
            }
            const savedPlans = JSON.parse(localStorage.getItem('mtrCartPlanDrafts') || '{}');
            savedPlans[String(editCartItemId || result.id)] = planDraft;
            localStorage.setItem('mtrCartPlanDrafts', JSON.stringify(savedPlans));
        } catch (err) {
            console.error('Failed to sync to cart:', err);
            showToast(err.message);
            return;
        }

        localStorage.removeItem('mtrCustomPlan');
        localStorage.removeItem(draftKey);
        window.location.href = 'cart.php';
    });
    renderSelectedRegions();
    setWizardStep(1);
    renderHotels();
})();
</script>
</body>
</html>
