<?php
// ======================================================================
// 🌐 Skyebot™ Intent Dispatcher: GENERAL (Ontology-Aware v2.0)
// ----------------------------------------------------------------------
// Purpose:
//   • Handles open-ended user prompts (not reports, CRUD, etc.)
//   • Provides humanized responses directly from SSE / dynamicData
//   • Adds legacy Google fallback when internal data yields no result
//   • Integrates Codex Ontology for semantic reasoning
// Compatibility: PHP 5.6 (GoDaddy hosting safe)
// ======================================================================

require_once __DIR__ . '/../helpers.php';  // callOpenAi(), sendJsonResponse(), webFallbackSearch()

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
    if (isset($dynamicData[$k])) $sseContext[$k] = $dynamicData[$k];
}

// 🧠 Inject Codex ontology for semantic reasoning (if available)
if (isset($dynamicData['codex'])) {
    $semanticIndex = array();

    foreach ($dynamicData['codex'] as $key => $entry) {
        if (isset($entry['ontology']) && is_array($entry['ontology'])) {
            $semanticIndex[$key] = $entry['ontology'];
        }
    }

    if (!empty($semanticIndex)) {
        $sseContext['semanticIndex'] = $semanticIndex;
        error_log("🧩 Ontology context loaded: " . count($semanticIndex) . " entries");
    }
}

error_log("🧭 [intent_general] Prompt='{$prompt}' | SSE keys=" . implode(',', array_keys($sseContext)));

// --------------------------------------------------------------
// 🧠 System instruction (AI behavioral guardrails)
// --------------------------------------------------------------
$systemInstr =
  "You are Skyebot™, an intelligent assistant connected to the Skyesoft system.\n" .
  "You have three knowledge sources:\n" .
  "1. SSE JSON (live operational data)\n" .
  "2. Codex Ontology (semantic meaning of Skyesoft modules)\n" .
  "3. Web results (when internal data lacks the answer)\n\n" .
  "Rules:\n" .
  "- Prefer answers from SSE if clearly relevant.\n" .
  "- Use ontology when interpreting meaning between related modules (e.g., Attendance → TimeIntervalStandards).\n" .
  "- If SSE and Codex both lack relevant data, use webFallbackSearch for verified public info.\n" .
  "- Always reply in one concise, human-readable sentence.\n" .
  "- When referencing external info, prefix with 🌍.\n" .
  "- Never invent data; when uncertain, say so briefly and cite source when applicable.";

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
// 🧯 1️⃣ Primary SSE lexical fallback (AI returned nothing)
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

    // No SSE match — go directly to the web
    $web = webFallbackSearch($prompt);
    sendJsonResponse($web['response'], "general", array(
        "sessionId" => $sessionId,
        "source"    => "web",
        "url"       => isset($web['url']) ? $web['url'] : ''
    ));
    exit;
}

// --------------------------------------------------------------
// 🌐 2️⃣ Web fallback if AI’s reply indicates lack of data
// --------------------------------------------------------------
$denialPatterns = array(
    'not found', 'no data', 'no information', 'does not include',
    'i’m sorry', 'im sorry', 'cannot find', 'not available', 'unsure'
);
$triggerWeb = false;
foreach ($denialPatterns as $p) {
    if (stripos($llmText, $p) !== false) {
        $triggerWeb = true;
        break;
    }
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
// 📤 3️⃣ Normal return — AI result (no post-formatting)
// --------------------------------------------------------------
sendJsonResponse(trim($llmText), "general", array(
    "sessionId" => $sessionId,
    "source"    => "sse"
));
exit;