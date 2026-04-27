<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

// ─────────────────────────────────────────
// 🔐 Load credentials
// ─────────────────────────────────────────
$apiKey   = getenv("KALSHI_API_KEY");
$keyPath  = getenv("KALSHI_PRIVATE_KEY_PATH");

if (!$apiKey) {
    die(json_encode(["success" => false, "error" => "Missing KALSHI_API_KEY"]));
}
if (!$keyPath || !file_exists($keyPath)) {
    die(json_encode(["success" => false, "error" => "Invalid key path: " . $keyPath]));
}

// Load private key
$privateKeyContent = file_get_contents($keyPath);
$privateKey = openssl_pkey_get_private($privateKeyContent);
if (!$privateKey) {
    die(json_encode(["success" => false, "error" => "Failed to load private key"]));
}

// ─────────────────────────────────────────
// 🎯 Config
// ─────────────────────────────────────────
$type = $_GET['type'] ?? 'balance';

$baseUrl = 'https://demo-api.kalshi.co';   // ← DEMO first!

$paths = [
    'balance'   => '/trade-api/v2/portfolio/balance',
    'positions' => '/trade-api/v2/portfolio/positions',
    'orders'    => '/trade-api/v2/portfolio/orders',
    'fills'     => '/trade-api/v2/portfolio/fills',
];

$path = $paths[$type] ?? $paths['balance'];
$method = 'GET';

// ─────────────────────────────────────────
// ⚙️ Signature with PSS (compatible locally + server)
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . strtoupper($method) . $path;

$signature = '';

// Use PSS padding if available (PHP 8.1+), else fallback
if (defined('OPENSSL_PSS_PADDING')) {
    $algo = OPENSSL_ALGO_SHA256 | OPENSSL_PSS_PADDING;
} else {
    $algo = OPENSSL_ALGO_SHA256;   // local fallback
}

$success = openssl_sign($message, $signature, $privateKey, $algo);

if (!$success || empty($signature)) {
    die(json_encode(["success" => false, "error" => "Signing failed. Check private key and PHP version on server."]));
}

$base64Signature = base64_encode($signature);

// Add this right before the cURL request for debugging
echo "Debug Info:<br>";
echo "Base URL: " . $baseUrl . "<br>";
echo "Path: " . $path . "<br>";
echo "Timestamp: " . $timestamp . "<br>";
echo "Key ID length: " . strlen($apiKey) . "<br>";
echo "Key starts with: " . substr($apiKey, 0, 8) . "..." . "<br>";
// Then continue with the request

// ─────────────────────────────────────────
// 🚀 cURL Request
// ─────────────────────────────────────────
$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",
    "KALSHI-ACCESS-SIGNATURE: $base64Signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp",
    "Content-Type: application/json",
    "User-Agent: Kalshi-PHP/1.0"
];

$url = $baseUrl . $path;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);

// curl_close is safe on PHP 8.4, but we can suppress the IDE warning
if (function_exists('curl_close')) {
    curl_close($ch);
}

header('Content-Type: application/json');

if ($err) {
    echo json_encode(["success" => false, "error" => $err, "code" => $httpCode]);
    exit;
}

$data = json_decode($response, true) ?? [];

echo json_encode([
    "success" => $httpCode >= 200 && $httpCode < 300,
    "type"    => $type,
    "code"    => $httpCode,
    "data"    => $data
]);