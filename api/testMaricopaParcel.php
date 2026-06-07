<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$rawAddress = '3145 N 33rd Ave Phoenix AZ 85017';

// Clean address for query
$searchTerm = strtoupper(preg_replace('/[^A-Z0-9 ]/', '', $rawAddress));
$searchTerm = trim(preg_replace('/\s+/', ' ', $searchTerm));

// Simpler and more reliable where clause
$where = "UPPER(PHYSICAL_ADDRESS) LIKE '%" . str_replace("'", "''", $searchTerm) . "%'";

$params = http_build_query([
    'where'             => $where,
    'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
    'returnGeometry'    => 'true',
    'outSR'             => '4326',           // WGS84 lat/lon
    'geometryPrecision' => 6,
    'f'                 => 'json'
]);

$url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

$response = @file_get_contents($url);

if ($response === false) {
    echo json_encode(['success' => false, 'error' => 'Failed to contact ArcGIS service']);
    exit;
}

$data = json_decode($response, true);
$features = $data['features'] ?? [];

$parcelDetails = [];

foreach ($features as $feature) {
    $attr = $feature['attributes'] ?? [];
    $geom = $feature['geometry'] ?? [];

    if (empty($attr['APN'])) continue;

    $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($attr['APN']));

    $parcelDetails[] = [
        'apnRaw'       => $apnRaw,
        'apnDisplay'   => $apnRaw, // or format it nicely if you want
        'address'      => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
        'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
        'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
        'owner'        => trim($attr['OWNER_NAME'] ?? ''),
        
        // === Per-parcel coordinates ===
        'latitude'     => $geom['y'] ?? null,
        'longitude'    => $geom['x'] ?? null,

        'source'       => 'mca_arcgis_mcassessor',
        'confidence'   => 90
    ];
}

echo json_encode([
    'success'        => true,
    'requestUrl'     => $url,
    'candidateCount' => count($parcelDetails),
    'parcelDetails'  => $parcelDetails
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);