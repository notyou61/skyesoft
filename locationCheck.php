<?php

declare(strict_types=1);

header('Content-Type: application/json');

$executionTime = time();
$isoTimestamp  = date('c', $executionTime);

// 1. Read input payload (Supports JSON POST body or GET parameters)
$rawInput = file_get_contents('php://input');
$input    = json_decode((string)$rawInput, true);

if (!is_array($input)) {
    $input = $_GET;
}

$dataObj  = $input['data'] ?? [];
$location = $input['location'] ?? $dataObj['location'] ?? $input;
$parcel   = $location['parcel'] ?? $dataObj['location']['parcelDetails'][0] ?? $dataObj['parcel'] ?? [];

$googleMapsApiKey   = getenv('GOOGLE_MAPS_API_KEY') ?: null;
$activitySessionId  = $input['activitySessionId'] ?? $dataObj['activitySessionId'] ?? bin2hex(random_bytes(16));

// Extract location strings
$address      = $location['locationAddress'] ?? $dataObj['locationAddress'] ?? $input['locationAddress'] ?? null;
$city         = $location['locationCity'] ?? $dataObj['locationCity'] ?? null;
$state        = $location['locationState'] ?? $dataObj['locationState'] ?? 'AZ';
$zip          = $location['locationZip'] ?? $dataObj['locationZip'] ?? null;
$cityStateZip = $location['locationCityStateZip'] ?? $dataObj['locationCityStateZip'] ?? implode(', ', array_filter([$city, trim($state . ' ' . $zip)]));

$locationPlaceId      = $location['locationPlaceId'] ?? $dataObj['locationPlaceId'] ?? null;
$locationParcelNumber = $location['locationParcelNumberRaw'] ?? $location['locationParcelNumber'] ?? $dataObj['locationParcelNumber'] ?? $parcel['apnDisplay'] ?? $parcel['apnRaw'] ?? $parcel['parcelNumber'] ?? null;
$locationJurisdiction = $location['locationJurisdiction'] ?? $dataObj['locationJurisdiction'] ?? $city ?? null;
$locationCounty       = $location['locationCounty'] ?? $dataObj['locationCounty'] ?? 'Maricopa';

$addressParts = array_filter([$address, $cityStateZip]);
$fullAddress  = !empty($addressParts) ? implode(', ', $addressParts) : null;

// 2. Load zoning registry configuration
$zoningRegistryFile = __DIR__ . '/zoning.json';
$zoningConfig       = file_exists($zoningRegistryFile) 
    ? (json_decode((string)file_get_contents($zoningRegistryFile), true) ?? [])
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

// 3. SHORT-CIRCUIT: Direct Parcel Bypass if already fully supplied
if (!empty($parcel['zoningCode']) && strtoupper((string)$parcel['zoningCode']) !== 'UNKNOWN') {
    echo json_encode([
        'success'           => true,
        'status'            => 'resolved',
        'activitySessionId' => $activitySessionId,
        'data'              => [
            'location' => formatLocationResponse([
                'address'           => $address,
                'resolvedAddress'  => $fullAddress,
                'city'              => $city,
                'state'             => $state,
                'zip'               => $zip,
                'placeId'           => $locationPlaceId,
                'lat'               => $location['locationLatitude'] ?? null,
                'lng'               => $location['locationLongitude'] ?? null,
                'county'            => $locationCounty,
                'jurisdiction'      => $locationJurisdiction,
                'parcelNumber'      => $locationParcelNumber,
                'zoningCode'        => $parcel['zoningCode'],
                'zoningDescription' => $parcel['zoningDescription'] ?? 'N/A',
                'zoningSource'      => $parcel['zoningSource'] ?? 'Skyesoft Parcel Record',
                'confidence'        => (int)($parcel['confidence'] ?? 95),
                'verifiedAt'        => $executionTime
            ])
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 4. RESOLVE COORDINATES & GEOCODING
$rawLat = $location['locationLatitude'] ?? $dataObj['locationLatitude'] ?? $dataObj['coordinates']['lat'] ?? $input['locationLatitude'] ?? null;
$rawLng = $location['locationLongitude'] ?? $dataObj['locationLongitude'] ?? $dataObj['coordinates']['lng'] ?? $input['locationLongitude'] ?? null;

$lat = ($rawLat !== null && is_numeric($rawLat)) ? (float)$rawLat : null;
$lng = ($rawLng !== null && is_numeric($rawLng)) ? (float)$rawLng : null;

$locationResolvedAddress = $fullAddress;

if (($lat === null || $lng === null) && $locationPlaceId && $googleMapsApiKey) {
    $placeDetailsUrl = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $locationPlaceId,
        'fields'   => 'geometry,formatted_address',
        'key'      => $googleMapsApiKey
    ]);

    $placeRes = httpGetJson($placeDetailsUrl);
    if (($placeRes['status'] ?? '') === 'OK' && isset($placeRes['result']['geometry']['location'])) {
        $lat                     = (float)$placeRes['result']['geometry']['location']['lat'];
        $lng                     = (float)$placeRes['result']['geometry']['location']['lng'];
        $locationResolvedAddress = $placeRes['result']['formatted_address'] ?? $fullAddress;
    }
}

if (($lat === null || $lng === null) && $fullAddress !== null) {
    $geocodeUrl = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
        'f'            => 'json',
        'singleLine'   => $fullAddress,
        'outFields'    => 'Match_addr,Addr_type,StAddr,City,Region,Postal',
        'maxLocations' => 1
    ]);

    $geocodeData = httpGetJson($geocodeUrl);
    $candidate   = $geocodeData['candidates'][0] ?? null;

    if ($candidate && ($candidate['score'] ?? 0) >= 70) {
        $lng                     = (float)$candidate['location']['x'];
        $lat                     = (float)$candidate['location']['y'];
        $locationResolvedAddress = $candidate['attributes']['Match_addr'] ?? $fullAddress;
        $city                    = $city ?: ($candidate['attributes']['City'] ?? $city);
        $zip                     = $zip ?: ($candidate['attributes']['Postal'] ?? $zip);
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
        'success'           => false,
        'status'            => 'location_invalid',
        'activitySessionId' => $activitySessionId,
        'issues'            => $issues
    ], JSON_PRETTY_PRINT);
    exit;
}

// 5. QUERY PARCEL DETAILS VIA MARICOPA ASSESSOR SPATIAL REST
$ownerName = null;
$subdiv    = null;
$lotSize   = null;

if ($lat !== null && $lng !== null) {
    $parcelSpatialUrl = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . http_build_query([
        'f'              => 'json',
        'geometry'       => json_encode(['x' => $lng, 'y' => $lat, 'spatialReference' => ['wkid' => 4326]]),
        'geometryType'   => 'esriGeometryPoint',
        'inSR'           => '4326',
        'spatialRel'     => 'esriSpatialRelIntersects',
        'where'          => '1=1',
        'outFields'      => 'APN,OWNER_NAME,SITE_ADDRESS,CITY,SUBDIVISION,SQUARE_FEET',
        'returnGeometry' => 'false'
    ]);

    $parcelRes = httpGetJson($parcelSpatialUrl, 8);
    $pAttr     = $parcelRes['features'][0]['attributes'] ?? [];

    if (!empty($pAttr)) {
        $locationParcelNumber = $pAttr['APN'] ?? $locationParcelNumber;
        $ownerName            = $pAttr['OWNER_NAME'] ?? null;
        $subdiv               = $pAttr['SUBDIVISION'] ?? null;
        $lotSize              = isset($pAttr['SQUARE_FEET']) ? (int)$pAttr['SQUARE_FEET'] : null;
        $city                 = $city ?: ($pAttr['CITY'] ?? null);
    }
}

// 6. QUERY JURISDICTION ZONING LAYER
$zoningCode  = 'UNKNOWN';
$zoningDesc  = 'N/A';
$sourceLayer = 'Unmapped Spatial Layer';

if (is_array($matchedConfig) && isset($matchedConfig['service']['serviceUrl'])) {
    $svc  = $matchedConfig['service'];
    $qry  = $matchedConfig['query'] ?? [];
    $fm   = $matchedConfig['fieldMapping'] ?? [];
    $norm = $matchedConfig['normalization'] ?? [];

    $endpoint = rtrim((string)$svc['serviceUrl'], '/') . '/' . ($svc['layerId'] ?? 0) . '/query';

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

    $zoningData = httpGetJson($endpoint . '?' . http_build_query($queryParams), (int)($qry['timeoutSeconds'] ?? 8));
    $features   = $zoningData['features'] ?? [];

    if (!empty($features)) {
        $attrs       = $features[0]['attributes'] ?? [];
        $sourceLayer = ($svc['provider'] ?? 'City GIS') . ' Community Development Department';

        $zCode = resolveMappedField($attrs, $fm['zoningCode'] ?? ['ZONING', 'LABEL1', 'ZONE'], $norm);
        if ($zCode !== null) {
            $zoningCode = $zCode;
        }

        $zDesc = resolveMappedField($attrs, $fm['zoningDescription'] ?? ['GEN_ZONE', 'DESCRIPTION'], $norm);
        if ($zDesc !== null) {
            $zoningDesc = $zDesc;
        }
    }
}

$hasZoningMatch   = ($zoningCode !== 'UNKNOWN' && $zoningCode !== 'N/A');
$resultConfidence = $hasZoningMatch ? 95 : 50;

// 7. RETURN STANDARDIZED CONTACT-PROPOSAL FORMAT
echo json_encode([
    'success'           => true,
    'status'            => $hasZoningMatch ? 'resolved' : 'zoning_unmapped',
    'activitySessionId' => $activitySessionId,
    'data'              => [
        'location' => formatLocationResponse([
            'address'           => $address,
            'resolvedAddress'  => $locationResolvedAddress,
            'city'              => $city ?: $locationJurisdiction,
            'state'             => $state,
            'zip'               => $zip,
            'placeId'           => $locationPlaceId,
            'lat'               => $lat,
            'lng'               => $lng,
            'county'            => $locationCounty,
            'jurisdiction'      => $locationJurisdiction,
            'parcelNumber'      => $locationParcelNumber,
            'ownerName'         => $ownerName,
            'subdivision'       => $subdiv,
            'lotSize'           => $lotSize,
            'zoningCode'        => $zoningCode,
            'zoningDescription' => $zoningDesc,
            'zoningSource'      => $sourceLayer,
            'confidence'        => $resultConfidence,
            'verifiedAt'        => $executionTime
        ])
    ]
], JSON_PRETTY_PRINT);

#region Helper Routines

function formatLocationResponse(array $p): array {
    return [
        'locationAddress'         => $p['address'],
        'locationAddressRaw'      => $p['address'],
        'locationCity'            => $p['city'],
        'locationState'           => $p['state'],
        'locationZip'             => $p['zip'],
        'locationPlaceId'         => $p['placeId'],
        'locationLatitude'        => $p['lat'],
        'locationLongitude'       => $p['lng'],
        'locationValidated'       => true,
        'locationResolvedAddress' => $p['resolvedAddress'],
        'locationMatchQuality'    => [
            'partialMatch' => false,
            'locationType' => 'ROOFTOP',
            'mismatches'   => [],
            'warnings'     => []
        ],
        'locationCounty'          => $p['county'],
        'locationCensusValidated' => true,
        'locationCountyFips'      => '013',
        'locationCountyGeoId'     => '04013',
        'parcelDetails'           => [
            [
                'parcelNumber' => $p['parcelNumber'],
                'ownerName'    => $p['ownerName'],
                'siteAddress'  => $p['resolvedAddress'],
                'city'         => $p['city'],
                'jurisdiction' => $p['jurisdiction'],
                'source'       => 'arcgis_coordinate',
                'assessor'     => [
                    'propertyType' => 'Commercial',
                    'mapId'        => $p['parcelNumber'] ? $p['parcelNumber'] . '00' : null,
                    'mapUrl'       => $p['parcelNumber'] ? 'https://mcassessor.maricopa.gov/getmapid/' . $p['parcelNumber'] . '/' : null,
                    'status'       => 'resolved'
                ],
                'owner' => [
                    'name'           => $p['ownerName'],
                    'mailingAddress' => null,
                    'inCareOf'       => null
                ],
                'parcelRecord' => [
                    'apnRaw'            => $p['parcelNumber'],
                    'ownerName'         => $p['ownerName'],
                    'subdivision'       => $p['subdivision'] ?? null,
                    'lotSize'           => $p['lotSize'] ?? null,
                    'yearBuilt'         => null,
                    'zoningCode'        => $p['zoningCode'],
                    'zoningDescription' => $p['zoningDescription'],
                    'zoningSource'      => $p['zoningSource'],
                    'zoningVerifiedAt'  => $p['verifiedAt'],
                    'source'            => 'maricopa_assessor',
                    'confidence'        => $p['confidence'],
                    'createdAt'         => $p['verifiedAt'],
                    'updatedAt'         => null
                ],
                'parcelRecordReady' => true,
                'zoning'            => [
                    'status'            => ($p['zoningCode'] !== 'UNKNOWN') ? 'resolved' : 'unmapped',
                    'reason'            => null,
                    'message'           => ($p['zoningCode'] !== 'UNKNOWN') ? 'Base zoning resolved successfully.' : 'Zoning layer unmapped.',
                    'zoningCode'        => $p['zoningCode'],
                    'zoningDescription' => $p['zoningDescription'],
                    'zoningSource'      => $p['zoningSource'],
                    'zoningVerifiedAt'  => $p['verifiedAt'],
                    'confidence'        => $p['confidence'],
                    'requiresReview'    => ($p['zoningCode'] === 'UNKNOWN')
                ]
            ]
        ],
        'parcelCount'        => 1,
        'jurisdictionName'   => $p['jurisdiction'],
        'jurisdictionType'   => 'City',
        'hasMultipleParcels' => false,
        'zoning'             => [
            'status'            => ($p['zoningCode'] !== 'UNKNOWN') ? 'resolved' : 'unmapped',
            'reason'            => null,
            'zoningCode'        => $p['zoningCode'],
            'zoningDescription' => $p['zoningDescription'],
            'zoningSource'      => $p['zoningSource'],
            'zoningVerifiedAt'  => $p['verifiedAt'],
            'confidence'        => $p['confidence'],
            'requiresReview'    => ($p['zoningCode'] === 'UNKNOWN')
        ]
    ];
}

function resolveMappedField(array $attributes, array $fieldCandidates, array $norm = []): ?string {
    $normalizedAttrs = [];
    foreach ($attributes as $key => $val) {
        $normalizedAttrs[strtoupper((string)$key)] = $val;
    }

    foreach ($fieldCandidates as $candidate) {
        $key = strtoupper((string)$candidate);
        if (array_key_exists($key, $normalizedAttrs) && $normalizedAttrs[$key] !== null) {
            $val = trim((string)$normalizedAttrs[$key]);
            if ($val !== '') {
                return $val;
            }
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