<?php
header('Content-Type: application/json');

// 1. Read incoming JSON payload
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

// Extract location structure
$location = $input['location'] ?? $input;
$parcel = $location['parcel'] ?? [];

// Standardized Core Address & Location Identifier Extraction
$address = $location['locationAddress'] ?? '3145 N 33rd Ave';
$city = $location['locationCity'] ?? 'Phoenix';
$state = $location['locationState'] ?? 'AZ';
$zip = $location['locationZip'] ?? '85017';

$locationPlaceId = $location['locationPlaceId'] ?? null;
$locationParcelNumber = $location['locationParcelNumberRaw'] ?? $location['locationParcelNumber'] ?? null;
$locationJurisdiction = $location['locationJurisdiction'] ?? 'Phoenix';
$locationCounty = $location['locationCounty'] ?? 'Maricopa';

$activitySessionId = $input['activitySessionId'] ?? 'location-check-session';
$fullAddress = trim("$address, $city, $state $zip", " ,");

// 2. Load zoning registry rule configuration
$zoningRegistryFile = __DIR__ . '/zoning.json';
$zoningRaw = file_exists($zoningRegistryFile) 
    ? json_decode(file_get_contents($zoningRegistryFile), true) 
    : [];

// Match jurisdiction against single-schema or keyed dictionary formats
$jurisKey = strtolower(trim($locationJurisdiction));
$matchedConfig = null;

if (isset($zoningRaw['jurisdiction']['slug'])) {
    if (strtolower($zoningRaw['jurisdiction']['slug']) === $jurisKey) {
        $matchedConfig = $zoningRaw;
    }
} else {
    $matchedConfig = $zoningRaw[$jurisKey] ?? null;
}

$issues = [];
$locationValidated = true;

// 3. SHORT-CIRCUIT: Direct Parcel Verification (Pre-verified Parcel Data)
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
            'locationPlaceId' => $locationPlaceId,
            'locationParcelNumber' => $locationParcelNumber ?? 'N/A',
            'locationJurisdiction' => $locationJurisdiction,
            'locationCounty' => $locationCounty,
            'zoningCode' => $parcel['zoningCode'],
            'zoningDescription' => $parcel['zoningDescription'] ?? 'N/A',
            'sourceLayer' => $parcel['zoningSource'] ?? 'Skyesoft Parcel Record',
            'filter' => 'DIRECT_PARCEL_LOOKUP',
            'parcel' => [
                'ownerName' => $parcel['ownerName'] ?? null,
                'subdivision' => $parcel['subdivision'] ?? null,
                'lotSize' => $parcel['lotSize'] ?? null,
                'yearBuilt' => $parcel['yearBuilt'] ?? null,
                'zoningCode' => $parcel['zoningCode'],
                'zoningDescription' => $parcel['zoningDescription'] ?? 'N/A',
                'zoningSource' => $parcel['zoningSource'] ?? null,
                'zoningVerifiedAt' => $parcel['zoningVerifiedAt'] ?? null,
                'source' => $parcel['source'] ?? 'maricopa_assessor',
                'confidence' => ($parcel['confidence'] ?? '95') . '%'
            ],
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
            'locationPlaceId' => $locationPlaceId,
            'locationParcelNumber' => $locationParcelNumber ?? 'N/A',
            'locationJurisdiction' => $locationJurisdiction ?: 'Unknown',
            'locationCounty' => $locationCounty,
            'zoningCode' => 'N/A',
            'zoningDescription' => 'Address validation failed. Human review required.',
            'parcel' => null,
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

// 5. Query Configured Spatial Layer
$lng = $candidate['location']['x'];
$lat = $candidate['location']['y'];

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

    $geometryJson = json_encode([
        'x' => $lng,
        'y' => $lat,
        'spatialReference' => ['wkid' => $qry['inputSpatialReference'] ?? 4326]
    ]);

    $spatialUrl = $endpoint . '?' . http_build_query([
        'f' => $qry['responseFormat'] ?? 'json',
        'geometry' => $geometryJson,
        'geometryType' => $qry['geometryType'] ?? 'esriGeometryPoint',
        'inSR' => $qry['inputSpatialReference'] ?? 4326,
        'spatialRel' => $qry['spatialRelationship'] ?? 'esriSpatialRelIntersects',
        'where' => $qry['where'] ?? '1=1',
        'outFields' => implode(',', $qry['outFields'] ?? ['*']),
        'returnGeometry' => !empty($qry['returnGeometry']) ? 'true' : 'false'
    ]);

    $spatialResponse = @file_get_contents($spatialUrl);
    $zoningData = $spatialResponse ? json_decode($spatialResponse, true) : null;
    $features = $zoningData['features'] ?? [];

    if (!empty($features)) {
        $attrs = $features[0]['attributes'];
        $sourceLayer = $svc['provider'] . ' (' . ($svc['layerName'] ?? 'Zoning') . ')';

        $zoningCode = resolveMappedField($attrs, $fm['zoningCode'] ?? ['LABEL1', 'ZONING']);
        $zoningDesc = resolveMappedField($attrs, $fm['zoningDescription'] ?? ['GEN_ZONE']);

        $extractedMetaData['caseNumber'] = resolveMappedField($attrs, $fm['caseNumber'] ?? ['REDEFINE1']);
        $extractedMetaData['ordinanceNumber'] = resolveMappedField($attrs, $fm['ordinanceNumber'] ?? ['ORD_NUM']);

        // Historic Overlay Flag
        $historicVal = resolveMappedField($attrs, $fm['historic'] ?? ['HISTORIC']);
        if (!empty($historicVal) && !in_array(strtoupper($historicVal), ['N', 'NO', 'NONE', 'FALSE'])) {
            $overlays['historicDesignation'] = [
                'isHistoric' => true,
                'designationType' => 'Historic District (' . $historicVal . ')'
            ];
        }

        // TOD Overlay Flag
        $todVal = resolveMappedField($attrs, $fm['transitOrientedDevelopment'] ?? ['TOD']);
        if (!empty($todVal) && !in_array(strtoupper($todVal), ['N', 'NO', 'NONE', 'FALSE'])) {
            $overlays['regulatoryPlan'] = [
                'name' => 'Transit Oriented Development (' . $todVal . ')',
                'type' => 'TOD District'
            ];
        }
    }
}

// 6. Regional Fallback: Maricopa County PlanNet (Layer 11)
if ($zoningCode === 'UNKNOWN') {
    $geometryJson = json_encode([
        'x' => $lng,
        'y' => $lat,
        'spatialReference' => ['wkid' => 4326]
    ]);

    $layer11Url = 'https://gis.maricopa.gov/arcgis/rest/services/PlanNet/Zoning/MapServer/11/query?' . http_build_query([
        'f' => 'json',
        'geometry' => $geometryJson,
        'geometryType' => 'esriGeometryPoint',
        'inSR' => 4326,
        'spatialRel' => 'esriSpatialRelIntersects',
        'where' => '1=1',
        'outFields' => '*',
        'returnGeometry' => 'false'
    ]);

    $layer11Response = @file_get_contents($layer11Url);
    $zoningData = $layer11Response ? json_decode($layer11Response, true) : null;
    $features = $zoningData['features'] ?? [];

    if (!empty($features)) {
        $attrs = $features[0]['attributes'];
        $zoningCode = $attrs['ZONING'] ?? $attrs['ZONE'] ?? $attrs['LABEL1'] ?? 'UNKNOWN';
        $zoningDesc = $attrs['ZONING_DESC'] ?? $attrs['GEN_ZONE'] ?? 'N/A';
        $sourceLayer = 'Maricopa County PlanNet Zoning Layer 11';
    }
}

// 7. Output Final Standardized Response
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
        'locationPlaceId' => $locationPlaceId,
        'locationParcelNumber' => $locationParcelNumber ?? 'N/A',
        'locationJurisdiction' => $locationJurisdiction,
        'locationCounty' => $locationCounty,
        'zoningCode' => $zoningCode,
        'zoningDescription' => $zoningDesc,
        'sourceLayer' => $sourceLayer,
        'parcel' => !empty($parcel) ? [
            'ownerName' => $parcel['ownerName'] ?? null,
            'subdivision' => $parcel['subdivision'] ?? null,
            'lotSize' => $parcel['lotSize'] ?? null,
            'yearBuilt' => $parcel['yearBuilt'] ?? null,
            'zoningCode' => $zoningCode,
            'zoningDescription' => $zoningDesc,
            'zoningSource' => $parcel['zoningSource'] ?? $sourceLayer,
            'zoningVerifiedAt' => $parcel['zoningVerifiedAt'] ?? (string)time(),
            'source' => $parcel['source'] ?? 'spatial_lookup',
            'confidence' => $hasZoningMatch ? ($matchedConfig['validation']['successfulResultConfidence'] ?? 95) . '%' : '50%'
        ] : null,
        'meta' => (object)$extractedMetaData,
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
        if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
            $val = trim((string)$attributes[$field]);
            if ($val !== '') {
                return $val;
            }
        }
    }
    return null;
}