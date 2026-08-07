<?php

declare(strict_types=1);

header('Content-Type: application/json');

$executionTime = time();
$isoTimestamp  = date('c', $executionTime);

// 1. Read input payload
$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true) ?? [];

// Standardize container extraction across flat, nested, or data-wrapped structures
$dataObj  = $input['data'] ?? [];
$location = $input['location'] ?? $dataObj['location'] ?? $input;
$parcel   = $location['parcel'] ?? $dataObj['location']['parcelDetails'][0] ?? $dataObj['parcel'] ?? [];

$googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: null;

// Extract fields safely
$address      = $location['locationAddress'] ?? $dataObj['locationAddress'] ?? $input['locationAddress'] ?? null;
$cityStateZip = $location['locationCityStateZip'] ?? $dataObj['locationCityStateZip'] ?? $input['locationCityStateZip'] ?? null;

$locationPlaceId      = $location['locationPlaceId'] ?? $dataObj['locationPlaceId'] ?? $input['locationPlaceId'] ?? null;
$locationParcelNumber = $location['locationParcelNumberRaw'] ?? $location['locationParcelNumber'] ?? $dataObj['locationParcelNumber'] ?? $parcel['apnDisplay'] ?? $parcel['apnRaw'] ?? null;
$locationJurisdiction = $location['locationJurisdiction'] ?? $dataObj['locationJurisdiction'] ?? $input['locationJurisdiction'] ?? null;
$locationCounty       = $location['locationCounty'] ?? $dataObj['locationCounty'] ?? $input['locationCounty'] ?? null;

$activitySessionId = $input['activitySessionId'] ?? $dataObj['activitySessionId'] ?? 'location-check-session';

$addressParts = array_filter([$address, $cityStateZip]);
$fullAddress  = !empty($addressParts) ? implode(', ', $addressParts) : null;

// 2. Load zoning registry rule configuration
$zoningRegistryFile = __DIR__ . '/zoning.json';
$zoningConfig       = file_exists($zoningRegistryFile) 
    ? json_decode((string)file_get_contents($zoningRegistryFile), true) 
    : [];

$jurisKey      = strtolower(trim((string)$locationJurisdiction));
$matchedConfig = null;

if ($jurisKey !== '') {
    if (isset($zoningConfig['jurisdiction'])) {
        $configSlug = strtolower((string)($zoningConfig['jurisdiction']['slug'] ?? $zoningConfig['jurisdiction']['label'] ?? ''));
        if ($configSlug === $jurisKey) {
            $matchedConfig = $zoningConfig;
        }
    } elseif (isset($zoningConfig[$jurisKey])) {
        $matchedConfig = $zoningConfig[$jurisKey];
    } else {
        foreach ($zoningConfig as $cfg) {
            if (is_array($cfg) && strtolower((string)($cfg['jurisdiction']['slug'] ?? $cfg['jurisdiction']['label'] ?? '')) === $jurisKey) {
                $matchedConfig = $cfg;
                break;
            }
        }
    }
}

if (!$matchedConfig && isset($zoningConfig['service'])) {
    $matchedConfig = $zoningConfig;
}

$issues            = [];
$locationValidated = true;

// 3. SHORT-CIRCUIT: Direct Parcel Verification
if (!empty($parcel['zoningCode']) && strtoupper((string)$parcel['zoningCode']) !== 'UNKNOWN') {
    echo json_encode([
        'status'            => 'success',
        'timestamp'         => $executionTime,
        'isoTimestamp'      => $isoTimestamp,
        'locationValidated' => true,
        'uiState'           => [
            'proposalStatus' => 'valid',
            'canCommit'      => true
        ],
        'data' => [
            'address'              => $fullAddress,
            'locationPlaceId'      => $locationPlaceId,
            'locationParcelNumber' => $locationParcelNumber,
            'locationJurisdiction' => $locationJurisdiction,
            'locationCounty'       => $locationCounty,
            'zoningCode'           => $parcel['zoningCode'],
            'zoningDescription'    => $parcel['zoningDescription'] ?? 'N/A',
            'sourceLayer'          => $parcel['zoningSource'] ?? 'Skyesoft Parcel Record',
            'filter'               => 'DIRECT_PARCEL_LOOKUP',
            'parcel'               => [
                'ownerName'         => $parcel['ownerName'] ?? $parcel['owner'] ?? null,
                'subdivision'       => $parcel['subdivision'] ?? null,
                'lotSize'           => $parcel['lotSize'] ?? null,
                'yearBuilt'         => $parcel['yearBuilt'] ?? null,
                'zoningCode'        => $parcel['zoningCode'],
                'zoningDescription' => $parcel['zoningDescription'] ?? 'N/A',
                'zoningSource'      => $parcel['zoningSource'] ?? null,
                'zoningVerifiedAt'  => (string)$executionTime,
                'source'            => $parcel['source'] ?? 'maricopa_assessor',
                'confidence'        => ($parcel['confidence'] ?? '95') . '%'
            ],
            'overlays' => [
                'regulatoryPlan'        => $parcel['regulatoryPlan'] ?? null,
                'historicDesignation'   => $parcel['historicDesignation'] ?? null,
                'comprehensiveSignPlan' => $parcel['comprehensiveSignPlan'] ?? null
            ],
            'confidence'        => ($parcel['confidence'] ?? '95') . '%',
            'reviewRequired'    => false,
            'issues'            => [],
            'activitySessionId' => $activitySessionId
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 4. RESOLVE COORDINATES
$rawLat = $location['locationLatitude'] ?? $dataObj['locationLatitude'] ?? $dataObj['coordinates']['lat'] ?? $input['locationLatitude'] ?? null;
$rawLng = $location['locationLongitude'] ?? $dataObj['locationLongitude'] ?? $dataObj['coordinates']['lng'] ?? $input['locationLongitude'] ?? null;

$lat = ($rawLat !== null && is_numeric($rawLat)) ? (float)$rawLat : null;
$lng = ($rawLng !== null && is_numeric($rawLng)) ? (float)$rawLng : null;

$coordinateSource = ($lat !== null && $lng !== null) ? 'input_payload' : 'geocoder';

if (($lat === null || $lng === null) && $locationPlaceId && $googleMapsApiKey) {
    $placeDetailsUrl = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $locationPlaceId,
        'fields'   => 'geometry',
        'key'      => $googleMapsApiKey
    ]);

    $placeRes = httpGetJson($placeDetailsUrl);
    if (($placeRes['status'] ?? '') === 'OK' && isset($placeRes['result']['geometry']['location'])) {
        $lat              = (float)$placeRes['result']['geometry']['location']['lat'];
        $lng              = (float)$placeRes['result']['geometry']['location']['lng'];
        $coordinateSource = 'google_place_details';
    }
}

if (($lat === null || $lng === null) && $fullAddress !== null) {
    $geocodeUrl = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
        'f'            => 'json',
        'singleLine'   => $fullAddress,
        'outFields'    => 'Match_addr,Addr_type,StAddr',
        'maxLocations' => 1
    ]);

    $geocodeData = httpGetJson($geocodeUrl);
    $candidate   = $geocodeData['candidates'][0] ?? null;

    if ($candidate && ($candidate['score'] ?? 0) >= 70) {
        $lng              = (float)$candidate['location']['x'];
        $lat              = (float)$candidate['location']['y'];
        $coordinateSource = 'esri_geocoder';
    } else {
        $locationValidated = false;
        $issues[] = [
            'code'     => 'RS-8',
            'severity' => 'blocking',
            'message'  => 'Invalid Location: Unable to geocode address or resolve Place ID.'
        ];
    }
} elseif ($lat === null || $lng === null) {
    $locationValidated = false;
    $issues[] = [
        'code'     => 'RS-8',
        'severity' => 'blocking',
        'message'  => 'Invalid Location: Missing valid coordinates or printable address.'
    ];
}

if (!$locationValidated) {
    echo json_encode([
        'status'            => 'location_invalid',
        'timestamp'         => $executionTime,
        'isoTimestamp'      => $isoTimestamp,
        'locationValidated' => false,
        'uiState'           => [
            'proposalStatus' => 'invalid_location',
            'canCommit'      => false
        ],
        'data' => [
            'address'              => $fullAddress,
            'locationPlaceId'      => $locationPlaceId,
            'locationParcelNumber' => $locationParcelNumber,
            'locationJurisdiction' => $locationJurisdiction,
            'locationCounty'       => $locationCounty,
            'zoningCode'           => 'N/A',
            'zoningDescription'    => 'Address validation failed. Human review required.',
            'parcel'               => null,
            'overlays'             => [
                'regulatoryPlan'        => null,
                'historicDesignation'   => null,
                'comprehensiveSignPlan' => null
            ],
            'confidence'        => '0%',
            'reviewRequired'    => true,
            'issues'            => $issues,
            'activitySessionId' => $activitySessionId
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 5. Query Jurisdiction Map Server
$zoningCode        = 'UNKNOWN';
$zoningDesc        = 'N/A';
$sourceLayer       = 'Unmapped Spatial Layer';
$extractedMetaData = [];
$overlays          = [
    'regulatoryPlan'        => null,
    'historicDesignation'   => null,
    'comprehensiveSignPlan' => null
];

if ($matchedConfig && isset($matchedConfig['service']['serviceUrl'])) {
    $svc  = $matchedConfig['service'];
    $qry  = $matchedConfig['query'] ?? [];
    $fm   = $matchedConfig['fieldMapping'] ?? [];
    $norm = $matchedConfig['normalization'] ?? [];

    $endpoint = rtrim((string)$svc['serviceUrl'], '/') . '/' . ($svc['layerId'] ?? 0) . '/query';

    // Strategy A: Point Query
    $queryParams = [
        'f'              => $qry['responseFormat'] ?? 'json',
        'geometry'       => json_encode(['x' => $lng, 'y' => $lat, 'spatialReference' => ['wkid' => 4326]]),
        'geometryType'   => 'esriGeometryPoint',
        'inSR'           => '4326',
        'spatialRel'     => $qry['spatialRelationship'] ?? 'esriSpatialRelIntersects',
        'where'          => $qry['where'] ?? '1=1',
        'outFields'      => is_array($qry['outFields'] ?? null) ? implode(',', $qry['outFields']) : ($qry['outFields'] ?? '*'),
        'returnGeometry' => 'false'
    ];

    $spatialUrl = $endpoint . '?' . http_build_query($queryParams);
    $zoningData = httpGetJson($spatialUrl, (int)($qry['timeoutSeconds'] ?? 8));
    $features   = $zoningData['features'] ?? [];

    // Strategy B: Envelope Bounding Box Fallback (~15 meters)
    if (empty($features)) {
        $delta = 0.00015;
        $queryParams['geometry']     = json_encode([
            'xmin' => $lng - $delta,
            'ymin' => $lat - $delta,
            'xmax' => $lng + $delta,
            'ymax' => $lat + $delta,
            'spatialReference' => ['wkid' => 4326]
        ]);
        $queryParams['geometryType'] = 'esriGeometryEnvelope';

        $fallbackSpatialUrl = $endpoint . '?' . http_build_query($queryParams);
        $fallbackData       = httpGetJson($fallbackSpatialUrl, (int)($qry['timeoutSeconds'] ?? 8));
        $features           = $fallbackData['features'] ?? [];
    }

    if (!empty($features)) {
        $attrs       = $features[0]['attributes'] ?? [];
        $sourceLayer = ($svc['provider'] ?? 'City GIS') . ' (' . ($svc['layerName'] ?? 'Zoning') . ')';

        $zoningCodeRaw = resolveMappedField($attrs, $fm['zoningCode'] ?? ['LABEL1', 'ZONING'], $norm);
        if ($zoningCodeRaw !== null) {
            $zoningCode = $zoningCodeRaw;
        }

        $zoningDescRaw = resolveMappedField($attrs, $fm['zoningDescription'] ?? ['GEN_ZONE'], $norm);
        if ($zoningDescRaw !== null) {
            $zoningDesc = $zoningDescRaw;
        }

        $extractedMetaData['caseNumber']      = resolveMappedField($attrs, $fm['caseNumber'] ?? ['REDEFINE1'], $norm);
        $extractedMetaData['ordinanceNumber'] = resolveMappedField($attrs, $fm['ordinanceNumber'] ?? ['ORD_NUM'], $norm);

        $historicVal = resolveMappedField($attrs, $fm['historic'] ?? ['HISTORIC'], $norm);
        if (!empty($historicVal) && !in_array(strtoupper($historicVal), ['N', 'NO', 'NONE', 'FALSE'], true)) {
            $overlays['historicDesignation'] = [
                'isHistoric'      => true,
                'designationType' => 'Historic Overlay (' . $historicVal . ')'
            ];
        }

        $todVal = resolveMappedField($attrs, $fm['transitOrientedDevelopment'] ?? ['TOD'], $norm);
        if (!empty($todVal) && !in_array(strtoupper($todVal), ['N', 'NO', 'NONE', 'FALSE'], true)) {
            $overlays['regulatoryPlan'] = [
                'name' => 'Transit Oriented Development (' . $todVal . ')',
                'type' => 'TOD Overlay'
            ];
        }
    }
}

// 6. Regional Fallback: Maricopa County PlanNet (Layer 11)
if ($zoningCode === 'UNKNOWN' && $lat !== null && $lng !== null) {
    $layer11Url = 'https://gis.maricopa.gov/arcgis/rest/services/PlanNet/Zoning/MapServer/11/query?' . http_build_query([
        'f'              => 'json',
        'geometry'       => json_encode(['x' => $lng, 'y' => $lat, 'spatialReference' => ['wkid' => 4326]]),
        'geometryType'   => 'esriGeometryPoint',
        'inSR'           => '4326',
        'spatialRel'     => 'esriSpatialRelIntersects',
        'where'          => '1=1',
        'outFields'      => '*',
        'returnGeometry' => 'false'
    ]);

    $zoningData = httpGetJson($layer11Url, 8);
    $features   = $zoningData['features'] ?? [];

    if (!empty($features)) {
        $attrs       = $features[0]['attributes'] ?? [];
        $zoningCode  = $attrs['LABEL1'] ?? $attrs['ZONING'] ?? $attrs['ZONE'] ?? 'UNKNOWN';
        $zoningDesc  = $attrs['GEN_ZONE'] ?? $attrs['ZONING_DESC'] ?? 'N/A';
        $sourceLayer = 'Maricopa County PlanNet Zoning Layer 11';
    }
}

// 7. Standardized Output
$hasZoningMatch = ($zoningCode !== 'UNKNOWN' && $zoningCode !== 'N/A');

echo json_encode([
    'status'            => $hasZoningMatch ? 'success' : 'zoning_unmapped',
    'timestamp'         => $executionTime,
    'isoTimestamp'      => $isoTimestamp,
    'locationValidated' => true,
    'uiState'           => [
        'proposalStatus' => $hasZoningMatch ? 'valid' : 'review_required',
        'canCommit'      => $hasZoningMatch
    ],
    'data' => [
        'address'     => $fullAddress,
        'coordinates' => [
            'lat'    => $lat,
            'lng'    => $lng,
            'source' => $coordinateSource
        ],
        'locationPlaceId'      => $locationPlaceId,
        'locationParcelNumber' => $locationParcelNumber,
        'locationJurisdiction' => $locationJurisdiction,
        'locationCounty'       => $locationCounty,
        'zoningCode'           => $zoningCode,
        'zoningDescription'    => $zoningDesc,
        'sourceLayer'          => $sourceLayer,
        'parcel'               => [
            'ownerName'         => $parcel['ownerName'] ?? $parcel['owner'] ?? null,
            'subdivision'       => $parcel['subdivision'] ?? null,
            'lotSize'           => $parcel['lotSize'] ?? null,
            'yearBuilt'         => $parcel['yearBuilt'] ?? null,
            'zoningCode'        => $zoningCode,
            'zoningDescription' => $zoningDesc,
            'zoningSource'      => $sourceLayer,
            'zoningVerifiedAt'  => (string)$executionTime,
            'source'            => $parcel['source'] ?? 'maricopa_assessor',
            'confidence'        => $hasZoningMatch ? ($matchedConfig['validation']['successfulResultConfidence'] ?? 95) . '%' : '50%'
        ],
        'meta'           => (object)$extractedMetaData,
        'overlays'       => $overlays,
        'confidence'     => $hasZoningMatch ? ($matchedConfig['validation']['successfulResultConfidence'] ?? 95) . '%' : '50%',
        'reviewRequired' => !$hasZoningMatch,
        'issues'         => $hasZoningMatch ? [] : [[
            'code'     => 'RS-8_WARNING',
            'severity' => 'warning',
            'message'  => 'Location validated, but local jurisdiction zoning layer needs manual selection.'
        ]],
        'activitySessionId' => $activitySessionId
    ]
], JSON_PRETTY_PRINT);

#region Helper Routines

function resolveMappedField(array $attributes, array $fieldCandidates, array $norm = []): ?string {
    $normalizedAttrs = [];
    foreach ($attributes as $key => $val) {
        $normalizedAttrs[strtoupper((string)$key)] = $val;
    }

    $emptyTokens = $norm['emptyValueTokens'] ?? ['', ' '];

    foreach ($fieldCandidates as $candidate) {
        $key = strtoupper((string)$candidate);
        if (array_key_exists($key, $normalizedAttrs) && $normalizedAttrs[$key] !== null) {
            $val = (string)$normalizedAttrs[$key];

            if (!empty($norm['trimValues'])) {
                $val = trim($val);
            }

            if (!empty($norm['collapseWhitespace'])) {
                $val = (string)preg_replace('/\s+/', ' ', $val);
            }

            if (!empty($norm['uppercaseZoningCode'])) {
                $val = strtoupper($val);
            }

            if (in_array($val, $emptyTokens, true)) {
                continue;
            }

            return $val;
        }
    }

    return null;
}

function httpGetJson(string $url, int $timeoutSeconds = 5): ?array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'Skyesoft-LocationCheck/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : null;
}

#endregion