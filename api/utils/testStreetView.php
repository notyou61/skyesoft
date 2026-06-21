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
// STREET VIEW USING PANOID (Exact View)
// =====================================================

$panoid = 'eTWSWopwFhk1iy9PgByh6A';  // From your Google Maps link

$streetViewUrl = 'https://maps.googleapis.com/maps/api/streetview'
    . '?size=900x500'
    . '&pano=' . $panoid
    . '&heading=0'
    . '&fov=45'
    . '&pitch=0'
    . '&key=' . urlencode($googleKey);

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

<hr>

<h3>Generated URL</h3>
<pre><?= htmlspecialchars($streetViewUrl) ?></pre>

</body>
</html>