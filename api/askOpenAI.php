<?php
// 📄 File: api/askOpenAI.php
// Skyebot™ Semantic Responder v4.1 — Codex-Aware, Regex-Free, LLM Architecture
// -----------------------------------------------------------------------------
// Compatible: PHP 5.6
// Environment: skyelighting.com / Skyesoft™
// -----------------------------------------------------------------------------
// Purpose: Interprets user intent using Codex + SSE + LLM reasoning.
// Distinguishes between “show me” (summary) and “generate” (report) requests.
// Fully replaces older regex- or hardcoded-based dispatch logic.

#region ⚙️ Environment / Logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
ini_set('error_log', $logDir . '/skyebot_debug.log');
error_log("🧭 Skyebot session started " . date('Y-m-d H:i:s'));
#endregion


#region 🧠 Input Loader
$raw = @file_get_contents('php://input');
if (PHP_SAPI === 'cli' && (empty($raw) || trim($raw) === '')) {
    global $argv; if (!empty($argv[1])) $raw = $argv[1];
}
$raw = trim($raw);
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(array("response" => "❌ Invalid JSON", "action" => "error")); exit;
}
$prompt = isset($data['prompt']) ? trim($data['prompt']) : '';
$conversation = isset($data['conversation']) && is_array($data['conversation']) ? $data['conversation'] : array();
if ($prompt === '') {
    echo json_encode(array("response" => "❌ Empty prompt.", "action" => "none")); exit;
}
#endregion


#region 🔩 Dependencies
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/env_boot.php";
date_default_timezone_set("America/Phoenix");
if (session_status() === PHP_SESSION_NONE) @session_start();
$sessionId = session_id();
header("Content-Type: application/json; charset=UTF-8");
#endregion


#region 🌐 Load Dynamic Context (SSE + Codex)
$dynamicUrl = "https://www.skyelighting.com/skyesoft/api/getDynamicData.php";
$ch = curl_init($dynamicUrl);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Skyebot/1.0 (+skyelighting.com)'
));
$dynamicRaw = curl_exec($ch);
curl_close($ch);
$dynamicData = json_decode($dynamicRaw, true);
if (!is_array($dynamicData)) $dynamicData = array();

$codexData = isset($dynamicData['codex']) ? $dynamicData['codex'] : array();
$allModules = array();
foreach ($codexData as $k => $v) {
    if (is_array($v)) {
        $allModules[$k] = $v;
        if (isset($v['modules']) && is_array($v['modules'])) {
            foreach ($v['modules'] as $m => $x) $allModules[$m] = $x;
        }
    }
}
error_log("📚 Codex modules loaded: " . count($allModules));
#endregion


#region 🧭 Codex Semantic Resolver (Regex-Free)
function resolveCodexSlugByTitle($prompt, $modules) {
    if (empty($modules)) return null;
    $promptLower = strtolower($prompt);
    $bestSlug = null; $bestScore = 0.0;

    foreach ($modules as $slug => $meta) {
        if (!isset($meta['title'])) continue;
        $title = strtolower($meta['title']);
        $aliases = isset($meta['relationships']['aliases'])
            ? array_map('strtolower', (array)$meta['relationships']['aliases'])
            : array();
        $bag = array_merge(array($title), $aliases);

        foreach ($bag as $term) {
            if (strlen($term) < 3) continue;
            if (strpos($promptLower, $term) !== false) {
                $percent = 100;
            } else {
                similar_text($promptLower, $term, $percent);
            }
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $bestSlug = $slug;
            }
        }
    }

    if ($bestSlug && $bestScore > 30) {
        error_log("🔗 Codex semantic match: '$prompt' → '$bestSlug' ($bestScore%)");
        return $bestSlug;
    }
    error_log("⚠️ No strong Codex match (max $bestScore%)");
    return null;
}
#endregion


#region 🤖 Intent Classification (LLM Router)
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) { echo json_encode(array("response" => "❌ API key not found.", "action" => "error")); exit; }

$routerPrompt = <<<PROMPT
You are Skyebot™, the Semantic Responder.
Classify the user’s intent based on meaning, not keywords.

Possible intents:
- "summary": textual explanation (e.g., "show me", "describe", "what is").
- "report": generate a document or sheet (e.g., "generate", "create").
- "crud": perform create, update, delete, or read actions.
- "general": conversational or undefined.

Return ONLY JSON in this format:
{"intent":"summary|report|crud|general","target":"codex-slug","confidence":0.0-1.0}

User message:
"$prompt"

Codex modules (titles + aliases):
PROMPT;
foreach ($allModules as $slug => $mod) {
    $title = isset($mod['title']) ? $mod['title'] : $slug;
    $aliases = isset($mod['relationships']['aliases']) ? implode(', ', (array)$mod['relationships']['aliases']) : '';
    $routerPrompt .= "- $slug: $title | $aliases\n";
}

$routerMessages = array(
    array("role" => "system", "content" => "You are Skyebot’s intent classifier. Respond only with JSON."),
    array("role" => "user", "content" => $routerPrompt)
);
$routerResponse = callOpenAi($routerMessages, $apiKey);
$intentData = json_decode(trim($routerResponse), true);
#endregion


#region 🧩 Interpret Router Output + Resolve Target
$intent = isset($intentData['intent']) ? strtolower($intentData['intent']) : 'general';
$target = isset($intentData['target']) ? strtolower($intentData['target']) : null;
$confidence = isset($intentData['confidence']) ? (float)$intentData['confidence'] : 0.0;

if (!$target || !isset($allModules[$target])) {
    $resolved = resolveCodexSlugByTitle($prompt, $allModules);
    if ($resolved) $target = $resolved;
}
error_log("🧭 Intent=$intent | Target=$target | Confidence=$confidence | Prompt=$prompt");
#endregion


#region 🧾 Intent Routing
// 1️⃣ SUMMARY
if ($intent === 'summary' && $target && isset($allModules[$target])) {
    $m = $allModules[$target];
    $purpose = isset($m['purpose']['text']) ? $m['purpose']['text'] :
               (isset($m['purpose']) ? $m['purpose'] : 'No description available.');
    echo json_encode(array(
        "response" => "📘 " . $m['title'] . " — " . $purpose,
        "action" => "summary",
        "sessionId" => $sessionId
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 2️⃣ REPORT
if ($intent === 'report' && $target) {
    $generatorUrl = "https://www.skyelighting.com/skyesoft/api/generateReports.php?module=" . urlencode($target);
    $payload = json_encode(array("slug" => $target));
    $ch = curl_init($generatorUrl);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ));
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && preg_match('/✅ PDF created successfully:\s*(.+)$/m', $result, $m)) {
        $link = trim($m[1]);
        echo json_encode(array(
            "response" => "✅ Information Sheet generated for $target",
            "link" => $link,
            "action" => "sheet_generated",
            "sessionId" => $sessionId
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode(array(
            "response" => "⚠️ Report generation failed (HTTP $code).",
            "body" => substr($result, 0, 200),
            "action" => "error",
            "sessionId" => $sessionId
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// 3️⃣ CRUD (placeholder)
if ($intent === 'crud') {
    echo json_encode(array(
        "response" => "🧩 CRUD intent detected but no dispatcher configured.",
        "action" => "crud_placeholder",
        "sessionId" => $sessionId
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion


#region 💬 Default Conversational Fallback
$messages = array(
    array("role" => "system", "content" => "You are Skyebot™, contextual AI grounded in Codex and SSE data."),
    array("role" => "user", "content" => $prompt)
);
$aiResponse = callOpenAi($messages, $apiKey);
echo json_encode(array(
    "response" => trim($aiResponse),
    "action" => "answer",
    "sessionId" => $sessionId
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
#endregion