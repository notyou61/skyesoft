<?php
// 📄 File: api/askOpenAI.php
// Entry point for Skyebot AI interactions (PHP 5.6 compatible refactor)

// ===========================================================
// 🧩 UNIVERSAL JSON OUTPUT SHIELD (GoDaddy Safe)
// ===========================================================

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
    $response = [
        "response"  => $msg,
        "action"    => "error",
        "sessionId" => session_id() ?: 'N/A'
    ];

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
        $response = [
            "response"  => $msg,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        ];
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return;
    }

    // If normal output exists but isn’t JSON, wrap it safely
    if (!empty($output) && stripos(trim($output), '{') !== 0) {
        header('Content-Type: application/json; charset=UTF-8', true);
        $clean = substr(strip_tags($output), 0, 500);
        echo json_encode([
            "response"  => "❌ Internal error: " . $clean,
            "action"    => "error",
            "sessionId" => session_id() ?: 'N/A'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } elseif (!empty($output)) {
        header('Content-Type: application/json; charset=UTF-8', true);
        echo trim($output);
    }
});

// ===========================================================
// Require shared helpers *after* shield is active
// ===========================================================
require_once __DIR__ . "/helpers.php";

#region 🔹 Report Generators (specific report logic)
require_once __DIR__ . "/reports/zoning.php";
require_once __DIR__ . "/reports/signOrdinance.php";
require_once __DIR__ . "/reports/photoSurvey.php";
require_once __DIR__ . "/reports/custom.php";
#endregion

#region 🔹 Shared Zoning Logic
require_once __DIR__ . "/jurisdictionZoning.php";
#endregion

#region 🔹 Environment & Session Setup
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

#region 🛡️ Input & Session Bootstrap
$data = json_decode(file_get_contents("php://input"), true);
if ($data === null || $data === false) {
    echo json_encode(array(
        "response"  => "❌ Invalid or empty JSON payload.",
        "action"    => "none",
        "sessionId" => $sessionId
    ), JSON_PRETTY_PRINT);
    exit;
}

$prompt = isset($data["prompt"])
    ? trim(strip_tags(filter_var($data["prompt"], FILTER_DEFAULT)))
    : "";
$conversation = (isset($data["conversation"]) && is_array($data["conversation"])) ? $data["conversation"] : array();
$lowerPrompt = strtolower($prompt);

// ✅ Handle "generate [module] sheet" pattern (case-insensitive, PHP 5.6-safe)
if (!empty($prompt) && preg_match('/generate (.+?) sheet/', $lowerPrompt, $matches)) {

    $moduleName = strtolower(str_replace(' ', '', $matches[1]));

    // Safely load codex array if available
    $codex = array();
    if (isset($dynamicData['codex'])) {
        $codex = $dynamicData['codex'];
    }

    // Case-insensitive key match for Codex modules
    $foundKey = '';
    foreach ($codex as $key => $val) {
        if (strtolower($key) === $moduleName) {
            $foundKey = $key;
            break;
        }
    }

    if (!empty($foundKey)) {

        $title = isset($codex[$foundKey]['title']) ? $codex[$foundKey]['title'] : ucfirst($foundKey);
        $link  = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . $foundKey;

        $response = array(
            'response' => '📄 <strong>' . $title . '</strong> — <a href="' . $link . '" target="_blank">Generate Sheet</a>'
        );

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($response);
        exit;

    } else {
        $response = array(
            'response' => '⚠️ The requested module "' . $moduleName . '" was not found in the Codex.'
        );
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($response);
        exit;
    }
}

if (empty($prompt)) {
    sendJsonResponse("❌ Empty prompt.", "none", array("sessionId" => $sessionId));
    exit;
}
#endregion

#region 📚 Build Context Blocks (Semantic Router)
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

#region 🛡️ Headers and Setup
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
- Interpret user intent semantically, not just syntactically.
- Map natural language (e.g., “quitting time,” “how much daylight is left,” “what’s the vibe today”) to the correct sseSnapshot fields, even if wording is unusual.
- Prefer semantic interpretation of live data (time, weather, KPIs, work intervals) over strict keyword matching.
- Use Codex knowledge (e.g., glossary terms, Semantic Responder module) to handle indirect or obscure phrasings.
- If information is unavailable in sseSnapshot or Codex, respond with "NEEDS_GOOGLE_SEARCH" instead of "I don’t know".
PROMPT;

foreach ($injectBlocks as $section => $block) {
    $systemPrompt .= "\n\n📘 " . strtoupper($section) . ":\n";
    $systemPrompt .= json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
#endregion

#region 🚦 Dispatch Handler
$handled = false;
$responsePayload = null;
$reportTypesSpec = !empty($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])
    ? $dynamicData['modules']['reportGenerationSuite']['reportTypesSpec']
    : array();

// 1. 🔑 Quick Agentic Actions (Logout, Login)
// --- Logout ---
if (preg_match('/\b(log\s*out|logout|exit|sign\s*out|quit|leave|end\s+session|done\s+for\s+now|close)\b/i', $lowerPrompt)) {
    performLogout();
    $responsePayload = array(
        "actionType" => "Logout",
        "status"     => "success",
        "response"   => "👋 You have been logged out.",
        "sessionId"  => $sessionId
    );
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Login ---
elseif (preg_match('/\blog\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+)\b/i', $lowerPrompt, $matches)) {
    $username = $matches[1];
    $password = $matches[2];

    if (authenticateUser($username, $password)) {
        $_SESSION['user'] = $username;
        $responsePayload = array(
            "actionType" => "Login",
            "status"     => "success",
            "user"       => $username,
            "sessionId"  => $sessionId,
            "loggedIn"   => true
        );
    } else {
        $responsePayload = array(
            "actionType" => "Login",
            "status"     => "failed",
            "message"    => "Invalid credentials",
            "sessionId"  => $sessionId
        );
    }

    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 🔹 Codex Information Sheet Generator (self-adapting + JSON-safe)
if (!$handled && preg_match('/\b(generate|create|make|produce|show)\b.*?\b(information|sheet|report|codex)\b/i', $prompt)) {
    //
    error_log("🧭 Codex Information Sheet Generator triggered — prompt: " . $prompt);

    // 1️⃣ Load Codex (prefer dynamicData, fallback to file)
    $codexData = isset($dynamicData['codex'])
        ? $dynamicData['codex']
        : (file_exists(CODEX_PATH) ? json_decode(file_get_contents(CODEX_PATH), true) : array());

    // Normalize Codex structure (handles both wrapped and direct JSON)
    $modules = (isset($codexData['modules']) && is_array($codexData['modules']))
        ? $codexData['modules']
        : $codexData;

    // 2️⃣ Auto-generate aliases dynamically (DRY)
    $aliases = array();
    $normalizedPrompt = strtolower(preg_replace('/[^a-z0-9\s]/', '', $prompt));

    foreach ($modules as $key => $entry) {
        if (!is_array($entry) || !isset($entry['title'])) continue;

        $cleanTitle = preg_replace('/[^\w\s()]/u', '', strtolower($entry['title']));
        $titleWords = preg_split('/\s+/', trim($cleanTitle));

        if (empty($titleWords)) continue;

        // Multi-word alias ("time interval standards")
        if (count($titleWords) > 1) {
            $multi = implode(' ', $titleWords);
            if (!isset($aliases[$multi])) $aliases[$multi] = $key;
        }

        // Single-word alias ("timeintervalstandards")
        $single = implode('', $titleWords);
        if (!isset($aliases[$single])) $aliases[$single] = $key;

        // Acronym in parentheses ("(TIS)")
        if (preg_match('/\(([A-Z0-9]+)\)/', $entry['title'], $m)) {
            $acro = strtolower($m[1]);
            if (!isset($aliases[$acro])) $aliases[$acro] = $key;
        }
    }
    
    // 🧩 TEMP DEBUG: attempt to log aliases safely (GoDaddy open_basedir safe)
    $debugPath = __DIR__ . '/logs/alias-debug.log';
    $debugData = "Aliases generated at " . date('Y-m-d H:i:s') . "\n" . print_r(array_keys($aliases), true);

    $writeSuccess = @file_put_contents($debugPath, $debugData);

    // Always log what happened
    if ($writeSuccess === false) {
        $altPath = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        $altFile = $altPath . '/alias-debug-' . date('Ymd-His') . '.log';
        @file_put_contents($altFile, $debugData);
        error_log("⚠️ Failed to write alias-debug.log at path: $debugPath — wrote to fallback $altFile instead.");
    } else {
        error_log("✅ alias-debug.log written successfully to $debugPath");
    }

    // 3️⃣ Resolve slug by flexible substring matching (DRY + PHP 5.6-safe)
    $slug = null;
    foreach ($aliases as $alias => $target) {
        // Normalize alias and prompt for fair comparison
        $aliasNorm = strtolower(preg_replace('/[^a-z0-9\s]/', '', $alias));

        if (
            strpos($normalizedPrompt, $aliasNorm) !== false ||     // alias inside prompt
            strpos($aliasNorm, $normalizedPrompt) !== false         // prompt inside alias
        ) {
            $slug = $target;
            break;
        }
    }

    // 4️⃣ Generate via internal API or return not-found
    if ($slug) {
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
            $responsePayload = array(
                "response"  => "❌ Network error contacting generateReports.php: $msg",
                "action"    => "error",
                "sessionId" => $sessionId
            );
        } else {
            $cleanResult = strip_tags($result);
            $responsePayload = array(
                "response"  => "📘 The **{$modules[$slug]['title']}** sheet is being generated.\n\n{$cleanResult}",
                "action"    => "sheet_generated",
                "slug"      => $slug,
                "sessionId" => $sessionId
            );
        }

    } else {
        $responsePayload = array(
            "response"  => "⚠️ The requested module was not found in the Codex.",
            "action"    => "none",
            "sessionId" => $sessionId
        );
    }

    // 5️⃣ Always output JSON once, safely
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 2. 📑 Reports (run this BEFORE CRUD)
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
            $responsePayload = [
                "actionType" => "Create",
                "actionName" => "Report",
                "reportType" => $detectedReport,
                "response"   => "ℹ️ Report definition for $detectedReport.",
                "spec"       => $reportTypesSpec[$detectedReport],
                "inputs"     => ["rawPrompt" => $prompt],
                "sessionId"  => $sessionId
            ];
        } else {
            $responsePayload = [
                "actionType" => "Create",
                "actionName" => "Report",
                "reportType" => $detectedReport,
                "response"   => "ℹ️ Codex information about requested report type.",
                "details"    => $reportTypesSpec[$detectedReport],
                "sessionId"  => $sessionId
            ];
        }
        $handled = true;
    }
}

// 3. --- CRUD (only if no report was matched) ---
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

// 4. 🧭 SemanticResponder
if (!$handled) {
    $messages = [
        [
            "role" => "system",
            "content" => "Here is the current Source of Truth snapshot (sseSnapshot + codex). Use this to answer semantically.\n\n" . $systemPrompt
        ],
        [
            "role" => "system",
            "content" => json_encode($dynamicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ]
    ];
    if (!empty($conversation)) {
        $history = array_slice($conversation, -2);
        foreach ($history as $entry) {
            if (isset($entry["role"]) && isset($entry["content"])) {
                $messages[] = [
                    "role"    => $entry["role"],
                    "content" => $entry["content"]
                ];
            }
        }
    }
    $messages[] = ["role" => "user", "content" => $prompt];

    $aiResponse = callOpenAi($messages);

    // 🔍 Safeguard: detect likely truncation
    if ($aiResponse && !preg_match('/[.!?…]$/', trim($aiResponse))) {
        error_log("⚠️ AI response may be truncated: " . substr($aiResponse, -50));
    }    

    if ($aiResponse && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") === false) {
        // Default response text
        $responseText = $aiResponse . " ⁂";

        // Ensure codex is loaded
        if (!isset($codex) || !isset($codex['modules'])) {
            if (isset($dynamicData['codex'])) {
                $codex = $dynamicData['codex'];
            } else {
                $codex = [];
            }
        }

        $ctaAdded = false; // ✅ safeguard against multiple links

        // 🔹 Normalize AI response for safer matching
        $normalizedResponse = normalizeTitle($aiResponse);

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

                error_log("🔎 Checking module: rawTitle='$rawTitle', cleanTitle='$cleanTitle', acronym='$acronym', key='$key'");
                error_log("🔎 AI Response snippet: " . substr($normalizedResponse, 0, 200));

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

                error_log("🔎 Checking information sheet: sheetKey='$sheetKey', sheetTitle='$sheetTitle', sheetPurpose='$sheetPurpose'");

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

                error_log("🔎 Checking glossary: key='$key', cleanKey='$cleanKey'");

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

                error_log("🔎 Checking included document: doc='$doc', cleanDoc='$cleanDoc'");

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
        $responsePayload = [
            "response"  => $responseText,
            "action"    => "answer",
            "sessionId" => $sessionId
        ];
        $handled = true;
    }
}

// 5. 🌐 Google Search Fallback
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
        $responsePayload = [
            "response" => "⚠️ Search service unavailable: " . $searchResults['error'] . ". Please try again.",
            "action" => "error",
            "sessionId" => $sessionId
        ];
    } elseif (!empty($searchResults['summary'])) {
        // Use the summary (handles both single and AI-summarized cases)
        $responsePayload = [
            "response" => $searchResults['summary'] . " (via Google Search)",
            "action" => "answer",
            "sessionId" => $sessionId
        ];
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
        $responsePayload = [
            "response" => "⚠️ No relevant search results found. Please try rephrasing your query.",
            "action" => "error",
            "sessionId" => $sessionId
        ];
    }
}

// 6. ✅ Final Output
if ($responsePayload) {
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    sendJsonResponse("❌ Unable to process request.", "error", ["sessionId" => $sessionId]);
}
#endregion