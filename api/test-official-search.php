<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$q = $argv[1] ?? $_GET['q'] ?? '225 N 1ST ST BUCKEYE AZ 85326';

$url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($q);

echo "=== Maricopa Assessor Official Search Test ===\n\n";
echo "Query : " . $q . "\n";
echo "URL   : " . $url . "\n\n";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'Skyesoft Parcel Resolver (Test)',
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

echo "HTTP Status : " . $httpCode . "\n";
echo "Curl Error  : " . ($curlError ?: 'None') . "\n";
echo "Response Length : " . strlen($response) . " bytes\n\n";

if ($response === false) {
    echo "Request completely failed.\n";
    exit;
}

// Show first part of raw response
echo "=== Raw Response Preview (first 1500 chars) ===\n";
echo substr(htmlspecialchars($response), 0, 1500) . "...\n\n";

$data = json_decode($response, true);

echo "=== JSON Decode Error ===\n";
echo json_last_error_msg() . "\n\n";

if (is_array($data)) {
    echo "=== Top Level Keys ===\n";
    print_r(array_keys($data));

    echo "\n=== Full Decoded Structure (first level) ===\n";
    print_r($data);
} else {
    echo "Response is NOT valid JSON.\n";
}