<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Street View + Satellite Fallback
// =====================================================

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Load Skyesoft environment
require_once __DIR__ . '/../utils/envLoader.php';
skyesoftLoadEnv();

// =====================================================
// TEST ADDRESS
// =====================================================

$lat = 33.4721;
$lng = -111.9903;
$address = '2252 N 44th St Phoenix, AZ 85008';

error_log("[STREETVIEW TEST] Starting test for address: " . $address);

// =====================================================
// GOOGLE API KEY
// =====================================================

$googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: '';

if (empty($googleKey)) {
    die('Google API Key Missing - Check your .env file');
}

// =====================================================
// TRY STREET VIEW
// =====================================================

$streetViewUrl = 'https://maps.googleapis.com/maps/api/streetview?size=900x500'
    . '&location=' . $lat . ',' . $lng
    . '&heading=0'
    . '&fov=90'
    . '&pitch=0'
    . '&key=' . urlencode($googleKey);

$imageData = @file_get_contents($streetViewUrl);

$isValidStreetView = $imageData && strlen($imageData) > 5000; // Real image check

// =====================================================
// SAVE OR FALLBACK
// =====================================================

$ephemeralDir = __DIR__ . '/../../data/runtimeEphemeral/streetview/';
if (!is_dir($ephemeralDir)) {
    mkdir($ephemeralDir, 0755, true);
}

$filename = 'streetview-' . uniqid() . '.jpg';
$fullPath = $ephemeralDir . $filename;

if ($isValidStreetView) {
    file_put_contents($fullPath, $imageData);
    echo "<p><strong>Street View saved:</strong> " . $filename . "</p>";
    $imageSrc = "/skyesoft/data/runtimeEphemeral/streetview/" . $filename;
} else {
    // Satellite Fallback
    $staticMapUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'center=' . $lat . ',' . $lng
        . '&zoom=18&size=900x500&maptype=satellite'
        . '&markers=color:red%7C' . $lat . ',' . $lng
        . '&key=' . urlencode($googleKey);

    $imageData = @file_get_contents($staticMapUrl);
    file_put_contents($fullPath, $imageData);
    echo "<p><strong>Satellite fallback saved:</strong> " . $filename . "</p>";
    $imageSrc = "/skyesoft/data/runtimeEphemeral/streetview/" . $filename;
}

$interactiveUrl = "https://www.google.com/maps/@{$lat},{$lng},3a,75y,200h,90t/data=!3m6!1e1!3m4!1s!2e0!7i16384!8i8192";

?>
<!DOCTYPE html>
<html>
<head>
    <title>Street View Test</title>
    <style>
        body { font-family:Arial, sans-serif; padding:20px; }
        img { max-width:100%; border:1px solid #bbb; border-radius:6px; }
    </style>
</head>
<body>

<h2>Skyesoft Street View Test</h2>

<p><strong>Address:</strong> <?= htmlspecialchars($address) ?></p>

<img src="<?= htmlspecialchars($imageSrc) ?>" alt="Location View">

<p style="text-align:center; margin-top:10px;">
    <a href="<?= htmlspecialchars($interactiveUrl) ?>" target="_blank" style="color:#14377C; text-decoration:underline;">
        Open Interactive Street View in Google Maps
    </a>
</p>

</body>
</html>