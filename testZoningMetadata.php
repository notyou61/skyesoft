<?php

require_once __DIR__ . '/api/utils/resolveZoning.php';

// Test Phoenix coordinate (Downtown Phoenix)
$jurisdiction = 'Phoenix';
$latitude     = 33.4484;
$longitude    = -112.0740;
$apn          = '111-22-333';

echo "Running resolveZoning() for {$jurisdiction}...\n";

$result = resolveZoning($jurisdiction, $latitude, $longitude, $apn);

print_r($result);