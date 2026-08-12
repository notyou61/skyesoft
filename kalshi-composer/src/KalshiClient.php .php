<?php
declare(strict_types=1);


// ======================================================================
// Skyesoft — KalshiClient.php
// Version: 0.1.0
// Last Updated: 2026-08-12
//
// Role:
// Provides reusable authenticated read-only access to the Kalshi API.
//
// Responsibilities:
//  • Load Kalshi credentials from the Skyesoft environment
//  • Load the RSA private key
//  • Generate Kalshi RSA-PSS authentication signatures
//  • Execute authenticated GET requests
//  • Decode and return JSON responses
//
// Safety:
//  • Read-only API client
//  • No POST / order-placement methods
//  • Live trading capability intentionally excluded
//
// Dependencies:
//  • phpseclib/phpseclib
//  • Skyesoft envLoader.php
//
// ======================================================================


require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/api/utils/envLoader.php';


use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;


class KalshiClient {


    private string $apiKey;
    private string $baseUrl;
    private PrivateKey $privateKey;


    // ------------------------------------------------------------------
    // Constructor
    // ------------------------------------------------------------------

    public function __construct() {


        // Load Skyesoft environment
        skyesoftLoadEnv();


        // Resolve Kalshi configuration
        $apiKey  = skyesoftGetEnv('KALSHI_API_KEY');
        $keyPath = skyesoftGetEnv('KALSHI_PRIVATE_KEY_PATH');
        $baseUrl = skyesoftGetEnv('KALSHI_BASE_URL');


        // Validate environment
        if (!$apiKey) {
            throw new RuntimeException(
                'KALSHI_API_KEY is not configured.'
            );
        }

        if (!$keyPath) {
            throw new RuntimeException(
                'KALSHI_PRIVATE_KEY_PATH is not configured.'
            );
        }

        if (!$baseUrl) {
            throw new RuntimeException(
                'KALSHI_BASE_URL is not configured.'
            );
        }

        if (!file_exists($keyPath)) {
            throw new RuntimeException(
                'Kalshi private key was not found.'
            );
        }


        // Load private key
        $privateKeyContent = file_get_contents($keyPath);

        if ($privateKeyContent === false) {
            throw new RuntimeException(
                'Unable to read Kalshi private key.'
            );
        }


        $privateKey = PublicKeyLoader::loadPrivateKey(
            $privateKeyContent
        );


        // Validate RSA key
        if (!$privateKey instanceof PrivateKey) {
            throw new RuntimeException(
                'Kalshi private key is not an RSA private key.'
            );
        }


        // Configure Kalshi RSA-PSS signing
        $privateKey = $privateKey
            ->withPadding(RSA::SIGNATURE_PSS)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->withSaltLength(32);


        // Store client configuration
        $this->apiKey    = $apiKey;
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->privateKey = $privateKey;
    }


    // ------------------------------------------------------------------
    // Authenticated GET
    // ------------------------------------------------------------------

    public function get(string $path, array $query = []): array {


        // Normalize API path
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }


        // Build query string
        $queryString = '';

        if ($query) {
            $queryString = '?' . http_build_query(
                $query,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        }


        // Kalshi signs path only (query excluded)
        $signaturePath = $path;


        // Build request
        $method    = 'GET';
        $timestamp = (string) round(microtime(true) * 1000);
        $message   = $timestamp . $method . $signaturePath;


        // Sign request
        $signature = $this->privateKey->sign($message);

        $signatureBase64 = base64_encode($signature);


        // Build URL
        $url = $this->baseUrl . $path . $queryString;


        // Build headers
        $headers = [
            'KALSHI-ACCESS-KEY: ' . $this->apiKey,
            'KALSHI-ACCESS-SIGNATURE: ' . $signatureBase64,
            'KALSHI-ACCESS-TIMESTAMP: ' . $timestamp,
            'Accept: application/json'
        ];


        // Initialize request
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException(
                'Unable to initialize cURL.'
            );
        }


        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true
        ]);


        // Execute request
        $response = curl_exec($ch);

        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $curlError = curl_error($ch);

        curl_close($ch);


        // Handle transport failure
        if ($response === false) {
            throw new RuntimeException(
                'Kalshi request failed: ' . $curlError
            );
        }


        // Decode response
        $decoded = json_decode($response, true);


        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Kalshi returned an invalid JSON response.'
            );
        }


        // Handle HTTP failure
        if ($httpCode < 200 || $httpCode >= 300) {

            $message = $decoded['error']['message']
                ?? 'Unknown Kalshi API error';

            throw new RuntimeException(
                "Kalshi API HTTP {$httpCode}: {$message}"
            );
        }


        return $decoded;
    }
}