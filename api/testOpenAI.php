<?php
// ======================================================================
//  FILE: testOpenAI.php
//  PURPOSE: Verify OpenAI API connectivity and .env key loading
//  VERSION: v1.0.0 (PHP 5.6 Compatible)
//  AUTHOR: Skyesoft Diagnostic Utility
// ======================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ----------------------------------------------------------------------
//  STEP 1 – Load .env (GoDaddy-compatible)
// ----------------------------------------------------------------------
$envPaths = array(
    __DIR__ . '/../.env',
    '/home/notyou64/.env',
    '/home/notyou64/public_html/skyesoft/.env'
);

foreach ($envPaths as $envFile) {
    if (file_exists($envFile)) {
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
        break;
    }
}

// ----------------------------------------------------------------------
//  STEP 2 – Validate API Key
// ----------------------------------------------------------------------
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey || trim($apiKey) === '') {
    http_response_code(500);
    echo json_encode(array(
        "status"  => "error",
        "message" => "❌ API key not found or empty.",
        "hint"    => "Check .env path and ensure OPENAI_API_KEY is defined."
    ));
    exit;
}

// ----------------------------------------------------------------------
//  STEP 3 – Perform Basic Test Query
// ----------------------------------------------------------------------
$url = 'https://api.openai.com/v1/chat/completions';
$data = array(
    "model" => "gpt-3.5-turbo",
    "messages" => array(
        array("role" => "user", "content" => "Hello from Skyesoft diagnostic test. Respond with 'ACK'.")
    ),
    "max_tokens" => 10
);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$response = curl_exec($ch);
$error    = curl_error($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ----------------------------------------------------------------------
//  STEP 4 – Output Result
// ----------------------------------------------------------------------
if ($error) {
    echo json_encode(array(
        "status" => "error",
        "message" => "Curl error: " . $error
    ));
} elseif ($code !== 200) {
    echo json_encode(array(
        "status" => "error",
        "http_code" => $code,
        "response" => $response
    ));
} else {
    $decoded = json_decode($response, true);
    $msg = isset($decoded["choices"][0]["message"]["content"]) ? $decoded["choices"][0]["message"]["content"] : "(no content)";
    echo json_encode(array(
        "status" => "success",
        "message" => "✅ OpenAI API reachable.",
        "reply"   => $msg
    ));
}
