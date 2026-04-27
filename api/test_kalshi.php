<?php
require_once __DIR__ . '/utils/envLoader.php';
skyesoftLoadEnv();

echo "PHP Version: " . phpversion() . "<br><hr>";
echo "Current directory: " . __DIR__ . "<br><hr>";

// List files to help debug path
echo "<strong>Files in api folder:</strong><br>";
$files = scandir(__DIR__);
foreach ($files as $f) {
    if ($f != '.' && $f != '..') echo $f . "<br>";
}

echo "<hr>";

// Try to find autoload or bootstrap
$possible_paths = [
    __DIR__ . '/phpseclib/autoload.php',
    __DIR__ . '/phpseclib/bootstrap.php',
    __DIR__ . '/phpseclib/phpseclib/autoload.php',
    __DIR__ . '/phpseclib-3.0.52/autoload.php'
];

foreach ($possible_paths as $p) {
    if (file_exists($p)) {
        echo "✅ Found: " . basename($p) . " at " . $p . "<br>";
        require_once $p;
        break;
    }
}

if (!class_exists('phpseclib3\Crypt\PublicKeyLoader')) {
    die("❌ phpseclib not loaded correctly. Check folder structure.");
}

// Rest of the code (if loaded)
$apiKey  = getenv("KALSHI_API_KEY");
$keyPath = getenv("KALSHI_PRIVATE_KEY_PATH");

if (!$apiKey || !$keyPath || !file_exists($keyPath)) {
    die("Missing credentials or key file");
}

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

$privateKey = PublicKeyLoader::load(file_get_contents($keyPath))
    ->withPadding(RSA::SIGNATURE_PSS)
    ->withHash('sha256')
    ->withMGFHash('sha256')
    ->withSaltLength(32);

$timestamp = (string) round(microtime(true) * 1000);
$message   = $timestamp . 'GET/trade-api/v2/portfolio/balance';

$signature = $privateKey->sign($message);
$base64Signature = base64_encode($signature);

echo "✅ Signature created with phpseclib!<br>";

// (cURL request code omitted for brevity - add if needed)

echo "If you see this, we're ready to go!";