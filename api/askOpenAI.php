<?php
// 📄 File: api/askOpenAI.php
// Entry point for Skyebot AI interactions (PHP 5.6 compatible refactor v2.3 - Codex Extracted)

#region 🧠 SKYEBOT UNIVERSAL INPUT LOADER (CLI + WEB Compatible)
$rawInput = @file_get_contents('php://input');

if (PHP_SAPI === 'cli' && (empty($rawInput) || trim($rawInput) === '')) {
    global $argv;
    if (isset($argv[1]) && trim($argv[1]) !== '') {
        $rawInput = $argv[1];
    }
}

// 🧭 Initialize system instructions accumulator
$systemInstr = '';

$rawInput  = trim($rawInput);
$inputData = json_decode($rawInput, true);

// Attempt to fix malformed quotes if needed
if (!is_array($inputData)) {
    $fixed = trim($rawInput, "\"'");
    $inputData = json_decode($fixed, true);
}

// Handle invalid or empty input
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

#region 🧩 SKYEBOT ⇄ POLICY ENGINE INTEGRATION
if (!empty($prompt) && function_exists('runPolicyEngine')) {
    $policyData = runPolicyEngine($prompt);

    if ($policyData['success'] && isset($policyData['policy'])) {
        $summary = isset($policyData['policy']['purpose']['text'])
            ? $policyData['policy']['purpose']['text']
            : '[Policy data available but no summary text]';

        $systemInstr .= "\n\n📜 PolicyEngine Context:\n" . $summary;

        error_log("📥 PolicyEngine JSON processed: domain={$policyData['domain']} target={$policyData['target']}");
    } else {
        error_log("⚠️ PolicyEngine returned empty or failed for prompt: {$prompt}");
    }
} else {
    error_log("⚠️ PolicyEngine not invoked — no prompt or runPolicyEngine() unavailable.");
}
#endregion

#region 🧠 SKYEBOT UNIVERSAL INPUT LOADER (CLI + WEB Compatible)
// ================================================================
$rawInput = @file_get_contents('php://input');

if (PHP_SAPI === 'cli' && (empty($rawInput) || trim($rawInput) === '')) {
    global $argv;
    if (isset($argv[1]) && trim($argv[1]) !== '') {
        $rawInput = $argv[1];
    }
}

$rawInput  = trim($rawInput);
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

//
require_once __DIR__ . '/ai/policyEngine.php';
//
if (!empty($prompt)) {
    $policyData = runPolicyEngine($prompt);

    if ($policyData['success'] && isset($policyData['policy'])) {
        $summary = isset($policyData['policy']['purpose']['text'])
            ? $policyData['policy']['purpose']['text']
            : '[Policy data available but no summary text]';
        $systemInstr .= "\n\n📜 PolicyEngine Context:\n" . $summary;
        error_log("📥 PolicyEngine JSON processed: domain={$policyData['domain']} target={$policyData['target']}");
    } else {
        error_log("⚠️ PolicyEngine returned empty or failed.");
    }
}

$conversation = (isset($inputData['conversation']) && is_array($inputData['conversation']))
    ? $inputData['conversation']
    : array();

$lowerPrompt = strtolower($prompt);
#endregion

#region 🧩 SKYEBOT GOVERNED PROMPT ROUTING (Codex-Aware Policy Pass)
$governedPrompt = $prompt; // default fallback
if (function_exists('runPolicyEngine')) {
    $governedPrompt = runPolicyEngine($prompt);
    error_log("🧩 Governed prompt constructed successfully via PolicyEngine.");
} else {
    error_log("⚠️ PolicyEngine unavailable — using raw prompt.");
}

$prompt = $governedPrompt;
error_log("🧠 Governed prompt preview: " . substr(json_encode($prompt), 0, 250));
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

#region 🧩 SKYEBOT GOVERNED PROMPT ROUTING (Codex-Aware Policy Pass)
// ---------------------------------------------------------------
// Replaces direct prompt usage with a PolicyEngine-governed variant.
// Ensures Codex, SSE context, and policy hierarchy are applied.
// ---------------------------------------------------------------

$governedPrompt = $prompt; // default fallback
if (function_exists('runPolicyEngine')) {
    $governedPrompt = runPolicyEngine($prompt);
    error_log("🧩 Governed prompt constructed successfully via PolicyEngine.");
} else {
    error_log("⚠️ PolicyEngine unavailable — using raw prompt.");
}

// Replace main prompt for downstream logic
$prompt = $governedPrompt;

// 🔍 Debug: preview governed prompt output
error_log("🧠 Governed prompt preview: " . substr(json_encode($prompt), 0, 250));

#endregion

#region 🔒 UNIVERSAL JSON OUTPUT SHIELD (GoDaddy Safe)

// Disable HTML error output and start buffering immediately
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Clean buffer completely (reset any prior output)
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);

    // ✅ MTCO fix: send header only if still allowed
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8', true);
    }

    $clean = htmlspecialchars(strip_tags($errstr), ENT_QUOTES, 'UTF-8');
    $msg = "⚠️ PHP error [$errno]: $clean in $errfile on line $errline";

    echo json_encode(array(
        "response"  => $msg,
        "action"    => "error",
        "sessionId" => session_id() ?: 'N/A'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    exit(1);
}, E_ALL);

register_shutdown_function(function () {
    $lastError = error_get_last();
    $output = ob_get_clean();

    // 🚨 Catch fatal errors that bypass the normal error handler
    if ($lastError && ($lastError['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {

        // 🧹 Clean current buffer only (don’t destroy the stack)
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code(500);

        // 🧭 MTCO safeguard: only set headers if still possible
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8', true);
        }

        // 🧩 Sanitize and build fatal-error message
        $clean = htmlspecialchars(strip_tags($lastError['message']), ENT_QUOTES, 'UTF-8');
        $msg   = "❌ Fatal error: $clean in {$lastError['file']} on line {$lastError['line']}";

        $response = array(
            "response"  => $msg,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        );

        // 📤 Output clean JSON error payload
        echo json_encode(
            $response,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        // 🧯 Stop further execution to ensure clean exit
        return;
    }

    // 🧩 If output exists but isn't valid JSON, wrap it safely
    if (!empty($output) && stripos(trim($output), '{') !== 0) {

        // 🧭 MTCO safeguard: send header only if still possible
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8', true);
        }

        // ✂️ Sanitize and limit leaked output
        $clean = substr(strip_tags($output), 0, 500);

        echo json_encode(array(
            "response"  => "❌ Internal error: " . $clean,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    } elseif (!empty($output)) {
        // 🧭 If output already looks like JSON, just ensure proper header
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8', true);
        }

        echo trim($output);
    }

    // 🧯 Finally, flush remaining buffer if active
    if (ob_get_length()) {
        @ob_end_flush();
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
// 🧩 Error Handler — converts PHP warnings/notices to JSON (MTCO safe)
// --------------------------------------------------
set_error_handler(function ($errno, $errstr, $errfile, $errline) {

    // 🧹 Clean current buffer without destroying the stack
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code(500);

    // 🧭 MTCO safeguard: set header only if still possible
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8', true);
    }

    // ✂️ Sanitize and build message
    $clean = htmlspecialchars(strip_tags($errstr), ENT_QUOTES, 'UTF-8');
    $msg   = "⚠️ PHP error [$errno]: $clean in $errfile on line $errline";

    // 📤 Emit structured JSON error
    echo json_encode(array(
        "response"  => $msg,
        "action"    => "error",
        "sessionId" => session_id() ?: 'N/A'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    // 🧯 Stop further processing
    exit(1);
}, E_ALL);

// --------------------------------------------------
// 🧱 Shutdown Handler — catches fatal / parse errors
// --------------------------------------------------
register_shutdown_function(function () {
    $lastError = error_get_last();
    $output = ob_get_clean();

// --------------------------------------------------
// 🚨 Catch fatal errors that bypass the normal handler
// --------------------------------------------------
if ($lastError && ($lastError['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {

    // 🧹 Clean current buffer without destroying stack
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code(500);

    // 🧭 MTCO safeguard: only send header if still allowed
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8', true);
    }

    // ✂️ Sanitize message to prevent HTML leakage
    $clean = htmlspecialchars(strip_tags($lastError['message']), ENT_QUOTES, 'UTF-8');
    $msg   = "❌ Fatal error: $clean in {$lastError['file']} on line {$lastError['line']}";

    // 📤 Output structured JSON response
    echo json_encode(array(
        "response"  => $msg,
        "action"    => "error",
        "sessionId" => session_id() ?: 'N/A'
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    // 🧯 End handler cleanly
    return;
}

    // --------------------------------------------------
    // ✅ Wrap any non-JSON output safely
    // --------------------------------------------------
    if (!empty($output) && stripos(trim($output), '{') !== 0) {

        // 🧭 Guard header call
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8', true);
        }

        // ✂️ Sanitize visible text (limit 500 chars)
        $clean = substr(strip_tags($output), 0, 500);

        echo json_encode(array(
            "response"  => "❌ Internal error: " . $clean,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    } elseif (!empty($output)) {

        // 🧭 Ensure header if output already valid JSON
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8', true);
        }

        echo trim($output);
    }

    // --------------------------------------------------
    // 🧾 Flush remaining buffer gracefully
    // --------------------------------------------------
    if (ob_get_length()) {
        @ob_end_flush();
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

// 🔹 Load Dynamic SSE + Extract Codex Layer (Fix for Blind Resolver)
$sseData = $dynamicData;  // Full SSE from curl (live preferred)
$codexPath = __DIR__ . '/../assets/data/dynamicData.json';  // Local fallback if curl fails
if (empty($sseData) && file_exists($codexPath)) {
    $localRaw = file_get_contents($codexPath);
    $sseData = json_decode($localRaw, true) ?: array();
}

// 🧩 Codex v5.3 – Unified Dynamic Context Integration
// Merge all top-level SSE keys dynamically to maintain Codex alignment
if (is_array($sseData)) {
    foreach ($sseData as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $dynamicData[$key] = $value;
        }
    }
    error_log("🧠 Unified context loaded (" . count($sseData) . " SSE keys integrated)");
} else {
    error_log("⚠️ SSE data not an array; skipping context merge.");
}

$codex = isset($sseData['codex']) && is_array($sseData['codex'])
    ? $sseData['codex']
    : array();

// Set globals for resolver
global $codex, $sseData;
error_log("🧭 Codex extracted: " . count($codex) . " keys (e.g., " . implode(', ', array_slice(array_keys($codex), 0, 3)) . ") | SSE keys: " . count($sseData));
#endregion

#region 🧩 Dynamic Holiday Resolver (Codex-Compliant, PHP 5.6 Safe)

// --- Step 1: Guard clause: only continue if Codex holiday data + prompt exist ---
if (
    isset($codex['timeIntervalStandards']['holidayRegistry']['holidays']) &&
    is_array($codex['timeIntervalStandards']['holidayRegistry']['holidays']) &&
    isset($prompt)
) {
    // 🧹 Normalize prompt to lowercase text
    $queryText = '';
    if (is_string($prompt)) {
        $queryText = strtolower($prompt);
    } elseif (is_array($prompt)) {
        $queryText = strtolower(implode(' ', array_filter($prompt, 'is_string')));
    }

    // --- Step 2: Determine if this query should trigger the resolver ---
    $triggered = false;
    if (
        strpos($queryText, 'holiday')      !== false ||
        strpos($queryText, 'christmas')    !== false ||
        strpos($queryText, 'thanksgiving') !== false ||
        strpos($queryText, 'halloween')    !== false
    ) {
        $triggered = true;
    }

    // --- Step 3: Process only if triggered ---
    if ($triggered) {
        $today       = date('Y-m-d');
        $todayTime   = strtotime($today . ' 00:00:00');
        $currentYear = date('Y');

        $filterCategory = null;
        if (strpos($queryText, 'company') !== false) {
            $filterCategory = 'company';
        } elseif (strpos($queryText, 'federal') !== false) {
            $filterCategory = 'federal';
        }

        // --- Step 4: Build candidate list from Codex registry ---
        $candidates = array();
        foreach ($codex['timeIntervalStandards']['holidayRegistry']['holidays'] as $h) {
            if (!isset($h['rule'])) continue;
            $rule = $h['rule'];

            // Basic fixed-date parser (safe subset of your rule logic)
            if (preg_match('/^(\w{3})\s+(\d{1,2})$/', $rule, $m)) {
                $month = date('m', strtotime($m[1] . ' 1'));
                $day   = sprintf('%02d', $m[2]);
                $holidayDate = "$currentYear-$month-$day";
            } else {
                // Fallback: skip complex rules here (already resolved upstream)
                continue;
            }

            $holidayTime = strtotime($holidayDate . ' 00:00:00');
            if ($holidayTime < $todayTime) continue;

            // Normalize categories (single or multi)
            $cats = array();
            if (isset($h['categories']) && is_array($h['categories'])) {
                foreach ($h['categories'] as $c) {
                    if (is_string($c)) $cats[] = strtolower($c);
                }
            } elseif (isset($h['category'])) {
                $cats[] = strtolower($h['category']);
            }

            if (!$filterCategory || in_array($filterCategory, $cats)) {
                $h['date'] = $holidayDate;
                $candidates[] = $h;
            }
        }

        // --- Step 5: Formulate response ---
        if (!empty($candidates)) {
            usort($candidates, function ($a, $b) {
                return strcmp($a['date'], $b['date']);
            });
            $next = reset($candidates);
            $respText = 'The next ' . ($filterCategory ? $filterCategory . ' ' : '') .
                        'holiday is ' . $next['name'] . ' on ' .
                        date('F j, Y', strtotime($next['date'])) . '.';
        } else {
            $respText = 'No upcoming holidays found in the current registry.';
        }

        $response = array(
            'response'  => $respText,
            'action'    => 'inform',
            'status'    => 'ok',
            'sessionId' => isset($sessionId) ? $sessionId : uniqid('sess_')
        );

        // --- Step 6: Output JSON and exit cleanly ---
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            flush();
        }
        exit(0);
    }
}

// If we reach here, resolver was not triggered — continue normal AI logic.
#endregion

#region ✅ Handle "generate [module] sheet" pattern (PHP 5.6-safe)

// 🧩 Normalize to string before lowercase conversion
$lowerPrompt = is_string($prompt)
    ? strtolower($prompt)
    : (is_array($prompt) && isset($prompt['prompt'])
        ? strtolower($prompt['prompt'])
        : '');

// More robust capture: "generate (an)? (information)? sheet (for)? <name>"
if (!empty($prompt) && preg_match(
    '/\bgenerate\b\s*(?:an?\s+)?(?:information\s+)?sheet(?:\s+for)?\s+(.+?)(?:\s*(?:sheet|report))?(?:[.!?]|\s*$)/i',
    is_string($prompt) ? $prompt : (is_array($prompt) && isset($prompt['prompt']) ? $prompt['prompt'] : ''),
    $matches
)) {
    // Normalize candidate module name
    $moduleNameRaw = trim($matches[1]);
    $moduleName    = strtolower(preg_replace('/[^a-z0-9_-]/i', '', str_replace(' ', '', $moduleNameRaw)));
    $aiFallbackStarted = false; // safeguard tracker

    // Load codex safely
    $codexData = $codex ?: array();  // Use extracted $codex

    // Build full module index (flat + nested)
    $allModules = array();
    if (is_array($codexData)) {
        foreach ($codexData as $k => $v) { $allModules[$k] = $v; }
        if (isset($codexData['modules']) && is_array($codexData['modules'])) {
            foreach ($codexData['modules'] as $k => $v) { $allModules[$k] = $v; }
        }
    }

    // --- Resolve slug from moduleName + prompt
    $slug = '';
    $normalizedPrompt = preg_replace('/[^a-z0-9]/', '', strtolower(is_string($prompt) ? $prompt : (is_array($prompt) && isset($prompt['prompt']) ? $prompt['prompt'] : '')));
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
        error_log("⚠️ No matching Codex module found for prompt: " . (is_string($prompt) ? $prompt : json_encode($prompt)));
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array(
            "response"  => "⚠️ No matching Codex module found for “{$moduleNameRaw}”. Try a clearer module name.",
            "action"    => "none",
            "sessionId" => $sessionId
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    error_log("✅ Codex slug resolved to '$slug' from prompt: " . (is_string($prompt) ? $prompt : json_encode($prompt)));

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
                "response"  => "📘 The **{$title}** sheet is ready.\n\n📄 [Open Report]($publicUrl)",
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
    "glossary"     => !empty($codex['glossary']) ? array_keys($codex['glossary']) : array(),
    "constitution" => !empty($codex['constitution']) ? array_keys($codex['constitution']) : array(),
    "modules"      => !empty($codex['modules']) ? array_keys($codex['modules']) : array()
);

// Always inject slim Codex sections (keys only) for broader context
$injectBlocks = array(
    "snapshot" => $snapshotSlim,
    "glossary" => isset($codex['glossary']) ? array_keys($codex['glossary']) : array(),
    "modules"  => isset($codex['modules']) ? array_keys($codex['modules']) : array(),
);

// 🧭 Flatten Codex for RAG (add metadata for AI reasoning)
$codexMeta = array();
if (!empty($codex)) {
    foreach ($codex as $key => $mod) {
        $codexMeta[$key] = array(
            'title'       => isset($mod['title']) ? $mod['title'] : $key,
            'description' => isset($mod['description']) ? $mod['description']
                : (isset($mod['purpose']['text']) ? $mod['purpose']['text'] : 'No summary available'),
            'tags'        => isset($mod['tags']) ? $mod['tags']
                : (isset($mod['aliases']) ? $mod['aliases'] : array())
        );
    }
    if (isset($codex['modules']) && is_array($codex['modules'])) {
        foreach ($codex['modules'] as $key => $mod) {
            if (!isset($codexMeta[$key])) {
                $codexMeta[$key] = array(
                    'title'       => isset($mod['title']) ? $mod['title'] : $key,
                    'description' => isset($mod['description']) ? $mod['description'] : 'No summary available',
                    'tags'        => isset($mod['tags']) ? $mod['tags'] : array()
                );
            }
        }
    }
}
$injectBlocks['codexMeta'] = $codexMeta;
error_log("🧭 codexMeta built: " . count($codexMeta) . " entries [" . implode(', ', array_slice(array_keys($codexMeta), 0, 3)) . "...]");

// 🩹 Safeguard: normalize $prompt before using string functions
if (is_array($prompt)) {
    $prompt = isset($prompt['prompt']) ? (string)$prompt['prompt'] : json_encode($prompt);
}
$lowerPrompt = strtolower((string)$prompt);

// Selectively expand specific Codex entries if keywords match
foreach ($codexCategories as $section => $keys) {
    foreach ($keys as $key) {
        if (strpos($lowerPrompt, strtolower($key)) !== false) {
            $injectBlocks[$section][$key] = $codex[$section][$key];
        }
    }
}

// Report types injection (safe for all input types)
if (stripos($prompt, 'report') !== false) {
    $injectBlocks['reportTypes'] = !empty($codex['modules']['reportGenerationSuite']['reportTypesSpec'])
        ? array_keys($codex['modules']['reportGenerationSuite']['reportTypesSpec'])
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
#endregion

#region 🧩 Build Router Prompt
$routerPrompt = <<<PROMPT
You are the Skyebot™ Semantic Intent Router.
Your role is to classify and route user intent based on meaning, not keywords.
Use Codex Semantic Index, SSE context, and conversation history.

If the user request involves making, creating, or preparing any sheet, report, or codex, classify it as intent = "report".
If the request involves creating, updating, or deleting an entity, classify it as intent = "crud".
If the user is asking a question about time, duration, schedules, or events (e.g., 'how long until workday begins', 'when is sunset', 'time till X'), classify it as intent = "temporal" — target the relevant codex slug if applicable (e.g., 'timeIntervalStandards' for workday/schedule queries).
Prefer "temporal" over "report" for time-related questions, even if they reference Codex terms like 'workday'.
Prefer "report" over "crud" when both could apply.

🆕 IMPROVED: For "target", extract ONLY the clean Codex module slug (e.g., 'timeIntervalStandards' for "Time Interval Standards" or "information sheet for time interval standards"). Ignore boilerplate like "generate", "sheet for", or full phrases. Use exact keys from the Codex Semantic Index.

Return only JSON in this structure:
{
  "intent": "logout" | "login" | "report" | "crud" | "temporal" | "general",
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
// ======================================================================
// Purpose:
//   • Resolve user intent and route execution semantically
//   • Use Codex + SSE object reasoning (via resolveSkyesoftObject())
//   • Eliminate fragile regex conditions
//   • Maintain clear audit trail via structured logging
// ======================================================================
if (is_array($intentData) && isset($intentData['intent'])) {
    $intent        = strtolower(trim($intentData['intent']));
    $target        = isset($intentData['target']) ? trim($intentData['target']) : null;  // Trim, no strtolower (preserve camelCase)
    $confidence    = isset($intentData['confidence']) ? (float)$intentData['confidence'] : 0.0;
    $minConfidence = 0.7;  // Bumped to align with resolver threshold
    $handled       = false;

    // 🧠 Step 1: Semantic Target Resolution (SSE + Codex)
    // Always attempt resolution if target doesn't directly match (even if provided)
    if (in_array($intent, array('report', 'summary', 'crud')) && 
        (!$target || !isset($codexMeta[$target]) || $confidence < $minConfidence)) {
        $resolved = resolveSkyesoftObject($prompt, $dynamicData);
        error_log("🔗 Resolver attempt for '{$prompt}': " . json_encode($resolved));
        if (is_array($resolved) && isset($resolved['key']) && $resolved['confidence'] > 70) {
            $target = $resolved['key'];
            $confidence = $resolved['confidence'];
            error_log("🔗 Semantic resolver matched {$resolved['layer']} → {$target} ({$resolved['confidence']}%)");
        } else {
            error_log("⚠️ Semantic resolver found no strong match for '{$prompt}' (conf: " . (isset($resolved['confidence']) ? $resolved['confidence'] : 0) . ")");
        }
    }

    // 🧩 Step 2: Relationship Context Expansion
    // Expand dependencies or providers within Codex meta, if defined.
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
    error_log("🧭 Intent: {$intent} | Target: " . ($target ?: 'none') . " | Confidence: {$confidence} | Prompt: {$prompt}");

    // 🧾 Step 4: Dispatch by Intent
    switch ($intent) {

        // 🔒 Logout
        case 'logout':
            performLogout();
            sendJsonResponse("👋 You have been logged out.", "Logout", array("status" => "success"));
            $handled = true;
            break;

        // 🔑 Login
        case 'login':
            $_SESSION['user'] = $target ?: 'guest';
            sendJsonResponse("Welcome, {$_SESSION['user']}!", "Login", array(
                "status" => "success",
                "user"   => $_SESSION['user']
            ));
            $handled = true;
            break;

        // 🧾 Report Generation (Codex / SSE object sheets)
        case 'report':
            if (!$target) {
                error_log("⚠️ Report intent triggered with no valid target.");
                sendJsonResponse("⚠️ No valid report target specified.", "error");
                $handled = true;
                break;
            }
            // Double-check post-resolution match (now succeeds with full codexMeta)
            if (!isset($codexMeta[$target])) {
                error_log("⚠️ Resolved target '{$target}' not in codexMeta; forcing re-resolve. Available: " . implode(', ', array_keys($codexMeta)));
                $resolved = resolveSkyesoftObject($prompt, $dynamicData);
                error_log("🔗 Re-resolve: " . json_encode($resolved));
                if (is_array($resolved) && isset($resolved['key']) && $resolved['confidence'] > 70) {
                    $target = $resolved['key'];
                } else {
                    sendJsonResponse(
                        "⚠️ Unable to resolve report target from '{$prompt}' (conf: " . (isset($resolved['confidence']) ? $resolved['confidence'] : 0) . ").",
                        "error"
                    );
                    $handled = true;
                    break;
                }
            }
            error_log("🧾 Dispatching report generation for target '{$target}'");
            include __DIR__ . "/dispatchers/intent_report.php";
            $handled = true;
            break;

        // 📘 Semantic Summary (Codex or SSE object description)
        case 'summary':
            if ($target && isset($codexMeta[$target])) {
                $m = $codexMeta[$target];
                $summary = array(
                    "title"    => $m['title'],
                    "purpose"  => isset($m['purpose']['text']) ? $m['purpose']['text'] : "",
                    "category" => isset($m['category']) ? $m['category'] : "",
                    "features" => isset($m['features']['items']) ? $m['features']['items'] : array()
                );
                sendJsonResponse("📘 {$summary['title']} — {$summary['purpose']}", "summary", array("details" => $summary));
                $handled = true;
            } else {
                $resolved = resolveSkyesoftObject($prompt, $dynamicData);
                if (is_array($resolved) && $resolved['confidence'] > 30) {
                    sendJsonResponse("📘 Summary generated for {$resolved['key']}", "summary", array("resolved" => $resolved));
                } else {
                    sendJsonResponse("⚠️ Unable to generate summary — no target found.", "error");
                }
                $handled = true;
            }
            break;

                // ⚙️ CRUD Operations (Create, Read, Update, Delete)
        case 'crud':
            error_log("⚙️ Routing CRUD action for target '{$target}'");
            include __DIR__ . "/dispatchers/intent_crud.php";
            $handled = true;
            break;

        // 🧩 Default / General Chat
        default:
            error_log("💬 Default intent route triggered — using general chat handler.");
            include __DIR__ . "/dispatchers/intent_general.php";
            $handled = true;
            break;
    }
} else {
    // Fallback: router returned invalid or empty JSON
    error_log("⚠️ Invalid router output. Response: " . substr((string)$routerResponse, 0, 200));
    sendJsonResponse("⚠️ Unable to classify request. Please rephrase.", "error");
    $handled = true;
}

#endregion

#region 🧠 PHASE 6: AI RESPONSE COMPOSER (Natural Language Generator)
// ======================================================================
// Purpose:
//   • Converts structured semantic or Codex-based data into natural text
//   • Bridges structured JSON (e.g., temporal, contextual) to human reply
//   • Ensures clean single-response output (no early exits)
//   • Fully PHP 5.6 compatible
// ======================================================================

// 🧩 Safety: ensure variable exists before processing
$aiReply = isset($aiReply) ? $aiReply : '';

// 🧩 Guard: prevent premature echoes from upstream router blocks
ob_end_clean(); // Clear any buffered output before composing

// ✅ Normalize: if the router/intent returned an array, encode for parsing
if (is_array($aiReply)) {
    $aiReply = json_encode($aiReply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// Detect if router or intent produced structured JSON
if (is_string($aiReply) && substr(trim($aiReply), 0, 1) === '{') {
    $decoded = json_decode($aiReply, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $domain = isset($decoded['domain']) ? $decoded['domain'] : '';
        $intent = isset($decoded['intent']) ? $decoded['intent'] : '';

    // ⏱️ Temporal domain → Natural Language Composer (v1.3 Codex-aligned)
    if ($domain === 'temporal' && isset($decoded['data']['runtime'])) {
        $rt = $decoded['data']['runtime'];
        $d  = isset($rt['delta']) ? $rt['delta'] : array();
        $event = isset($decoded['event']) ? $decoded['event'] : 'event';
        $targetDate = isset($rt['targetDate']) ? $rt['targetDate'] : date('Y-m-d');
        $targetTime = isset($rt['targetTime']) ? $rt['targetTime'] : date('g:i A');
        $now = isset($rt['now']) ? $rt['now'] : date('g:i A');
        $tz  = isset($rt['timezone']) ? $rt['timezone'] : 'America/Phoenix';

        // Build human-readable delta (from resolver, no recomputation)
        $parts = array();
        if (!empty($d['totalDays'])) $parts[] = $d['totalDays'].' day'.($d['totalDays']!=1?'s':'');
        if (!empty($d['hours']))     $parts[] = $d['hours'].' hour'.($d['hours']!=1?'s':'');
        if (!empty($d['minutes']))   $parts[] = $d['minutes'].' minute'.($d['minutes']!=1?'s':'');
        $deltaText = empty($parts) ? 'less than a minute' : implode(' and ', $parts);
        $dir = isset($d['direction']) ? $d['direction'] : 'until';

        // Compose message
        if ($dir === 'ago') {
            $msg = "The {$event} started {$deltaText} ago at {$targetTime} on {$targetDate}.";
        } else {
            $msg = "The next {$event} begins at {$targetTime} on {$targetDate}, which is {$deltaText} from now.";
        }

        // Domain-specific refinements
        if ($event === 'sundown') {
            $msg = "Sunset is at {$targetTime} today ({$tz}), which is {$deltaText} from now.";
        }
        if ($event === 'worktime' && isset($decoded['isWorkdayToday']) && !$decoded['isWorkdayToday']) {
            $msg = "The next workday begins at {$targetTime} on {$targetDate} ({$tz}), {$deltaText} from now.";
        }

        // Optional: add ongoing check
        if (isset($rt['elapsed']) && is_array($rt['elapsed'])) {
            $e = $rt['elapsed'];
            $eh = isset($e['hours']) ? $e['hours'] : 0;
            $em = isset($e['minutes']) ? $e['minutes'] : 0;
            $msg = "The workday began {$eh} hour".($eh!=1?'s':'')." and {$em} minute".($em!=1?'s':'')." ago at {$targetTime}.";
        }

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('response' => $msg), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }


        // 🧩 Other domains → prepare for AI summary or fallback
        if (!empty($domain) && $domain !== 'temporal') {
            $aiPrompt = "You are Skyebot. Convert this JSON context into a natural response:\n\n" .
                        json_encode($decoded, JSON_PRETTY_PRINT);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array('response' => $aiPrompt), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// 🧩 Fallback: If structured JSON not detected, continue normal output flow
#endregion

#region 🧩 FINAL RESPONSE SAFEGUARD
// ================================================================
// Purpose:
//   • Ensures consistent JSON response even if a branch misses output
//   • Prevents silent script termination or raw echoes
// ================================================================

echo "<!--";
echo "\n🧭 Skyebot prompt before send: " . substr($prompt, 0, 250);
echo "\n🧩 SystemInstr contains policyEngine? " . (strpos($systemInstr, 'policyEngine.php') !== false ? 'yes' : 'no');
echo "\n-->";

if (!$handled) {
    error_log("⚠️ Unhandled prompt path — returning generic AI response.");
    sendJsonResponse(
        "💬 I understood your message but couldn’t determine an exact action. Try rephrasing or specify a report type.",
        "fallback",
        array("prompt" => $prompt)
    );
}
#endregion

#region ✅ Output Flush
// Force clean buffer flush (safety for GoDaddy PHP handlers)
while (ob_get_level() > 0) { ob_end_flush(); }
exit;
#endregion