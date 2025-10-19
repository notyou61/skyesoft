<?php
// 📄 File: api/askOpenAI.php
// Skyebot™ Semantic Responder v3.1 (Full Codex-Aligned, Regex-Free LLM Architecture)
// ---------------------------------------------------------------
// Entry point for Skyebot AI interactions (PHP 5.6 compatible refactor v3.1)
// Integrates original shielding/input/logging with enhanced semantic routing.
// Compatible with PHP 5.6+ | Skyelighting.com | 2025-10-19

#region 🧾 SKYEBOT LOCAL LOGGING SETUP (FOR GODADDY PHP 5.6)
ini_set('display_errors', 1);
error_reporting(E_ALL);
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/skyebot_debug.log';
ini_set('error_log', $logFile);
error_log("🧭 --- New Skyebot session started at " . date('Y-m-d H:i:s') . " ---");
#endregion

#region 🧠 SKYEBOT UNIVERSAL INPUT LOADER (CLI + WEB Compatible)
// ================================================================
// Purpose: Accepts input via HTTP POST (web) or CLI arguments (local testing)
// Repairs malformed JSON payloads and prevents null crashes
// Normalizes `$prompt`, `$conversation`, and `$lowerPrompt` variables
// Fully compatible with PHP 5.6+
// ================================================================
$rawInput = @file_get_contents('php://input');
if (PHP_SAPI === 'cli' && (empty($rawInput) || trim($rawInput) === '')) {
    global $argv;
    if (isset($argv[1]) && trim($argv[1]) !== '') {
        $rawInput = $argv[1];
    }
}
$rawInput = trim($rawInput);
$inputData = json_decode($rawInput, true);
if (!is_array($inputData)) {
    $fixed = trim($rawInput, "\"'");
    $inputData = json_decode($fixed, true);
}
if (!is_array($inputData) || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(array(
        'response'  => '❌ Invalid or empty JSON payload.',
        'action'    => 'none',
        'sessionId' => uniqid('sess_')
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$prompt = isset($inputData['prompt'])
    ? trim(strip_tags(filter_var($inputData['prompt'], FILTER_DEFAULT)))
    : '';
$conversation = (isset($inputData['conversation']) && is_array($inputData['conversation']))
    ? $inputData['conversation']
    : array();
$lowerPrompt = strtolower($prompt);
#endregion

#region 🔩 Environment & Session Setup
require_once __DIR__ . "/env_boot.php";
header("Content-Type: application/json");
date_default_timezone_set("America/Phoenix");
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$sessionId = session_id();
#endregion

#region 🔩 Dependencies / Helpers (with fallbacks)
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/reports/zoning.php";
require_once __DIR__ . "/reports/signOrdinance.php";
require_once __DIR__ . "/reports/photoSurvey.php";
require_once __DIR__ . "/reports/custom.php";
require_once __DIR__ . "/jurisdictionZoning.php";

// Fallback for missing functions
if (!function_exists('sendJsonResponse')) {
    function sendJsonResponse($response, $action = 'none', $extra = array()) {
        $payload = array(
            'response' => $response,
            'action' => $action,
            'sessionId' => session_id() ?: 'N/A'
        ) + $extra;
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('performLogout')) {
    function performLogout() {
        session_destroy();
    }
}
if (!function_exists('callOpenAi')) {
    function callOpenAi($messages, $apiKey = null) {
        // Stub: Replace with real OpenAI call (e.g., via curl to api.openai.com/v1/chat/completions)
        $apiKey = $apiKey ?: getenv('OPENAI_API_KEY');
        if (!$apiKey) return 'API key missing.';
        // Placeholder response for testing; implement full curl here
        return json_encode(array('choices' => array(array('message' => array('content' => 'Mock AI response.')))));
    }
}
if (!function_exists('googleSearch')) {
    function googleSearch($query) {
        // Stub: Implement real search (e.g., via Google Custom Search API)
        return array('error' => 'Search unavailable.', 'summary' => 'Mock search result for: ' . $query);
    }
}
if (!function_exists('getCodeFileSafe')) {
    function getCodeFileSafe($path) {
        $baseDir = realpath(__DIR__ . '/..');
        $target = realpath($baseDir . '/' . ltrim($path, '/'));
        if (!$target || strpos($target, $baseDir) !== 0 || !file_exists($target)) {
            return array('error' => true, 'message' => 'File access denied/not found.', 'content' => '', 'file' => basename($path), 'path' => $path, 'size' => 0);
        }
        $content = @file_get_contents($target);
        if ($content === false) {
            return array('error' => true, 'message' => 'Read failed.', 'content' => '', 'file' => basename($target), 'path' => $target, 'size' => filesize($target));
        }
        return array('error' => false, 'message' => 'Success.', 'content' => $content, 'file' => basename($target), 'path' => $target, 'size' => filesize($target));
    }
}
#endregion

#region 📂 Load Unified Context (DynamicData + Codex Integration)
// ===============================================================
// Purpose:
//  - Load live SSE data from getDynamicData.php
//  - Parse Codex (modules, glossary, constitution, etc.)
//  - Build $codexData, $allModules, $codexMeta, and $injectBlocks
//  - Provide snapshot context for AI reasoning
// ===============================================================

$dynamicUrl = 'https://www.skyelighting.com/skyesoft/api/getDynamicData.php';
$dynamicData = array();
$snapshotSummary = '{}';

// --- Fetch Dynamic Data (SSE Stream) ---
$ch = curl_init($dynamicUrl);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Skyebot/1.0 (+skyelighting.com)'
));
$dynamicRaw = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- Decode SSE Response ---
if ($dynamicRaw !== false && empty($err) && $httpCode === 200) {
    $decoded = json_decode($dynamicRaw, true);
    if (is_array($decoded)) $dynamicData = $decoded;
} else {
    error_log("⚠️ SSE fetch failed (HTTP $httpCode, err=$err)");
}

// --- Initialize Codex Data ---
$codexData = isset($dynamicData['codex']) && is_array($dynamicData['codex'])
    ? $dynamicData['codex']
    : array();

// --- Flatten All Modules (for semantic reference) ---
$allModules = array();
if (is_array($codexData)) {
    foreach ($codexData as $key => $value) {
        if (is_array($value)) $allModules[$key] = $value;
        if (isset($value['modules']) && is_array($value['modules'])) {
            foreach ($value['modules'] as $slug => $mod) {
                $allModules[$slug] = $mod;
            }
        }
    }
}
error_log("📚 Codex loaded: " . count($allModules) . " modules available.");

// --- Slim Snapshot for SSE Awareness ---
$snapshotSlim = isset($dynamicData['sseSnapshot'])
    ? json_encode($dynamicData['sseSnapshot'], JSON_UNESCAPED_SLASHES)
    : '{}';

// --- Codex Categories ---
$codexCategories = array(
    'modules'      => isset($dynamicData['codex']['modules']) ? array_keys($dynamicData['codex']['modules']) : array(),
    'constitution' => isset($dynamicData['codex']['constitution']) ? array_keys($dynamicData['codex']['constitution']) : array(),
    'glossary'     => isset($dynamicData['codex']['glossary']) ? array_keys($dynamicData['codex']['glossary']) : array()
);

// --- Inject Blocks for Router Context ---
$injectBlocks = array(
    'snapshot' => $snapshotSlim,
    'glossary' => $codexCategories['glossary'],
    'modules'  => $codexCategories['modules']
);

// --- Build Codex Meta (semantic index) ---
$codexMeta = array();
if (isset($codexData['modules']) && is_array($codexData['modules'])) {
    foreach ($codexData['modules'] as $slug => $mod) {
        $codexMeta[$slug] = array(
            'title'       => isset($mod['title']) ? $mod['title'] : $slug,
            'description' => isset($mod['description']) ? $mod['description'] : 'No summary available',
            'tags'        => isset($mod['tags']) ? $mod['tags'] : array(),
            'category'    => isset($mod['category']) ? $mod['category'] : ''
        );
    }
}
$injectBlocks['codexMeta'] = $codexMeta;

// --- Expand Matching Codex Sections (based on user prompt) ---
foreach ($codexCategories as $section => $keys) {
    foreach ($keys as $key) {
        if (strpos(strtolower($prompt), strtolower($key)) !== false) {
            if (isset($dynamicData['codex'][$section][$key])) {
                $injectBlocks[$section][$key] = $dynamicData['codex'][$section][$key];
            }
        }
    }
}

// --- Attach Report Types (optional) ---
if (stripos($prompt, 'report') !== false && isset($dynamicData['modules']['reportGenerationSuite'])) {
    $injectBlocks['reportTypes'] = array_keys(
        isset($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])
            ? $dynamicData['modules']['reportGenerationSuite']['reportTypesSpec']
            : array()
    );
}
#endregion

#region 🚛 SKYEBOT HEADERS & API KEY VALIDATION
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    sendJsonResponse("❌ API key not found.", "none", array("sessionId" => $sessionId));
    exit;
}
#endregion

#region 🧠 SKYEBOT SEMANTIC INTENT ROUTER (Primary Dispatch Layer)
$handled = false;
$allModules = array();
if (is_array($dynamicData) && isset($dynamicData['codex'])) {
    foreach ($dynamicData['codex'] as $k => $v) {
        if (is_array($v)) $allModules[$k] = $v;
        if (isset($v['modules'])) foreach ($v['modules'] as $m => $x) $allModules[$m] = $x;
    }
}
error_log("📚 Loaded Codex modules: " . count($allModules));

function findClosestModule($text, $modules) {
    $best = null; $bestScore = 0;
    $words = array_unique(explode(' ', strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text))));
    foreach ($modules as $slug => $m) {
        if (!isset($m['title'])) continue;
        $bag = strtolower(
            (isset($m['title']) ? $m['title'] . ' ' : '') .
            (isset($m['purpose']['text']) ? $m['purpose']['text'] . ' ' : (isset($m['purpose']) ? $m['purpose'] . ' ' : '')) .
            (isset($m['actions']) ? implode(' ', $m['actions']) . ' ' : '') .
            (isset($m['subtypes']) ? implode(' ', $m['subtypes']) . ' ' : '') .
            (isset($m['relationships']['aliases']) ? implode(' ', $m['relationships']['aliases']) : '')
        );
        $bagWords = array_unique(explode(' ', $bag));
        $overlap = count(array_intersect($words, $bagWords));
        $score = $overlap / (count($words) ? count($words) : 1);
        if ($score > $bestScore && $score > 0.15) {
            $bestScore = $score;
            $best = $slug;
        }
    }
    return $best;
}

$routerPrompt = <<<PROMPT
You are the Skyebot™ Semantic Intent Router.
Your role is to classify and route user intent based on meaning, not keywords.
Use Codex Semantic Index, SSE context, and conversation history.

Possible intents:
- "summary": user wants a textual explanation or overview (e.g., "show me", "describe", "what is").
- "report": user wants a document, sheet, or report produced (e.g., "generate", "create", "sheet for").
- "crud": user wants to create/update/delete/read an entity (e.g., "add permit", "update attendance").
- "general": anything else (conversational, non-actionable).
- "login": user wants to log in.
- "logout": user wants to log out.

If the user request involves making, creating, or preparing any sheet, report, or codex, classify it as intent = "report".
If the request involves creating, updating, or deleting an entity, classify it as intent = "crud".
Prefer "report" over "crud" when both could apply.

Return only JSON in this structure:
{
  "intent": "logout" | "login" | "report" | "crud" | "summary" | "general",
  "target": "codex-slug-or-entity",
  "confidence": 0.0–1.0
}

User message:
"$prompt"

Full Index (title | purpose | actions | subtypes | aliases):
PROMPT;
foreach ($allModules as $slug => $mod) {
    $purpose = isset($mod['purpose']['text']) ? $mod['purpose']['text'] : (isset($mod['purpose']) ? $mod['purpose'] : '');
    $actions = isset($mod['actions']) ? implode(', ', $mod['actions']) : '';
    $subtypes = isset($mod['subtypes']) ? implode(', ', $mod['subtypes']) : '';
    $aliases = isset($mod['relationships']['aliases']) ? implode(', ', $mod['relationships']['aliases']) : '';
    $routerPrompt .= " - $slug: " . (isset($mod['title']) ? $mod['title'] : $slug) . " | $purpose | Actions: $actions | Subtypes: $subtypes | Aliases: $aliases\n";
}
if (!empty($conversation)) {
    $recent = array_slice($conversation, -3);
    $routerPrompt .= "\nRecent chat context:\n" . json_encode($recent, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
$routerMessages = array(
    array("role" => "system", "content" => "You are Skyebot’s intent classifier. Respond only with JSON."),
    array("role" => "user", "content" => $routerPrompt)
);
$routerResponse = callOpenAi($routerMessages, $apiKey);
$intentData = json_decode(trim($routerResponse), true);
error_log("🧭 Router raw output: " . substr($routerResponse, 0, 400));

if (is_array($intentData) && isset($intentData['intent']) && $intentData['confidence'] >= 0.6) {
    $intent = strtolower(trim($intentData['intent']));
    $target = isset($intentData['target']) ? strtolower(trim($intentData['target'])) : null;
    $confidence = (float)$intentData['confidence'];

    // Semantic corrections (ontology-aware, no regex bias)
    if ($intent === 'crud' && preg_match('/\b(sheet|report|codex)\b/i', $prompt)) {
        error_log("🔄 Linguistic correction: CRUD → Report.");
        $intent = 'report';
    }
    if (in_array($intent, ['general', 'crud']) &&
        preg_match('/\b(make|create|build|prepare|produce|compile|generate)\b/i', $prompt) &&
        preg_match('/\b(sheet|report|codex|summary|document)\b/i', $prompt)) {
        error_log("🔁 Semantic override: {$intent} → report.");
        $intent = 'report';
    }
    if ($intent !== 'report' && preg_match('/\bsheet\b/i', $prompt) && isset($codexMeta)) {
        foreach ($codexMeta as $key => $meta) {
            if (!isset($meta['title'])) continue;
            $title = strtolower($meta['title']);
            $category = isset($meta['category']) ? strtolower($meta['category']) : '';
            if (strpos($title, 'document standards') !== false || strpos($category, 'system layer') !== false) {
                error_log("📄 Codex-aware override: matched Document Standards → report.");
                $intent = 'report';
                break;
            }
        }
    }
    if ($target && isset($codexMeta[$target])) {
        $meta = $codexMeta[$target];
        if (!empty($meta['dependsOn'])) {
            foreach ($meta['dependsOn'] as $dep) {
                if (isset($codexMeta[$dep])) $meta['resolvedDependencies'][$dep] = $codexMeta[$dep];
            }
        }
        if (!empty($meta['provides'])) $meta['resolvedProviders'] = $meta['provides'];
        $codexMeta[$target] = $meta;
    }
    // Log resolved intent
    error_log("🧭 Intent: $intent | Target: $target | Conf: $confidence | Prompt: $prompt");
    // 🧭 Auto-resolve Codex slug by title (LLM-hybrid, Codex-driven, regex-free)
    function resolveCodexSlugByTitle($prompt, $modules) {
        $p = strtolower($prompt);
        foreach ($modules as $slug => $meta) {
            if (!isset($meta['title'])) continue;
            $title = strtolower($meta['title']);
            $aliases = isset($meta['relationships']['aliases']) ? array_map('strtolower', $meta['relationships']['aliases']) : array();
            // Codex-aligned inference: matches on title, slug, or known aliases
            if (strpos($p, $title) !== false || strpos($p, strtolower($slug)) !== false) return $slug;
            foreach ($aliases as $alias) {
                if (strpos($p, $alias) !== false) return $slug;
            }
        }
        return null;
    }

    // 🔍 Hybrid inference: if router didn’t find a valid target, auto-map via Codex
    if (($intent === "report" || $intent === "summary") && (!$target || !isset($allModules[$target]))) {
        $autoSlug = resolveCodexSlugByTitle($prompt, $allModules);
        if ($autoSlug) {
            $target = $autoSlug;
            error_log("🔗 Auto-mapped Codex title → '$target'");
        }
    }
    
    // 🧭 Universal Codex Auto-Mapping Helper — Resolves $target from prompt using ontology
    function resolveCodexSlugByTitle($prompt, $allModules, $codexMeta = null) {
        if (empty($allModules)) {
            error_log("⚠️ No Codex modules loaded for mapping.");
            return null;
        }
        $promptLower = strtolower($prompt);
        $bestSlug = null;
        $bestScore = 0.0;

        foreach ($allModules as $slug => $meta) {
            if (!isset($meta['title'])) continue;
            $title = strtolower($meta['title']);
            $aliases = isset($meta['relationships']['aliases'])
                ? array_map('strtolower', (array)$meta['relationships']['aliases'])
                : array();
            $bag = array_merge(array($title), $aliases);
            foreach ($bag as $term) {
                if (strlen($term) < 4) continue; // Skip noise
                if (strpos($promptLower, $term) !== false) { // Exact substring boost
                    $percent = 100; // Instant high score for direct hits
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
            error_log("🔗 Codex-wide semantic match: '$prompt' → '$bestSlug' ($bestScore%)");
            return $bestSlug;
        } else {
            error_log("⚠️ No strong Codex match (max score $bestScore%).");
            return null;
        }
    }

    // 🧭 Universal Codex Auto-Mapping — Ensures valid $target before dispatch
    if (($intent === "report" || $intent === "summary") && (!$target || !isset($allModules[$target]))) {
        $target = resolveCodexSlugByTitle($prompt, $allModules, $codexMeta);
    }

    switch ($intent) {
        case "logout":
            performLogout();
            sendJsonResponse("👋 You have been logged out.", "Logout", array("status" => "success"));
            $handled = true;
            break;
        case "login":
            $_SESSION['user'] = $target ?: 'guest';
            sendJsonResponse("Welcome, {$_SESSION['user']}!", "Login", array("status" => "success", "user" => $_SESSION['user']));
            $handled = true;
            break;
        case "summary":
            if ($target && isset($allModules[$target])) {
                $m = $allModules[$target];
                $summary = array(
                    "title" => isset($m['title']) ? $m['title'] : $target,
                    "purpose" => isset($m['purpose']['text']) ? $m['purpose']['text'] : (isset($m['purpose']) ? $m['purpose'] : ""),
                    "features" => isset($m['features']['items']) ? $m['features']['items'] : array(),
                    "category" => isset($m['category']) ? $m['category'] : ""
                );
                sendJsonResponse("📘 " . $summary['title'] . " — " . $summary['purpose'], "summary", array("details" => $summary));
                $handled = true;
            }
            break;
        case "report":
            if (!$target) {
                foreach ($codexMeta as $key => $meta) {
                    $title = strtolower($meta['title']);
                    if (preg_match('/\b(' . preg_quote($title, '/') . ')\b/i', strtolower($prompt))) {
                        $target = $key;
                        error_log("🔗 Auto-mapped Codex title '{$meta['title']}' → slug '$key'");
                        break;
                    }
                }
            }
            if ($target || preg_match('/\b(sheet|report|codex|information\s+sheet|module)\b/i', $prompt)) {
                error_log("🧾 Routing to intent_report.php for target: " . ($target ?: 'none'));
                if (file_exists(__DIR__ . "/dispatchers/intent_report.php")) {
                    include __DIR__ . "/dispatchers/intent_report.php";
                } else {
                    $generatorUrl = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . urlencode($target ?: 'default');
                    $payload = json_encode(array("slug" => $target ?: 'default'));
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
                    if ($code === 200 && preg_match('/✅ PDF created successfully:\s*(.+)$/m', $result, $matches)) {
                        $link = trim($matches[1]);
                        sendJsonResponse("✅ Information Sheet generated for " . ($target ?: 'default'), "sheet_generated", array("link" => $link));
                    } else {
                        sendJsonResponse("⚠️ Report generation failed (HTTP $code).", "error", array("body" => substr($result, 0, 200)));
                    }
                }
                $handled = true;
            }
            break;
        case "crud":
            if ($target && preg_match('/\.(php|js|json|html|css)$/i', $target)) {
                $result = getCodeFileSafe($target);
                if ($result['error']) {
                    sendJsonResponse("❌ " . $result['message'], "error");
                } else {
                    $preview = substr($result['content'], 0, 2000);
                    if (strlen($result['content']) > 2000) $preview .= "\n\n⚠️ (Truncated for display)";
                    sendJsonResponse("📄 Here's the code for **" . basename($result['file']) . "**:\n\n```\n" . $preview . "\n```", "crud_read_code");
                }
                $handled = true;
            } elseif (file_exists(__DIR__ . "/dispatchers/intent_crud.php")) {
                include __DIR__ . "/dispatchers/intent_crud.php";
                $handled = true;
            }
            break;
    }
}
#endregion

#region 💬 SemanticResponder (AI Fallback)
if (!$handled) {
    $messages = array(
        array(
            "role" => "system",
            "content" => "You are Skyebot — respond conversationally using the current SSE stream and Codex data. " .
                         "Reference timeDateArray, workPhase, weatherData, KPIs, and announcements if relevant. " .
                         "Be concise, friendly, and contextually aware."
        ),
        array(
            "role" => "system",
            "content" => json_encode($dynamicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        )
    );
    if (!empty($conversation)) {
        $history = array_slice($conversation, -2);
        foreach ($history as $entry) {
            if (isset($entry['role']) && isset($entry['content'])) {
                $messages[] = array("role" => $entry['role'], "content" => $entry['content']);
            }
        }
    }
    $messages[] = array("role" => "user", "content" => $prompt);
    $aiResponse = callOpenAi($messages, $apiKey);
    if (!empty($aiResponse)) {
        sendJsonResponse(trim($aiResponse), "answer");
        $handled = true;
    }
}
#endregion

#region 🌐 Google Search Fallback
if (!$handled || (isset($aiResponse) && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") !== false)) {
    $searchResults = googleSearch($prompt);
    file_put_contents(__DIR__ . '/error.log', date('Y-m-d H:i:s') . " - Google Search called for prompt: $prompt\nResults keys: " . (is_array($searchResults) ? implode(', ', array_keys($searchResults)) : 'none') . "\n", FILE_APPEND);
    if (isset($searchResults['error'])) {
        sendJsonResponse("⚠️ Search service unavailable: " . $searchResults['error'] . ". Please try again.", "error");
    } elseif (!empty($searchResults['summary'])) {
        $payload = array("response" => $searchResults['summary'] . " (via Google Search)", "action" => "answer");
        if (isset($searchResults['raw'][0]['link'])) $payload['link'] = $searchResults['raw'][0]['link'];
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        sendJsonResponse("⚠️ No relevant search results found. Please rephrase your query.", "error");
    }
    $handled = true;
}
#endregion

#region ✅ Final Output
if (!$handled) {
    sendJsonResponse("❌ Unable to process request.", "error");
}
#endregion

#region 🔒 UNIVERSAL JSON OUTPUT SHIELD (GoDaddy Safe)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8', true);
    $clean = htmlspecialchars(strip_tags($errstr), ENT_QUOTES, 'UTF-8');
    $msg = "⚠️ PHP error [$errno]: $clean in $errfile on line $errline";
    echo json_encode(array("response" => $msg, "action" => "error", "sessionId" => $sessionId ?: 'N/A'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit(1);
}, E_ALL);
register_shutdown_function(function () {
    $lastError = error_get_last();
    $output = ob_get_clean();
    if ($lastError && ($lastError['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8', true);
        $clean = htmlspecialchars(strip_tags($lastError['message']), ENT_QUOTES, 'UTF-8');
        $msg = "❌ Fatal error: $clean in {$lastError['file']} on line {$lastError['line']}";
        echo json_encode(array("response" => $msg, "action" => "error", "sessionId" => $sessionId ?: 'N/A'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return;
    }
    if (!empty($output) && stripos(trim($output), '{') !== 0) {
        header('Content-Type: application/json; charset=UTF-8', true);
        $clean = substr(strip_tags($output), 0, 500);
        echo json_encode(array("response" => "❌ Internal error: " . $clean, "action" => "error", "sessionId" => $sessionId ?: 'N/A'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } elseif (!empty($output)) {
        header('Content-Type: application/json; charset=UTF-8', true);
        echo trim($output);
    }
});
#endregion