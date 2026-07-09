<?php
// ======================================================================
// 🧠 Skyesoft — testBrowserlessPlatMap.php
// 🌐 Browserless Plat Map Rendering Validation Test
// 🔬 Validates:
//     • Outbound stateless /screenshot requests to production-sfo instance
//     • High-DPI browser viewports for PDF rasterization
//     • Dynamic artifact directory verification and creation
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
$artifactsDir = __DIR__ . '/../artifacts';
$testFilename = 'TST-IMG-PAR-DEBUG-001.png';
$outputPath = $artifactsDir . '/' . $testFilename;

// Test URL using a real Maricopa County Map ID from your records
$testMapUrl = 'https://mcassessor.maricopa.gov/getmapid/825220401/'; 

// Corrected: Explicitly targeting the stateless screenshot endpoint
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
        'waitUntil' => 'networkidle0', // Let the native PDF canvas fully settle
        'timeout'   => 25000
    ],
    'viewport' => [
        'width' => 1200,
        'height' => 800,
        'deviceScaleFactor' => 2 // 2x resolution boost for readable tax text lines
    ]
];

// =====================================================
// 🚀 RUN THE TEST
// =====================================================
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 45 
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// =====================================================
// 📋 EVALUATE & DISPLAY RESULTS
// =====================================================

// Step 1: Check if Browserless successfully returned an image buffer stream
if ($httpCode === 200 && $response && strlen($response) > 10000) {
    
    // --- TEMPORARY VISUAL PASSTHROUGH DIAGNOSTIC ---
    // If you want to check the visual rendering directly in your browser,
    // uncomment the next three lines below:
    // header('Content-Type: image/png');
    // echo $response;
    // exit;
    // ------------------------------------------------
    
    // Ensure artifacts directory exists safely with correct permissions
    if (!is_dir($artifactsDir)) {
        mkdir($artifactsDir, 0755, true);
    }

    header('Content-Type: text/plain');
    echo "=====================================\n";
    echo "🧠 Skyesoft Browserless Plat Map Test\n";
    echo "=====================================\n\n";
    echo "✅ Success! Received valid binary stream from Browserless (" . round(strlen($response) / 1024, 2) . " KB).\n";
    
    // Attempt to commit the raw buffer stream into the central file storage layout
    if (file_put_contents($outputPath, $response)) {
        echo "💾 File written successfully to disk!\n";
        echo "📍 Path: {$outputPath}\n";
        echo "📂 Filename: {$testFilename}\n\n";
        echo "Go check your file manager. If the file looks perfect, we can confidently drop this request into production.\n";
    } else {
        echo "❌ Error: Failed to write image stream to path: {$outputPath}. Verify folder owner permissions.\n";
    }
} else {
    header('Content-Type: text/plain');
    echo "=====================================\n";
    echo "🧠 Skyesoft Browserless Plat Map Test\n";
    echo "=====================================\n\n";
    echo "⚠ Rendering Failure!\n";
    echo "📡 HTTP Status Code: {$httpCode}\n";
    if ($curlError) {
        echo "❌ cURL Error: {$curlError}\n";
    }
    echo "\n📝 Raw API Response Sample:\n";
    echo substr($response, 0, 1000) . (strlen($response) > 1000 ? "... [truncated]" : "") . "\n";
}

echo "=====================================\n";