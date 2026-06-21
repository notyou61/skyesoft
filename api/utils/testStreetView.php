<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Using the same function as contact proposal
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
// USE THE SAME FUNCTION AS CONTACT PROPOSAL
// =====================================================

$reportPath = __DIR__ . '/../reports/contactProposalReport.php';

if (file_exists($reportPath)) {
    require_once $reportPath;
    error_log("[STREETVIEW TEST] Loaded contactProposalReport.php");
} else {
    die("Could not find contactProposalReport.php at: " . $reportPath);
}

if (!function_exists('generateStreetViewImage')) {
    die("generateStreetViewImage function not found");
}

error_log("[STREETVIEW TEST] Calling generateStreetViewImage()");

$streetViewPath = generateStreetViewImage(
    (string)$lat,
    (string)$lng,
    $googleKey,
    $address
);

if ($streetViewPath) {
    echo "<p><strong>Image saved:</strong> " . basename($streetViewPath) . "</p>";
    error_log("[STREETVIEW TEST] ✅ generateStreetViewImage succeeded: " . $streetViewPath);
} else {
    echo "<p>generateStreetViewImage failed</p>";
    error_log("[STREETVIEW TEST] ❌ generateStreetViewImage failed");
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