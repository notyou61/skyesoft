<?php

require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

// ─────────────────────────────────────────
// 🔐 Load credentials
// ─────────────────────────────────────────
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

// ❌ Validate env
if (!$apiKey) {
    die("❌ Missing KALSHI_API_KEY\n");
}

if (!$keyPath || !file_exists($keyPath)) {
    die("❌ Invalid key path: " . $keyPath . "\n");
}

// 🔑 Load private key
$privateKey = trim(file_get_contents($keyPath));
$key = openssl_pkey_get_private($privateKey);

if (!$key) {
    die("❌ Key failed to load\n");
}

// ─────────────────────────────────────────
// 🎯 Select endpoint dynamically
// ─────────────────────────────────────────
$type = $_GET['type'] ?? 'balance';

switch ($type) {
    case 'balance':
        $path = "/portfolio/balance";
        break;

    case 'positions':
        $path = "/portfolio/positions";
        break;

    case 'orders':
        $path = "/portfolio/orders";
        break;

    case 'fills':
        $path = "/portfolio/fills";
        break;
}

// ─────────────────────────────────────────
// ⚙️ Build request
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000); // ✅ KEEP THIS
$method    = "GET";
$body      = "";

// Exact string required
$message = $timestamp . strtoupper($method) . $path . $body;

// Sign
openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256);
$signature = base64_encode($sig);

// 🌐 Headers
$headers = [
    "KALSHI-ACCESS-KEY: $apiKey",   // ✅ FIXED
    "KALSHI-ACCESS-SIGNATURE: $signature",
    "KALSHI-ACCESS-TIMESTAMP: $timestamp",
    "Content-Type: application/json"
];

// ─────────────────────────────────────────
// 🚀 Execute request
// ─────────────────────────────────────────
$url = "https://api.elections.kalshi.com/trade-api/v2" . $path;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_DNS_CACHE_TIMEOUT => 0,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_POSTFIELDS    => ""
]);

$response = curl_exec($ch);

// 📊 Debug
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);

curl_close($ch);

// ─────────────────────────────────────────
// 📊 Output (clean JSON)
// ─────────────────────────────────────────
header('Content-Type: application/json');

if ($err) {
    echo json_encode([
        "success" => false,
        "error"   => $err,
        "code"    => $httpCode
    ]);
    exit;
}

$data = json_decode($response, true);

echo json_encode([
    "success" => true,
    "type"    => $type,
    "code"    => $httpCode,
    "data"    => $data
]);