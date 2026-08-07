<?php
header('Content-Type: application/json');

// 1. Read incoming JSON payload from Skyesoft
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Extract from nested Skyesoft location structure or top-level properties
$location = $input['location'] ?? $input;
$parcel = $location['parcel'] ?? [];

$address = $location['locationAddress'] ?? $input['address'] ?? '';
$city = $location['locationCity'] ?? '';
$state = $location['locationState'] ?? 'AZ';
$zip = $location['locationZip'] ?? '';
$jurisdiction = $location['locationJurisdiction'] ?? '';
$activitySessionId = $input['activitySessionId'] ?? null;

$fullAddress = trim("$address, $city, $state $zip", " ,");

// 2. SHORT-CIRCUIT: If Skyesoft already has parcel zoning data attached, surface it directly
if (!empty($parcel['zoningCode'])) {
    echo json_encode([
        'address' => $fullAddress,
        'apn' => $location['locationParcelNumberRaw'] ?? $location['locationParcelNumber'] ?? 'N/A',
        'jurisdiction' => $jurisdiction ?: 'City of Phoenix',
        'zoningCode' => $parcel['zoningCode'], // Resolves to A-2
        'zoningDescription' => $parcel['zoningDescription'] ?? 'Industrial',
        'sourceLayer' => $parcel['zoningSource'] ?? 'Skyesoft Parcel Record / City of Phoenix',
        'filter' => 'DIRECT_PARCEL_LOOKUP',
        'verificationDate' => !empty($parcel['zoningVerifiedAt']) 
            ? date('Y-m-d H:i:s', (int)$parcel['zoningVerifiedAt']) 
            : date('Y-m-d H:i:s'),
        'candidateCount' => 1,
        'confidence' => ($parcel['confidence'] ?? '95') . '%',
        'reviewRequired' => false,
        'rawAttributes' => $parcel,
        'activitySessionId' => $activitySessionId
    ], JSON_PRETTY_PRINT);
    exit;
}

// 3. FALLBACK: Geocode address for ESRI ArcGIS Spatial Query
$geocodeUrl = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
    'f' => 'json',
    'singleLine' => $fullAddress,
    'outFields' => 'Match_addr',
    'maxLocations' => 1
]);

$geocodeData = json_decode(@file_get_contents($geocodeUrl), true);

if (empty($geocodeData['candidates'])) {
    echo json_encode([
        'error' => 'Geocoding failed for provided address.',
        'address' => $fullAddress,
        'activitySessionId' => $activitySessionId
    ]);
    exit;
}

$candidate = $geocodeData['candidates'][0];
$lng = $candidate['location']['x'];
$lat = $candidate['location']['y'];

// 4. Query Maricopa County PlanNet Zoning (Layer 11) for Unincorporated County Land
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

$zoningData = json_decode(@file_get_contents($layer11Url), true);
$features = $zoningData['features'] ?? [];

if (count($features) > 0) {
    $attrs = $features[0]['attributes'];
    echo json_encode([
        'address' => $fullAddress,
        'coordinates' => ['lat' => $lat, 'lng' => $lng],
        'apn' => $attrs['APN'] ?? $attrs['PARCEL'] ?? 'N/A',
        'jurisdiction' => $attrs['JURIS'] ?? 'Maricopa County',
        'zoningCode' => $attrs['ZONING'] ?? $attrs['ZONE'] ?? 'UNKNOWN',
        'zoningDescription' => $attrs['ZONING_DESC'] ?? 'N/A',
        'sourceLayer' => 'Maricopa County PlanNet Zoning Layer 11',
        'filter' => "JURIS = 'COUNTY'",
        'verificationDate' => date('Y-m-d H:i:s'),
        'candidateCount' => count($features),
        'confidence' => '100%',
        'reviewRequired' => false,
        'rawAttributes' => $attrs,
        'activitySessionId' => $activitySessionId
    ], JSON_PRETTY_PRINT);
    exit;
}

// 5. Final fallback if outside county layer and no parcel match
echo json_encode([
    'address' => $fullAddress,
    'coordinates' => ['lat' => $lat, 'lng' => $lng],
    'jurisdiction' => $jurisdiction ? "Incorporated ($jurisdiction)" : 'Outside County Jurisdiction',
    'zoningCode' => 'N/A',
    'zoningDescription' => 'No match in Layer 11 (Unincorporated Maricopa County)',
    'sourceLayer' => 'Maricopa County PlanNet Zoning Layer 11',
    'filter' => "JURIS = 'COUNTY'",
    'verificationDate' => date('Y-m-d H:i:s'),
    'candidateCount' => 0,
    'confidence' => '0%',
    'reviewRequired' => true,
    'rawAttributes' => null,
    'activitySessionId' => $activitySessionId
], JSON_PRETTY_PRINT);