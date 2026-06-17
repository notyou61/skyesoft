<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$address = trim($_POST['address'] ?? '');

$googleApiKey =
    getenv('GOOGLE_MAPS_API_KEY')
    ?: getenv('GOOGLE_MAPS_PLACE_ID_API_KEY')
    ?: '';

$geocodeResult = [];
$findPlaceResult = [];
$placeDetailsResult = [];

function curlGetJson($url)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Skyesoft Google Places Test'
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);

    curl_close($ch);

    if ($error || !$response) {
        return null;
    }

    return json_decode($response, true);
}

if ($address !== '' && $googleApiKey !== '') {

    // =====================================================
    // GEOCODE API
    // =====================================================

    $geocodeUrl =
        'https://maps.googleapis.com/maps/api/geocode/json?' .
        http_build_query([
            'address' => $address,
            'key'     => $googleApiKey
        ]);

    $geocodeResult = curlGetJson($geocodeUrl);

    // =====================================================
    // FIND PLACE FROM TEXT
    // =====================================================

    $findPlaceUrl =
        'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' .
        http_build_query([
            'input'     => $address,
            'inputtype' => 'textquery',
            'fields'    => 'place_id,name,formatted_address',
            'key'       => $googleApiKey
        ]);

    $findPlaceResult = curlGetJson($findPlaceUrl);

    // =====================================================
    // PLACE DETAILS
    // =====================================================

    $placeId = '';

    if (!empty($findPlaceResult['candidates'][0]['place_id'])) {
        $placeId = $findPlaceResult['candidates'][0]['place_id'];
    }

    if ($placeId) {

        $detailsUrl =
            'https://maps.googleapis.com/maps/api/place/details/json?' .
            http_build_query([
                'place_id' => $placeId,
                'fields'   => implode(',', [
                    'name',
                    'formatted_address',
                    'geometry',
                    'business_status',
                    'types'
                ]),
                'key'      => $googleApiKey
            ]);

        $placeDetailsResult = curlGetJson($detailsUrl);
    }
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Google Places Test</title>

<style>

body{
    font-family:Arial,Helvetica,sans-serif;
    margin:40px;
}

input[type=text]{
    width:700px;
    padding:10px;
    font-size:15px;
}

button{
    padding:10px 20px;
    cursor:pointer;
}

.section{
    margin-top:30px;
    border:1px solid #ddd;
    background:#f9f9f9;
    padding:20px;
}

pre{
    background:#fff;
    border:1px solid #ddd;
    padding:15px;
    overflow:auto;
}

table{
    border-collapse:collapse;
    width:100%;
}

th,
td{
    border:1px solid #ccc;
    padding:8px;
    text-align:left;
}

th{
    background:#efefef;
}

</style>

</head>
<body>

<h2>Google Places API Test</h2>

<form method="post">

    <input
        type="text"
        name="address"
        value="<?php echo htmlspecialchars($address); ?>"
        placeholder="100 E CAMELBACK RD PHOENIX AZ 85012">

    <button type="submit">
        Test Address
    </button>

</form>

<?php if ($address !== ''): ?>

<div class="section">

    <h3>1. Input Address</h3>

    <p>
        <strong>
            <?php echo htmlspecialchars($address); ?>
        </strong>
    </p>

</div>

<div class="section">

    <h3>2. Geocode Result</h3>

    <pre><?php print_r($geocodeResult); ?></pre>

</div>

<div class="section">

    <h3>3. Find Place Result</h3>

    <pre><?php print_r($findPlaceResult); ?></pre>

</div>

<div class="section">

    <h3>4. Place Details Result</h3>

    <pre><?php print_r($placeDetailsResult); ?></pre>

</div>

<div class="section">

    <h3>5. Comparison Summary</h3>

    <?php

    $geoAddress =
        $geocodeResult['results'][0]['formatted_address']
        ?? '';

    $geoPlaceId =
        $geocodeResult['results'][0]['place_id']
        ?? '';

    $geoLat =
        $geocodeResult['results'][0]['geometry']['location']['lat']
        ?? '';

    $geoLng =
        $geocodeResult['results'][0]['geometry']['location']['lng']
        ?? '';

    $placeAddress =
        $placeDetailsResult['result']['formatted_address']
        ?? '';

    $placePlaceId =
        $placeDetailsResult['result']['place_id']
        ?? '';

    $placeLat =
        $placeDetailsResult['result']['geometry']['location']['lat']
        ?? '';

    $placeLng =
        $placeDetailsResult['result']['geometry']['location']['lng']
        ?? '';

    ?>

    <table>

        <tr>
            <th>Field</th>
            <th>Geocode</th>
            <th>Places</th>
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

    </table>

</div>

<?php endif; ?>

</body>
</html>