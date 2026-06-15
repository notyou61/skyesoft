<?php
// =====================================================
// Location Resolution Test — Census + Parcel
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';

$censusResult = [];
$parcelResult = [];

$rsCode = 'RS-UNKNOWN';
$parcelStatus = 'Unknown';

// Load utilities
$utilsDir = __DIR__ . '/utils';
require_once $utilsDir . '/validateAddressCensus.php';
require_once $utilsDir . '/resolveParcel.php';

if ($rawAddress !== '') {
    $censusResult = validateAddressCensus($rawAddress);

    $parcelResult = resolveParcel(
        $censusResult['normalized']['lat'] ?? null,
        $censusResult['normalized']['lng'] ?? null,
        $censusResult['county'] ?? null,
        $censusResult['countyFips'] ?? null,
        $rawAddress
    );

    // Determine RS Code
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

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Location Resolution Test — Census + Parcel</title>
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

<h2>Location Resolution Test — Census + Parcel</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="2252 N 44th St Phoenix, AZ 85008" style="width:650px;">
    <button type="submit">Resolve Location</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">
    <h3>Input Address</h3>
    <p><strong><?php echo htmlspecialchars($rawAddress); ?></strong></p>

    <h3>Census Result</h3>
    <?php if ($censusResult['valid'] ?? false): ?>
        <p class="success">✅ Valid Address</p>
        <table>
            <tr><td><strong>County</strong></td><td><?php echo htmlspecialchars($censusResult['county'] ?? '—'); ?></td></tr>
            <tr><td><strong>FIPS</strong></td><td><?php echo htmlspecialchars($censusResult['countyFips'] ?? '—'); ?></td></tr>
        </table>
    <?php else: ?>
        <p class="error">❌ <?php echo htmlspecialchars($censusResult['reason'] ?? 'Unknown'); ?></p>
    <?php endif; ?>

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
</div>
<?php endif; ?>

</body>
</html>