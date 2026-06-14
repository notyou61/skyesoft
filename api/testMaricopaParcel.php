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

echo "=== TESTING PARCEL RESOLUTION ===\n";
echo "Address: " . $rawAddress . "\n\n";

// Multi-stage search terms
$searchTerms = [
    $upper,
    preg_replace('/\b(E|W|N|S)\b/', '', $upper),
    preg_replace('/\b(RD|ROAD|ST|AVE|BLVD)\b/', '', $upper),
    preg_replace('/[^A-Z0-9 ]/', '', $upper),
    '7401 E CAMELBACK',   // Very broad for Camelback
    '3145 N 33RD'         // Very broad for 33rd Ave
];

$data = null;
$successfulTerm = '';

foreach ($searchTerms as $term) {
    echo "Trying: " . $term . "\n";

    $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

    $params = http_build_query([
        'where'          => $where,
        'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ]);

    $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;
    echo "URL: " . $url . "\n";

    $response = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));

    if ($response === false) {
        echo "Request failed (network/timeout)\n\n";
        continue;
    }

    $data = json_decode($response, true);
    $count = isset($data['features']) ? count($data['features']) : 0;

    echo "Results: " . $count . "\n\n";

    if ($count > 0) {
        $successfulTerm = $term;
        break;
    }
}

if (isset($data['features']) && count($data['features']) > 0) {
    echo "✅ SUCCESS with term: " . $successfulTerm . "\n\n";
    echo json_encode($data['features'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo "❌ No results from any term.\n";
    echo "Last raw response preview:\n" . substr($response ?? '', 0, 600) . "...\n";
}