<?php
declare(strict_types=1);

// ========================================================================
//  Skyesoft — resolveLocationFrontages.php
//  Resolves GIS-verified parcel street frontage throughout Maricopa County
//  Runtime: PHP 8.4
// ========================================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

set_time_limit(0);

// #region Configuration

require_once __DIR__ . '/../api/sessionBootstrap.php';
require_once __DIR__ . '/../api/utils/envLoader.php';
require_once __DIR__ . '/../api/dbConnect.php';

skyesoftLoadEnv();

$db = getPDO();
$analysisSpatialReference = 2223; // NAD83 Arizona Central (feet)
$streetSearchDistanceFeet = 300;
$frontageMaximumDistanceFeet = 100;
$minimumParallelAlignment = 0.75;
$minimumFrontageFeet = 8;
$defaultLimit = 10;

$parcelUrl = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/' .
    'MaricopaDynamicQueryService/MapServer/3/query';
$countyStreetUrl = 'https://services.arcgis.com/ykpntM6e3tHvzKRJ/arcgis/rest/services/' .
    'Maricopa_County_Streets/FeatureServer/0/query';
$phoenixStreetUrl = 'https://maps.phoenix.gov/pub/rest/services/Public/' .
    'STR_StreetCenterline/MapServer/0/query';
$adotFunctionalUrl = 'https://services6.arcgis.com/clPWQMwZfdWn4MQZ/arcgis/rest/services/' .
    'ADOT_FunctionalSystem_2024/FeatureServer/0/query';

$locationId = requestInteger('locationId', null);
$parcelDetailsId = requestInteger('parcelDetailsId', null);
$limit = requestInteger('limit', $defaultLimit);
$offset = requestInteger('offset', 0);
$writeRequested = requestBoolean('write', false);
$dryRun = !$writeRequested;
$includeEvidence = requestBoolean('includeEvidence', false);

if ($limit !== null && $limit < 0) $limit = $defaultLimit;
if ($offset === null || $offset < 0) $offset = 0;
if (PHP_SAPI !== 'cli' && empty($_SESSION['authenticated'])) {
    fail('Authentication is required.', 401, array());
}

// #endregion

// #region Request & Output Helpers

/**
 * Return a request value from GET or CLI arguments.
 */
function requestValue($name, $defaultValue)
{
    if (isset($_GET[$name])) return $_GET[$name];

    global $argv;

    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $argument) {
            if (strpos($argument, '--' . $name . '=') === 0) {
                return substr($argument, strlen($name) + 3);
            }
        }
    }

    return $defaultValue;
}

/**
 * Return an integer request value.
 */
function requestInteger($name, $defaultValue)
{
    $value = requestValue($name, $defaultValue);

    if ($value === null || $value === '') return $defaultValue;
    if (!is_numeric($value)) return $defaultValue;

    return (int)$value;
}

/**
 * Return a Boolean request value.
 */
function requestBoolean($name, $defaultValue)
{
    $value = requestValue($name, $defaultValue ? '1' : '0');
    $normalized = strtolower(trim((string)$value));

    return in_array($normalized, array('1', 'true', 'yes', 'on'), true);
}

/**
 * Return JSON and stop execution.
 */
function outputJson($payload, $statusCode)
{
    http_response_code((int)$statusCode);
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    exit;
}

/**
 * Return a structured failure and stop execution.
 */
function fail($message, $statusCode, $details)
{
    outputJson(array(
        'success' => false,
        'error' => $message,
        'details' => $details
    ), $statusCode);
}

// #endregion

// #region ArcGIS Helpers

/**
 * Execute an ArcGIS REST request.
 */
function callArcGis($endpoint, $params)
{
    $requestUrl = $endpoint . '?' . http_build_query($params, '', '&');
    $curl = curl_init($requestUrl);

    if ($curl === false) throw new Exception('Unable to initialize cURL.');

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl, CURLOPT_TIMEOUT, 45);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    curl_setopt($curl, CURLOPT_USERAGENT, 'Skyesoft-Frontage-Resolver/1.0');

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) throw new Exception('cURL request failed: ' . $curlError);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception('ArcGIS returned HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode($responseBody, true);

    if (!is_array($decoded)) throw new Exception('ArcGIS returned invalid JSON.');
    if (isset($decoded['error'])) {
        $message = isset($decoded['error']['message'])
            ? $decoded['error']['message']
            : 'Unknown ArcGIS error';
        throw new Exception('ArcGIS error: ' . $message);
    }

    return $decoded;
}

/**
 * Return the first populated ArcGIS attribute.
 */
function firstAttribute($attributes, $fieldNames)
{
    foreach ($fieldNames as $fieldName) {
        if (array_key_exists($fieldName, $attributes) && $attributes[$fieldName] !== null) {
            return $attributes[$fieldName];
        }
    }

    return null;
}

/**
 * Escape text used in an ArcGIS where clause.
 */
function escapeArcGisSql($value)
{
    return str_replace("'", "''", $value);
}

/**
 * Normalize an APN for County matching.
 */
function normalizeParcelNumber($parcelNumber)
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$parcelNumber));
}

// #endregion

// #region Geometry Helpers

/**
 * Calculate an envelope surrounding polygon rings.
 */
function calculateEnvelope($rings)
{
    $minimumX = INF;
    $minimumY = INF;
    $maximumX = -INF;
    $maximumY = -INF;

    foreach ($rings as $ring) {
        foreach ($ring as $point) {
            if (!is_array($point) || count($point) < 2) continue;

            $minimumX = min($minimumX, (float)$point[0]);
            $minimumY = min($minimumY, (float)$point[1]);
            $maximumX = max($maximumX, (float)$point[0]);
            $maximumY = max($maximumY, (float)$point[1]);
        }
    }

    if (!is_finite($minimumX) || !is_finite($minimumY)) {
        throw new Exception('Parcel geometry contains no usable coordinates.');
    }

    return array($minimumX, $minimumY, $maximumX, $maximumY);
}

/**
 * Calculate the distance from a point to a line segment.
 */
function pointToSegmentDistance($point, $start, $end)
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
 * Return absolute directional alignment from zero through one.
 */
function calculateParallelAlignment($edgeStart, $edgeEnd, $roadStart, $roadEnd)
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
 * Find the closest parallel segment in a road feature.
 */
function matchEdgeToStreet($edgeStart, $edgeEnd, $paths)
{
    $midpoint = array(
        ((float)$edgeStart[0] + (float)$edgeEnd[0]) / 2,
        ((float)$edgeStart[1] + (float)$edgeEnd[1]) / 2
    );
    $bestMatch = null;

    foreach ($paths as $path) {
        $count = count($path);

        for ($index = 1; $index < $count; $index++) {
            $roadStart = $path[$index - 1];
            $roadEnd = $path[$index];
            $distance = pointToSegmentDistance($midpoint, $roadStart, $roadEnd);
            $alignment = calculateParallelAlignment($edgeStart, $edgeEnd, $roadStart, $roadEnd);

            if ($bestMatch === null || $distance < $bestMatch['distanceFeet']) {
                $bestMatch = array(
                    'distanceFeet' => $distance,
                    'parallelAlignment' => $alignment
                );
            }
        }
    }

    return $bestMatch;
}

// #endregion

// #region Street Helpers

/**
 * Build a complete County street name.
 */
function buildCountyStreetName($attributes)
{
    $parts = array(
        firstAttribute($attributes, array('StDir')),
        firstAttribute($attributes, array('StName')),
        firstAttribute($attributes, array('StType')),
        firstAttribute($attributes, array('StSufx'))
    );
    $cleanParts = array();

    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') $cleanParts[] = $part;
    }

    return strtoupper(implode(' ', $cleanParts));
}

/**
 * Normalize a street name for cross-source matching.
 */
function normalizeStreetName($streetName)
{
    $name = strtoupper(trim((string)$streetName));
    $name = preg_replace('/[^A-Z0-9 ]+/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);

    $replacements = array(
        ' AVENUE' => ' AVE',
        ' STREET' => ' ST',
        ' ROAD' => ' RD',
        ' BOULEVARD' => ' BLVD',
        ' DRIVE' => ' DR',
        ' LANE' => ' LN',
        ' PARKWAY' => ' PKWY',
        ' HIGHWAY' => ' HWY'
    );

    return trim(strtr($name, $replacements));
}

/**
 * Translate Phoenix street class codes.
 */
function describePhoenixStreetClass($classCode)
{
    $map = array(
        'FR' => 'Freeway',
        'EX' => 'Expressway',
        'MA' => 'Major Arterial',
        'AR' => 'Arterial',
        'AT' => 'Arterial',
        'CO' => 'Collector',
        'MC' => 'Minor Collector',
        'LO' => 'Local'
    );
    $code = strtoupper(trim((string)$classCode));

    return isset($map[$code]) ? $map[$code] : null;
}

/**
 * Translate Phoenix class codes into its sign-code tier.
 */
function resolvePhoenixRoadTier($classCode)
{
    $map = array(
        'FR' => 'freeway',
        'EX' => 'freeway',
        'MA' => 'highVolume',
        'AR' => 'highVolume',
        'AT' => 'highVolume',
        'CO' => 'highVolume',
        'MC' => 'lowVolume',
        'LO' => 'lowVolume'
    );
    $code = strtoupper(trim((string)$classCode));

    return isset($map[$code]) ? $map[$code] : null;
}

/**
 * Translate ADOT functional-system values.
 */
function describeAdotFunctionalSystem($value, $description)
{
    if (trim((string)$description) !== '') return trim((string)$description);

    $map = array(
        '1' => 'Interstate',
        '2' => 'Other Freeway and Expressway',
        '3' => 'Other Principal Arterial',
        '4' => 'Minor Arterial',
        '5' => 'Major Collector',
        '6' => 'Minor Collector',
        '7' => 'Local'
    );
    $key = trim((string)$value);

    return isset($map[$key]) ? $map[$key] : null;
}

// #endregion

// #region Data Retrieval

/**
 * Load parcel-detail/location records.
 */
function loadSourceRecords($db, $locationId, $parcelDetailsId, $limit, $offset)
{
    $where = array("UPPER(COALESCE(l.locationCounty, '')) = 'MARICOPA'");
    $params = array();

    if ($locationId !== null) {
        $where[] = 'pd.locationId = :locationId';
        $params[':locationId'] = $locationId;
    }

    if ($parcelDetailsId !== null) {
        $where[] = 'pd.parcelDetailsId = :parcelDetailsId';
        $params[':parcelDetailsId'] = $parcelDetailsId;
    }

    $sql = "SELECT
                pd.parcelDetailsId,
                pd.locationId,
                pd.apnRaw,
                l.locationName,
                l.locationAddress,
                l.locationAddressSuite,
                l.locationCity,
                l.locationState,
                l.locationZip,
                l.locationJurisdiction,
                l.locationCounty,
                l.locationLatitude,
                l.locationLongitude
            FROM tblLocationParcelDetails pd
            INNER JOIN tblLocations l ON l.locationId = pd.locationId
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pd.parcelDetailsId";

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int)$offset . ', ' . (int)$limit;
    }

    $statement = $db->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve one County parcel polygon.
 */
function retrieveParcel($record, $parcelUrl, $spatialReference)
{
    $apn = normalizeParcelNumber($record['apnRaw']);
    $data = callArcGis($parcelUrl, array(
        'where' => "APN='" . escapeArcGisSql($apn) . "'",
        'outFields' => 'OBJECTID,APN,APN_DASH,OWNER_NAME,PHYSICAL_ADDRESS',
        'returnGeometry' => 'true',
        'outSR' => $spatialReference,
        'resultRecordCount' => 5,
        'f' => 'json'
    ));
    $features = isset($data['features']) ? $data['features'] : array();

    if (count($features) !== 1) {
        throw new Exception('County parcel query returned ' . count($features) . ' matches.');
    }

    $feature = $features[0];
    $rings = isset($feature['geometry']['rings']) ? $feature['geometry']['rings'] : array();

    if (empty($rings)) throw new Exception('County parcel geometry is missing.');

    return array(
        'attributes' => isset($feature['attributes']) ? $feature['attributes'] : array(),
        'rings' => $rings,
        'envelope' => calculateEnvelope($rings)
    );
}

/**
 * Retrieve County street centerlines surrounding a parcel.
 */
function retrieveCountyStreets($envelope, $countyStreetUrl, $spatialReference, $searchDistance)
{
    $data = callArcGis($countyStreetUrl, array(
        'where' => 'IsBuilt=1 AND IsPublic=1',
        'geometry' => implode(',', $envelope),
        'geometryType' => 'esriGeometryEnvelope',
        'inSR' => $spatialReference,
        'spatialRel' => 'esriSpatialRelIntersects',
        'distance' => $searchDistance,
        'units' => 'esriSRUnit_Foot',
        'outFields' => 'OBJECTID,StDir,StName,StType,StSufx,SubClass,Source,SegmentID,IsBuilt,IsPublic',
        'returnGeometry' => 'true',
        'outSR' => $spatialReference,
        'resultRecordCount' => 200,
        'f' => 'json'
    ));
    $features = isset($data['features']) ? $data['features'] : array();
    $streets = array();

    foreach ($features as $feature) {
        $attributes = isset($feature['attributes']) ? $feature['attributes'] : array();
        $streetName = buildCountyStreetName($attributes);
        $paths = isset($feature['geometry']['paths']) ? $feature['geometry']['paths'] : array();

        if ($streetName === '' || empty($paths)) continue;

        $streets[] = array(
            'objectId' => firstAttribute($attributes, array('OBJECTID')),
            'segmentId' => firstAttribute($attributes, array('SegmentID')),
            'streetName' => $streetName,
            'normalizedStreetName' => normalizeStreetName($streetName),
            'countySubClass' => firstAttribute($attributes, array('SubClass')),
            'source' => 'Maricopa County StreetCenterlines',
            'paths' => $paths
        );
    }

    return $streets;
}

// #endregion

// #region Frontage Resolution

/**
 * Calculate frontage groups from parcel and street geometry.
 */
function calculateFrontages($rings, $streets, $maximumDistance, $minimumAlignment, $minimumFrontage, $includeEvidence)
{
    $groups = array();
    $evidence = array();

    foreach ($rings as $ringIndex => $ring) {
        $count = count($ring);

        for ($edgeIndex = 1; $edgeIndex < $count; $edgeIndex++) {
            $edgeStart = $ring[$edgeIndex - 1];
            $edgeEnd = $ring[$edgeIndex];
            $edgeLength = hypot(
                (float)$edgeEnd[0] - (float)$edgeStart[0],
                (float)$edgeEnd[1] - (float)$edgeStart[1]
            );
            $selectedStreet = null;
            $selectedMatch = null;

            foreach ($streets as $street) {
                $match = matchEdgeToStreet($edgeStart, $edgeEnd, $street['paths']);

                if ($match === null) continue;
                if ($match['distanceFeet'] > $maximumDistance) continue;
                if ($match['parallelAlignment'] < $minimumAlignment) continue;

                if ($selectedMatch === null || $match['distanceFeet'] < $selectedMatch['distanceFeet']) {
                    $selectedStreet = $street;
                    $selectedMatch = $match;
                }
            }

            if ($includeEvidence) {
                $evidence[] = array(
                    'ringIndex' => $ringIndex,
                    'edgeIndex' => $edgeIndex - 1,
                    'edgeLengthFeet' => round($edgeLength, 2),
                    'assignedStreet' => $selectedStreet === null ? null : $selectedStreet['streetName'],
                    'distanceToCenterlineFeet' => $selectedMatch === null
                        ? null
                        : round($selectedMatch['distanceFeet'], 2),
                    'parallelAlignment' => $selectedMatch === null
                        ? null
                        : round($selectedMatch['parallelAlignment'], 4)
                );
            }

            if ($selectedStreet === null) continue;

            $groupKey = $selectedStreet['normalizedStreetName'];

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = array(
                    'streetName' => $selectedStreet['streetName'],
                    'lengthFeet' => 0.0,
                    'edgeCount' => 0,
                    'maximumDistanceFeet' => 0.0,
                    'minimumAlignment' => 1.0,
                    'countySubClass' => $selectedStreet['countySubClass'],
                    'sourceObjectIds' => array()
                );
            }

            $groups[$groupKey]['lengthFeet'] += $edgeLength;
            $groups[$groupKey]['edgeCount']++;
            $groups[$groupKey]['maximumDistanceFeet'] = max(
                $groups[$groupKey]['maximumDistanceFeet'],
                $selectedMatch['distanceFeet']
            );
            $groups[$groupKey]['minimumAlignment'] = min(
                $groups[$groupKey]['minimumAlignment'],
                $selectedMatch['parallelAlignment']
            );

            $objectId = (string)$selectedStreet['objectId'];
            $groups[$groupKey]['sourceObjectIds'][$objectId] = $objectId;
        }
    }

    $frontages = array();

    foreach ($groups as $group) {
        if ($group['lengthFeet'] < $minimumFrontage) continue;

        $confidence = 100;
        if ($group['maximumDistanceFeet'] > 75) $confidence -= 15;
        else if ($group['maximumDistanceFeet'] > 50) $confidence -= 8;
        if ($group['minimumAlignment'] < 0.85) $confidence -= 10;
        else if ($group['minimumAlignment'] < 0.92) $confidence -= 5;
        if ($group['edgeCount'] > 12) $confidence -= 5;

        $frontages[] = array(
            'streetName' => $group['streetName'],
            'frontageLengthFeet' => round($group['lengthFeet'], 2),
            'frontageMethod' => 'countyParcelBoundaryToCountyStreetCenterline',
            'streetClassCode' => $group['countySubClass'] === null
                ? null
                : (string)$group['countySubClass'],
            'streetClassification' => null,
            'roadTier' => null,
            'parcelSource' => 'Maricopa County Assessor Parcel GIS',
            'streetSource' => 'Maricopa County StreetCenterlines',
            'parcelSourceObjectId' => null,
            'streetSourceObjectId' => implode(',', array_values($group['sourceObjectIds'])),
            'verificationStatus' => $confidence >= 90 ? 'gis_verified' : 'gis_calculated',
            'confidence' => max(0, min(100, $confidence)),
            'requiresManualReview' => $confidence >= 90 ? 0 : 1,
            'edgeCount' => $group['edgeCount'],
            'maximumDistanceToCenterlineFeet' => round($group['maximumDistanceFeet'], 2),
            'minimumParallelAlignment' => round($group['minimumAlignment'], 4)
        );
    }

    usort($frontages, 'sortFrontagesByLength');

    return array('frontages' => $frontages, 'edgeEvidence' => $evidence);
}

/**
 * Sort longest frontages first.
 */
function sortFrontagesByLength($left, $right)
{
    if ($left['frontageLengthFeet'] == $right['frontageLengthFeet']) return 0;

    return $left['frontageLengthFeet'] < $right['frontageLengthFeet'] ? 1 : -1;
}

// #endregion

// #region Classification Adapters

/**
 * Enrich Phoenix frontages from its authoritative street layer.
 */
function enrichFromPhoenix($frontages, $envelope, $endpoint, $spatialReference)
{
    $data = callArcGis($endpoint, array(
        'where' => '1=1',
        'geometry' => implode(',', $envelope),
        'geometryType' => 'esriGeometryEnvelope',
        'inSR' => $spatialReference,
        'spatialRel' => 'esriSpatialRelIntersects',
        'distance' => 300,
        'units' => 'esriSRUnit_Foot',
        'outFields' => '*',
        'returnGeometry' => 'false',
        'resultRecordCount' => 100,
        'f' => 'json'
    ));
    $features = isset($data['features']) ? $data['features'] : array();
    $classes = array();

    foreach ($features as $feature) {
        $attributes = isset($feature['attributes']) ? $feature['attributes'] : array();
        $name = firstAttribute($attributes, array('ANNAME', 'FULLNAME', 'STREETNAME'));
        $code = firstAttribute($attributes, array('STREETCLASS', 'ST_CLASS', 'CLASS'));

        if ($name === null || $code === null) continue;

        $key = normalizeStreetName($name);
        $classes[$key] = array(
            'code' => (string)$code,
            'classification' => describePhoenixStreetClass($code),
            'roadTier' => resolvePhoenixRoadTier($code),
            'source' => 'City of Phoenix Street Centerline',
            'objectId' => firstAttribute($attributes, array('OBJECTID'))
        );
    }

    foreach ($frontages as $index => $frontage) {
        $key = normalizeStreetName($frontage['streetName']);

        if (!isset($classes[$key])) continue;

        $frontages[$index]['streetClassCode'] = $classes[$key]['code'];
        $frontages[$index]['streetClassification'] = $classes[$key]['classification'];
        $frontages[$index]['roadTier'] = $classes[$key]['roadTier'];
        $frontages[$index]['streetSource'] = $classes[$key]['source'];
        $frontages[$index]['streetSourceObjectId'] = (string)$classes[$key]['objectId'];
    }

    return $frontages;
}

/**
 * Enrich unresolved classifications using ADOT regional functional class.
 */
function enrichFromAdot($frontages, $envelope, $endpoint, $spatialReference)
{
    $data = callArcGis($endpoint, array(
        'where' => '1=1',
        'geometry' => implode(',', $envelope),
        'geometryType' => 'esriGeometryEnvelope',
        'inSR' => $spatialReference,
        'spatialRel' => 'esriSpatialRelIntersects',
        'distance' => 120,
        'units' => 'esriSRUnit_Foot',
        'outFields' => 'OBJECTID,RouteId,FunctionalSystem_Value,FunctionalSystem',
        'returnGeometry' => 'false',
        'resultRecordCount' => 100,
        'f' => 'json'
    ));
    $features = isset($data['features']) ? $data['features'] : array();

    foreach ($frontages as $index => $frontage) {
        if ($frontage['streetClassification'] !== null) continue;

        $normalizedFrontageName = normalizeStreetName($frontage['streetName']);

        foreach ($features as $feature) {
            $attributes = isset($feature['attributes']) ? $feature['attributes'] : array();
            $routeId = firstAttribute($attributes, array('RouteId'));
            $normalizedRoute = normalizeStreetName($routeId);

            if ($normalizedRoute === '') continue;
            if (strpos($normalizedFrontageName, $normalizedRoute) === false &&
                strpos($normalizedRoute, $normalizedFrontageName) === false) continue;

            $value = firstAttribute($attributes, array('FunctionalSystem_Value'));
            $description = firstAttribute($attributes, array('FunctionalSystem'));
            $frontages[$index]['streetClassCode'] = $value === null ? null : (string)$value;
            $frontages[$index]['streetClassification'] = describeAdotFunctionalSystem($value, $description);
            $frontages[$index]['streetSource'] = 'ADOT 2024 Functional System';
            $frontages[$index]['streetSourceObjectId'] = (string)firstAttribute($attributes, array('OBJECTID'));
            break;
        }
    }

    return $frontages;
}

// #endregion

// #region Database Persistence

/**
 * Insert or update one frontage record without creating duplicates.
 */
function saveFrontage($db, $record, $frontage, $timestamp)
{
    $find = $db->prepare(
        'SELECT frontageId FROM tblLocationFrontages ' .
        'WHERE parcelDetailsId = :parcelDetailsId AND streetName = :streetName LIMIT 1'
    );
    $find->execute(array(
        ':parcelDetailsId' => $record['parcelDetailsId'],
        ':streetName' => $frontage['streetName']
    ));
    $frontageId = $find->fetchColumn();

    $values = array(
        ':locationId' => $record['locationId'],
        ':parcelDetailsId' => $record['parcelDetailsId'],
        ':streetName' => $frontage['streetName'],
        ':frontageLengthFeet' => $frontage['frontageLengthFeet'],
        ':frontageMethod' => $frontage['frontageMethod'],
        ':streetClassCode' => $frontage['streetClassCode'],
        ':streetClassification' => $frontage['streetClassification'],
        ':roadTier' => $frontage['roadTier'],
        ':parcelSource' => $frontage['parcelSource'],
        ':streetSource' => $frontage['streetSource'],
        ':parcelSourceObjectId' => $frontage['parcelSourceObjectId'],
        ':streetSourceObjectId' => $frontage['streetSourceObjectId'],
        ':verificationStatus' => $frontage['verificationStatus'],
        ':confidence' => $frontage['confidence'],
        ':requiresManualReview' => $frontage['requiresManualReview'],
        ':verifiedAt' => $timestamp,
        ':updatedAt' => $timestamp
    );

    if ($frontageId) {
        $values[':frontageId'] = $frontageId;
        $sql = 'UPDATE tblLocationFrontages SET
                    locationId = :locationId,
                    parcelDetailsId = :parcelDetailsId,
                    streetName = :streetName,
                    frontageLengthFeet = :frontageLengthFeet,
                    frontageMethod = :frontageMethod,
                    streetClassCode = :streetClassCode,
                    streetClassification = :streetClassification,
                    roadTier = :roadTier,
                    parcelSource = :parcelSource,
                    streetSource = :streetSource,
                    parcelSourceObjectId = :parcelSourceObjectId,
                    streetSourceObjectId = :streetSourceObjectId,
                    verificationStatus = :verificationStatus,
                    confidence = :confidence,
                    requiresManualReview = :requiresManualReview,
                    verifiedAt = :verifiedAt,
                    updatedAt = :updatedAt
                WHERE frontageId = :frontageId';
        $statement = $db->prepare($sql);
        $statement->execute($values);

        return 'updated';
    }

    $values[':createdAt'] = $timestamp;
    $sql = 'INSERT INTO tblLocationFrontages (
                locationId, parcelDetailsId, streetName, frontageLengthFeet,
                frontageMethod, streetClassCode, streetClassification, roadTier,
                parcelSource, streetSource, parcelSourceObjectId, streetSourceObjectId,
                verificationStatus, confidence, requiresManualReview,
                verifiedAt, createdAt, updatedAt
            ) VALUES (
                :locationId, :parcelDetailsId, :streetName, :frontageLengthFeet,
                :frontageMethod, :streetClassCode, :streetClassification, :roadTier,
                :parcelSource, :streetSource, :parcelSourceObjectId, :streetSourceObjectId,
                :verificationStatus, :confidence, :requiresManualReview,
                :verifiedAt, :createdAt, :updatedAt
            )';
    $statement = $db->prepare($sql);
    $statement->execute($values);

    return 'inserted';
}

// #endregion

// #region Batch Execution

if (!extension_loaded('curl')) fail('The PHP cURL extension is required.', 500, array());

$startedAt = time();
$results = array();
$exceptions = array();
$summary = array(
    'sourceRecords' => 0,
    'locationsProcessed' => 0,
    'frontagesResolved' => 0,
    'gisVerified' => 0,
    'manualReviewRequired' => 0,
    'classificationMappingRequired' => 0,
    'recordsInserted' => 0,
    'recordsUpdated' => 0,
    'recordsWritten' => 0,
    'errors' => 0
);

try {
    $records = loadSourceRecords($db, $locationId, $parcelDetailsId, $limit, $offset);
} catch (Exception $exception) {
    fail('Unable to load source records.', 500, array('message' => $exception->getMessage()));
}

$summary['sourceRecords'] = count($records);

foreach ($records as $record) {
    $summary['locationsProcessed']++;

    try {
        $parcel = retrieveParcel($record, $parcelUrl, $analysisSpatialReference);
        $countyStreets = retrieveCountyStreets(
            $parcel['envelope'],
            $countyStreetUrl,
            $analysisSpatialReference,
            $streetSearchDistanceFeet
        );

        if (empty($countyStreets)) throw new Exception('No County street centerlines were returned.');

        $calculation = calculateFrontages(
            $parcel['rings'],
            $countyStreets,
            $frontageMaximumDistanceFeet,
            $minimumParallelAlignment,
            $minimumFrontageFeet,
            $includeEvidence
        );
        $frontages = $calculation['frontages'];

        if (empty($frontages)) throw new Exception('No defensible parcel frontage was found.');

        // Municipal classification (Phoenix currently implemented).
        if (strcasecmp(trim($record['locationJurisdiction']), 'Phoenix') === 0) {
            try {
                $frontages = enrichFromPhoenix(
                    $frontages,
                    $parcel['envelope'],
                    $phoenixStreetUrl,
                    $analysisSpatialReference
                );
            } catch (Exception $classificationException) {
                // Continue to the regional fallback.
            }
        }

        // County-wide regional classification fallback.
        try {
            $frontages = enrichFromAdot(
                $frontages,
                $parcel['envelope'],
                $adotFunctionalUrl,
                $analysisSpatialReference
            );
        } catch (Exception $classificationException) {
            // A valid frontage remains usable without classification.
        }

        $parcelAttributes = $parcel['attributes'];
        $parcelObjectId = firstAttribute($parcelAttributes, array('OBJECTID'));

        foreach ($frontages as $index => $frontage) {
            $frontages[$index]['parcelSourceObjectId'] = $parcelObjectId === null
                ? normalizeParcelNumber($record['apnRaw'])
                : (string)$parcelObjectId;

            if ($frontages[$index]['verificationStatus'] === 'gis_verified') {
                $summary['gisVerified']++;
            }

            if ($frontages[$index]['requiresManualReview']) {
                $summary['manualReviewRequired']++;
            }

            if ($frontages[$index]['roadTier'] === null) {
                $summary['classificationMappingRequired']++;
            }
        }

        if (!$dryRun) {
            $db->beginTransaction();

            try {
                foreach ($frontages as $frontage) {
                    $writeResult = saveFrontage($db, $record, $frontage, time());

                    if ($writeResult === 'inserted') $summary['recordsInserted']++;
                    if ($writeResult === 'updated') $summary['recordsUpdated']++;
                    $summary['recordsWritten']++;
                }

                $db->commit();
            } catch (Exception $writeException) {
                if ($db->inTransaction()) $db->rollBack();
                throw $writeException;
            }
        }

        $summary['frontagesResolved'] += count($frontages);

        $result = array(
            'locationId' => (int)$record['locationId'],
            'parcelDetailsId' => (int)$record['parcelDetailsId'],
            'locationName' => $record['locationName'],
            'jurisdiction' => $record['locationJurisdiction'],
            'apnRaw' => $record['apnRaw'],
            'parcelMatch' => array(
                'apn' => firstAttribute($parcelAttributes, array('APN')),
                'apnRaw' => firstAttribute($parcelAttributes, array('APN_DASH')),
                'physicalAddress' => firstAttribute($parcelAttributes, array('PHYSICAL_ADDRESS'))
            ),
            'frontages' => $frontages
        );

        if ($includeEvidence) $result['edgeEvidence'] = $calculation['edgeEvidence'];
        $results[] = $result;
    } catch (Exception $exception) {
        $summary['errors']++;
        $exceptions[] = array(
            'locationId' => (int)$record['locationId'],
            'parcelDetailsId' => (int)$record['parcelDetailsId'],
            'locationName' => $record['locationName'],
            'jurisdiction' => $record['locationJurisdiction'],
            'apnRaw' => $record['apnRaw'],
            'message' => $exception->getMessage()
        );
    }
}

outputJson(array(
    'success' => $summary['errors'] === 0,
    'mode' => $dryRun ? 'dryRun' : 'write',
    'filters' => array(
        'locationId' => $locationId,
        'parcelDetailsId' => $parcelDetailsId,
        'limit' => $limit,
        'offset' => $offset,
        'includeEvidence' => $includeEvidence
    ),
    'configuration' => array(
        'analysisSpatialReference' => $analysisSpatialReference,
        'streetSearchDistanceFeet' => $streetSearchDistanceFeet,
        'frontageMaximumDistanceFeet' => $frontageMaximumDistanceFeet,
        'minimumParallelAlignment' => $minimumParallelAlignment,
        'minimumFrontageFeet' => $minimumFrontageFeet,
        'parcelSource' => 'Maricopa County Assessor Parcel GIS',
        'streetGeometrySource' => 'Maricopa County StreetCenterlines',
        'regionalClassificationSource' => 'ADOT 2024 Functional System'
    ),
    'summary' => $summary,
    'results' => $results,
    'exceptions' => $exceptions,
    'startedAt' => $startedAt,
    'completedAt' => time(),
    'disclaimer' => 'GIS-verified measurements are derived from authoritative government GIS geometry. They are not a boundary survey and are not certified by a registered land surveyor.'
), 200);

// #endregion
