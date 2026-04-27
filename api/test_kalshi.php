<?php

// ─────────────────────────────────────────
// 🌍 Load environment
// ─────────────────────────────────────────
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

// ─────────────────────────────────────────
// 🔐 Load credentials
// ─────────────────────────────────────────
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

// Debug (safe visibility)
echo "API KEY: " . ($apiKey ? "loaded\n" : "missing\n");
echo "KEY PATH: " . ($keyPath ?: "missing\n");

// ─────────────────────────────────────────
// ❌ Validate env
// ─────────────────────────────────────────
if (!$apiKey) {
    die("❌ Missing KALSHI_API_KEY\n");
}

if (!$keyPath || !file_exists($keyPath)) {
    die("❌ Invalid key path: " . $keyPath . "\n");
}

// ─────────────────────────────────────────
// 🔑 Load private key
// ─────────────────────────────────────────
$privateKey = file_get_contents($keyPath);
$key = openssl_pkey_get_private($privateKey);

if (!$key) {
    die("❌ Key failed to load\n");
}

// ─────────────────────────────────────────
// ⚙️ Build request
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000);
$method    = "GET";
$path      = "/trade-api/v2/markets";
$body      = "";

// IMPORTANT: exact signing format
$message = $timestamp . $method . $path . $body;

// ─────────────────────────────────────────
// ✍️ Sign request
// ─────────────────────────────────────────
openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256);
$signature = base64_encode($sig);

// ─────────────────────────────────────────
// 🌐 Prepare headers
// ─────────────────────────────────────────
$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",
    "KALSHI-ACCESS-SIGNATURE: $signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp",
    "Content-Type: application/json"
];

// ─────────────────────────────────────────
// 🚀 Execute request
// ─────────────────────────────────────────
$url = "https://api.kalshi.com" . $path;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);

// ─────────────────────────────────────────
// 📊 Handle response
// ─────────────────────────────────────────
if ($response === false) {
    echo "❌ CURL ERROR: " . curl_error($ch) . "\n";
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "HTTP CODE: $httpCode\n\n";

    echo "RESPONSE:\n";
    echo $response . "\n";
}

curl_close($ch);