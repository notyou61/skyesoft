<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View API Endpoint
// Address → Geocode → Street View Metadata → Image (SV or Satellite)
// =====================================================

ini_set('display_errors', 0); // Production: hide warnings
error_reporting(E_ALL);

// Load Skyesoft environment - FIXED PATH
require_once __DIR__ . '/../../utils/envLoader.php';  // ← Adjusted for api/ dir
skyesoftLoadEnv();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$rawAddress = trim($input['address'] ?? '');

if (empty($rawAddress)) {
    echo json_encode(['success' => false, 'message' => 'Address is required']);
    exit;
}

error_log("[STREETVIEW API] Processing: " . $rawAddress);

$googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: '';

if (empty($googleKey)) {
    echo json_encode(['success' => false, 'message' => 'Google Maps API key not configured']);
    exit;
}

// 1. Geocode the address
$geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?address=' 
    . urlencode($rawAddress) . '&key=' . urlencode($googleKey);

$geoJson = @file_get_contents($geocodeUrl);
$geoData = $geoJson ? json_decode($geoJson, true) : [];

if (empty($geoData['results'][0]['geometry']['location'])) {
    echo json_encode(['success' => false, 'message' => 'Could not geocode address']);
    exit;
}

$loc = $geoData['results'][0]['geometry']['location'];
$lat = $loc['lat'];
$lng = $loc['lng'];
$formattedAddress = $geoData['results'][0]['formatted_address'] ?? $rawAddress;

// 2. Street View Metadata Check
$metadataUrl = 'https://maps.googleapis.com/maps/api/streetview/metadata?'
    . 'location=' . $lat . ',' . $lng
    . '&key=' . urlencode($googleKey);

$metadataJson = @file_get_contents($metadataUrl);
$metadata = $metadataJson ? json_decode($metadataJson, true) : [];

$hasStreetView = ($metadata['status'] ?? '') === 'OK';

// 3. Prepare ephemeral storage
$ephemeralDir = __DIR__ . '/../../data/runtimeEphemeral/streetview/';
if (!is_dir($ephemeralDir)) {
    mkdir($ephemeralDir, 0755, true);
}

$filename = 'location-' . uniqid() . '.jpg';
$fullPath = $ephemeralDir . $filename;

// 4. Fetch Image
if ($hasStreetView) {
    $imageUrl = 'https://maps.googleapis.com/maps/api/streetview?size=900x500'
        . '&location=' . $lat . ',' . $lng
        . '&heading=0&fov=90&pitch=5'
        . '&key=' . urlencode($googleKey);
    $imageType = 'streetview';
} else {
    $imageUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'center=' . $lat . ',' . $lng
        . '&zoom=19&size=900x500&maptype=satellite'
        . '&markers=color:red%7C' . $lat . ',' . $lng
        . '&key=' . urlencode($googleKey);
    $imageType = 'satellite';
}

$imageData = @file_get_contents($imageUrl);
$imagePath = '';

if ($imageData) {
    file_put_contents($fullPath, $imageData);
    $imagePath = "/skyesoft/data/runtimeEphemeral/streetview/" . $filename;
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch imagery']);
    exit;
}

$interactiveUrl = "https://www.google.com/maps/@{$lat},{$lng},3a,75y,200h,90t/data=!3m6!1e1!3m4!1s!2e0!7i16384!8i8192";

echo json_encode([
    'success'       => true,
    'imageType'     => $imageType,
    'address'       => $formattedAddress,
    'latitude'      => $lat,
    'longitude'     => $lng,
    'imagePath'     => $imagePath,
    'interactiveUrl'=> $interactiveUrl
]);