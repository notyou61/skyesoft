<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - getStreetViewEmbed.php
// Address → Geocode → Google Street View Embed URL
// =====================================================

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$address = trim((string)($_GET['address'] ?? ''));
$heading = is_numeric($_GET['heading'] ?? null) ? (float)$_GET['heading'] : 105;
$pitch   = is_numeric($_GET['pitch'] ?? null) ? (float)$_GET['pitch'] : 8;

if ($address === '') {
    http_response_code(400);
    echo 'Address required';
    exit;
}

$googleKey =
    skyesoftGetEnv('GOOGLE_MAPS_API_KEY')
    ?: skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: getenv('GOOGLE_MAPS_API_KEY')
    ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: '';

if ($googleKey === '') {
    http_response_code(500);
    echo 'Google Maps API key missing';
    exit;
}

// Normalize address spacing
$address = preg_replace('/\s+/', ' ', $address);

// Geocode address
$geocodeUrl =
    'https://maps.googleapis.com/maps/api/geocode/json'
    . '?address=' . urlencode($address)
    . '&key=' . urlencode($googleKey);

$geocodeJson = @file_get_contents($geocodeUrl);
$geocode = $geocodeJson ? json_decode($geocodeJson, true) : [];

$lat = $geocode['results'][0]['geometry']['location']['lat'] ?? null;
$lng = $geocode['results'][0]['geometry']['location']['lng'] ?? null;

if (!$lat || !$lng) {
    http_response_code(422);
    echo 'Unable to geocode address';
    exit;
}

// Build Street View embed URL using coordinates
$embedUrl =
    'https://www.google.com/maps/embed/v1/streetview'
    . '?key=' . urlencode($googleKey)
    . '&location=' . urlencode($lat . ',' . $lng)
    . '&heading=' . urlencode((string)$heading)
    . '&pitch=' . urlencode((string)$pitch)
    . '&fov=80';

echo $embedUrl;