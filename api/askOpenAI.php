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

#region 🧠 SKYEBOT UNIVERSAL INPUT LOADER (CLI + WEB Compatible)
// ================================================================
// Purpose:
//  • Accepts input via HTTP POST (web) or CLI arguments (local testing)
//  • Repairs malformed JSON payloads and prevents null crashes
//  • Normalizes `$prompt`, `$conversation`, and `$lowerPrompt` variables
//  • Fully compatible with PHP 5.6+
// ================================================================

// 1️⃣ Read input from HTTP POST body
$rawInput = @file_get_contents('php://input');

// 2️⃣ Fallback: use CLI input when running locally (e.g.)
//     php askOpenAI.php '{"prompt":"Hello Skyebot"}'
if (PHP_SAPI === 'cli' && (empty($rawInput) || trim($rawInput) === '')) {
    global $argv;
    if (isset($argv[1]) && trim($argv[1]) !== '') {
        $rawInput = $argv[1];
    }
}

// 3️⃣ Trim and decode input
$rawInput  = trim($rawInput);
$inputData = json_decode($rawInput, true);

// 4️⃣ Repair common JSON wrapping issues (e.g., extra quotes)
if (!is_array($inputData)) {
    $fixed = trim($rawInput, "\"'");
    $inputData = json_decode($fixed, true);
}

// 5️⃣ Guard clause: invalid or empty JSON
if (!is_array($inputData) || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(array(
        'response'  => '❌ Invalid or empty JSON payload.',
        'action'    => 'none',
        'sessionId' => uniqid('sess_')
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 6️⃣ Extract primary prompt and conversation context
$prompt = isset($inputData['prompt'])
    ? trim(strip_tags(filter_var($inputData['prompt'], FILTER_DEFAULT)))
    : '';

$conversation = (isset($inputData['conversation']) && is_array($inputData['conversation']))
    ? $inputData['conversation']
    : array();

// 7️⃣ Prepare lowercase version for NLP keyword matching
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

#region 🔒 SKYEBOT UNIVERSAL JSON OUTPUT SHIELD (GoDaddy Safe)
// ======================================================================
// Purpose:
//   • Catches all PHP runtime and fatal errors, converting them to JSON
//   • Prevents raw HTML or notices from leaking to the frontend
//   • Ensures consistent JSON output for Skyebot API responses
//   • Fully compatible with PHP 5.6 (GoDaddy hosting environment)
// ======================================================================

// Disable HTML error output and start safe buffering
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

// --------------------------------------------------
// 🧩 Error Handler — converts PHP warnings/notices to JSON
// --------------------------------------------------
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8', true);

    $clean = htmlspecialchars(strip_tags($errstr), ENT_QUOTES, 'UTF-8');
    $msg = "⚠️ PHP error [$errno]: $clean in $errfile on line $errline";

    echo json_encode(array(
        "response"  => $msg,
        "action"    => "error",
        "sessionId" => session_id() ?: 'N/A'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit(1);
}, E_ALL);

// --------------------------------------------------
// 🧱 Shutdown Handler — catches fatal / parse errors
// --------------------------------------------------
register_shutdown_function(function () {
    $lastError = error_get_last();
    $output = ob_get_clean();

    // 🚨 Catch fatal errors that bypass the handler
    if ($lastError && ($lastError['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8', true);

        $clean = htmlspecialchars(strip_tags($lastError['message']), ENT_QUOTES, 'UTF-8');
        $msg = "❌ Fatal error: $clean in {$lastError['file']} on line {$lastError['line']}";

        echo json_encode(array(
            "response"  => $msg,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return;
    }

    // ✅ Wrap non-JSON output safely
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

#region 🧩 SKYEBOT HELPER SAFEGUARDS (Loaded After Shield)
// ======================================================================
// Purpose:
//   • Ensures required helpers are loaded after the error shield
//   • Provides a fallback implementation of getCodeFileSafe()
//     for secure code-reading by Skyebot when helpers.php is missing
// ======================================================================

// Load shared helper functions (safe inclusion)
require_once __DIR__ . "/helpers.php";

// --------------------------------------------------
// 🧰 Safe Fallback: getCodeFileSafe()
// --------------------------------------------------
if (!function_exists('getCodeFileSafe')) {

    /**
     * Safely reads a file from within the Skyesoft project directory.
     * Prevents directory traversal and returns structured metadata.
     *
     * @param string $path  Relative or absolute path to target file
     * @return array {
     *   @type bool   $error   True if file could not be read
     *   @type string $message Description or error
     *   @type string $content File contents (if available)
     *   @type string $file    File name only
     *   @type string $path    Absolute path (sanitized)
     *   @type int    $size    File size in bytes
     * }
     */
    function getCodeFileSafe($path)
    {
        $baseDir = realpath(__DIR__ . '/..');      // project root
        $target  = realpath($baseDir . '/' . ltrim($path, '/'));

        // 🚫 Directory traversal protection
        if (!$target || strpos($target, $baseDir) !== 0) {
            return array(
                'error'   => true,
                'message' => 'Unauthorized file access attempt blocked.',
                'content' => '',
                'file'    => basename($path),
                'path'    => $path,
                'size'    => 0
            );
        }

        // 🚫 File missing
        if (!file_exists($target)) {
            return array(
                'error'   => true,
                'message' => 'File not found: ' . basename($path),
                'content' => '',
                'file'    => basename($path),
                'path'    => $target,
                'size'    => 0
            );
        }

        // ✅ Read file safely
        $content = @file_get_contents($target);
        if ($content === false) {
            return array(
                'error'   => true,
                'message' => 'Unable to read file: ' . basename($path),
                'content' => '',
                'file'    => basename($path),
                'path'    => $target,
                'size'    => filesize($target)
            );
        }

        return array(
            'error'   => false,
            'message' => 'File read successfully.',
            'content' => $content,
            'file'    => basename($target),
            'path'    => $target,
            'size'    => filesize($target)
        );
    }
}
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

        // 🔧 Immediately trigger report generation server-side
        @file_get_contents($link);

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

#region 🚧 SKYEBOT INPUT VALIDATION & GUARD CLAUSE
// ================================================================
// Purpose:
//   • Ensures a valid prompt was received before continuing
//   • Prevents downstream errors in semantic or Codex parsing
//   • Returns a structured JSON response for consistent UX
// ================================================================

if (empty($prompt)) {
    sendJsonResponse(
        "❌ Empty prompt received. Please enter a valid request.",
        "none",
        array("sessionId" => $sessionId)
    );
    exit;
}

#endregion

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

#region 🚛 SKYEBOT HEADERS & API KEY VALIDATION
// ================================================================
// Purpose:
//   • Load and validate OpenAI API key
//   • Prevent unauthorized execution if key missing
// ================================================================
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    sendJsonResponse("❌ API key not found.", "none", array("sessionId" => $sessionId));
    exit;
}
#endregion

#region 🧠 SKYEBOT SEMANTIC INTENT ROUTER (Primary Dispatch Layer)
// ======================================================================
// Purpose:
//   • Classifies and routes user input using semantic AI understanding
//   • Replaces legacy regex-based triggers with contextual reasoning
//   • Routes to report, CRUD, login/logout, or general AI response
//   • Includes fallback layers for semantic continuity
// ======================================================================

$handled = false;
$responsePayload = null;


#region 🧩 Build Router Prompt
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
#endregion

#region 🚀 Execute Router Classification
$routerMessages = array(
    array("role" => "system", "content" => "You are Skyebot’s intent classifier. Respond only with JSON."),
    array("role" => "user", "content" => $routerPrompt)
);

$routerResponse = callOpenAi($routerMessages);
$intentData = json_decode(trim($routerResponse), true);
error_log("🧭 Router raw output: " . substr($routerResponse, 0, 400));
#endregion

#region 🧭 Intent Resolution Layer
if (is_array($intentData) && isset($intentData['intent']) && $intentData['confidence'] >= 0.6) {
    $intent = strtolower(trim($intentData['intent']));
    $target = isset($intentData['target']) ? strtolower(trim($intentData['target'])) : null;

    
    // #region 🧠 SEMANTIC CORRECTION & REPORT BIAS
    // Linguistic correction: reroute CRUD → Report when text includes "sheet" or "report"
    if ($intent === 'crud' && preg_match('/\b(sheet|report|codex)\b/i', $prompt)) {
        error_log("🔄 Linguistic correction: CRUD → Report.");
        $intent = 'report';
    }

    // Detect creation verbs combined with document-type nouns
    if (
        in_array($intent, ['general', 'crud']) &&
        preg_match('/\b(make|create|build|prepare|produce|compile|generate)\b/i', $prompt) &&
        preg_match('/\b(sheet|report|codex|summary|document)\b/i', $prompt)
    ) {
        error_log("🔁 Semantic override: {$intent} → report (creation verb + document noun).");
        $intent = 'report';
    }

    // Recognize “sheet” in Document Standards context
    if ($intent !== 'report' && preg_match('/\bsheet\b/i', $prompt) && isset($codexMeta)) {
        foreach ($codexMeta as $key => $meta) {
            if (!isset($meta['title'])) continue;
            $title = strtolower($meta['title']);
            $category = isset($meta['category']) ? strtolower($meta['category']) : '';
            if (strpos($title, 'document standards') !== false || strpos($category, 'system layer') !== false) {
                error_log("📄 Codex-aware override: matched Document Standards context → report.");
                $intent = 'report';
                break;
            }
        }
    }
    // #endregion

    #region 🔗 Codex Relationship Resolver (Ontology Integration)
    if ($target && isset($codexMeta[$target])) {
        $meta = $codexMeta[$target];

        // Dependencies
        if (!empty($meta['dependsOn'])) {
            error_log("🔗 $target depends on: " . implode(', ', $meta['dependsOn']));
            foreach ($meta['dependsOn'] as $dep) {
                if (isset($codexMeta[$dep])) {
                    $meta['resolvedDependencies'][$dep] = $codexMeta[$dep];
                }
            }
        }

        // Providers
        if (!empty($meta['provides'])) {
            error_log("📡 $target provides: " . implode(', ', $meta['provides']));
            $meta['resolvedProviders'] = $meta['provides'];
        }

        // Aliases
        if (!empty($meta['aliases'])) {
            $aliases = implode('|', array_map('preg_quote', $meta['aliases']));
            if (preg_match("/\b($aliases)\b/i", $prompt)) {
                error_log("🪞 Alias matched for $target.");
                $intent = 'report';
            }
        }

        $codexMeta[$target] = $meta;
    }
    #endregion

    #region ⚙️ Intent Switch Router (Core Dispatch)
    // Switch based on resolved intent
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
            // Auto-map Codex modules when title appears in prompt
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
            if ($target && isset($dynamicData['codex']['modules'][$target])) {
                include __DIR__ . "/dispatchers/intent_report.php";
                exit;
            }
            break;

        case "crud":
            if (isset($target) && preg_match('/\.(php|js|json|html|css)$/i', $target)) {
                $result = getCodeFileSafe($target);
                if ($result['error']) {
                    sendJsonResponse("❌ " . $result['message'], "error", array(
                        "sessionId" => $sessionId,
                        "requestedFile" => $target
                    ));
                    exit;
                }
                if (function_exists('logMessage')) {
                    logMessage("👁️ CRUD: Viewed code file '{$target}' (Session: {$sessionId})");
                }
                $preview = substr($result['content'], 0, 2000);
                if (strlen($result['content']) > 2000) {
                    $preview .= "\n\n⚠️ (File truncated for display — full content logged.)";
                }
                sendJsonResponse(
                    "📄 Here's the code for **" . basename($result['file']) . "**:\n\n```\n" . $preview . "\n```",
                    "crud_read_code",
                    array(
                        "sessionId" => $sessionId,
                        "path" => $result['path'],
                        "fileSize" => $result['size']
                    )
                );
                exit;
            }
            include __DIR__ . "/dispatchers/intent_crud.php";
            exit;

        default:
            break; // fallthrough → SemanticResponder
    }
    #endregion
}
#endregion

#region 🧩 SKYEBOT SEMANTIC FALLBACK SUITE
// ======================================================================
// Purpose:
//   • Provides continuity when intent router cannot resolve input
//   • Executes layered fallbacks (report → CRUD → acronym → AI → search)
// ======================================================================

#region 📘 Report Generator (AI + Codex Fallback)
if (!$handled && !$intentData && preg_match('/\b(sheet|report|codex|module|summary)\b/i', $prompt)) {
    include __DIR__ . "/dispatchers/fallback_report.php";
    exit;
}
#endregion

#region 🧾 CRUD Operations (Regex Fallback)
if (!$handled && preg_match('/\b(create|read|update|delete)\s+(?!a\b|the\b)([a-zA-Z0-9]+)/i', $lowerPrompt, $matches)) {
    include __DIR__ . "/dispatchers/fallback_crud.php";
    exit;
}
#endregion

#region 🧩 Codex Acronym Resolver (Normalization Fallback)
if (!$handled && isset($dynamicData['codex']['modules'])) {
    //include __DIR__ . "/dispatchers/fallback_acronym.php";
}
#endregion

#region 💬 SemanticResponder (AI Fallback)
if (!$handled) {
    include __DIR__ . "/dispatchers/fallback_semantic.php";
}
#endregion

#region 🌐 Google Search Fallback
if (!$handled || (isset($aiResponse) && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") !== false)) {
    include __DIR__ . "/dispatchers/fallback_search.php";
}
#endregion

#region ✅ Final Output (Guaranteed JSON)
if ($responsePayload) {
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    sendJsonResponse("❌ Unable to process request.", "error", array("sessionId" => $sessionId));
}
#endregion

#endregion