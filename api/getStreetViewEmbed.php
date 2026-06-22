<?php
// getStreetViewEmbed.php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$address = $_GET['address'] ?? '';
$heading = $_GET['heading'] ?? 105;
$pitch   = $_GET['pitch'] ?? 8;

if (empty($address)) {
    http_response_code(400);
    echo "Address required";
    exit;
}

$googleKey = skyesoftGetEnv('GOOGLE_MAPS_API_KEY'); // Use a key with Maps Embed enabled

$embedUrl = "https://www.google.com/maps/embed/v1/streetview?"
    . "key=" . urlencode($googleKey)
    . "&location=" . urlencode($address)
    . "&heading=" . $heading
    . "&pitch=" . $pitch;

echo $embedUrl; // or return full iframe HTML
?>