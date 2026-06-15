<?php
// =====================================================
// Location Resolution Test Harness — Safe Version
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';

$googleResult       = [];
$censusResult       = [];
$jurisdictionResult = [];
$parcelResult       = [];

$placeId     = '';
$latitude    = '';
$longitude   = '';

$jurisdictionName = '';
$jurisdictionType = '';

$rsCode       = 'RS-UNKNOWN';
$parcelStatus = 'Unknown';

// =====================================================
// Load Available Utilities
// =====================================================
$utilsDir = __DIR__ . '/utils';

if (file_exists($utilsDir . '/validateAddressCensus.php')) {
    require_once $utilsDir . '/validateAddressCensus.php';
}
if (file_exists($utilsDir . '/resolveParcel.php')) {
    require_once $utilsDir . '/resolveParcel.php';
}
if (file_exists($utilsDir . '/resolveJurisdiction.php')) {
    require_once $utilsDir . '/resolveJurisdiction.php';
}
// Note: validateAddressGoogle.php does not exist yet

// =====================================================
// Process Request
// =====================================================
if ($rawAddress !== '') {

    // 1. Google (placeholder until file is created)
    if (function_exists('validateAddressGoogle')) {
        $googleResult = validateAddressGoogle($rawAddress);
        $placeId   = $googleResult['placeId']   ?? '';
        $latitude  = $googleResult['latitude']  ?? '';
        $longitude = $googleResult['longitude'] ?? '';
    } else {
        // Temporary fallback: extract from Census if available
        $placeId = 'Not available (validateAddressGoogle missing)';
    }

    // 2. Census
    if (function_exists('validateAddressCensus')) {
        $censusResult = validateAddressCensus($rawAddress);
    }

    // 3. Jurisdiction (current version only accepts 1 string argument)
    if (function_exists('resolveJurisdiction')) {
        $jurRaw = $censusResult['county'] ?? $rawAddress;
        $jurisdictionResult = resolveJurisdiction($jurRaw);
        $jurisdictionName = $jurisdictionResult['label'] ?? '';
        $jurisdictionType = $jurisdictionResult['jurisdictionType'] ?? '';
    }

    // 4. Parcel
    if (function_exists('resolveParcel')) {
        $parcelResult = resolveParcel(
            $latitude ?: null,
            $longitude ?: null,
            $censusResult['county'] ?? null,
            $censusResult['countyFips'] ?? null,
            $rawAddress
        );

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

<h2>Location Resolution Test Harness</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="3145 N 33rd Ave, Phoenix, AZ 85017" style="width:650px;">
    <button type="submit">Resolve Location</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">

    <h3>Input Address</h3>
    <p><strong><?php echo htmlspecialchars($rawAddress); ?></strong></p>

    <!-- Google -->
    <h3>Google Result</h3>
    <?php if (!empty($placeId) && $placeId !== 'Not available (validateAddressGoogle missing)'): ?>
        <table>
            <tr><td><strong>Place ID</strong></td><td><?php echo htmlspecialchars($placeId); ?></td></tr>
            <tr><td><strong>Latitude</strong></td><td><?php echo htmlspecialchars($latitude); ?></td></tr>
            <tr><td><strong>Longitude</strong></td><td><?php echo htmlspecialchars($longitude); ?></td></tr>
        </table>
    <?php else: ?>
        <p class="error">❌ Google validation not available yet (validateAddressGoogle.php missing)</p>
    <?php endif; ?>

    <!-- Census -->
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

    <!-- Jurisdiction -->
    <h3>Jurisdiction Result</h3>
    <table>
        <tr><td><strong>Jurisdiction Name</strong></td><td><?php echo htmlspecialchars($jurisdictionName ?: '—'); ?></td></tr>
        <tr><td><strong>Jurisdiction Type</strong></td><td><?php echo htmlspecialchars($jurisdictionType ?: '—'); ?></td></tr>
    </table>

    <!-- Parcel -->
    <h3>Parcel Result</h3>
    <p><strong>Parcel Count:</strong> <?php echo $parcelResult['parcelCount'] ?? 0; ?></p>
    <p><strong>RS Code:</strong> <strong><?php echo $rsCode; ?></strong> — <?php echo $parcelStatus; ?></p>

    <?php if (!empty($parcelResult['parcelDetails'])): ?>
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

    <!-- Governance -->
    <h3>Governance Evaluation</h3>
    <table>
        <tr><td><strong>Google Valid</strong></td><td><?php echo !empty($placeId) && $placeId !== 'Not available (validateAddressGoogle missing)' ? 'Yes' : 'No'; ?></td></tr>
        <tr><td><strong>Census Valid</strong></td><td><?php echo ($censusResult['valid'] ?? false) ? 'Yes' : 'No'; ?></td></tr>
        <tr><td><strong>Jurisdiction Resolved</strong></td><td><?php echo $jurisdictionName ? 'Yes' : 'No'; ?></td></tr>
        <tr><td><strong>Parcel Count</strong></td><td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td></tr>
        <tr><td><strong>RS Code</strong></td><td><strong><?php echo htmlspecialchars($rsCode); ?></strong></td></tr>
        <tr><td><strong>Status</strong></td><td><?php echo htmlspecialchars($parcelStatus); ?></td></tr>
    </table>

</div>
<?php endif; ?>

</body>
</html>