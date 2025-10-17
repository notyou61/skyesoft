<?php
// ======================================================================
// File: api/dispatchers/intent_report.php
// Purpose: Handle semantic report generation routed from askOpenAI.php
// ======================================================================
// Compatible with PHP 5.6 (GoDaddy environment)
// ======================================================================

include_once __DIR__ . '/../helpers.php';

// 🧩 1️⃣ Fallback: infer target if not passed from router
if (!isset($target) || empty($target)) {
    if (isset($prompt)) {
        $normalizedPrompt = strtolower(preg_replace('/[^a-z0-9]/', '', $prompt));

        if (isset($codexMeta) && is_array($codexMeta)) {
            foreach ($codexMeta as $key => $meta) {
                $normKey = strtolower(preg_replace('/[^a-z0-9]/', '', $key));
                $normTitle = isset($meta['title'])
                    ? strtolower(preg_replace('/[^a-z0-9]/', '', $meta['title']))
                    : '';

                if (strpos($normalizedPrompt, $normKey) !== false ||
                    strpos($normalizedPrompt, $normTitle) !== false) {
                    $target = $key;
                    error_log("🧭 intent_report.php inferred target → $key");
                    break;
                }
            }
        }
    }
}

// 🧩 2️⃣ Guard: still no target? return graceful JSON error
if (empty($target)) {
    echo json_encode(array(
        "response"  => "⚠️ No valid report target specified.",
        "action"    => "error",
        "sessionId" => isset($sessionId) ? $sessionId : uniqid("session_")
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 🧩 3️⃣ Generate report via helper bridge
if (function_exists('handleIntentReport')) {
    $responsePayload = handleIntentReport(
        array("target" => $target),
        isset($sessionId) ? $sessionId : uniqid("session_")
    );

    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 🧩 4️⃣ Fallback: helpers not loaded or function missing
echo json_encode(array(
    "response"  => "❌ Report handler unavailable (helpers.php missing or invalid).",
    "action"    => "error",
    "sessionId" => isset($sessionId) ? $sessionId : uniqid("session_")
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
