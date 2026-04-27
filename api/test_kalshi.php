<?php

$apiKey = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

$privateKey = file_get_contents($keyPath);
$key = openssl_pkey_get_private($privateKey);

if (!$key) {
    die("❌ Key failed to load\n");
}

$timestamp = (string) round(microtime(true) * 1000);
$method = "GET";
$path = "/trade-api/v2/markets";
$body = "";

$message = $timestamp . $method . $path . $body;

openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256);
$signature = base64_encode($sig);

$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",
    "KALSHI-ACCESS-SIGNATURE: $signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp"
];

$ch = curl_init("https://api.kalshi.com" . $path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);

if ($response === false) {
    echo "❌ CURL ERROR: " . curl_error($ch) . "\n";
} else {
    echo "✅ RESPONSE:\n";
    echo $response . "\n";
}

curl_close($ch);