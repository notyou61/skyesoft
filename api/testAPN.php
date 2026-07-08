<?php
declare(strict_types=1);

$apn = '17342369A';
$url = 'https://mcassessor.maricopa.gov/mapid/parcel/' . urlencode($apn);

// Set up context options to mimic a standard browser request
$options = [
    'http' => [
        'method' => "GET",
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                    "Accept: application/json, text/plain, */*\r\n"
    ]
];

$context = stream_context_create($options);

// Execute the request using the context
$response = @file_get_contents($url, false, $context);

echo "<h2>HTTP Headers</h2><pre>";
print_r($http_response_header ?? []);
echo "</pre>";

echo "<h2>Raw Response</h2><pre>";

// Safe type checking before passing to htmlspecialchars
if ($response === false) {
    echo "Request failed. The remote server rejected the request or is down.";
} else {
    echo htmlspecialchars($response);
}

echo "</pre>";