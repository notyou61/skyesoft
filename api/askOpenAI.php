<?php
// ============================================================================
// 🤖 Skyebot™ Semantic Responder v4.2
// Regex-Free LLM Architecture + Codex Alignment + SSE Awareness
// ============================================================================
// PHP 5.6 Compatible  |  Skyelighting.com/skyesoft  |  2025-10-20
// ----------------------------------------------------------------------------

#region 🧾 Environment / Logging
ini_set('display_errors', 1);
error_reporting(E_ALL);
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
ini_set('error_log', $logDir . '/skyebot_debug.log');
error_log("🧭 Skyebot session started " . date('Y-m-d H:i:s'));
#endregion


#region 🧠 Input Loader (CLI + Web)
$raw = @file_get_contents('php://input');
if (PHP_SAPI === 'cli' && (empty($raw) || trim($raw) === '')) {
    global $argv; if (!empty($argv[1])) $raw = $argv[1];
}
$data = json_decode(trim($raw), true);
if (!is_array($data)) { echo json_encode(["response"=>"❌ Invalid JSON","action"=>"error"]); exit; }
$prompt = isset($data['prompt']) ? trim($data['prompt']) : '';
$conversation = isset($data['conversation']) && is_array($data['conversation']) ? $data['conversation'] : [];
if ($prompt === '') { echo json_encode(["response"=>"❌ Empty prompt.","action"=>"none"]); exit; }
#endregion


#region 🔩 Dependencies
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/env_boot.php";
date_default_timezone_set("America/Phoenix");
if (session_status() === PHP_SESSION_NONE) @session_start();
$sessionId = session_id();
header("Content-Type: application/json; charset=UTF-8");
#endregion


#region 🌐 Load Unified Context (DynamicData + Codex)
$dynamicUrl = 'https://www.skyelighting.com/skyesoft/api/getDynamicData.php';
$ch = curl_init($dynamicUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false
]);
$dynamicRaw = curl_exec($ch);
curl_close($ch);
$dynamicData = json_decode($dynamicRaw, true);
if (!is_array($dynamicData)) $dynamicData = [];
$allModules = [];
if (isset($dynamicData['codex'])) {
    foreach ($dynamicData['codex'] as $k => $v) {
        if (is_array($v)) $allModules[$k] = $v;
        if (isset($v['modules'])) foreach ($v['modules'] as $m => $x) $allModules[$m] = $x;
    }
}
error_log("📚 Loaded Codex modules: " . count($allModules));
#endregion


#region 🔍 Helper: Codex Semantic Resolver (DRY + Ontology)
function resolveCodexSlugByTitle($prompt, $modules) {
    $promptLower = strtolower($prompt);
    $bestSlug = null; $bestScore = 0.0;
    foreach ($modules as $slug => $meta) {
        if (!isset($meta['title'])) continue;
        $title = strtolower($meta['title']);
        $aliases = isset($meta['relationships']['aliases'])
            ? array_map('strtolower', (array)$meta['relationships']['aliases']) : [];
        $bag = array_merge([$title], $aliases);
        foreach ($bag as $term) {
            if (strlen($term) < 3) continue;
            similar_text($promptLower, $term, $percent);
            if ($percent > $bestScore) { $bestScore = $percent; $bestSlug = $slug; }
        }
    }
    if ($bestSlug && $bestScore > 30) {
        error_log("🔗 Codex match: '$prompt' → '$bestSlug' ($bestScore%)");
        return $bestSlug;
    }
    error_log("⚠️ No strong Codex match ($bestScore%)");
    return null;
}
#endregion


#region 🤖 Intent Classification (LLM Router)
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) { echo json_encode(["response"=>"❌ API key not found.","action"=>"error"]); exit; }

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
$routerMessages = [
    ["role"=>"system","content"=>"You are Skyebot’s intent classifier. Respond only with JSON."],
    ["role"=>"user","content"=>$routerPrompt]
];
$routerResponse = callOpenAi($routerMessages, $apiKey);
$intentData = json_decode(trim($routerResponse), true);
#endregion


#region 🧩 Interpret Intent
$intent = isset($intentData['intent']) ? strtolower($intentData['intent']) : 'general';
$target = isset($intentData['target']) ? strtolower($intentData['target']) : null;
$confidence = isset($intentData['confidence']) ? (float)$intentData['confidence'] : 0;

if ($confidence < 0.6) {
    $target = resolveCodexSlugByTitle($prompt, $allModules);
    if ($target) $intent = 'report';
}
error_log("🧭 Intent: $intent | Target: $target | Conf: $confidence | Prompt: $prompt");
#endregion


#region 🧾 Execute Intent (Summary / Report / CRUD / General)

// -- Summary --------------------------------------------------------------
if ($intent === "summary" && $target && isset($allModules[$target])) {
    $m = $allModules[$target];
    $summary = [
        "title" => isset($m['title']) ? $m['title'] : $target,
        "purpose" => isset($m['purpose']['text']) ? $m['purpose']['text']
            : (isset($m['purpose']) ? $m['purpose'] : ""),
        "features" => isset($m['features']['items']) ? $m['features']['items'] : [],
        "category" => isset($m['category']) ? $m['category'] : ""
    ];
    echo json_encode([
        "response" => "📘 " . $summary['title'] . " — " . $summary['purpose'],
        "details" => $summary,
        "action" => "summary",
        "sessionId" => $sessionId
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}

// -- Report ---------------------------------------------------------------
if ($intent === "report" && $target) {
    $generatorUrl = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . urlencode($target);
    $payload = json_encode(["slug" => $target]);
    $ch = curl_init($generatorUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 🧩 Auto-detect PDF path in generator output
    if ($code === 200 && preg_match('/(\/docs\/sheets\/.+\.pdf)/i', $result, $matches)) {
        $pdfPath = trim($matches[1]);
        $publicLink = (strpos($pdfPath, 'http') === 0)
            ? $pdfPath
            : 'https://skyelighting.com/skyesoft' . $pdfPath;

        echo json_encode([
            "response" => "✅ Information Sheet generated for $target",
            "link" => $publicLink,
            "action" => "sheet_generated",
            "sessionId" => $sessionId
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        echo json_encode([
            "response" => "⚠️ No valid report target specified.",
            "action" => "error",
            "sessionId" => $sessionId
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// -- CRUD ---------------------------------------------------------------
if ($intent === "crud") {
    echo json_encode([
        "response" => "⚙️ CRUD intent detected (module: $target).",
        "action" => "crud_placeholder",
        "sessionId" => $sessionId
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}

// -- General -------------------------------------------------------------
$messages = [
    ["role"=>"system","content"=>"You are Skyebot™, contextual AI for Skyesoft. Use Codex and SSE data."],
    ["role"=>"user","content"=>$prompt]
];
$aiResponse = callOpenAi($messages, $apiKey);
echo json_encode([
    "response" => trim($aiResponse),
    "action" => "answer",
    "sessionId" => $sessionId
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
exit;

#endregion