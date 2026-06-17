<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Lookup Test Harness
 * Version: 5.14.5 — Google Place + Jurisdiction Fix
 */

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once __DIR__ . '/resolveLocation.php';

// Safe requires
$jurPaths = [__DIR__.'/resolveJurisdiction.php', __DIR__.'/utils/resolveJurisdiction.php', dirname(__DIR__).'/resolveJurisdiction.php'];
foreach ($jurPaths as $p) { if (file_exists($p)) { require_once $p; break; } }

$parcelPaths = [__DIR__.'/resolveParcel.php', __DIR__.'/utils/resolveParcel.php', dirname(__DIR__).'/resolveParcel.php'];
foreach ($parcelPaths as $p) { if (file_exists($p)) { require_once $p; break; } }

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

<h2>Parcel Lookup Test Harness — v5.14.5 (Google Place + Jurisdiction)</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="225 N 1st St Buckeye AZ 85326" required>
    <button type="submit">Resolve</button>
</form>

<?php if ($rawAddress): ?>

    <div class="result-box">

        <?php
        $input = ['address' => $rawAddress];
        $googleResult = getGoogleGeocode($input);
        ?>

        <h3>1. Google Result</h3>
        <pre><?php print_r($googleResult); ?></pre>

        <?php if ($googleResult && isset($googleResult['lat'], $googleResult['lng'])): ?>

            <?php
            // Direct Google Place Details fetch (more reliable than function)
            $placeDetails = null;
            $googleApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: getenv('GOOGLE_MAPS_PLACE_ID_API_KEY') ?: '';
            if (!empty($googleResult['placeId']) && $googleApiKey) {
                $detailsUrl = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
                    'place_id' => $googleResult['placeId'],
                    'fields'   => 'name,formatted_address,geometry,business_status,types,website,formatted_phone_number,rating,user_ratings_total,url',
                    'key'      => $googleApiKey
                ]);
                $detailsResponse = @file_get_contents($detailsUrl);
                if ($detailsResponse) {
                    $placeDetails = json_decode($detailsResponse, true);
                }
            }
            ?>

            <?php
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
            <p><strong>Google Place object is empty</strong> — direct API call also returned no data (check API key / billing).</p>
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