<?php
declare(strict_types=1);

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once __DIR__ . '/resolveLocation.php';

echo "<pre>";
echo "GOOGLE KEY: " . (getenv("GOOGLE_MAPS_BACKEND_API_KEY") ? "✔ Loaded" : "❌ Missing") . "\n";
echo "MCA KEY: " . (getenv("MARICOPA_COUNTY_API_KEY") ? "✔ Loaded" : "❌ Missing") . "\n";
echo "</pre>";

$input = [
    'address' => '3145 N 33rd Ave Phoenix, AZ 85017'
];

echo "<h2>Google Test</h2>";
$google = getGoogleGeocode($input);
echo "<pre>"; print_r($google); echo "</pre>";

echo "<h2>Maricopa Test</h2>";
if ($google) {
    $street = extractStreetAddress($google['address']);
    $parcel = getMaricopaParcelFromAddress($street, $google['city']);
    echo "<pre>"; print_r($parcel); echo "</pre>";
}

echo "<h2>Full resolveLocation()</h2>";
$full = resolveLocation($input);
echo "<pre>"; print_r($full); echo "</pre>";