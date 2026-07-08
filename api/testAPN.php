<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load Skyesoft environment variables
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$apn = '17342369A';
$token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';

$url = 'https://mcassessor.maricopa.gov/mapid/parcel/' . urlencode($apn);

// Build context headers matching Maricopa's security specs
$options = [
    'http' => [
        'method' => "GET",
        'header' => "Authorization: " . trim($token) . "\r\n" .
                    "User-Agent: \r\n" . // Leave empty to comply with API edge firewall rules
                    "Accept: application/json, text/plain, */*\r\n" .
                    "Cache-Control: no-cache\r\n",
        'timeout' => 20
    ]
];

$context = stream_context_create($options);

// Execute the request
$response = @file_get_contents($url, false, $context);

echo "<h2>Maricopa MapID Token Test</h2>";
echo "<strong>Token Present:</strong> " . ($token ? 'Yes' : 'No') . "<br>";
if ($token) {
    echo "<strong>Token Length:</strong> " . strlen($token) . "<br>";
}
echo "<br>";

echo "<h2>HTTP Response Headers</h2><pre>";
print_r($http_response_header ?? []);
echo "</pre>";

echo "<h2>Raw Response</h2><pre>";

if ($response === false) {
    echo "Request failed. Check if the token is valid or if the endpoint structure has changed.";
} else {
    echo htmlspecialchars($response);
}

echo "</pre>";