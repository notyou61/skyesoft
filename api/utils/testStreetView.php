<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Metadata Check → Street View or Satellite Fallback
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
// 1. METADATA CHECK
// =====================================================

$metadataUrl = 'https://maps.googleapis.com/maps/api/streetview/metadata?'
    . 'location=' . $lat . ',' . $lng
    . '&key=' . urlencode($googleKey);

$metadataJson = @file_get_contents($metadataUrl);
$metadata = $metadataJson ? json_decode($metadataJson, true) : [];

$hasStreetView = ($metadata['status'] ?? '') === 'OK';

error_log("[STREETVIEW TEST] Metadata status: " . ($metadata['status'] ?? 'UNKNOWN') . 
          " | Has Street View: " . ($hasStreetView ? 'YES' : 'NO'));

// =====================================================
// 2. GENERATE IMAGE
// =====================================================

$ephemeralDir = __DIR__ . '/../../data/runtimeEphemeral/streetview/';
if (!is_dir($ephemeralDir)) {
    mkdir($ephemeralDir, 0755, true);
}

$filename = 'location-' . uniqid() . '.jpg';
$fullPath = $ephemeralDir . $filename;

if ($hasStreetView) {
    // Street View
    $imageUrl = 'https://maps.googleapis.com/maps/api/streetview?size=900x500'
        . '&location=' . $lat . ',' . $lng
        . '&heading=0'
        . '&fov=90'
        . '&pitch=5'
        . '&key=' . urlencode($googleKey);

    $imageType = 'streetview';
    echo "<p><strong>Street View available and saved:</strong> " . $filename . "</p>";
} else {
    // Satellite Fallback
    $imageUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'center=' . $lat . ',' . $lng
        . '&zoom=19&size=900x500&maptype=satellite'
        . '&markers=color:red%7C' . $lat . ',' . $lng
        . '&key=' . urlencode($googleKey);

    $imageType = 'satellite';
    echo "<p><strong>Satellite fallback saved:</strong> " . $filename . "</p>";
}

$imageData = @file_get_contents($imageUrl);

if ($imageData) {
    file_put_contents($fullPath, $imageData);
    $imageSrc = "/skyesoft/data/runtimeEphemeral/streetview/" . $filename;
} else {
    $imageSrc = '';
    echo "<p>Failed to fetch image</p>";
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
        .type { font-weight:bold; color:<?= $hasStreetView ? '#006400' : '#8B4513' ?>; }
    </style>
</head>
<body>

<h2>Skyesoft Location Image Test</h2>

<p><strong>Address:</strong> <?= htmlspecialchars($address) ?></p>
<p class="type">Image Type: <?= strtoupper($imageType) ?></p>

<?php if ($imageSrc): ?>
    <img src="<?= htmlspecialchars($imageSrc) ?>" alt="<?= $imageType ?>">
<?php endif; ?>

<p style="text-align:center; margin-top:10px;">
    <a href="<?= htmlspecialchars($interactiveUrl) ?>" target="_blank" style="color:#14377C; text-decoration:underline;">
        Open Interactive View in Google Maps
    </a>
</p>

</body>
</html>