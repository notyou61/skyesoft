<?php

// #region ⚙️ Runtime

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/utils/envLoader.php';

skyesoftLoadEnv();

// #endregion

// #region 📍 Test Address

$rawAddress = '7401 E CAMELBACK RD SCOTTSDALE AZ 85251';

// #endregion

// #region 🧹 Multi-Stage Normalization

$normalizedAddress = strtoupper(trim($rawAddress));
$normalizedAddress = str_replace([', USA', ','], ' ', $normalizedAddress);
$normalizedAddress = preg_replace('/\s+/', ' ', $normalizedAddress);

$searchTerms = [
    $normalizedAddress,
    preg_replace('/\b(E|W|N|S)\b/', '', $normalizedAddress),           // Remove directionals
    preg_replace('/\b(RD|ROAD|ST|AVE|BLVD)\b/', '', $normalizedAddress), // Remove suffixes
    preg_replace('/[^A-Z0-9 ]/', '', $normalizedAddress)               // Keep only letters/numbers/spaces
];

error_log('Original: ' . $rawAddress);

// #endregion

// #region 🌐 Try Multiple Queries

$data = null;

foreach ($searchTerms as $term) {
    if (strlen(trim($term)) < 8) continue;

    $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . 
             str_replace("'", "''", $term) . "%')";

    $params = http_build_query([
        'where'             => $where,
        'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
        'returnGeometry'    => 'true',
        'outSR'             => '4326',
        'geometryPrecision' => 6,
        'f'                 => 'json'
    ]);

    $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

    $response = @file_get_contents($url);

    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['features']) && count($data['features']) > 0) {
            error_log('Success with term: ' . $term);
            break;
        }
    }
}

// #endregion

// #region 📦 Normalize Results

$features = $data['features'] ?? [];

$parcelDetails = [];

foreach ($features as $feature) {
    $attr = $feature['attributes'] ?? [];
    $geom = $feature['geometry'] ?? [];

    if (empty($attr['APN'])) continue;

    $apnRaw = preg_replace('/[^A-Z0-9-]/', '', strtoupper($attr['APN']));

    // Calculate centroid
    $latitude = $longitude = null;
    if (!empty($geom['rings'][0])) {
        $ring = $geom['rings'][0];
        $count = count($ring);
        if ($count > 0) {
            $sumX = $sumY = 0;
            foreach ($ring as $point) {
                $sumX += $point[0];
                $sumY += $point[1];
            }
            $longitude = round($sumX / $count, 6);
            $latitude  = round($sumY / $count, 6);
        }
    }

    $parcelDetails[] = [
        'apnRaw'          => $apnRaw,
        'apnDisplay'      => formatAPN($apnRaw),
        'propertyAddress' => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
        'propertyCity'    => trim($attr['PHYSICAL_CITY'] ?? ''),
        'jurisdiction'    => trim($attr['JURISDICTION'] ?? ''),
        'ownerName'       => trim($attr['OWNER_NAME'] ?? ''),
        'latitude'        => $latitude,
        'longitude'       => $longitude,
        'source'          => 'mca_arcgis_mcassessor',
        'confidence'      => 90
    ];
}

// #endregion

// #region ✅ Output

echo json_encode([
    'success'             => true,
    'inputAddress'        => $rawAddress,
    'normalizedAddress'   => $normalizedAddress,
    'searchTerms'         => $searchTerms,
    'candidateCount'      => count($parcelDetails),
    'parcelDetails'       => $parcelDetails,
    'rawResponsePreview'  => substr($response ?? '', 0, 1000)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// #endregion

// #region 🧰 Helpers

function formatAPN(string $apnRaw): string {
    $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($apnRaw));
    if (strlen($apnRaw) < 8) return $apnRaw;
    return substr($apnRaw, 0, 3) . '-' . substr($apnRaw, 3, 2) . '-' . substr($apnRaw, 5);
}

// #endregion