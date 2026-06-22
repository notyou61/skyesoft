<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - getStreetView.php API
// Robust Street View / Satellite fallback
// =====================================================

header('Content-Type: application/json');

try {
    // ─────────────────────────────────────────
    // 🌍 Load environment
    // ─────────────────────────────────────────
    if (!function_exists('skyesoftLoadEnv')) {
        require_once __DIR__ . '/utils/envLoader.php';
    }
    skyesoftLoadEnv();

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $address = trim($input['address'] ?? '');

    if (empty($address)) {
        throw new Exception('Address is required');
    }

    $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') 
        ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') 
        ?: '';

    if (empty($googleKey)) {
        throw new Exception('Google Maps API key not configured. Check .env');
    }

    // Geocode
    $geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?address=' 
        . urlencode($address) . '&key=' . urlencode($googleKey);
    
    $geoJson = @file_get_contents($geocodeUrl);
    $geo = $geoJson ? json_decode($geoJson, true) : null;

    if (empty($geo['results'][0]['geometry']['location'])) {
        throw new Exception('Could not geocode the address');
    }

    $loc = $geo['results'][0]['geometry']['location'];
    $lat = $loc['lat'];
    $lng = $loc['lng'];
    $formattedAddress = $geo['results'][0]['formatted_address'] ?? $address;

    // Street View Metadata
    $metadataUrl = "https://maps.googleapis.com/maps/api/streetview/metadata?location=$lat,$lng&key=" . urlencode($googleKey);
    $metaJson = @file_get_contents($metadataUrl);
    $metadata = $metaJson ? json_decode($metaJson, true) : [];
    $hasStreetView = ($metadata['status'] ?? '') === 'OK';

    // Ensure ephemeral dir
    $ephemeralDir = __DIR__ . '/../data/runtimeEphemeral/streetview/';
    if (!is_dir($ephemeralDir)) {
        mkdir($ephemeralDir, 0755, true);
    }

    $filename = 'location-' . uniqid() . '.jpg';
    $fullPath = $ephemeralDir . $filename;

    if ($hasStreetView) {
        $imageUrl = "https://maps.googleapis.com/maps/api/streetview?size=900x500&location=$lat,$lng&heading=0&fov=90&pitch=5&key=" . urlencode($googleKey);
        $imageType = 'streetview';
    } else {
        $imageUrl = "https://maps.googleapis.com/maps/api/staticmap?center=$lat,$lng&zoom=19&size=900x500&maptype=satellite&markers=color:red%7C$lat,$lng&key=" . urlencode($googleKey);
        $imageType = 'satellite';
    }

    $imageData = @file_get_contents($imageUrl);

    if ($imageData) {
        file_put_contents($fullPath, $imageData);
        $imagePath = "/skyesoft/data/runtimeEphemeral/streetview/" . $filename;
    } else {
        throw new Exception('Failed to fetch map image');
    }

    $interactiveUrl = "https://www.google.com/maps/@$lat,$lng,3a,75y,200h,90t/data=!3m6!1e1!3m4!1s!2e0!7i16384!8i8192";

    echo json_encode([
        'success' => true,
        'imageType' => $imageType,
        'address' => $formattedAddress,
        'latitude' => round($lat, 6),
        'longitude' => round($lng, 6),
        'imagePath' => $imagePath,
        'interactiveUrl' => $interactiveUrl
    ]);

} catch (Exception $e) {
    error_log('[Skyesoft StreetView API Error] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}