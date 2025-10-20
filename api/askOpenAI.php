<?php
// ============================================================================
// 🤖 Skyebot™ Semantic Responder v5.0
// Codex-Aligned | SSE-Aware | Regex-Free | PHP 5.6 Compatible
// ----------------------------------------------------------------------------
// Purpose: Interpret user prompts semantically using Codex + SSE context,
// then act on intent → summary, report, crud, or general reasoning.
// ============================================================================

#region 🧾  Environment / Logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
ini_set('error_log', $logDir . '/skyebot_debug.log');
error_log("🧭 Skyebot v5.0 session " . date('Y-m-d H:i:s'));
#endregion


#region 🧠  Input Loader
$raw = @file_get_contents('php://input');
if (PHP_SAPI === 'cli' && (empty($raw) || trim($raw) === '')) {
    global $argv; if (!empty($argv[1])) $raw = $argv[1];
}
$data = json_decode(trim($raw), true);
if (!is_array($data)) { echo json_encode(["response"=>"❌ Invalid JSON","action"=>"error"]); exit; }

$prompt = isset($data['prompt']) ? trim($data['prompt']) : '';
$conversation = isset($data['conversation']) && is_array($data['conversation']) ? $data['conversation'] : [];
if ($prompt === '') { echo json_encode(["response"=>"❌ Empty prompt.","action"=>"none"]); exit; }

date_default_timezone_set("America/Phoenix");
if (session_status() === PHP_SESSION_NONE) @session_start();
$sessionId = session_id();
header("Content-Type: application/json; charset=UTF-8");
#endregion


#region 🔩  Dependencies
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/env_boot.php";
#endregion


#region 🌐  Load SSE + Codex Context
$dynamicUrl = 'https://www.skyelighting.com/skyesoft/api/getDynamicData.php';
$ch = curl_init($dynamicUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => false
]);
$dynamicRaw = curl_exec($ch);
curl_close($ch);
$dynamicData = json_decode($dynamicRaw, true);
if (!is_array($dynamicData)) $dynamicData = [];
$sse = isset($dynamicData['sseSnapshot']) ? $dynamicData['sseSnapshot'] : [];
$codex = isset($dynamicData['codex']) ? $dynamicData['codex'] : [];
$allModules = [];
foreach ($codex as $k => $v) {
    if (is_array($v)) $allModules[$k] = $v;
    if (isset($v['modules'])) foreach ($v['modules'] as $m => $x) $allModules[$m] = $x;
}
error_log("📚 Codex modules loaded: " . count($allModules));
#endregion


#region 🧩  Helper — Semantic Resolver (Codex-Wide)
function resolveCodexSlugByTitle($prompt, $modules) {
    $promptLower = strtolower($prompt);
    $bestSlug = null; $bestScore = 0;
    foreach ($modules as $slug => $meta) {
        if (!isset($meta['title'])) continue;
        $title = strtolower($meta['title']);
        $aliases = isset($meta['relationships']['aliases'])
            ? array_map('strtolower', (array)$meta['relationships']['aliases'])
            : [];
        $bag = array_merge([$title], $aliases);
        foreach ($bag as $term) {
            if (strlen($term) < 3) continue;
            similar_text($promptLower, $term, $pct);
            if ($pct > $bestScore) { $bestScore = $pct; $bestSlug = $slug; }
        }
    }
    if ($bestSlug && $bestScore > 30) {
        error_log("🔗 Codex semantic match: '$prompt' → '$bestSlug' ($bestScore%)");
        return $bestSlug;
    }
    return null;
}
#endregion


#region 🤖  LLM Router (Intent Classification)
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) { echo json_encode(["response"=>"❌ API key not found.","action"=>"error"]); exit; }

$routerPrompt = <<<PROMPT
You are Skyebot™, the Semantic Responder for Skyesoft.
Determine what the user wants based on meaning (not keywords).

Return strict JSON:
{"intent":"summary|report|crud|general","target":"codex-slug","confidence":0.0-1.0"}

User message:
"$prompt"

Codex modules (titles and aliases):
PROMPT;
foreach ($allModules as $slug => $m) {
    $t = isset($m['title']) ? $m['title'] : $slug;
    $a = isset($m['relationships']['aliases']) ? implode(', ', (array)$m['relationships']['aliases']) : '';
    $routerPrompt .= "- $slug: $t | $a\n";
}

$routerMessages = [
    ["role"=>"system","content"=>"Respond only with JSON."],
    ["role"=>"user","content"=>$routerPrompt]
];
$routerResponse = callOpenAi($routerMessages, $apiKey);
$intentData = json_decode(trim($routerResponse), true);

$intent = isset($intentData['intent']) ? strtolower($intentData['intent']) : 'general';
$target = isset($intentData['target']) ? strtolower($intentData['target']) : null;
$conf   = isset($intentData['confidence']) ? (float)$intentData['confidence'] : 0;

if ($conf < 0.6 || !$target || !isset($allModules[$target])) {
    $fallback = resolveCodexSlugByTitle($prompt, $allModules);
    if ($fallback) { $target = $fallback; if ($intent === 'general') $intent = 'report'; }
}
error_log("🧭 Intent=$intent | Target=$target | Conf=$conf | Prompt=$prompt");
#endregion


#region 🧾  Intent Routing
// ---------- Summary ---------------------------------------------------------
if ($intent === 'summary' && $target && isset($allModules[$target])) {
    $m = $allModules[$target];
    $summary = [
        "title" => isset($m['title']) ? $m['title'] : $target,
        "purpose" => isset($m['purpose']['text']) ? $m['purpose']['text'] :
            (isset($m['purpose']) ? $m['purpose'] : ''),
        "category" => isset($m['category']) ? $m['category'] : '',
        "features" => isset($m['features']['items']) ? $m['features']['items'] : []
    ];
    echo json_encode([
        "response" => "📘 {$summary['title']} — {$summary['purpose']}",
        "details"  => $summary,
        "action"   => "summary",
        "sessionId"=> $sessionId
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- Report ----------------------------------------------------------
if ($intent === 'report' && $target) {
    $genUrl = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . urlencode($target);
    $payload = json_encode(["slug"=>$target]);
    $ch = curl_init($genUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_TIMEOUT=>30
    ]);
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && preg_match('/(\/docs\/sheets\/.+\.pdf)/i', $result, $m)) {
        $pdfPath = trim($m[1]);
        $link = (strpos($pdfPath, 'http') === 0)
            ? $pdfPath
            : 'https://skyelighting.com/skyesoft' . $pdfPath;
        echo json_encode([
            "response"=>"✅ Information Sheet generated for $target",
            "link"=>$link,
            "action"=>"sheet_generated",
            "sessionId"=>$sessionId
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        echo json_encode([
            "response"=>"⚠️ Report generation failed (HTTP $code).",
            "body"=>substr($result,0,200),
            "action"=>"error",
            "sessionId"=>$sessionId
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ---------- CRUD ------------------------------------------------------------
if ($intent === 'crud') {
    echo json_encode([
        "response"=>"⚙️ CRUD request (module: $target). Dispatcher pending.",
        "action"=>"crud_placeholder",
        "sessionId"=>$sessionId
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- General / SSE Context ------------------------------------------
$now = isset($sse['timeDateArray']['time']) ? $sse['timeDateArray']['time'] : date('h:i A');
$conditions = isset($sse['weatherData']['description']) ? $sse['weatherData']['description'] : 'clear';
$loc = isset($sse['weatherData']['city']) ? $sse['weatherData']['city'] : 'Phoenix';
$dynamicContext = "As of $now in $loc, conditions are $conditions.";

$messages = [
    ["role"=>"system","content"=>"You are Skyebot™, use Codex + SSE to respond naturally."],
    ["role"=>"system","content"=>"SSE snapshot: $dynamicContext"],
    ["role"=>"user","content"=>$prompt]
];
$reply = callOpenAi($messages, $apiKey);

echo json_encode([
    "response"=>trim($reply),
    "context"=>$dynamicContext,
    "action"=>"answer",
    "sessionId"=>$sessionId
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
exit;
#endregion