<?php
declare(strict_types=1);

// Skyesoft Street View API - Robust Version
header('Content-Type: application/json');

try {
    // Load environment - Try multiple possible paths
    $possiblePaths = [
        __DIR__ . '/../../utils/envLoader.php',
        __DIR__ . '/../utils/envLoader.php',
        $_SERVER['DOCUMENT_ROOT'] . '/skyesoft/utils/envLoader.php'
    ];

    $loaded = false;
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            skyesoftLoadEnv();
            $loaded = true;
            break;
        }
    }

    if (!$loaded) {
        throw new Exception('Could not load envLoader.php');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $address = trim($input['address'] ?? '');

    if (empty($address)) {
        throw new Exception('Address is required');
    }

    $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') ?: '';
    if (empty($googleKey)) {
        throw new Exception('Google API Key not configured');
    }

    // Geocode
    $geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . urlencode($googleKey);
    $geoJson = @file_get_contents($geocodeUrl);
    $geo = $geoJson ? json_decode($geoJson, true) : null;

    if (empty($geo['results'][0]['geometry']['location'])) {
        throw new Exception('Geocoding failed for address');
    }

    $loc = $geo['results'][0]['geometry']['location'];
    $lat = $loc['lat'];
    $lng = $loc['lng'];
    $formatted = $geo['results'][0]['formatted_address'] ?? $address;

    // Street View Metadata
    $metaUrl = "https://maps.googleapis.com/maps/api/streetview/metadata?location=$lat,$lng&key=" . urlencode($googleKey);
    $meta = @json_decode(file_get_contents($metaUrl), true);
    $hasSV = ($meta['status'] ?? '') === 'OK';

    // Save image
    $dir = __DIR__ . '/../../data/runtimeEphemeral/streetview/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'sv-' . uniqid() . '.jpg';
    $fullPath = $dir . $filename;

    if ($hasSV) {
        $imgUrl = "https://maps.googleapis.com/maps/api/streetview?size=900x500&location=$lat,$lng&heading=0&fov=90&pitch=0&key=" . urlencode($googleKey);
        $type = 'streetview';
    } else {
        $imgUrl = "https://maps.googleapis.com/maps/api/staticmap?center=$lat,$lng&zoom=19&size=900x500&maptype=satellite&markers=color:red|$lat,$lng&key=" . urlencode($googleKey);
        $type = 'satellite';
    }

    $imgData = @file_get_contents($imgUrl);
    $imagePath = '';

    if ($imgData) {
        file_put_contents($fullPath, $imgData);
        $imagePath = "/skyesoft/data/runtimeEphemeral/streetview/" . $filename;
    } else {
        throw new Exception('Failed to download image');
    }

    $interactive = "https://www.google.com/maps/@$lat,$lng,3a,75y,200h,90t";

    echo json_encode([
        'success' => true,
        'imageType' => $type,
        'address' => $formatted,
        'latitude' => $lat,
        'longitude' => $lng,
        'imagePath' => $imagePath,
        'interactiveUrl' => $interactive
    ]);

} catch (Exception $e) {
    error_log("[STREETVIEW API ERROR] " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}