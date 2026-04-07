<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

echo "<pre>";
echo "GOOGLE KEY: " . getenv("GOOGLE_API_KEY") . "\n";
echo "MCA KEY: " . getenv("MARICOPA_COUNTY_API_KEY") . "\n";
echo "</pre>";

require_once __DIR__ . '/resolveLocation.php';

// 🔍 TEST INPUT (REAL ADDRESS)
$input = [
    'address' => '3145 N 33rd Ave Phoenix, AZ 85017'
];

// ==========================
// TEST 1 — GOOGLE ONLY
// ==========================
echo "<h2>Google Test</h2>";

$google = getGoogleGeocode($input);

echo "<pre>";
print_r($google);
echo "</pre>";


// ==========================
// TEST 2 — MARICOPA ONLY
// ==========================
echo "<h2>Maricopa Test</h2>";

if ($google && !empty($google['address'])) {
    $parcel = getMaricopaParcelFromAddress($google['address']);

    echo "<pre>";
    print_r($parcel);
    echo "</pre>";
} else {
    echo "Google failed — skipping Maricopa test<br>";
}


// ==========================
// TEST 3 — FULL PIPELINE
// ==========================
echo "<h2>Full resolveLocation()</h2>";

$full = resolveLocation($input);

echo "<pre>";
print_r($full);
echo "</pre>";