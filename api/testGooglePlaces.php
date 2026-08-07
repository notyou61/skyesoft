<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$address = trim($_POST['address'] ?? '');

$googleApiKey =
    getenv('GOOGLE_MAPS_API_KEY')
    ?: getenv('GOOGLE_MAPS_PLACE_ID_API_KEY')
    ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: '';

$geocodeResult        = [];
$findPlaceResult      = [];
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
        CURLOPT_SSL_VERIFYPEER => false, // Prevents local cURL SSL cert failures
        CURLOPT_USERAGENT      => 'Skyesoft Google Diagnostics Tool/2.0'
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

if ($address !== '') {

    if ($googleApiKey === '') {
        $curlErrors[] = "CRITICAL: No API key resolved from environment (GOOGLE_MAPS_API_KEY).";
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

        // =====================================================
        // 3. FIND PLACE FROM TEXT
        // =====================================================
        $findPlaceUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' . http_build_query([
            'input'     => $address,
            'inputtype' => 'textquery',
            'fields'    => 'place_id,name,formatted_address,geometry',
            'key'       => $googleApiKey
        ]);

        $findPlaceResult = curlGetJson($findPlaceUrl, $curlErrors);

        // =====================================================
        // 4. PLACE DETAILS
        // =====================================================
        $placeId = $geocodeResult['results'][0]['place_id'] 
            ?? $findPlaceResult['candidates'][0]['place_id'] 
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
                    'types'
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
<title>Google Maps API Diagnostics Tool</title>
<style>
body { font-family: Arial, Helvetica, sans-serif; margin: 40px; background: #f4f6f8; color: #333; }
input[type=text] { width: 600px; padding: 10px; font-size: 15px; border: 1px solid #ccc; border-radius: 4px; }
button { padding: 10px 20px; font-size: 15px; cursor: pointer; background: #0066cc; color: #fff; border: none; border-radius: 4px; }
.section { margin-top: 25px; border: 1px solid #dcdcdc; background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.error-box { background: #fde8e8; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 6px; margin-top: 20px; }
pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow: auto; max-height: 400px; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
th { background: #f0f4f8; }
.badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
.badge-key { background: #e2e8f0; color: #1a202c; }
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
    <p><strong>Resolved Key Source:</strong> <span class="badge badge-key"><?php echo $googleApiKey ? 'Key Present (' . substr($googleApiKey, 0, 8) . '...)' : 'MISSING'; ?></span></p>
    <p><strong>Input Target:</strong> <?php echo htmlspecialchars($address); ?></p>
</div>

<div class="section">
    <h3>2. Geocode Result</h3>
    <pre><?php echo htmlspecialchars(json_encode($geocodeResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="section">
    <h3>3. Find Place Result</h3>
    <pre><?php echo htmlspecialchars(json_encode($findPlaceResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="section">
    <h3>4. Place Details Result</h3>
    <pre><?php echo htmlspecialchars(json_encode($placeDetailsResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<div class="section">
    <h3>5. Reverse Geocode Result</h3>
    <pre><?php echo htmlspecialchars(json_encode($reverseGeocodeResult, JSON_PRETTY_PRINT)); ?></pre>
</div>

<?php
$geoAddress     = $geocodeResult['results'][0]['formatted_address'] ?? 'N/A';
$geoPlaceId      = $geocodeResult['results'][0]['place_id'] ?? 'N/A';
$geoLat          = $geocodeResult['results'][0]['geometry']['location']['lat'] ?? 'N/A';
$geoLng          = $geocodeResult['results'][0]['geometry']['location']['lng'] ?? 'N/A';
$geoLocationType = $geocodeResult['results'][0]['geometry']['location_type'] ?? 'N/A';
$geoTypes        = $geocodeResult['results'][0]['types'] ?? [];

$placeAddress   = $placeDetailsResult['result']['formatted_address'] ?? 'N/A';
$placePlaceId   = $placeDetailsResult['result']['place_id'] ?? 'N/A';
$placeLat       = $placeDetailsResult['result']['geometry']['location']['lat'] ?? 'N/A';
$placeLng       = $placeDetailsResult['result']['geometry']['location']['lng'] ?? 'N/A';
?>

<div class="section">
    <h3>6. Comparison Summary</h3>
    <table>
        <tr>
            <th>Field</th>
            <th>Geocode API</th>
            <th>Places API (Details)</th>
        </tr>
        <tr>
            <td>Address</td>
            <td><?php echo htmlspecialchars($geoAddress); ?></td>
            <td><?php echo htmlspecialchars($placeAddress); ?></td>
        </tr>
        <tr>
            <td>Place ID</td>
            <td><?php echo htmlspecialchars($geoPlaceId); ?></td>
            <td><?php echo htmlspecialchars($placePlaceId); ?></td>
        </tr>
        <tr>
            <td>Latitude</td>
            <td><?php echo htmlspecialchars((string)$geoLat); ?></td>
            <td><?php echo htmlspecialchars((string)$placeLat); ?></td>
        </tr>
        <tr>
            <td>Longitude</td>
            <td><?php echo htmlspecialchars((string)$geoLng); ?></td>
            <td><?php echo htmlspecialchars((string)$placeLng); ?></td>
        </tr>
        <tr>
            <td>Location Type</td>
            <td><?php echo htmlspecialchars($geoLocationType); ?></td>
            <td>—</td>
        </tr>
        <tr>
            <td>Types</td>
            <td><?php echo htmlspecialchars(implode(', ', $geoTypes)); ?></td>
            <td><?php echo htmlspecialchars(implode(', ', $placeDetailsResult['result']['types'] ?? [])); ?></td>
        </tr>
    </table>
</div>

<div class="section">
    <h3>7. Geocode Address Components</h3>
    <pre><?php echo htmlspecialchars(json_encode($geocodeResult['results'][0]['address_components'] ?? [], JSON_PRETTY_PRINT)); ?></pre>
</div>

<?php endif; ?>

</body>
</html>