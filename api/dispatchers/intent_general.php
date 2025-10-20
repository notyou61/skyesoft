<?php
// ======================================================================
// 🌐 Skyebot™ Intent Dispatcher: GENERAL
// ----------------------------------------------------------------------
// Purpose:
//   • Handles open-ended user prompts (not reports, CRUD, etc.)
//   • Provides humanized responses directly from SSE / dynamicData
//   • Zero hardcoding — all reasoning done by OpenAI using live context
// Compatibility: PHP 5.6 (GoDaddy hosting safe)
// ======================================================================

// Ensure helpers and utilities are loaded
require_once __DIR__ . '/../helpers.php';  // callOpenAi(), sendJsonResponse(), etc.

// --------------------------------------------------------------
// 🧱 Safeguard variable scope
// --------------------------------------------------------------
if (!isset($prompt)) $prompt = '';
if (!isset($dynamicData) || !is_array($dynamicData)) $dynamicData = array();
if (!isset($sessionId)) $sessionId = session_id();

// --------------------------------------------------------------
// 🧩 Build compact SSE context (keeps token usage minimal)
// --------------------------------------------------------------
$contextKeys = array('timeDateArray', 'weatherData', 'skyesoftHolidays', 'codex', 'siteMeta');
$sseContext = array();

foreach ($contextKeys as $k) {
    if (isset($dynamicData[$k])) {
        $sseContext[$k] = $dynamicData[$k];
    }
}

error_log("🧭 [intent_general] Prompt='{$prompt}' | SSE keys=" . implode(',', array_keys($sseContext)));

// --------------------------------------------------------------
// 🧠 System instruction (AI behavioral guardrails)
// --------------------------------------------------------------
$systemInstr =
    "You are Skyebot™, answering strictly from the provided SSE JSON.\n" .
    "Rules:\n" .
    "- Do not invent or guess. If the SSE doesn’t contain the answer, say so briefly.\n" .
    "- Prefer humanized phrasing, but include the exact value when useful, e.g.: \"Veterans Day is coming soon (11/11/25).\"\n" .
    "- If the user asks \"when…\", look for time/date-like values.\n" .
    "- If multiple candidates fit, pick the most specific and mention the field name briefly in parentheses.\n" .
    "- Be concise (one sentence).\n" .
    "- Never reveal internal keys unless it clarifies the answer.";

// --------------------------------------------------------------
// 🧩 Compose user message for AI model
// --------------------------------------------------------------
$userMsg =
    "User request:\n" . '"' . $prompt . '"' . "\n\n" .
    "SSE snapshot (partial):\n" .
    json_encode($sseContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// --------------------------------------------------------------
// 🚀 Call OpenAI (helpers.php manages API key & cURL)
// --------------------------------------------------------------
$messages = array(
    array("role" => "system", "content" => $systemInstr),
    array("role" => "user",   "content" => $userMsg)
);

$llmText = callOpenAi($messages);

// --------------------------------------------------------------
// 🧯 Error handling and graceful fallback
// --------------------------------------------------------------
if (empty($llmText)) {
    $fallback = querySSE($prompt, $sseContext);
    if ($fallback && isset($fallback['message'])) {
        sendJsonResponse($fallback['message'], "general", array(
            "sessionId" => $sessionId,
            "resolvedKey" => $fallback['key'],
            "score" => $fallback['score']
        ));
        exit;
    }
}

// --------------------------------------------------------------
// 📤 Return AI result (no post-formatting = no hardcoding)
// --------------------------------------------------------------
sendJsonResponse(trim($llmText), "general", array(
    "sessionId" => $sessionId
));
exit;