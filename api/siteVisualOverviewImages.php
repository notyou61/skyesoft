<?php

// #region Section 0 — Endpoint Identity & Environment Bootstrap

/**
 * Skyesoft Site Visual Overview image endpoint.
 *
 * Supported image types:
 * - satellite: Streams a Google Static Maps satellite image.
 * - parcel: Creates a temporary aerial image for the primary parcel and
 *   returns its public artifact URL as JSON.
 *
 * Parcel request contract (POST JSON):
 * {
 *     "type": "parcel",
 *     "latitude": 33.4848523,
 *     "longitude": -112.1288006,
 *     "parcel": {
 *         "parcelNumber": "10803009E",
 *         "parcelGeometry": {
 *             "geometryType": "polygon",
 *             "spatialReference": { "wkid": 2223 },
 *             "rings": [],
 *             "bounds": {}
 *         }
 *     }
 * }
 *
 * Compatibility: PHP 5.3+
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

// #endregion

// #region Section 1 — Shared Response & Request Helpers

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

// #endregion

// #region Section 2 — Shared Remote Request Helpers

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

// #endregion

// #region Section 3 — Satellite Image Handler

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

// #endregion

// #region Section 4 — Parcel Geometry Projection

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

// #endregion

// #region Section 5 — Parcel Artifact Handler

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

// #endregion

// #region Section 6 — Request Router

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
    case 'immediateVicinity':
    case 'extendedContext':
        siteVisualOverviewError(
            'HTTP/1.1 501 Not Implemented',
            'The requested image type is reserved but not implemented.',
            true
        );
        break;

    default:
        siteVisualOverviewError(
            'HTTP/1.1 400 Bad Request',
            'Unsupported Site Visual Overview image type.',
            true
        );
        break;
}

// #endregion
