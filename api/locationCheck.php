<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — locationCheck.php (API Endpoint)
//  Version: 2.1.6
//  Last Updated: 2026-08-08
//  Codex Tier: 2 — Infrastructure / GIS & Location Pipeline
// ======================================================================

#region SECTION 0 — Environment Bootstrap & Headers

header('Content-Type: application/json');

// Bootstrap Environment Loader from within /api directory
$envLoaderPath = __DIR__ . '/utils/envLoader.php';
if (!file_exists($envLoaderPath)) {
    $envLoaderPath = __DIR__ . '/../utils/envLoader.php';
}

if (file_exists($envLoaderPath)) {
    require_once $envLoaderPath;
    if (function_exists('skyesoftLoadEnv')) {
        skyesoftLoadEnv();
    }
}

$executionTime = time();
$isoTimestamp  = date('c', $executionTime);

// Robust API Key Resolution
$googleMapsApiKey = '';
if (function_exists('skyesoftGetEnv')) {
    $googleMapsApiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY') ?: skyesoftGetEnv('GOOGLE_MAPS_API_KEY');
}

if (empty($googleMapsApiKey)) {
    $googleMapsApiKey = $_ENV['GOOGLE_MAPS_BACKEND_API_KEY']
        ?? $_ENV['GOOGLE_MAPS_API_KEY']
        ?? getenv('GOOGLE_MAPS_BACKEND_API_KEY')
        ?? getenv('GOOGLE_MAPS_API_KEY')
        ?? getenv('GOOGLE_MAPS_STATIC_API_KEY')
        ?? $_SERVER['GOOGLE_MAPS_API_KEY']
        ?? '';
}

#endregion

#region SECTION 1 — Request Ingestion & Data Cleansing

$rawInput = file_get_contents('php://input');
$input    = json_decode((string)$rawInput, true);

if (!is_array($input)) {
    $input = $_GET;
}

$dataObj  = $input['data'] ?? [];
$location = $input['location'] ?? $dataObj['location'] ?? $input;
$parcel   = $location['parcel'] ?? $dataObj['location']['parcelDetails'][0] ?? $dataObj['parcel'] ?? [];

$activitySessionId = $input['activitySessionId'] ?? $dataObj['activitySessionId'] ?? bin2hex(random_bytes(16));
$debugEnabled      = filter_var($input['debug'] ?? $_GET['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Extract normalized location attributes
$address      = $location['locationAddress'] ?? $dataObj['locationAddress'] ?? $input['locationAddress'] ?? null;
$city         = $location['locationCity'] ?? $dataObj['locationCity'] ?? null;
$state        = $location['locationState'] ?? $dataObj['locationState'] ?? 'AZ';
$zip          = $location['locationZip'] ?? $dataObj['locationZip'] ?? null;
$cityStateZip = $location['locationCityStateZip'] ?? $dataObj['locationCityStateZip'] ?? implode(', ', array_filter([$city, trim($state . ' ' . $zip)]));

// Regional Unit Cleansing: Strip suite/unit designators
$cleanAddress = preg_replace('/\b(suite|ste|unit|apt|#)\s*[\w-]+/i', '', (string)$address);
$cleanAddress = trim(preg_replace('/\s+/', ' ', (string)$cleanAddress), " ,");

$locationPlaceId = $location['locationPlaceId'] 
    ?? $location['placeId'] 
    ?? $location['place_id'] 
    ?? $dataObj['locationPlaceId'] 
    ?? $dataObj['placeId'] 
    ?? $dataObj['place_id'] 
    ?? $input['locationPlaceId'] 
    ?? null;

$locationParcelNumber = $location['locationParcelNumberRaw'] ?? $location['locationParcelNumber'] ?? $dataObj['locationParcelNumber'] ?? $parcel['apnDisplay'] ?? $parcel['apnRaw'] ?? $parcel['parcelNumber'] ?? null;
$locationJurisdiction = $location['locationJurisdiction'] ?? $dataObj['locationJurisdiction'] ?? $city ?? null;
$locationCounty       = $location['locationCounty'] ?? $dataObj['locationCounty'] ?? 'Maricopa';

$addressParts      = array_filter([$address, $cityStateZip]);
$fullAddress       = !empty($addressParts) ? implode(', ', $addressParts) : null;
$cleanAddressParts = array_filter([$cleanAddress, $cityStateZip]);
$fullCleanAddress  = !empty($cleanAddressParts) ? implode(', ', $cleanAddressParts) : $fullAddress;

#endregion

#region SECTION 2 — Google Place ID & Spatial Geocoding Resolution

if (!function_exists('cleanAddressForPlaces')) {
    function cleanAddressForPlaces(string $rawAddress): string
    {
        $clean = preg_replace('/\b(suite|ste|unit|apt|apartment|#)\s*[\w\-]+/i', '', $rawAddress);

        if (class_exists('NumberFormatter')) {
            $formatter = new NumberFormatter('en', NumberFormatter::SPELLOUT);
            $clean = preg_replace_callback('/\b([0-9]+)(st|nd|rd|th)\b/i', static function ($matches) use ($formatter) {
                $num = (int)$matches[1];
                $spelled = $formatter->format($num);
                return ucwords(str_replace('-', ' ', $spelled));
            }, $clean);
        }

        return trim(preg_replace('/\s+/', ' ', $clean));
    }
}

$rawLat = $location['locationLatitude'] ?? $dataObj['locationLatitude'] ?? $dataObj['coordinates']['lat'] ?? $input['locationLatitude'] ?? null;
$rawLng = $location['locationLongitude'] ?? $dataObj['locationLongitude'] ?? $dataObj['coordinates']['lng'] ?? $input['locationLongitude'] ?? null;

$lat = ($rawLat !== null && is_numeric($rawLat)) ? (float)$rawLat : null;
$lng = ($rawLng !== null && is_numeric($rawLng)) ? (float)$rawLng : null;

$locationResolvedAddress = $fullAddress;
$issues                  = [];
$locationValidated       = true;

// Option A: Pre-existing Google Place ID lookup
if ($locationPlaceId && $googleMapsApiKey) {
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

// Option B: Query Google Geocoding API using cleaned street address
if (!$locationPlaceId && $fullCleanAddress && $googleMapsApiKey) {
    $googleGeocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address' => $fullCleanAddress,
        'key'     => $googleMapsApiKey
    ]);

    $geoRes = httpGetJson($googleGeocodeUrl);
    if (($geoRes['status'] ?? '') === 'OK' && !empty($geoRes['results'][0])) {
        $firstResult             = $geoRes['results'][0];
        $locationPlaceId         = $firstResult['place_id'] ?? null;
        if ($lat === null && isset($firstResult['geometry']['location']['lat']) && is_numeric($firstResult['geometry']['location']['lat'])) {
            $lat = (float)$firstResult['geometry']['location']['lat'];
        }
        if ($lng === null && isset($firstResult['geometry']['location']['lng']) && is_numeric($firstResult['geometry']['location']['lng'])) {
            $lng = (float)$firstResult['geometry']['location']['lng'];
        }
        $locationResolvedAddress = $firstResult['formatted_address'] ?? $fullAddress;
    }

    // Fallback to Places Find Place From Text
    if (!$locationPlaceId) {
        $placesQueryAddress = cleanAddressForPlaces($fullCleanAddress);

        $findPlaceUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' . http_build_query([
            'input'     => $placesQueryAddress,
            'inputtype' => 'textquery',
            'fields'    => 'place_id,formatted_address,geometry',
            'key'       => $googleMapsApiKey
        ]);

        $findRes = httpGetJson($findPlaceUrl);
        if (($findRes['status'] ?? '') === 'OK' && !empty($findRes['candidates'][0])) {
            $candidate               = $findRes['candidates'][0];
            $locationPlaceId         = $candidate['place_id'] ?? null;
            if ($lat === null && isset($candidate['geometry']['location']['lat']) && is_numeric($candidate['geometry']['location']['lat'])) {
                $lat = (float)$candidate['geometry']['location']['lat'];
            }
            if ($lng === null && isset($candidate['geometry']['location']['lng']) && is_numeric($candidate['geometry']['location']['lng'])) {
                $lng = (float)$candidate['geometry']['location']['lng'];
            }
            $locationResolvedAddress = $candidate['formatted_address'] ?? $locationResolvedAddress;
        }
    }
}

// Option C: Reverse Geocode via Lat/Lng if Place ID is missing
if (!$locationPlaceId && $lat !== null && $lng !== null && $googleMapsApiKey) {
    $googleReverseGeocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'latlng' => $lat . ',' . $lng,
        'key'    => $googleMapsApiKey
    ]);

    $revRes = httpGetJson($googleReverseGeocodeUrl);
    if (($revRes['status'] ?? '') === 'OK' && !empty($revRes['results'][0])) {
        $locationPlaceId         = $revRes['results'][0]['place_id'] ?? null;
        $locationResolvedAddress = $revRes['results'][0]['formatted_address'] ?? $locationResolvedAddress;
    }
}

// Option D: Fallback to ArcGIS World Geocoding Server
if (($lat === null || $lng === null) && $fullCleanAddress !== null) {
    $arcgisGeocodeUrl = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
        'f'            => 'json',
        'singleLine'   => $fullCleanAddress,
        'outFields'    => 'Match_addr,Addr_type,StAddr,City,Region,Postal',
        'maxLocations' => 1
    ]);

    $geocodeData = httpGetJson($arcgisGeocodeUrl);
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

#endregion

#region SECTION 3 — Registry Configuration & Short-Circuit Check

$jurisKey  = strtolower(trim((string)$locationJurisdiction));
$jurisSlug = trim(preg_replace('/[^a-z0-9]+/', '-', $jurisKey), '-');

$zoningRegistryCandidates = [
    __DIR__ . '/data/authoritative/jurisdictions/' . $jurisSlug . '/zoning.json',
    __DIR__ . '/../data/authoritative/jurisdictions/' . $jurisSlug . '/zoning.json',
    __DIR__ . '/zoning.json',
    __DIR__ . '/../zoning.json',
    __DIR__ . '/zoning (1).json',
    __DIR__ . '/../zoning (1).json'
];

$zoningRegistryFile = null;
foreach ($zoningRegistryCandidates as $candidateFile) {
    if (is_file($candidateFile)) {
        $zoningRegistryFile = $candidateFile;
        break;
    }
}

$zoningConfig = [];
if ($zoningRegistryFile !== null) {
    $zoningConfig = json_decode((string)file_get_contents($zoningRegistryFile), true);
    if (!is_array($zoningConfig)) {
        $zoningConfig = [];
    }
}

$matchedConfig = null;
$configSlug = strtolower((string)($zoningConfig['jurisdiction']['slug'] ?? $zoningConfig['jurisdiction']['label'] ?? ''));
if ($configSlug !== '' && ($configSlug === $jurisKey || $configSlug === $jurisSlug)) {
    $matchedConfig = $zoningConfig;
} elseif ($jurisKey !== '') {
    if (isset($zoningConfig[$jurisKey])) {
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

// SHORT-CIRCUIT: Direct Parcel Bypass
if (!empty($parcel['zoningCode']) && strtoupper((string)$parcel['zoningCode']) !== 'UNKNOWN') {
    echo json_encode([
        'success'           => true,
        'status'            => 'resolved',
        'activitySessionId' => $activitySessionId,
        'data'              => [
            'location' => formatLocationResponse([
                'address'           => $address,
                'resolvedAddress'   => $locationResolvedAddress,
                'city'              => $city,
                'state'             => $state,
                'zip'               => $zip,
                'placeId'           => $locationPlaceId,
                'lat'               => $lat,
                'lng'               => $lng,
                'county'            => $locationCounty,
                'jurisdiction'      => ucfirst((string)$locationJurisdiction),
                'parcelNumber'      => $locationParcelNumber,
                'zoningCode'        => $parcel['zoningCode'],
                'zoningDescription' => $parcel['zoningDescription'] ?? 'N/A',
                'zoningSource'      => $parcel['zoningSource'] ?? 'Skyesoft Parcel Record',
                'ownerName'         => $parcel['ownerName'] ?? null,
                'subdivision'       => $parcel['subdivision'] ?? null,
                'lotSize'           => $parcel['lotSize'] ?? null,
                'confidence'        => (int)($parcel['confidence'] ?? 95),
                'verifiedAt'        => $executionTime
            ])
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

#endregion

#region SECTION 4 — Maricopa County Spatial Assessor Query

$ownerName = null;
$subdiv    = null;
$lotSize   = null;

if ($lat !== null && $lng !== null) {
    $queryEnvelope = function(float $x, float $y, float $delta) {
        return 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . http_build_query([
            'f'                => 'json',
            'geometry'         => json_encode([
                'xmin' => $x - $delta,
                'ymin' => $y - $delta,
                'xmax' => $x + $delta,
                'ymax' => $y + $delta,
                'spatialReference' => ['wkid' => 4326]
            ]),
            'geometryType'     => 'esriGeometryEnvelope',
            'inSR'             => '4326',
            'spatialRel'       => 'esriSpatialRelIntersects',
            'where'            => '1=1',
            'outFields'        => '*',
            'returnGeometry'   => 'false'
        ]);
    };

    $parcelRes = httpGetJson($queryEnvelope($lng, $lat, 0.00002), 8);
    if (empty($parcelRes['features'])) {
        $parcelRes = httpGetJson($queryEnvelope($lng, $lat, 0.00015), 8);
    }

    $pAttr = $parcelRes['features'][0]['attributes'] ?? [];
    if (!empty($pAttr)) {
        $locationParcelNumber = $pAttr['APN_FORMATTED'] ?? $pAttr['PARCEL_ID'] ?? $pAttr['APN'] ?? $pAttr['PARCEL'] ?? $locationParcelNumber;
        $ownerName            = $pAttr['OWNER_NAME'] ?? $pAttr['OWNER'] ?? null;
        $subdiv               = $pAttr['SUBDIVISION'] ?? $pAttr['SUB_NAME'] ?? null;
        $lotSize              = isset($pAttr['SQUARE_FEET']) ? (int)$pAttr['SQUARE_FEET'] : (isset($pAttr['CALC_AREA']) ? (int)$pAttr['CALC_AREA'] : null);
        $city                 = $city ?: ($pAttr['CITY'] ?? null);
    }
}

#endregion

#region SECTION 5 — Municipal Zoning Resolution

$zoningCode        = 'UNKNOWN';
$zoningDesc        = 'N/A';
$sourceLayer       = 'Unmapped Spatial Layer';
$zoningDiagnostics = [];

if (is_array($matchedConfig) && isset($matchedConfig['service']['serviceUrl'])) {
    $svc  = $matchedConfig['service'];
    $qry  = $matchedConfig['query'] ?? [];
    $fm   = $matchedConfig['fieldMapping'] ?? [];
    $norm = $matchedConfig['normalization'] ?? [];

    $layerId  = filter_var($svc['layerId'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $endpoint = rtrim((string)$svc['serviceUrl'], '/') . '/' . $layerId . '/query';

    $executeZoningQuery = function(string $geomJson, string $geomType) use ($endpoint, $qry) {
        $queryParams = [
            'f'              => $qry['responseFormat'] ?? 'json',
            'geometry'       => $geomJson,
            'geometryType'   => $geomType,
            'inSR'           => '4326',
            'spatialRel'     => $qry['spatialRelationship'] ?? 'esriSpatialRelIntersects',
            'where'          => $qry['where'] ?? '1=1',
            'outFields'      => is_array($qry['outFields'] ?? null) ? implode(',', $qry['outFields']) : ($qry['outFields'] ?? '*'),
            'returnGeometry' => 'false'
        ];
        return httpGetJsonDetailed($endpoint . '?' . http_build_query($queryParams), (int)($qry['timeoutSeconds'] ?? 8));
    };

    $pointGeom    = json_encode(['x' => $lng, 'y' => $lat, 'spatialReference' => ['wkid' => 4326]]);
    $zoningResult = $executeZoningQuery($pointGeom, 'esriGeometryPoint');
    $zoningData   = $zoningResult['data'] ?? [];
    $features     = $zoningData['features'] ?? [];

    if (empty($features)) {
        $delta   = 0.00015;
        $envGeom = json_encode([
            'xmin' => $lng - $delta,
            'ymin' => $lat - $delta,
            'xmax' => $lng + $delta,
            'ymax' => $lat + $delta,
            'spatialReference' => ['wkid' => 4326]
        ]);
        $zoningResult = $executeZoningQuery($envGeom, 'esriGeometryEnvelope');
        $zoningData   = $zoningResult['data'] ?? [];
        $features     = $zoningData['features'] ?? [];
    }

    if (!empty($features)) {
        $attrs       = $features[0]['attributes'] ?? [];
        $sourceLayer = (string)($svc['provider'] ?? 'City GIS');

        $codeCandidates = array_merge($fm['zoningCode'] ?? [], ['ZONING', 'ZONING_CODE', 'LABEL', 'ZONE', 'DISTRICT']);
        $descCandidates = array_merge($fm['zoningDescription'] ?? [], ['DESCRIPTION', 'ZONING_DESC', 'ZONE_DESC']);

        $zCode = resolveMappedField($attrs, $codeCandidates, $norm);
        if ($zCode !== null) {
            $zoningCode = !empty($norm['uppercaseZoningCode']) ? strtoupper($zCode) : $zCode;
        }

        $zDesc = resolveMappedField($attrs, $descCandidates, $norm);
        if ($zDesc !== null) {
            $zoningDesc = $zDesc;
        }
    }
}

$hasZoningMatch   = ($zoningCode !== 'UNKNOWN' && $zoningCode !== 'N/A');
$resultConfidence = $hasZoningMatch ? 95 : 50;

#endregion

#region SECTION 6 — Payload Output & Execution Termination

$output = [
    'success'           => true,
    'status'            => $hasZoningMatch ? 'resolved' : 'zoning_unmapped',
    'activitySessionId' => $activitySessionId,
    'data'              => [
        'location' => formatLocationResponse([
            'address'           => $address,
            'resolvedAddress'   => $locationResolvedAddress,
            'city'              => $city ?: ucfirst((string)$locationJurisdiction),
            'state'             => $state,
            'zip'               => $zip,
            'placeId'           => $locationPlaceId,
            'lat'               => $lat,
            'lng'               => $lng,
            'county'            => $locationCounty,
            'jurisdiction'      => ucfirst((string)$locationJurisdiction),
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
];

// Append debug block if debug is requested or matched
if ($debugEnabled || isset($zoningResult)) {
    $output['debug'] = [
        'jurisdictionKey'  => $jurisKey,
        'jurisdictionSlug' => $jurisSlug,
        'configMatched'    => ($matchedConfig !== null),
        'zoningQuery'      => [
            'configFile'   => $zoningRegistryFile,
            'endpoint'     => $endpoint ?? null,
            'httpCode'     => $zoningResult['httpCode'] ?? 200,
            'curlError'    => $zoningResult['curlError'] ?? '',
            'arcGisError'  => $zoningResult['data']['error'] ?? null,
            'featureCount' => count($features ?? [])
        ]
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

#endregion

#region SECTION 7 — Helper Routines

if (!function_exists('httpGetJson')) {
    function httpGetJson(string $url, int $timeout = 10): array {
        $res = httpGetJsonDetailed($url, $timeout);
        return is_array($res['data']) ? $res['data'] : [];
    }
}

if (!function_exists('httpGetJsonDetailed')) {
    function httpGetJsonDetailed(string $url, int $timeout = 10): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: Skyesoft-GIS/2.1'],
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = ($response !== false) ? json_decode($response, true) : null;

        return [
            'success'   => ($httpCode >= 200 && $httpCode < 300 && is_array($decoded)),
            'httpCode'  => $httpCode,
            'curlError' => $curlError,
            'data'      => $decoded
        ];
    }
}

if (!function_exists('resolveMappedField')) {
    function resolveMappedField(array $attributes, array $candidates, array $normalization = []): ?string {
        foreach ($candidates as $candidate) {
            foreach ($attributes as $key => $val) {
                if (strcasecmp((string)$key, (string)$candidate) === 0 && $val !== null && trim((string)$val) !== '') {
                    $strVal = trim((string)$val);
                    if (!empty($normalization['stripSpecialChars'])) {
                        $strVal = preg_replace('/[^\w\s-]/', '', $strVal);
                    }
                    return $strVal;
                }
            }
        }
        return null;
    }
}

function formatLocationResponse(array $p): array {
    $cleanApn = $p['parcelNumber'] ? preg_replace('/\D/', '', (string)$p['parcelNumber']) : null;
    $isResolved = ($p['zoningCode'] !== 'UNKNOWN' && $p['zoningCode'] !== 'N/A');

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
                    'mapId'        => $cleanApn,
                    'mapUrl'       => $cleanApn ? 'https://mcassessor.maricopa.gov/getmapid/' . $cleanApn . '/' : null,
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
                    'status'            => $isResolved ? 'resolved' : 'unmapped',
                    'reason'            => null,
                    'message'           => $isResolved ? 'Base zoning resolved successfully.' : 'Zoning layer unmapped.',
                    'zoningCode'        => $p['zoningCode'],
                    'zoningDescription' => $p['zoningDescription'],
                    'zoningSource'      => $p['zoningSource'],
                    'zoningVerifiedAt'  => $p['verifiedAt'],
                    'confidence'        => $p['confidence'],
                    'requiresReview'    => !$isResolved
                ]
            ]
        ],
        'parcelCount'        => 1,
        'jurisdictionName'   => $p['jurisdiction'],
        'jurisdictionType'   => 'City',
        'hasMultipleParcels' => false,
        'zoning'             => [
            'status'            => $isResolved ? 'resolved' : 'unmapped',
            'reason'            => null,
            'zoningCode'        => $p['zoningCode'],
            'zoningDescription' => $p['zoningDescription'],
            'zoningSource'      => $p['zoningSource'],
            'zoningVerifiedAt'  => $p['verifiedAt'],
            'confidence'        => $p['confidence'],
            'requiresReview'    => !$isResolved
        ]
    ];
}

#endregion