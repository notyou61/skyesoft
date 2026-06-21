<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Using Payload Data
// =====================================================

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Load Skyesoft environment
require_once __DIR__ . '/../utils/envLoader.php';
skyesoftLoadEnv();

// =====================================================
// TEST ADDRESS FROM PAYLOAD
// =====================================================

$lat = 33.4848225;
$lng = -112.1288313;
$address = '3145 N 33rd Ave Phoenix, AZ 85017';

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
// STANDALONE generateStreetViewImage
// =====================================================

function generateStreetViewImage(
    string $lat,
    string $lng,
    string $googleKey,
    string $address = ''
): ?string
{
    if (empty($googleKey) || empty($lat) || empty($lng)) {
        error_log("[STREETVIEW TEST] Missing lat, lng or API key");
        return null;
    }

    $heading = 270; // West for this address

    $url = 'https://maps.googleapis.com/maps/api/streetview?size=640x320'
        . '&location=' . $lat . ',' . $lng
        . '&heading=' . $heading
        . '&pitch=5'
        . '&fov=85'
        . '&key=' . $googleKey;

    error_log("[STREETVIEW TEST] Calling URL: " . $url);

    $imageData = @file_get_contents($url);

    if ($imageData === false || strlen($imageData) < 1000) {
        error_log("[STREETVIEW TEST] Google returned invalid/small image");
        return null;
    }

    // Save the image
    $ephemeralDir = __DIR__ . '/../../data/runtimeEphemeral/streetview/';
    if (!is_dir($ephemeralDir)) {
        mkdir($ephemeralDir, 0755, true);
    }

    $tempPath = $ephemeralDir . 'streetview-' . uniqid() . '.jpg';

    if (file_put_contents($tempPath, $imageData) === false) {
        error_log("[STREETVIEW TEST] Failed to write image to disk");
        return null;
    }

    error_log("[STREETVIEW TEST] ✅ Saved: " . $tempPath);
    return $tempPath;
}

// =====================================================
// CALL IT
// =====================================================

$streetViewPath = generateStreetViewImage(
    (string)$lat,
    (string)$lng,
    $googleKey,
    $address
);

if ($streetViewPath) {
    echo "<p><strong>Image saved:</strong> " . basename($streetViewPath) . "</p>";
} else {
    echo "<p>generateStreetViewImage failed</p>";
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

<?php if ($streetViewPath && file_exists($streetViewPath)): ?>
    <img src="<?= htmlspecialchars($streetViewPath) ?>" alt="Street View">
<?php else: ?>
    <p>No image generated</p>
<?php endif; ?>

<p style="text-align:center; margin-top:10px;">
    <a href="<?= htmlspecialchars($interactiveUrl) ?>" target="_blank" style="color:#14377C; text-decoration:underline;">
        Open Interactive Street View in Google Maps
    </a>
</p>

</body>
</html>