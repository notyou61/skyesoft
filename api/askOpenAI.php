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

#region ✅ Handle "generate [module] sheet" pattern (PHP 5.6-safe)
$lowerPrompt = strtolower($prompt);

// More robust capture: "generate (an)? (information)? sheet (for)? <name>"
if (!empty($prompt) && preg_match(
    '/\bgenerate\b\s*(?:an?\s+)?(?:information\s+)?sheet(?:\s+for)?\s+(.+?)(?:\s*(?:sheet|report))?(?:[.!?]|\s*$)/i',
    $prompt,
    $matches
)) {
    // Normalize candidate module name
    $moduleNameRaw = trim($matches[1]);
    $moduleName    = strtolower(preg_replace('/[^a-z0-9_-]/i', '', str_replace(' ', '', $moduleNameRaw)));
    $aiFallbackStarted = false; // safeguard tracker

    // Load codex safely
    $codexData = isset($dynamicData['codex'])
        ? $dynamicData['codex']
        : (file_exists(CODEX_PATH)
            ? json_decode(file_get_contents(CODEX_PATH), true)
            : array());

    // Build full module index (accept both wrapped + flat)
    $allModules = array();
    if (is_array($codexData)) {
        foreach ($codexData as $k => $v) { $allModules[$k] = $v; }
        if (isset($codexData['modules']) && is_array($codexData['modules'])) {
            foreach ($codexData['modules'] as $k => $v) { $allModules[$k] = $v; }
        }
        if (isset($codexData['codexModules']) && is_array($codexData['codexModules'])) {
            foreach ($codexData['codexModules'] as $k => $v) { $allModules[$k] = $v; }
        }
    }

    // --- Resolve slug from moduleName + prompt
    $slug = '';
    $normalizedPrompt = preg_replace('/[^a-z0-9]/', '', strtolower($prompt));
    error_log("🧭 Codex keys visible: " . implode(', ', array_keys($allModules)));

    // 1) Exact normalized key match
    foreach ($allModules as $key => $_) {
        $nk = preg_replace('/[^a-z0-9]/', '', strtolower($key));
        if ($nk === $moduleName) { $slug = $key; break; }
    }

    // 2) Substring match in prompt
    if ($slug === '') {
        foreach ($allModules as $key => $_) {
            $nk = preg_replace('/[^a-z0-9]/', '', strtolower($key));
            if ($nk && strpos($normalizedPrompt, $nk) !== false) { $slug = $key; break; }
        }
    }

    // 3) Levenshtein fallback
    if ($slug === '') {
        $bestKey = ''; $best = 999;
        foreach ($allModules as $key => $_) {
            $nk = preg_replace('/[^a-z0-9]/', '', strtolower($key));
            if ($nk === '') continue;
            $d = levenshtein($moduleName, $nk);
            if ($d < $best) { $best = $d; $bestKey = $key; }
        }
        if ($best <= 3) { $slug = $bestKey; }
    }

    if ($slug === '') {
        error_log("⚠️ No matching Codex module found for prompt: " . $prompt);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array(
            "response"  => "⚠️ No matching Codex module found for “{$moduleNameRaw}”. Try a clearer module name.",
            "action"    => "none",
            "sessionId" => $sessionId
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    error_log("✅ Codex slug resolved to '$slug' from prompt: " . $prompt);

    // =====================================================
    // 🧾 Generate via internal API POST (not include)
    // =====================================================
    if (isset($allModules[$slug])) {
        $generatorUrl = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . urlencode($slug);
        $payload = json_encode(array("slug" => $slug));

        $ch = curl_init($generatorUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Skyebot/1.0');
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        error_log("🧾 Report POST -> code=$httpCode err=" . ($curlErr ?: 'none') . " body=" . substr((string)$result, 0, 200));

        // Determine title
        $title = isset($allModules[$slug]['title'])
            ? $allModules[$slug]['title']
            : ucwords(str_replace(array('-', '_'), ' ', $slug));

        // Success heuristic: generator echoes "✅ PDF created successfully: /path..."
        $ok = ($httpCode === 200) && (strpos((string)$result, '✅ PDF created successfully') !== false);

        if ($ok) {
            // Expected public URL
            // Remove emojis and invisible unicode (PHP 5.6-safe)
            $cleanTitle = preg_replace(
                '/[\x{1F000}-\x{1FFFF}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{200D}]/u',
                '',
                $title
            );
            $cleanTitle = preg_replace('/[^\w\s-]/u', '', trim($cleanTitle));
            $cleanTitle = preg_replace('/\s+/', ' ', $cleanTitle); // collapse double spaces
            // Finalize filename
            $fileName   = 'Information Sheet - ' . $cleanTitle . '.pdf';
            // Determine actual generator directory
            $possiblePaths = array(
                '/home/notyou64/public_html/skyesoft/docs/sheets/' . $fileName,
                '/home/notyou64/public_html/skyesoft/api/../docs/sheets/' . $fileName
            );
            // Locate existing file
            $pdfPath = '';
            foreach ($possiblePaths as $p) {
                if (file_exists($p)) { $pdfPath = realpath($p); break; }
            }
            if ($pdfPath === '') { $pdfPath = $possiblePaths[0]; } // fallback
            // Build public URL
            $publicUrl = str_replace(
                array('/home/notyou64/public_html', ' '),
                array('https://www.skyelighting.com', '%20'),
                $pdfPath
            );

            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array(
                "response"  => "📘 The **{$title}** sheet is ready.\n\n📄 [Open Report]({$publicUrl})",
                "action"    => "sheet_generated",
                "slug"      => $slug,
                "reportUrl" => $publicUrl,
                "sessionId" => $sessionId
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        } else {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array(
                "response"  => "⚠️ Report generation failed (HTTP {$httpCode}). " . ($curlErr ? "cURL: {$curlErr}" : "Response: " . substr((string)$result, 0, 160)),
                "action"    => "error",
                "slug"      => $slug,
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
    $routerPrompt .= "\n\nRecent chat context:\n" .
                     json_encode($recent, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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

#region 🧭 Intent Resolution Layer (Semantic-Driven, Regex-Free)
if (is_array($intentData) && isset($intentData['intent'])) {
    $intent      = strtolower(trim($intentData['intent']));
    $target      = isset($intentData['target']) ? strtolower(trim($intentData['target'])) : null;
    $confidence  = isset($intentData['confidence']) ? (float)$intentData['confidence'] : 0.0;
    $minConfidence = 0.6;

    // 🧠 Step 1: Semantic Target Resolution (SSE + Codex)
    if (in_array($intent, array('report', 'summary')) && (!$target || !isset($codexMeta[$target]))) {
        $resolved = resolveSkyesoftObject($prompt, $dynamicData);
        if (is_array($resolved) && $resolved['confidence'] > 30) {
            $target = $resolved['key'];
            error_log("🔗 Semantic resolver matched {$resolved['layer']} → {$target} ({$resolved['confidence']}%)");
        }
    }

    // 🧩 Step 2: Relationship Context Expansion
    if ($target && isset($codexMeta[$target])) {
        $meta = $codexMeta[$target];
        if (!empty($meta['dependsOn'])) {
            foreach ($meta['dependsOn'] as $dep) {
                if (isset($codexMeta[$dep])) {
                    $meta['resolvedDependencies'][$dep] = $codexMeta[$dep];
                }
            }
        }
        if (!empty($meta['provides'])) {
            $meta['resolvedProviders'] = $meta['provides'];
        }
        $codexMeta[$target] = $meta;
    }

    // 🧩 Step 3: Log final routing decision
    error_log("🧭 Intent: {$intent} | Target: {$target} | Confidence: {$confidence} | Prompt: {$prompt}");

    // 🧾 Step 4: Dispatch by Intent
    switch ($intent) {

        case 'logout':
            performLogout();
            sendJsonResponse("👋 You have been logged out.", "Logout", array("status" => "success"));
            exit;

        case 'login':
            $_SESSION['user'] = $target ?: 'guest';
            sendJsonResponse("Welcome, {$_SESSION['user']}!", "Login", array(
                "status" => "success",
                "user" => $_SESSION['user']
            ));
            exit;

        case 'report':
            // Route to report generator
            error_log("🧾 Dispatching report generation for target '{$target}'");
            include __DIR__ . "/dispatchers/intent_report.php";
            exit;

        case 'summary':
            // Generate semantic summary from Codex/SSE
            if ($target && isset($codexMeta[$target])) {
                $m = $codexMeta[$target];
                $summary = array(
                    "title"    => $m['title'],
                    "purpose"  => isset($m['purpose']['text']) ? $m['purpose']['text'] : "",
                    "category" => isset($m['category']) ? $m['category'] : "",
                    "features" => isset($m['features']['items']) ? $m['features']['items'] : array()
                );
                sendJsonResponse("📘 {$summary['title']} — {$summary['purpose']}", "summary", array("details" => $summary));
                exit;
            }
            break;

        case 'crud':
            include __DIR__ . "/dispatchers/intent_crud.php";
            exit;

        default:
            break;
    }
}
#endregion

#region 💬 SemanticResponder (AI Fallback)
if (!$handled) {
    // 🧩 Build full context-aware message stack
    $messages = array(
        array(
            "role" => "system",
            "content" =>
                "You are Skyebot — respond conversationally using the current SSE stream and Codex data. " .
                "Reference timeDateArray, workPhase, weatherData, KPIs, and announcements if relevant. " .
                "Be concise, friendly, and contextually aware."
        ),
        array(
            "role" => "system",
            "content" => json_encode($dynamicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        )
    );

    // 🧠 Preserve recent conversation for continuity
    if (!empty($conversation)) {
        $history = array_slice($conversation, -2);
        foreach ($history as $entry) {
            if (isset($entry['role']) && isset($entry['content'])) {
                $messages[] = array(
                    "role"    => $entry['role'],
                    "content" => $entry['content']
                );
            }
        }
    }

    // Add user message last
    $messages[] = array("role" => "user", "content" => $prompt);

    // 🧠 Generate contextual AI response
    $aiResponse = callOpenAi($messages);

    if (!empty($aiResponse)) {
        $responsePayload = array(
            "response"  => trim($aiResponse),
            "action"    => "answer",
            "sessionId" => $sessionId
        );
        $handled = true;
    }
}
#endregion

#region 🌐 Google Search Fallback
if (
    !$handled ||
    (!empty($aiResponse) && is_string($aiResponse) &&
     stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") !== false)
) {
    $searchResults = googleSearch($prompt);

    file_put_contents(
        __DIR__ . '/error.log',
        date('Y-m-d H:i:s') .
        " - Google Search called for prompt: $prompt\n" .
        "Results keys: " .
        (!empty($searchResults) && is_array($searchResults)
            ? implode(', ', array_keys($searchResults))
            : 'none') . "\n",
        FILE_APPEND
    );

    if (isset($searchResults['error'])) {
        $responsePayload = array(
            "response"  => "⚠️ Search service unavailable: " .
                           $searchResults['error'] . ". Please try again.",
            "action"    => "error",
            "sessionId" => $sessionId
        );
    } elseif (!empty($searchResults['summary'])) {
        $responsePayload = array(
            "response"  => $searchResults['summary'] . " (via Google Search)",
            "action"    => "answer",
            "sessionId" => $sessionId
        );
        if (isset($searchResults['raw'][0]) && is_array($searchResults['raw'][0])) {
            $firstLink = isset($searchResults['raw'][0]['link'])
                ? $searchResults['raw'][0]['link']
                : null;
            if ($firstLink) $responsePayload['link'] = $firstLink;
        }
    } else {
        $responsePayload = array(
            "response"  =>
                "⚠️ No relevant search results found. Please rephrase your query.",
            "action"    => "error",
            "sessionId" => $sessionId
        );
    }
}
#endregion

#region ✅ Final Output
if ($responsePayload) {
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    sendJsonResponse("❌ Unable to process request.", "error", array("sessionId" => $sessionId));
}
#endregion