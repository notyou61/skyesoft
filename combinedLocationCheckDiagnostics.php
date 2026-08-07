<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — combinedLocationCheckDiagnostics.php
//  Version: 3.0.0
//  Last Updated: 2026-08-07
// ======================================================================

#region SECTION 0 — Environment Bootstrap, Diagnostic Headers & Config

// Force JSON response header
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', '1');

$executionTime = time();
$isoTimestamp  = date('c', $executionTime);

// Load local environment via Skyesoft envLoader
if (file_exists(__DIR__ . '/utils/envLoader.php')) {
    require_once __DIR__ . '/utils/envLoader.php';
    if (function_exists('skyesoftLoadEnv')) {
        skyesoftLoadEnv();
    }
}

// Key resolution matching Google Diagnostics Tool v3 logic
$googleMapsApiKey = '';
if (function_exists('skyesoftGetEnv')) {
    $googleMapsApiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY') ?: skyesoftGetEnv('GOOGLE_MAPS_API_KEY');
}
if (empty($googleMapsApiKey)) {
    $googleMapsApiKey = getenv('GOOGLE_MAPS_BACKEND_API_KEY') 
        ?: getenv('GOOGLE_MAPS_API_KEY') 
        ?: getenv('GOOGLE_MAPS_PLACE_ID_API_KEY') 
        ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') 
        ?: ($_SERVER['GOOGLE_MAPS_API_KEY'] ?? $_ENV['GOOGLE_MAPS_API_KEY'] ?? '');
}

#endregion

#region SECTION 1 — Request Ingestion & Data Cleansing

// Hardcoded target test address as requested
$hardcodedAddress = '738 S Perry Ln, Tempe, AZ 85288, USA';

$rawInput = file_get_contents('php://input');
$input    = json_decode((string)$rawInput, true);

if (!is_array($input)) {
    $input = $_GET;
}

$dataObj  = $input['data'] ?? [];
$location = $input['location'] ?? $dataObj['location'] ?? $input;
$parcel   = $location['parcel'] ?? $dataObj['location']['parcelDetails'][0] ?? $dataObj['parcel'] ?? [];

$activitySessionId = $input['activitySessionId'] ?? $dataObj['activitySessionId'] ?? bin2hex(random_bytes(16));

// Override target address parameters with hardcoded target
$address      = $hardcodedAddress;
$city         = 'Tempe';
$state        = 'AZ';
$zip          = '85288';
$cityStateZip = 'Tempe, AZ 85288';

// Strip suite/unit designators
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
$locationJurisdiction = 'Tempe';
$locationCounty       = 'Maricopa';

$fullAddress      = $address;
$fullCleanAddress = $cleanAddress;

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
$debugLogs               = [];

// Track resolved Google API status steps
$debugLogs['key_resolved'] = !empty($googleMapsApiKey);
$debugLogs['key_preview']  = !empty($googleMapsApiKey) ? substr($googleMapsApiKey, 0, 8) . '...' : 'MISSING';

// Option A: Pre-existing Google Place ID lookup
if ($locationPlaceId && $googleMapsApiKey) {
    $placeDetailsUrl = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $locationPlaceId,
        'fields'   => 'geometry,formatted_address',
        'key'      => $googleMapsApiKey
    ]);

    $placeRes = httpGetJson($placeDetailsUrl);
    $debugLogs['option_a_place_details'] = $placeRes['status'] ?? 'HTTP_FAILED';
    if (($placeRes['status'] ?? '') === 'OK' && isset($placeRes['result']['geometry']['location'])) {
        $lat                     = (float)$placeRes['result']['geometry']['location']['lat'];
        $lng                     = (float)$placeRes['result']['geometry']['location']['lng'];
        $locationResolvedAddress = $placeRes['result']['formatted_address'] ?? $fullAddress;
    }
}

// Option B: Query Google Geocoding API using cleaned street address
if (!$locationPlaceId && $fullCleanAddress && $googleMapsApiKey) {

    // Stage 1: Geocoding API
    $googleGeocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address' => $fullCleanAddress,
        'key'     => $googleMapsApiKey
    ]);

    $geoRes = httpGetJson($googleGeocodeUrl);
    $debugLogs['option_b_stage_1_geocode_status'] = $geoRes['status'] ?? 'HTTP_FAILED';

    if (($geoRes['status'] ?? '') === 'OK' && !empty($geoRes['results'][0])) {
        $firstResult             = $geoRes['results'][0];
        $locationPlaceId         = $firstResult['place_id'] ?? null;
        $lat                     = $lat ?? (float)($firstResult['geometry']['location']['lat'] ?? null);
        $lng                     = $lng ?? (float)($firstResult['geometry']['location']['lng'] ?? null);
        $locationResolvedAddress = $firstResult['formatted_address'] ?? $fullAddress;
    }

    // Stage 2: Fallback to Places Find Place From Text
    if (!$locationPlaceId) {
        $placesQueryAddress = cleanAddressForPlaces($fullCleanAddress);

        $findPlaceUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' . http_build_query([
            'input'     => $placesQueryAddress,
            'inputtype' => 'textquery',
            'fields'    => 'place_id,formatted_address,geometry',
            'key'       => $googleMapsApiKey
        ]);

        $findRes = httpGetJson($findPlaceUrl);
        $debugLogs['option_b_stage_2_find_place_status'] = $findRes['status'] ?? 'HTTP_FAILED';

        if (($findRes['status'] ?? '') === 'OK' && !empty($findRes['candidates'][0])) {
            $candidate               = $findRes['candidates'][0];
            $locationPlaceId         = $candidate['place_id'] ?? null;
            $lat                     = $lat ?? (float)($candidate['geometry']['location']['lat'] ?? null);
            $lng                     = $lng ?? (float)($candidate['geometry']['location']['lng'] ?? null);
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
    $debugLogs['option_c_reverse_geocode_status'] = $revRes['status'] ?? 'HTTP_FAILED';

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
        'diagnostics'       => $debugLogs,
        'issues'            => $issues
    ], JSON_PRETTY_PRINT);
    exit;
}

#endregion

#region SECTION 3 — Registry Configuration & Short-Circuit Check

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

    // Primary ~2m point query
    $parcelRes = httpGetJson($queryEnvelope($lng, $lat, 0.00002), 8);
    
    // Fallback ~15m buffer if point intersects roadway or boundary seam
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

$zoningCode  = 'UNKNOWN';
$zoningDesc  = 'N/A';
$sourceLayer = 'Unmapped Spatial Layer';

if (is_array($matchedConfig) && isset($matchedConfig['service']['serviceUrl'])) {
    $svc  = $matchedConfig['service'];
    $qry  = $matchedConfig['query'] ?? [];
    $fm   = $matchedConfig['fieldMapping'] ?? [];
    $norm = $matchedConfig['normalization'] ?? [];

    $endpoint = rtrim((string)$svc['serviceUrl'], '/') . '/' . ($svc['layerId'] ?? 0) . '/query';

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
        return httpGetJson($endpoint . '?' . http_build_query($queryParams), (int)($qry['timeoutSeconds'] ?? 8));
    };

    // Primary Point lookup
    $pointGeom  = json_encode(['x' => $lng, 'y' => $lat, 'spatialReference' => ['wkid' => 4326]]);
    $zoningData = $executeZoningQuery($pointGeom, 'esriGeometryPoint');
    $features   = $zoningData['features'] ?? [];

    // Fallback spatial envelope
    if (empty($features)) {
        $delta   = 0.00015;
        $envGeom = json_encode([
            'xmin' => $lng - $delta,
            'ymin' => $lat - $delta,
            'xmax' => $lng + $delta,
            'ymax' => $lat + $delta,
            'spatialReference' => ['wkid' => 4326]
        ]);
        $zoningData = $executeZoningQuery($envGeom, 'esriGeometryEnvelope');
        $features   = $zoningData['features'] ?? [];
    }

    if (!empty($features)) {
        $attrs       = $features[0]['attributes'] ?? [];
        $sourceLayer = ($svc['provider'] ?? 'City GIS') . ' Community Development Department';

        $codeCandidates = array_merge(
            $fm['zoningCode'] ?? [], 
            ['ZONING', 'ZONING_CODE', 'LABEL', 'LABEL1', 'ZONE', 'ZONE_CODE', 'DISTRICT', 'ZONING_DISTRICT']
        );
        
        $descCandidates = array_merge(
            $fm['zoningDescription'] ?? [], 
            ['GEN_ZONE', 'DESCRIPTION', 'ZONING_DESC', 'ZONE_DESC', 'SHORT_DESC', 'FULL_ZONING']
        );

        $zCode = resolveMappedField($attrs, $codeCandidates, $norm);
        if ($zCode !== null) {
            $zoningCode = $zCode;
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

echo json_encode([
    'success'           => true,
    'status'            => $hasZoningMatch ? 'resolved' : 'zoning_unmapped',
    'activitySessionId' => $activitySessionId,
    'diagnostics'       => $debugLogs,
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
], JSON_PRETTY_PRINT);

#endregion

#region SECTION 7 — Helper Routines

function formatLocationResponse(array $p): array {
    $cleanApn = $p['parcelNumber'] ? preg_replace('/\D/', '', (string)$p['parcelNumber']) : null;

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
        CURLOPT_USERAGENT      => 'Skyesoft-LocationCheck/2.1',
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