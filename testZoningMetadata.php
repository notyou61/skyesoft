<?php

declare(strict_types=1);

// Correct path resolution relative to project root
require_once __DIR__ . '/api/utils/resolveZoning.php';

// Accept query params via URL or CLI defaults (Downtown Phoenix)
$jurisdiction = $_GET['jurisdiction'] ?? 'Phoenix';
$latitude     = isset($_GET['lat']) ? (float)$_GET['lat'] : 33.4484;
$longitude    = isset($_GET['lng']) ? (float)$_GET['lng'] : -112.0740;
$apn          = $_GET['apn'] ?? null;

$result = resolveZoning($jurisdiction, $latitude, $longitude, $apn);

// Format clean JSON output for both web browser and HTTP clients
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo "=== Skyesoft Zoning Resolver Test Output ===\n";
    print_r($result);
}