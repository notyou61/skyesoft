<?php
// =====================================================
// resolveParcelReview.php
// Reusable Parcel Review Resolver for Skyesoft Command Interface
// =====================================================

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 0); // Production: off

$rawAddress = trim($_POST['address'] ?? $_GET['address'] ?? '');

if (empty($rawAddress)) {
    echo json_encode([
        'success' => false,
        'error' => 'Address is required'
    ]);
    exit;
}

// Load dependencies
$utilsDir = __DIR__ . '/utils';

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

require_once $utilsDir . '/validateAddressCensus.php';
require_once $utilsDir . '/resolveParcel.php';
require_once $utilsDir . '/resolveJurisdiction.php';

// Google Geocode Helper
function getGooglePlaceData(string $searchAddress) {
    $googleApiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY') 
        ?: getenv('GOOGLE_MAPS_BACKEND_API_KEY');

    if (empty($searchAddress) || empty($googleApiKey)) {
        return ['placeId' => null, 'latitude' => null, 'longitude' => null, 'validated' => false];
    }

    $geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . 
        http_build_query([
            'address' => $searchAddress,
            'key'     => $googleApiKey
        ]);

    $geocodeResponse = @file_get_contents($geocodeUrl);
    $geocodeData = json_decode($geocodeResponse, true);

    if (isset($geocodeData['results'][0])) {
        $result = $geocodeData['results'][0];
        return [
            'placeId'   => $result['place_id'] ?? null,
            'latitude'  => $result['geometry']['location']['lat'] ?? null,
            'longitude' => $result['geometry']['location']['lng'] ?? null,
            'validated' => true
        ];
    }

    return ['placeId' => null, 'latitude' => null, 'longitude' => null, 'validated' => false];
}

// Process Resolution
$censusResult = [];
$googleResult = [];
$parcelResult = [];

$placeId = $latitude = $longitude = '';
$jurisdictionName = $jurisdictionType = $postalCity = '';
$rsCode = 'RS-UNKNOWN';
$parcelStatus = 'Unknown';
$aiSummary = '';

// 1. Census
if (function_exists('validateAddressCensus')) {
    $censusResult = validateAddressCensus($rawAddress);
}

// 2. Google
$googleResult = getGooglePlaceData($rawAddress);
$placeId   = $googleResult['placeId'] ?? '';
$latitude  = $googleResult['latitude'] ?? '';
$longitude = $googleResult['longitude'] ?? '';

// 3. Parcel
if (function_exists('resolveParcel')) {
    $parcelResult = resolveParcel(
        $latitude ?: null,
        $longitude ?: null,
        $censusResult['county'] ?? null,
        $censusResult['countyFips'] ?? null,
        $rawAddress
    );

    // Governance Classification (same as test file)
    $parcelCount = $parcelResult['parcelCount'] ?? 0;
    $searchTier  = $parcelResult['searchTier'] ?? null;

    if ($searchTier === 'county_bypass') {
        $rsCode = 'RS-0';
        $parcelStatus = 'Parcel Resolution Not Available';
    } elseif ($parcelCount === 0) {
        $rsCode = 'RS-7';
        $parcelStatus = 'Unresolved Parcel';
    } elseif ($parcelCount === 1) {
        $rsCode = 'RS-0';
        $parcelStatus = 'Single Parcel Found';
    } else {
        $rsCode = 'RS-6';
        $parcelStatus = 'Multiple Parcels Found';
    }

    // Jurisdiction
    if (!empty($parcelResult['jurisdictionName'])) {
        $jurisdictionName = $parcelResult['jurisdictionName'];
        $jurisdictionType = $parcelResult['jurisdictionType'] ?? 'Unknown';
    } elseif (!empty($parcelResult['parcelDetails'][0]['jurisdiction'])) {
        $jurisdictionName = $parcelResult['parcelDetails'][0]['jurisdiction'];
        $jurisdictionType = 'City';
    }
}

// Fallback Jurisdiction
if (empty($jurisdictionName) && function_exists('resolveJurisdiction')) {
    $jurRaw = $censusResult['county'] ?? $rawAddress;
    $jurisdictionResult = resolveJurisdiction($jurRaw);
    $jurisdictionName = $jurisdictionResult['label'] ?? $jurRaw;
    $jurisdictionType = $jurisdictionResult['jurisdictionType'] ?? 'County';
}

// AI-style Summary
$summaryLines = [];
$summaryLines[] = "Skyesoft resolved the details for " . htmlspecialchars($rawAddress) . ".";
if (($censusResult['valid'] ?? false)) {
    $summaryLines[] = "Census confirmed " . ucwords(strtolower($censusResult['county'] ?? 'Maricopa')) . " County.";
}
if (!empty($placeId)) {
    $summaryLines[] = "Google validated the address.";
}
$parcelCount = $parcelResult['parcelCount'] ?? 0;
$summaryLines[] = "Parcel lookup found {$parcelCount} parcel(s).";
if (!empty($jurisdictionName)) {
    $summaryLines[] = "Governing jurisdiction: " . ucwords(strtolower($jurisdictionName)) . ".";
}
if ($rsCode !== 'RS-UNKNOWN') {
    $summaryLines[] = "Governance: {$rsCode} ({$parcelStatus}).";
}

$aiSummary = implode("<br>", array_filter($summaryLines, fn($line) => trim($line) !== ''));

// Final JSON Output
$jsonOutput = [
    'success' => true,
    'inputAddress' => $rawAddress,
    'summary' => $aiSummary,
    'google' => $googleResult,
    'census' => $censusResult,
    'parcel' => $parcelResult,
    'jurisdiction' => [
        'governingJurisdiction' => $jurisdictionName ?: null,
        'jurisdictionType' => $jurisdictionType ?: null,
        'postalCity' => $postalCity ?: null,
    ],
    'governance' => [
        'rsCode' => $rsCode,
        'parcelStatus' => $parcelStatus
    ]
];

header('Content-Type: application/json');
echo json_encode($jsonOutput, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;