<?php

require_once __DIR__ . '/askOpenAI.php';

// Simulate env load
skyesoftLoadEnv();

$apiKey = skyesoftGetEnv("OPENAI_API_KEY");

error_log('[env] DB_USER=' . var_export(skyesoftGetEnv("DB_USER"), true));
error_log('[env] DB_PASS=' . var_export(skyesoftGetEnv("DB_PASS"), true));

$response = callOpenAI(
    "Say 'Skyesoft test successful.'",
    $apiKey
);

var_dump($response);