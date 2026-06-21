<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Satellite Fallback + Interactive Link + Save
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
// BUILD VISUAL
// =====================================================

$staticMapUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
    . 'center=' . $lat . ',' . $lng
    . '&zoom=18&size=900x500&maptype=satellite'
    . '&markers=color:red%7C' . $lat . ',' . $lng
    . '&key=' . urlencode($googleKey);

$interactiveUrl = "https://www.google.com/maps/@{$lat},{$lng},3a,75y,200h,90t/data=!3m6!1e1!3m4!1s!2e0!7i16384!8i8192";

// Save the image to disk
$ephemeralDir = __DIR__ . '/../data/runtimeEphemeral/streetview/';
if (!is_dir($ephemeralDir)) {
    mkdir($ephemeralDir, 0755, true);
}

$filename = 'streetview-' . uniqid() . '.jpg';
$fullPath = $ephemeralDir . $filename;

$imageData = @file_get_contents($staticMapUrl);

if ($imageData && file_put_contents($fullPath, $imageData)) {
    echo "<p><strong>Image saved:</strong> " . $filename . "</p>";
} else {
    echo "<p>Failed to save image</p>";
}

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

<h3>Satellite View (Fallback)</h3>
<img src="<?= htmlspecialchars($staticMapUrl) ?>" alt="Satellite View">

<p style="text-align:center; margin-top:10px;">
    <a href="<?= htmlspecialchars($interactiveUrl) ?>" target="_blank" style="color:#14377C; text-decoration:underline;">
        Open Interactive Street View in Google Maps
    </a>
</p>

<hr>

<h3>Generated Satellite URL</h3>
<pre><?= htmlspecialchars($staticMapUrl) ?></pre>

</body>
</html>