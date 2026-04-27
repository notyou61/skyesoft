<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

echo "PHP Version: " . phpversion() . "<br><hr>";

// ─────────────────────────────────────────
// Load credentials
// ─────────────────────────────────────────
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

if (!$apiKey) die(json_encode(["success" => false, "error" => "Missing KALSHI_API_KEY"]));
if (!$keyPath || !file_exists($keyPath)) die(json_encode(["success" => false, "error" => "Invalid key path"]));

// ─────────────────────────────────────────
// Load phpseclib
// ─────────────────────────────────────────
require_once __DIR__ . '/phpseclib/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

// Load private key with Kalshi PSS settings
$privateKeyContent = file_get_contents($keyPath);
$privateKey = PublicKeyLoader::load($privateKeyContent)
    ->withPadding(RSA::SIGNATURE_PSS)
    ->withHash('sha256')
    ->withMGFHash('sha256')
    ->withSaltLength(32);

// ─────────────────────────────────────────
// Sign the request
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . 'GET/trade-api/v2/portfolio/balance';

$signature = $privateKey->sign($message);
$base64Signature = base64_encode($signature);

echo "✅ phpseclib Signature created successfully<br>";

// ─────────────────────────────────────────
// Make the API call
// ─────────────────────────────────────────
$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",
    "KALSHI-ACCESS-SIGNATURE: $base64Signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp",
    "Content-Type: application/json",
    "Accept: application/json",
    "User-Agent: Kalshi-PHP/1.0"
];

$ch = curl_init("https://api.elections.kalshi.com/trade-api/v2/portfolio/balance");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
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
    "code"    => $httpCode,
    "data"    => $data
], JSON_PRETTY_PRINT);