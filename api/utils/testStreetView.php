<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Independent Test Utility
// =====================================================

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Load Skyesoft environment
require_once __DIR__ . '/../utils/envLoader.php';
skyesoftLoadEnv();

// =====================================================
// TEST ADDRESS
// =====================================================

$address = '2252 N 44th St Phoenix, AZ 85008';
$lat = 33.4720564;
$lng = -111.9902556;

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
// TEST MULTIPLE HEADINGS
// =====================================================

$headings = [0, 90, 180, 270, 45, 135, 225, 315];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Street View Test</title>
    <style>
        body { font-family:Arial, sans-serif; padding:20px; }
        img { max-width:100%; border:1px solid #bbb; border-radius:6px; margin:10px 0; }
        pre { background:#f5f5f5; padding:10px; overflow:auto; }
    </style>
</head>
<body>

<h2>Skyesoft Street View Test</h2>

<p><strong>Address:</strong> <?= htmlspecialchars($address) ?></p>

<?php foreach ($headings as $heading): ?>

    <h3>Heading: <?= $heading ?></h3>
    <?php
    $streetViewUrl = 'https://maps.googleapis.com/maps/api/streetview'
        . '?size=900x500'
        . '&location=' . $lat . ',' . $lng
        . '&heading=' . $heading
        . '&fov=90'
        . '&pitch=0'
        . '&key=' . urlencode($googleKey);
    ?>
    <img src="<?= htmlspecialchars($streetViewUrl) ?>" alt="Street View <?= $heading ?>">
    <pre><?= htmlspecialchars($streetViewUrl) ?></pre>

<?php endforeach; ?>

</body>
</html>