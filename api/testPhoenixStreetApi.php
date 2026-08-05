<?php
// ========================================================================
//  Skyesoft — testPhoenixStreetApi.php
//  Confirms parcel street frontage using County and Phoenix GIS geometry
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

$parcelUrl = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/' .
    'MaricopaDynamicQueryService/MapServer/3/query';

$defaultAddress = '3145 N 33rd Ave, Phoenix, AZ 85017';
$address = trim((string)($_GET['address'] ?? $argv[1] ?? $defaultAddress));
$defaultParcelNumber = '108-03-009E';
$parcelNumber = trim((string)($_GET['parcel'] ?? $argv[2] ?? $defaultParcelNumber));
$verifiedStreetName = trim((string)($_GET['verifiedStreet'] ?? 'N 33RD AVE'));
$verifiedFrontageFeet = isset($_GET['verifiedFrontage'])
    ? (float)$_GET['verifiedFrontage']
    : ($parcelNumber === $defaultParcelNumber ? 131.0 : null);
$frontageToleranceFeet = 2.0;
$streetSearchDistanceFeet = 300;
$frontageMaximumDistanceFeet = 100;
$minimumParallelAlignment = 0.75;
$analysisSpatialReference = 2223; // NAD83 Arizona Central (International Feet)

if ($address === '') {
    fail('An address is required.', 400);
}

if ($parcelNumber === '') {
    fail('A parcel number is required.', 400);
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

/**
 * Normalize an APN for matching while retaining its possible suffix.
 */
function normalizeParcelNumber(string $parcelNumber): string
{
    return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $parcelNumber));
}

/**
 * Escape a string for an ArcGIS SQL where clause.
 */
function escapeArcGisSql(string $value): string
{
    return str_replace("'", "''", $value);
}

/**
 * Return an envelope for all coordinates in the parcel rings.
 */
function calculateEnvelope(array $rings): array
{
    $minimumX = INF;
    $minimumY = INF;
    $maximumX = -INF;
    $maximumY = -INF;

    foreach ($rings as $ring) {
        foreach ($ring as $point) {
            if (!is_array($point) || count($point) < 2) continue;

            $x = (float)$point[0];
            $y = (float)$point[1];
            $minimumX = min($minimumX, $x);
            $minimumY = min($minimumY, $y);
            $maximumX = max($maximumX, $x);
            $maximumY = max($maximumY, $y);
        }
    }

    if (!is_finite($minimumX) || !is_finite($minimumY)) {
        throw new RuntimeException('The parcel geometry did not contain usable coordinates.');
    }

    return [$minimumX, $minimumY, $maximumX, $maximumY];
}

/**
 * Calculate the shortest distance from a point to a line segment.
 */
function pointToSegmentDistance(array $point, array $start, array $end): float
{
    $segmentX = (float)$end[0] - (float)$start[0];
    $segmentY = (float)$end[1] - (float)$start[1];
    $lengthSquared = ($segmentX * $segmentX) + ($segmentY * $segmentY);

    if ($lengthSquared <= 0.0) {
        return hypot((float)$point[0] - (float)$start[0], (float)$point[1] - (float)$start[1]);
    }

    $projection = (
        (((float)$point[0] - (float)$start[0]) * $segmentX) +
        (((float)$point[1] - (float)$start[1]) * $segmentY)
    ) / $lengthSquared;
    $projection = max(0.0, min(1.0, $projection));
    $closestX = (float)$start[0] + ($projection * $segmentX);
    $closestY = (float)$start[1] + ($projection * $segmentY);

    return hypot((float)$point[0] - $closestX, (float)$point[1] - $closestY);
}

/**
 * Compare the direction of a parcel edge and street segment (0–1).
 */
function calculateParallelAlignment(array $edgeStart, array $edgeEnd, array $roadStart, array $roadEnd): float
{
    $edgeX = (float)$edgeEnd[0] - (float)$edgeStart[0];
    $edgeY = (float)$edgeEnd[1] - (float)$edgeStart[1];
    $roadX = (float)$roadEnd[0] - (float)$roadStart[0];
    $roadY = (float)$roadEnd[1] - (float)$roadStart[1];
    $edgeLength = hypot($edgeX, $edgeY);
    $roadLength = hypot($roadX, $roadY);

    if ($edgeLength <= 0.0 || $roadLength <= 0.0) return 0.0;

    return abs((($edgeX * $roadX) + ($edgeY * $roadY)) / ($edgeLength * $roadLength));
}

/**
 * Find the closest suitably parallel segment within one street feature.
 */
function matchEdgeToStreet(array $edgeStart, array $edgeEnd, array $paths): ?array
{
    $midpoint = [
        ((float)$edgeStart[0] + (float)$edgeEnd[0]) / 2,
        ((float)$edgeStart[1] + (float)$edgeEnd[1]) / 2
    ];
    $bestMatch = null;

    foreach ($paths as $path) {
        for ($index = 1, $count = count($path); $index < $count; $index++) {
            $roadStart = $path[$index - 1];
            $roadEnd = $path[$index];
            $distance = pointToSegmentDistance($midpoint, $roadStart, $roadEnd);
            $alignment = calculateParallelAlignment($edgeStart, $edgeEnd, $roadStart, $roadEnd);

            if ($bestMatch === null || $distance < $bestMatch['distanceFeet']) {
                $bestMatch = [
                    'distanceFeet' => $distance,
                    'parallelAlignment' => $alignment
                ];
            }
        }
    }

    return $bestMatch;
}

/**
 * Translate Phoenix's adopted street class into its ordinance volume tier.
 */
function resolvePhoenixRoadTier(?string $classCode): ?string
{
    $tierMap = [
        'FR' => 'freeway',
        'EX' => 'freeway',
        'MA' => 'highVolume',
        'AR' => 'highVolume',
        'AT' => 'highVolume',
        'CO' => 'highVolume',
        'MC' => 'lowVolume',
        'LO' => 'lowVolume'
    ];

    return $tierMap[strtoupper(trim((string)$classCode))] ?? null;
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

$normalizedParcelNumber = normalizeParcelNumber($parcelNumber);
$parcelResponse = callArcGis($parcelUrl, [
    'where' => "APN='" . escapeArcGisSql($normalizedParcelNumber) . "'",
    'outFields' => 'APN,APN_DASH,OWNER_NAME,PHYSICAL_ADDRESS',
    'returnGeometry' => 'true',
    'outSR' => $analysisSpatialReference,
    'resultRecordCount' => 5,
    'f' => 'json'
]);

$parcelFeatures = $parcelResponse['data']['features'] ?? [];

if (!is_array($parcelFeatures) || count($parcelFeatures) !== 1) {
    fail('The County parcel query did not resolve exactly one parcel.', 404, [
        'parcelNumber' => $parcelNumber,
        'matchCount' => is_array($parcelFeatures) ? count($parcelFeatures) : 0
    ]);
}

$parcelFeature = $parcelFeatures[0];
$parcelAttributes = is_array($parcelFeature['attributes'] ?? null)
    ? $parcelFeature['attributes']
    : [];
$parcelRings = $parcelFeature['geometry']['rings'] ?? [];

if (!is_array($parcelRings) || empty($parcelRings)) {
    fail('The County parcel response did not contain polygon rings.');
}

$parcelEnvelope = calculateEnvelope($parcelRings);

$streetResponse = callArcGis($streetUrl, [
    'where' => '1=1',
    'geometry' => implode(',', $parcelEnvelope),
    'geometryType' => 'esriGeometryEnvelope',
    'inSR' => $analysisSpatialReference,
    'spatialRel' => 'esriSpatialRelIntersects',
    'distance' => $streetSearchDistanceFeet,
    'units' => 'esriSRUnit_Foot',
    'outFields' => '*',
    'returnGeometry' => 'true',
    'outSR' => $analysisSpatialReference,
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
        'leftFromAddress' => firstAttribute($attributes, ['LTFROMADD', 'L_F_ADD', 'L_F_ADD1']),
        'leftToAddress' => firstAttribute($attributes, ['LTTOADD', 'L_T_ADD', 'L_T_ADD1']),
        'rightFromAddress' => firstAttribute($attributes, ['RTFROMADD', 'R_F_ADD', 'R_F_ADD1']),
        'rightToAddress' => firstAttribute($attributes, ['RTTOADD', 'R_T_ADD', 'R_T_ADD1']),
        'segmentLengthFeet' => firstAttribute(
            $attributes,
            ['SHAPE.STLength()', 'Shape__Length', 'SHAPE__Length', 'Shape_Length', 'SHAPE_LENGTH']
        ),
        'status' => firstAttribute($attributes, ['STATUS', 'SEGMENTSTATUS']),
        'geometry' => $feature['geometry'] ?? null,
        'rawAttributes' => $attributes
    ];

    $streetCandidate['matchScore'] = scoreStreetCandidate($streetCandidate, $address);
    $streetCandidates[] = $streetCandidate;
}

usort($streetCandidates, function (array $left, array $right): int {
    return ($right['matchScore'] ?? 0) <=> ($left['matchScore'] ?? 0);
});

$retrievedAt = time();
$frontageGroups = [];
$edgeEvidence = [];

foreach ($parcelRings as $ringIndex => $ring) {
    for ($edgeIndex = 1, $count = count($ring); $edgeIndex < $count; $edgeIndex++) {
        $edgeStart = $ring[$edgeIndex - 1];
        $edgeEnd = $ring[$edgeIndex];
        $edgeLength = hypot(
            (float)$edgeEnd[0] - (float)$edgeStart[0],
            (float)$edgeEnd[1] - (float)$edgeStart[1]
        );
        $selectedStreet = null;
        $selectedMatch = null;

        foreach ($streetCandidates as $streetCandidate) {
            $paths = $streetCandidate['geometry']['paths'] ?? [];
            $match = matchEdgeToStreet($edgeStart, $edgeEnd, $paths);

            if ($match === null || $match['distanceFeet'] > $frontageMaximumDistanceFeet) continue;
            if ($match['parallelAlignment'] < $minimumParallelAlignment) continue;

            if ($selectedMatch === null || $match['distanceFeet'] < $selectedMatch['distanceFeet']) {
                $selectedStreet = $streetCandidate;
                $selectedMatch = $match;
            }
        }

        $evidence = [
            'ringIndex' => $ringIndex,
            'edgeIndex' => $edgeIndex - 1,
            'edgeLengthFeet' => round($edgeLength, 2),
            'assignedStreet' => $selectedStreet['streetName'] ?? null,
            'distanceToCenterlineFeet' => isset($selectedMatch['distanceFeet'])
                ? round($selectedMatch['distanceFeet'], 2)
                : null,
            'parallelAlignment' => isset($selectedMatch['parallelAlignment'])
                ? round($selectedMatch['parallelAlignment'], 4)
                : null
        ];
        $edgeEvidence[] = $evidence;

        if ($selectedStreet === null) continue;

        $groupKey = (string)$selectedStreet['objectId'];

        if (!isset($frontageGroups[$groupKey])) {
            $frontageGroups[$groupKey] = [
                'street' => $selectedStreet,
                'lengthFeet' => 0.0,
                'edgeCount' => 0,
                'maximumDistanceFeet' => 0.0,
                'minimumAlignment' => 1.0
            ];
        }

        $frontageGroups[$groupKey]['lengthFeet'] += $edgeLength;
        $frontageGroups[$groupKey]['edgeCount']++;
        $frontageGroups[$groupKey]['maximumDistanceFeet'] = max(
            $frontageGroups[$groupKey]['maximumDistanceFeet'],
            $selectedMatch['distanceFeet']
        );
        $frontageGroups[$groupKey]['minimumAlignment'] = min(
            $frontageGroups[$groupKey]['minimumAlignment'],
            $selectedMatch['parallelAlignment']
        );
    }
}

$siteFrontages = [];

foreach ($frontageGroups as $frontageGroup) {
    $street = $frontageGroup['street'];
    $calculatedFeet = round($frontageGroup['lengthFeet'], 1);
    $isVerifiedStreet = strtoupper((string)$street['streetName']) === strtoupper($verifiedStreetName);
    $differenceFeet = $isVerifiedStreet && $verifiedFrontageFeet !== null
        ? round($calculatedFeet - $verifiedFrontageFeet, 1)
        : null;
    $withinTolerance = $differenceFeet !== null
        ? abs($differenceFeet) <= $frontageToleranceFeet
        : null;

    $siteFrontages[] = [
        'streetName' => $street['streetName'],
        'frontageLengthFeet' => $calculatedFeet,
        'frontageMethod' => 'countyParcelBoundaryToMunicipalStreetCenterline',
        'municipalClassification' => $street['streetClassification'],
        'municipalClassificationCode' => $street['streetClassCode'],
        'classificationSource' => 'City of Phoenix Street Centerline',
        'classificationSourceObjectId' => $street['objectId'],
        'localRoadTier' => resolvePhoenixRoadTier(
            is_scalar($street['streetClassCode']) ? (string)$street['streetClassCode'] : null
        ),
        'parcelAdjacencyConfirmed' => true,
        'edgeCount' => $frontageGroup['edgeCount'],
        'maximumDistanceToCenterlineFeet' => round($frontageGroup['maximumDistanceFeet'], 1),
        'minimumParallelAlignment' => round($frontageGroup['minimumAlignment'], 4),
        'verification' => $isVerifiedStreet && $verifiedFrontageFeet !== null ? [
            'referenceFrontageFeet' => $verifiedFrontageFeet,
            'referenceMethod' => 'manualParcelMap',
            'differenceFeet' => $differenceFeet,
            'toleranceFeet' => $frontageToleranceFeet,
            'withinTolerance' => $withinTolerance
        ] : null,
        'requiresFieldVerification' => $withinTolerance === false,
        'retrievedAt' => $retrievedAt
    ];
}

usort($siteFrontages, function (array $left, array $right): int {
    return ($right['frontageLengthFeet'] ?? 0) <=> ($left['frontageLengthFeet'] ?? 0);
});

#endregion

#region SECTION V — Output & Response

echo json_encode([
    'success' => true,
    'test' => [
        'addressInput' => $address,
        'parcelInput' => $parcelNumber,
        'analysisSpatialReference' => $analysisSpatialReference,
        'streetSearchDistanceFeet' => $streetSearchDistanceFeet,
        'frontageMaximumDistanceFeet' => $frontageMaximumDistanceFeet,
        'minimumParallelAlignment' => $minimumParallelAlignment,
        'verifiedStreetName' => $verifiedStreetName,
        'verifiedFrontageFeet' => $verifiedFrontageFeet,
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
    'parcel' => [
        'parcelNumber' => $parcelAttributes['APN'] ?? null,
        'parcelNumberRaw' => $parcelAttributes['APN_DASH'] ?? null,
        'ownerName' => $parcelAttributes['OWNER_NAME'] ?? null,
        'physicalAddress' => $parcelAttributes['PHYSICAL_ADDRESS'] ?? null,
        'spatialReference' => $analysisSpatialReference,
        'envelope' => $parcelEnvelope,
        'ringCount' => count($parcelRings)
    ],
    'streetCandidates' => $streetCandidates,
    'siteFrontages' => $siteFrontages,
    'edgeEvidence' => $edgeEvidence,
    'notes' => [
        'Frontage is calculated independently from parcel and street geometry in EPSG:2223 feet.',
        'The manual 131-foot value is used only to validate the calculated N 33rd Avenue result.',
        'This is GIS-calculated frontage and is not a substitute for a legal survey.'
    ],
    'requests' => [
        'geocoder' => $geocoderResponse['requestUrl'],
        'parcel' => $parcelResponse['requestUrl'],
        'streetCenterline' => $streetResponse['requestUrl']
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion