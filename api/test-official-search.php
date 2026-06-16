<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$q = $_GET['q'] ?? $argv[1] ?? '225 N 1ST ST BUCKEYE AZ 85326';
$token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';

echo "<h2>Maricopa Assessor Official Search Test</h2>";
echo "<strong>Query:</strong> " . htmlspecialchars($q) . "<br>";
echo "<strong>Token Present:</strong> " . ($token ? 'Yes' : 'No') . "<br><br>";

$url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($q);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'Skyesoft Parcel Resolver',
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Authorization: ' . $token,
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
    echo "<strong style='color:red'>Request failed.</strong>";
    exit;
}

echo "<h3>Raw Response Preview</h3>";
echo "<pre>" . htmlspecialchars(substr($response, 0, 1200)) . "...</pre>";

$data = json_decode($response, true);
echo "<h3>JSON Decode:</h3> " . json_last_error_msg() . "<br><br>";

if (is_array($data)) {
    echo "<h3>Top Level Keys</h3><pre>";
    print_r(array_keys($data));
    echo "</pre>";

    echo "<h3>Full Decoded Data</h3><pre>";
    print_r($data);
    echo "</pre>";
}
?>