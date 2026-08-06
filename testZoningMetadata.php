<?php

declare(strict_types=1);

// Correct path resolution relative to project root
require_once __DIR__ . '/api/utils/resolveZoning.php';

// Support both URL query params and CLI flags (--lat=... --lng=... --apn=... --jurisdiction=...)
$options = [];
if (php_sapi_name() === 'cli') {
    $options = getopt('', ['jurisdiction::', 'lat::', 'lng::', 'apn::']);
}

$jurisdiction = $options['jurisdiction'] ?? $_GET['jurisdiction'] ?? 'Phoenix';
$latitude     = isset($options['lat']) ? (float)$options['lat'] : (isset($_GET['lat']) ? (float)$_GET['lat'] : 33.4831);
$longitude    = isset($options['lng']) ? (float)$options['lng'] : (isset($_GET['lng']) ? (float)$_GET['lng'] : -112.1302);
$apn          = $options['apn'] ?? $_GET['apn'] ?? '108-03-009E';

$result = resolveZoning($jurisdiction, $latitude, $longitude, $apn);

// Format clean JSON output for both web browser and HTTP clients
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo "=== Skyesoft Zoning Resolver Test Output ===\n";
    echo "Target Jurisdiction : {$jurisdiction}\n";
    echo "Target Coordinates  : {$latitude}, {$longitude}\n";
    echo "Target APN          : " . ($apn ?? 'N/A') . "\n";
    echo "--------------------------------------------\n";
    print_r($result);
}