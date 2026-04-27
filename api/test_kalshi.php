<?php

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

// 🔐 Load credentials
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

echo "API KEY: " . ($apiKey ? "loaded\n" : "missing\n");
echo "KEY PATH: " . ($keyPath ?: "missing\n");

// ❌ Validate env
if (!$apiKey) {
    die("❌ Missing KALSHI_API_KEY\n");
}

if (!$keyPath || !file_exists($keyPath)) {
    die("❌ Invalid key path: " . $keyPath . "\n");
}

// 🔑 Load private key
$privateKey = file_get_contents($keyPath);
$key = openssl_pkey_get_private($privateKey);

if (!$key) {
    die("❌ Key failed to load\n");
}

// ⚙️ Build request
$timestamp = (string) round(microtime(true) * 1000);
$method    = "GET";
$path      = "/trade-api/v2/markets";
$body      = "";

$message = $timestamp . $method . $path . $body;

// ✍️ Sign request
openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256);
$signature = base64_encode($sig);

// 🌐 Headers
$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",
    "KALSHI-ACCESS-SIGNATURE: $signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp",
    "Content-Type: application/json"
];

// 🚀 Execute request
$url = "https://api.elections.kalshi.com" . $path;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 10,

    // 🔥 FIXES FOR YOUR ERROR:
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Force IPv4
    CURLOPT_DNS_CACHE_TIMEOUT => 0,         // Avoid stale DNS
]);

$response = curl_exec($ch);

// 📊 Debug info
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);

curl_close($ch);

// Output
echo "HTTP CODE: $httpCode\n";

if ($err) {
    echo "❌ CURL ERROR: $err\n";
} else {
    echo "\nRESPONSE:\n$response\n";
}