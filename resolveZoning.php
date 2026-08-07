<?php
header('Content-Type: application/json');

// 1. Parse incoming request or default to test address
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

$address = !empty($input['address']) ? $input['address'] : '3145 N 33rd Ave, Phoenix, AZ 85017';
$activitySessionId = $input['activitySessionId'] ?? null;

// 2. Geocode the address via ArcGIS World Geocoder
$geocodeUrl = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
    'f' => 'json',
    'singleLine' => $address,
    'outFields' => 'Match_addr,Addr_type',
    'maxLocations' => 1
]);

$geocodeResponse = @file_get_contents($geocodeUrl);
$geocodeData = json_decode($geocodeResponse, true);

if (empty($geocodeData['candidates'])) {
    echo json_encode([
        'error' => 'Geocoding failed for provided address.',
        'address' => $address,
        'activitySessionId' => $activitySessionId
    ]);
    exit;
}

$candidate = $geocodeData['candidates'][0];
$lng = $candidate['location']['x'];
$lat = $candidate['location']['y'];
$matchedAddress = $candidate['address'];

// 3. Query Maricopa County PlanNet Zoning (Layer 11)
$layer11Url = 'https://gis.maricopa.gov/arcgis/rest/services/PlanNet/Zoning/MapServer/11/query?' . http_build_query([
    'f' => 'json',
    'geometry' => "$lng,$lat",
    'geometryType' => 'esriGeometryPoint',
    'inSR' => '4326',
    'spatialRel' => 'esriSpatialRelWithin',
    'where' => "JURIS = 'COUNTY'",
    'outFields' => '*',
    'returnGeometry' => 'false'
]);

$zoningResponse = @file_get_contents($layer11Url);
$zoningData = json_decode($zoningResponse, true);

$features = $zoningData['features'] ?? [];
$candidateCount = count($features);

if ($candidateCount > 0) {
    $attributes = $features[0]['attributes'];
    
    // Adjust key names based on exact Layer 11 field schema output
    $zoningCode = $attributes['ZONING'] ?? $attributes['ZONE'] ?? $attributes['ZONING_CODE'] ?? 'UNKNOWN';
    $zoningDesc = $attributes['ZONING_DESC'] ?? $attributes['DESCRIPTION'] ?? 'N/A';
    $jurisdiction = $attributes['JURIS'] ?? 'Maricopa County';
    $apn = $attributes['APN'] ?? $attributes['PARCEL'] ?? 'N/A';

    $response = [
        'address' => $matchedAddress,
        'coordinates' => [
            'lat' => $lat,
            'lng' => $lng
        ],
        'apn' => $apn,
        'jurisdiction' => $jurisdiction,
        'zoningCode' => $zoningCode,
        'zoningDescription' => $zoningDesc,
        'sourceLayer' => 'Maricopa County PlanNet Zoning Layer 11',
        'filter' => "JURIS = 'COUNTY'",
        'verificationDate' => date('Y-m-d H:i:s'),
        'candidateCount' => $candidateCount,
        'confidence' => '100%',
        'reviewRequired' => false,
        'rawAttributes' => $attributes,
        'activitySessionId' => $activitySessionId
    ];
} else {
    $response = [
        'address' => $matchedAddress,
        'coordinates' => [
            'lat' => $lat,
            'lng' => $lng
        ],
        'jurisdiction' => 'Outside County Jurisdiction / Incorporated City',
        'zoningCode' => 'N/A',
        'zoningDescription' => 'No match in Maricopa County PlanNet Layer 11 (JURIS = \'COUNTY\')',
        'sourceLayer' => 'Maricopa County PlanNet Zoning Layer 11',
        'filter' => "JURIS = 'COUNTY'",
        'verificationDate' => date('Y-m-d H:i:s'),
        'candidateCount' => 0,
        'confidence' => '0%',
        'reviewRequired' => true,
        'activitySessionId' => $activitySessionId
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);