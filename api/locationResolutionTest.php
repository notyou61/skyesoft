<?php
// =====================================================
// Location Resolution Test Harness — DEBUG VERSION
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';

echo "<h2>Location Resolution Test Harness — DEBUG</h2>";

// =====================================================
// Path Debugging
// =====================================================
$utilsDir = __DIR__ . '/utils';
echo "<strong>Current Directory (__DIR__):</strong> " . __DIR__ . "<br>";
echo "<strong>Utils Directory:</strong> " . $utilsDir . "<br>";

$filesToCheck = [
    'resolveParcel.php'       => $utilsDir . '/resolveParcel.php',
    'validateAddressCensus.php' => $utilsDir . '/validateAddressCensus.php',
    'resolveJurisdiction.php' => $utilsDir . '/resolveJurisdiction.php'
];

echo "<h3>File Check</h3><pre>";
foreach ($filesToCheck as $name => $path) {
    $exists = file_exists($path) ? '✅ EXISTS' : '❌ NOT FOUND';
    echo "$name → $path  $exists\n";
}
echo "</pre>";

// =====================================================
// Safe Includes
// =====================================================
$includeSuccess = true;

if (file_exists($utilsDir . '/resolveParcel.php')) {
    require_once $utilsDir . '/resolveParcel.php';
} else {
    echo "<p style='color:red;'>❌ Could not load resolveParcel.php</p>";
    $includeSuccess = false;
}

if (file_exists($utilsDir . '/validateAddressCensus.php')) {
    require_once $utilsDir . '/validateAddressCensus.php';
} else {
    echo "<p style='color:orange;'>⚠️ Could not load validateAddressCensus.php (continuing...)</p>";
}

if (file_exists($utilsDir . '/resolveJurisdiction.php')) {
    require_once $utilsDir . '/resolveJurisdiction.php';
} else {
    echo "<p style='color:orange;'>⚠️ Could not load resolveJurisdiction.php (continuing...)</p>";
}

if (!$includeSuccess) {
    echo "<h3 style='color:red;'>Cannot continue without resolveParcel.php. Check the file path above.</h3>";
    exit;
}

// Rest of the code (same as before)
$googleResult = [];
$censusResult = [];
$parcelResult = [];
$jurisdictionResult = [];

$rsCode = 'RS-UNKNOWN';
$parcelStatus = 'Unknown';

function geocodeGoogle($address) {
    $apiKey = getenv('GOOGLE_MAPS_BACKEND_API_KEY');
    if (empty($apiKey)) return [];
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $apiKey;
    $response = @file_get_contents($url);
    if ($response === false) return [];
    $data = json_decode($response, true);
    if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) return [];
    $result = $data['results'][0];
    return [
        'placeId'   => $result['place_id'] ?? '',
        'formatted' => $result['formatted_address'] ?? $address,
        'lat'       => $result['geometry']['location']['lat'] ?? null,
        'lng'       => $result['geometry']['location']['lng'] ?? null
    ];
}

if ($rawAddress !== '') {
    $googleResult = geocodeGoogle($rawAddress);
    $censusResult = validateAddressCensus($rawAddress);

    $parcelResult = resolveParcel(
        $googleResult['lat'] ?? null,
        $googleResult['lng'] ?? null,
        $censusResult['county'] ?? null,
        $censusResult['countyFips'] ?? null,
        $rawAddress
    );

    $jurRaw = $parcelResult['jurisdictionName'] ?? ($censusResult['county'] ?? '');
    $jurisdictionResult = resolveJurisdiction($jurRaw);

    $parcelCount = $parcelResult['parcelCount'] ?? 0;
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
?>

<!-- The rest of your HTML output (same as previous version) -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Location Resolution Test Harness - DEBUG</title>
<style>
    body { font-family: Arial, sans-serif; margin: 30px; line-height: 1.5; }
    input[type="text"] { width: 620px; padding: 10px; font-size: 15px; }
    button { padding: 10px 20px; font-size: 15px; }
    .result { margin-top: 30px; padding: 20px; border: 1px solid #ddd; background: #f9f9f9; }
    table { border-collapse: collapse; width: 100%; margin: 15px 0; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    th { background: #f0f0f0; }
</style>
</head>
<body>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="3145 N 33rd Ave, Phoenix, AZ 85017" style="width:650px;">
    <button type="submit">Resolve Location</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">
    <h3>Input Address</h3>
    <p><strong><?php echo htmlspecialchars($rawAddress); ?></strong></p>

    <h3>Resolution Summary</h3>
    <table>
        <tr><td><strong>Google Place ID</strong></td><td><?php echo htmlspecialchars($googleResult['placeId'] ?? '—'); ?></td></tr>
        <tr><td><strong>Jurisdiction</strong></td><td><?php echo htmlspecialchars($jurisdictionResult['label'] ?? '—'); ?> (<?php echo htmlspecialchars($jurisdictionResult['jurisdictionType'] ?? '—'); ?>)</td></tr>
        <tr><td><strong>County</strong></td><td><?php echo htmlspecialchars($censusResult['county'] ?? '—'); ?> (FIPS: <?php echo htmlspecialchars($censusResult['countyFips'] ?? '—'); ?>)</td></tr>
        <tr><td><strong>Parcel Count</strong></td><td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td></tr>
        <tr><td><strong>RS Code</strong></td><td><strong><?php echo $rsCode; ?></strong> — <?php echo $parcelStatus; ?></td></tr>
    </table>

    <?php if (!empty($parcelResult['parcelDetails'])): ?>
    <h3>Parcel Details</h3>
    <table>
        <thead><tr><th>APN</th><th>Owner</th><th>Address</th></tr></thead>
        <tbody>
        <?php foreach ($parcelResult['parcelDetails'] as $p): ?>
            <tr>
                <td><?php echo htmlspecialchars($p['parcelNumber'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['ownerName'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($p['siteAddress'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>