<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - getStreetView.php (Simplified - No Geocode)
// =====================================================

header('Content-Type: application/json');

try {
    // Load environment
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
        throw new Exception('Google Maps API key not configured');
    }

    // Use address directly for Street View / Static Map
    $encodedAddress = urlencode($address);

    // Street View Metadata (using address)
    $metadataUrl = "https://maps.googleapis.com/maps/api/streetview/metadata?location=$encodedAddress&key=" . urlencode($googleKey);
    $metaJson = @file_get_contents($metadataUrl);
    $metadata = $metaJson ? json_decode($metaJson, true) : [];
    $hasStreetView = ($metadata['status'] ?? '') === 'OK';

    // Image
    $ephemeralDir = __DIR__ . '/../data/runtimeEphemeral/streetview/';
    if (!is_dir($ephemeralDir)) {
        mkdir($ephemeralDir, 0755, true);
    }

    $filename = 'location-' . uniqid() . '.jpg';
    $fullPath = $ephemeralDir . $filename;

    if ($hasStreetView) {
        $imageUrl = "https://maps.googleapis.com/maps/api/streetview?size=900x500&location=$encodedAddress&heading=0&fov=90&pitch=5&key=" . urlencode($googleKey);
        $imageType = 'streetview';
    } else {
        $imageUrl = "https://maps.googleapis.com/maps/api/staticmap?center=$encodedAddress&zoom=19&size=900x500&maptype=satellite&markers=color:red|$encodedAddress&key=" . urlencode($googleKey);
        $imageType = 'satellite';
    }

    $imageData = @file_get_contents($imageUrl);

    if ($imageData) {
        file_put_contents($fullPath, $imageData);
        $imagePath = "/skyesoft/data/runtimeEphemeral/streetview/" . $filename;
    } else {
        throw new Exception('Failed to fetch imagery from Google');
    }

    $interactiveUrl = "https://www.google.com/maps/search/?api=1&query=$encodedAddress";

    echo json_encode([
        'success' => true,
        'imageType' => $imageType,
        'address' => $address,
        'imagePath' => $imagePath,
        'interactiveUrl' => $interactiveUrl
    ]);

} catch (Exception $e) {
    error_log('[StreetView API Error] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}