<?php
// =====================================================
// Location Resolution Test Harness — AI Powered Summary
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
$aiSummary    = '';

// =====================================================
// Load Utilities
// =====================================================
$utilsDir = __DIR__ . '/utils';

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once $utilsDir . '/validateAddressCensus.php';
require_once $utilsDir . '/resolveParcel.php';
require_once $utilsDir . '/resolveJurisdiction.php';

// Try to load askOpenAI.php if it exists
$useAI = false;
if (file_exists(__DIR__ . '/askOpenAI.php')) {
    require_once __DIR__ . '/askOpenAI.php';
    $useAI = true;
}

// =====================================================
// Google Geocode Function
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

    // 4. Jurisdiction fallback
    if (empty($jurisdictionName) && function_exists('resolveJurisdiction')) {
        $jurRaw = $censusResult['county'] ?? $rawAddress;
        $jurisdictionResult = resolveJurisdiction($jurRaw);
        $jurisdictionName = $jurisdictionResult['label'] ?? '';
        $jurisdictionType = $jurisdictionResult['jurisdictionType'] ?? '';
    }

    // =====================================================
    // AI Summary using askOpenAI.php
    // =====================================================
    $aiSummary = '';

    if ($useAI && function_exists('askOpenAI')) {

        $prompt = "You are Skyesoft's location intelligence assistant. Generate a clear, professional, one-paragraph summary.\n\n" .
                  "Start the summary with this exact phrase: \"Skyesoft resolved the details for {$rawAddress}...\"\n\n" .
                  "Key facts to include:\n" .
                  "- Google Place ID: " . ($placeId ?: 'Not available') . "\n" .
                  "- County: " . ($censusResult['county'] ?? 'Unknown') . "\n" .
                  "- Parcels found: " . ($parcelResult['parcelCount'] ?? 0) . "\n" .
                  "- Governing Jurisdiction: " . ($jurisdictionName ?: 'Unknown') . "\n" .
                  "- Postal City: " . ($postalCity ?: 'Unknown') . "\n" .
                  "- Governance: {$rsCode} ({$parcelStatus})\n\n" .
                  "Write naturally and concisely.";

        try {
            $aiResponse = askOpenAI($prompt, [
                'temperature' => 0.3,
                'max_tokens'  => 220
            ]);

            if (is_array($aiResponse)) {
                $aiSummary = trim($aiResponse['content'] ?? $aiResponse['text'] ?? '');
            } elseif (is_string($aiResponse)) {
                $aiSummary = trim($aiResponse);
            }

        } catch (Exception $e) {
            error_log('[TEST HARNESS] askOpenAI error: ' . $e->getMessage());
            $aiSummary = "Skyesoft resolved the details for {$rawAddress}. An error occurred while generating the AI summary.";
        }

    } else {
        // Fallback deterministic version
        $aiSummary = "Skyesoft resolved the details for {$rawAddress}. ";
        if (!empty($placeId)) $aiSummary .= "Google successfully validated the address. ";
        if (($censusResult['valid'] ?? false)) $aiSummary .= "Census confirmed the location is in " . ($censusResult['county'] ?? 'Maricopa') . " County. ";
        $aiSummary .= "Parcel lookup found " . ($parcelResult['parcelCount'] ?? 0) . " parcel(s). ";
        if (!empty($jurisdictionName)) $aiSummary .= "The governing jurisdiction is {$jurisdictionName}. ";
        if (!empty($rsCode) && $rsCode !== 'RS-UNKNOWN') $aiSummary .= "Governance classified this as {$rsCode}.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Location Resolution Test Harness — AI Summary</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.4; font-size: 14px; }
    input[type="text"] { width: 600px; padding: 8px; font-size: 14px; }
    button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
    .result { margin-top: 15px; padding: 15px; border: 1px solid #ccc; background: #f9f9f9; border-radius: 6px; }
    table { border-collapse: collapse; width: 100%; margin-top: 8px; font-size: 13px; }
    th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
    th { background: #f0f0f0; }
    .section-header { margin: 12px 0 6px 0; font-size: 15px; font-weight: bold; }
    .success { color: #2e7d32; }
    .error { color: #c62828; }
    .ai-summary { background: #e8f5e9; padding: 14px; border-left: 5px solid #2e7d32; margin-bottom: 20px; font-size: 14.5px; line-height: 1.5; }
    pre { background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
</style>
</head>
<body>

<h2>Location Resolution Test Harness — AI Powered</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="3145 N 33rd Ave, Phoenix, AZ 85017">
    <button type="submit">Resolve Location</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">

    <!-- AI Summary -->
    <?php if (!empty($aiSummary)): ?>
    <h3>AI Summary</h3>
    <div class="ai-summary">
        <?php echo nl2br(htmlspecialchars($aiSummary)); ?>
    </div>
    <?php endif; ?>

    <!-- 1. Census -->
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
    <?php else: ?>
        <p class="error"><?php echo htmlspecialchars($censusResult['reason'] ?? 'Census validation failed'); ?></p>
    <?php endif; ?>

    <!-- 2. Google -->
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
    <?php else: ?>
        <p class="error">Google validation failed or returned no Place ID.</p>
    <?php endif; ?>

    <!-- 3. Parcel -->
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
                <td><?php echo htmlspecialchars($p['jurisdiction'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- 4. Jurisdiction -->
    <div class="section-header">
        4. Jurisdiction Result 
        <?php if (!empty($jurisdictionName)): ?>
            <span class="success">✅ Resolved</span>
        <?php else: ?>
            <span class="error">❌ Not Resolved</span>
        <?php endif; ?>
    </div>
    <table>
        <tr><td style="width:180px"><strong>Governing Jurisdiction</strong></td><td><?php echo htmlspecialchars($jurisdictionName ?: '—'); ?></td></tr>
        <tr><td><strong>Jurisdiction Type</strong></td><td><?php echo htmlspecialchars($jurisdictionType ?: '—'); ?></td></tr>
        <tr><td><strong>Postal City</strong></td><td><?php echo htmlspecialchars($postalCity ?: '—'); ?></td></tr>
    </table>

    <!-- 5. Governance -->
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