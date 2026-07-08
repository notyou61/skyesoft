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

$headers = [];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    
    // Present a legitimate browser context to pass Cloudflare's edge checks
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json, text/plain, */*',
        'Authorization: ' . trim($token),
        'Cache-Control: no-cache',
        'Connection: keep-alive'
    ],
    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    }
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<h2>Maricopa MapID cURL Test</h2>";
echo "<strong>Token Present:</strong> " . ($token ? 'Yes' : 'No') . " (Length: " . strlen($token) . ")<br>";
echo "<strong>HTTP Status Code:</strong> $httpCode<br>";
echo "<strong>Curl Error:</strong> " . ($curlError ?: 'None') . "<br><br>";

echo "<h2>HTTP Response Headers</h2><pre>";
print_r($headers);
echo "</pre>";

echo "<h2>Raw Response</h2><pre>";
if ($response === false) {
    echo "Request failed completely. Curl Error: " . htmlspecialchars($curlError);
} else {
    echo htmlspecialchars($response);
}
echo "</pre>";