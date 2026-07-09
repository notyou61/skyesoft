<?php
// ======================================================================
// 🧠 Skyesoft — testBrowserlessPlatMap.php
// 🌐 Browserless Plat Map Rendering Validation Test
// 🔬 Validates:
//     • Outbound rendering requests to the production-sfo instance
//     • High-DPI browser viewports for PDF rasterization
//     • File system write permissions inside /artifacts
// ======================================================================

// =====================================================
// 📦 LOAD DEPENDENCIES & ENVIRONMENT
// =====================================================
require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/utils/envLoader.php';
}
skyesoftLoadEnv();

// =====================================================
// 🔑 GET CONFIGURATION
// =====================================================
$apiKey = getenv('BROWSERLESS_API_KEY');
if (!$apiKey) {
    die("❌ Error: BROWSERLESS_API_KEY not found in environment.\n");
}

// Target directory for the test artifact output
$artifactsDir = __DIR__ . '/../../artifacts';
$testFilename = 'TST-IMG-PAR-DEBUG-001.png';
$outputPath = $artifactsDir . '/' . $testFilename;

// Test URL using a real Maricopa County Map ID from your records
$testMapUrl = 'https://mcassessor.maricopa.gov/getmapid/825220401/'; 

// Match your verified hosting region, switching to the dedicated screenshot service
$endpoint = 'https://production-sfo.browserless.io/screenshot?token=' . urlencode($apiKey);

// =====================================================
// 📦 PREPARE BROWSERLESS SCREENSHOT PAYLOAD
// =====================================================
$payload = [
    'url' => $testMapUrl,
    'options' => [
        'type' => 'png',
        'fullPage' => false
    ],
    'gotoOptions' => [
        'waitUntil' => 'networkidle0', // Wait until the PDF viewer completely stops downloading assets
        'timeout'   => 25000
    ],
    'viewport' => [
        'width' => 1200,
        'height' => 800,
        'deviceScaleFactor' => 2 // Render at 2x resolution to ensure map lines stay razor-sharp
    ]
];

// =====================================================
// 🚀 RUN THE TEST
// =====================================================
header('Content-Type: text/plain');
echo "=====================================\n";
echo "🧠 Skyesoft Browserless Plat Map Test\n";
echo "=====================================\n\n";
echo "🌐 Targeting Map: {$testMapUrl}\n";
echo "📡 Sending to: {$endpoint}\n";
echo "⏳ Waiting for remote browser engine rendering...\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 45 // Generous timeout to allow the browser instance to process the PDF
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// =====================================================
// 📋 EVALUATE RESULTS
// =====================================================
if ($httpCode === 200 && $response && strlen($response) > 10000) {
    echo "✅ Success! Received valid binary stream from Browserless (" . round(strlen($response) / 1024, 2) . " KB).\n";
    
    // Attempt to write the file into the central artifacts path
    if (file_put_contents($outputPath, $response)) {
        echo "💾 File written successfully to disk!\n";
        echo "📍 Path: {$outputPath}\n";
        echo "📂 Filename: {$testFilename}\n\n";
        echo "Go check your file manager. If the file looks perfect, we are ready to copy this logic to production.\n";
    } else {
        echo "❌ Error: Failed to write image stream to path: {$outputPath}. Check folder write permissions.\n";
    }
} else {
    echo "⚠ Rendering Failure!\n";
    echo "📡 HTTP Status Code: {$httpCode}\n";
    if ($curlError) {
        echo "❌ cURL Error: {$curlError}\n";
    }
    echo "\n📝 Raw API Response:\n";
    echo substr($response, 0, 1000) . (strlen($response) > 1000 ? "... [truncated]" : "") . "\n";
}

echo "=====================================\n";