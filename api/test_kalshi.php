<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

echo "PHP Version running: " . phpversion() . "<br><hr>";

// Rest of your script (credentials, signing, etc.)
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

if (!$apiKey) die(json_encode(["success" => false, "error" => "Missing KALSHI_API_KEY"]));
if (!$keyPath || !file_exists($keyPath)) die(json_encode(["success" => false, "error" => "Invalid key path"]));

$privateKey = openssl_pkey_get_private(file_get_contents($keyPath));
if (!$privateKey) die(json_encode(["success" => false, "error" => "Failed to load private key"]));

// Config + Signing (same as before)
$type = $_GET['type'] ?? 'balance';
$baseUrl = 'https://api.elections.kalshi.com';

$paths = ['balance' => '/trade-api/v2/portfolio/balance', /* ... other paths */];
$path   = $paths[$type] ?? $paths['balance'];
$method = 'GET';

$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . strtoupper($method) . $path;

$signature = '';
if (defined('OPENSSL_PSS_PADDING')) {
    $algo = OPENSSL_ALGO_SHA256 | OPENSSL_PSS_PADDING;
    echo "✅ Using PSS Padding<br>";
} else {
    $algo = OPENSSL_ALGO_SHA256;
    echo "⚠️ Using fallback (may not work)<br>";
}

openssl_sign($message, $signature, $privateKey, $algo);
$base64Signature = base64_encode($signature);

// Headers + cURL (same as your previous working version)
$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",
    "KALSHI-ACCESS-SIGNATURE: $base64Signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp",
    "Content-Type: application/json",
    "Accept: application/json",
    "User-Agent: Kalshi-PHP/1.0"
];

$url = $baseUrl . $path;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CUSTOMREQUEST  => $method,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');

if ($err) {
    echo json_encode(["success" => false, "error" => $err]);
    exit;
}

$data = json_decode($response, true) ?? [];
echo json_encode([
    "success" => $httpCode >= 200 && $httpCode < 300,
    "type"    => $type,
    "code"    => $httpCode,
    "data"    => $data
], JSON_PRETTY_PRINT);