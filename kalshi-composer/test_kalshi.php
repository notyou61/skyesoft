<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../api/utils/envLoader.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;


// ─────────────────────────────────────────
// Environment
// ─────────────────────────────────────────
skyesoftLoadEnv();


// ─────────────────────────────────────────
// Kalshi Config
// ─────────────────────────────────────────
$apiKey  = skyesoftGetEnv('KALSHI_API_KEY');
$keyPath = skyesoftGetEnv('KALSHI_PRIVATE_KEY_PATH');
$baseUrl = skyesoftGetEnv('KALSHI_BASE_URL');

$path   = '/trade-api/v2/portfolio/balance';
$method = 'GET';


// ─────────────────────────────────────────
// Validate Config
// ─────────────────────────────────────────
if (!$apiKey) {
    die("ERROR: KALSHI_API_KEY not configured.\n");
}

if (!$keyPath) {
    die("ERROR: KALSHI_PRIVATE_KEY_PATH not configured.\n");
}

if (!$baseUrl) {
    die("ERROR: KALSHI_BASE_URL not configured.\n");
}

if (!file_exists($keyPath)) {
    die("ERROR: Private key not found.\n");
}


// ─────────────────────────────────────────
// Load Private Key
// ─────────────────────────────────────────
$privateKeyContent = file_get_contents($keyPath);

$privateKey = PublicKeyLoader::loadPrivateKey($privateKeyContent);

if (!$privateKey instanceof \phpseclib3\Crypt\RSA\PrivateKey) {
    die("ERROR: Private key is not RSA.\n");
}

$privateKey = $privateKey
    ->withPadding(RSA::SIGNATURE_PSS)
    ->withHash('sha256')
    ->withMGFHash('sha256')
    ->withSaltLength(32);

// ─────────────────────────────────────────
// Create Kalshi Signature
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . $method . $path;

$signature       = $privateKey->sign($message);
$signatureBase64 = base64_encode($signature);

// ─────────────────────────────────────────
// Request
// ─────────────────────────────────────────
$url = $baseUrl . $path;

$headers = [
    'KALSHI-ACCESS-KEY: ' . $apiKey,
    'KALSHI-ACCESS-SIGNATURE: ' . $signatureBase64,
    'KALSHI-ACCESS-TIMESTAMP: ' . $timestamp,
    'Accept: application/json'
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

// ─────────────────────────────────────────
// Output
// ─────────────────────────────────────────
echo "Kalshi Authentication Test\n";
echo "--------------------------\n";
echo "HTTP Status: {$httpCode}\n";

if ($curlError) {
    echo "cURL Error: {$curlError}\n";
}

echo "Response:\n";
echo $response . "\n";