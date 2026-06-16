<?php
// =====================================================
// Location Resolution Test Harness
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
// Google Geocode
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
// Process Request
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

    // 3. Parcel
    if (function_exists('resolveParcel')) {
        $parcelResult = resolveParcel(
            $latitude ?: null,
            $longitude ?: null,
            $censusResult['county'] ?? null,
            $censusResult['countyFips'] ?? null,
            $rawAddress
        );

        $parcelCount = $parcelResult['parcelCount'] ?? 0;

        if (!empty($parcelResult['parcelDetails'][0]['jurisdiction'])) {
            $jurisdictionName = $parcelResult['parcelDetails'][0]['jurisdiction'];
            $jurisdictionType = 'City';
        }

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

    // 4. Jurisdiction
    if (empty($jurisdictionName) && function_exists('resolveJurisdiction')) {
        $jurRaw = $censusResult['county'] ?? $rawAddress;
        $jurisdictionResult = resolveJurisdiction($jurRaw);
        $jurisdictionName = $jurisdictionResult['label'] ?? '';
        $jurisdictionType = $jurisdictionResult['jurisdictionType'] ?? '';
    }

    // =====================================================
    // Structured Summary
    // =====================================================
    $summaryLines = [];

    if (($censusResult['valid'] ?? false)) {
        $summaryLines[] = "Skyesoft resolved the details for " . htmlspecialchars($rawAddress) . ".";
        $summaryLines[] = "Census confirmed the location is in " . ucwords(strtolower($censusResult['county'] ?? 'Maricopa')) . " County.";
    }

    if (!empty($placeId)) {
        $summaryLines[] = "Google successfully validated the address and returned a Place ID.";
    }

    $parcelCount = $parcelResult['parcelCount'] ?? 0;
    if ($parcelCount > 0) {
        $summaryLines[] = "Parcel lookup found {$parcelCount} parcel(s) for this address.";
    } else {
        $summaryLines[] = "No parcels were found for this address.";
    }

    if (!empty($jurisdictionName)) {
        $jurisdictionTitle = ucwords(strtolower($jurisdictionName));
        $summaryLines[] = "The governing jurisdiction is {$jurisdictionTitle}.";
        
        if (!empty($postalCity) && strtolower($postalCity) !== strtolower($jurisdictionName)) {
            $postalTitle = ucwords(strtolower($postalCity));
            $summaryLines[] = "The postal city is listed as {$postalTitle}.";
        }
    }

    if (!empty($rsCode) && $rsCode !== 'RS-UNKNOWN') {
        $summaryLines[] = "Governance classified this as {$rsCode} ({$parcelStatus}).";
    }

    $aiSummary = implode("\n", $summaryLines);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Location Resolution Test Harness</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.5; font-size: 14px; }
    input[type="text"] { width: 620px; padding: 10px; font-size: 15px; }
    button { padding: 10px 20px; font-size: 15px; cursor: pointer; }
    .result { margin-top: 20px; padding: 20px; border: 1px solid #ddd; background: #f9f9f9; border-radius: 8px; }
    table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 13px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    th { background: #f0f0f0; }
    .section-header { margin: 18px 0 8px 0; font-size: 16px; font-weight: bold; }
    .success { color: #2e7d32; font-weight: bold; }
    .error { color: #c62828; font-weight: bold; }
    .ai-summary {
        background: #e8f5e9;
        padding: 14px;
        border-left: 6px solid #2e7d32;
        margin: 15px 0;
        font-size: 14.5px;
        line-height: 1.5;
        white-space: pre-line;
    }
    pre { background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 12.5px; }
</style>
</head>
<body>

<h2>Location Resolution Test Harness</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="3145 N 33rd Ave, Phoenix, AZ 85017" style="width:650px;">
    <button type="submit">Resolve Location</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">

    <?php if (!empty($aiSummary)): ?>
    <h3>Summary</h3>
    <div class="ai-summary">
        <?php echo nl2br(htmlspecialchars($aiSummary)); ?>
    </div>
    <?php endif; ?>

    <!-- Census -->
    <div class="section-header">
        1. Census Result 
        <?php if ($censusResult['valid'] ?? false): ?>
            <span class="success">✅ Valid Address</span>
        <?php else: ?>
            <span class="error">❌ Invalid / Not Found</span>
        <?php endif; ?>
    </div>
    <?php if ($censusResult['valid'] ?? false): ?>
        <table>
            <tr><td style="width:180px"><strong>County</strong></td><td><?php echo htmlspecialchars($censusResult['county'] ?? '—'); ?></td></tr>
            <tr><td><strong>County FIPS</strong></td><td><?php echo htmlspecialchars($censusResult['countyFips'] ?? '—'); ?></td></tr>
            <tr><td><strong>County GEOID</strong></td><td><?php echo htmlspecialchars($censusResult['countyGeoId'] ?? '—'); ?></td></tr>
        </table>
    <?php endif; ?>

    <!-- Google -->
    <div class="section-header">
        2. Google Result 
        <?php if (!empty($placeId)): ?>
            <span class="success">✅ Valid</span>
        <?php else: ?>
            <span class="error">❌ Failed</span>
        <?php endif; ?>
    </div>
    <?php if (!empty($placeId)): ?>
        <table>
            <tr><td style="width:180px"><strong>Place ID</strong></td><td><?php echo htmlspecialchars($placeId); ?></td></tr>
            <tr><td><strong>Latitude</strong></td><td><?php echo htmlspecialchars($latitude); ?></td></tr>
            <tr><td><strong>Longitude</strong></td><td><?php echo htmlspecialchars($longitude); ?></td></tr>
        </table>
    <?php endif; ?>

    <!-- Parcel -->
    <div class="section-header">
        3. Parcel Result 
        <?php if (($parcelResult['parcelCount'] ?? 0) > 0): ?>
            <span class="success">✅ Found (<?php echo $parcelResult['parcelCount']; ?>)</span>
        <?php else: ?>
            <span class="error">❌ None Found</span>
        <?php endif; ?>
    </div>

    <?php if (!empty($parcelResult['parcelDetails'])): ?>
    <table>
        <thead>
            <tr>
                <th style="width:140px">APN</th>
                <th>Owner</th>
                <th>Address</th>
                <th style="width:120px">Jurisdiction</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($parcelResult['parcelDetails'] as $p): ?>
            <tr>
                <td><?php echo htmlspecialchars($p['parcelNumber'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['ownerName'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['siteAddress'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars(ucwords(strtolower($p['jurisdiction'] ?? ''))); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Jurisdiction -->
    <div class="section-header">
        4. Jurisdiction Result 
        <?php if (!empty($jurisdictionName)): ?>
            <span class="success">✅ Resolved</span>
        <?php else: ?>
            <span class="error">❌ Not Resolved</span>
        <?php endif; ?>
    </div>
    <table>
        <tr><td style="width:180px"><strong>Governing Jurisdiction</strong></td><td><?php echo htmlspecialchars(ucwords(strtolower($jurisdictionName ?: '—'))); ?></td></tr>
        <tr><td><strong>Jurisdiction Type</strong></td><td><?php echo htmlspecialchars($jurisdictionType ?: '—'); ?></td></tr>
        <tr><td><strong>Postal City</strong></td><td><?php echo htmlspecialchars(ucwords(strtolower($postalCity ?: '—'))); ?></td></tr>
    </table>

    <!-- Governance -->
    <div class="section-header">
        5. Governance Evaluation
        <?php if (in_array($rsCode, ['RS-0', 'RS-6'])): ?>
            <span class="success">✅ Acceptable</span>
        <?php else: ?>
            <span class="error">❌ Blocked</span>
        <?php endif; ?>
    </div>
    <table>
        <tr><td style="width:180px"><strong>Census Valid</strong></td><td><?php echo ($censusResult['valid'] ?? false) ? 'Yes' : 'No'; ?></td></tr>
        <tr><td><strong>Google Valid</strong></td><td><?php echo !empty($placeId) ? 'Yes' : 'No'; ?></td></tr>
        <tr><td><strong>Parcel Count</strong></td><td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td></tr>
        <tr><td><strong>RS Code</strong></td><td><strong><?php echo htmlspecialchars($rsCode); ?></strong></td></tr>
        <tr><td><strong>Status</strong></td><td><?php echo htmlspecialchars($parcelStatus); ?></td></tr>
    </table>

    <!-- JSON Output -->
    <h3 style="margin-top:25px">JSON Output (for AI / Skyesoft Prompt)</h3>
    <?php
    $jsonOutput = [
        'inputAddress' => $rawAddress,
        'google' => [
            'placeId'   => $placeId ?: null,
            'latitude'  => $latitude ?: null,
            'longitude' => $longitude ?: null,
        ],
        'census' => $censusResult,
        'parcel' => $parcelResult,
        'jurisdiction' => [
            'governingJurisdiction' => $jurisdictionName ?: null,
            'jurisdictionType'      => $jurisdictionType ?: null,
            'postalCity'            => $postalCity ?: null,
        ],
        'governance' => [
            'rsCode'       => $rsCode,
            'parcelStatus' => $parcelStatus,
        ]
    ];
    ?>
    <pre><?php echo json_encode($jsonOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></pre>

</div>
<?php endif; ?>

</body>
</html>