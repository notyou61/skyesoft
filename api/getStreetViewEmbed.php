<?php
// getStreetViewEmbed.php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$address = $_GET['address'] ?? '';
$heading = (int)($_GET['heading'] ?? 105);
$pitch   = (int)($_GET['pitch'] ?? 8);

if (empty($address)) {
    http_response_code(400);
    echo "Address required";
    exit;
}

$googleKey = skyesoftGetEnv('GOOGLE_MAPS_EMBED_API_KEY')
    ?: getenv('GOOGLE_MAPS_EMBED_API_KEY')
    ?: '';

if (empty($googleKey)) {
    http_response_code(500);
    echo "Embed API key not configured";
    exit;
}

// For now, use a simple geocode or hardcoded fallback. 
// Better: integrate with your existing geocoder in getStreetView.php
$lat = 33.4720564;  // default for testing
$lng = -111.9902556;

$embedUrl = "https://www.google.com/maps/embed/v1/streetview?"
    . "key=" . urlencode($googleKey)
    . "&location=" . $lat . "," . $lng
    . "&heading=" . $heading
    . "&pitch=" . $pitch
    . "&fov=80";

echo $embedUrl;
?>