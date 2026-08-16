<?php

// ======================================================================
// Skyesoft — siteVisualOverviewImages.php
// Version: 1.3.0
// Site Visual Overview Image Generation Endpoint
// ======================================================================
//
// Primary Responsibilities
// • Route Site Visual Overview image requests by image type
// • Generate satellite, primary-parcel, Street View, Immediate Vicinity,
//   and Extended Context imagery
// • Apply parcel boundaries and location markers
// • Store temporary generated imagery in /skyesoft/artifacts
// • Return streamed images or structured artifact responses
//
// Architectural Principles
// • Google API credentials remain exclusively server-side
// • Primary-parcel data originates from the locationCheck.php response
// • Image artifacts are temporary, reproducible, and non-authoritative
// • No database writes occur during image generation
// • All request values and upstream responses are validated
//
// Supported Image Types
// • satellite
// • parcel
// • streetView
// • immediateVicinity
// • extendedContext
//
// Compatibility
// • PHP 8.3+
//
// ======================================================================

#region Section 0 — Endpoint Identity & Environment Bootstrap

/**
 * Skyesoft Site Visual Overview image endpoint.
 *
 * Supported image types:
 * - satellite: Streams a Google Static Maps satellite image.
 * - parcel: Creates a temporary aerial image for the primary parcel and
 *   returns its public artifact URL as JSON.
 * - streetView: Creates a temporary ground-level image aimed from the
 *   Google panorama toward the validated property coordinates.
 * - immediateVicinity: Creates a temporary labeled roadmap of the
 *   approximately 1.25-mile context around the property, with the
 *   primary parcel boundary and a red location marker.
 * - extendedContext: Creates a temporary roadmap showing the fastest
 *   driving route from Christy Signs (Entity 1) to the destination,
 *   with both locations marked, the driving polyline, a direct line,
 *   and distance/duration metrics returned in JSON for caption use.
 *
 * Extended Context request contract (POST JSON):
 * {
 *     "type": "extendedContext",
 *     "destination": {
 *         "address": "2252 N 44th St, Phoenix, AZ 85008, USA",
 *         "latitude": 33.4720564,
 *         "longitude": -111.9902556
 *     }
 * }
 *
 * Top-level latitude / longitude / address are also accepted for
 * backward compatibility.
 *
 * Compatibility: PHP 8.3+
 */

// Load Skyesoft environment
$envLoaderPath = __DIR__ . '/utils/envLoader.php';

if (!file_exists($envLoaderPath)) {
    siteVisualOverviewError(
        'HTTP/1.1 500 Internal Server Error',
        'Site Visual Overview image service is not configured.',
        false
    );
}

require_once $envLoaderPath;

if (function_exists('skyesoftLoadEnv')) {
    skyesoftLoadEnv();
}

// Prevent caching of temporary preview responses
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

#endregion

#region Section 1 — Shared Response & Request Helpers

/**
 * Return a controlled error response and stop execution.
 *
 * @param string  $statusLine HTTP response status line.
 * @param string  $message    Safe user-facing error message.
 * @param boolean $asJson     Whether to return a JSON response.
 *
 * @return void
 */
function siteVisualOverviewError($statusLine, $message, $asJson)
{
    header($statusLine);

    if ($asJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array(
            'success' => false,
            'error' => $message
        ));
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }

    exit;
}

/**
 * Return a JSON response and stop execution.
 *
 * @param array $payload Response payload.
 *
 * @return void
 */
function siteVisualOverviewJson($payload)
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

/**
 * Read and decode an optional JSON request body.
 *
 * @return array
 */
function siteVisualOverviewReadJsonBody()
{
    $requestBody = file_get_contents('php://input');

    if ($requestBody === false || trim($requestBody) === '') {
        return array();
    }

    $decodedBody = json_decode($requestBody, true);

    return is_array($decodedBody)
        ? $decodedBody
        : array();
}

/**
 * Resolve one request value from JSON, GET, or POST data.
 *
 * @param array  $jsonBody Request JSON.
 * @param string $key      Requested field name.
 * @param mixed  $default  Default value.
 *
 * @return mixed
 */
function siteVisualOverviewRequestValue($jsonBody, $key, $default)
{
    if (isset($jsonBody[$key])) {
        return $jsonBody[$key];
    }

    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    if (isset($_POST[$key])) {
        return $_POST[$key];
    }

    return $default;
}

/**
 * Validate and normalize latitude and longitude.
 *
 * @param mixed   $latitudeRaw  Raw latitude.
 * @param mixed   $longitudeRaw Raw longitude.
 * @param boolean $asJson       Whether errors should be JSON.
 *
 * @return array
 */
function siteVisualOverviewCoordinates($latitudeRaw, $longitudeRaw, $asJson)
{
    if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'Valid latitude and longitude values are required.',
            $asJson
        );
    }

    $latitude = (float) $latitudeRaw;
    $longitude = (float) $longitudeRaw;

    if ($latitude < -90 || $latitude > 90) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'Latitude must be between -90 and 90.',
            $asJson
        );
    }

    if ($longitude < -180 || $longitude > 180) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'Longitude must be between -180 and 180.',
            $asJson
        );
    }

    return array($latitude, $longitude);
}

#endregion

#region Section 2 — Shared Remote Request Helpers

/**
 * Resolve the server-side Google Static Maps API key.
 *
 * @return string
 */
function siteVisualOverviewGoogleKey()
{
    $googleKey = '';

    if (function_exists('skyesoftGetEnv')) {
        $googleKey = (string) skyesoftGetEnv(
            'GOOGLE_MAPS_STATIC_API_KEY'
        );
    }

    if ($googleKey === '') {
        $environmentKey = getenv('GOOGLE_MAPS_STATIC_API_KEY');
        $googleKey = $environmentKey !== false
            ? (string) $environmentKey
            : '';
    }

    return $googleKey;
}

/**
 * Execute a controlled cURL GET request.
 *
 * @param string $url Request URL.
 *
 * @return array
 */
function siteVisualOverviewCurlGet($url)
{
    if (!function_exists('curl_init')) {
        return array(
            'success' => false,
            'data' => '',
            'httpCode' => 0,
            'contentType' => '',
            'error' => 'PHP cURL extension unavailable.'
        );
    }

    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($curl, CURLOPT_TIMEOUT, 25);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt(
        $curl,
        CURLOPT_USERAGENT,
        'Skyesoft-SiteVisualOverview/1.0'
    );

    $responseData = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo(
        $curl,
        CURLINFO_CONTENT_TYPE
    );

    curl_close($curl);

    return array(
        'success' => $responseData !== false && $httpCode === 200,
        'data' => $responseData === false ? '' : $responseData,
        'httpCode' => $httpCode,
        'contentType' => $contentType,
        'error' => $curlError
    );
}

#endregion

#region Section 3 — Satellite Image Handler

/**
 * Stream a satellite image response.
 *
 * @param array  $jsonBody Request JSON.
 * @param string $googleKey Google Maps Static API key.
 *
 * @return void
 */
function siteVisualOverviewSatellite($jsonBody, $googleKey)
{
    $latitudeRaw = siteVisualOverviewRequestValue(
        $jsonBody,
        'latitude',
        ''
    );

    $longitudeRaw = siteVisualOverviewRequestValue(
        $jsonBody,
        'longitude',
        ''
    );

    $coordinates = siteVisualOverviewCoordinates(
        $latitudeRaw,
        $longitudeRaw,
        false
    );

    $zoomRaw = siteVisualOverviewRequestValue($jsonBody, 'zoom', '19');

    if (!is_numeric($zoomRaw)) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'A valid zoom value is required.',
            false
        );
    }

    $zoom = (int) $zoomRaw;

    if ($zoom < 0 || $zoom > 21) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'Zoom must be between 0 and 21.',
            false
        );
    }

    $latitudeParam = number_format($coordinates[0], 7, '.', '');
    $longitudeParam = number_format($coordinates[1], 7, '.', '');
    $coordinateParam = $latitudeParam . ',' . $longitudeParam;

    $googleUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'center=' . rawurlencode($coordinateParam)
        . '&zoom=' . $zoom
        . '&size=600x338'
        . '&scale=2'
        . '&maptype=satellite'
        . '&markers=' . rawurlencode('color:red|' . $coordinateParam)
        . '&format=jpg'
        . '&key=' . rawurlencode($googleKey);

    $imageResponse = siteVisualOverviewCurlGet($googleUrl);
    $isImage = strpos(
        strtolower($imageResponse['contentType']),
        'image/'
    ) === 0;

    if (
        !$imageResponse['success'] ||
        $imageResponse['data'] === '' ||
        !$isImage
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Satellite request failed. HTTP='
            . $imageResponse['httpCode']
            . ' Content-Type=' . $imageResponse['contentType']
            . ' cURL=' . $imageResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 502 Bad Gateway',
            'Unable to retrieve the satellite image.',
            false
        );
    }

    header('Content-Type: ' . $imageResponse['contentType']);
    header('Content-Length: ' . strlen($imageResponse['data']));
    header(
        'Content-Disposition: inline; '
        . 'filename="site-visual-satellite.jpg"'
    );

    echo $imageResponse['data'];
    exit;
}

#endregion

#region Section 4 — Parcel Geometry Projection

/**
 * Project the primary parcel polygon from its source WKID to WGS84.
 *
 * @param array   $parcelGeometry Primary parcel geometry.
 * @param boolean $asJson         Whether errors should be JSON.
 *
 * @return array
 */
function siteVisualOverviewProjectParcel($parcelGeometry, $asJson)
{
    $sourceWkid = isset(
        $parcelGeometry['spatialReference']['wkid']
    )
        ? (int) $parcelGeometry['spatialReference']['wkid']
        : 0;

    $rings = isset($parcelGeometry['rings']) &&
        is_array($parcelGeometry['rings'])
        ? $parcelGeometry['rings']
        : array();

    if ($sourceWkid <= 0 || empty($rings)) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'Primary parcel geometry is incomplete.',
            $asJson
        );
    }

    // Geometry is already expressed as longitude and latitude
    if ($sourceWkid === 4326) {
        return array(
            'rings' => $rings,
            'spatialReference' => array('wkid' => 4326)
        );
    }

    $geometryPayload = json_encode(array(
        'geometryType' => 'esriGeometryPolygon',
        'geometries' => array(array(
            'rings' => $rings,
            'spatialReference' => array('wkid' => $sourceWkid)
        ))
    ));

    $projectionUrl =
        'https://utility.arcgisonline.com/ArcGIS/rest/services/'
        . 'Geometry/GeometryServer/project'
        . '?f=json'
        . '&inSR=' . $sourceWkid
        . '&outSR=4326'
        . '&geometries=' . rawurlencode($geometryPayload);

    $projectionResponse = siteVisualOverviewCurlGet($projectionUrl);
    $projectionData = json_decode($projectionResponse['data'], true);

    if (
        !$projectionResponse['success'] ||
        !is_array($projectionData) ||
        !isset($projectionData['geometries'][0]['rings']) ||
        !is_array($projectionData['geometries'][0]['rings'])
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Parcel projection failed. HTTP='
            . $projectionResponse['httpCode']
            . ' cURL=' . $projectionResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 502 Bad Gateway',
            'Unable to project the primary parcel boundary.',
            $asJson
        );
    }

    return array(
        'rings' => $projectionData['geometries'][0]['rings'],
        'spatialReference' => array('wkid' => 4326)
    );
}

/**
 * Build Google Static Maps path coordinates from projected rings.
 *
 * @param array $rings Projected WGS84 rings.
 *
 * @return string
 */
function siteVisualOverviewParcelPath($rings)
{
    if (empty($rings[0]) || !is_array($rings[0])) {
        return '';
    }

    $pathPoints = array();

    foreach ($rings[0] as $point) {
        if (
            !is_array($point) ||
            !isset($point[0]) ||
            !isset($point[1]) ||
            !is_numeric($point[0]) ||
            !is_numeric($point[1])
        ) {
            continue;
        }

        // ArcGIS polygon coordinates are longitude, latitude
        $pathPoints[] = number_format((float) $point[1], 7, '.', '')
            . ','
            . number_format((float) $point[0], 7, '.', '');
    }

    return implode('|', $pathPoints);
}

#endregion

#region Section 5 — Parcel Artifact Handler

/**
 * Create the primary-parcel aerial artifact and return its URL.
 *
 * @param array  $jsonBody Request JSON.
 * @param string $googleKey Google Maps Static API key.
 *
 * @return void
 */
function siteVisualOverviewParcel($jsonBody, $googleKey)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        siteVisualOverviewError(
            'HTTP/1.1 405 Method Not Allowed',
            'Parcel images require a POST JSON request.',
            true
        );
    }

    $coordinates = siteVisualOverviewCoordinates(
        siteVisualOverviewRequestValue($jsonBody, 'latitude', ''),
        siteVisualOverviewRequestValue($jsonBody, 'longitude', ''),
        true
    );

    $parcel = isset($jsonBody['parcel']) && is_array($jsonBody['parcel'])
        ? $jsonBody['parcel']
        : array();

    $parcelNumber = isset($parcel['parcelNumber'])
        ? trim((string) $parcel['parcelNumber'])
        : '';

    $parcelGeometry = isset($parcel['parcelGeometry']) &&
        is_array($parcel['parcelGeometry'])
        ? $parcel['parcelGeometry']
        : array();

    if ($parcelNumber === '' || empty($parcelGeometry)) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'The primary parcel number and geometry are required.',
            true
        );
    }

    $projectedGeometry = siteVisualOverviewProjectParcel(
        $parcelGeometry,
        true
    );

    $parcelPath = siteVisualOverviewParcelPath(
        $projectedGeometry['rings']
    );

    if ($parcelPath === '') {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'The primary parcel boundary contains no valid points.',
            true
        );
    }

    $latitudeParam = number_format($coordinates[0], 7, '.', '');
    $longitudeParam = number_format($coordinates[1], 7, '.', '');
    $coordinateParam = $latitudeParam . ',' . $longitudeParam;

    // Let the parcel path determine the map extent and preserve the marker
    $googleUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'size=600x338'
        . '&scale=2'
        . '&maptype=satellite'
        . '&path=' . rawurlencode(
            'color:0x1976D2FF|weight:4|fillcolor:0x1976D233|'
            . $parcelPath
        )
        . '&markers=' . rawurlencode('color:red|' . $coordinateParam)
        . '&format=jpg'
        . '&key=' . rawurlencode($googleKey);

    $imageResponse = siteVisualOverviewCurlGet($googleUrl);
    $isImage = strpos(
        strtolower($imageResponse['contentType']),
        'image/'
    ) === 0;

    if (
        !$imageResponse['success'] ||
        $imageResponse['data'] === '' ||
        !$isImage
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Parcel image request failed. HTTP='
            . $imageResponse['httpCode']
            . ' Content-Type=' . $imageResponse['contentType']
            . ' cURL=' . $imageResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 502 Bad Gateway',
            'Unable to retrieve the parcel image.',
            true
        );
    }

    $artifactDirectory = dirname(__DIR__) . '/artifacts';

    if (!is_dir($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is unavailable.',
            true
        );
    }

    if (!is_writable($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is not writable.',
            true
        );
    }

    $safeParcelNumber = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        $parcelNumber
    );

    if ($safeParcelNumber === '') {
        $safeParcelNumber = 'unknown';
    }

    $uniqueToken = function_exists('openssl_random_pseudo_bytes')
        ? bin2hex(openssl_random_pseudo_bytes(6))
        : substr(md5(uniqid('', true)), 0, 12);

    $artifactFilename = 'tmp-site-visual-parcel-'
        . $safeParcelNumber
        . '-'
        . time()
        . '-'
        . $uniqueToken
        . '.jpg';

    $artifactPath = $artifactDirectory . '/' . $artifactFilename;
    $bytesWritten = file_put_contents(
        $artifactPath,
        $imageResponse['data'],
        LOCK_EX
    );

    if ($bytesWritten === false || $bytesWritten <= 0) {
        error_log(
            '[SITE VISUAL IMAGES] Unable to write parcel artifact: '
            . $artifactPath
        );

        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'Unable to create the temporary parcel artifact.',
            true
        );
    }

    siteVisualOverviewJson(array(
        'success' => true,
        'status' => 'ready',
        'type' => 'parcel',
        'parcelNumber' => $parcelNumber,
        'artifactFilename' => $artifactFilename,
        'artifactUrl' => '/skyesoft/artifacts/' . $artifactFilename,
        'createdAt' => time(),
        'temporary' => true
    ));
}

#endregion

#region Section 6 — Street View Artifact Handler

/**
 * Calculate the initial compass bearing between two WGS84 points.
 *
 * @param float $fromLatitude  Panorama latitude.
 * @param float $fromLongitude Panorama longitude.
 * @param float $toLatitude    Property latitude.
 * @param float $toLongitude   Property longitude.
 *
 * @return float
 */
function siteVisualOverviewBearing(
    $fromLatitude,
    $fromLongitude,
    $toLatitude,
    $toLongitude
) {
    $fromLatitudeRadians = deg2rad($fromLatitude);
    $toLatitudeRadians = deg2rad($toLatitude);
    $longitudeDifference = deg2rad(
        $toLongitude - $fromLongitude
    );

    $bearingY = sin($longitudeDifference) *
        cos($toLatitudeRadians);

    $bearingX = cos($fromLatitudeRadians) *
        sin($toLatitudeRadians) -
        sin($fromLatitudeRadians) *
        cos($toLatitudeRadians) *
        cos($longitudeDifference);

    $bearing = rad2deg(atan2($bearingY, $bearingX));

    return fmod($bearing + 360.0, 360.0);
}

/**
 * Resolve the Phoenix odd/even addressing notation.
 *
 * Odd-numbered properties are generally located on the east side of
 * north-south streets or the south side of east-west streets. Even-numbered
 * properties are generally located on the opposing sides.
 *
 * @param string $address Validated property address.
 *
 * @return array
 */
function siteVisualOverviewAddressParity($address)
{
    $streetNumber = null;

    if (preg_match('/^\s*(\d+)/', $address, $matches)) {
        $streetNumber = (int) $matches[1];
    }

    if ($streetNumber === null) {
        return array(
            'streetNumber' => null,
            'parity' => 'unavailable',
            'propertySideNotation' => 'Address parity unavailable.'
        );
    }

    $isOdd = ($streetNumber % 2) === 1;

    return array(
        'streetNumber' => $streetNumber,
        'parity' => $isOdd ? 'odd' : 'even',
        'propertySideNotation' => $isOdd
            ? 'Odd address: east side of a north-south street or south '
                . 'side of an east-west street.'
            : 'Even address: west side of a north-south street or north '
                . 'side of an east-west street.'
    );
}

/**
 * Create the default Street View artifact aimed toward the property.
 *
 * @param array  $jsonBody Request JSON.
 * @param string $googleKey Google Maps Static API key.
 *
 * @return void
 */
function siteVisualOverviewStreetView($jsonBody, $googleKey)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        siteVisualOverviewError(
            'HTTP/1.1 405 Method Not Allowed',
            'Street View images require a POST JSON request.',
            true
        );
    }

    $coordinates = siteVisualOverviewCoordinates(
        siteVisualOverviewRequestValue($jsonBody, 'latitude', ''),
        siteVisualOverviewRequestValue($jsonBody, 'longitude', ''),
        true
    );

    $address = trim((string) siteVisualOverviewRequestValue(
        $jsonBody,
        'address',
        ''
    ));

    if ($address === '') {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'A validated property address is required.',
            true
        );
    }

    $propertyLatitude = $coordinates[0];
    $propertyLongitude = $coordinates[1];
    $addressParity = siteVisualOverviewAddressParity($address);

    // Resolve the nearest available panorama for the validated address
    $metadataUrl =
        'https://maps.googleapis.com/maps/api/streetview/metadata?'
        . 'location=' . rawurlencode($address)
        . '&source=outdoor'
        . '&key=' . rawurlencode($googleKey);

    $metadataResponse = siteVisualOverviewCurlGet($metadataUrl);
    $metadata = json_decode($metadataResponse['data'], true);

    $metadataStatus = is_array($metadata) && isset($metadata['status'])
        ? (string) $metadata['status']
        : 'UNKNOWN';

    if (
        !$metadataResponse['success'] ||
        !is_array($metadata) ||
        $metadataStatus !== 'OK' ||
        empty($metadata['pano_id']) ||
        !isset($metadata['location']['lat']) ||
        !isset($metadata['location']['lng']) ||
        !is_numeric($metadata['location']['lat']) ||
        !is_numeric($metadata['location']['lng'])
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Street View metadata unavailable. '
            . 'Status=' . $metadataStatus
            . ' HTTP=' . $metadataResponse['httpCode']
            . ' cURL=' . $metadataResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 404 Not Found',
            'No usable outdoor Street View panorama was found.',
            true
        );
    }

    $panoramaId = (string) $metadata['pano_id'];
    $panoramaLatitude = (float) $metadata['location']['lat'];
    $panoramaLongitude = (float) $metadata['location']['lng'];

    // Aim the camera from the panorama directly toward the property
    $heading = siteVisualOverviewBearing(
        $panoramaLatitude,
        $panoramaLongitude,
        $propertyLatitude,
        $propertyLongitude
    );

    $fovRaw = siteVisualOverviewRequestValue($jsonBody, 'fov', 75);
    $pitchRaw = siteVisualOverviewRequestValue($jsonBody, 'pitch', 5);
    $fov = is_numeric($fovRaw) ? (int) $fovRaw : 75;
    $pitch = is_numeric($pitchRaw) ? (int) $pitchRaw : 5;

    // Enforce Google Street View image limits
    $fov = max(10, min(120, $fov));
    $pitch = max(-90, min(90, $pitch));

    $streetViewUrl =
        'https://maps.googleapis.com/maps/api/streetview?'
        . 'size=600x338'
        . '&scale=2'
        . '&pano=' . rawurlencode($panoramaId)
        . '&heading=' . rawurlencode(
            number_format($heading, 2, '.', '')
        )
        . '&fov=' . $fov
        . '&pitch=' . $pitch
        . '&source=outdoor'
        . '&return_error_code=true'
        . '&key=' . rawurlencode($googleKey);

    $imageResponse = siteVisualOverviewCurlGet($streetViewUrl);
    $isImage = strpos(
        strtolower($imageResponse['contentType']),
        'image/'
    ) === 0;

    if (
        !$imageResponse['success'] ||
        $imageResponse['data'] === '' ||
        !$isImage
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Street View image request failed. HTTP='
            . $imageResponse['httpCode']
            . ' Content-Type=' . $imageResponse['contentType']
            . ' cURL=' . $imageResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 502 Bad Gateway',
            'Unable to retrieve the Street View image.',
            true
        );
    }

    $artifactDirectory = dirname(__DIR__) . '/artifacts';

    if (!is_dir($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is unavailable.',
            true
        );
    }

    if (!is_writable($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is not writable.',
            true
        );
    }

    $uniqueToken = bin2hex(random_bytes(6));
    $artifactFilename = 'tmp-site-visual-street-view-1-'
        . time()
        . '-'
        . $uniqueToken
        . '.jpg';

    $artifactPath = $artifactDirectory . '/' . $artifactFilename;
    $bytesWritten = file_put_contents(
        $artifactPath,
        $imageResponse['data'],
        LOCK_EX
    );

    if ($bytesWritten === false || $bytesWritten <= 0) {
        error_log(
            '[SITE VISUAL IMAGES] Unable to write Street View artifact: '
            . $artifactPath
        );

        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'Unable to create the temporary Street View artifact.',
            true
        );
    }

    siteVisualOverviewJson(array(
        'success' => true,
        'status' => 'ready',
        'type' => 'streetView',
        'viewIndex' => 1,
        'address' => $address,
        'propertyLatitude' => $propertyLatitude,
        'propertyLongitude' => $propertyLongitude,
        'panoramaId' => $panoramaId,
        'panoramaLatitude' => $panoramaLatitude,
        'panoramaLongitude' => $panoramaLongitude,
        'heading' => round($heading, 2),
        'headingSource' => 'panoramaToPropertyBearing',
        'addressParity' => $addressParity,
        'fov' => $fov,
        'pitch' => $pitch,
        'artifactFilename' => $artifactFilename,
        'artifactUrl' => '/skyesoft/artifacts/' . $artifactFilename,
        'createdAt' => time(),
        'temporary' => true
    ));
}

#endregion

#region Section 7 — Immediate Vicinity Artifact Handler

/**
 * Create the Immediate Vicinity labeled roadmap artifact.
 *
 * Uses a transparent context path to force an approximately 1.25-mile
 * map extent so nearby major intersections remain visible. Overlays the
 * primary parcel boundary (blue) and a red property marker. The image
 * is stored as a temporary artifact under /skyesoft/artifacts.
 *
 * @param array  $jsonBody  Request JSON.
 * @param string $googleKey Google Maps Static API key.
 *
 * @return void
 */
function siteVisualOverviewImmediateVicinity($jsonBody, $googleKey)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        siteVisualOverviewError(
            'HTTP/1.1 405 Method Not Allowed',
            'Immediate Vicinity images require a POST JSON request.',
            true
        );
    }

    $coordinates = siteVisualOverviewCoordinates(
        siteVisualOverviewRequestValue($jsonBody, 'latitude', ''),
        siteVisualOverviewRequestValue($jsonBody, 'longitude', ''),
        true
    );

    $parcel = isset($jsonBody['parcel']) && is_array($jsonBody['parcel'])
        ? $jsonBody['parcel']
        : array();

    $parcelNumber = isset($parcel['parcelNumber'])
        ? trim((string) $parcel['parcelNumber'])
        : '';

    $parcelGeometry = isset($parcel['parcelGeometry']) &&
        is_array($parcel['parcelGeometry'])
        ? $parcel['parcelGeometry']
        : array();

    if ($parcelNumber === '' || empty($parcelGeometry)) {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'The primary parcel number and geometry are required.',
            true
        );
    }

    $projectedGeometry = siteVisualOverviewProjectParcel(
        $parcelGeometry,
        true
    );

    $parcelPath = siteVisualOverviewParcelPath(
        $projectedGeometry['rings']
    );

    if ($parcelPath === '') {
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'The primary parcel boundary contains no valid points.',
            true
        );
    }

    $latitude = $coordinates[0];
    $longitude = $coordinates[1];

    // Approximate 1.25-mile square context extent centered on the property.
    // This forces the Static Maps viewport to include nearby major
    // intersections while still framing the parcel and marker tightly.
    $contextMiles = 1.25;
    $halfMiles = $contextMiles / 2.0;
    $latRad = deg2rad($latitude);
    $milesPerDegreeLat = 69.0;
    $milesPerDegreeLon = 69.0 * cos($latRad);
    $dLat = $halfMiles / $milesPerDegreeLat;
    $dLon = $halfMiles / $milesPerDegreeLon;

    $contextPoints = array(
        number_format($latitude + $dLat, 7, '.', '')
            . ','
            . number_format($longitude + $dLon, 7, '.', ''),
        number_format($latitude + $dLat, 7, '.', '')
            . ','
            . number_format($longitude - $dLon, 7, '.', ''),
        number_format($latitude - $dLat, 7, '.', '')
            . ','
            . number_format($longitude - $dLon, 7, '.', ''),
        number_format($latitude - $dLat, 7, '.', '')
            . ','
            . number_format($longitude + $dLon, 7, '.', ''),
        number_format($latitude + $dLat, 7, '.', '')
            . ','
            . number_format($longitude + $dLon, 7, '.', '')
    );

    $contextPath = implode('|', $contextPoints);

    $latitudeParam = number_format($latitude, 7, '.', '');
    $longitudeParam = number_format($longitude, 7, '.', '');
    $coordinateParam = $latitudeParam . ',' . $longitudeParam;

    // Transparent context path forces the 1.25-mile extent.
    // Visible path draws the primary parcel boundary.
    // Red marker identifies the validated property location.
    $googleUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'size=600x338'
        . '&scale=2'
        . '&maptype=roadmap'
        . '&path=' . rawurlencode(
            'color:0x00000000|weight:1|fillcolor:0x00000000|'
            . $contextPath
        )
        . '&path=' . rawurlencode(
            'color:0x1976D2FF|weight:4|fillcolor:0x1976D233|'
            . $parcelPath
        )
        . '&markers=' . rawurlencode('color:red|' . $coordinateParam)
        . '&format=jpg'
        . '&key=' . rawurlencode($googleKey);

    $imageResponse = siteVisualOverviewCurlGet($googleUrl);
    $isImage = strpos(
        strtolower($imageResponse['contentType']),
        'image/'
    ) === 0;

    if (
        !$imageResponse['success'] ||
        $imageResponse['data'] === '' ||
        !$isImage
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Immediate Vicinity image request failed. HTTP='
            . $imageResponse['httpCode']
            . ' Content-Type=' . $imageResponse['contentType']
            . ' cURL=' . $imageResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 502 Bad Gateway',
            'Unable to retrieve the Immediate Vicinity image.',
            true
        );
    }

    $artifactDirectory = dirname(__DIR__) . '/artifacts';

    if (!is_dir($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is unavailable.',
            true
        );
    }

    if (!is_writable($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is not writable.',
            true
        );
    }

    $safeParcelNumber = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        $parcelNumber
    );

    if ($safeParcelNumber === '') {
        $safeParcelNumber = 'unknown';
    }

    $uniqueToken = function_exists('random_bytes')
        ? bin2hex(random_bytes(6))
        : (function_exists('openssl_random_pseudo_bytes')
            ? bin2hex(openssl_random_pseudo_bytes(6))
            : substr(md5(uniqid('', true)), 0, 12));

    $artifactFilename = 'tmp-site-visual-immediate-vicinity-'
        . $safeParcelNumber
        . '-'
        . time()
        . '-'
        . $uniqueToken
        . '.jpg';

    $artifactPath = $artifactDirectory . '/' . $artifactFilename;
    $bytesWritten = file_put_contents(
        $artifactPath,
        $imageResponse['data'],
        LOCK_EX
    );

    if ($bytesWritten === false || $bytesWritten <= 0) {
        error_log(
            '[SITE VISUAL IMAGES] Unable to write Immediate Vicinity artifact: '
            . $artifactPath
        );

        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'Unable to create the temporary Immediate Vicinity artifact.',
            true
        );
    }

    siteVisualOverviewJson(array(
        'success' => true,
        'status' => 'ready',
        'type' => 'immediateVicinity',
        'parcelNumber' => $parcelNumber,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'contextMiles' => $contextMiles,
        'artifactFilename' => $artifactFilename,
        'artifactUrl' => '/skyesoft/artifacts/' . $artifactFilename,
        'createdAt' => time(),
        'temporary' => true
    ));
}

#endregion

#region Section 8 — Extended Context Artifact Handle

/**
 * Resolve the server-side Google Maps Backend API key
 * (used for Directions / server-to-server calls).
 *
 * @return string
 */
function siteVisualOverviewBackendKey()
{
    $backendKey = '';

    if (function_exists('skyesoftGetEnv')) {
        $backendKey = (string) skyesoftGetEnv(
            'GOOGLE_MAPS_BACKEND_API_KEY'
        );
    }

    if ($backendKey === '') {
        $environmentKey = getenv('GOOGLE_MAPS_BACKEND_API_KEY');
        $backendKey = $environmentKey !== false
            ? (string) $environmentKey
            : '';
    }

    return $backendKey;
}

/**
 * Calculate the great-circle (straight-line) distance in miles
 * between two WGS84 points using the Haversine formula.
 *
 * @param float $lat1 Origin latitude.
 * @param float $lon1 Origin longitude.
 * @param float $lat2 Destination latitude.
 * @param float $lon2 Destination longitude.
 *
 * @return float Distance in miles.
 */
function siteVisualOverviewHaversineMiles($lat1, $lon1, $lat2, $lon2)
{
    $earthRadiusMiles = 3958.7613;

    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);

    $a = sin($deltaLat / 2) * sin($deltaLat / 2)
        + cos($lat1Rad) * cos($lat2Rad)
        * sin($deltaLon / 2) * sin($deltaLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return round($earthRadiusMiles * $c, 2);
}

/**
 * Load Christy Signs primary location (Entity 1) from tblLocations.
 *
 * Prefers the billing location for locationEntityId = 1.
 *
 * @param boolean $asJson Whether errors should be returned as JSON.
 *
 * @return array Associative array with latitude, longitude, name, address.
 */
function siteVisualOverviewLoadChristyLocation($asJson)
{
    $latitude = null;
    $longitude = null;
    $name = 'Christy Signs';
    $address = '';

    // Attempt to use an already-available PDO connection if present
    $pdo = null;
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    } elseif (function_exists('skyesoftGetPdo')) {
        $pdo = skyesoftGetPdo();
    }

    // Fallback: build a short-lived PDO from environment variables
    if (!($pdo instanceof PDO) && function_exists('skyesoftGetEnv')) {
        $dbHost = (string) skyesoftGetEnv('DB_HOST');
        $dbName = (string) skyesoftGetEnv('DB_NAME');
        $dbUser = (string) skyesoftGetEnv('DB_USER');
        $dbPass = (string) skyesoftGetEnv('DB_PASS');

        if ($dbHost === '') {
            $dbHost = (string) skyesoftGetEnv('DB_HOSTNAME');
        }
        if ($dbName === '') {
            $dbName = (string) skyesoftGetEnv('DB_DATABASE');
        }
        if ($dbUser === '') {
            $dbUser = (string) skyesoftGetEnv('DB_USERNAME');
        }
        if ($dbPass === '') {
            $dbPass = (string) skyesoftGetEnv('DB_PASSWORD');
        }

        if ($dbHost !== '' && $dbName !== '' && $dbUser !== '') {
            try {
                $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName
                    . ';charset=utf8mb4';
                $pdo = new PDO(
                    $dsn,
                    $dbUser,
                    $dbPass,
                    array(
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false
                    )
                );
            } catch (Exception $e) {
                error_log(
                    '[SITE VISUAL IMAGES] DB connection failed: '
                    . $e->getMessage()
                );
                $pdo = null;
            }
        }
    }

    if ($pdo instanceof PDO) {
        try {
            $sql = 'SELECT locationLatitude, locationLongitude, '
                . 'locationName, locationAddress, locationCity, '
                . 'locationState, locationZip '
                . 'FROM tblLocations '
                . 'WHERE locationEntityId = 1 '
                . 'AND locationIsNotValid = 0 '
                . 'ORDER BY locationIsBilling DESC, locationId ASC '
                . 'LIMIT 1';

            $stmt = $pdo->query($sql);
            $row = $stmt ? $stmt->fetch() : false;

            if (is_array($row)
                && isset($row['locationLatitude'])
                && isset($row['locationLongitude'])
                && is_numeric($row['locationLatitude'])
                && is_numeric($row['locationLongitude'])
            ) {
                $latitude = (float) $row['locationLatitude'];
                $longitude = (float) $row['locationLongitude'];

                if (!empty($row['locationName'])) {
                    $name = trim((string) $row['locationName']);
                }

                $parts = array();
                if (!empty($row['locationAddress'])) {
                    $parts[] = trim((string) $row['locationAddress']);
                }
                if (!empty($row['locationCity'])) {
                    $parts[] = trim((string) $row['locationCity']);
                }
                if (!empty($row['locationState'])) {
                    $parts[] = trim((string) $row['locationState']);
                }
                if (!empty($row['locationZip'])) {
                    $parts[] = trim((string) $row['locationZip']);
                }
                $address = implode(', ', $parts);
            }
        } catch (Exception $e) {
            error_log(
                '[SITE VISUAL IMAGES] Failed to load Christy Signs location: '
                . $e->getMessage()
            );
        }
    }

    // Hard fallback matching the known Entity 1 / billing row if DB is unreachable
    if ($latitude === null || $longitude === null) {
        $latitude = 33.4848523;
        $longitude = -112.1288006;
        $name = 'Christy Signs';
        $address = '3145 N 33rd Ave, Phoenix, AZ 85017';

        error_log(
            '[SITE VISUAL IMAGES] Using hard-coded Christy Signs coordinates '
            . '(DB lookup unavailable).'
        );
    }

    return array(
        'latitude' => $latitude,
        'longitude' => $longitude,
        'name' => $name,
        'address' => $address
    );
}

/**
 * Create the Extended Context roadmap artifact showing the fastest
 * driving route from Christy Signs to the destination property.
 *
 * Returns the artifact URL plus driving and straight-line metrics
 * so the client can render a caption under the image.
 *
 * @param array  $jsonBody  Request JSON.
 * @param string $googleKey Google Maps Static API key (for the final image).
 *
 * @return void
 */
function siteVisualOverviewExtendedContext($jsonBody, $googleKey)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        siteVisualOverviewError(
            'HTTP/1.1 405 Method Not Allowed',
            'Extended Context images require a POST JSON request.',
            true
        );
    }

    // Accept either top-level latitude/longitude/address or the nested
    // destination object used by the current client payload.
    $latitudeRaw = siteVisualOverviewRequestValue($jsonBody, 'latitude', '');
    $longitudeRaw = siteVisualOverviewRequestValue($jsonBody, 'longitude', '');
    $destinationAddress = trim((string) siteVisualOverviewRequestValue(
        $jsonBody,
        'address',
        ''
    ));

    if (isset($jsonBody['destination']) && is_array($jsonBody['destination'])) {
        $destination = $jsonBody['destination'];

        if (($latitudeRaw === '' || $latitudeRaw === null)
            && isset($destination['latitude'])
        ) {
            $latitudeRaw = $destination['latitude'];
        }

        if (($longitudeRaw === '' || $longitudeRaw === null)
            && isset($destination['longitude'])
        ) {
            $longitudeRaw = $destination['longitude'];
        }

        if ($destinationAddress === ''
            && isset($destination['address'])
        ) {
            $destinationAddress = trim((string) $destination['address']);
        }
    }

    $destinationCoordinates = siteVisualOverviewCoordinates(
        $latitudeRaw,
        $longitudeRaw,
        true
    );

    $destinationLatitude = $destinationCoordinates[0];
    $destinationLongitude = $destinationCoordinates[1];

    // Load Christy Signs (Entity 1) primary / billing location
    $origin = siteVisualOverviewLoadChristyLocation(true);
    $originLatitude = $origin['latitude'];
    $originLongitude = $origin['longitude'];
    $originName = $origin['name'];
    $originAddress = $origin['address'];

    $backendKey = siteVisualOverviewBackendKey();

    if ($backendKey === '') {
        error_log(
            '[SITE VISUAL IMAGES] Google Maps Backend API key missing.'
        );
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'Directions service is not configured.',
            true
        );
    }

    // Request the fastest driving route (classic Directions API)
    $originParam = number_format($originLatitude, 7, '.', '')
        . ','
        . number_format($originLongitude, 7, '.', '');
    $destinationParam = number_format($destinationLatitude, 7, '.', '')
        . ','
        . number_format($destinationLongitude, 7, '.', '');

    $directionsUrl =
        'https://maps.googleapis.com/maps/api/directions/json?'
        . 'origin=' . rawurlencode($originParam)
        . '&destination=' . rawurlencode($destinationParam)
        . '&mode=driving'
        . '&units=imperial'
        . '&key=' . rawurlencode($backendKey);

    $directionsResponse = siteVisualOverviewCurlGet($directionsUrl);
    $directionsData = json_decode($directionsResponse['data'], true);

    $directionsStatus = is_array($directionsData)
        && isset($directionsData['status'])
        ? (string) $directionsData['status']
        : 'UNKNOWN';

    if (
        !$directionsResponse['success']
        || !is_array($directionsData)
        || $directionsStatus !== 'OK'
        || empty($directionsData['routes'][0]['overview_polyline']['points'])
        || empty($directionsData['routes'][0]['legs'][0])
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Directions request failed. Status='
            . $directionsStatus
            . ' HTTP=' . $directionsResponse['httpCode']
            . ' cURL=' . $directionsResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 502 Bad Gateway',
            'Unable to retrieve the driving route.',
            true
        );
    }

    $route = $directionsData['routes'][0];
    $leg = $route['legs'][0];

    $encodedPolyline = (string) $route['overview_polyline']['points'];
    $drivingDistanceText = isset($leg['distance']['text'])
        ? (string) $leg['distance']['text']
        : '';
    $drivingDistanceMeters = isset($leg['distance']['value'])
        ? (int) $leg['distance']['value']
        : 0;
    $drivingDurationText = isset($leg['duration']['text'])
        ? (string) $leg['duration']['text']
        : '';
    $drivingDurationSeconds = isset($leg['duration']['value'])
        ? (int) $leg['duration']['value']
        : 0;
    $routeSummary = isset($route['summary'])
        ? (string) $route['summary']
        : '';

    // Straight-line (great-circle) distance
    $straightLineMiles = siteVisualOverviewHaversineMiles(
        $originLatitude,
        $originLongitude,
        $destinationLatitude,
        $destinationLongitude
    );

    // Build the Static Map
    // - encoded driving route polyline (blue)
    // - direct geodesic line between the two points (gray)
    // - two red labeled markers (C = Christy Signs, D = Destination)
    $directPath = number_format($originLatitude, 7, '.', '')
        . ','
        . number_format($originLongitude, 7, '.', '')
        . '|'
        . number_format($destinationLatitude, 7, '.', '')
        . ','
        . number_format($destinationLongitude, 7, '.', '');

    $googleUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'size=600x338'
        . '&scale=2'
        . '&maptype=roadmap'
        . '&path=' . rawurlencode(
            'color:0x1976D2FF|weight:5|enc:' . $encodedPolyline
        )
        . '&path=' . rawurlencode(
            'color:0x757575AA|weight:2|' . $directPath
        )
        . '&markers=' . rawurlencode(
            'color:red|label:C|' . $originParam
        )
        . '&markers=' . rawurlencode(
            'color:red|label:D|' . $destinationParam
        )
        . '&format=jpg'
        . '&key=' . rawurlencode($googleKey);

    $imageResponse = siteVisualOverviewCurlGet($googleUrl);
    $isImage = strpos(
        strtolower($imageResponse['contentType']),
        'image/'
    ) === 0;

    if (
        !$imageResponse['success']
        || $imageResponse['data'] === ''
        || !$isImage
    ) {
        error_log(
            '[SITE VISUAL IMAGES] Extended Context image request failed. HTTP='
            . $imageResponse['httpCode']
            . ' Content-Type=' . $imageResponse['contentType']
            . ' cURL=' . $imageResponse['error']
        );

        siteVisualOverviewError(
            'HTTP/1.1 502 Bad Gateway',
            'Unable to retrieve the Extended Context image.',
            true
        );
    }

    $artifactDirectory = dirname(__DIR__) . '/artifacts';

    if (!is_dir($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is unavailable.',
            true
        );
    }

    if (!is_writable($artifactDirectory)) {
        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'The Skyesoft artifacts directory is not writable.',
            true
        );
    }

    $uniqueToken = function_exists('random_bytes')
        ? bin2hex(random_bytes(6))
        : (function_exists('openssl_random_pseudo_bytes')
            ? bin2hex(openssl_random_pseudo_bytes(6))
            : substr(md5(uniqid('', true)), 0, 12));

    $artifactFilename = 'tmp-site-visual-extended-context-'
        . time()
        . '-'
        . $uniqueToken
        . '.jpg';

    $artifactPath = $artifactDirectory . '/' . $artifactFilename;
    $bytesWritten = file_put_contents(
        $artifactPath,
        $imageResponse['data'],
        LOCK_EX
    );

    if ($bytesWritten === false || $bytesWritten <= 0) {
        error_log(
            '[SITE VISUAL IMAGES] Unable to write Extended Context artifact: '
            . $artifactPath
        );

        siteVisualOverviewError(
            'HTTP/1.1 500 Internal Server Error',
            'Unable to create the temporary Extended Context artifact.',
            true
        );
    }

    siteVisualOverviewJson(array(
        'success' => true,
        'status' => 'ready',
        'type' => 'extendedContext',
        'origin' => array(
            'name' => $originName,
            'address' => $originAddress,
            'latitude' => $originLatitude,
            'longitude' => $originLongitude
        ),
        'destination' => array(
            'address' => $destinationAddress,
            'latitude' => $destinationLatitude,
            'longitude' => $destinationLongitude
        ),
        'drivingDistanceText' => $drivingDistanceText,
        'drivingDistanceMeters' => $drivingDistanceMeters,
        'drivingDurationText' => $drivingDurationText,
        'drivingDurationSeconds' => $drivingDurationSeconds,
        'straightLineMiles' => $straightLineMiles,
        'routeSummary' => $routeSummary,
        'artifactFilename' => $artifactFilename,
        'artifactUrl' => '/skyesoft/artifacts/' . $artifactFilename,
        'createdAt' => time(),
        'temporary' => true
    ));
}

#endregion

#region Section 9 — Request Router

$jsonBody = siteVisualOverviewReadJsonBody();
$imageType = trim((string) siteVisualOverviewRequestValue(
    $jsonBody,
    'type',
    'satellite'
));

$googleKey = siteVisualOverviewGoogleKey();

if ($googleKey === '') {
    error_log(
        '[SITE VISUAL IMAGES] Google Maps Static API key missing.'
    );

    siteVisualOverviewError(
        'HTTP/1.1 500 Internal Server Error',
        'Site Visual Overview image service is not configured.',
        $imageType === 'parcel'
            || $imageType === 'streetView'
            || $imageType === 'immediateVicinity'
            || $imageType === 'extendedContext'
    );
}

// Route supported image request
switch ($imageType) {
    case 'satellite':
        siteVisualOverviewSatellite($jsonBody, $googleKey);
        break;

    case 'parcel':
        siteVisualOverviewParcel($jsonBody, $googleKey);
        break;

    case 'streetView':
        siteVisualOverviewStreetView($jsonBody, $googleKey);
        break;

    case 'immediateVicinity':
        siteVisualOverviewImmediateVicinity($jsonBody, $googleKey);
        break;

    case 'extendedContext':
        siteVisualOverviewExtendedContext($jsonBody, $googleKey);
        break;

    default:
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'Unsupported Site Visual Overview image type.',
            true
        );
        break;
}

#endregion