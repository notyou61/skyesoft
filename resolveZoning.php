<?php
header('Content-Type: application/json');

// 1. Read incoming JSON payload from Skyesoft
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

// Extract location structure
$location = $input['location'] ?? $input;
$parcel = $location['parcel'] ?? [];

// Hardcoded default address for testing: 3145 N 33rd Ave, Phoenix, AZ 85017
$address = $location['locationAddress'] ?? $input['address'] ?? '3145 N 33rd Ave';
$city = $location['locationCity'] ?? 'Phoenix';
$state = $location['locationState'] ?? 'AZ';
$zip = $location['locationZip'] ?? '85017';
$jurisdiction = $location['locationJurisdiction'] ?? 'City of Phoenix';
$activitySessionId = $input['activitySessionId'] ?? 'test-session-33rd-ave';

$fullAddress = trim("$address, $city, $state $zip", " ,");

// 2. Load jurisdiction-specific zoning definitions (zoning.json)
$zoningRegistryFile = __DIR__ . '/zoning.json';
$zoningRegistry = file_exists($zoningRegistryFile) 
    ? json_decode(file_get_contents($zoningRegistryFile), true) 
    : [];

// Track governance issues for location validation
$issues = [];
$locationValidated = true;

// 3. SHORT-CIRCUIT: Direct Parcel Lookup (Pre-verified in Skyesoft)
if (!empty($parcel['zoningCode'])) {
    echo json_encode([
        'status' => 'success',
        'locationValidated' => true,
        'uiState' => [
            'proposalStatus' => 'valid',
            'canCommit' => true
        ],
        'data' => [
            'address' => $fullAddress,
            'apn' => $location['locationParcelNumberRaw'] ?? $location['locationParcelNumber'] ?? 'N/A',
            'jurisdiction' => $jurisdiction ?: 'City of Phoenix',
            'zoningCode' => $parcel['zoningCode'],
            'zoningDescription' => $parcel['zoningDescription'] ?? 'N/A',
            'sourceLayer' => $parcel['zoningSource'] ?? 'Skyesoft Parcel Record',
            'filter' => 'DIRECT_PARCEL_LOOKUP',
            'verificationDate' => !empty($parcel['zoningVerifiedAt']) 
                ? date('Y-m-d H:i:s', (int)$parcel['zoningVerifiedAt']) 
                : date('Y-m-d H:i:s'),
            'confidence' => ($parcel['confidence'] ?? '95') . '%',
            'reviewRequired' => false,
            'issues' => [],
            'activitySessionId' => $activitySessionId
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 4. Validate Address & Geocode via ESRI ArcGIS Server
if (empty($fullAddress) || strlen($fullAddress) < 5) {
    $locationValidated = false;
    $issues[] = [
        'code' => 'RS-8',
        'severity' => 'blocking',
        'message' => 'Invalid or incomplete address provided.'
    ];
}

$candidate = null;
if ($locationValidated) {
    $geocodeUrl = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
        'f' => 'json',
        'singleLine' => $fullAddress,
        'outFields' => 'Match_addr,Addr_type,StAddr',
        'maxLocations' => 1
    ]);

    $geocodeData = json_decode(@file_get_contents($geocodeUrl), true);
    $candidate = $geocodeData['candidates'][0] ?? null;

    // Fail validation if geocoder found no candidate or couldn't resolve a street address
    if (!$candidate || ($candidate['score'] ?? 0) < 70) {
        $locationValidated = false;
        $issues[] = [
            'code' => 'RS-8',
            'severity' => 'blocking',
            'message' => 'Invalid Location: Unable to geocode address to a known physical structure.'
        ];
    }
}

// Handle INVALID ADDRESS Return Path
if (!$locationValidated) {
    echo json_encode([
        'status' => 'location_invalid',
        'locationValidated' => false,
        'uiState' => [
            'proposalStatus' => 'invalid_location',
            'canCommit' => false
        ],
        'data' => [
            'address' => $fullAddress,
            'jurisdiction' => $jurisdiction ?: 'Unknown',
            'zoningCode' => 'N/A',
            'zoningDescription' => 'Address validation failed. Human review required.',
            'confidence' => '0%',
            'reviewRequired' => true,
            'issues' => $issues,
            'activitySessionId' => $activitySessionId
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 5. Query Spatial Layers if Geocode Succeeded
$lng = $candidate['location']['x'];
$lat = $candidate['location']['y'];

// Check Jurisdiction Mapping in zoning.json or fall back to County PlanNet Layer 11
$jurisKey = strtolower(trim($jurisdiction));
$matchedRule = $zoningRegistry[$jurisKey] ?? null;

if ($matchedRule && isset($matchedRule['endpoint'])) {
    // Custom Endpoint Query from zoning.json
    $spatialUrl = $matchedRule['endpoint'] . '?' . http_build_query([
        'f' => 'json',
        'geometry' => "$lng,$lat",
        'geometryType' => 'esriGeometryPoint',
        'inSR' => '4326',
        'spatialRel' => 'esriSpatialRelWithin',
        'outFields' => '*',
        'returnGeometry' => 'false'
    ]);
    $zoningData = json_decode(@file_get_contents($spatialUrl), true);
    $features = $zoningData['features'] ?? [];
    
    if (!empty($features)) {
        $attrs = $features[0]['attributes'];
        echo json_encode([
            'status' => 'success',
            'locationValidated' => true,
            'uiState' => ['proposalStatus' => 'valid', 'canCommit' => true],
            'data' => [
                'address' => $fullAddress,
                'coordinates' => ['lat' => $lat, 'lng' => $lng],
                'apn' => $attrs[$matchedRule['apnField'] ?? 'APN'] ?? 'N/A',
                'jurisdiction' => $jurisdiction,
                'zoningCode' => $attrs[$matchedRule['codeField'] ?? 'ZONING'] ?? 'UNKNOWN',
                'zoningDescription' => $attrs[$matchedRule['descField'] ?? 'ZONING_DESC'] ?? 'N/A',
                'sourceLayer' => $matchedRule['layerName'] ?? 'Jurisdiction Layer',
                'confidence' => '100%',
                'reviewRequired' => false,
                'issues' => [],
                'activitySessionId' => $activitySessionId
            ]
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

// 6. Regional Fallback: Maricopa County PlanNet (Layer 11)
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

if (!empty($features)) {
    $attrs = $features[0]['attributes'];
    echo json_encode([
        'status' => 'success',
        'locationValidated' => true,
        'uiState' => ['proposalStatus' => 'valid', 'canCommit' => true],
        'data' => [
            'address' => $fullAddress,
            'coordinates' => ['lat' => $lat, 'lng' => $lng],
            'apn' => $attrs['APN'] ?? $attrs['PARCEL'] ?? 'N/A',
            'jurisdiction' => $attrs['JURIS'] ?? 'Maricopa County',
            'zoningCode' => $attrs['ZONING'] ?? $attrs['ZONE'] ?? 'UNKNOWN',
            'zoningDescription' => $attrs['ZONING_DESC'] ?? 'N/A',
            'sourceLayer' => 'Maricopa County PlanNet Zoning Layer 11',
            'confidence' => '100%',
            'reviewRequired' => false,
            'issues' => [],
            'activitySessionId' => $activitySessionId
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 7. Spatial Zoning Not Found (Valid Address, Unmapped Spatial Boundary)
echo json_encode([
    'status' => 'zoning_unmapped',
    'locationValidated' => true,
    'uiState' => [
        'proposalStatus' => 'review_required',
        'canCommit' => false
    ],
    'data' => [
        'address' => $fullAddress,
        'coordinates' => ['lat' => $lat, 'lng' => $lng],
        'jurisdiction' => $jurisdiction ? "Incorporated ($jurisdiction)" : 'Outside Unincorporated Layer',
        'zoningCode' => 'N/A',
        'zoningDescription' => 'Address is valid, but spatial zoning boundary was not found.',
        'confidence' => '50%',
        'reviewRequired' => true,
        'issues' => [
            [
                'code' => 'RS-8_WARNING',
                'severity' => 'warning',
                'message' => 'Location validated, but local jurisdiction zoning layer needs manual selection.'
            ]
        ],
        'activitySessionId' => $activitySessionId
    ]
], JSON_PRETTY_PRINT);