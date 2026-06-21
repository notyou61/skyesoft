<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Street View Only + Save File + Logging
// =====================================================

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Load Skyesoft environment
require_once __DIR__ . '/../utils/envLoader.php';
skyesoftLoadEnv();

// =====================================================
// TEST ADDRESS
// =====================================================

$lat = 33.4720564;
$lng = -111.9902556;
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

error_log("[STREETVIEW TEST] Google Key present: YES");

// =====================================================
// BUILD STREET VIEW
// =====================================================

$streetViewUrl = 'https://maps.googleapis.com/maps/api/streetview'
    . '?size=900x500'
    . '&location=' . $lat . ',' . $lng
    . '&heading=270'
    . '&fov=90'
    . '&pitch=0'
    . '&key=' . urlencode($googleKey);

error_log("[STREETVIEW TEST] Generated URL: " . $streetViewUrl);

// =====================================================
// SAVE STREET VIEW IMAGE
// =====================================================

$ephemeralDir = __DIR__ . '/../../data/runtimeEphemeral/streetview/';

if (!is_dir($ephemeralDir)) {
    mkdir($ephemeralDir, 0755, true);
    error_log("[STREETVIEW TEST] Created directory: " . $ephemeralDir);
}

$filename = 'streetview-' . uniqid() . '.jpg';
$fullPath = $ephemeralDir . $filename;

error_log("[STREETVIEW TEST] Attempting to save to: " . $fullPath);

$imageData = @file_get_contents($streetViewUrl);

if ($imageData) {
    $size = strlen($imageData);
    error_log("[STREETVIEW TEST] Received " . $size . " bytes from Google");

    if ($size > 5000) {  // Real image check
        if (file_put_contents($fullPath, $imageData)) {
            echo "<p><strong>Image saved:</strong> " . $filename . " (" . round($size / 1024, 1) . " KB)</p>";
            error_log("[STREETVIEW TEST] ✅ Successfully saved: " . $fullPath);
        } else {
            echo "<p>Failed to save image to disk</p>";
            error_log("[STREETVIEW TEST] ❌ Failed to write file");
        }
    } else {
        echo "<p>Received placeholder image from Google (" . $size . " bytes)</p>";
        error_log("[STREETVIEW TEST] ⚠️ Placeholder image received (too small)");
    }
} else {
    echo "<p>Failed to download image from Google</p>";
    error_log("[STREETVIEW TEST] ❌ file_get_contents failed");
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
        pre { background:#f5f5f5; padding:10px; overflow:auto; }
    </style>
</head>
<body>

<h2>Skyesoft Street View Test</h2>

<p><strong>Address:</strong> <?= htmlspecialchars($address) ?></p>

<img src="<?= htmlspecialchars($streetViewUrl) ?>" alt="Street View">

<p style="text-align:center; margin-top:10px;">
    <a href="<?= htmlspecialchars($interactiveUrl) ?>" target="_blank" style="color:#14377C; text-decoration:underline;">
        Open Interactive Street View in Google Maps
    </a>
</p>

<hr>

<h3>Generated Street View URL</h3>
<pre><?= htmlspecialchars($streetViewUrl) ?></pre>

</body>
</html>