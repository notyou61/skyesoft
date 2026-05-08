<?php

// #region ⚙️ Runtime

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/utils/envLoader.php';

skyesoftLoadEnv();

// #endregion



// #region 📍 Test Address

$rawAddress = '3145 N 33rd Ave Phoenix AZ 85017';

// #endregion



// #region 🧹 Normalize Address

$normalizedAddress = strtoupper(trim($rawAddress));

$normalizedAddress = str_replace(', USA', '', $normalizedAddress);

// Remove commas
$normalizedAddress = str_replace(',', ' ', $normalizedAddress);

// Collapse whitespace
$normalizedAddress = preg_replace('/\s+/', ' ', $normalizedAddress);

// Remove city/state/zip from lookup string
$normalizedAddress = preg_split(
    '/\bPHOENIX\b|\bAZ\b|\d{5}/',
    $normalizedAddress
)[0];

$normalizedAddress = trim($normalizedAddress);

// #endregion



// #region 🌐 Build ArcGIS Query

$where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" .
    str_replace("'", "''", $normalizedAddress) .
    "%')";

$params = http_build_query([
    'where'           => $where,
    'outFields'       => implode(',', [
        'APN',
        'PHYSICAL_ADDRESS',
        'PHYSICAL_CITY',
        'JURISDICTION',
        'OWNER_NAME'
    ]),
    'returnGeometry'  => 'false',
    'f'               => 'json'
]);

$url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

// #endregion



// #region 🚀 Execute Request

$response = @file_get_contents($url);

if ($response === false) {

    echo json_encode([
        'success' => false,
        'error'   => 'Failed to contact Maricopa ArcGIS service.',
        'url'     => $url
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

$data = json_decode($response, true);

// #endregion



// #region 📦 Normalize Results

$features = $data['features'] ?? [];

$parcelDetails = [];

foreach ($features as $feature) {

    $attr = $feature['attributes'] ?? [];

    if (empty($attr['APN'])) {
        continue;
    }

    $apnRaw = preg_replace(
        '/[^A-Z0-9]/',
        '',
        strtoupper($attr['APN'])
    );

    $parcelDetails[] = [

        'apnRaw'              => $apnRaw,
        'apnDisplay'          => formatAPN($apnRaw),

        'propertyAddress'     => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
        'propertyCity'        => trim($attr['PHYSICAL_CITY'] ?? ''),

        'jurisdiction'        => trim($attr['JURISDICTION'] ?? ''),

        'ownerName'           => trim($attr['OWNER_NAME'] ?? ''),

        'source'              => 'mca_arcgis_mcassessor',
        'confidence'          => 90

    ];
}

// #endregion



// #region ✅ Output

echo json_encode([

    'success'             => true,

    'inputAddress'        => $rawAddress,
    'normalizedAddress'   => $normalizedAddress,

    'arcgisWhere'         => $where,
    'requestUrl'          => $url,

    'candidateCount'      => count($parcelDetails),

    'parcelDetails'       => $parcelDetails,

    'rawResponsePreview'  => substr($response, 0, 1000)

], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// #endregion



// #region 🧰 Helpers

function formatAPN(string $apnRaw): string {

    $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($apnRaw));

    if (strlen($apnRaw) < 8) {
        return $apnRaw;
    }

    return substr($apnRaw, 0, 3) . '-' .
           substr($apnRaw, 3, 2) . '-' .
           substr($apnRaw, 5);
}

// #endregion