<?php
// File: api/dispatchers/intent_crud.php
// Purpose: Handle semantic CRUD intents routed from askOpenAI.php

include_once __DIR__ . '/../helpers.php';

if (!isset($intentData) || empty($intentData['target'])) {
    echo json_encode([
        "response" => "⚠️ No valid CRUD target specified.",
        "action"   => "error",
        "sessionId" => $sessionId
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(handleIntentCrud($intentData, $sessionId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;