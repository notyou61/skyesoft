<?php

// #region 🚧 Internal Test Mode (bypass controller)
define('SKYESOFT_INTERNAL', true);
// #endregion


// #region 🔗 Load Core API
require_once __DIR__ . '/askOpenAI.php';
// #endregion


// #region 🌱 Load Environment
skyesoftLoadEnv();
// #endregion


// #region 🔑 Retrieve Keys

$apiKey = skyesoftGetEnv("OPENAI_API_KEY");

// Debug env (temporary)
error_log('[env] OPENAI=' . ($apiKey ? 'OK' : 'NULL'));
error_log('[env] DB_USER=' . var_export(skyesoftGetEnv("DB_USER"), true));
error_log('[env] DB_PASS=' . var_export(skyesoftGetEnv("DB_PASS"), true));

// #endregion


// #region 🤖 Execute Test Prompt

$response = callOpenAI(
    "Say 'Skyesoft test successful.'",
    $apiKey
);

// #endregion


// #region 📤 Output Result

var_dump($response);

// #endregion