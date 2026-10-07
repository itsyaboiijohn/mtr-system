<?php
require_once __DIR__ . '/../api/auth.php';
require_user_auth();
require_once __DIR__ . '/../api/db_con.php';

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

$packageId = filter_input(INPUT_GET, 'package_id', FILTER_VALIDATE_INT);
if (!$packageId) {
    $packageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}
if (!$packageId) {
    http_response_code(400);
    exit('A valid package is required.');
}

$packageStatement = $database->prepare("SELECT Travel_Pck_ID, Hotel_ID, name, description, duration_days, price_per_person FROM travel_packages WHERE Travel_Pck_ID = ? AND status = 'published' AND (visibility_minutes IS NULL OR published_at IS NULL OR DATE_ADD(published_at, INTERVAL visibility_minutes MINUTE) > NOW()) LIMIT 1");
$packageStatement->execute([$packageId]);
$package = $packageStatement->fetch();
if (!$package) {
    http_response_code(404);
    exit('Package not found.');
}

$hotelList = $database->query("SELECT Hotel_ID, name, location, price_per_night FROM hotels WHERE status = 'active' ORDER BY name")->fetchAll();

$tierStatement = $database->prepare('SELECT min_pax, max_pax, price_per_pax FROM travel_package_price_tiers WHERE Travel_Pck_ID = ? ORDER BY min_pax');
$tierStatement->execute([$packageId]);
$priceTiers = $tierStatement->fetchAll();
if (!$priceTiers) {
    $priceTiers = [['min_pax' => 1, 'max_pax' => 50, 'price_per_pax' => $package['price_per_person']]];
}
$maxPax = min(50, max(array_map(static fn ($tier) => (int) $tier['max_pax'], $priceTiers)));
$baseDuration = max(1, (int) $package['duration_days']);
$maxDuration = max($baseDuration, 14);

$addonStatement = $database->prepare("SELECT Travel_Pck_ID, name, description, duration_days, price_per_person
    FROM travel_packages
    WHERE status = 'published'
      AND (visibility_minutes IS NULL OR published_at IS NULL OR DATE_ADD(published_at, INTERVAL visibility_minutes MINUTE) > NOW())
      AND Travel_Pck_ID <> ?
    ORDER BY created_at DESC");
$addonStatement->execute([$packageId]);
$addons = $addonStatement->fetchAll();

$packageDestinationStatement = $database->prepare("SELECT d.Destination_ID, d.name, d.description, d.price,
    COALESCE((SELECT di.image_path FROM destination_images di WHERE di.Destination_ID = d.Destination_ID ORDER BY di.Destination_Image_ID LIMIT 1), d.image_url) AS image_path
    FROM destinations d
    INNER JOIN travel_package_destinations pd ON pd.Destination_ID = d.Destination_ID
    WHERE pd.Travel_Pck_ID = ? AND d.status = 'active'
    ORDER BY d.name");
$packageDestinationStatement->execute([$packageId]);
$packageDestinations = $packageDestinationStatement->fetchAll();

$destinationStatement = $database->prepare("SELECT d.Destination_ID, d.name, d.description, d.price,
    COALESCE((SELECT di.image_path FROM destination_images di WHERE di.Destination_ID = d.Destination_ID ORDER BY di.Destination_Image_ID LIMIT 1), d.image_url) AS image_path
    FROM destinations d
    WHERE d.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM travel_package_destinations pd
          WHERE pd.Travel_Pck_ID = ? AND pd.Destination_ID = d.Destination_ID
      )
    ORDER BY d.name");
$destinationStatement->execute([$packageId]);
$availableDestinations = $destinationStatement->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customize Your Trip | Mindanao Travel & Tours</title>
    <style>
        :root { --bg: #f0faff; --card: #fff; --ink: #0f1724; --muted: #64748b; --line: #dce8ef; --accent: #0ea5e9; --accent-dark: #0369a1; --soft: #e6f9ff; --shadow: 0 18px 45px rgba(2, 6, 23, .07); font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: radial-gradient(circle at 85% 0, #d8f4ff 0, transparent 34%), var(--bg); color: var(--ink); }
        a { color: inherit; }
        .page { width: min(1180px, calc(100% - 40px)); margin: auto; padding: 24px 20px 50px; }
        .header { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 50px; }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .logo { display: grid; width: 44px; height: 44px; place-items: center; overflow: hidden; border: 1px solid rgba(14,165,233,.15); border-radius: 10px; background: #fff; box-shadow: var(--shadow); }
        .logo img { width: 100%; height: 100%; object-fit: contain; }
        .brand strong, .brand small { display: block; } .brand strong { font-size: 15px; } .brand small { margin-top: 3px; color: var(--muted); font-size: 12px; }
        .back { color: var(--accent-dark); font-weight: 700; text-decoration: none; }
        .back:hover { text-decoration: underline; }
        .intro { max-width: 680px; margin-bottom: 25px; } .eyebrow { margin: 0 0 8px; color: var(--accent-dark); font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(30px, 4vw, 46px); letter-spacing: -.8px; } .intro p { margin: 10px 0 0; color: var(--muted); line-height: 1.55; }
        .layout { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(280px, .75fr); gap: 24px; align-items: start; }
        .card { border: 1px solid var(--line); border-radius: 14px; background: var(--card); box-shadow: var(--shadow); }
        .planner { padding: 24px; } .section-heading { display: flex; justify-content: space-between; align-items: start; gap: 16px; margin-bottom: 18px; } h2 { margin: 0; font-size: 19px; } .section-heading p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 28px; } label span { display: block; margin-bottom: 7px; font-size: 12px; font-weight: 800; } input, select { width: 100%; height: 44px; padding: 0 11px; border: 1px solid var(--line); border-radius: 9px; background: #fff; color: var(--ink); font: inherit; }
        .plan-list { display: grid; gap: 10px; } .plan { display: grid; grid-template-columns: 22px 58px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 12px; border: 1px solid var(--line); border-radius: 10px; cursor: pointer; transition: border-color 160ms, background 160ms; } .plan:hover, .plan.selected { border-color: rgba(14,165,233,.55); background: #f7fdff; }
        .destination-thumb { width: 58px; height: 48px; margin-top: -2px; border-radius: 8px; background: #dff5ff center / cover no-repeat; }
        .destination-plan-copy { min-width: 0; }
        .plan input { width: 18px; height: 18px; margin: 2px 0 0; accent-color: var(--accent); } .plan h3 { margin: 0 0 4px; font-size: 15px; } .plan p { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.45; } .plan-price { color: var(--accent-dark); font-size: 13px; font-weight: 800; white-space: nowrap; }
        .included-list { display: grid; gap: 10px; margin-bottom: 28px; }
        .included-plan { display: grid; grid-template-columns: 58px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 12px; border: 1px solid #b8e3f2; border-radius: 10px; background: #f7fdff; }
        .included-plan h3 { margin: 0 0 4px; font-size: 15px; }
        .included-plan p { margin: 0; color: var(--muted); font-size: 12px; }
        .included-label { color: var(--green, #047857); font-size: 12px; font-weight: 800; white-space: nowrap; }
        .duration-note { grid-column: 1 / -1; margin: -4px 0 0; color: var(--muted); font-size: 12px; line-height: 1.5; }
        .duration-warning { display: none; grid-column: 1 / -1; margin: -16px 0 0; color: #b45309; font-size: 12px; font-weight: 700; line-height: 1.5; }
        .duration-warning.visible { display: block; }
        .add-plan { display: inline-flex; align-items: center; gap: 7px; margin-top: 14px; padding: 0; border: 0; background: transparent; color: var(--accent-dark); font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; } .add-plan:hover { color: var(--accent); }
        .custom-plan { display: none; grid-template-columns: 1fr 130px; gap: 10px; margin-top: 12px; padding: 14px; border-radius: 10px; background: var(--soft); } .custom-plan.visible { display: grid; }
        .summary { position: sticky; top: 20px; padding: 22px; } .summary h2 { margin-bottom: 18px; } .summary-row { display: flex; justify-content: space-between; gap: 16px; margin: 13px 0; color: var(--muted); font-size: 14px; } .summary-row strong { color: var(--ink); text-align: right; } .summary-total { display: flex; justify-content: space-between; margin-top: 21px; padding-top: 17px; border-top: 1px solid var(--line); font-size: 18px; font-weight: 800; } .summary-total span:last-child { color: var(--accent-dark); }
        .button { display: inline-flex; width: 100%; min-height: 47px; align-items: center; justify-content: center; margin-top: 22px; border: 0; border-radius: 9px; background: var(--accent); color: #fff; font: inherit; font-weight: 800; cursor: pointer; } .button:hover { background: var(--accent-dark); } .note { margin: 12px 0 0; color: var(--muted); font-size: 12px; line-height: 1.5; text-align: center; }
        @media (max-width: 760px) { .page { width: min(100% - 28px, 580px); padding-top: 20px; } .header { margin-bottom: 38px; } .layout { grid-template-columns: 1fr; } .summary { position: static; } }
        @media (max-width: 480px) { .field-grid { grid-template-columns: 1fr; } .custom-plan { grid-template-columns: 1fr; } .header { align-items: flex-start; } .back { white-space: nowrap; } }
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
        <header class="header">
            <a class="brand" href="dashboard.php"><span class="logo"><img src="../assets/logo/logo.png" alt="MTR logo"></span><span><strong>Mindanao Travel & Tours</strong><small>Build a trip that feels like yours</small></span></a>
            <a class="back" href="package.php?id=<?= (int) $packageId ?>">← Back to package</a>
        </header>
        <section class="intro"><p class="eyebrow">Personalize your itinerary</p><h1>Make this trip yours.</h1><p>Keep the essentials you love, then add experiences that fit your pace. Your selections update the estimate instantly.</p></section>
        <div class="layout">
            <section class="card planner">
                <div class="section-heading"><div><h2>Trip details</h2><p>Adjust the basics before choosing add-ons.</p></div></div>
                <div class="field-grid"><label><span>Travel date</span><input id="travelDate" type="date"></label><label><span>Travelers</span><select id="travelerCount"><?php for ($pax = 1; $pax <= $maxPax; $pax++): ?><option value="<?= $pax ?>"><?= $pax ?> <?= $pax === 1 ? 'traveler' : 'travelers' ?></option><?php endfor; ?></select></label><label><span>Trip duration</span><select id="durationDays"><?php for ($days = $baseDuration; $days <= $maxDuration; $days++): ?><option value="<?= $days ?>"><?= $days ?> <?= $days === 1 ? 'day' : 'days' ?><?= $days === $baseDuration ? ' (package)' : '' ?></option><?php endfor; ?></select></label><label><span>Pickup location</span><input id="pickupLocation" list="mindanaoTransportLocations" type="text" maxlength="180" placeholder="e.g. Davao International Airport" required></label><label><span>Departure location</span><input id="departureLocation" list="mindanaoTransportLocations" type="text" maxlength="180" placeholder="e.g. Dapa Port, Siargao" required></label><p class="duration-note" id="durationNote">The package starts at <?= $baseDuration ?> days. Extra days are priced using the package daily rate.</p><p class="duration-warning" id="durationWarning"></p></div>
                    <div class="field-grid"><label><span>Overnight accommodation</span><select id="accommodationType"><option value="none">No overnight stay</option><option value="listed_hotel">Choose a hotel from the list</option><option value="custom_hotel">Enter a hotel</option><option value="friend_or_family">Staying with a friend or family</option><option value="other">Other accommodation</option></select></label><label id="accommodationDetails" hidden><span>Hotel</span><select id="hotelId"><option value="">Choose a hotel</option><?php foreach ($hotelList as $hotel): ?><option value="<?= (int) $hotel['Hotel_ID'] ?>"><?= htmlspecialchars($hotel['name'] . ' - ' . $hotel['location'], ENT_QUOTES, 'UTF-8') ?><?= (float) $hotel['price_per_night'] > 0 ? ' (from PHP ' . number_format((float) $hotel['price_per_night'], 2) . '/night)' : '' ?></option><?php endforeach; ?></select></label><label id="accommodationNameField"><span>Name or description</span><input id="accommodationName" type="text" maxlength="255" placeholder="e.g. Friend's house or custom hotel name"></label><label id="accommodationAddressField"><span>Accommodation location</span><input id="accommodationAddress" type="text" maxlength="500" placeholder="Enter the address or area"></label><label><span>Google Maps link <small>(optional)</small></span><input id="accommodationMapsLink" type="url" maxlength="500" placeholder="Paste a Google Maps link"></label><p class="duration-note" id="accommodationNote">Accommodation is arranged and paid for by the tourist. It is not included in the MTR payment.</p></div>
                <div class="section-heading"><div><h2>Included destinations</h2><p>These destinations are already part of this package.</p></div></div>
                <div class="included-list">
                    <?php if (!$packageDestinations): ?>
                        <p class="plan"><span></span><span class="destination-plan-copy"><strong>No destinations assigned yet.</strong></span></p>
                    <?php else: ?>
                        <?php foreach ($packageDestinations as $destination): ?>
                        <?php
                            $destinationImage = trim((string) ($destination['image_path'] ?? ''));
                            $destinationImageUrl = $destinationImage !== ''
                                ? (preg_match('/^https?:\/\//i', $destinationImage) ? $destinationImage : '../' . ltrim($destinationImage, '/'))
                                : '';
                        ?>
                        <div class="included-plan">
                            <span class="destination-thumb" role="img" aria-label="<?= htmlspecialchars($destination['name'], ENT_QUOTES, 'UTF-8') ?>" style="background-image:url('<?= htmlspecialchars($destinationImageUrl, ENT_QUOTES, 'UTF-8') ?>')"></span>
                            <span class="destination-plan-copy"><h3><?= htmlspecialchars($destination['name'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($destination['description'] ?: 'Included in this travel package.', ENT_QUOTES, 'UTF-8') ?></p></span>
                            <span class="included-label">Included</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="section-heading"><div><h2>Add destinations</h2><p>Select one or more destinations to add to your current package.</p></div></div>
                <div class="plan-list" id="planList">
                    <?php if (!$availableDestinations): ?>
                        <p class="plan"><span></span><span class="destination-plan-copy"><strong>All destinations are already included.</strong></span></p>
                    <?php endif; ?>
                    <?php foreach ($availableDestinations as $destination): ?>
                    <?php
                        $destinationImage = trim((string) ($destination['image_path'] ?? ''));
                        $destinationImageUrl = $destinationImage !== ''
                            ? (preg_match('/^https?:\/\//i', $destinationImage) ? $destinationImage : '../' . ltrim($destinationImage, '/'))
                            : '';
                    ?>
                    <label class="plan"><input type="checkbox" name="destination" value="<?= (int) $destination['Destination_ID'] ?>" data-destination-name="<?= htmlspecialchars($destination['name'], ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars((string) $destination['price'], ENT_QUOTES, 'UTF-8') ?>"><span class="destination-thumb" role="img" aria-label="<?= htmlspecialchars($destination['name'], ENT_QUOTES, 'UTF-8') ?>" style="background-image:url('<?= htmlspecialchars($destinationImageUrl, ENT_QUOTES, 'UTF-8') ?>')"></span><span class="destination-plan-copy"><h3><?= htmlspecialchars($destination['name'], ENT_QUOTES, 'UTF-8') ?></h3></span><span class="plan-price">+ ₱ <?= number_format((float) $destination['price'], 2) ?></span></label>
                    <?php endforeach; ?>
                </div>
           
                <div class="custom-plan" id="customPlan"><label><span>Plan name</span><input id="customName" type="text" placeholder="e.g. Bukidnon Highlands"></label><label><span>Estimated price</span><input id="customPrice" type="number" min="0" step="100" placeholder="0"></label></div>
            </section>
            <aside class="card summary"><h2>Your trip estimate</h2><div class="summary-row"><span>Base package</span><strong id="baseLabel"><?= htmlspecialchars($package['name'], ENT_QUOTES, 'UTF-8') ?></strong></div><div class="summary-row"><span>Travelers</span><strong id="travelerLabel">1 traveler</strong></div><div class="summary-row"><span>Added destinations</span><strong id="addonLabel">None</strong></div><div class="summary-total"><span>Estimated total</span><span id="total">₱ 0.00</span></div><button class="button" id="saveTrip" type="button">Save customized trip</button><p class="note">You can still review everything in your cart before payment.</p></aside>
        </div>
    </main>
    <script>
        (() => {
            const params = new URLSearchParams(window.location.search);
            const packageId = <?= (int) $packageId ?>;
            const packageHotelId = <?= json_encode($package['Hotel_ID'] ?? null) ?>;
            const baseName = <?= json_encode($package['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const priceTiers = <?= json_encode($priceTiers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            const date = document.querySelector('#travelDate');
            const travelers = document.querySelector('#travelerCount');
            const durationDays = document.querySelector('#durationDays');
            const durationNote = document.querySelector('#durationNote');
            const durationWarning = document.querySelector('#durationWarning');
            const pickupLocation = document.querySelector('#pickupLocation');
            const departureLocation = document.querySelector('#departureLocation');
            const accommodationType = document.querySelector('#accommodationType');
            const accommodationDetails = document.querySelector('#accommodationDetails');
            const hotelId = document.querySelector('#hotelId');
            const accommodationName = document.querySelector('#accommodationName');
            const accommodationAddress = document.querySelector('#accommodationAddress');
            const accommodationMapsLink = document.querySelector('#accommodationMapsLink');
            const hotelField = document.querySelector('#hotelId').closest('label');
            const accommodationNameField = document.querySelector('#accommodationNameField');
            const accommodationAddressField = document.querySelector('#accommodationAddressField');
            const accommodationMapsField = accommodationMapsLink.closest('label');
            const accommodationNote = document.querySelector('#accommodationNote');
            const total = document.querySelector('#total');
            const addonLabel = document.querySelector('#addonLabel');
            const customPlan = document.querySelector('#customPlan');
            const customName = document.querySelector('#customName');
            const customPrice = document.querySelector('#customPrice');
            const baseDuration = <?= $baseDuration ?>;
            const includedDestinationCount = <?= count($packageDestinations) ?>;
            let durationTouched = false;
            let totalDestinationCount = includedDestinationCount;
            const requiresAccommodation = (destinationCount = totalDestinationCount) => destinationCount >= 2;
            const syncAccommodation = (destinationCount = totalDestinationCount) => {
                const mustStayOvernight = requiresAccommodation(destinationCount);
                if (mustStayOvernight && accommodationType.value === 'none') {
                    accommodationType.value = 'listed_hotel';
                }
                const hasOvernightStay = accommodationType.value !== 'none';
                accommodationType.querySelector('option[value="none"]').hidden = mustStayOvernight;
                accommodationDetails.hidden = !hasOvernightStay;
                hotelField.hidden = accommodationType.value !== 'listed_hotel';
                accommodationNameField.hidden = !hasOvernightStay || accommodationType.value === 'listed_hotel';
                accommodationAddressField.hidden = !hasOvernightStay || accommodationType.value === 'listed_hotel';
                accommodationMapsField.hidden = !hasOvernightStay;
                accommodationNote.textContent = mustStayOvernight
                    ? 'Trips with two or more destinations require an overnight accommodation. Choose a listed hotel or enter your own.'
                    : 'Accommodation is arranged and paid for by the tourist. It is not included in the MTR payment.';
            };
            accommodationType.addEventListener('change', syncAccommodation);
            syncAccommodation();
            document.querySelector('#baseLabel').textContent = baseName;
            date.value = params.get('date') || '';
            travelers.value = params.get('travelers') || '1';
            const formatPrice = (value) => `₱ ${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            const basePriceFor = (count) => {
                const tier = priceTiers.find(item => count >= Number(item.min_pax) && count <= Number(item.max_pax));
                return tier ? Number(tier.price_per_pax) : Number(priceTiers[priceTiers.length - 1].price_per_pax);
            };
            const recommendedDurationFor = (destinationCount) => baseDuration + Math.max(0, Math.ceil((destinationCount - 3) / 2));
            const syncDurationRecommendation = (destinationCount) => {
                const recommended = Math.min(Number(durationDays.options[durationDays.options.length - 1].value), recommendedDurationFor(destinationCount));
                if (!durationTouched) durationDays.value = String(recommended);
                durationNote.textContent = recommended > baseDuration
                    ? `We recommend ${recommended} days for ${destinationCount} destinations. You can adjust the duration above.`
                    : `The package starts at ${baseDuration} days. Extra days are priced using the package daily rate.`;
            };
            const update = () => {
                const selectedDestinations = Array.from(document.querySelectorAll('input[name="destination"]:checked'));
                const addon = selectedDestinations.reduce((sum, destination) => sum + Number(destination.dataset.price || 0), 0);
                const name = selectedDestinations.length
                    ? selectedDestinations.map(destination => destination.dataset.destinationName).join(', ')
                    : 'None';
                const travelersCount = Number(travelers.value);
                totalDestinationCount = includedDestinationCount + selectedDestinations.length;
                syncDurationRecommendation(totalDestinationCount);
                syncAccommodation(totalDestinationCount);
                const selectedDuration = Number(durationDays.value);
                const dailyRate = basePriceFor(travelersCount) / baseDuration;
                const extraDayCost = Math.max(0, selectedDuration - baseDuration) * dailyRate;
                document.querySelector('#travelerLabel').textContent = `${travelersCount} traveler${travelersCount === 1 ? '' : 's'}`;
                addonLabel.textContent = addon ? name : 'None';
                total.textContent = formatPrice((basePriceFor(travelersCount) + extraDayCost + addon) * travelersCount);
                const destinationCapacity = selectedDuration * 2;
                durationWarning.textContent = totalDestinationCount > destinationCapacity
                    ? `${totalDestinationCount} destinations may be too much for ${selectedDuration} days. Consider adding more days.`
                    : '';
                durationWarning.classList.toggle('visible', totalDestinationCount > destinationCapacity);
                document.querySelectorAll('.plan').forEach((plan) => plan.classList.toggle('selected', plan.querySelector('input').checked));
            };
            document.querySelectorAll('input[name="destination"], #travelerCount').forEach((control) => control.addEventListener('change', update));
            durationDays.addEventListener('change', () => { durationTouched = true; update(); });
            const addPlan = document.querySelector('#addPlan');
            if (addPlan) addPlan.addEventListener('click', () => {
                const isVisible = customPlan.classList.toggle('visible');
                addPlan.setAttribute('aria-expanded', String(isVisible));
                if (isVisible) customName.focus();
            });
            document.querySelector('#saveTrip').addEventListener('click', async () => {
                if (requiresAccommodation() && accommodationType.value === 'none') {
                    window.alert('Trips with two or more destinations require one primary accommodation location.');
                    accommodationType.focus();
                    return;
                }
                if (accommodationType.value === 'listed_hotel' && !hotelId.value) {
                    window.alert('Choose a hotel from the list.');
                    hotelId.focus();
                    return;
                }
                if (accommodationType.value !== 'none' && accommodationType.value !== 'listed_hotel' && (!accommodationName.value.trim() || !accommodationAddress.value.trim())) {
                    window.alert('Enter the accommodation name and location.');
                    accommodationName.focus();
                    return;
                }
                const customValue = Number(customPrice.value) || 0;
                if (customPlan.classList.contains('visible') && customName.value.trim()) {
                    addonLabel.textContent = customName.value.trim();
                    const extraDayCost = Math.max(0, Number(durationDays.value) - baseDuration) * (basePriceFor(Number(travelers.value)) / baseDuration);
                    total.textContent = formatPrice((basePriceFor(Number(travelers.value)) + extraDayCost + customValue) * Number(travelers.value));
                }
                const selectedDestinations = Array.from(document.querySelectorAll('input[name="destination"]:checked'));
                const travelerCount = Number(travelers.value) || 1;
                const totalAmount = Number(total.textContent.replace(/[^\d.]/g, '')) || 0;
                const unitPrice = travelerCount > 0 ? totalAmount / travelerCount : totalAmount;
                const addonNames = selectedDestinations.map(destination => destination.dataset.destinationName).join(', ') || 'None';
                const details = `Duration: ${durationDays.value} days · Date: ${date.value || 'TBD'} · Destination count: ${totalDestinationCount} · Addons: ${addonNames}`;
                const title = baseName + ' (Customized)';

                localStorage.removeItem('mtrCustomizedPackage');

                try {
                    const addResponse = await fetch('cart.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'add',
                            item_type: 'package',
                            package_id: packageId,
                            hotel_id: accommodationType.value === 'listed_hotel' ? (hotelId.value || null) : null,
                            accommodation_type: accommodationType.value,
                            accommodation_name: accommodationName.value.trim(),
                            accommodation_address: accommodationAddress.value.trim(),
                            accommodation_maps_link: accommodationMapsLink.value.trim(),
                            title: title,
                            details: details,
                            image_url: '../assets/logo/logo.png',
                            travelers: travelerCount,
                            pickup_location: pickupLocation.value.trim(),
                            departure_location: departureLocation.value.trim(),
                            unit_price: unitPrice
                        })
                    });
                    const addResult = await addResponse.json();
                    if (!addResponse.ok || !addResult.success) {
                        throw new Error(addResult.error || 'The customized trip could not be saved.');
                    }

                    const submitResponse = await fetch('cart.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'submit_plan' })
                    });
                    const submitResult = await submitResponse.json();
                    if (!submitResponse.ok || !submitResult.success) {
                        throw new Error(submitResult.error || 'The customized trip could not be submitted for review.');
                    }
                } catch (err) {
                    console.error('Failed to sync customized package to cart:', err);
                    window.alert(err.message);
                    return;
                }

                window.location.href = 'booking.php';
            });
            update();
        })();
    </script>
</body>
</html>