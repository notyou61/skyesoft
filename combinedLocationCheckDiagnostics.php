<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/api/utils/envLoader.php';
skyesoftLoadEnv();

$address = '738 S Perry Ln, Tempe, AZ 85288, USA';

$googleApiKey = '';
if (function_exists('skyesoftGetEnv')) {
    $googleApiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY') ?: skyesoftGetEnv('GOOGLE_MAPS_API_KEY');
}
if (empty($googleApiKey)) {
    $googleApiKey = $_ENV['GOOGLE_MAPS_BACKEND_API_KEY']
        ?? $_ENV['GOOGLE_MAPS_API_KEY']
        ?? getenv('GOOGLE_MAPS_BACKEND_API_KEY')
        ?? getenv('GOOGLE_MAPS_API_KEY')
        ?? getenv('GOOGLE_MAPS_PLACE_ID_API_KEY')
        ?? getenv('GOOGLE_MAPS_STATIC_API_KEY')
        ?? $_SERVER['GOOGLE_MAPS_API_KEY']
        ?? '';
}

function curlGetJson(string $url): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Skyesoft Google Diagnostics Tool/3.0'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode((string)$response, true);
}

function cleanAddressForPlaces(string $rawAddress): string
{
    $clean = preg_replace('/\b(suite|ste|unit|apt|apartment|#)\s*[\w\-]+/i', '', $rawAddress);
    return trim(preg_replace('/\s+/', ' ', $clean));
}

$placeId = '';

if ($googleApiKey !== '') {
    // 1. Geocode API
    $geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'address' => $address,
        'key'     => $googleApiKey
    ]);
    $geocodeResult = curlGetJson($geocodeUrl);
    $placeId = $geocodeResult['results'][0]['place_id'] ?? '';

    // 2. Find Place API (Fallback)
    if ($placeId === '') {
        $placesQueryAddress = cleanAddressForPlaces($address);
        $findPlaceUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?' . http_build_query([
            'input'     => $placesQueryAddress,
            'inputtype' => 'textquery',
            'fields'    => 'place_id',
            'key'       => $googleApiKey
        ]);
        $findPlaceResult = curlGetJson($findPlaceUrl);
        $placeId = $findPlaceResult['candidates'][0]['place_id'] ?? '';
    }

    // 3. Text Search API (Fallback)
    if ($placeId === '') {
        $placesQueryAddress = $placesQueryAddress ?? cleanAddressForPlaces($address);
        $textSearchUrl = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
            'query' => $placesQueryAddress,
            'key'   => $googleApiKey
        ]);
        $textSearchResult = curlGetJson($textSearchUrl);
        $placeId = $textSearchResult['results'][0]['place_id'] ?? '';
    }
}

echo $placeId;