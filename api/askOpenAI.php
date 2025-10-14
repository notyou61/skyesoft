<?php
// 📄 File: api/askOpenAI_next.php
// Entry point for Skyebot AI interactions (PHP 5.6 compatible refactor v2.0)
// =======================================================

#region 🧾 SKYEBOT LOCAL LOGGING SETUP (FOR GODADDY PHP 5.6)

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Create a writable log file in /api/logs/
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/skyebot_debug.log';
ini_set('error_log', $logFile);

error_log("🧭 --- New Skyebot session started at " . date('Y-m-d H:i:s') . " ---");
#endregion

#region =========================================================
#  SKYEBOT UNIVERSAL INPUT LOADER  (CLI  +  WEB  compatible)
# =========================================================

// 1️⃣  Prefer web POST body
$rawInput = file_get_contents('php://input');

// 2️⃣  If nothing came in, fall back to CLI argument
if (PHP_SAPI === 'cli' && (empty($rawInput) || trim($rawInput) === '')) {
    if (isset($argv[1]) && trim($argv[1]) !== '') {
        $rawInput = $argv[1];
    }
}

// 3️⃣  Decode JSON
$rawInput  = trim($rawInput);
$inputData = json_decode($rawInput, true);

// 4️⃣  Fallback: try to fix common quoting mistakes
if (!is_array($inputData)) {
    // Remove surrounding quotes if Bash wrapped them
    $fixed = trim($rawInput, "\"'");
    $inputData = json_decode($fixed, true);
}

// 5️⃣  Guard clause
if (!is_array($inputData) || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(array(
        'response'  => '❌ Invalid or empty JSON payload.',
        'action'    => 'none',
        'sessionId' => uniqid()
    ));
    exit;
}

// 6️⃣  Extract prompt/conversation (PHP 5.6 safe)
$prompt = isset($inputData['prompt'])
    ? trim(strip_tags(filter_var($inputData['prompt'], FILTER_DEFAULT)))
    : '';
$conversation = (isset($inputData['conversation']) && is_array($inputData['conversation']))
    ? $inputData['conversation'] : array();
$lowerPrompt = strtolower($prompt);
#endregion

#region 🔒 UNIVERSAL JSON OUTPUT SHIELD (GoDaddy Safe)

// Disable HTML error output and start buffering immediately
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Clean buffer completely
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8', true);

    $clean = htmlspecialchars(strip_tags($errstr), ENT_QUOTES, 'UTF-8');
    $msg = "⚠️ PHP error [$errno]: $clean in $errfile on line $errline";
    $response = array(
        "response"  => $msg,
        "action"    => "error",
        "sessionId" => session_id() ?: 'N/A'
    );

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit(1);
}, E_ALL);

register_shutdown_function(function () {
    $lastError = error_get_last();
    $output = ob_get_clean();

    // Catch fatal errors that bypass the handler
    if ($lastError && ($lastError['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8', true);

        $clean = htmlspecialchars(strip_tags($lastError['message']), ENT_QUOTES, 'UTF-8');
        $msg = "❌ Fatal error: $clean in {$lastError['file']} on line {$lastError['line']}";
        $response = array(
            "response"  => $msg,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        );
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return;
    }

    // If normal output exists but isn’t JSON, wrap it safely
    if (!empty($output) && stripos(trim($output), '{') !== 0) {
        header('Content-Type: application/json; charset=UTF-8', true);
        $clean = substr(strip_tags($output), 0, 500);
        echo json_encode(array(
            "response"  => "❌ Internal error: " . $clean,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } elseif (!empty($output)) {
        header('Content-Type: application/json; charset=UTF-8', true);
        echo trim($output);
    }
});
#endregion

#region ===========================================================
# Require shared helpers *after* shield is active
# ===========================================================
require_once __DIR__ . "/helpers.php";
#endregion

#region 🔩 Report Generators (specific report logic)
require_once __DIR__ . "/reports/zoning.php";
require_once __DIR__ . "/reports/signOrdinance.php";
require_once __DIR__ . "/reports/photoSurvey.php";
require_once __DIR__ . "/reports/custom.php";
#endregion

#region 🔩 Shared Zoning Logic
require_once __DIR__ . "/jurisdictionZoning.php";
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

#region 📂 Load Unified Context (DynamicData)
$dynamicUrl = 'https://www.skyelighting.com/skyesoft/api/getDynamicData.php';
$dynamicData = array();
$snapshotSummary = '{}';

// Fetch JSON via curl
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

// Decode if successful
if ($dynamicRaw !== false && empty($err) && $httpCode === 200) {
    $decoded = json_decode($dynamicRaw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $dynamicData = $decoded;
    } else {
        file_put_contents(__DIR__ . '/error.log',
            date('Y-m-d H:i:s') . " - JSON decode error: " . json_last_error_msg() . "\nRaw: $dynamicRaw\n",
            FILE_APPEND
        );
    }
} else {
    file_put_contents(__DIR__ . '/error.log',
        date('Y-m-d H:i:s') . " - Failed to fetch $dynamicUrl (err=$err, code=$httpCode)\n",
        FILE_APPEND
    );
}

// Always build a snapshot, even if minimal
if (!empty($dynamicData)) {
    $snapshotSummary = json_encode($dynamicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    // Safe fallback if nothing loaded
    $snapshotSummary = json_encode(array(
        "timeDateArray" => array(
            "currentLocalTime" => date('h:i:s A'),
            "currentDate" => date('Y-m-d'),
            "timeZone" => date_default_timezone_get()
        ),
        "weatherData" => array(
            "description" => "Unavailable",
            "temp" => null
        ),
        "announcements" => array(),
        "kpiData" => array()
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
#endregion

#region ✅ Handle "generate [module] sheet" pattern (case-insensitive, PHP 5.6-safe)
// Retained for backward compatibility — now defers to AI semantic resolution
if (!empty($prompt) && preg_match('/generate (.+?) sheet/i', $lowerPrompt, $matches)) {
    $moduleName = strtolower(str_replace(' ', '', $matches[1]));
    $aiFallbackStarted = false; // safeguard tracker

    // Load codex safely
    $codexData = isset($dynamicData['codex'])
        ? $dynamicData['codex']
        : (file_exists(CODEX_PATH)
            ? json_decode(file_get_contents(CODEX_PATH), true)
            : array());

    // Normalize structure (accepts both wrapped + flat)
    $modules = (isset($codexData['modules']) && is_array($codexData['modules']))
        ? $codexData['modules']
        : $codexData;

    // Search Codex for direct key match first
    $foundKey = '';
    foreach ($modules as $key => $val) {
        if (strtolower($key) === $moduleName) {
            $foundKey = $key;
            break;
        }
    }

    // If found, return direct link
    if (!empty($foundKey)) {
        $title = isset($modules[$foundKey]['title']) ? $modules[$foundKey]['title'] : ucfirst($foundKey);
        $link  = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . $foundKey;

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array(
            "response"  => "📄 <strong>" . $title . "</strong> — <a href=\"" . $link . "\" target=\"_blank\">Generate Sheet</a>",
            "action"    => "sheet_generated",
            "slug"      => $foundKey,
            "reportUrl" => $link,
            "sessionId" => $sessionId
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Otherwise → direct Codex resolver (no AI dependency)
    if (!$aiFallbackStarted) {
        $aiFallbackStarted = true;
        error_log("⚙️ [Skyebot] Falling back to Codex resolver for prompt: $prompt");

        // Build slim Codex index
        $codexSlim = array();
        foreach ($codexData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['title'])) continue;
            $codexSlim[] = array(
                "slug" => $key,
                "title" => $entry['title'],
                "description" => isset($entry['description']) ? $entry['description'] : ''
            );
        }

        // ================================================
        // 🧭 Direct Codex Slug Resolution (no AI dependency)
        // ================================================
        $slug = null;
        $normalizedPrompt = preg_replace('/[^a-z0-9]/', '', strtolower($prompt));

        // Load full codex dataset (top-level + nested modules)
        $allModules = array_merge(
            $codexData,
            isset($codexData['modules']) && is_array($codexData['modules']) ? $codexData['modules'] : []
        );

        // Log visible keys
        error_log("🧭 Codex keys visible: " . implode(', ', array_keys($allModules)));

        // Try normalized substring match
        foreach ($allModules as $key => $module) {
            $normalizedKey = preg_replace('/[^a-z0-9]/', '', strtolower($key));
            if (strpos($normalizedPrompt, $normalizedKey) !== false) {
                $slug = $key;
                break;
            }
        }

        // Fuzzy fallback
        if (!$slug) {
            foreach ($allModules as $key => $module) {
                $normalizedKey = preg_replace('/[^a-z0-9]/', '', strtolower($key));
                if (levenshtein($normalizedPrompt, $normalizedKey) < 4) {
                    $slug = $key;
                    break;
                }
            }
        }

        // Log result or exit
        if ($slug) {
            error_log("✅ Codex slug resolved to '$slug' from prompt: " . $prompt);
        } else {
            error_log("⚠️ No matching Codex module found for prompt: " . $prompt);
            echo json_encode([
                "response"  => "⚠️ No matching Codex module found. Please rephrase your request.",
                "action"    => "none",
                "sessionId" => uniqid()
            ]);
            exit;
        }

        // Generate via internal API
        if ($slug && isset($allModules[$slug])) {
            $apiUrl  = "https://www.skyelighting.com/skyesoft/api/generateReports.php";
            $payload = json_encode(array("slug" => $slug));
            $context = stream_context_create(array(
                "http" => array(
                    "method"  => "POST",
                    "header"  => "Content-Type: application/json\r\n",
                    "content" => $payload,
                    "timeout" => 15
                )
            ));
            $result = @file_get_contents($apiUrl, false, $context);

            if ($result === false) {
                $error = error_get_last();
                $msg = isset($error['message']) ? $error['message'] : 'Unknown network error';
                $response = "❌ Network error contacting generateReports.php: $msg";
                $action = "error";
                $publicUrl = null;
            } else {
                $title = isset($allModules[$slug]['title'])
                    ? $allModules[$slug]['title']
                    : ucwords(str_replace(array('-', '_'), ' ', $slug));

                // 🛠 Sanitize title for safe file and URL use
                function sanitizeTitleForUrl($text) {
                    $clean = preg_replace('/[\x{1F000}-\x{1FFFF}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{200D}]/u', '', $text);
                    $clean = preg_replace('/[\x{00A0}\x{FEFF}\x{200B}-\x{200F}\x{202A}-\x{202F}\x{205F}-\x{206F}]+/u', '', $clean);
                    $clean = preg_replace('/\s+/', ' ', trim($clean));
                    $clean = preg_replace('/[^\P{C}]+/u', '', $clean);
                    $clean = preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9)]+$/', '', $clean);
                    return trim($clean);
                }

                // ✅ File name + URL
                $cleanTitle = sanitizeTitleForUrl($title);
                $fileName   = 'Information Sheet - ' . $cleanTitle . '.pdf';
                $pdfPath = '/home/notyou64/public_html/skyesoft/docs/sheets/' . $fileName;
                $publicUrl  = str_replace(
                    array('/home/notyou64/public_html', ' ', '(', ')'),
                    array('https://www.skyelighting.com', '%20', '%28', '%29'),
                    $pdfPath
                );

                $response = "📘 The **" . $title . "** sheet is ready.\n\n📄 [Open Report](" . $publicUrl . ")";
                $action = "sheet_generated";
            }

            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array(
                "response"  => $response,
                "action"    => $action,
                "slug"      => $slug,
                "reportUrl" => $publicUrl,
                "sessionId" => $sessionId
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}
#endregion

if (empty($prompt)) {
    sendJsonResponse("❌ Empty prompt.", "none", array("sessionId" => $sessionId));
    exit;
}

#region 🗜 Build Context Blocks (Semantic Router)
$snapshotSlim = array(
    "timeDateArray" => isset($dynamicData['timeDateArray']) ? $dynamicData['timeDateArray'] : array(),
    "weatherData"   => isset($dynamicData['weatherData'])
        ? array(
            "description" => isset($dynamicData['weatherData']['description']) ? $dynamicData['weatherData']['description'] : "Unavailable",
            "temp"        => isset($dynamicData['weatherData']['temp']) ? $dynamicData['weatherData']['temp'] : null
        ) : array(),
    "announcements" => isset($dynamicData['announcements'])
        ? array_column($dynamicData['announcements'], 'title')
        : array(),
    "kpiData"       => isset($dynamicData['kpiData']) ? $dynamicData['kpiData'] : array()
);

$codexCategories = array(
    "glossary"     => !empty($dynamicData['codex']['glossary']) ? array_keys($dynamicData['codex']['glossary']) : array(),
    "constitution" => !empty($dynamicData['codex']['constitution']) ? array_keys($dynamicData['codex']['constitution']) : array(),
    "modules"      => !empty($dynamicData['codex']['modules']) ? array_keys($dynamicData['codex']['modules']) : array()
);

// Always inject slim Codex sections (keys only) for broader context
$injectBlocks = array(
    "snapshot" => $snapshotSlim,
    "glossary" => isset($dynamicData['codex']['glossary']) ? array_keys($dynamicData['codex']['glossary']) : array(),
    "modules"  => isset($dynamicData['codex']['modules']) ? array_keys($dynamicData['codex']['modules']) : array(),
);

// 🧭 Flatten Codex for RAG (add metadata for AI reasoning)
$codexMeta = array();
if (isset($dynamicData['codex']['modules'])) {
    foreach ($dynamicData['codex']['modules'] as $key => $mod) {
        $codexMeta[$key] = array(
            'title' => isset($mod['title']) ? $mod['title'] : $key,
            'description' => isset($mod['description']) ? $mod['description'] : 'No summary available',
            'tags' => isset($mod['tags']) ? $mod['tags'] : array()
        );
    }
}
$injectBlocks['codexMeta'] = $codexMeta;

// Selectively expand specific Codex entries if keywords match
foreach ($codexCategories as $section => $keys) {
    foreach ($keys as $key) {
        if (strpos($lowerPrompt, strtolower($key)) !== false) {
            $injectBlocks[$section][$key] = $dynamicData['codex'][$section][$key];
        }
    }
}

if (stripos($prompt, 'report') !== false) {
    $injectBlocks['reportTypes'] = !empty($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])
        ? array_keys($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])
        : array();
}
#endregion

#region 🚛 Headers and Setup
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    sendJsonResponse("❌ API key not found.", "none", array("sessionId" => $sessionId));
    exit;
}
#endregion

#region 📝 System Prompt
$systemPrompt = <<<PROMPT
You are Skyebot™, an assistant for a signage company.

You always have access to a live JSON snapshot called sseSnapshot.
It contains current date, time, weather, KPIs, announcements, and workday intervals.
Never claim you lack real-time access — always ground answers in this snapshot.

⚠️ RULES:
- For time/date questions (e.g., "What time is it?", "What day is today?") → use timeDateArray.
- For weather questions (e.g., "What's it like outside?", "How hot is it?") → use weatherData.temp and weatherData.description.
- For forecast questions (e.g., "What's tomorrow like?") → use weatherData.forecast.
- For KPIs (e.g., "Orders?", "Any approvals?") → use kpiData.
- For announcements (e.g., "What's new?", "Any bulletins?") → use announcements.
- For workday/interval questions (e.g., "When do we finish?", "How long before quitting time?", "How many hours left in the shift?") → compare timeDateArray.currentLocalTime with intervalsArray.workdayIntervals.end, or use intervalsArray.currentDaySecondsRemaining. Calculate hours and minutes.
- For glossary questions (e.g., “What is LGBAS?”, “Define MTCO.”) → answer using codex.glossary entries. Always explain in plain sentences, not JSON.
- For Codex-related module questions (e.g., “Explain the Semantic Responder module,” “What is the Skyesoft Constitution?”) → provide a natural language explanation using Codex entries. Always explain in plain sentences, not JSON, unless JSON is explicitly requested.
- For CRUD and report creation → return JSON in the defined format.
- For logout → return JSON only: {"actionType":"Logout","status":"success"}.
- If uncertain or lacking information in sseSnapshot or Codex, respond with "NEEDS_GOOGLE_SEARCH" to trigger a search fallback.
- Otherwise → answer in plain text using Codex or general knowledge.
- Always respond naturally in plain text sentences.

🧭 SEMANTIC RESPONDER PRINCIPLE:
- Derive meaning from context, not from phrasing — interpret what the user intends, not how they say it.
- Resolve all intents through the Codex Semantic Index, matching titles, descriptions, or tags that best reflect the user’s request.
- Fuse SSE context (time, KPIs, weather, work intervals) and recent chat history to maintain temporal and situational awareness.
- Example: “Workday doc” or “when do we finish?” both map to ⏱️ Time Interval Standards (TIS) automatically.
- Prefer reasoning grounded in Codex and real-time data; avoid guessing or hallucination.
- If information is unavailable in sseSnapshot or Codex, respond with "NEEDS_GOOGLE_SEARCH" instead of "I don’t know".
PROMPT;

foreach ($injectBlocks as $section => $block) {
    $systemPrompt .= "\n\n📘 " . strtoupper($section) . ":\n";
    $systemPrompt .= json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
#endregion

#region 🛣 Dispatch Handler (Semantic Intent Router)
$handled = false;
$responsePayload = null;

#region Semantic Intent Router — replaces regex triggers entirely
$handled = false;
$responsePayload = null;

// 🧠 PRE-ROUTER SEMANTIC LAYER — early detection of report intent
// This runs BEFORE the routerPrompt so we can intercept common phrasing
if (
    preg_match('/\b(make|create|build|prepare|produce|compile|generate)\b/i', $prompt) &&
    preg_match('/\b(sheet|report|codex|summary|document)\b/i', $prompt)
) {
    error_log("⚡ Pre-router override: Detected report-style phrasing → forcing intent = 'report'.");
    $intentData = [
        "intent"     => "report",
        "target"     => "auto",
        "confidence" => 1.0
    ];
}

// ==========================
// Continue with Router Logic
// ==========================
$routerPrompt = <<<PROMPT
You are the Skyebot™ Semantic Intent Router.
Your role is to classify and route user intent based on meaning, not keywords.
Use Codex Semantic Index, SSE context, and conversation history.

If the user request involves making, creating, or preparing any sheet, report, or codex, classify it as intent = "report".
If the request involves creating, updating, or deleting an entity, classify it as intent = "crud".
Prefer "report" over "crud" when both could apply.

Return only JSON in this structure:
{
  "intent": "logout" | "login" | "report" | "crud" | "general",
  "target": "codex-slug-or-entity",
  "confidence": 0.0–1.0
}

User message:
"$prompt"

Codex Semantic Index:
PROMPT;
$routerPrompt .= json_encode($codexMeta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

if (!empty($conversation)) {
    $recent = array_slice($conversation, -3);
    $routerPrompt .= "\n\nRecent chat context:\n" . json_encode($recent, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

$routerMessages = array(
    array("role" => "system", "content" => "You are Skyebot’s intent classifier. Respond only with JSON."),
    array("role" => "user", "content" => $routerPrompt)
);

$routerResponse = callOpenAi($routerMessages);
$intentData = $intentData ?? json_decode(trim($routerResponse), true);
error_log("🧭 Router raw output: " . substr($routerResponse, 0, 400));

if (is_array($intentData) && isset($intentData['intent']) && $intentData['confidence'] >= 0.6) {
    $intent = strtolower(trim($intentData['intent']));
    $target = isset($intentData['target']) ? strtolower(trim($intentData['target'])) : null;

    // 🧠 SEMANTIC CORRECTION LAYER (linguistic override + expanded report bias)
    if ($intent === 'crud' && preg_match('/\b(sheet|report|codex)\b/i', $prompt)) {
        error_log("🔄 Linguistic correction: rerouting CRUD → Report (phrase matched 'sheet' or 'report').");
        $intent = 'report';
    }

    if (
        in_array($intent, ['general', 'crud']) &&
        preg_match('/\b(make|create|build|prepare|produce|compile|generate)\b/i', $prompt) &&
        preg_match('/\b(sheet|report|codex|summary|document)\b/i', $prompt)
    ) {
        error_log("🔁 Semantic override: rerouting {$intent} → report (creation verb + document noun).");
        $intent = 'report';
    }

    // Intent Switch
    switch ($intent) {
        case "logout":
            performLogout();
            echo json_encode([
                "actionType" => "Logout",
                "status"     => "success",
                "response"   => "👋 You have been logged out.",
                "sessionId"  => $sessionId
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;

        case "login":
            $_SESSION['user'] = $target ?: 'guest';
            echo json_encode([
                "actionType" => "Login",
                "status"     => "success",
                "user"       => $_SESSION['user'],
                "sessionId"  => $sessionId
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;

        case "report":
            if ($target && isset($dynamicData['codex']['modules'][$target])) {
                include __DIR__ . "/dispatchers/intent_report.php";
                exit;
            }
            break;

        case "crud":
            include __DIR__ . "/dispatchers/intent_crud.php";
            exit;

        default:
            break;
    }
}
#endregion

// 🔩 Codex Information Sheet Generator (Semantic Fallback)
// Triggered only if the router did not handle the prompt (ensures graceful continuity)
if (!$handled && !$intentData && preg_match('/\b(sheet|report|codex|module|summary)\b/i', $prompt)) {
    error_log("🧭 Semantic Fallback: Codex Information Sheet Generator triggered — prompt: " . $prompt);

    // 1️⃣ Load Codex (prefer live dynamicData snapshot, fallback to static file)
    $codexData = isset($dynamicData['codex'])
        ? $dynamicData['codex']
        : (file_exists(CODEX_PATH)
            ? json_decode(file_get_contents(CODEX_PATH), true)
            : array());

    // Normalize structure (works for both wrapped and flat schemas)
    $modules = (isset($codexData['modules']) && is_array($codexData['modules']))
        ? $codexData['modules']
        : $codexData;

    // 2️⃣ Build lightweight semantic index
    $codexSlim = array();
    foreach ($modules as $key => $entry) {
        if (!is_array($entry) || !isset($entry['title'])) continue;
        $codexSlim[] = array(
            "slug"        => $key,
            "title"       => $entry['title'],
            "description" => isset($entry['description']) ? $entry['description'] : ''
        );
    }

    // 3️⃣ Ask AI to semantically resolve user request → module slug
    $resolverPrompt = <<<PROMPT
atch the following user request to the most relevant Codex module.
Base your choice on meaning, not exact wording.

User request: "$prompt"

Available modules:
PROMPT;
    $resolverPrompt .= json_encode($codexSlim, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $resolverPrompt .= "\n\nRespond strictly as JSON: {\"slug\": \"best-match\" or null}";

    $messages = array(
        array("role" => "system", "content" => "You are a semantic resolver for Codex modules. Select the most relevant slug; use null if no confident match."),
        array("role" => "user", "content" => $resolverPrompt)
    );

    $aiSlugResponse = callOpenAi($messages);
    $slugResult = json_decode($aiSlugResponse, true);
    $slug = isset($slugResult['slug']) ? trim($slugResult['slug']) : null;

    // 4️⃣ Fallback: normalized key scanning if AI uncertain
    if (!$slug) {
        $normalizedPrompt = preg_replace('/[^a-z0-9]/', '', strtolower($prompt));
        foreach ($modules as $key => $module) {
            $normalizedKey = preg_replace('/[^a-z0-9]/', '', strtolower($key));
            if (strpos($normalizedPrompt, $normalizedKey) !== false || levenshtein($normalizedPrompt, $normalizedKey) < 4) {
                $slug = $key;
                break;
            }
        }
    }

    // 5️⃣ Final routing decision
    if (!$slug || !isset($modules[$slug])) {
        error_log("⚠️ No matching Codex module found for prompt: " . substr($prompt, 0, 100));
        sendJsonResponse("⚠️ No matching Codex module found. Please rephrase your request.", "none", array("sessionId" => $sessionId));
    }

    // 6️⃣ Generate report dynamically via internal API
    $apiUrl  = "https://www.skyelighting.com/skyesoft/api/generateReports.php";
    $payload = json_encode(array("slug" => $slug));
    $context = stream_context_create(array(
        "http" => array(
            "method"  => "POST",
            "header"  => "Content-Type: application/json\r\n",
            "content" => $payload,
            "timeout" => 15
        )
    ));
    $result = @file_get_contents($apiUrl, false, $context);

    if ($result === false) {
        $err = error_get_last();
        $msg = isset($err['message']) ? $err['message'] : 'Unknown network error';
        sendJsonResponse("❌ Network error contacting generateReports.php: $msg", "error", array("sessionId" => $sessionId));
    }

    // 7️⃣ Construct clean public URL + response
    $title = isset($modules[$slug]['title']) ? trim($modules[$slug]['title']) : ucwords(str_replace(['-', '_'], ' ', $slug));
    $cleanTitle = preg_replace('/[\p{So}\p{Cn}\p{Cs}\x{00A0}\x{FEFF}\x{200B}-\x{200D}\x{2000}-\x{206F}]+/u', '', $title);
    $cleanTitle = preg_replace('/[^A-Za-z0-9 _()\-]+/', '', trim($cleanTitle));
    $fileName = 'Information Sheet - ' . $cleanTitle . '.pdf';
    $publicUrl = 'https://www.skyelighting.com/skyesoft/docs/sheets/' . str_replace(' ', '%20', $fileName);

    error_log("📘 Generated Info Sheet (fallback): slug='$slug', url='$publicUrl'");

    $responsePayload = array(
        "response"  => "📘 The **" . $title . "** information sheet is ready.\n\n📄 [Open Report](" . $publicUrl . ")",
        "action"    => "sheet_generated",
        "slug"      => $slug,
        "reportUrl" => $publicUrl,
        "sessionId" => $sessionId,
        "preventCtaInjection" => true
    );

    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}


$reportTypesSpec = !empty($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])
    ? $dynamicData['modules']['reportGenerationSuite']['reportTypesSpec']
    : array();

// 2. 📑 Reports (run this BEFORE CRUD) — Preserved as fallback
if (!$handled) {
    $detectedReport = null;
    foreach ($reportTypesSpec as $reportName => $reportDef) {
        if (stripos($prompt, $reportName) !== false) {
            $detectedReport = $reportName;
            break;
        }
    }

    if ($detectedReport && isset($reportTypesSpec[$detectedReport])) {
        if (preg_match('/\b\d{1,5}\b/', $prompt) && preg_match('/\b\d{5}\b/', $prompt)) {
            $responsePayload = array(
                "actionType" => "Create",
                "actionName" => "Report",
                "reportType" => $detectedReport,
                "response"   => "ℹ️ Report definition for $detectedReport.",
                "spec"       => $reportTypesSpec[$detectedReport],
                "inputs"     => array("rawPrompt" => $prompt),
                "sessionId"  => $sessionId
            );
        } else {
            $responsePayload = array(
                "actionType" => "Create",
                "actionName" => "Report",
                "reportType" => $detectedReport,
                "response"   => "ℹ️ Codex information about requested report type.",
                "details"    => $reportTypesSpec[$detectedReport],
                "sessionId"  => $sessionId
            );
        }
        $handled = true;
    }
}

// 3. --- CRUD (only if no report was matched) --- Preserved as fallback
if (!$handled && preg_match('/\b(create|read|update|delete)\s+(?!a\b|the\b)([a-zA-Z0-9]+)/i', $lowerPrompt, $matches)) {
    $actionType = ucfirst(strtolower($matches[1]));
    $entity     = $matches[2];
    $details    = array("entity" => $entity, "prompt" => $prompt);

    if ($actionType === "Create") {
        $success = createCrudEntity($entity, $details);
        $responsePayload = array(
            "actionType" => "Create",
            "entity"     => $entity,
            "status"     => $success ? "success" : "failed",
            "sessionId"  => $sessionId
        );
    } elseif ($actionType === "Read") {
        $result = readCrudEntity($entity, $details);
        $responsePayload = array(
            "actionType" => "Read",
            "entity"     => $entity,
            "result"     => $result,
            "status"     => "success",
            "sessionId"  => $sessionId
        );
    } elseif ($actionType === "Update") {
        $success = updateCrudEntity($entity, $details);
        $responsePayload = array(
            "actionType" => "Update",
            "entity"     => $entity,
            "status"     => $success ? "success" : "failed",
            "sessionId"  => $sessionId
        );
    } elseif ($actionType === "Delete") {
        $success = deleteCrudEntity($entity, $details);
        $responsePayload = array(
            "actionType" => "Delete",
            "entity"     => $entity,
            "status"     => $success ? "success" : "failed",
            "sessionId"  => $sessionId
        );
    }

    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 🔐 Codex Acronym Resolver (Pre-normalization) — Preserved as fallback
if (!$handled && isset($dynamicData['codex']['modules'])) {
    $acronymMap = array();

    foreach ($dynamicData['codex']['modules'] as $key => $module) {
        if (isset($module['title']) && preg_match('/\(([A-Z0-9]+)\)/', $module['title'], $m)) {
            $acronymMap[$m[1]] = $key;
        }
    }

    // Normalize prompt for scanning
    $normalizedPrompt = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prompt));

    foreach ($acronymMap as $acro => $slug) {
        if (stripos($normalizedPrompt, $acro) !== false) {
            error_log("🔍 Acronym '$acro' matched Codex key '$slug' in prompt.");
            // Automatically rewrite the prompt to full title for matching
            $prompt = preg_replace('/\b' . preg_quote($acro, '/') . '\b/i', $slug, $prompt);
            break;
        }
    }
}

// 4. 🧭 SemanticResponder — Preserved as fallback
if (!$handled) {
    $messages = array(
        array(
            "role" => "system",
            "content" => "Here is the current Source of Truth snapshot (sseSnapshot + codex). Use this to answer semantically.\n\n" . $systemPrompt
        ),
        array(
            "role" => "system",
            "content" => json_encode($dynamicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        )
    );
    if (!empty($conversation)) {
        $history = array_slice($conversation, -2);
        foreach ($history as $entry) {
            if (isset($entry["role"]) && isset($entry["content"])) {
                $messages[] = array(
                    "role"    => $entry["role"],
                    "content" => $entry["content"]
                );
            }
        }
    }
    $messages[] = array("role" => "user", "content" => $prompt);

    $aiResponse = callOpenAi($messages);

    // 🔍 Safeguard: detect likely truncation
    if ($aiResponse && !preg_match('/[.!?…]$/', trim($aiResponse))) {
        error_log("⚠️ AI response may be truncated: " . substr($aiResponse, -50));
    }    

    if ($aiResponse && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") === false) {
    // Default response text
    $responseText = $aiResponse . " ‧";

        // Ensure codex is loaded
        if (!isset($codex) || !isset($codex['modules'])) {
            if (isset($dynamicData['codex'])) {
                $codex = $dynamicData['codex'];
            } else {
                $codex = array();
            }
        }

        $ctaAdded = false; // ✅ safeguard against multiple links

        // 🔩 Normalize AI response for safer matching
        $normalizedResponse = normalizeTitle($aiResponse);

        // **PATCH: Guard to skip CTA injection if payload is pre-tagged**
        if (isset($responsePayload["preventCtaInjection"]) && $responsePayload["preventCtaInjection"] === true) {
            $ctaAdded = true; // Prevent duplicate Open Report link
        }

        // Check Codex modules
        if (isset($codex['modules']) && is_array($codex['modules'])) {
            foreach ($codex['modules'] as $key => $moduleDef) {
                if ($ctaAdded) break;

                $rawTitle   = isset($moduleDef['title']) ? $moduleDef['title'] : '';
                $cleanTitle = normalizeTitle($rawTitle);

                // Extract acronym, e.g. (TIS)
                $acronym = null;
                if (preg_match('/\(([A-Z0-9]+)\)/', $rawTitle, $matches)) {
                    $acronym = $matches[1];
                }

                error_log("📐 Checking module: rawTitle='$rawTitle', cleanTitle='$cleanTitle', acronym='$acronym', key='$key'");
                error_log("📐 AI Response snippet: " . substr($normalizedResponse, 0, 200));

                if (
                    (!empty($rawTitle)   && stripos($normalizedResponse, $rawTitle)   !== false) ||
                    (!empty($cleanTitle) && stripos($normalizedResponse, $cleanTitle) !== false) ||
                    (!empty($acronym)    && stripos($normalizedResponse, $acronym)    !== false) ||
                    stripos($normalizedResponse, $key) !== false
                ) {
                    error_log("✅ CTA Match: Module '$rawTitle' (key: $key) matched in AI response.");
                    $responseText .= getTrooperLink($key);
                    $ctaAdded = true;
                    break;
                }
            }
        }

        // Check Information Sheets
        if (!$ctaAdded && isset($codex['informationSheetSuite']['types']) && is_array($codex['informationSheetSuite']['types'])) {
            foreach ($codex['informationSheetSuite']['types'] as $sheetKey => $sheetDef) {
                $sheetPurpose = isset($sheetDef['purpose']) ? normalizeTitle($sheetDef['purpose']) : '';
                $sheetTitle   = isset($sheetDef['title']) ? normalizeTitle($sheetDef['title']) : '';

                error_log("📐 Checking information sheet: sheetKey='$sheetKey', sheetTitle='$sheetTitle', sheetPurpose='$sheetPurpose'");

                if (
                    (!empty($sheetPurpose) && stripos($normalizedResponse, $sheetPurpose) !== false) ||
                    (!empty($sheetTitle)   && stripos($normalizedResponse, $sheetTitle)   !== false) ||
                    stripos($normalizedResponse, $sheetKey) !== false
                ) {
                    error_log("✅ CTA Match: Information Sheet '$sheetKey' matched in AI response.");
                    $responseText .= getTrooperLink($sheetKey);
                    $ctaAdded = true;
                    break;
                }
            }
        }

        // Check Glossary terms
        if (!$ctaAdded && isset($codex['glossary']) && is_array($codex['glossary'])) {
            foreach ($codex['glossary'] as $key => $entry) {
                if ($ctaAdded) break;

                $cleanKey = normalizeTitle($key);

                error_log("📐 Checking glossary: key='$key', cleanKey='$cleanKey'");

                if (
                    stripos($normalizedResponse, $key) !== false ||
                    stripos($normalizedResponse, $cleanKey) !== false
                ) {
                    error_log("✅ CTA Match: Glossary term '$key' matched in AI response.");
                    $responseText .= getTrooperLink($key);
                    $ctaAdded = true;
                    break;
                }
            }
        }

        // (Optional) Check Included Documents list
        if (!$ctaAdded && isset($codex['includedDocuments']['documents']) && is_array($codex['includedDocuments']['documents'])) {
            foreach ($codex['includedDocuments']['documents'] as $doc) {
                if ($ctaAdded) break;

                $cleanDoc = normalizeTitle($doc);

                error_log("📐 Checking included document: doc='$doc', cleanDoc='$cleanDoc'");

                if (
                    stripos($normalizedResponse, $doc) !== false ||
                    stripos($normalizedResponse, $cleanDoc) !== false
                ) {
                    error_log("✅ CTA Match: Included document '$doc' matched in AI response.");
                    $responseText .= getTrooperLink($doc);
                    $ctaAdded = true;
                    break;
                }
            }
        }

        // Prepare final payload
        $responsePayload = array(
            "response"  => $responseText,
            "action"    => "answer",
            "sessionId" => $sessionId
        );
        $handled = true;
    }
}

// 5. 🌐 Google Search Fallback — Preserved as fallback
if (!$handled || (isset($aiResponse) && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") !== false)) {
    $searchResults = googleSearch($prompt);

    // Log for debugging
    file_put_contents(__DIR__ . '/error.log',
        date('Y-m-d H:i:s') . " - Google Search called for prompt: $prompt\n" .
        "Results keys: " . implode(', ', array_keys($searchResults)) . "\n",
        FILE_APPEND
    );

    if (isset($searchResults['error'])) {
        // Handle API errors (e.g., keys not set, curl failure)
        $responsePayload = array(
            "response" => "⚠️ Search service unavailable: " . $searchResults['error'] . ". Please try again.",
            "action" => "error",
            "sessionId" => $sessionId
        );
    } elseif (!empty($searchResults['summary'])) {
        // Use the summary (handles both single and AI-summarized cases)
        $responsePayload = array(
            "response" => $searchResults['summary'] . " (via Google Search)",
            "action" => "answer",
            "sessionId" => $sessionId
        );
        // Optionally add raw snippets or link if available (e.g., from first raw item)
        if (isset($searchResults['raw'][0]) && is_array($searchResults['raw'][0])) {
            // If raw contains full items (adjust if your function returns links)
            $firstLink = isset($searchResults['raw'][0]['link']) ? $searchResults['raw'][0]['link'] : null;
            if ($firstLink) {
                $responsePayload['link'] = $firstLink;
            }
        }
    } else {
        // No useful results
        $responsePayload = array(
            "response" => "⚠️ No relevant search results found. Please try rephrasing your query.",
            "action" => "error",
            "sessionId" => $sessionId
        );
    }
}

// 6. ✅ Final Output
if ($responsePayload) {
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    sendJsonResponse("❌ Unable to process request.", "error", array("sessionId" => $sessionId));
}
#endregion