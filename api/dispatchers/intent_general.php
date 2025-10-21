<?php
// ======================================================================
// 🌐 Skyebot™ Intent Dispatcher: GENERAL  (v2.5 – Derived Time Patch)
// ----------------------------------------------------------------------
// • Handles open-ended prompts via SSE + AI reasoning
// • Computes derived metrics like “time until sunset” or “workday ends in”
// • Uses deterministic math, not LLM estimation
// Compatibility: PHP 5.6 (GoDaddy safe)
// ======================================================================

require_once __DIR__ . '/../helpers.php'; // callOpenAi(), sendJsonResponse(), webFallbackSearch(), etc.

// --------------------------------------------------------------
// 🧱 Safeguard variable scope
// --------------------------------------------------------------
if (!isset($prompt)) $prompt = '';
if (!isset($dynamicData) || !is_array($dynamicData)) $dynamicData = array();
if (!isset($sessionId)) $sessionId = session_id();

// --------------------------------------------------------------
// 🧩 Build compact SSE context
// --------------------------------------------------------------
$contextKeys = array('timeDateArray','weatherData','skyesoftHolidays','codex','siteMeta');
$sseContext  = array();
foreach ($contextKeys as $k) {
    if (isset($dynamicData[$k])) $sseContext[$k] = $dynamicData[$k];
}

// --------------------------------------------------------------
// ⏳ Derived-time helpers (server-side, no hardcoding)
// --------------------------------------------------------------
if (!function_exists('secondsUntilTodayClock')) {
    function secondsUntilTodayClock($targetClock, $tzName) {
        $tz  = new DateTimeZone($tzName);
        $now = new DateTime('now', $tz);
        $t   = DateTime::createFromFormat('g:i A', trim($targetClock), $tz);
        if (!$t) return null;
        $t->setDate($now->format('Y'), $now->format('m'), $now->format('d'));
        $diff = $t->getTimestamp() - $now->getTimestamp();
        return ($diff < 0) ? 0 : $diff;
    }
}
if (!function_exists('humanizeSecondsShort')) {
    function humanizeSecondsShort($secs) {
        if ($secs === null) return '';
        $mins = floor($secs / 60);
        $hrs  = floor($mins / 60);
        $rem  = $mins % 60;
        if ($hrs > 0 && $rem > 0) return $hrs . " hours and " . $rem . " minutes";
        if ($hrs > 0) return $hrs . " hours";
        return $mins . " minutes";
    }
}

// --------------------------------------------------------------
// 🧮 Attach derived metrics (sunset, workday, etc.)
// --------------------------------------------------------------
$tzName = isset($sseContext['timeDateArray']['timeZone'])
    ? $sseContext['timeDateArray']['timeZone']
    : 'America/Phoenix';

$derived = array();

// Sunset / daylight end
if (isset($sseContext['timeDateArray']['daylightStartEndArray']['daylightEnd'])) {
    $sunset = $sseContext['timeDateArray']['daylightStartEndArray']['daylightEnd'];
    $secs   = secondsUntilTodayClock($sunset, $tzName);
    $derived['sunsetAt'] = $sunset;
    $derived['sunsetIn'] = humanizeSecondsShort($secs);
}

// Workday end (from Codex TimeIntervalStandards)
if (isset($sseContext['codex']['timeIntervalStandards']['segmentsOffice']['end'])) {
    $workEnd = $sseContext['codex']['timeIntervalStandards']['segmentsOffice']['end'];
    $secsW   = secondsUntilTodayClock($workEnd, $tzName);
    $derived['workdayEndsAt'] = $workEnd;
    $derived['workdayEndsIn'] = humanizeSecondsShort($secsW);
}

// Attach derived section if available
if (!empty($derived)) $sseContext['derived'] = $derived;

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
  "- Use derived fields (e.g., derived.sunsetIn) for time intervals.\n" .
  "- If SSE lacks relevant data, use webFallbackSearch to infer facts from the web.\n" .
  "- Always return a single concise, human-readable sentence.\n" .
  "- When using web data, prefix with 🌍.\n" .
  "- Never guess numeric intervals; rely on derived values if available.\n" .
  "- Never list JSON keys unless it clarifies the answer.";

// --------------------------------------------------------------
// 🧩 Compose user message for AI model
// --------------------------------------------------------------
$userMsg =
  "User request:\n" . '"' . $prompt . '"' . "\n\n" .
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
// 🧯 Fallback chain (SSE → Web)
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
    $web = webFallbackSearch($prompt);
    sendJsonResponse($web['response'], "general", array(
        "sessionId" => $sessionId,
        "source"    => "web",
        "url"       => isset($web['url']) ? $web['url'] : ''
    ));
    exit;
}

// --------------------------------------------------------------
// 🌐 Web fallback if AI reply indicates missing data
// --------------------------------------------------------------
$denialPatterns = array('not found','no data','no information','does not include','sorry','cannot find','unsure');
$triggerWeb = false;
foreach ($denialPatterns as $p) {
    if (stripos($llmText, $p) !== false) { $triggerWeb = true; break; }
}
if ($triggerWeb) {
    $fallback = webFallbackSearch($prompt);
    sendJsonResponse($fallback['response'], "general", array(
        "sessionId" => $sessionId,
        "source"    => "web",
        "url"       => isset($fallback['url']) ? $fallback['url'] : ''
    ));
    exit;
}

// --------------------------------------------------------------
// 📤 Normal return — AI result
// --------------------------------------------------------------
sendJsonResponse(trim($llmText), "general", array(
    "sessionId" => $sessionId,
    "source"    => "sse"
));
exit;