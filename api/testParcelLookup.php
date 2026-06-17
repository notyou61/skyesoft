<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Lookup Test Harness
 * Version: 5.14.8 — Robust Require + Clear Errors
 */

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once __DIR__ . '/resolveLocation.php';

// =====================================================
// Robust loading of resolveParcel.php
// =====================================================
$resolveParcelFile = __DIR__ . '/utils/resolveParcel.php';

if (!file_exists($resolveParcelFile)) {
    die('<h2 style="color:red;">ERROR: resolveParcel.php not found at:<br>' . $resolveParcelFile . '</h2>');
}

require_once $resolveParcelFile;

// Safety check - make sure the function actually exists
if (!function_exists('resolveParcel')) {
    die('<h2 style="color:red;">ERROR: Function resolveParcel() was not loaded.<br>Check that resolveParcel.php has no syntax errors.</h2>');
}

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parcel Lookup Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        input[type="text"] { width: 650px; padding: 10px; font-size: 16px; }
        button { padding: 10px 25px; font-size: 16px; cursor: pointer; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 6px; overflow-x: auto; }
        .result-box { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; border-radius: 8px; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 15px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #efefef; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>

<h2>Parcel Lookup Test Harness — v5.14.8</h2>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="3145 N 33rd Ave Phoenix AZ 85017" required>
    <button type="submit">Resolve</button>
</form>

<?php if ($rawAddress): ?>

    <div class="result-box">

        <?php
        $input = ['address' => $rawAddress];
        $googleResult = getGoogleGeocode($input);
        ?>

        <h3>1. Google Result</h3>
        <pre><?php print_r($googleResult); ?></pre>

        <?php if ($googleResult && isset($googleResult['lat'], $googleResult['lng'])): ?>

            <?php
            $placeDetails = null; // We removed placeId-based fetch

            $parcelResult = resolveParcel(
                $googleResult['lat'] ?? null,
                $googleResult['lng'] ?? null,
                'Maricopa',
                '013',
                $rawAddress,
                $placeDetails
            );
            ?>

            <h3>2. resolveParcel() Result</h3>
            <pre><?php print_r($parcelResult); ?></pre>

            <?php if (!empty($parcelResult)): ?>
            <table>
                <tr>
                    <th>Success</th>
                    <td class="<?php echo $parcelResult['success'] ? 'success' : 'error'; ?>">
                        <?php echo $parcelResult['success'] ? '✅ Yes' : '❌ No'; ?>
                    </td>
                </tr>
                <tr>
                    <th>Parcel Count</th>
                    <td><?php echo $parcelResult['parcelCount'] ?? 0; ?></td>
                </tr>
                <tr>
                    <th>Search Source</th>
                    <td><?php echo htmlspecialchars($parcelResult['searchSource'] ?? 'none'); ?></td>
                </tr>
                <tr>
                    <th>Search Tier</th>
                    <td><?php echo htmlspecialchars($parcelResult['searchTier'] ?? 'none'); ?></td>
                </tr>
                <tr>
                    <th>Jurisdiction</th>
                    <td><?php echo htmlspecialchars($parcelResult['jurisdictionName'] ?? ''); ?></td>
                </tr>
            </table>
            <?php endif; ?>

            <?php
            $fullResult = resolveLocation($input);
            ?>
            <h3>3. Legacy resolveLocation() Result</h3>
            <pre><?php print_r($fullResult); ?></pre>

        <?php else: ?>
            <p style="color:red;">Google geocoding failed.</p>
        <?php endif; ?>

    </div>

<?php endif; ?>

</body>
</html>