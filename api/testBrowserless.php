<?php
// ======================================================================
// 🧠 Skyesoft — testBrowserless.php
// 🌐 Browserless Connectivity Test
// 🔬 Validates:
//     • .env loading
//     • API key retrieval
//     • Outbound HTTPS requests
//     • Browserless authentication
// ======================================================================

// =====================================================
// 📦 LOAD ENVIRONMENT
// =====================================================

require_once __DIR__ . '/../vendor/autoload.php';

// ─────────────────────────────────────────
// 🌍 Load environment
// ─────────────────────────────────────────

if (!function_exists('skyesoftLoadEnv')) {

    require_once __DIR__ . '/utils/envLoader.php';
}

skyesoftLoadEnv();

// -----------------------------------------------------
// Parse .env manually (GoDaddy-safe)
// -----------------------------------------------------

$envLines =
    file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($envLines as $line) {

    if (
        strpos(trim($line), '#') === 0 ||
        strpos($line, '=') === false
    ) {
        continue;
    }

    list($key, $value) =
        explode('=', $line, 2);

    putenv(trim($key) . '=' . trim($value));
}

// =====================================================
// 🔑 GET API KEY
// =====================================================

$apiKey =
    getenv('BROWSERLESS_API_KEY');

if (!$apiKey) {

    die(
        '❌ Browserless API key not found'
    );
}

// =====================================================
// 🌐 TEST ENDPOINT
// =====================================================

$endpoint =
    'https://production-sfo.browserless.io/chromium'
    . '?token='
    . urlencode($apiKey);

// =====================================================
// 🚀 TEST REQUEST
// =====================================================

$ch = curl_init();

curl_setopt_array($ch, [

    CURLOPT_URL            => $endpoint,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json'
    ],

    // -------------------------------------------------
    // Minimal Browserless payload
    // -------------------------------------------------

    CURLOPT_POSTFIELDS     => json_encode([

        'url' => 'https://example.com'

    ]),

    CURLOPT_TIMEOUT        => 30
]);

$response =
    curl_exec($ch);

$httpCode =
    curl_getinfo($ch, CURLINFO_HTTP_CODE);

$error =
    curl_error($ch);

curl_close($ch);

// =====================================================
// 📋 OUTPUT RESULTS
// =====================================================

header('Content-Type: text/plain');

echo "=====================================\n";
echo "🧠 Skyesoft Browserless Test\n";
echo "=====================================\n\n";

echo "✅ .env Loaded\n";
echo "✅ API Key Retrieved\n\n";

echo "🌐 Endpoint:\n";
echo $endpoint . "\n\n";

echo "📡 HTTP Status:\n";
echo $httpCode . "\n\n";

// -----------------------------------------------------
// Success
// -----------------------------------------------------

if ($httpCode >= 200 && $httpCode < 300) {

    echo "✅ Browserless Connection Successful\n\n";

} else {

    echo "⚠ Browserless Response:\n\n";
    echo $response . "\n\n";
}

// -----------------------------------------------------
// cURL Errors
// -----------------------------------------------------

if ($error) {

    echo "❌ cURL Error:\n";
    echo $error . "\n";
}

echo "=====================================\n";