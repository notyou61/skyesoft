<?php
// ======================================================================
//  FILE: testOpenAI.php
//  PURPOSE: Diagnostic utility – verify .env loading & OpenAI connectivity
//  VERSION: v1.1.0  (PHP 5.6 Compatible)
//  AUTHOR: CPAP-01 Parliamentarian Integration
// ======================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// ----------------------------------------------------------------------
//  STEP 0 – Load Environment (.env) for GoDaddy PHP 5.6 Compatibility
// ----------------------------------------------------------------------
$envPaths = array(
    __DIR__ . '/.env',                             // Local (api folder)
    __DIR__ . '/../.env',                          // Project-level
    '/home/notyou64/.env',                         // Account-level (confirmed)
    '/home/notyou64/secure/.env',                  // Alternate secure location
    '/home/notyou64/public_html/skyesoft/.env'     // Webroot fallback
);

foreach ($envPaths as $envFile) {
    if (!file_exists($envFile)) continue;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = str_replace("\r", '', trim($line));
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
    break; // stop after first valid .env
}

// ----------------------------------------------------------------------
//  STEP 1 – Retrieve and Validate API Key
// ----------------------------------------------------------------------
$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey || trim($apiKey) === '') {
    http_response_code(500);
    echo json_encode(array(
        'status'  => 'error',
        'message' => '❌ API key not found or empty.',
        'hint'    => 'Check .env path and ensure OPENAI_API_KEY is defined.'
    ));
    exit;
}

// ----------------------------------------------------------------------
//  STEP 2 – Perform Lightweight OpenAI Request
// ----------------------------------------------------------------------
$url  = 'https://api.openai.com/v1/chat/completions';
$data = array(
    'model' => 'gpt-3.5-turbo',
    'messages' => array(array('role' => 'user', 'content' => 'Reply "ACK"')),
    'max_tokens'  => 5,
    'temperature' => 0
);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ----------------------------------------------------------------------
//  STEP 3 – Parse Response
// ----------------------------------------------------------------------
if ($err) {
    http_response_code(500);
    echo json_encode(array(
        'status'  => 'error',
        'message' => '❌ cURL error: ' . $err
    ));
    exit;
}

if ($code !== 200) {
    http_response_code($code);
    echo json_encode(array(
        'status'  => 'error',
        'message' => '❌ OpenAI API responded with HTTP ' . $code,
        'body'    => $resp
    ));
    exit;
}

$decoded = json_decode($resp, true);
$reply   = isset($decoded['choices'][0]['message']['content'])
    ? trim($decoded['choices'][0]['message']['content'])
    : '[Empty Response]';

// ----------------------------------------------------------------------
//  STEP 4 – Return JSON
// ----------------------------------------------------------------------
echo json_encode(array(
    'status'  => 'success',
    'message' => '✅ OpenAI API reachable.',
    'reply'   => $reply,
    'version' => 'v1.1.0'
));
exit;