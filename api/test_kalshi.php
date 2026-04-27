<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

echo "PHP Version: " . phpversion() . "<br><hr>";

// ─────────────────────────────────────────
// Credentials
// ─────────────────────────────────────────
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

if (!$apiKey) die(json_encode(["success" => false, "error" => "Missing KALSHI_API_KEY"]));
if (!$keyPath || !file_exists($keyPath)) die(json_encode(["success" => false, "error" => "Invalid key path"]));

$privateKey = openssl_pkey_get_private(file_get_contents($keyPath));
if (!$privateKey) die(json_encode(["success" => false, "error" => "Failed to load private key"]));

// ─────────────────────────────────────────
// Config
// ─────────────────────────────────────────
$type = $_GET['type'] ?? 'balance';
$baseUrl = 'https://api.elections.kalshi.com';

$path   = '/trade-api/v2/portfolio/balance';
$method = 'GET';

// ─────────────────────────────────────────
// Signature - Manual PSS setup (works on restricted PHP builds)
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . strtoupper($method) . $path;

$signature = '';
$success = openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

if (!$success || empty($signature)) {
    die(json_encode(["success" => false, "error" => "Basic signing failed"]));
}

$base64Signature = base64_encode($signature);

echo "Debug: Using basic SHA256 signature (PSS not available)<br>";
echo "Timestamp: $timestamp<br>";
echo "Message: $message<br><hr>";

// ─────────────────────────────────────────
// Request
// ─────────────────────────────────────────
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
    CURLOPT_TIMEOUT        => 15,
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