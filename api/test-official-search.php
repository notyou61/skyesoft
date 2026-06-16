<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$q = $_GET['q'] ?? $argv[1] ?? '225 N 1ST ST BUCKEYE AZ 85326';

$url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($q);

echo "<h2>Maricopa Assessor Official Search Test</h2>";
echo "<strong>Query:</strong> " . htmlspecialchars($q) . "<br>";
echo "<strong>URL:</strong> <a href='$url' target='_blank'>$url</a><br><br>";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'Skyesoft Parcel Resolver (Test)',
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Cache-Control: no-cache'
    ]
]);

$response   = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError  = curl_error($ch);
curl_close($ch);

echo "<strong>HTTP Status:</strong> $httpCode<br>";
echo "<strong>Curl Error:</strong> " . ($curlError ?: 'None') . "<br>";
echo "<strong>Response Length:</strong> " . strlen($response) . " bytes<br><br>";

if ($response === false) {
    echo "<strong style='color:red'>Request failed completely.</strong>";
    exit;
}

// Raw preview
echo "<h3>Raw Response Preview (first 800 chars)</h3>";
echo "<pre>" . htmlspecialchars(substr($response, 0, 800)) . "...</pre>";

// Try to decode
$data = json_decode($response, true);
$jsonError = json_last_error_msg();

echo "<h3>JSON Decode Status:</h3>";
echo "<pre>" . $jsonError . "</pre>";

if (is_array($data)) {
    echo "<h3>Top Level Keys</h3>";
    echo "<pre>";
    print_r(array_keys($data));
    echo "</pre>";

    echo "<h3>Full Decoded Data (first level only)</h3>";
    echo "<pre>";
    print_r($data);
    echo "</pre>";
} else {
    echo "<strong style='color:red'>Response is NOT valid JSON.</strong>";
}
?>