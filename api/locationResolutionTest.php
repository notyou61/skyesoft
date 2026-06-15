<?php
// =====================================================
// Location Resolution Test Harness — Jurisdiction from Parcel
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';

$censusResult       = [];
$googleResult       = [];
$parcelResult       = [];
$jurisdictionResult = [];

$placeId     = '';
$latitude    = '';
$longitude   = '';

$jurisdictionName = '';
$jurisdictionType = '';
$postalCity       = '';

$rsCode       = 'RS-UNKNOWN';
$parcelStatus = 'Unknown';

// =====================================================
// Load Utilities
// =====================================================
$utilsDir = __DIR__ . '/utils';

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once $utilsDir . '/validateAddressCensus.php';
require_once $utilsDir . '/resolveParcel.php';
require_once $utilsDir . '/resolveJurisdiction.php';

// =====================================================
// Google Geocode (your working logic)
// =====================================================
function getGooglePlaceData($searchAddress) {
    $googleApiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY') 
        ?: getenv('GOOGLE_MAPS_BACKEND_API_KEY');

    if (!empty($searchAddress) && !empty($googleApiKey)) {
        $geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . 
            http_build_query([
                'address' => $searchAddress,
                'key'     => $googleApiKey
            ]);

        $geocodeResponse = @file_get_contents($geocodeUrl);
        $geocodeData     = json_decode($geocodeResponse, true);

        if (isset($geocodeData['results'][0])) {
            $result = $geocodeData['results'][0];
            return [
                'placeId'   => $result['place_id'] ?? null,
                'latitude'  => $result['geometry']['location']['lat'] ?? null,
                'longitude' => $result['geometry']['location']['lng'] ?? null,
                'validated' => true
            ];
        }
    }
    return ['placeId' => null, 'latitude' => null, 'longitude' => null, 'validated' => false];
}

// =====================================================
// Process Request (Census → Google → Parcel → Jurisdiction → Governance)
// =====================================================
if ($rawAddress !== '') {

    // 1. Census
    if (function_exists('validateAddressCensus')) {
        $censusResult = validateAddressCensus($rawAddress);
    }

    // 2. Google
    $googleResult = getGooglePlaceData($rawAddress);
    $placeId   = $googleResult['placeId']   ?? '';
    $latitude  = $googleResult['latitude']  ?? '';
    $longitude = $googleResult['longitude'] ?? '';

    // 3. Parcel (primary source for Jurisdiction)
    if (function_exists('resolveParcel')) {
        $parcelResult = resolveParcel(
            $latitude ?: null,
            $longitude ?: null,
            $censusResult['county'] ?? null,
            $censusResult['countyFips'] ?? null,
            $rawAddress
        );

        $parcelCount = $parcelResult['parcelCount'] ?? 0;

        // Set Jurisdiction from Parcel data if available
        if (!empty($parcelResult['parcelDetails'][0]['jurisdiction'])) {
            $jurisdictionName = $parcelResult['parcelDetails'][0]['jurisdiction'];
            $jurisdictionType = 'City'; // Default for now
        }

        // Set Postal City from parcel if available
        if (!empty($parcelResult['parcelDetails'][0]['city'])) {
            $postalCity = $parcelResult['parcelDetails'][0]['city'];
        }

        if ($parcelCount === 0) {
            $rsCode = 'RS-7';
            $parcelStatus = 'Unresolved Parcel';
        } elseif ($parcelCount === 1) {
            $rsCode = 'RS-0';
            $parcelStatus = 'Single Parcel Found';
        } else {
            $rsCode = 'RS-6';
            $parcelStatus = 'Multiple Parcels Found';
        }
    }

    // 4. Jurisdiction (fallback if not from parcel)
    if (empty($jurisdictionName) && function_exists('resolveJurisdiction')) {
        $jurRaw = $censusResult['county'] ?? $rawAddress;
        $jurisdictionResult = resolveJurisdiction($jurRaw);
        $jurisdictionName = $jurisdictionResult['label'] ?? '';
        $jurisdictionType = $jurisdictionResult['jurisdictionType'] ?? '';
    }

    // 5. Governance already calculated
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Location Resolution Test Harness</title>
<style>
    body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
    input[type="text"] { width: 650px; padding: 12px; font-size: 16px; }
    button { padding: 12px 24px; font-size: 16px; cursor: pointer; }
    .result { margin-top: 30px; padding: 25px; border: 1px solid #ccc; background: #f9f9f9; border-radius: 6px; }
    table { border-collapse: collapse; width: 100%; margin-top: 15px; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #f0f0f0; }
    .success { color: green; }
    .error { color: red; }
</style>
</head>
<body>

<h2>Location Resolution Test Harness — Jurisdiction from Parcel</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="3145 N 33rd Ave, Phoenix, AZ 85017" style="width:650px;">
    <button type="submit">Resolve Location</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">

    <h3>Input Address</h3>
    <p><strong><?php echo htmlspecialchars($rawAddress); ?></strong></p>

    <!-- 1. Census -->
    <h3>Census Result</h3>
    <?php if ($censusResult['valid'] ?? false): ?>
        <p class="success">✅ Valid Address</p>
        <table>
            <tr><td><strong>County</strong></td><td><?php echo htmlspecialchars($censusResult['county'] ?? '—'); ?></td></tr>
            <tr><td><strong>County FIPS</strong></td><td><?php echo htmlspecialchars($censusResult['countyFips'] ?? '—'); ?></td></tr>
            <tr><td><strong>County GEOID</strong></td><td><?php echo htmlspecialchars($censusResult['countyGeoId'] ?? '—'); ?></td></tr>
        </table>
    <?php else: ?>
        <p class="error">❌ <?php echo htmlspecialchars($censusResult['reason'] ?? 'Census validation failed'); ?></p>
    <?php endif; ?>

    <!-- 2. Google -->
    <h3>Google Result</h3>
    <?php if (!empty($placeId)): ?>
        <table>
            <tr><td><strong>Place ID</strong></td><td><?php echo htmlspecialchars($placeId); ?></td></tr>
            <tr><td><strong>Latitude</strong></td><td><?php echo htmlspecialchars($latitude); ?></td></tr>
            <tr><td><strong>Longitude</strong></td><td><?php echo htmlspecialchars($longitude); ?></td></tr>
        </table>
    <?php else: ?>
        <p class="error">❌ Google validation failed.</p>
    <?php endif; ?>

    <!-- 3. Parcel -->
    <h3>Parcel Result</h3>
    <p><strong>Parcel Count:</strong> <?php echo $parcelResult['parcelCount'] ?? 0; ?></p>
    <p><strong>RS Code:</strong> <strong><?php echo $rsCode; ?></strong> — <?php echo $parcelStatus; ?></p>

    <?php if (!empty($parcelResult['parcelDetails'])): ?>
    <table>
        <thead><tr><th>APN</th><th>Owner</th><th>Address</th><th>Jurisdiction</th></tr></thead>
        <tbody>
        <?php foreach ($parcelResult['parcelDetails'] as $p): ?>
            <tr>
                <td><?php echo htmlspecialchars($p['parcelNumber'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['ownerName'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['siteAddress'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['jurisdiction'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- 4. Jurisdiction (from Parcel) + Postal City -->
    <h3>Jurisdiction Result</h3>
    <table>
        <tr><td><strong>Governing Jurisdiction</strong></td><td><?php echo htmlspecialchars($jurisdictionName ?: '—'); ?></td></tr>
        <tr><td><strong>Jurisdiction Type</strong></td><td><?php echo htmlspecialchars($jurisdictionType ?: '—'); ?></td></tr>
        <tr><td><strong>Postal City</strong></td><td><?php echo htmlspecialchars($postalCity ?: '—'); ?></td></tr>
    </table>

    <!-- 5. Governance -->
    <h3>Governance Evaluation</h3>
    <table>
        <tr><td><strong>Census Valid</strong></td><td><?php echo ($censusResult['valid'] ?? false) ? 'Yes' : 'No'; ?></td></tr>
        <tr><td><strong>Google Valid</strong></td><td><?php echo !empty($placeId) ? 'Yes' : 'No'; ?></td></tr>
        <tr><td><strong>Parcel Count</strong></td><td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td></tr>
        <tr><td><strong>RS Code</strong></td><td><strong><?php echo htmlspecialchars($rsCode); ?></strong></td></tr>
        <tr><td><strong>Status</strong></td><td><?php echo htmlspecialchars($parcelStatus); ?></td></tr>
    </table>

</div>
<?php endif; ?>

</body>
</html>