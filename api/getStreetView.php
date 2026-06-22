<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - getStreetView.php
// Fast imagery + tblActions logging (consistent with askOpenAI)
// =====================================================

header('Content-Type: application/json');

try {
    // Load environment
    if (!function_exists('skyesoftLoadEnv')) {
        require_once __DIR__ . '/utils/envLoader.php';
    }
    skyesoftLoadEnv();

    // ─────────────────────────────────────────
    // SESSION + DB BOOTSTRAP
    // ─────────────────────────────────────────
    require_once __DIR__ . '/sessionBootstrap.php';
    require_once __DIR__ . '/dbConnect.php';
    require_once __DIR__ . '/utils/actions.php';

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

    $encodedAddress = urlencode($address);

    // Street View Metadata
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
        // Optimized for front-facing commercial building view (Phoenix area)
        $imageUrl = "https://maps.googleapis.com/maps/api/streetview?"
            . "size=900x500"
            . "&location=$encodedAddress"
            . "&heading=100"      // Adjusted toward typical building front
            . "&fov=65"           // Tighter, more focused on building
            . "&pitch=8"          // Slight upward tilt to capture facade
            . "&key=" . urlencode($googleKey);
        $imageType = 'streetview';
    } else {
        $imageUrl = "https://maps.googleapis.com/maps/api/staticmap?"
            . "center=$encodedAddress"
            . "&zoom=20&size=900x500&maptype=satellite"
            . "&markers=color:red%7C$encodedAddress"
            . "&key=" . urlencode($googleKey);
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

    // ─────────────────────────────────────────
    // LOG TO tblActions (Consistent with askOpenAI)
    // ─────────────────────────────────────────
    $activitySessionId = $input['activitySessionId'] ?? ($_SESSION['activitySessionId'] ?? session_id());
    $contactId = $_SESSION['contactId'] ?? 0;

    try {
        insertActionPrompt([
            'actionTypeId'     => 12,                    // Location / Imagery action
            'contactId'        => $contactId,
            'promptText'       => "street view " . $address,
            'responseText'     => "Street View generated: " . $imageType,
            'intent'           => 'location.streetview',
            'intentConfidence' => 0.95,
            'latitude'         => null,
            'longitude'        => null,
            'activitySessionId'=> $activitySessionId,
            'origin'           => 1,
            'actionPayloadData'=> $input,
            'actionResponseData'=> [
                'imageType' => $imageType,
                'imagePath' => $imagePath
            ]
        ], getPDO());
    } catch (Throwable $e) {
        error_log("[StreetView Logging] Failed: " . $e->getMessage());
    }

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