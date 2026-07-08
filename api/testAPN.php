<?php
declare(strict_types=1);

$apn = '17342369A';

$url = 'https://mcassessor.maricopa.gov/mapid/parcel/' . urlencode($apn);

$response = @file_get_contents($url);

echo "<h2>HTTP Headers</h2><pre>";
print_r($http_response_header ?? []);
echo "</pre>";

echo "<h2>Raw Response</h2><pre>";

if ($response === false) {
    echo "Request failed.";
} else {
    echo htmlspecialchars($response);
}

echo "</pre>";