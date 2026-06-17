<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Lookup Test Harness
 * Version: 5.14.4 — Stable + Google Place Enrichment
 */

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once __DIR__ . '/resolveLocation.php';

// Safe require for resolveJurisdiction.php (used by resolveParcel)
$jurPaths = [
    __DIR__ . '/resolveJurisdiction.php',
    __DIR__ . '/utils/resolveJurisdiction.php',
    dirname(__DIR__) . '/resolveJurisdiction.php'
];
foreach ($jurPaths as $p) {
    if (file_exists($p)) { require_once $p; break; }
}

// Safe require for resolveParcel.php
$parcelPaths = [
    __DIR__ . '/resolveParcel.php',
    __DIR__ . '/utils/resolveParcel.php',
    dirname(__DIR__) . '/resolveParcel.php'
];
$parcelLoaded = false;
foreach ($parcelPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $parcelLoaded = true;
        break;
    }
}
if (!$parcelLoaded) {
    die('<h2 style="color:red;">Error: resolveParcel.php not found. Please upload the latest version.</h2>');
}

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parcel Lookup Test (Google Place + Coordinates)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        input[type="text"] { width: 650px; padding: 10px; font-size: 16px; }
        button { padding: 10px 25px; font-size: 16px; cursor: pointer; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 6px; overflow-x: auto; }
        .result-box { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; border-radius: 8px; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #efefef; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>

<h2>Parcel Lookup Test Harness — v5.14.4 (Google Place Included)</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="225 N 1st St Buckeye AZ 85326" required>
    <button type="submit">Resolve</button>
</form>

<?php if ($rawAddress): ?>

    <div class="result-box">

        <?php
        $input = ['address' => $rawAddress];

        // 1. Google Geocode
        $googleResult = getGoogleGeocode($input);
        ?>

        <h3>1. Google Result</h3>
        <pre><?php print_r($googleResult); ?></pre>

        <?php if ($googleResult && isset($googleResult['lat'], $googleResult['lng'])): ?>

            <?php
            // 2. Get Google Place Details (safe)
            $placeDetails = null;
            if (!empty($googleResult['placeId']) && function_exists('getGooglePlaceDetails')) {
                $placeDetails = getGooglePlaceDetails($googleResult['placeId']);
            }
            ?>

            <?php
            // 3. resolveParcel with Google Place
            $parcelResult = resolveParcel(
                $googleResult['lat'] ?? null,
                $googleResult['lng'] ?? null,
                'Maricopa',
                '013',
                $rawAddress,
                $placeDetails
            );
            ?>

            <h3>2. resolveParcel() Result</h3>
            <pre><?php print_r($parcelResult); ?></pre>

            <?php if (!empty($parcelResult['googlePlace'])): ?>
            <h4>✅ Google Place Enrichment Object</h4>
            <pre><?php print_r($parcelResult['googlePlace']); ?></pre>
            <?php else: ?>
            <p><strong>Google Place object is empty</strong> — Place Details function not returning data or not defined.</p>
            <?php endif; ?>

            <?php if (!empty($parcelResult)): ?>
            <table>
                <tr><th>Success</th><td class="<?php echo $parcelResult['success'] ? 'success' : 'error'; ?>"><?php echo $parcelResult['success'] ? '✅ Yes' : '❌ No'; ?></td></tr>
                <tr><th>Parcel Count</th><td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td></tr>
                <tr><th>Search Source</th><td><?php echo htmlspecialchars($parcelResult['searchSource'] ?? 'none'); ?></td></tr>
                <tr><th>Search Tier</th><td><?php echo htmlspecialchars($parcelResult['searchTier'] ?? 'none'); ?></td></tr>
                <tr><th>Jurisdiction</th><td><?php echo htmlspecialchars($parcelResult['jurisdictionName'] ?? ''); ?></td></tr>
            </table>
            <?php endif; ?>

            <?php
            $fullResult = resolveLocation($input);
            ?>
            <h3>3. Legacy resolveLocation() Result</h3>
            <pre><?php print_r($fullResult); ?></pre>

        <?php else: ?>
            <p style="color:red;">Google geocoding failed.</p>
        <?php endif; ?>

    </div>

<?php endif; ?>

</body>
</html>