<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$address = trim($_POST['address'] ?? $_GET['address'] ?? '');

// Match SECTION 08 key resolution order exactly
$googleApiKey = '';
if (function_exists('skyesoftGetEnv')) {
    $googleApiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY') ?: skyesoftGetEnv('GOOGLE_MAPS_API_KEY');
}
if (empty($googleApiKey)) {
    $googleApiKey = getenv('GOOGLE_MAPS_BACKEND_API_KEY')
        ?: getenv('GOOGLE_MAPS_API_KEY')
        ?: getenv('GOOGLE_MAPS_PLACE_ID_API_KEY')
        ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
        ?: '';
}

$geocodeResult        = [];
$findPlaceResult      = [];
$textSearchResult      = [];
$placeDetailsResult   = [];
$reverseGeocodeResult = [];
$curlErrors           = [];

function curlGetJson(string $url, array &$errorList): ?array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Skyesoft Google Diagnostics Tool/3.0'
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($error) {
        $errorList[] = "cURL Error for [{$url}]: " . $error;
        return null;
    }

    if ($httpCode >= 400) {
        $errorList[] = "HTTP Status {$httpCode} returned for [{$url}]";
    }

    return json_decode((string)$response, true);
}

// Utility to clean suite/unit numbers prior to Places API search
function cleanAddressForPlaces(string $rawAddress): string
{
    $clean = preg_replace('/\b(suite|ste|unit|apt|apartment|#)\s*[\w\-]+/i', '', $rawAddress);
    return trim(preg_replace('/\s+/', ' ', $clean));
}

if ($address !== '') {

    if ($googleApiKey === '') {
        $curlErrors[] = "CRITICAL: No API key resolved from environment (GOOGLE_MAPS_BACKEND_API_KEY / GOOGLE_MAPS_API_KEY).";
    } else {

        // =====================================================
        // 1. GEOCODE API
        // =====================================================
        $geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $address,
            'key'     => $googleApiKey
        ]);

        $geocodeResult = curlGetJson($geocodeUrl, $curlErrors);

        // =====================================================
        // 2. REVERSE GEOCODE FROM RETURNED COORDINATES
        // =====================================================
        if (!empty($geocodeResult['results'][0]['geometry']['location'])) {
            $lat = $geocodeResult['results'][0]['geometry']['location']['lat'];
            $lng = $geocodeResult['results'][0]['geometry']['location']['lng'];

            $reverseUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
                'latlng' => $lat . ',' . $lng,
                'key'    => $googleApiKey
            ]);

            $reverseGeocodeResult = curlGetJson($reverseUrl, $curlErrors);
        }

        // Clean unit string for Places API calls
        $placesQueryAddress = cleanAddressForPlaces($address);

        // =====================================================
        // 3. FIND PLACE FROM TEXT
        // =====================================================
        $findPlaceUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' . http_build_query([
            'input'     => $placesQueryAddress,
            'inputtype' => 'textquery',
            'fields'    => 'place_id,name,formatted_address,geometry',
            'key'       => $googleApiKey
        ]);

        $findPlaceResult = curlGetJson($findPlaceUrl, $curlErrors);

        // =====================================================
        // 4. PLACES TEXT SEARCH (FALLBACK / SUPPLEMENTAL)
        // =====================================================
        $textSearchUrl = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
            'query' => $placesQueryAddress,
            'key'   => $googleApiKey
        ]);

        $textSearchResult = curlGetJson($textSearchUrl, $curlErrors);

        // =====================================================
        // 5. PLACE DETAILS
        // =====================================================
        $placeId = $geocodeResult['results'][0]['place_id'] 
            ?? $findPlaceResult['candidates'][0]['place_id'] 
            ?? $textSearchResult['results'][0]['place_id']
            ?? '';

        if ($placeId !== '') {
            $detailsUrl = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
                'place_id' => $placeId,
                'fields'   => implode(',', [
                    'place_id',
                    'name',
                    'formatted_address',
                    'geometry',
                    'business_status',
                    'types',
                    'address_components'
                ]),
                'key'      => $googleApiKey
            ]);

            $placeDetailsResult = curlGetJson($detailsUrl, $curlErrors);
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Google Maps API Diagnostics Tool v3.0</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 30px; background: #f4f6f8; color: #2d3748; }
input[type=text] { width: 620px; padding: 12px; font-size: 15px; border: 1px solid #cbd5e0; border-radius: 6px; box-sizing: border-box; }
button { padding: 12px 24px; font-size: 15px; font-weight: 600; cursor: pointer; background: #2b6cb0; color: #fff; border: none; border-radius: 6px; }
button:hover { background: #2c5282; }
.section { margin-top: 25px; border: 1px solid #e2e8f0; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.error-box { background: #fed7d7; border: 1px solid #f5c6cb; color: #9b2c2c; padding: 15px; border-radius: 6px; margin-top: 20px; }
pre { background: #1a202c; color: #e2e8f0; padding: 15px; border-radius: 6px; overflow: auto; max-height: 400px; font-size: 13px; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; }
th { background: #edf2f7; color: #4a5568; font-size: 14px; }
.badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
.badge-key { background: #c6f6d5; color: #22543d; }
.badge-missing { background: #fed7d7; color: #9b2c2c; }
</style>
</head>
<body>

<h2>Google Maps API Diagnostics Tool</h2>

<form method="post">
    <input
        type="text"
        name="address"
        value="<?php echo htmlspecialchars($address); ?>"
        placeholder="100 E CAMELBACK RD PHOENIX AZ 85012">

    <button type="submit">Run Diagnostics</button>
</form>

<?php if (!empty($curlErrors)): ?>
    <div class="error-box">
        <strong>Execution Warnings / Errors:</strong>
        <ul>
            <?php foreach ($curlErrors as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($address !== ''): ?>

<div class="section">
    <h3>1. Execution Environment</h3>
    <p><strong>Resolved Key Status:</strong> 
        <?php if ($googleApiKey !== ''): ?>
            <span class="badge badge-key">Key Present (<?php echo htmlspecialchars(substr($googleApiKey, 0, 8)); ?>...)</span>
        <?php else: ?>
            <span class="badge badge-missing">MISSING</span>
        <?php endif; ?>
    </p>
    <p><strong>Input Target Address:</strong> <?php echo htmlspecialchars($address); ?></p>
    <p><strong>Places Sanitized Query:</strong> <?php echo htmlspecialchars(cleanAddressForPlaces($address)); ?></p>
</div>

<?php
$geoAddress     = $geocodeResult['results'][0]['formatted_address'] ?? 'N/A';
$geoPlaceId      = $geocodeResult['results'][0]['place_id'] ?? 'N/A';
$geoLat          = $geocodeResult['results'][0]['geometry']['location']['lat'] ?? 'N/A';
$geoLng          = $geocodeResult['results'][0]['geometry']['location']['lng'] ?? 'N/A';
$geoLocationType = $geocodeResult['results'][0]['geometry']['location_type'] ?? 'N/A';
$geoTypes        = $geocodeResult['results'][0]['types'] ?? [];

$findPlaceId      = $findPlaceResult['candidates'][0]['place_id'] ?? 'N/A';
$findPlaceAddress = $findPlaceResult['candidates'][0]['formatted_address'] ?? 'N/A';

$textPlaceId      = $textSearchResult['results'][0]['place_id'] ?? 'N/A';
$textPlaceAddress = $textSearchResult['results'][0]['formatted_address'] ?? 'N/A';

$placeAddress   = $placeDetailsResult['result']['formatted_address'] ?? 'N/A';
$placePlaceId   = $placeDetailsResult['result']['place_id'] ?? 'N/A';
$placeLat       = $placeDetailsResult['result']['geometry']['location']['lat'] ?? 'N/A';
$placeLng       = $placeDetailsResult['result']['geometry']['location']['lng'] ?? 'N/A';
?>

<div class="section">
    <h3>2. Resolution & Endpoint Comparison</h3>
    <table>
        <tr>
            <th>Metric / Endpoint</th>
            <th>Geocode API</th>
            <th>Find Place API</th>
            <th>Text Search API</th>
            <th>Place Details API</th>
        </tr>
        <tr>
            <td><strong>Place ID</strong></td>
            <td><code><?php echo htmlspecialchars($geoPlaceId); ?></code></td>
            <td><code><?php echo htmlspecialchars($findPlaceId); ?></code></td>
            <td><code><?php echo htmlspecialchars($textPlaceId); ?></code></td>
            <td><code><?php echo htmlspecialchars($placePlaceId); ?></code></td>
        </tr>
        <tr>
            <td><strong>Formatted Address</strong></td>
            <td><?php echo htmlspecialchars($geoAddress); ?></td>
            <td><?php echo htmlspecialchars($findPlaceAddress); ?></td>
            <td><?php echo htmlspecialchars($textPlaceAddress); ?></td>
            <td><?php echo htmlspecialchars($placeAddress); ?></td>
        </tr>
        <tr>
            <td><strong>Latitude</strong></td>
            <td><?php echo htmlspecialchars((string)$geoLat); ?></td>
            <td><?php echo htmlspecialchars((string)($findPlaceResult['candidates'][0]['geometry']['location']['lat'] ?? 'N/A')); ?></td>
            <td><?php echo htmlspecialchars((string)($textSearchResult['results'][0]['geometry']['location']['lat'] ?? 'N/A')); ?></td>
            <td><?php echo htmlspecialchars((string)$placeLat); ?></td>
        </tr>
        <tr>
            <td><strong>Longitude</strong></td>
            <td><?php echo htmlspecialchars((string)$geoLng); ?></td>
            <td><?php echo htmlspecialchars((string)($findPlaceResult['candidates'][0]['geometry']['location']['lng'] ?? 'N/A')); ?></td>
            <td><?php echo htmlspecialchars((string)($textSearchResult['results'][0]['geometry']['location']['lng'] ?? 'N/A')); ?></td>
            <td><?php echo htmlspecialchars((string)$placeLng); ?></td>
        </tr>
        <tr>
            <td><strong>Location Type / Status</strong></td>
            <td><?php echo htmlspecialchars($geoLocationType); ?></td>
            <td>—</td>
            <td>—</td>
            <td><?php echo htmlspecialchars($placeDetailsResult['result']['business_status'] ?? 'N/A'); ?></td>
        </tr>
    </table>
</div>

<div class="section">
    <h3>3. Raw Geocode Result Payload</h3>
    <pre><?php echo htmlspecialchars(json_encode($geocodeResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="section">
    <h3>4. Raw Find Place Result Payload</h3>
    <pre><?php echo htmlspecialchars(json_encode($findPlaceResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="section">
    <h3>5. Raw Text Search Result Payload</h3>
    <pre><?php echo htmlspecialchars(json_encode($textSearchResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="section">
    <h3>6. Raw Place Details Result Payload</h3>
    <pre><?php echo htmlspecialchars(json_encode($placeDetailsResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="section">
    <h3>7. Raw Reverse Geocode Result Payload</h3>
    <pre><?php echo htmlspecialchars(json_encode($reverseGeocodeResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<?php endif; ?>

</body>
</html>