<?php
// File: api/dispatchers/intent_report.php
// Purpose: Handle semantic report generation routed from askOpenAI.php

include_once __DIR__ . '/../helpers.php';

if (!isset($intentData['target'])) {
    echo json_encode([
        "response" => "⚠️ No valid report target specified.",
        "action"   => "error",
        "sessionId" => $sessionId
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(handleIntentReport($intentData, $sessionId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;