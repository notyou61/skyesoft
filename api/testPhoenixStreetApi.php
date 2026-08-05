<?php
// ========================================================================
//  Skyesoft — testPhoenixStreetApi.php
//  Tests Phoenix geocoding and street-centerline ArcGIS REST services
//  Codex-Governed Module • PHP 8.3
//  Implements: Structural Code Standard
// ========================================================================

#region SECTION I — Metadata & Error Handling

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * Return a structured JSON failure and stop execution.
 */
function fail(string $msg, int $statusCode = 500, array $details = []): never
{
    http_response_code($statusCode);

    echo json_encode([
        'success' => false,
        'error' => '❌ ' . $msg,
        'details' => $details
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

set_exception_handler(function (Throwable $exception): never {
    error_log(
        '[testPhoenixStreetApi] ' . get_class($exception) . ': ' .
        $exception->getMessage() . ' in ' . $exception->getFile() .
        ':' . $exception->getLine()
    );

    fail($exception->getMessage());
});

#endregion

#region SECTION II — Configuration Loading

$geocoderUrl = 'https://maps.phoenix.gov/pub/rest/services/Public/' .
    'PHOENIX_GC/GeocodeServer/findAddressCandidates';

$streetUrl = 'https://maps.phoenix.gov/pub/rest/services/Public/' .
    'STR_StreetCenterline/MapServer/0/query';

$defaultAddress = '3145 N 33rd Ave, Phoenix, AZ 85017';
$address = trim((string)($_GET['address'] ?? $argv[1] ?? $defaultAddress));
$searchDistanceMeters = 125;

if ($address === '') {
    fail('An address is required.', 400);
}

if (!extension_loaded('curl')) {
    fail('The PHP cURL extension is not enabled on this server.');
}

#endregion

#region SECTION III — Helpers & Utilities

/**
 * Execute an ArcGIS REST GET request and decode its JSON response.
 */
function callArcGis(string $endpoint, array $params): array
{
    $requestUrl = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $curl = curl_init($requestUrl);

    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Skyesoft-Phoenix-GIS-Test/1.0'
    ]);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        throw new RuntimeException('cURL request failed: ' . $curlError);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('ArcGIS returned HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode($responseBody, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('ArcGIS returned invalid JSON.');
    }

    if (isset($decoded['error'])) {
        $apiMessage = (string)($decoded['error']['message'] ?? 'Unknown ArcGIS error');
        throw new RuntimeException('ArcGIS error: ' . $apiMessage);
    }

    return [
        'requestUrl' => $requestUrl,
        'httpCode' => $httpCode,
        'data' => $decoded
    ];
}

/**
 * Return the first populated field from an ArcGIS attributes array.
 */
function firstAttribute(array $attributes, array $fieldNames): mixed
{
    foreach ($fieldNames as $fieldName) {
        if (array_key_exists($fieldName, $attributes) && $attributes[$fieldName] !== null) {
            return $attributes[$fieldName];
        }
    }

    return null;
}

/**
 * Translate a Phoenix street class code without applying ordinance rules.
 */
function describeStreetClass(?string $classCode): ?string
{
    $classMap = [
        'AR' => 'Arterial',
        'AT' => 'Arterial',
        'CO' => 'Collector',
        'MC' => 'Minor Collector',
        'LO' => 'Local'
    ];

    $normalizedCode = strtoupper(trim((string)$classCode));

    return $classMap[$normalizedCode] ?? ($normalizedCode !== '' ? $normalizedCode : null);
}

/**
 * Score a nearby segment against the requested street and address number.
 */
function scoreStreetCandidate(array $candidate, string $address): int
{
    $score = 0;
    $streetName = strtoupper((string)($candidate['streetName'] ?? ''));
    $normalizedAddress = strtoupper($address);

    if ($streetName !== '' && str_contains($normalizedAddress, $streetName)) {
        $score += 100;
    }

    if (preg_match('/^\s*(\d+)/', $address, $numberMatch)) {
        $addressNumber = (int)$numberMatch[1];
        $ranges = [
            [$candidate['leftFromAddress'] ?? null, $candidate['leftToAddress'] ?? null],
            [$candidate['rightFromAddress'] ?? null, $candidate['rightToAddress'] ?? null]
        ];

        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if (!is_numeric($rangeStart) || !is_numeric($rangeEnd)) {
                continue;
            }

            $rangeMinimum = min((int)$rangeStart, (int)$rangeEnd);
            $rangeMaximum = max((int)$rangeStart, (int)$rangeEnd);

            if ($addressNumber >= $rangeMinimum && $addressNumber <= $rangeMaximum) {
                $score += 50;
                break;
            }
        }
    }

    return $score;
}

#endregion

#region SECTION IV — Core Logic

$geocoderResponse = callArcGis($geocoderUrl, [
    'SingleLine' => $address,
    'outFields' => '*',
    'outSR' => '4326',
    'maxLocations' => 5,
    'f' => 'json'
]);

$candidates = $geocoderResponse['data']['candidates'] ?? [];

if (!is_array($candidates) || empty($candidates)) {
    fail('The Phoenix geocoder did not return a location.', 404);
}

$bestCandidate = $candidates[0];
$location = $bestCandidate['location'] ?? [];
$latitude = isset($location['y']) ? (float)$location['y'] : null;
$longitude = isset($location['x']) ? (float)$location['x'] : null;

if ($latitude === null || $longitude === null) {
    fail('The geocoder result did not contain coordinates.');
}

$streetResponse = callArcGis($streetUrl, [
    'where' => '1=1',
    'geometry' => $longitude . ',' . $latitude,
    'geometryType' => 'esriGeometryPoint',
    'inSR' => '4326',
    'spatialRel' => 'esriSpatialRelIntersects',
    'distance' => $searchDistanceMeters,
    'units' => 'esriSRUnit_Meter',
    'outFields' => '*',
    'returnGeometry' => 'false',
    'resultRecordCount' => 25,
    'f' => 'json'
]);

$streetFeatures = $streetResponse['data']['features'] ?? [];
$streetCandidates = [];

foreach ($streetFeatures as $feature) {
    $attributes = is_array($feature['attributes'] ?? null) ? $feature['attributes'] : [];
    $classCode = firstAttribute($attributes, ['STREETCLASS', 'ST_CLASS', 'CLASS']);

    $streetCandidate = [
        'objectId' => firstAttribute($attributes, ['OBJECTID', 'ObjectID']),
        'streetName' => firstAttribute($attributes, ['ANNAME', 'FULLNAME', 'STREETNAME']),
        'streetClassCode' => $classCode,
        'streetClassification' => describeStreetClass(
            is_scalar($classCode) ? (string)$classCode : null
        ),
        'jurisdiction' => firstAttribute($attributes, ['JURISDICTION', 'JURISDICT']),
        'leftFromAddress' => firstAttribute($attributes, ['L_F_ADD', 'L_F_ADD1']),
        'leftToAddress' => firstAttribute($attributes, ['L_T_ADD', 'L_T_ADD1']),
        'rightFromAddress' => firstAttribute($attributes, ['R_F_ADD', 'R_F_ADD1']),
        'rightToAddress' => firstAttribute($attributes, ['R_T_ADD', 'R_T_ADD1']),
        'segmentLengthFeet' => firstAttribute(
            $attributes,
            ['Shape__Length', 'SHAPE__Length', 'Shape_Length', 'SHAPE_LENGTH']
        ),
        'status' => firstAttribute($attributes, ['STATUS', 'SEGMENTSTATUS']),
        'rawAttributes' => $attributes
    ];

    $streetCandidate['matchScore'] = scoreStreetCandidate($streetCandidate, $address);
    $streetCandidates[] = $streetCandidate;
}

usort($streetCandidates, function (array $left, array $right): int {
    return ($right['matchScore'] ?? 0) <=> ($left['matchScore'] ?? 0);
});

$primaryStreet = $streetCandidates[0] ?? null;
$retrievedAt = time();
$normalizedFrontage = null;

if ($primaryStreet !== null) {
    $normalizedFrontage = [
        'streetName' => $primaryStreet['streetName'],
        'frontageLengthFeet' => null,
        'frontageMethod' => null,
        'regionalClassification' => $primaryStreet['streetClassification'],
        'regionalClassificationCode' => $primaryStreet['streetClassCode'],
        'regionalSource' => 'City of Phoenix Street Centerline',
        'regionalSourceObjectId' => $primaryStreet['objectId'],
        'localRoadTier' => null,
        'ordinanceMatrixKey' => null,
        'classificationConfidence' => 1.0,
        'requiresFieldVerification' => true,
        'retrievedAt' => $retrievedAt
    ];
}

#endregion

#region SECTION V — Output & Response

echo json_encode([
    'success' => true,
    'test' => [
        'addressInput' => $address,
        'searchDistanceMeters' => $searchDistanceMeters,
        'retrievedAt' => $retrievedAt
    ],
    'geocoder' => [
        'matchedAddress' => $bestCandidate['address'] ?? null,
        'score' => $bestCandidate['score'] ?? null,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'parcelNumber' => firstAttribute(
            is_array($bestCandidate['attributes'] ?? null) ? $bestCandidate['attributes'] : [],
            ['APN', 'PARCEL', 'PARCELID', 'ParcelID']
        ),
        'rawCandidate' => $bestCandidate
    ],
    'streetCandidates' => $streetCandidates,
    'normalizedSiteFrontageCandidate' => $normalizedFrontage,
    'notes' => [
        'The first nearby segment is a candidate, not yet a proven parcel frontage.',
        'segmentLengthFeet is the road segment length, not parcel frontage length.',
        'localRoadTier and ordinanceMatrixKey require a governed Phoenix mapping rule.'
    ],
    'requests' => [
        'geocoder' => $geocoderResponse['requestUrl'],
        'streetCenterline' => $streetResponse['requestUrl']
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion
