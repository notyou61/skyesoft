<?php

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

// #region 📍 Test Address
$rawAddress = '7401 E CAMELBACK RD SCOTTSDALE AZ 85251';
// $rawAddress = '3145 N 33rd Ave Phoenix AZ 85017';
// #endregion

echo "Testing address: " . $rawAddress . "\n\n";

// Try search endpoint first
$searchQuery = urlencode($rawAddress);

$url = 'https://mcassessor.maricopa.gov/search/property/?q=' . $searchQuery;

echo "Search URL: " . $url . "\n";

$response = @file_get_contents($url);

if ($response === false) {
    echo "Request failed.\n";
    exit;
}

$data = json_decode($response, true);

$candidateCount = isset($data['results']) ? count($data['results']) : 0;

echo "Results found: " . $candidateCount . "\n\n";

if ($candidateCount > 0) {
    echo json_encode($data['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    echo "No results from /search/property\n";
    echo "Raw response preview: " . substr($response, 0, 800) . "...\n";
}

// Optional: Try direct parcel lookup if we have an APN
if (isset($data['results'][0]['apn'])) {
    $apn = $data['results'][0]['apn'];
    $detailUrl = 'https://mcassessor.maricopa.gov/parcel/' . urlencode($apn);
    echo "\nTrying direct parcel details: " . $detailUrl . "\n";
    
    $detailResponse = @file_get_contents($detailUrl);
    if ($detailResponse !== false) {
        $detail = json_decode($detailResponse, true);
        echo "Parcel Details:\n";
        echo json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}