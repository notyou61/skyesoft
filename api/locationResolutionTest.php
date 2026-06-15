<?php
// =====================================================
// Location Resolution Test Harness — FIXED INCLUDES
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';

echo "<h2>Location Resolution Test Harness</h2>";

// =====================================================
// FORCE CORRECT PATH (your utils/ folder)
// =====================================================
$utilsPath = __DIR__ . '/utils';

echo "<strong>Looking for files in:</strong> " . $utilsPath . "<br><br>";

$files = [
    'resolveParcel.php',
    'validateAddressCensus.php',
    'resolveJurisdiction.php'
];

foreach ($files as $file) {
    $fullPath = $utilsPath . '/' . $file;
    if (file_exists($fullPath)) {
        echo "✅ Found: $file<br>";
        require_once $fullPath;
    } else {
        echo "❌ MISSING: $file at $fullPath<br>";
    }
}

echo "<hr>";

// =====================================================
// Rest of the test (only runs if files loaded)
// =====================================================
if (!function_exists('resolveParcel')) {
    echo "<h3 style='color:red;'>Cannot continue — missing required functions.</h3>";
    exit;
}

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
        'placeId' => $result['place_id'] ?? '',
        'formatted' => $result['formatted_address'] ?? $address,
        'lat' => $result['geometry']['location']['lat'] ?? null,
        'lng' => $result['geometry']['location']['lng'] ?? null
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

<!-- HTML Output -->
<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="2252 N 44th St Phoenix, AZ 85008" style="width:650px;">
    <button type="submit">Resolve Location</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div style="margin-top:30px; padding:20px; border:1px solid #ddd; background:#f9f9f9;">
    <h3>Input Address</h3>
    <p><strong><?php echo htmlspecialchars($rawAddress); ?></strong></p>

    <h3>Resolution Summary</h3>
    <table style="width:100%; border-collapse:collapse;">
        <tr><td><strong>Google Place ID</strong></td><td><?php echo htmlspecialchars($googleResult['placeId'] ?? '—'); ?></td></tr>
        <tr><td><strong>Jurisdiction</strong></td><td><?php echo htmlspecialchars($jurisdictionResult['label'] ?? '—'); ?></td></tr>
        <tr><td><strong>County</strong></td><td><?php echo htmlspecialchars($censusResult['county'] ?? '—'); ?></td></tr>
        <tr><td><strong>Parcel Count</strong></td><td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td></tr>
        <tr><td><strong>RS Code</strong></td><td><strong><?php echo $rsCode; ?></strong> — <?php echo $parcelStatus; ?></td></tr>
    </table>
</div>
<?php endif; ?>