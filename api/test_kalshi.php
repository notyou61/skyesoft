<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

$apiKey   = getenv("KALSHI_API_KEY");
$keyPath  = getenv("KALSHI_PRIVATE_KEY_PATH");

if (!$apiKey) die(json_encode(["success" => false, "error" => "Missing KALSHI_API_KEY"]));
if (!$keyPath || !file_exists($keyPath)) die(json_encode(["success" => false, "error" => "Invalid key path"]));

$privateKeyContent = file_get_contents($keyPath);
$privateKey = openssl_pkey_get_private($privateKeyContent);

if (!$privateKey) {
    die(json_encode(["success" => false, "error" => "Failed to load private key. Check file format."]));
}

// Debug key info
$keyDetails = openssl_pkey_get_details($privateKey);
echo "<strong>Key Debug:</strong> Type: " . ($keyDetails['type'] ?? 'unknown') . " | Bits: " . ($keyDetails['bits'] ?? 'N/A') . "<br><hr>";

// Config
$type = $_GET['type'] ?? 'balance';
$baseUrl = 'https://api.elections.kalshi.com';   // Production

$paths = [
    'balance'   => '/trade-api/v2/portfolio/balance',
    'positions' => '/trade-api/v2/portfolio/positions',
    'orders'    => '/trade-api/v2/portfolio/orders',
    'fills'     => '/trade-api/v2/portfolio/fills',
];

$path = $paths[$type] ?? $paths['balance'];
$method = 'GET';

// Signature
$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . strtoupper($method) . $path;

echo "<strong>Signing Debug:</strong><br>";
echo "Timestamp: $timestamp<br>";
echo "Method: " . strtoupper($method) . "<br>";
echo "Path: $path<br>";
echo "Full message: $message<br><hr>";

$signature = '';
$success = openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256 | OPENSSL_PSS_PADDING);

if (!$success || empty($signature)) {
    die(json_encode(["success" => false, "error" => "openssl_sign failed"]));
}

$base64Signature = base64_encode($signature);
echo "Signature (base64): " . substr($base64Signature, 0, 50) . "...<br><hr>";

// Headers
$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",
    "KALSHI-ACCESS-SIGNATURE: $base64Signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp",
    "Content-Type: application/json",
    "User-Agent: Kalshi-PHP-Debug/1.0"
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
$err      = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');

if ($err) {
    echo json_encode(["success" => false, "error" => $err]);
    exit;
}

echo "<strong>Raw Kalshi Response:</strong><br>" . htmlspecialchars($response) . "<br><hr>";

$data = json_decode($response, true) ?? [];
echo json_encode([
    "success" => $httpCode >= 200 && $httpCode < 300,
    "type"    => $type,
    "code"    => $httpCode,
    "data"    => $data
], JSON_PRETTY_PRINT);