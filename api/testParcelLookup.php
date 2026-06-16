<?php
declare(strict_types=1);

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once __DIR__ . '/resolveLocation.php';

$rawAddress = isset($_POST['address']) ? trim($_POST['address']) : '';
$googleResult = null;
$parcelResult = null;
$fullResult = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parcel Lookup Test (Working Logic)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        input[type="text"] { width: 650px; padding: 10px; font-size: 16px; }
        button { padding: 10px 25px; font-size: 16px; cursor: pointer; }
        .section { margin-top: 30px; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 6px; overflow-x: auto; }
        .result-box { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; border-radius: 8px; margin-top: 20px; }
    </style>
</head>
<body>

<h2>Parcel Lookup Test Harness</h2>
<p><strong>Using working logic from resolveLocation.php</strong></p>

<form method="post">
    <input type="text" name="address" value="<?php echo htmlspecialchars($rawAddress); ?>" 
           placeholder="225 N 1st Street, Buckeye, AZ 85326" required>
    <button type="submit">Resolve</button>
</form>

<?php if ($rawAddress): ?>

    <div class="result-box">

        <?php
        // Prepare input for resolveLocation
        $input = ['address' => $rawAddress];

        // 1. Google Geocode
        $googleResult = getGoogleGeocode($input);
        ?>

        <h3>1. Google Result</h3>
        <pre><?php print_r($googleResult); ?></pre>

        <?php if ($googleResult && !empty($googleResult['city'])): ?>

            <?php
            // 2. Maricopa Parcel Lookup (the working function)
            $street = extractStreetAddress($googleResult['address'] ?? $rawAddress);
            $parcelResult = getMaricopaParcelFromAddress($street, $googleResult['city']);
            ?>

            <h3>2. Maricopa Parcel Result</h3>
            <pre><?php print_r($parcelResult); ?></pre>

            <?php
            // 3. Full resolveLocation pipeline
            $fullResult = resolveLocation($input);
            ?>

            <h3>3. Full resolveLocation() Result</h3>
            <pre><?php print_r($fullResult); ?></pre>

        <?php else: ?>
            <p style="color:red;"><strong>Google geocoding failed. Cannot proceed with parcel lookup.</strong></p>
        <?php endif; ?>

    </div>

<?php endif; ?>

</body>
</html>