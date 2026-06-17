<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Lookup Test Harness
 * Version: 5.14.2 — Coordinate-First + Google Place Enrichment
 * Tests: Google Geocode → Place Details → resolveParcel (with full enrichment)
 */

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once __DIR__ . '/resolveLocation.php';

// Robust require for resolveParcel.php
$possiblePaths = [
    __DIR__ . '/resolveParcel.php',           // same directory (api/)
    __DIR__ . '/utils/resolveParcel.php',     // utils subfolder
    dirname(__DIR__) . '/resolveParcel.php'   // parent directory
];

$resolveParcelPath = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $resolveParcelPath = $path;
        require_once $path;
        break;
    }
}

if (!$resolveParcelPath) {
    die('<h2 style="color:red;">Error: resolveParcel.php not found.<br>Please upload/save the latest resolveParcel.php (v5.14.2) into the api/ folder first.</h2>');
}

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';
$googleResult = null;
$parcelResult = null;
$fullResult = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parcel Lookup Test (Coordinate-First + Google Place)</title>
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

<h2>Parcel Lookup Test Harness — Coordinate-First + Google Place Enrichment (v5.14.2)</h2>
<p><strong>Testing: Google Geocode → Place Details → resolveParcel</strong></p>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="100 E CAMELBACK RD PHOENIX AZ 85012" required>
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
            // Get full Google Place Details for enrichment (safe)
            $placeDetails = null;
            if (!empty($googleResult['placeId']) && function_exists('getGooglePlaceDetails')) {
                $placeDetails = getGooglePlaceDetails($googleResult['placeId']);
            }

            // 2. resolveParcel with Google Place enrichment
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
            <p><strong>Note:</strong> Google Place object not populated (Place Details not passed or unavailable).</p>
            <?php endif; ?>

            <?php if (!empty($parcelResult)): ?>
            <table>
                <tr>
                    <th>Success</th>
                    <td class="<?php echo $parcelResult['success'] ? 'success' : 'error'; ?>">
                        <?php echo $parcelResult['success'] ? '✅ Yes' : '❌ No'; ?>
                    </td>
                </tr>
                <tr>
                    <th>Parcel Count</th>
                    <td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td>
                </tr>
                <tr>
                    <th>Search Source</th>
                    <td><?php echo htmlspecialchars($parcelResult['searchSource'] ?? 'none'); ?></td>
                </tr>
                <tr>
                    <th>Search Tier</th>
                    <td><?php echo htmlspecialchars($parcelResult['searchTier'] ?? 'none'); ?></td>
                </tr>
                <tr>
                    <th>Jurisdiction</th>
                    <td><?php echo htmlspecialchars($parcelResult['jurisdictionName'] ?? ''); ?></td>
                </tr>
                <?php if (!empty($parcelResult['note'])): ?>
                <tr>
                    <th>Note</th>
                    <td><?php echo htmlspecialchars($parcelResult['note']); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php endif; ?>

            <?php
            // 3. Full resolveLocation pipeline (for comparison)
            $fullResult = resolveLocation($input);
            ?>

            <h3>3. Full resolveLocation() Result (Legacy Comparison)</h3>
            <pre><?php print_r($fullResult); ?></pre>

        <?php else: ?>
            <p style="color:red;"><strong>Google geocoding failed. Cannot proceed with parcel lookup.</strong></p>
        <?php endif; ?>

    </div>

<?php endif; ?>

</body>
</html>