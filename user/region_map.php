<?php
require_once __DIR__ . '/../api/auth.php';
require_user_auth();
$database = require_once __DIR__ . '/../api/db_con.php';

$regionLabels = [
    'zamboanga peninsula' => 'Zamboanga Peninsula',
    'northern mindanao' => 'Northern Mindanao',
    'davao region' => 'Davao Region',
    'soccsksargen' => 'SOCCSKSARGEN',
    'caraga' => 'Caraga',
    'armm' => 'BARMM'
];
$regionColors = [
    'Zamboanga Peninsula' => '#d76b4a',
    'Northern Mindanao' => '#168c87',
    'Davao Region' => '#3478a8',
    'SOCCSKSARGEN' => '#c29332',
    'Caraga' => '#8165a7',
    'BARMM' => '#5b8b4e'
];

$boundaryPath = __DIR__ . '/../assets/philippines-admin1.geojson';
$boundaryData = json_decode((string) file_get_contents($boundaryPath), true);
$regionFeatures = [];
foreach (($boundaryData['features'] ?? []) as $feature) {
    $sourceName = strtolower(trim((string) ($feature['properties']['shapeName'] ?? '')));
    if (!isset($regionLabels[$sourceName])) {
        continue;
    }
    $regionName = $regionLabels[$sourceName];
    $feature['properties']['region_name'] = $regionName;
    $feature['properties']['region_color'] = $regionColors[$regionName];
    $regionFeatures[] = $feature;
}

$destinations = $database->query("SELECT Destination_ID AS id, name, location, region, category, description, price, image_url
    FROM destinations WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$destinationImages = $database->query('SELECT Destination_ID AS destination_id, image_path FROM destination_images ORDER BY Destination_ID, Destination_Image_ID')->fetchAll(PDO::FETCH_ASSOC);
$imagesByDestination = [];
foreach ($destinationImages as $destinationImage) {
    $imagesByDestination[(int) $destinationImage['destination_id']][] = $destinationImage['image_path'];
}
foreach ($destinations as &$destination) {
    $destination['image_paths'] = $imagesByDestination[(int) $destination['id']] ?? [];
    if (!$destination['image_paths'] && !empty($destination['image_url'])) {
        $destination['image_paths'][] = $destination['image_url'];
    }
}
unset($destination);
$mindanaoGeoJson = ['type' => 'FeatureCollection', 'features' => $regionFeatures];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan by Region | Mindanao Travel & Tours</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root {
            --ink: #102a43;
            --muted: #65788a;
            --paper: #f2f7f7;
            --line: #dce6e8;
            --teal: #168c87;
            --coral: #d76b4a;
            --card: #fff;
            --shadow: 0 12px 32px rgba(16,42,67,.08);
            font-family: "Aptos", "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--paper); color: var(--ink); }
        a { color: inherit; text-decoration: none; }
        .page { width: min(1400px, calc(100% - 40px)); margin: 0 auto; padding: 22px 0 42px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 24px; }
        .brand { display: flex; align-items: center; gap: 11px; }
        .brand img { width: 42px; height: 42px; padding: 4px; border-radius: 9px; background: #fff; object-fit: contain; }
        .brand strong, .brand span { display: block; }
        .brand strong { font-size: 14px; }
        .brand span { margin-top: 3px; color: var(--muted); font-size: 11px; }
        .top-actions { display: flex; gap: 9px; }
        .top-link { display: inline-flex; min-height: 38px; align-items: center; justify-content: center; padding: 0 12px; border: 1px solid var(--line); border-radius: 7px; background: #fff; color: var(--ink); font-size: 12px; font-weight: 700; }
        .top-link.primary { border-color: var(--teal); background: var(--teal); color: #fff; }
        .dashboard-back { min-height: 38px; padding: 0; border: 0; background: transparent; color: #176d75; font-size: 13px; font-weight: 700; }
        .dashboard-back:hover { text-decoration: underline; }
        .intro { margin: 0 0 20px; }
        .eyebrow { margin: 0 0 6px; color: var(--coral); font-size: 11px; font-weight: 800; text-transform: uppercase; }
        h1 { margin: 0; font-size: 28px; line-height: 1.2; }
        .intro p { max-width: 720px; margin: 8px 0 0; color: var(--muted); font-size: 13px; line-height: 1.5; }
        .explorer { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(330px, .8fr); gap: 16px; align-items: stretch; }
        .map-panel, .region-panel { min-width: 0; overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: var(--card); box-shadow: var(--shadow); }
        .map-heading, .region-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 18px 12px; }
        .map-heading h2, .region-heading h2 { margin: 0; font-size: 16px; }
        .map-heading p, .region-heading p { margin: 4px 0 0; color: var(--muted); font-size: 11px; line-height: 1.4; }
        .reset-region { display: none; flex: 0 0 auto; min-height: 30px; padding: 0 9px; border: 1px solid var(--line); border-radius: 6px; background: white; color: var(--ink); font-size: 11px; font-weight: 700; }
        .reset-region.visible { display: inline-flex; align-items: center; }
        #mindanaoMap { width: 100%; height: min(68vh, 650px); min-height: 410px; background: #e5eff0; }
        .map-attribution { padding: 8px 12px; border-top: 1px solid var(--line); color: var(--muted); font-size: 10px; line-height: 1.5; }
        .map-attribution a { color: #176d75; text-decoration: underline; }
        .region-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; padding: 0 16px 14px; }
        .region-choice { display: flex; min-width: 0; min-height: 36px; align-items: center; gap: 8px; padding: 6px 8px; border: 1px solid var(--line); border-radius: 6px; background: #fff; color: var(--ink); text-align: left; font-size: 11px; font-weight: 700; }
        .region-choice:hover, .region-choice.active { border-color: var(--choice-color); background: color-mix(in srgb, var(--choice-color) 10%, white); }
        .region-swatch { width: 10px; height: 10px; flex: 0 0 10px; border-radius: 3px; background: var(--choice-color); }
        .region-results { display: grid; gap: 9px; max-height: min(48vh, 440px); overflow: auto; padding: 0 16px 16px; }
        .region-empty { padding: 22px 12px; border-top: 1px solid var(--line); color: var(--muted); font-size: 12px; line-height: 1.5; text-align: center; }
        .destination-card { display: grid; grid-template-columns: 88px minmax(0, 1fr); gap: 11px; min-width: 0; padding: 9px; border: 1px solid var(--line); border-radius: 7px; background: #fff; }
        .destination-image { width: 88px; height: 82px; overflow: hidden; border-radius: 5px; background: #e6f0ef; }
        .destination-image img { width: 100%; height: 100%; object-fit: cover; }
        .destination-copy { min-width: 0; align-self: center; }
        .destination-copy h3 { margin: 0; font-size: 13px; }
        .destination-copy p { margin: 4px 0; color: var(--muted); font-size: 10px; line-height: 1.4; }
        .destination-price { color: #176d75; font-size: 11px; font-weight: 800; }
        .region-plan-footer { padding: 0 16px 16px; }
        .build-region-plan { display: inline-flex; width: 100%; min-height: 42px; align-items: center; justify-content: center; gap: 8px; padding: 0 14px; border: 0; border-radius: 7px; background: var(--teal); color: #fff; font-size: 12px; font-weight: 800; }
        .build-region-plan:hover { background: #116f6b; }
        .build-region-plan[hidden] { display: none; }
        .leaflet-tooltip.region-tooltip { padding: 5px 8px; border: 0; border-radius: 5px; background: rgba(16,42,67,.92); box-shadow: none; color: #fff; font-size: 11px; font-weight: 700; }
        .leaflet-tooltip.region-tooltip:before { display: none; }
        .map-fallback { display: none; padding: 20px; color: #8b3b2c; font-size: 12px; }
        .map-fallback.visible { display: block; }
        @media (max-width: 900px) {
            .explorer { grid-template-columns: minmax(0, 1fr) minmax(300px, .8fr); }
            #mindanaoMap { height: 56vh; min-height: 360px; }
        }
        @media (max-width: 720px) {
            .page { width: min(100% - 24px, 560px); padding-top: 14px; }
            .topbar { align-items: flex-start; flex-direction: column; margin-bottom: 20px; }
            .explorer { grid-template-columns: 1fr; }
            #mindanaoMap { height: 48vh; min-height: 330px; }
            .region-results { max-height: 460px; }
        }
        @media (max-width: 420px) {
            .top-actions { width: 100%; }
            .top-link { flex: 1; }
            h1 { font-size: 24px; }
            .region-list { grid-template-columns: 1fr 1fr; gap: 6px; padding-right: 11px; padding-left: 11px; }
            .map-heading, .region-heading { padding-right: 12px; padding-left: 12px; }
            .destination-card { grid-template-columns: 72px minmax(0,1fr); gap: 8px; }
            .destination-image { width: 72px; height: 72px; }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="topbar">
            <a class="brand" href="dashboard.php">
                <img src="../assets/logo/logo.png" alt="MTR logo">
                <span><strong>Mindanao Travel & Tours</strong><span>Plan by region</span></span>
            </a>
            <div class="top-actions">
                <a class="top-link dashboard-back" href="dashboard.php">← Back to dashboard</a>
                <a class="top-link" href="cart.php">Trip cart</a>
            </div>
        </header>
        <section class="intro">
            <p class="eyebrow">Build a plan</p>
            <h1 id="regionMapTitle">Explore Mindanao by region</h1>
            <p id="regionMapDescription">Choose a region on the map or from the list to see destinations there, then continue to the custom trip builder.</p>
        </section>
        <div class="explorer">
            <section class="map-panel" aria-labelledby="mapTitle">
                <div class="map-heading">
                    <div><h2 id="mapTitle">Mindanao regions</h2><p>Tap a colored region to explore its destinations.</p></div>
                    <button class="reset-region" id="showAllRegions" type="button">Show all</button>
                </div>
                <div id="mindanaoMap" role="application" aria-label="Interactive map of Mindanao regions"></div>
                <p class="map-fallback" id="mapFallback">The interactive map could not load. You can still choose a region from the list.</p>
                <div class="map-attribution">Region boundaries: <a href="https://www.geoboundaries.org/" target="_blank" rel="noopener noreferrer">geoBoundaries</a>, CC BY 3.0 IGO, based on NAMRIA, PSA, and OCHA Philippines data. Map tiles: &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>.</div>
            </section>
            <section class="region-panel" aria-labelledby="regionPanelTitle">
                <header class="region-heading"><div><h2 id="regionPanelTitle">Choose a region</h2><p id="regionPanelNote">Mindanao has six selectable regions.</p></div></header>
                <div class="region-list" id="regionList">
                    <?php foreach ($regionColors as $regionName => $color): ?>
                    <button class="region-choice" type="button" data-region="<?= htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') ?>" style="--choice-color:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>"><span class="region-swatch"></span><span><?= htmlspecialchars($regionName, ENT_QUOTES, 'UTF-8') ?></span></button>
                    <?php endforeach; ?>
                </div>
                <div class="region-results" id="regionResults"><div class="region-empty">Select a region to see its destinations.</div></div>
                <div class="region-plan-footer"><a class="build-region-plan" id="buildRegionPlan" href="#" hidden>Build a custom plan <span aria-hidden="true">→</span></a></div>
            </section>
        </div>
    </main>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        (() => {
            const regionData = <?= json_encode($mindanaoGeoJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const destinations = <?= json_encode($destinations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const regionColors = <?= json_encode($regionColors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const regionResults = document.querySelector('#regionResults');
            const regionTitle = document.querySelector('#regionPanelTitle');
            const regionNote = document.querySelector('#regionPanelNote');
            const buildRegionPlan = document.querySelector('#buildRegionPlan');
            const addingRegion = new URLSearchParams(window.location.search).get('add_region') === '1';
            let existingRegions = [];
            let editCartItemId = null;
            if (addingRegion) {
                try {
                    const savedDraft = JSON.parse(localStorage.getItem('mtrRegionPlanDraft') || 'null');
                    existingRegions = Array.isArray(savedDraft?.regions) ? savedDraft.regions : [];
                    editCartItemId = Number(savedDraft?.editCartItemId) || null;
                } catch (error) {
                    console.error('Unable to read saved trip regions:', error);
                }
                document.querySelector('#regionMapTitle').textContent = 'Add another region to your trip';
                document.querySelector('#regionMapDescription').textContent = 'Your current destinations are saved. Choose the next region to add its destinations and hotel to the same trip.';
            }
            const showAllButton = document.querySelector('#showAllRegions');
            const regionButtons = Array.from(document.querySelectorAll('.region-choice'));
            const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character]);
            const normalizeRegion = value => String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
            const regionColorsByNormalizedName = Object.fromEntries(Object.entries(regionColors).map(([name, color]) => [normalizeRegion(name), color]));
            const assetUrl = path => /^(?:https?:|\/)/i.test(String(path || '')) ? path : `../${String(path || '').replace(/^(?:\.\.\/)+/, '')}`;
            const findDestinations = region => destinations.filter(destination => normalizeRegion(destination.region) === normalizeRegion(region));
            const mapElement = document.querySelector('#mindanaoMap');
            const mapFallback = document.querySelector('#mapFallback');
            let map = null;
            let regionsLayer = null;
            let selectedRegion = '';
            const layerByRegion = new Map();

            const renderDestinations = region => {
                const matches = findDestinations(region);
                regionTitle.textContent = region;
                const alreadyAdded = existingRegions.some(existingRegion => normalizeRegion(existingRegion) === normalizeRegion(region));
                regionNote.textContent = alreadyAdded
                    ? 'This region is already included in your trip. Choose a different region.'
                    : `${matches.length} destination${matches.length === 1 ? '' : 's'} in this region`;
                showAllButton.classList.add('visible');
                const editParam = addingRegion && editCartItemId ? `&edit_cart_item=${editCartItemId}` : '';
                buildRegionPlan.href = `build.php?region=${encodeURIComponent(region)}${addingRegion ? `&continue=1${editParam}` : ''}`;
                buildRegionPlan.innerHTML = addingRegion
                    ? 'Add this region to your trip <span aria-hidden="true">→</span>'
                    : 'Build a custom plan <span aria-hidden="true">→</span>';
                buildRegionPlan.hidden = matches.length === 0 || (addingRegion && alreadyAdded);
                regionButtons.forEach(button => button.classList.toggle('active', button.dataset.region === region));
                regionResults.innerHTML = matches.length ? matches.map(destination => {
                    const imagePath = destination.image_paths?.[0] || '';
                    const image = imagePath ? `<img src="${escapeHtml(assetUrl(imagePath))}" alt="${escapeHtml(destination.name)}">` : '';
                    const description = (destination.description || 'Explore this Mindanao destination.').trim().split(/\s+/).slice(0, 20).join(' ');
                    return `<article class="destination-card"><div class="destination-image">${image}</div><div class="destination-copy"><h3>${escapeHtml(destination.name)}</h3><p>${escapeHtml(destination.location || destination.region)} · ${escapeHtml(destination.category || 'Destination')}<br>${escapeHtml(description)}</p><span class="destination-price">From ₱${Number(destination.price || 0).toLocaleString('en-PH')} / person</span></div></article>`;
                }).join('') : '<div class="region-empty">No destinations are listed for this region yet. Try another region.</div>';
            };
            const selectRegion = region => {
                selectedRegion = region;
                renderDestinations(region);
                if (!map || !regionsLayer) return;
                regionsLayer.setStyle(feature => ({
                    color: feature.properties.region_name === region ? '#102a43' : '#fff',
                    weight: feature.properties.region_name === region ? 3 : 1.5,
                    fillColor: regionColors[feature.properties.region_name],
                    fillOpacity: feature.properties.region_name === region ? 0.88 : 0.62
                }));
                const layer = layerByRegion.get(region);
                if (layer) map.fitBounds(layer.getBounds(), { padding: [24, 24], maxZoom: 8 });
            };
            const resetRegion = () => {
                selectedRegion = '';
                regionTitle.textContent = 'Choose a region';
                regionNote.textContent = 'Mindanao has six selectable regions.';
                regionButtons.forEach(button => button.classList.remove('active'));
                showAllButton.classList.remove('visible');
                buildRegionPlan.hidden = true;
                regionResults.innerHTML = '<div class="region-empty">Select a region to see its destinations.</div>';
                if (map && regionsLayer) {
                    regionsLayer.setStyle(feature => ({ color: '#fff', weight: 1.5, fillColor: regionColors[feature.properties.region_name], fillOpacity: 0.72 }));
                    map.fitBounds(regionsLayer.getBounds(), { padding: [20, 20] });
                }
            };
            regionButtons.forEach(button => button.addEventListener('click', () => selectRegion(button.dataset.region)));
            showAllButton.addEventListener('click', resetRegion);

            if (window.L && regionData.features?.length) {
                map = L.map(mapElement, { scrollWheelZoom: false, zoomControl: true, preferCanvas: true });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 12,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);
                regionsLayer = L.geoJSON(regionData, {
                    style: feature => ({ color: '#fff', weight: 1.5, fillColor: regionColors[feature.properties.region_name], fillOpacity: 0.72 }),
                    onEachFeature: (feature, layer) => {
                        const name = feature.properties.region_name;
                        layerByRegion.set(name, layer);
                        layer.bindTooltip(name, { className: 'region-tooltip', sticky: true });
                        layer.on({
                            mouseover: () => { if (selectedRegion !== name) layer.setStyle({ weight: 3, fillOpacity: 0.9 }); },
                            mouseout: () => {
                                if (selectedRegion === name) {
                                    layer.setStyle({ color: '#102a43', weight: 3, fillColor: regionColors[name], fillOpacity: 0.88 });
                                } else {
                                    regionsLayer.resetStyle(layer);
                                }
                            },
                            click: () => selectRegion(name)
                        });
                    }
                }).addTo(map);
                map.fitBounds(regionsLayer.getBounds(), { padding: [20, 20] });
                window.addEventListener('resize', () => map.invalidateSize());
            } else {
                mapFallback.classList.add('visible');
            }
        })();
    </script>
</body>
</html>
