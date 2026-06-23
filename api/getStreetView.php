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

    // Backend operations key (Geocoding / Static Images)
    $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') 
        ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') 
        ?: '';

    // ─────────────────────────────────────────────────────────────
    // READ EXPLICIT EMBED API KEY (Matches image_aab500.png)
    // ─────────────────────────────────────────────────────────────
    $embedKey = skyesoftGetEnv('GOOGLE_MAPS_EMBED_API_KEY')
        ?: getenv('GOOGLE_MAPS_EMBED_API_KEY')
        ?: $googleKey; // Fallback if not configured yet

    if (empty($googleKey)) {
        throw new Exception('Google Maps Static API key not configured');
    }

    error_log("=== STREETVIEW REQUEST STARTED ===");
    error_log("Address: " . $address);
    error_log("Google Key length: " . strlen($googleKey));

    $encodedAddress = urlencode($address);

    // =====================================================
    // GEOCODE ADDRESS
    // =====================================================
    $lat = null;
    $lng = null;

    $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?"
        . "address={$encodedAddress}"
        . "&key=" . urlencode($googleKey);

    $geocodeJson = @file_get_contents($geocodeUrl);
    $geocode = $geocodeJson ? json_decode($geocodeJson, true) : [];

    if (
        !empty($geocode['results'][0]['geometry']['location']['lat']) &&
        !empty($geocode['results'][0]['geometry']['location']['lng'])
    ) {
        $lat = (float)$geocode['results'][0]['geometry']['location']['lat'];
        $lng = (float)$geocode['results'][0]['geometry']['location']['lng'];
        error_log("[StreetView] Successfully geocoded {$address} → {$lat}, {$lng}");
    } else {
        // Fallback coordinates for Phoenix area (safe default)
        $lat = 33.4720564;
        $lng = -111.9902556;
        error_log("[StreetView] Geocoding failed for {$address} - using Phoenix fallback coordinates");
    }

    error_log("[StreetView] Geocoded {$address} → {$lat}, {$lng}");

    // Street View Metadata
    $metadataUrl = "https://maps.googleapis.com/maps/api/streetview/metadata?location=$encodedAddress&key=" . urlencode($googleKey);
    $metaJson = @file_get_contents($metadataUrl);
    $metadata = $metaJson ? json_decode($metaJson, true) : [];
    $hasStreetView = ($metadata['status'] ?? '') === 'OK';

    // Image Snapshot Storage
    $ephemeralDir = __DIR__ . '/../data/runtimeEphemeral/streetview/';
    if (!is_dir($ephemeralDir)) {
        mkdir($ephemeralDir, 0755, true);
    }

    $filename = 'location-' . uniqid() . '.jpg';
    $fullPath = $ephemeralDir . $filename;

    if ($hasStreetView) {
        $imageUrl = "https://maps.googleapis.com/maps/api/streetview?"
            . "size=900x280"      
            . "&location=$encodedAddress"
            . "&heading=105"
            . "&fov=65"
            . "&pitch=8"
            . "&key=" . urlencode($googleKey);
        $imageType = 'streetview';
    } else {
        $imageUrl = "https://maps.googleapis.com/maps/api/staticmap?"
            . "center=$encodedAddress"
            . "&zoom=20&size=900x280&maptype=satellite"
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

    // ─────────────────────────────────────────────────────────────
    // INTERACTIVE MODAL ENDPOINT (POWERED BY DEDICATED EMBED KEY)
    // ─────────────────────────────────────────────────────────────

    if ($lat !== null && $lng !== null) {

        $interactiveUrl =
            "https://www.google.com/maps/embed/v1/streetview"
            . "?key=" . urlencode($embedKey)
            . "&location={$lat},{$lng}"
            . "&heading=105"
            . "&pitch=8"
            . "&fov=65";

    } else {

        $interactiveUrl =
            "https://www.google.com/maps/embed/v1/streetview"
            . "?key=" . urlencode($embedKey)
            . "&location=" . urlencode($address)
            . "&heading=105"
            . "&pitch=8"
            . "&fov=65";
    }

    // ─────────────────────────────────────────
    // LOG TO tblActions
    // ─────────────────────────────────────────
    $activitySessionId = $input['activitySessionId'] ?? ($_SESSION['activitySessionId'] ?? session_id());
    $contactId = $_SESSION['contactId'] ?? 0;

    try {
        insertActionPrompt([
            'actionTypeId'     => 12,                    
            'contactId'        => $contactId,
            'promptText'       => "street view " . $address,
            'responseText'     => "Street View generated: " . $imageType,
            'intent'           => 'location.streetview',
            'intentConfidence' => 0.95,
            'latitude'         => $lat,
            'longitude'        => $lng,
            'activitySessionId'=> $activitySessionId,
            'origin'           => 1,
            'actionPayloadData'=> $input,
            'actionResponseData' => [
                'imageType'      => $imageType,
                'imagePath'      => $imagePath,
                'address'        => $address,
                'latitude'       => $lat,
                'longitude'      => $lng,
                'interactiveUrl' => $interactiveUrl
            ]
        ], getPDO());
    } catch (Throwable $e) {
        error_log("[StreetView Logging] Failed: " . $e->getMessage());
    }

    echo json_encode([
        'success'        => true,
        'imageType'      => $imageType,
        'address'        => $address,
        'latitude'       => $lat,           
        'longitude'      => $lng,          
        'imagePath'      => $imagePath,
        'interactiveUrl' => $interactiveUrl
    ]);

} catch (Exception $e) {
    error_log('[StreetView API Error] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}