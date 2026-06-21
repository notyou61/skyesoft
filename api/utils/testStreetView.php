<?php
declare(strict_types=1);

// =====================================================
// Skyesoft - Street View Test
// Independent Test Utility
// =====================================================

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

// =====================================================
// TEST ADDRESS
// =====================================================

$address = '3145 N 33rd Ave Phoenix, AZ 85017';

// =====================================================
// GOOGLE API KEY
// =====================================================

$googleKey =
    skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
    ?: '';

if (empty($googleKey)) {
    die('Google API Key Missing');
}

// =====================================================
// ADDRESS PARITY TEST
// =====================================================

preg_match('/^\s*(\d+)/', $address, $matches);

$streetNumber = isset($matches[1])
    ? (int)$matches[1]
    : 0;

$isOdd = ($streetNumber % 2) === 1;

// =====================================================
// HEADING TEST
// Odd = East / South
// Even = West / North
// =====================================================

$heading = 180;

if (
    stripos($address, 'AVE') !== false ||
    stripos($address, 'AVENUE') !== false ||
    stripos($address, 'RD') !== false ||
    stripos($address, 'ROAD') !== false
) {

    // North/South roadway

    $heading = $isOdd
        ? 180     // South
        : 0;      // North

} else {

    // East/West roadway

    $heading = $isOdd
        ? 90      // East
        : 270;    // West
}

// =====================================================
// STREET VIEW URL
// =====================================================

$streetViewUrl =
    'https://maps.googleapis.com/maps/api/streetview'
    . '?size=900x500'
    . '&location=' . urlencode($address)
    . '&heading=' . $heading
    . '&fov=90'
    . '&pitch=0'
    . '&key=' . urlencode($googleKey);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Street View Test</title>

    <style>

        body{
            font-family:Arial, sans-serif;
            padding:20px;
        }

        img{
            max-width:100%;
            border:1px solid #bbb;
            border-radius:6px;
        }

        pre{
            background:#f5f5f5;
            padding:10px;
            overflow:auto;
        }

    </style>
</head>
<body>

<h2>Skyesoft Street View Test</h2>

<p>
    <strong>Address:</strong>
    <?= htmlspecialchars($address) ?>
</p>

<p>
    <strong>Street Number:</strong>
    <?= $streetNumber ?>
</p>

<p>
    <strong>Odd Address:</strong>
    <?= $isOdd ? 'YES' : 'NO' ?>
</p>

<p>
    <strong>Heading:</strong>
    <?= $heading ?>
</p>

<img src="<?= htmlspecialchars($streetViewUrl) ?>" alt="Street View">

<hr>

<h3>Generated URL</h3>

<pre><?= htmlspecialchars($streetViewUrl) ?></pre>

</body>
</html>