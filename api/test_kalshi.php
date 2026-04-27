<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

// ─────────────────────────────────────────
// 🔐 Load credentials
// ─────────────────────────────────────────
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

if (!$apiKey) die(json_encode(["success" => false, "error" => "Missing KALSHI_API_KEY"]));
if (!$keyPath || !file_exists($keyPath)) die(json_encode(["success" => false, "error" => "Invalid key path: " . $keyPath]));

// ─────────────────────────────────────────
// 📦 Load phpseclib (EXACT PATH)
// ─────────────────────────────────────────
require_once __DIR__ . '/phpseclib/phpseclib/bootstrap.php';

// FORCE load core classes (GoDaddy fix)
require_once __DIR__ . '/phpseclib/phpseclib/Crypt/PublicKeyLoader.php';
require_once __DIR__ . '/phpseclib/phpseclib/Crypt/RSA.php';
require_once __DIR__ . '/phpseclib/phpseclib/Crypt/Common/AsymmetricKey.php';

// ─────────────────────────────────────────
// 🔑 Load private key
// ─────────────────────────────────────────
$privateKeyContent = file_get_contents($keyPath);

$privateKey = PublicKeyLoader::load($privateKeyContent)
    ->withPadding(RSA::SIGNATURE_PSS)
    ->withHash('sha256')
    ->withMGFHash('sha256')
    ->withSaltLength(32);

// ─────────────────────────────────────────
// ⚙️ Config
// ─────────────────────────────────────────
$type = $_GET['type'] ?? 'balance';
$baseUrl = 'https://api.elections.kalshi.com';

$paths = [
    'balance'   => '/trade-api/v2/portfolio/balance',
    'positions' => '/trade-api/v2/portfolio/positions',
    'orders'    => '/trade-api/v2/portfolio/orders',
    'fills'     => '/trade-api/v2/portfolio/fills',
];

$path   = $paths[$type] ?? $paths['balance'];
$method = 'GET';

// ─────────────────────────────────────────
// ✍️ Signature
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . strtoupper($method) . $path;

$signature = $privateKey->sign($message);
$base64Signature = base64_encode($signature);

// ─────────────────────────────────────────
// 🌐 Request
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
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CUSTOMREQUEST  => $method,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

// Output
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