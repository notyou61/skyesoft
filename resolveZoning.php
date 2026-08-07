<?php
header('Content-Type: application/json');

// 1. Read input payload
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

// Extract location structure
$location = $input['location'] ?? $input;
$parcel = $location['parcel'] ?? [];

// Clean extracted location inputs
$address = $location['locationAddress'] ?? '3145 N 33rd Ave';
$city = $location['locationCity'] ?? 'Phoenix';
$state = $location['locationState'] ?? 'AZ';
$zip = $location['locationZip'] ?? '85017';
$jurisdiction = $location['locationJurisdiction'] ?? 'Phoenix';
$apn = $location['locationParcelNumberRaw'] ?? $location['locationParcelNumber'] ?? null;
$activitySessionId = $input['activitySessionId'] ?? 'location-check-session';

$fullAddress = trim("$address, $city, $state $zip", " ,");

// 2. Load zoning registry definitions (zoning.json containing your schema configs)
$zoningRegistryFile = __DIR__ . '/zoning.json';
$zoningRegistry = file_exists($zoningRegistryFile) 
    ? json_decode(file_get_contents($zoningRegistryFile), true) 
    : [];

$issues = [];
$locationValidated = true;

// 3. SHORT-CIRCUIT: Direct Parcel Lookup
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
            'apn' => $apn ?? 'N/A',
            'jurisdiction' => $jurisdiction,
            'zoningCode' => $parcel['zoningCode'],
            'zoningDescription' => $parcel['zoningDescription'] ?? 'N/A',
            'sourceLayer' => $parcel['zoningSource'] ?? 'Skyesoft Parcel Record',
            'filter' => 'DIRECT_PARCEL_LOOKUP',
            'overlays' => [
                'regulatoryPlan' => $parcel['regulatoryPlan'] ?? null,
                'historicDesignation' => $parcel['historicDesignation'] ?? null,
                'comprehensiveSignPlan' => $parcel['comprehensiveSignPlan'] ?? null
            ],
            'confidence' => ($parcel['confidence'] ?? '95') . '%',
            'reviewRequired' => false,
            'issues' => [],
            'activitySessionId' => $activitySessionId
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 4. Geocode Address via ESRI ArcGIS
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

    $geocodeResponse = @file_get_contents($geocodeUrl);
    $geocodeData = $geocodeResponse ? json_decode($geocodeResponse, true) : null;
    $candidate = $geocodeData['candidates'][0] ?? null;

    if (!$candidate || ($candidate['score'] ?? 0) < 70) {
        $locationValidated = false;
        $issues[] = [
            'code' => 'RS-8',
            'severity' => 'blocking',
            'message' => 'Invalid Location: Unable to geocode address to a physical structure.'
        ];
    }
}

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
            'overlays' => [
                'regulatoryPlan' => null,
                'historicDesignation' => null,
                'comprehensiveSignPlan' => null
            ],
            'confidence' => '0%',
            'reviewRequired' => true,
            'issues' => $issues,
            'activitySessionId' => $activitySessionId
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 5. Query Configured Jurisdiction Layer
$lng = $candidate['location']['x'];
$lat = $candidate['location']['y'];

$jurisKey = strtolower(trim($jurisdiction));
$matchedConfig = $zoningRegistry[$jurisKey] ?? null;

$zoningCode = 'UNKNOWN';
$zoningDesc = 'N/A';
$sourceLayer = 'Unmapped Spatial Layer';
$extractedMetaData = [];
$overlays = [
    'regulatoryPlan' => null,
    'historicDesignation' => null,
    'comprehensiveSignPlan' => null
];

if ($matchedConfig && isset($matchedConfig['service']['serviceUrl'])) {
    $svc = $matchedConfig['service'];
    $qry = $matchedConfig['query'];
    $fm = $matchedConfig['fieldMapping'] ?? [];

    $endpoint = rtrim($svc['serviceUrl'], '/') . '/' . ($svc['layerId'] ?? 0) . '/query';

    $spatialUrl = $endpoint . '?' . http_build_query([
        'f' => $qry['responseFormat'] ?? 'json',
        'geometry' => "$lng,$lat",
        'geometryType' => $qry['geometryType'] ?? 'esriGeometryPoint',
        'inSR' => $qry['inputSpatialReference'] ?? 4326,
        'spatialRel' => $qry['spatialRelationship'] ?? 'esriSpatialRelIntersects',
        'where' => $qry['where'] ?? '1=1',
        'outFields' => implode(',', $qry['outFields'] ?? ['*']),
        'returnGeometry' => $qry['returnGeometry'] ? 'true' : 'false'
    ]);

    $spatialResponse = @file_get_contents($spatialUrl);
    $zoningData = $spatialResponse ? json_decode($spatialResponse, true) : null;
    $features = $zoningData['features'] ?? [];

    if (!empty($features)) {
        $attrs = $features[0]['attributes'];
        $sourceLayer = $svc['provider'] . ' (' . ($svc['layerName'] ?? 'Zoning') . ')';

        // Dynamic field Mapping Resolution using priority array
        $zoningCode = resolveMappedField($attrs, $fm['zoningCode'] ?? ['ZONING']);
        $zoningDesc = resolveMappedField($attrs, $fm['zoningDescription'] ?? ['GEN_ZONE']);

        // Extra metadata captured directly from feature attributes
        $extractedMetaData['caseNumber'] = resolveMappedField($attrs, $fm['caseNumber'] ?? ['REDEFINE1']);
        $extractedMetaData['ordinanceNumber'] = resolveMappedField($attrs, $fm['ordinanceNumber'] ?? ['ORD_NUM']);

        // Native Historic Flag
        $historicVal = resolveMappedField($attrs, $fm['historic'] ?? ['HISTORIC']);
        if (!empty($historicVal) && strtoupper($historicVal) !== 'N' && strtoupper($historicVal) !== 'NO') {
            $overlays['historicDesignation'] = [
                'isHistoric' => true,
                'designationType' => 'City Historic Overlay (' . $historicVal . ')'
            ];
        }

        // Native Transit Oriented Development (TOD) Flag
        $todVal = resolveMappedField($attrs, $fm['transitOrientedDevelopment'] ?? ['TOD']);
        if (!empty($todVal) && strtoupper($todVal) !== 'N' && strtoupper($todVal) !== 'NO') {
            $overlays['regulatoryPlan'] = [
                'name' => 'Transit Oriented Development District (' . $todVal . ')',
                'type' => 'TOD Overlay'
            ];
        }
    }
}

// 6. Regional Fallback: Maricopa County PlanNet (Layer 11)
if ($zoningCode === 'UNKNOWN') {
    $layer11Url = 'https://gis.maricopa.gov/arcgis/rest/services/PlanNet/Zoning/MapServer/11/query?' . http_build_query([
        'f' => 'json',
        'geometry' => "$lng,$lat",
        'geometryType' => 'esriGeometryPoint',
        'inSR' => '4326',
        'spatialRel' => 'esriSpatialRelWithin',
        'where' => '1=1',
        'outFields' => '*',
        'returnGeometry' => 'false'
    ]);

    $layer11Response = @file_get_contents($layer11Url);
    $zoningData = $layer11Response ? json_decode($layer11Response, true) : null;
    $features = $zoningData['features'] ?? [];

    if (!empty($features)) {
        $attrs = $features[0]['attributes'];
        $zoningCode = $attrs['ZONING'] ?? $attrs['ZONE'] ?? 'UNKNOWN';
        $zoningDesc = $attrs['ZONING_DESC'] ?? 'N/A';
        $sourceLayer = 'Maricopa County PlanNet Zoning Layer 11';
    }
}

// 7. Output Result
$hasZoningMatch = ($zoningCode !== 'UNKNOWN' && $zoningCode !== 'N/A');

echo json_encode([
    'status' => $hasZoningMatch ? 'success' : 'zoning_unmapped',
    'locationValidated' => true,
    'uiState' => [
        'proposalStatus' => $hasZoningMatch ? 'valid' : 'review_required',
        'canCommit' => $hasZoningMatch
    ],
    'data' => [
        'address' => $fullAddress,
        'coordinates' => ['lat' => $lat, 'lng' => $lng],
        'apn' => $apn ?? 'N/A',
        'jurisdiction' => $jurisdiction,
        'zoningCode' => $zoningCode,
        'zoningDescription' => $zoningDesc,
        'sourceLayer' => $sourceLayer,
        'meta' => $extractedMetaData,
        'overlays' => $overlays,
        'confidence' => $hasZoningMatch ? ($matchedConfig['validation']['successfulResultConfidence'] ?? 95) . '%' : '50%',
        'reviewRequired' => !$hasZoningMatch,
        'issues' => $hasZoningMatch ? [] : [[
            'code' => 'RS-8_WARNING',
            'severity' => 'warning',
            'message' => 'Location validated, but local jurisdiction zoning layer needs manual selection.'
        ]],
        'activitySessionId' => $activitySessionId
    ]
], JSON_PRETTY_PRINT);

/**
 * Resolves priority field arrays against feature attributes
 */
function resolveMappedField(array $attributes, array $fieldCandidates) {
    foreach ($fieldCandidates as $field) {
        if (!empty($attributes[$field])) {
            return trim((string)$attributes[$field]);
        }
    }
    return null;
}