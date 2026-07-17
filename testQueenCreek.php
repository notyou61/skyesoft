<?php
declare(strict_types=1);

// Enable error reporting for testing
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/api/utils/resolveZoning.php';

// Queen Creek test coordinates from zoning.json
$latitude = 33.248197;
$longitude = -111.614224;
$jurisdiction = 'Queen Creek';

$result = resolveZoning($jurisdiction, $latitude, $longitude);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);