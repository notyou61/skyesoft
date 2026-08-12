<?php

require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

// ─────────────────────────────────────────
// Private Key
// ─────────────────────────────────────────
$keyPath = 'C:/Users/Steve Skye/Documents/kalshi-worker/keys/kalshi_private.pem';

if (!file_exists($keyPath)) {
    die("ERROR: Private key not found.\n");
}

$privateKeyContent = file_get_contents($keyPath);

// ─────────────────────────────────────────
// Load RSA Private Key
// ─────────────────────────────────────────
$privateKey = PublicKeyLoader::loadPrivateKey($privateKeyContent);

if (!$privateKey instanceof \phpseclib3\Crypt\RSA\PrivateKey) {
    die("ERROR: Loaded key is not an RSA private key.\n");
}

// ─────────────────────────────────────────
// Configure Kalshi RSA-PSS
// ─────────────────────────────────────────
$privateKey = $privateKey
    ->withPadding(RSA::SIGNATURE_PSS)
    ->withHash('sha256')
    ->withMGFHash('sha256')
    ->withSaltLength(32);

// ─────────────────────────────────────────
// Test Signature
// ─────────────────────────────────────────
$timestamp = (string) round(microtime(true) * 1000);
$method    = 'GET';
$path      = '/trade-api/v2/portfolio/balance';

$message   = $timestamp . $method . $path;
$signature = $privateKey->sign($message);

// ─────────────────────────────────────────
// Result
// ─────────────────────────────────────────
echo "phpseclib RSA-PSS Test\n";
echo "-----------------------\n";
echo "Timestamp: {$timestamp}\n";
echo "Message: {$message}\n";
echo "Signature Bytes: " . strlen($signature) . "\n";
echo "Base64 Signature:\n";
echo base64_encode($signature) . "\n";