<?php

require_once __DIR__ . '/askOpenAI.php';

// Simulate env load
skyesoftLoadEnv();

$apiKey = skyesoftGetEnv("OPENAI_API_KEY");

$response = callOpenAI(
    "Say 'Skyesoft test successful.'",
    $apiKey
);

var_dump($response);