<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — locationCheck.php (API Endpoint)
//  Version: 2.5.0 (Section Disclaimer Sources)
// ======================================================================

#region SECTION 0 — Headers & Environment

header('Content-Type: application/json');

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

$address      = $location['locationAddress'] ?? $dataObj['locationAddress'] ?? $input['locationAddress'] ?? null;
$city         = $location['locationCity'] ?? $dataObj['locationCity'] ?? null;
$state        = $location['locationState'] ?? $dataObj['locationState'] ?? 'AZ';
$zip          = $location['locationZip'] ?? $dataObj['locationZip'] ?? null;
$cityStateZip = $location['locationCityStateZip'] ?? $dataObj['locationCityStateZip'] ?? implode(', ', array_filter([$city, trim($state . ' ' . $zip)]));

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

#region SECTION 2 — Geocoding & Spatial Resolution

$rawLat = $location['locationLatitude'] ?? $dataObj['locationLatitude'] ?? $dataObj['coordinates']['lat'] ?? $input['locationLatitude'] ?? null;
$rawLng = $location['locationLongitude'] ?? $dataObj['locationLongitude'] ?? $dataObj['coordinates']['lng'] ?? $input['locationLongitude'] ?? null;

$lat = ($rawLat !== null && is_numeric($rawLat)) ? (float)$rawLat : null;
$lng = ($rawLng !== null && is_numeric($rawLng)) ? (float)$rawLng : null;

$locationResolvedAddress = $fullAddress;
$issues                  = [];
$locationValidated       = true;

// Option A: Place ID lookup
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

// Option B: Query Google Geocoding API
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

        // Resolve address components from Google response
        foreach (($firstResult['address_components'] ?? []) as $component) {
            $componentTypes = $component['types'] ?? [];

            if (
                in_array('locality', $componentTypes, true) ||
                (
                    empty($city) &&
                    in_array('postal_town', $componentTypes, true)
                )
            ) {
                $city = $component['long_name'] ?? $city;
            }

            if (in_array('administrative_area_level_1', $componentTypes, true)) {
                $state = $component['short_name'] ?? $state;
            }

            if (in_array('postal_code', $componentTypes, true)) {
                $zip = $component['long_name'] ?? $zip;
            }

            if (in_array('administrative_area_level_2', $componentTypes, true)) {
                $locationCounty = preg_replace(
                    '/\s+County$/i',
                    '',
                    (string)($component['long_name'] ?? $locationCounty)
                );
            }
        }
    }

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

// Option C: Reverse Geocode via Lat/Lng
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

// Finalize jurisdiction after geocoding
if (
    empty($locationJurisdiction) ||
    strtolower((string)$locationJurisdiction) === 'unknown'
) {
    $locationJurisdiction = $city ?: null;
}

#endregion

#region SECTION 3 — Regionalized Registry & Short-Circuit

$jurisKey   = strtolower(trim((string)$locationJurisdiction));
$jurisSlug  = trim(preg_replace('/[^a-z0-9]+/', '-', $jurisKey), '-');
$stateSlug  = strtolower(trim((string)$state));
$countySlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string)$locationCounty))), '-');

// Regionalized Registry Cascade: State/County/Jurisdiction -> Authoritative -> Local Fallbacks
$zoningRegistryCandidates = [
    __DIR__ . "/data/authoritative/{$stateSlug}/{$countySlug}/jurisdictions/{$jurisSlug}/zoning.json",
    __DIR__ . "/../data/authoritative/{$stateSlug}/{$countySlug}/jurisdictions/{$jurisSlug}/zoning.json",
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

// Direct Parcel Bypass Short-Circuit
if (!empty($parcel['zoningCode']) && strtoupper((string)$parcel['zoningCode']) !== 'UNKNOWN') {
    $frontageResult = resolveLocationFrontages(
        (string)$locationParcelNumber,
        (string)$locationJurisdiction,
        $executionTime
    );
    $signCodeResult = resolveApplicableSignCode(
        __DIR__,
        $stateSlug,
        $countySlug,
        $jurisSlug,
        (string)$locationJurisdiction,
        (string)$parcel['zoningCode'],
        $frontageResult['frontages']
    );
    $specialDesignations = resolveLocationSpecialDesignations(
        __DIR__,
        $stateSlug,
        $countySlug,
        $jurisSlug,
        (string)$locationJurisdiction,
        $lat,
        $lng
    );

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
                'verifiedAt'        => $executionTime,
                'frontages'         => $frontageResult['frontages'],
                'parcelGeometry'    => $frontageResult['parcelGeometry'],
                'signCode'          => $signCodeResult['signCode'],
                'specialDesignations' => $specialDesignations
            ])
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

#endregion

#region SECTION 4 — Maricopa Assessor GIS Query

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

// Resolve parcel frontages in memory
$frontageResult = resolveLocationFrontages(
    (string)$locationParcelNumber,
    (string)$locationJurisdiction,
    $executionTime
);
$signCodeResult = resolveApplicableSignCode(
    __DIR__,
    $stateSlug,
    $countySlug,
    $jurisSlug,
    (string)$locationJurisdiction,
    $zoningCode,
    $frontageResult['frontages']
);
$specialDesignations = resolveLocationSpecialDesignations(
        __DIR__,
        $stateSlug,
        $countySlug,
        $jurisSlug,
        (string)$locationJurisdiction,
        $lat,
        $lng
    );

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
            'verifiedAt'        => $executionTime,
            'frontages'         => $frontageResult['frontages'],
            'parcelGeometry'    => $frontageResult['parcelGeometry'],
            'signCode'          => $signCodeResult['signCode'],
            'specialDesignations' => $specialDesignations
        ])
    ]
];

if ($debugEnabled || isset($zoningResult)) {
    $output['debug'] = [
        'jurisdictionKey'  => $jurisKey,
        'jurisdictionSlug' => $jurisSlug,
        'regionalPath'     => "data/authoritative/{$stateSlug}/{$countySlug}/jurisdictions/{$jurisSlug}/zoning.json",
        'configMatched'    => ($matchedConfig !== null),
        'zoningQuery'      => [
            'configFile'   => $zoningRegistryFile,
            'endpoint'     => $endpoint ?? null,
            'httpCode'     => $zoningResult['httpCode'] ?? 200,
            'curlError'    => $zoningResult['curlError'] ?? '',
            'arcGisError'  => $zoningResult['data']['error'] ?? null,
            'featureCount' => count($features ?? [])
        ],
        'frontageQuery'    => $frontageResult['diagnostics'],
        'signCodeQuery'    => $signCodeResult['diagnostics']
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

#endregion

#region SECTION 7 — Global Function Definitions (Unconditional)

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

function httpGetJson(string $url, int $timeout = 10): array {
    $res = httpGetJsonDetailed($url, $timeout);
    return is_array($res['data']) ? $res['data'] : [];
}

function cleanAddressForPlaces(string $rawAddress): string {
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

function resolveApplicableSignCode(
    string $baseDirectory,
    string $stateSlug,
    string $countySlug,
    string $jurisdictionSlug,
    string $jurisdictionName,
    string $zoningCode,
    array $frontages = []
): array {
    $signAllowanceDisclaimer = 'Attached signs are mounted to a structure, such as wall or building signs. Detached signs are freestanding signs, such as pole, pylon, or monument signs. Any existing or remaining signs must be included when determining the total sign area available for the property.';

    // Resolve the jurisdiction artifact (regional path first).
    $signCodeCandidates = [
        $baseDirectory . "/data/authoritative/{$stateSlug}/{$countySlug}/jurisdictions/{$jurisdictionSlug}/signCode.json",
        $baseDirectory . "/../data/authoritative/{$stateSlug}/{$countySlug}/jurisdictions/{$jurisdictionSlug}/signCode.json",
        $baseDirectory . '/data/authoritative/jurisdictions/' . $jurisdictionSlug . '/signCode.json',
        $baseDirectory . '/../data/authoritative/jurisdictions/' . $jurisdictionSlug . '/signCode.json'
    ];

    $signCodeFile = null;
    foreach ($signCodeCandidates as $candidateFile) {
        if (is_file($candidateFile)) {
            $signCodeFile = $candidateFile;
            break;
        }
    }

    $normalizedZoningCode = strtoupper(trim(preg_replace('/[\s*]+$/', '', $zoningCode)));
    $defaultResult = [
        'jurisdiction'          => $jurisdictionName,
        'zoningCode'           => $normalizedZoningCode !== '' ? $normalizedZoningCode : $zoningCode,
        'landUseClassification'=> null,
        'attachedSigns'        => null,
        'detachedSigns'        => null,
        'requiredInputs'       => [],
        'source'               => null,
        'citation'             => null,
        'signAllowanceDisclaimer' => $signAllowanceDisclaimer,
        'signAllowanceDisclaimerSources' => [],
        'signAllowanceDisclaimerSource'  => null,
        'status'               => 'research_required'
    ];

    if ($signCodeFile === null) {
        return [
            'signCode'    => $defaultResult,
            'diagnostics' => [
                'status'     => 'file_missing',
                'configFile' => null
            ]
        ];
    }

    $signCodeConfig = json_decode((string)file_get_contents($signCodeFile), true);
    if (!is_array($signCodeConfig)) {
        return [
            'signCode'    => $defaultResult,
            'diagnostics' => [
                'status'     => 'invalid_json',
                'configFile' => $signCodeFile
            ]
        ];
    }

    // Match normalized zoning to the jurisdiction-defined land-use category.
    $landUseClassification = null;
    $landUseCitation       = null;
    $landUseCategories     = $signCodeConfig['classifications']['landUseCategories'] ?? [];

    foreach ($landUseCategories as $classification => $classificationConfig) {
        $districts = is_array($classificationConfig['zoningDistricts'] ?? null)
            ? $classificationConfig['zoningDistricts']
            : [];

        foreach ($districts as $district) {
            $normalizedDistrict = strtoupper(trim(preg_replace('/[\s*]+$/', '', (string)$district)));
            if ($normalizedDistrict === $normalizedZoningCode) {
                $landUseClassification = (string)$classification;
                $landUseCitation       = $classificationConfig['citation'] ?? null;
                break 2;
            }
        }
    }

    if ($landUseClassification === null) {
        $defaultResult['source'] = buildSignCodeSource($signCodeConfig);
        $defaultResult['signAllowanceDisclaimerSources'] = buildSignCodeDisclaimerSources($signCodeConfig);
        $defaultResult['signAllowanceDisclaimerSource'] = formatDisclaimerSourceLabel(
            $defaultResult['signAllowanceDisclaimerSources']
        );

        return [
            'signCode'    => $defaultResult,
            'diagnostics' => [
                'status'                => 'classification_missing',
                'configFile'            => $signCodeFile,
                'normalizedZoningCode'  => $normalizedZoningCode
            ]
        ];
    }

    $standards     = $signCodeConfig['identificationSignStandards'][$landUseClassification] ?? [];
    $attachedSigns = is_array($standards['wall'] ?? null) ? $standards['wall'] : null;
    $detachedSigns = is_array($standards['ground'] ?? null) ? $standards['ground'] : null;

    // Reconcile each resolved street frontage with its Table D-1 ground-sign tier.
    if ($detachedSigns !== null && is_array($detachedSigns['streetClassStandards'] ?? null)) {
        $detachedSigns['resolvedFrontageStandards'] = resolveFrontageSignStandards(
            $frontages,
            $detachedSigns['streetClassStandards'],
            (float)($detachedSigns['minimumSpacingFeet'] ?? 0),
            (string)($signCodeConfig['identificationSignStandards']['designReviewNotation'] ?? '')
        );
    }
    $requiredInputs = array_values(array_unique(array_merge(
        is_array($attachedSigns['requiredInputs'] ?? null) ? $attachedSigns['requiredInputs'] : [],
        is_array($detachedSigns['requiredInputs'] ?? null) ? $detachedSigns['requiredInputs'] : []
    )));

    $frontageStandardsResolved = true;
    if (is_array($detachedSigns['streetClassStandards'] ?? null)) {
        $resolvedFrontageStandards = is_array($detachedSigns['resolvedFrontageStandards'] ?? null)
            ? $detachedSigns['resolvedFrontageStandards']
            : [];
        $frontageStandardsResolved = $resolvedFrontageStandards !== [];

        foreach ($resolvedFrontageStandards as $resolvedFrontageStandard) {
            if (($resolvedFrontageStandard['status'] ?? 'research_required') !== 'resolved') {
                $frontageStandardsResolved = false;
                break;
            }
        }
    }

    $status = (
        $attachedSigns !== null
        && $detachedSigns !== null
        && $requiredInputs === []
        && $frontageStandardsResolved
    ) ? 'resolved' : 'research_required';

    return [
        'signCode' => [
            'jurisdiction'           => $signCodeConfig['jurisdiction']['label'] ?? $jurisdictionName,
            'zoningCode'            => $normalizedZoningCode,
            'landUseClassification' => $landUseClassification,
            'attachedSigns'         => $attachedSigns,
            'detachedSigns'         => $detachedSigns,
            'requiredInputs'        => $requiredInputs,
            'source'                => buildSignCodeSource($signCodeConfig),
            'citation'              => [
                'classification' => $landUseCitation,
                'attachedSigns'  => $attachedSigns['citation'] ?? null,
                'detachedSigns'  => $detachedSigns['citation'] ?? null
            ],
            'signAllowanceDisclaimer' => $signAllowanceDisclaimer,
            'signAllowanceDisclaimerSources' => buildSignCodeDisclaimerSources($signCodeConfig),
            'signAllowanceDisclaimerSource'  => formatDisclaimerSourceLabel(
                buildSignCodeDisclaimerSources($signCodeConfig)
            ),
            'status'                => $status
        ],
        'diagnostics' => [
            'status'                => $status,
            'configFile'            => $signCodeFile,
            'schemaVersion'         => $signCodeConfig['schemaVersion'] ?? null,
            'normalizedZoningCode'  => $normalizedZoningCode,
            'landUseClassification'=> $landUseClassification
        ]
    ];
}

/**
 * Match resolved roadway classifications and frontage lengths to Table D-1.
 * Local (LO) is supplied by the frontage resolver as the lowVolume road tier.
 */
function resolveFrontageSignStandards(
    array $frontages,
    array $streetClassStandards,
    float $minimumSpacingFeet,
    string $designReviewNotation
): array {
    $resolvedStandards = [];

    foreach ($frontages as $frontage) {
        if (!is_array($frontage)) {
            continue;
        }

        $streetName           = trim((string)($frontage['streetName'] ?? ''));
        $streetClassCode      = strtoupper(trim((string)($frontage['streetClassCode'] ?? '')));
        $streetClassification = trim((string)($frontage['streetClassification'] ?? ''));
        $roadTier             = trim((string)($frontage['roadTier'] ?? ''));
        $frontageLengthFeet   = is_numeric($frontage['frontageLengthFeet'] ?? null)
            ? (float)$frontage['frontageLengthFeet']
            : null;

        // Table D-1 uses primary/secondary tiers; frontage length reconciles the tier.
        $signClassification = $frontageLengthFeet !== null && $frontageLengthFeet <= 100
            ? 'secondary'
            : ($frontageLengthFeet !== null ? 'primary' : null);
        $standardKey = $roadTier !== '' && $signClassification !== null
            ? $roadTier . ucfirst($signClassification)
            : null;
        $matchedStandard = $standardKey !== null && is_array($streetClassStandards[$standardKey] ?? null)
            ? $streetClassStandards[$standardKey]
            : null;

        $resolvedStandards[] = [
            'streetName'             => $streetName !== '' ? $streetName : null,
            'frontageLengthFeet'     => $frontageLengthFeet,
            'streetClassCode'        => $streetClassCode !== '' ? $streetClassCode : null,
            'streetClassification'   => $streetClassification !== '' ? $streetClassification : null,
            'roadTier'               => $roadTier !== '' ? $roadTier : null,
            'signClassification'     => $signClassification,
            'tableStandardKey'       => $standardKey,
            'asOfRight'              => $matchedStandard['asOfRight'] ?? null,
            'designReviewMaximum'    => $matchedStandard['designReviewMaximum'] ?? null,
            'minimumSpacingFeet'     => $minimumSpacingFeet > 0 ? $minimumSpacingFeet : null,
            'designReviewNotation'   => $designReviewNotation !== '' ? $designReviewNotation : null,
            'citation'               => '705.D.1, Table D-1',
            'status'                 => $matchedStandard !== null ? 'resolved' : 'research_required'
        ];
    }

    return $resolvedStandards;
}

function buildSignCodeSource(array $signCodeConfig): array {
    return [
        'title'              => $signCodeConfig['ordinance']['title'] ?? null,
        'authority'          => $signCodeConfig['ordinance']['authority'] ?? null,
        'codeReference'      => $signCodeConfig['ordinance']['codeReference'] ?? null,
        'officialSourceUrl'  => $signCodeConfig['ordinance']['officialSourceUrl'] ?? null,
        'currentThroughDate' => $signCodeConfig['ordinance']['currentThroughDate'] ?? null,
        'schemaVersion'      => $signCodeConfig['schemaVersion'] ?? null
    ];
}

function buildSignCodeDisclaimerSources(array $signCodeConfig): array {
    $source = buildSignCodeSource($signCodeConfig);
    $labelParts = array_values(array_filter([
        trim((string)($source['authority'] ?? '')),
        trim((string)($source['codeReference'] ?? ''))
    ], static function (string $value): bool {
        return $value !== '';
    }));

    $label = implode(' — ', $labelParts);
    if ($label === '') {
        $label = trim((string)($source['title'] ?? ''));
    }

    if ($label === '') {
        return [];
    }

    return [[
        'label' => $label,
        'url'   => !empty($source['officialSourceUrl']) ? (string)$source['officialSourceUrl'] : null
    ]];
}

function normalizeDisclaimerSources(array $sources): array {
    $normalized = [];
    $seen = [];

    foreach ($sources as $source) {
        if (is_string($source)) {
            $label = trim($source);
            $url = null;
        } elseif (is_array($source)) {
            $label = trim((string)($source['label'] ?? $source['source'] ?? $source['title'] ?? $source['name'] ?? ''));
            $url = trim((string)($source['url'] ?? $source['officialSourceUrl'] ?? ''));
            $url = $url !== '' ? $url : null;
        } else {
            continue;
        }

        if ($label === '') {
            continue;
        }

        $key = strtolower($label . '|' . (string)$url);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = [
            'label' => $label,
            'url'   => $url
        ];
    }

    return $normalized;
}

function formatDisclaimerSourceLabel(array $sources): ?string {
    $sources = normalizeDisclaimerSources($sources);
    $labels = [];

    foreach ($sources as $source) {
        $label = trim((string)($source['label'] ?? ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return $labels !== [] ? implode('; ', $labels) : null;
}

function buildLocationDisclaimers(array $p): array {
    $frontages = is_array($p['frontages'] ?? null) ? $p['frontages'] : [];
    $measurementSources = [];

    foreach ($frontages as $frontage) {
        if (!is_array($frontage)) {
            continue;
        }

        foreach (['parcelSource', 'streetSource'] as $sourceKey) {
            $sourceName = trim((string)($frontage[$sourceKey] ?? ''));
            if ($sourceName !== '') {
                $measurementSources[] = ['label' => $sourceName, 'url' => null];
            }
        }
    }

    $measurementSources = normalizeDisclaimerSources($measurementSources);
    $signCode = is_array($p['signCode'] ?? null) ? $p['signCode'] : [];
    $signSources = normalizeDisclaimerSources(
        is_array($signCode['signAllowanceDisclaimerSources'] ?? null)
            ? $signCode['signAllowanceDisclaimerSources']
            : []
    );

    $specialDesignations = is_array($p['specialDesignations'] ?? null)
        ? $p['specialDesignations']
        : [];
    $specialSources = [];

    foreach ($specialDesignations as $designationKey => $designation) {
        if (in_array($designationKey, ['isComplete', 'disclaimers', 'errorMessage'], true) || !is_array($designation)) {
            continue;
        }

        $sourceName = trim((string)($designation['source'] ?? ''));
        if ($sourceName !== '') {
            $specialSources[] = ['label' => $sourceName, 'url' => null];
        }
    }
    $specialSources = normalizeDisclaimerSources($specialSources);

    $configuredSpecialDisclaimers = is_array($specialDesignations['disclaimers'] ?? null)
        ? $specialDesignations['disclaimers']
        : [];
    $normalizedSpecialDisclaimers = [];

    foreach ($configuredSpecialDisclaimers as $disclaimer) {
        if (is_string($disclaimer)) {
            $normalizedSpecialDisclaimers[] = [
                'text'        => $disclaimer,
                'sources'     => $specialSources,
                'sourceLabel' => formatDisclaimerSourceLabel($specialSources)
            ];
            continue;
        }

        if (!is_array($disclaimer)) {
            continue;
        }

        $disclaimerSources = [];
        if (isset($disclaimer['sources']) && is_array($disclaimer['sources'])) {
            $disclaimerSources = normalizeDisclaimerSources($disclaimer['sources']);
        } elseif (isset($disclaimer['source'])) {
            $disclaimerSources = normalizeDisclaimerSources([$disclaimer['source']]);
        }

        if ($disclaimerSources === []) {
            $disclaimerSources = $specialSources;
        }

        $normalizedDisclaimer = $disclaimer;
        $normalizedDisclaimer['sources'] = $disclaimerSources;
        $normalizedDisclaimer['sourceLabel'] = formatDisclaimerSourceLabel($disclaimerSources);
        $normalizedSpecialDisclaimers[] = $normalizedDisclaimer;
    }

    $propertyOverviewSources = normalizeDisclaimerSources([
        [
            'label' => 'Maricopa County Assessor',
            'url'   => null
        ]
    ]);

    return [
        'propertyOverview' => [
            'text' => 'Property information shown is based on the parcel and assessor records returned by the address-check workflow.',
            'sources' => $propertyOverviewSources,
            'sourceLabel' => formatDisclaimerSourceLabel($propertyOverviewSources)
        ],
        'measurement' => [
            'text' => 'Frontage measurements and street classifications are GIS-derived and should be field-verified before final design, fabrication, or permit submittal.',
            'sources' => $measurementSources,
            'sourceLabel' => formatDisclaimerSourceLabel($measurementSources)
        ],
        'signAllowance' => [
            'text' => $signCode['signAllowanceDisclaimer'] ?? null,
            'sources' => $signSources,
            'sourceLabel' => formatDisclaimerSourceLabel($signSources)
        ],
        'specialDesignations' => [
            'items' => $normalizedSpecialDisclaimers,
            'sources' => $specialSources,
            'sourceLabel' => formatDisclaimerSourceLabel($specialSources)
        ]
    ];
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
                'parcelGeometry'     => is_array($p['parcelGeometry'] ?? null)
                    ? $p['parcelGeometry']
                    : null,
                'frontages'          => is_array($p['frontages'] ?? null)
                    ? $p['frontages']
                    : [],
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
        'signCode'           => is_array($p['signCode'] ?? null)
            ? $p['signCode']
            : null,
        'specialDesignations' => is_array($p['specialDesignations'] ?? null)
            ? $p['specialDesignations']
            : null,
        'disclaimers'        => buildLocationDisclaimers($p),
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

function resolveLocationSpecialDesignations(
    string $baseDirectory,
    string $stateSlug,
    string $countySlug,
    string $jurisdictionSlug,
    string $jurisdictionName,
    ?float $latitude,
    ?float $longitude
): ?array {
    // Resolve jurisdiction artifact (regional path first)
    $configCandidates = [
        $baseDirectory . "/data/authoritative/{$stateSlug}/{$countySlug}/jurisdictions/{$jurisdictionSlug}/specialDesignations.json",
        $baseDirectory . "/../data/authoritative/{$stateSlug}/{$countySlug}/jurisdictions/{$jurisdictionSlug}/specialDesignations.json",
        $baseDirectory . '/data/authoritative/jurisdictions/' . $jurisdictionSlug . '/specialDesignations.json',
        $baseDirectory . '/../data/authoritative/jurisdictions/' . $jurisdictionSlug . '/specialDesignations.json'
    ];

    $configFile = null;
    foreach ($configCandidates as $candidateFile) {
        if (is_file($candidateFile)) {
            $configFile = $candidateFile;
            break;
        }
    }

    // No special-designation configuration for this jurisdiction
    if ($configFile === null) {
        return null;
    }

    $config = json_decode((string)file_get_contents($configFile), true);
    if (!is_array($config)) {
        return [
            'isComplete'   => false,
            'disclaimers'  => [],
            'errorMessage' => 'The jurisdiction special-designation configuration is invalid JSON.'
        ];
    }

    $configSlug = strtolower(trim((string)($config['jurisdiction']['slug'] ?? '')));
    if ($configSlug !== '' && $configSlug !== strtolower(trim($jurisdictionSlug))) {
        return [
            'isComplete'   => false,
            'disclaimers'  => is_array($config['disclaimers'] ?? null) ? array_values($config['disclaimers']) : [],
            'errorMessage' => 'The special-designation configuration does not match the resolved jurisdiction.'
        ];
    }

    $designationConfigs = is_array($config['specialDesignations'] ?? null)
        ? $config['specialDesignations']
        : [];
    $disclaimers = is_array($config['disclaimers'] ?? null)
        ? array_values($config['disclaimers'])
        : [];

    $result = [
        'isComplete'   => true,
        'disclaimers'  => $disclaimers,
        'errorMessage' => null
    ];

    foreach ($designationConfigs as $designationKey => $designationConfig) {
        if (!is_array($designationConfig)) {
            $result[$designationKey] = [
                'label'         => (string)$designationKey,
                'determination' => 'notAvailable',
                'status'        => 'notAvailable',
                'errorMessage'  => 'The designation configuration is invalid.',
                'matches'       => [],
                'source'        => null,
                'checkedAt'     => time()
            ];
            continue;
        }

        $result[$designationKey] = resolveConfiguredSpecialDesignation(
            $designationConfig,
            $latitude,
            $longitude
        );
    }

    // Complete means every configured designation has a definitive result
    foreach ($designationConfigs as $designationKey => $_designationConfig) {
        $determination = $result[$designationKey]['determination'] ?? null;
        if (!in_array($determination, ['yes', 'no', 'notAvailable'], true)) {
            $result['isComplete'] = false;
            break;
        }
    }

    return $result;
}

function resolveConfiguredSpecialDesignation(
    array $designationConfig,
    ?float $latitude,
    ?float $longitude
): array {
    $checkedAt = time();
    $label     = trim((string)($designationConfig['label'] ?? 'Special Designation'));
    $service   = is_array($designationConfig['service'] ?? null) ? $designationConfig['service'] : [];
    $query     = is_array($designationConfig['query'] ?? null) ? $designationConfig['query'] : [];
    $mapping   = is_array($designationConfig['fieldMapping'] ?? null) ? $designationConfig['fieldMapping'] : [];

    $serviceUrl = trim((string)($service['url'] ?? ''));
    $sourceName = trim((string)($service['source'] ?? ''));

    if ($latitude === null || $longitude === null) {
        return buildUnavailableSpecialDesignation(
            $label,
            $sourceName,
            'Special-designation lookup requires coordinates.',
            $checkedAt
        );
    }

    if ($serviceUrl === '') {
        return buildUnavailableSpecialDesignation(
            $label,
            $sourceName,
            'No authoritative service URL is configured for this designation.',
            $checkedAt
        );
    }

    $outFields = $query['outFields'] ?? ['*'];
    if (is_array($outFields)) {
        $outFields = implode(',', $outFields);
    }

    $params = [
        'where'          => (string)($query['where'] ?? '1=1'),
        'geometry'       => $longitude . ',' . $latitude,
        'geometryType'   => (string)($query['geometryType'] ?? 'esriGeometryPoint'),
        'spatialRel'     => (string)($query['spatialRel'] ?? 'esriSpatialRelIntersects'),
        'inSR'           => (string)($query['inSR'] ?? 4326),
        'outSR'          => (string)($query['outSR'] ?? 4326),
        'outFields'      => (string)$outFields,
        'returnGeometry' => !empty($query['returnGeometry']) ? 'true' : 'false',
        'f'              => (string)($query['format'] ?? 'json')
    ];

    $queryUrl = rtrim($serviceUrl, '/') . '/query?'
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $queryResult = httpGetJsonDetailed($queryUrl, (int)($query['timeoutSeconds'] ?? 8));
    $queryData = is_array($queryResult['data'] ?? null) ? $queryResult['data'] : [];

    $querySucceeded = (
        !empty($queryResult['success'])
        && empty($queryData['error'])
        && isset($queryData['features'])
        && is_array($queryData['features'])
    );

    if (!$querySucceeded) {
        $arcGisMessage = is_array($queryData['error'] ?? null)
            ? ($queryData['error']['message'] ?? null)
            : null;
        $curlError = trim((string)($queryResult['curlError'] ?? ''));
        $errorMessage = $arcGisMessage
            ?: ($curlError !== '' ? $curlError : 'HTTP ' . (string)($queryResult['httpCode'] ?? 0));

        return buildUnavailableSpecialDesignation(
            $label,
            $sourceName,
            $errorMessage,
            $checkedAt
        );
    }

    $matches = [];
    foreach ($queryData['features'] as $feature) {
        $attributes = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
        if ($attributes === []) {
            continue;
        }

        $match = [];
        foreach ($mapping as $outputField => $sourceField) {
            $match[(string)$outputField] = resolveSpecialDesignationAttribute(
                $attributes,
                (string)$sourceField
            );
        }
        $match['rawAttributes'] = $attributes;
        $matches[] = $match;
    }

    return [
        'label'         => $label,
        'determination' => $matches !== [] ? 'yes' : 'no',
        'status'        => $matches !== [] ? 'identified' : 'noneIdentified',
        'errorMessage'  => null,
        'matches'       => $matches,
        'source'        => $sourceName !== '' ? $sourceName : null,
        'checkedAt'     => $checkedAt
    ];
}

function buildUnavailableSpecialDesignation(
    string $label,
    string $sourceName,
    string $errorMessage,
    int $checkedAt
): array {
    return [
        'label'         => $label,
        'determination' => 'notAvailable',
        'status'        => 'notAvailable',
        'errorMessage'  => $errorMessage,
        'matches'       => [],
        'source'        => $sourceName !== '' ? $sourceName : null,
        'checkedAt'     => $checkedAt
    ];
}

function resolveSpecialDesignationAttribute(array $attributes, string $sourceField) {
    foreach ($attributes as $key => $value) {
        if (strcasecmp((string)$key, $sourceField) === 0) {
            return $value;
        }
    }
    return null;
}

function resolveLocationFrontages(string $parcelNumber, string $jurisdiction, int $verifiedAt): array {
    $result = [
        'frontages'       => [],
        'parcelGeometry'  => null,
        'diagnostics' => [
            'status'       => 'not_attempted',
            'parcelQuery'  => null,
            'streetQuery'  => null,
            'phoenixQuery' => null,
            'message'      => null
        ]
    ];

    $apn = preg_replace('/[^A-Za-z0-9]/', '', $parcelNumber);
    if ($apn === '') {
        $result['diagnostics']['status'] = 'parcel_number_missing';
        $result['diagnostics']['message'] = 'Frontage resolution requires a parcel number.';
        return $result;
    }

    $parcelEndpoint = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/'
        . 'MaricopaDynamicQueryService/MapServer/3/query';
    $parcelWhere = "APN='" . str_replace("'", "''", $apn) . "'";
    $parcelUrl = $parcelEndpoint . '?' . http_build_query([
        'f'              => 'json',
        'where'          => $parcelWhere,
        'outFields'      => '*',
        'returnGeometry' => 'true',
        'outSR'          => '2223'
    ]);
    $parcelResponse = httpGetJsonDetailed($parcelUrl, 12);
    $parcelFeatures = $parcelResponse['data']['features'] ?? [];
    $result['diagnostics']['parcelQuery'] = [
        'httpCode'     => $parcelResponse['httpCode'],
        'curlError'    => $parcelResponse['curlError'],
        'arcGisError'  => $parcelResponse['data']['error'] ?? null,
        'featureCount' => count($parcelFeatures)
    ];

    if ($parcelFeatures === []) {
        $result['diagnostics']['status'] = 'parcel_geometry_unresolved';
        $result['diagnostics']['message'] = 'The parcel polygon could not be resolved.';
        return $result;
    }

    $rings = $parcelFeatures[0]['geometry']['rings'] ?? [];
    $extent = calculateGeometryExtent($rings);
    if ($rings === [] || $extent === null) {
        $result['diagnostics']['status'] = 'parcel_geometry_invalid';
        $result['diagnostics']['message'] = 'The parcel response did not contain measurable rings.';
        return $result;
    }

    $result['parcelGeometry'] = [
        'geometryType' => 'polygon',
        'spatialReference' => [
            'wkid'  => 2223,
            'units' => 'feet'
        ],
        'rings'  => normalizeGeometryRings($rings),
        'bounds' => normalizeGeometryBounds($extent)
    ];

    $streetEndpoint = 'https://services.arcgis.com/ykpntM6e3tHvzKRJ/arcgis/rest/services/'
        . 'Maricopa_County_Streets/FeatureServer/0/query';
    $streetEnvelope = [
        'xmin' => $extent['xmin'] - 125,
        'ymin' => $extent['ymin'] - 125,
        'xmax' => $extent['xmax'] + 125,
        'ymax' => $extent['ymax'] + 125,
        'spatialReference' => ['wkid' => 2223]
    ];
    $streetUrl = $streetEndpoint . '?' . http_build_query([
        'f'                => 'json',
        'where'            => 'IsBuilt=1 AND IsPublic=1',
        'geometry'         => json_encode($streetEnvelope),
        'geometryType'     => 'esriGeometryEnvelope',
        'inSR'             => '2223',
        'outSR'            => '2223',
        'spatialRel'       => 'esriSpatialRelIntersects',
        'outFields'        => '*',
        'returnGeometry'   => 'true'
    ]);
    $streetResponse = httpGetJsonDetailed($streetUrl, 12);
    $streetFeatures = $streetResponse['data']['features'] ?? [];
    $result['diagnostics']['streetQuery'] = [
        'httpCode'     => $streetResponse['httpCode'],
        'curlError'    => $streetResponse['curlError'],
        'arcGisError'  => $streetResponse['data']['error'] ?? null,
        'featureCount' => count($streetFeatures)
    ];

    $frontages = calculateParcelFrontages($rings, $streetFeatures, $verifiedAt);
    if ($frontages === []) {
        $result['diagnostics']['status'] = 'no_frontage_identified';
        $result['diagnostics']['message'] = 'No qualifying public-street frontage was identified.';
        return $result;
    }

    if (strtolower(trim($jurisdiction)) === 'phoenix') {
        $phoenixResult = enrichPhoenixFrontages($frontages, $streetEnvelope);
        $frontages = $phoenixResult['frontages'];
        $result['diagnostics']['phoenixQuery'] = $phoenixResult['diagnostics'];
    }

    $result['frontages'] = array_values($frontages);
    $result['diagnostics']['status'] = 'resolved';
    return $result;
}

function calculateGeometryExtent(array $rings): ?array {
    $xs = [];
    $ys = [];
    foreach ($rings as $ring) {
        foreach ($ring as $point) {
            if (isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $xs[] = (float)$point[0];
                $ys[] = (float)$point[1];
            }
        }
    }
    if ($xs === [] || $ys === []) {
        return null;
    }
    return ['xmin' => min($xs), 'ymin' => min($ys), 'xmax' => max($xs), 'ymax' => max($ys)];
}

function normalizeGeometryRings(array $rings): array {
    $normalizedRings = [];
    foreach ($rings as $ring) {
        $normalizedRing = [];
        foreach ($ring as $point) {
            if (!isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }
            $normalizedRing[] = [round((float)$point[0], 3), round((float)$point[1], 3)];
        }
        if ($normalizedRing !== []) {
            $normalizedRings[] = $normalizedRing;
        }
    }
    return $normalizedRings;
}

function normalizeGeometryBounds(array $extent): array {
    return [
        'xmin' => round((float)$extent['xmin'], 3),
        'ymin' => round((float)$extent['ymin'], 3),
        'xmax' => round((float)$extent['xmax'], 3),
        'ymax' => round((float)$extent['ymax'], 3)
    ];
}

function calculateParcelFrontages(array $rings, array $streetFeatures, int $verifiedAt): array {
    $groups = [];
    foreach ($rings as $ringIndex => $ring) {
        $pointCount = count($ring);
        for ($index = 1; $index < $pointCount; $index++) {
            $start = $ring[$index - 1];
            $end = $ring[$index];
            $edgeLength = pointDistance($start, $end);
            if ($edgeLength < 1) {
                continue;
            }

            $best = null;
            foreach ($streetFeatures as $feature) {
                $attributes = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
                $streetName = resolveStreetName($attributes);
                if ($streetName === '') {
                    continue;
                }
                foreach (($feature['geometry']['paths'] ?? []) as $path) {
                    for ($pathIndex = 1, $pathCount = count($path); $pathIndex < $pathCount; $pathIndex++) {
                        $lineStart = $path[$pathIndex - 1];
                        $lineEnd = $path[$pathIndex];
                        $alignment = segmentAlignment($start, $end, $lineStart, $lineEnd);
                        if ($alignment < 0.75) {
                            continue;
                        }
                        $midpoint = [($start[0] + $end[0]) / 2, ($start[1] + $end[1]) / 2];
                        $distance = pointToSegmentDistance($midpoint, $lineStart, $lineEnd);
                        if ($distance > 100 || ($best !== null && $distance >= $best['distance'])) {
                            continue;
                        }
                        $best = [
                            'distance'   => $distance,
                            'streetName' => $streetName,
                            'attributes' => $attributes
                        ];
                    }
                }
            }

            if ($best === null) {
                continue;
            }
            $key = normalizeStreetName($best['streetName']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'streetName' => $best['streetName'],
                    'length'     => 0.0,
                    'segments'   => [],
                    'attributes' => $best['attributes'],
                    'distance'   => $best['distance']
                ];
            }
            $groups[$key]['length'] += $edgeLength;
            $groups[$key]['segments'][] = [
                'ringIndex'    => $ringIndex,
                'segmentIndex' => $index - 1,
                'start'        => [round((float)$start[0], 3), round((float)$start[1], 3)],
                'end'          => [round((float)$end[0], 3), round((float)$end[1], 3)],
                'lengthFeet'   => round($edgeLength, 2)
            ];
            $groups[$key]['distance'] = min($groups[$key]['distance'], $best['distance']);
        }
    }

    $frontages = [];
    foreach ($groups as $group) {
        if ($group['length'] < 8) {
            continue;
        }
        $classCode = resolveAttribute($group['attributes'], ['STREETCLASS', 'ST_CLASS', 'CLASS', 'FCC', 'ROADCLASS']);
        $manualReview = $group['distance'] > 75;
        $frontages[] = [
            'streetName'           => $group['streetName'],
            'frontageLengthFeet'   => round($group['length'], 2),
            'parcelSegments'       => $group['segments'],
            'frontageMethod'       => 'countyParcelBoundaryToCountyStreetCenterline',
            'streetClassCode'      => $classCode,
            'streetClassification' => null,
            'roadTier'             => null,
            'parcelSource'         => 'Maricopa County Assessor Parcel GIS',
            'streetSource'         => 'Maricopa County Street Centerlines',
            'verificationStatus'   => $manualReview ? 'review_required' : 'gis_calculated',
            'requiresManualReview' => $manualReview,
            'verifiedAt'           => $verifiedAt
        ];
    }
    return $frontages;
}

function enrichPhoenixFrontages(array $frontages, array $envelope): array {
    $endpoint = 'https://maps.phoenix.gov/pub/rest/services/Public/'
        . 'STR_StreetCenterline/MapServer/0/query';
    $url = $endpoint . '?' . http_build_query([
        'f'              => 'json',
        'where'          => '1=1',
        'geometry'       => json_encode($envelope),
        'geometryType'   => 'esriGeometryEnvelope',
        'inSR'           => '2223',
        'spatialRel'     => 'esriSpatialRelIntersects',
        'outFields'      => '*',
        'returnGeometry' => 'false'
    ]);
    $response = httpGetJsonDetailed($url, 12);
    $features = $response['data']['features'] ?? [];
    $classMap = [
        'FR' => ['Freeway', 'freeway'],
        'EX' => ['Expressway', 'freeway'],
        'MA' => ['Major Arterial', 'highVolume'],
        'AR' => ['Arterial', 'highVolume'],
        'AT' => ['Arterial', 'highVolume'],
        'CO' => ['Collector', 'highVolume'],
        'MC' => ['Minor Collector', 'lowVolume'],
        'LO' => ['Local', 'lowVolume']
    ];

    foreach ($frontages as $index => $frontage) {
        $targetName = normalizeStreetName((string)$frontage['streetName']);
        foreach ($features as $feature) {
            $attributes = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
            if (normalizeStreetName(resolveStreetName($attributes)) !== $targetName) {
                continue;
            }
            $code = strtoupper(resolveAttribute($attributes, [
                'STREETCLASS', 'ST_CLASS', 'CLASS_CODE', 'CLASS', 'ROAD_CLASS', 'STR_CLASS'
            ]));
            if (!isset($classMap[$code])) {
                continue;
            }
            $frontages[$index]['streetClassCode'] = $code;
            $frontages[$index]['streetClassification'] = $classMap[$code][0];
            $frontages[$index]['roadTier'] = $classMap[$code][1];
            $frontages[$index]['streetSource'] = 'City of Phoenix Street Centerline GIS';
            break;
        }
    }

    return [
        'frontages' => $frontages,
        'diagnostics' => [
            'httpCode'     => $response['httpCode'],
            'curlError'    => $response['curlError'],
            'arcGisError'  => $response['data']['error'] ?? null,
            'featureCount' => count($features)
        ]
    ];
}

function resolveStreetName(array $attributes): string {
    $streetName = resolveAttribute($attributes, [
        'ANNAME', 'FULLNAME', 'FULL_NAME', 'STREETNAME', 'STREET_NAME',
        'STR_NAME', 'NAME', 'ROADNAME'
    ]);

    if ($streetName !== '') {
        return $streetName;
    }

    $streetParts = [
        resolveAttribute($attributes, ['StDir']),
        resolveAttribute($attributes, ['StName']),
        resolveAttribute($attributes, ['StType']),
        resolveAttribute($attributes, ['StSufx'])
    ];

    $streetParts = array_values(array_filter(
        array_map('trim', $streetParts),
        static function (string $streetPart): bool {
            return $streetPart !== '';
        }
    ));

    return implode(' ', $streetParts);
}

function resolveAttribute(array $attributes, array $candidates): string {
    foreach ($candidates as $candidate) {
        foreach ($attributes as $key => $value) {
            if (strcasecmp((string)$key, (string)$candidate) === 0 && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
    }
    return '';
}

function normalizeStreetName(string $streetName): string {
    $normalized = strtoupper(trim($streetName));
    $normalized = preg_replace('/[^A-Z0-9]+/', ' ', $normalized);
    return trim(preg_replace('/\s+/', ' ', $normalized));
}

function pointDistance(array $first, array $second): float {
    return hypot((float)$second[0] - (float)$first[0], (float)$second[1] - (float)$first[1]);
}

function segmentAlignment(array $a1, array $a2, array $b1, array $b2): float {
    $ax = (float)$a2[0] - (float)$a1[0];
    $ay = (float)$a2[1] - (float)$a1[1];
    $bx = (float)$b2[0] - (float)$b1[0];
    $by = (float)$b2[1] - (float)$b1[1];
    $denominator = hypot($ax, $ay) * hypot($bx, $by);
    return $denominator > 0 ? abs(($ax * $bx + $ay * $by) / $denominator) : 0.0;
}

function pointToSegmentDistance(array $point, array $start, array $end): float {
    $dx = (float)$end[0] - (float)$start[0];
    $dy = (float)$end[1] - (float)$start[1];
    if ($dx === 0.0 && $dy === 0.0) {
        return pointDistance($point, $start);
    }
    $ratio = (((float)$point[0] - (float)$start[0]) * $dx
        + ((float)$point[1] - (float)$start[1]) * $dy) / ($dx * $dx + $dy * $dy);
    $ratio = max(0.0, min(1.0, $ratio));
    $projection = [(float)$start[0] + $ratio * $dx, (float)$start[1] + $ratio * $dy];
    return pointDistance($point, $projection);
}

#endregion