<?php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

// #region 📍 Test Address
$rawAddress = '7401 E CAMELBACK RD SCOTTSDALE AZ 85251';
// $rawAddress = '3145 N 33rd Ave Phoenix AZ 85017';
// #endregion

$original = $rawAddress;
$upper = strtoupper(trim($rawAddress));

error_log('=== PARCEL TEST START ===');
error_log('Original Address: ' . $original);

// Multi-stage normalization
$normalized = $upper;
$normalized = str_replace([', USA', ','], ' ', $normalized);
$normalized = preg_replace('/\s+/', ' ', $normalized);

$searchTerms = [
    $normalized,
    preg_replace('/\b(E|W|N|S)\b/', '', $normalized),
    preg_replace('/\b(RD|ROAD|ST|AVE|BLVD)\b/', '', $normalized),
    preg_replace('/[^A-Z0-9 ]/', '', $normalized)
];

echo "Testing address: " . $rawAddress . "\n\n";

$data = null;

foreach ($searchTerms as $term) {
    echo "Trying term: " . $term . "\n";

    $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

    $params = http_build_query([
        'where'          => $where,
        'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ]);

    $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

    echo "URL: " . $url . "\n";

    $response = @file_get_contents($url);

    if ($response === false) {
        echo "Request failed.\n\n";
        continue;
    }

    $data = json_decode($response, true);

    $count = isset($data['features']) ? count($data['features']) : 0;
    echo "Results found: " . $count . "\n\n";

    if ($count > 0) {
        echo "SUCCESS with term: " . $term . "\n";
        break;
    }
}

if (isset($data['features']) && count($data['features']) > 0) {
    echo json_encode($data['features'], JSON_PRETTY_PRINT);
} else {
    echo "No results found for any search term.\n";
    echo "Raw response preview: " . substr($response ?? '', 0, 500);
}