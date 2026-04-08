<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — testLocation.php
//  Purpose: Validate Google + MCA + Full Pipeline
// ======================================================================

#region SECTION 0 — Environment Bootstrap

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once __DIR__ . '/resolveLocation.php';

header("Content-Type: text/html; charset=UTF-8");

#endregion


#region SECTION 1 — Debug Environment

echo "<pre>";
echo "GOOGLE KEY: " . (getenv("GOOGLE_API_KEY") ? "✔ Loaded" : "❌ Missing") . "\n";
echo "MCA KEY: " . (getenv("MARICOPA_COUNTY_API_KEY") ? "✔ Loaded" : "❌ Missing") . "\n";
echo "</pre>";

#endregion


#region SECTION 2 — Test Input

$input = [
    'address' => '3145 N 33rd Ave Phoenix, AZ 85017'
];

#endregion


#region SECTION 3 — Google Test

echo "<h2>Google Test</h2>";

$google = getGoogleGeocode($input);

echo "<pre>";
print_r($google);
echo "</pre>";

#endregion


#region SECTION 4 — Maricopa Test (CORRECTED)

echo "<h2>Maricopa Test</h2>";

if ($google && !empty($google['address'])) {

    // ✅ FIX: Use Google result, not undefined $result
    $parcel = getMaricopaParcelFromAddress($google['address']);

    echo "<pre>";
    print_r($parcel);
    echo "</pre>";

} else {

    echo "❌ Google failed — skipping Maricopa test<br>";

}

#endregion


#region SECTION 5 — Full Pipeline Test

echo "<h2>Full resolveLocation()</h2>";

$full = resolveLocation($input);

echo "<pre>";
print_r($full);
echo "</pre>";

#endregion