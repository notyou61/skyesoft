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

// Use the simpler @ panorama format
$embedUrl = "https://www.google.com/maps/@?api=1&map_action=panorama&viewpoint=" 
    . urlencode($address) 
    . "&heading=" . $heading 
    . "&pitch=" . $pitch;

echo $embedUrl;
?>