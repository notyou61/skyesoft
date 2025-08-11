<?php
// 📁 File: api/places_reverse.php
// Purpose: Reverse-geocode lat/lng → Google Place ID (secure backend lookup)

header('Content-Type: application/json');
require_once __DIR__ . '/env_boot.php';

// Use secure backend key
$apiKey = getenv('GOOGLE_MAPS_BACKEND_API_KEY');

$lat = isset($_GET['lat']) ? trim($_GET['lat']) : '';
$lng = isset($_GET['lng']) ? trim($_GET['lng']) : '';

if (!$apiKey || $lat === '' || $lng === '') {
    echo json_encode(['place_id' => null, 'error' => 'Missing key or coordinates']);
    exit;
}

$url = sprintf(
    'https://maps.googleapis.com/maps/api/geocode/json?latlng=%s,%s&key=%s',
    urlencode($lat),
    urlencode($lng),
    urlencode($apiKey)
);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['place_id' => null, 'error' => $err]);
    exit;
}

$data = json_decode($body, true);
$placeId = isset($data['results'][0]['place_id']) ? $data['results'][0]['place_id'] : null;

echo json_encode([
    'place_id' => $placeId,
    'status'   => isset($data['status']) ? $data['status'] : null
]);
