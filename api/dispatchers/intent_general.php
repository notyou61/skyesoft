<?php
// ======================================================================
// 🌐 Skyebot™ Intent Dispatcher: GENERAL (v4.1 – Hallucination Guard)
// ----------------------------------------------------------------------
// • Handles open-ended user prompts using SSE + AI reasoning
// • Adds hallucination prevention when SSE data is irrelevant
// • Maintains legacy Google fallback for factual lookups
// Compatibility: PHP 5.6 (GoDaddy hosting safe)
// ======================================================================

require_once __DIR__ . '/../helpers.php';  // callOpenAi(), sendJsonResponse(), webFallbackSearch(), querySSE()

// --------------------------------------------------------------
// 🧱 Safeguard variable scope
// --------------------------------------------------------------
if (!isset($prompt)) $prompt = '';
if (!isset($dynamicData) || !is_array($dynamicData)) $dynamicData = array();
if (!isset($sessionId)) $sessionId = session_id();

// --------------------------------------------------------------
// 🧩 Build compact SSE context
// --------------------------------------------------------------
$contextKeys = array('timeDateArray', 'weatherData', 'skyesoftHolidays', 'codex', 'siteMeta');
$sseContext = array();
foreach ($contextKeys as $k) {
    if (isset($dynamicData[$k])) $sseContext[$k] = $dynamicData[$k];
}
error_log("🧭 [intent_general] Prompt='{$prompt}' | SSE keys=" . implode(',', array_keys($sseContext)));

/// --------------------------------------------------------------
// 🧠 Dynamic Hallucination Guard (Codex + SSE-driven)
// --------------------------------------------------------------
$promptLower = strtolower(trim($prompt));
$knownDomains = array();

// 1️⃣ Extract keys from SSE data
foreach ($sseContext as $key => $val) {
    $knownDomains[] = strtolower($key);
}

// 2️⃣ Extract module names from Codex (if available)
if (isset($dynamicData['codex']) && is_array($dynamicData['codex'])) {
    foreach (array_keys($dynamicData['codex']) as $codexKey) {
        $knownDomains[] = strtolower($codexKey);
    }
}

// 3️⃣ Check prompt relevance against all known terms
$relevant = false;
foreach ($knownDomains as $term) {
    if (strpos($promptLower, $term) !== false) {
        $relevant = true;
        break;
    }
}

// 4️⃣ Abort if irrelevant (no known domains found)
if (!$relevant) {
    sendJsonResponse(
        "That information isn't available in the current data stream.",
        "general",
        array(
            "sessionId" => $sessionId,
            "reason"    => "no_dynamic_relevance",
            "domains"   => $knownDomains
        )
    );
    exit;
}

// If prompt has no known domain → short-circuit before AI call
if (!$relevant) {
    sendJsonResponse(
        "That information isn't available in the current data stream.",
        "general",
        array("sessionId" => $sessionId, "reason" => "no_sse_relevance")
    );
    exit;
}

// --------------------------------------------------------------
// 🧠 System instruction (AI behavioral guardrails)
// --------------------------------------------------------------
$systemInstr =
  "You are Skyebot™, an intelligent assistant connected to the Skyesoft system.\n" .
  "You have two knowledge sources:\n" .
  "1. SSE JSON (live operational data)\n" .
  "2. Web results (when SSE lacks the answer)\n\n" .
  "Rules:\n" .
  "- Prefer answers from the SSE if they clearly match the user’s question.\n" .
  "- If SSE does not include relevant data, use webFallbackSearch to infer the answer.\n" .
  "- Respond with one concise, human-readable sentence.\n" .
  "- Prefix web answers with 🌍.\n" .
  "- Never guess; if the answer is uncertain, note that it was verified externally.\n" .
  "- Do not reveal raw JSON keys unless helpful for clarity.";

// --------------------------------------------------------------
// 🧩 Compose user message for AI model
// --------------------------------------------------------------
$userMsg =
  "User request:\n\"" . $prompt . "\"\n\n" .
  "SSE snapshot (partial):\n" .
  json_encode($sseContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// --------------------------------------------------------------
// 🚀 Call OpenAI
// --------------------------------------------------------------
$messages = array(
  array("role" => "system", "content" => $systemInstr),
  array("role" => "user",   "content" => $userMsg)
);
$llmText = callOpenAi($messages);

// --------------------------------------------------------------
// 🧯 1️⃣ SSE lexical fallback (AI returned nothing)
// --------------------------------------------------------------
if (empty($llmText)) {
    $fallback = querySSE($prompt, $sseContext);
    if ($fallback && isset($fallback['message'])) {
        sendJsonResponse($fallback['message'], "general", array(
            "sessionId"   => $sessionId,
            "resolvedKey" => $fallback['key'],
            "score"       => $fallback['score']
        ));
        exit;
    }

    // Direct web fallback
    $web = webFallbackSearch($prompt);
    sendJsonResponse($web['response'], "general", array(
        "sessionId" => $sessionId,
        "source"    => "web",
        "url"       => isset($web['url']) ? $web['url'] : ''
    ));
    exit;
}

// --------------------------------------------------------------
// 🌐 2️⃣ Web fallback if AI’s reply shows SSE insufficiency
// --------------------------------------------------------------
$denialPatterns = array(
    'not found', 'no data', 'no information', 'does not include',
    'i’m sorry', 'im sorry', 'cannot find', 'not available', 'unsure'
);
$triggerWeb = false;
foreach ($denialPatterns as $p) {
    if (stripos($llmText, $p) !== false) { $triggerWeb = true; break; }
}

if ($triggerWeb) {
    error_log("🌍 [intent_general] AI indicated lack of data — invoking web fallback for '{$prompt}'");
    $fallback = webFallbackSearch($prompt);
    sendJsonResponse($fallback['response'], "general", array(
        "sessionId" => $sessionId,
        "source"    => "web",
        "url"       => isset($fallback['url']) ? $fallback['url'] : ''
    ));
    exit;
}

// --------------------------------------------------------------
// 📤 3️⃣ Normal return — AI result
// --------------------------------------------------------------
sendJsonResponse(trim($llmText), "general", array(
    "sessionId" => $sessionId,
    "source"    => "sse"
));
exit;