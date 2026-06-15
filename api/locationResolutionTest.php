<?php
// =====================================================
// Location Resolution Test — Census First
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';

$censusResult = [];

// Load Census validator
$utilsDir = __DIR__ . '/utils';
require_once $utilsDir . '/validateAddressCensus.php';

if ($rawAddress !== '') {
    $censusResult = validateAddressCensus($rawAddress);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Census Address Validation Test</title>
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

<h2>Census Address Validation Test</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="2252 N 44th St Phoenix, AZ 85008" style="width:650px;">
    <button type="submit">Validate with Census</button>
</form>

<?php if ($rawAddress !== ''): ?>
<div class="result">
    <h3>Input Address</h3>
    <p><strong><?php echo htmlspecialchars($rawAddress); ?></strong></p>

    <h3>Census Result</h3>
    <?php if ($censusResult['valid'] ?? false): ?>
        <p class="success">✅ Address is valid in Census database</p>
        <table>
            <tr><td><strong>Normalized Address</strong></td><td><?php echo htmlspecialchars($censusResult['normalized']['address'] ?? '—'); ?></td></tr>
            <tr><td><strong>County</strong></td><td><?php echo htmlspecialchars($censusResult['county'] ?? '—'); ?></td></tr>
            <tr><td><strong>County FIPS</strong></td><td><?php echo htmlspecialchars($censusResult['countyFips'] ?? '—'); ?></td></tr>
            <tr><td><strong>County GEOID</strong></td><td><?php echo htmlspecialchars($censusResult['countyGeoId'] ?? '—'); ?></td></tr>
            <tr><td><strong>Latitude</strong></td><td><?php echo htmlspecialchars($censusResult['normalized']['lat'] ?? '—'); ?></td></tr>
            <tr><td><strong>Longitude</strong></td><td><?php echo htmlspecialchars($censusResult['normalized']['lng'] ?? '—'); ?></td></tr>
        </table>
    <?php else: ?>
        <p class="error">❌ <?php echo htmlspecialchars($censusResult['reason'] ?? 'Unknown error'); ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

</body>
</html>