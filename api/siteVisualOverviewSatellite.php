<?php

// #region Site Visual Overview Satellite Image

/**
 * Skyesoft Site Visual Overview satellite-image endpoint.
 *
 * Purpose:
 * - Accept validated latitude, longitude, and zoom values.
 * - Retrieve a Google Static Maps satellite image.
 * - Stream the image without database writes or permanent file creation.
 *
 * Compatibility: PHP 5.3+
 */

// Load Skyesoft environment
require_once __DIR__ . '/../utils/envLoader.php';

if (function_exists('skyesoftLoadEnv')) {
    skyesoftLoadEnv();
}

// Prevent caching of ephemeral preview responses
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

/**
 * Return a controlled text error response and stop execution.
 *
 * @param string $statusLine HTTP response status line.
 * @param string $message    Plain-text response message.
 *
 * @return void
 */
function siteVisualSatelliteError($statusLine, $message)
{
    header($statusLine);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

// Resolve request values
$latitudeRaw = isset($_GET['latitude'])
    ? trim((string) $_GET['latitude'])
    : '';

$longitudeRaw = isset($_GET['longitude'])
    ? trim((string) $_GET['longitude'])
    : '';

$zoomRaw = isset($_GET['zoom'])
    ? trim((string) $_GET['zoom'])
    : '19';

// Validate numeric input
if (
    $latitudeRaw === '' ||
    $longitudeRaw === '' ||
    !is_numeric($latitudeRaw) ||
    !is_numeric($longitudeRaw) ||
    !is_numeric($zoomRaw)
) {
    siteVisualSatelliteError(
        'HTTP/1.1 400 Bad Request',
        'Valid latitude, longitude, and zoom values are required.'
    );
}

$latitude = (float) $latitudeRaw;
$longitude = (float) $longitudeRaw;
$zoom = (int) $zoomRaw;

// Enforce geographic and map limits
if ($latitude < -90 || $latitude > 90) {
    siteVisualSatelliteError(
        'HTTP/1.1 400 Bad Request',
        'Latitude must be between -90 and 90.'
    );
}

if ($longitude < -180 || $longitude > 180) {
    siteVisualSatelliteError(
        'HTTP/1.1 400 Bad Request',
        'Longitude must be between -180 and 180.'
    );
}

if ($zoom < 0 || $zoom > 21) {
    siteVisualSatelliteError(
        'HTTP/1.1 400 Bad Request',
        'Zoom must be between 0 and 21.'
    );
}

// Resolve server-side Google API key
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

if ($googleKey === '') {
    error_log(
        '[SITE VISUAL SATELLITE] Google Maps Static API key missing.'
    );

    siteVisualSatelliteError(
        'HTTP/1.1 500 Internal Server Error',
        'Satellite-image service is not configured.'
    );
}

// Build normalized coordinate values
$latitudeParam = number_format($latitude, 7, '.', '');
$longitudeParam = number_format($longitude, 7, '.', '');
$coordinateParam = $latitudeParam . ',' . $longitudeParam;

// Build Google Static Maps request
$googleUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
    . 'center=' . rawurlencode($coordinateParam)
    . '&zoom=' . $zoom
    . '&size=600x338'
    . '&scale=2'
    . '&maptype=satellite'
    . '&markers=' . rawurlencode('color:red|' . $coordinateParam)
    . '&format=jpg'
    . '&key=' . rawurlencode($googleKey);

// Require cURL for controlled remote-image retrieval
if (!function_exists('curl_init')) {
    error_log(
        '[SITE VISUAL SATELLITE] PHP cURL extension unavailable.'
    );

    siteVisualSatelliteError(
        'HTTP/1.1 500 Internal Server Error',
        'Satellite-image service is unavailable.'
    );
}

// Request satellite image
$curl = curl_init();

curl_setopt($curl, CURLOPT_URL, $googleUrl);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($curl, CURLOPT_TIMEOUT, 20);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($curl, CURLOPT_USERAGENT, 'Skyesoft-SiteVisualOverview/1.0');

$imageData = curl_exec($curl);
$curlError = curl_error($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

curl_close($curl);

// Validate upstream response
$isImage = strpos(strtolower($contentType), 'image/') === 0;

if (
    $imageData === false ||
    $imageData === '' ||
    $httpCode !== 200 ||
    !$isImage
) {
    error_log(
        '[SITE VISUAL SATELLITE] Google image request failed. '
        . 'HTTP=' . $httpCode
        . ' Content-Type=' . $contentType
        . ' cURL=' . $curlError
    );

    siteVisualSatelliteError(
        'HTTP/1.1 502 Bad Gateway',
        'Unable to retrieve the satellite image.'
    );
}

// Stream ephemeral image response
header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($imageData));
header('Content-Disposition: inline; filename="site-visual-satellite.jpg"');

echo $imageData;
exit;

// #endregion

