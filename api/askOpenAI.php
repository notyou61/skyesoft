<?php
// 📄 File: api/askOpenAI.php
// Entry point for Skyebot AI interactions (PHP 5.6 compatible refactor)

#region 🌐 SSL Trust Setup
$caPath = __DIR__ . '/../assets/certs/cacert.pem';
if (file_exists($caPath)) {
    @ini_set('curl.cainfo', $caPath);
    @ini_set('openssl.cafile', $caPath);
}
#endregion

#region 🧩 UNIVERSAL JSON OUTPUT SHIELD (GoDaddy Safe)

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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

    if (!empty($output) && is_string($output) && stripos(trim($output), '{') !== 0) {
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

#endregion UNIVERSAL JSON OUTPUT SHIELD

#region 🧠 SESSION INITIALIZATION
session_start();
$sessionId = session_id();
#endregion SESSION INITIALIZATION

#region 🧩 CLI FALLBACK FOR LOCAL TESTING
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $decoded = ["prompt" => implode(" ", array_slice($argv, 1))];
    $rawInput = json_encode($decoded);
    $_SERVER["REQUEST_METHOD"] = "POST";
    error_log("💬 CLI Fallback Active: " . $rawInput);
} else {
    $rawInput = file_get_contents('php://input');
}
#endregion CLI FALLBACK

#region 🧩 JSON DECODE VALIDATION (Unified for Web + CLI)
global $decoded, $prompt, $lowerPrompt;
$handled = false;
$responsePayload = null;

// Normalize and decode safely
$normalizedInput = preg_replace('/^\xEF\xBB\xBF/', '', $rawInput); // Strip UTF-8 BOM
$normalizedInput = trim($normalizedInput);
$decoded = json_decode($normalizedInput, true);
error_log("🧩 DEBUG normalizedInput=" . var_export($normalizedInput, true));
error_log("🧩 DEBUG decoded=" . var_export($decoded, true));
error_log("🧩 JSON error=" . json_last_error() . " (" . json_last_error_msg() . ")");

// Fallback if decode still fails (force manual array)
if (!is_array($decoded) && strpos($normalizedInput, '{"prompt":') === 0) {
    $decoded = ["prompt" => preg_replace('/^{"prompt":"|"}$/', '', $normalizedInput)];
    error_log("🩹 Fallback decode path triggered manually");
}

// Duplicate validation removed – handled earlier
// if (!$decoded || empty($decoded['prompt'])) {
//     header('Content-Type: application/json; charset=UTF-8');
//     echo json_encode([
//         "response"  => "❌ Invalid or empty JSON payload.",
//         "action"    => "none",
//         "sessionId" => $sessionId
//     ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
//     exit;
// }

#endregion JSON DECODE VALIDATION

#region 📦 LOAD SHARED HELPERS
require_once __DIR__ . "/helpers.php";
#endregion LOAD SHARED HELPERS

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
// ===============================================================
// Unified input bootstrap for Skyebot™
// - Web requests: process php://input normally
// - CLI mode: skip (handled by CLI fallback + JSON decode)
// ===============================================================

if (php_sapi_name() !== 'cli') {

    // --- Read JSON input (from POST body) ---
    $data = json_decode(file_get_contents("php://input"), true);

    if ($data === null || $data === false) {
        echo json_encode(array(
            "response"  => "❌ Invalid or empty JSON payload.",
            "action"    => "none",
            "sessionId" => $sessionId
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // --- Normalize input variables ---
    $prompt = isset($data["prompt"])
        ? trim(strip_tags(filter_var($data["prompt"], FILTER_DEFAULT)))
        : "";

    $conversation = (isset($data["conversation"]) && is_array($data["conversation"]))
        ? $data["conversation"]
        : array();

    $lowerPrompt = strtolower($prompt);

    // ✅ Legacy "generate [module] sheet" shortcut
    // Retained for backward compatibility — now defers to AI semantic resolution
    if (!empty($prompt) && preg_match('/generate (.+?) sheet/i', $lowerPrompt, $matches)) {

        $moduleName = strtolower(str_replace(' ', '', $matches[1]));
        $aiFallbackStarted = false; // safeguard tracker

        // --- Load Codex safely ---
        $codexData = isset($dynamicData['codex'])
            ? $dynamicData['codex']
            : (file_exists(CODEX_PATH)
                ? json_decode(file_get_contents(CODEX_PATH), true)
                : array());

        // --- Normalize structure (accepts both wrapped + flat) ---
        $modules = (isset($codexData['modules']) && is_array($codexData['modules']))
            ? $codexData['modules']
            : $codexData;

        // --- Direct match search ---
        $foundKey = '';
        foreach ($modules as $key => $val) {
            if (strtolower($key) === $moduleName) {
                $foundKey = $key;
                break;
            }
        }

        // --- If found, return direct link immediately ---
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

        // --- Otherwise → AI-based fallback resolution ---
        if (!$aiFallbackStarted) {
            $aiFallbackStarted = true;
            error_log("⚙️ [Skyebot] Falling back to AI Codex resolver for prompt: $prompt");

            // Build slim Codex index
            $codexSlim = array();
            foreach ($modules as $key => $entry) {
                if (!is_array($entry) || !isset($entry['title'])) continue;
                $codexSlim[] = array(
                    "slug"        => $key,
                    "title"       => $entry['title'],
                    "description" => isset($entry['description']) ? $entry['description'] : ''
                );
            }

            // --- Build AI prompt ---
            $resolutionPrompt = "User request: " . $prompt .
                "\n\nAvailable Codex modules:\n" . json_encode($codexSlim, JSON_UNESCAPED_SLASHES) .
                "\n\nResolve to the best-matching module slug. Respond strictly as JSON: {\"slug\": \"exact-slug\" or null}";

            $messages = array(
                array("role" => "system", "content" => "You are a semantic resolver for Codex modules. Match the user intent to the closest module based on title, description, or keywords. If uncertain, use null."),
                array("role" => "user", "content" => $resolutionPrompt)
            );

            $aiSlugResponse = callOpenAi($messages);

            // --- Parse safely ---
            $slug = null;
            if (!empty($aiSlugResponse)) {
                $decoded = json_decode($aiSlugResponse, true);
                if (is_array($decoded) && isset($decoded['slug']) && $decoded['slug'] !== 'null') {
                    $slug = $decoded['slug'];
                } else {
                    error_log("⚠️ AI returned invalid slug response: " . substr($aiSlugResponse, 0, 200));
                }
            } else {
                error_log("⚠️ Empty AI slug response.");
            }

            error_log("🧠 AI Slug Resolution → slug='" . ($slug ? $slug : 'null') . "' from prompt='" . substr($prompt, 0, 100) . "'");

            // 🧭 Smart Fallback: Try partial or case-insensitive key match in Codex
            if ($slug && !isset($modules[$slug])) {
                $normalizedSlug = strtolower(preg_replace('/[\s_]+/', '', $slug)); // semanticresponder
                foreach ($modules as $key => $entry) {
                    $normalizedKey = strtolower(preg_replace('/[\s_]+/', '', $key));
                    if ($normalizedKey === $normalizedSlug) {
                        $slug = $key; // ✅ Restore the actual Codex key ("semanticResponder")
                        error_log("🔁 Matched normalized slug '$normalizedSlug' to Codex key '$key'");
                        break;
                    }
                    // Also handle partial inclusion ("semantic" → "semanticResponder")
                    if (strpos($normalizedKey, $normalizedSlug) !== false || strpos($normalizedSlug, $normalizedKey) !== false) {
                        $slug = $key;
                        error_log("🔍 Partial slug match: '$normalizedSlug' → '$key'");
                        break;
                    }
                }
            }

            // --- Generate report via internal API ---
            if ($slug && isset($modules[$slug])) {
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
                    $title = isset($modules[$slug]['title'])
                        ? $modules[$slug]['title']
                        : ucwords(str_replace(array('-', '_'), ' ', $slug));

                    // 🧼 Sanitize title for safe filenames and URLs
                    function sanitizeTitleForUrl($text) {
                        $clean = preg_replace('/[\x{1F000}-\x{1FFFF}\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{200D}]/u', '', $text);
                        $clean = preg_replace('/[\x{00A0}\x{FEFF}\x{200B}-\x{200F}\x{202A}-\x{202F}\x{205F}-\x{206F}]+/u', '', $clean);
                        $clean = preg_replace('/\s+/', ' ', trim($clean));
                        $clean = preg_replace('/[^\P{C}]+/u', '', $clean);
                        $clean = preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9)]+$/', '', $clean);
                        return trim($clean);
                    }

                    $cleanTitle = sanitizeTitleForUrl($title);
                    $fileName   = 'Information Sheet - ' . $cleanTitle . '.pdf';
                    $pdfPath    = '/home/notyou64/public_html/skyesoft/docs/sheets/' . $fileName;
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

            // --- Fallback if no match found ---
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(array(
                "response"  => "⚠️ No matching Codex module found. Please rephrase your request.",
                "action"    => "none",
                "sessionId" => $sessionId
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // --- Handle missing prompt (web-only safeguard) ---
    if (empty($prompt)) {
        sendJsonResponse("❌ Empty prompt.", "none", array("sessionId" => $sessionId));
        exit;
    }
} // end non-CLI guard

#endregion 🛡️ Input & Session Bootstrap

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

// 🧠 Flatten Codex for RAG (add metadata for AI reasoning)
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

if (is_string($prompt) && stripos($prompt, 'report') !== false) {
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

#region 🧩 Dynamic System Prompt Builder (Fully Data-Driven)
// ------------------------------------------------------------
// Builds the Skyebot system prompt dynamically from Codex + SSE
// No hardcoding; everything comes from codex.json and dynamicData
// ------------------------------------------------------------

$systemPromptParts = [];

// 🧭 1️⃣ Assistant identity and live context
$name = isset($dynamicData['codex']['meta']['assistantName'])
    ? trim($dynamicData['codex']['meta']['assistantName'])
    : 'Skyebot';

$systemPromptParts[] =
    "You are {$name}™, the integrated assistant within the Skyesoft environment. " .
    "You have continuous access to the live sseSnapshot — containing current date, time, weather, KPIs, announcements, and workday intervals. " .
    "Always ground your responses in that data.";

// ⚖️ 2️⃣ Core Codex content (auto-extract major modules)
if (isset($dynamicData['codex']['modules']) && is_array($dynamicData['codex']['modules'])) {
    foreach ($dynamicData['codex']['modules'] as $slug => $module) {
        $title = isset($module['title']) ? trim($module['title']) : ucwords(str_replace('_', ' ', $slug));
        $desc  = isset($module['description']) ? trim($module['description']) : '';
        if ($desc !== '') {
            $systemPromptParts[] = "{$title}: {$desc}";
        }
    }
}

// 📘 3️⃣ Glossary summary (auto-include)
if (isset($dynamicData['codex']['glossary']) && is_array($dynamicData['codex']['glossary'])) {
    $glossaryTerms = array_keys($dynamicData['codex']['glossary']);
    if (!empty($glossaryTerms)) {
        $preview = implode(', ', array_slice($glossaryTerms, 0, 5));
        $systemPromptParts[] =
            "Reference codex.glossary for internal frameworks and acronyms such as {$preview}, and others defined within the Codex.";
    }
}

// 🌦️ 4️⃣ Live-data feature hints (auto-detect)
$liveHints = [
    "intervalsArray" => "Use intervalsArray to determine workday phases and timing logic.",
    "weatherData"    => "Use weatherData for temperature, conditions, and forecasts.",
    "kpiData"        => "Use kpiData for performance metrics and counts.",
    "announcements"  => "Use announcements for internal bulletins and updates."
];
foreach ($liveHints as $key => $hint) {
    if (isset($dynamicData[$key])) $systemPromptParts[] = $hint;
}

// 🧩 5️⃣ Behavioral rules from Codex Constitution (if defined)
if (isset($dynamicData['codex']['constitution']['rules'])
    && is_array($dynamicData['codex']['constitution']['rules'])
) {
    $systemPromptParts[] = "Foundational behavioral rules:";
    foreach ($dynamicData['codex']['constitution']['rules'] as $rule) {
        if (trim($rule) !== '') $systemPromptParts[] = "• " . trim($rule);
    }
}

// 🧠 6️⃣ Fallback & response behavior
$systemPromptParts[] =
    "If uncertain or lacking data in sseSnapshot or Codex, respond with 'NEEDS_GOOGLE_SEARCH' to trigger the search fallback. " .
    "Always answer in clear, natural sentences grounded in Codex and live context.";

// ✅ Combine all sections with clean spacing
$systemPrompt = implode("\n\n", array_filter($systemPromptParts));

// 🪶 7️⃣ Append legacy injected blocks (optional)
if (!empty($injectBlocks) && is_array($injectBlocks)) {
    foreach ($injectBlocks as $section => $block) {
        $systemPrompt .= "\n\n📘 " . strtoupper($section) . ":\n" .
            json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
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
if (is_string($lowerPrompt) && preg_match('/\b(log\s*out|logout|exit|sign\s*out|quit|leave|end\s+session|done\s+for\s+now|close)\b/i', $lowerPrompt)) {
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
elseif (is_string($lowerPrompt) && preg_match('/\blog\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+)\b/i', $lowerPrompt, $matches)) {
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

// 🔹 Codex Information Sheet Generator (AI-Enhanced: Semantic Slug Resolution + Dynamic Scaling)
if (
    !$handled &&
    is_string($prompt) &&
    preg_match('/\b(generate|create|make|produce|show|build|prepare)\b/i', $prompt) &&
    preg_match('/\b(information|sheet|report|codex|module|file|summary)\b/i', $prompt)
) {

    error_log("🧭 AI-Enhanced Codex Information Sheet Generator triggered — prompt: " . $prompt);

    // 1️⃣ Load Codex (prefer dynamicData, fallback to file)
    $codexData = isset($dynamicData['codex'])
        ? $dynamicData['codex']
        : (file_exists(CODEX_PATH)
            ? json_decode(file_get_contents(CODEX_PATH), true)
            : array());

    // Normalize structure (handles both wrapped + flat)
    $modules = (isset($codexData['modules']) && is_array($codexData['modules']))
        ? $codexData['modules']
        : $codexData;

    // 2️⃣ Build slim Codex index for AI resolution
    $codexSlim = array();
    foreach ($modules as $key => $entry) {
        if (!is_array($entry) || !isset($entry['title'])) continue;
        $codexSlim[] = array(
            "slug"        => $key,
            "title"       => $entry['title'],
            "description" => isset($entry['description']) ? $entry['description'] : ''
        );
    }

    // 3️⃣ AI Slug Resolution (semantic matching via OpenAI)
    $resolutionPrompt = "User request: " . $prompt .
        "\n\nAvailable Codex modules:\n" . json_encode($codexSlim, JSON_UNESCAPED_SLASHES) .
        "\n\nResolve to the best-matching module slug. Respond strictly as JSON: {\"slug\": \"exact-slug\" or null}";

    $messages = array(
        array("role" => "system", "content" => "You are a semantic resolver for Codex modules. Match the user intent to the closest module based on title, description, or keywords. If uncertain, use null."),
        array("role" => "user", "content" => $resolutionPrompt)
    );

    $aiSlugResponse = callOpenAi($messages);

    // 🧠 Parse AI response safely (PHP 5.6-compatible)
    $slug = null;

    if (!empty($aiSlugResponse)) {
        $decoded = json_decode($aiSlugResponse, true);

        if (is_array($decoded) && isset($decoded['slug'])) {
            // ✅ Normalize slug: lower, trim, and remove spaces or underscores for consistent Codex key lookup
            $slug = strtolower(trim($decoded['slug']));
            $slug = preg_replace('/[\s_]+/', '', $slug); // e.g., "semantic responder" → "semanticresponder"
            error_log("🧩 Normalized slug → " . $slug);
        } else {
            error_log("⚠️ AI returned non-JSON or malformed slug response: " . substr($aiSlugResponse, 0, 200));
        }

    } else {
        error_log("⚠️ Empty AI slug response.");
    }

    error_log("🧠 AI Slug Resolution → slug='" . ($slug ? $slug : 'null') . "' from prompt='" . substr($prompt, 0, 100) . "'");

    // 4️⃣ Generate via internal API or build dynamic fallback
    if ($slug && isset($modules[$slug])) {
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
            // **PATCH: Tag to prevent CTA injection in SemanticResponder**
            $responsePayload["preventCtaInjection"] = true;
        } else {
            // ✅ Dynamic Codex Sheet Response (scales automatically)
            $title = isset($modules[$slug]['title'])
                ? trim($modules[$slug]['title'])
                : ucwords(str_replace(array('-', '_'), ' ', $slug));

            // Safeguard: Skip if title is empty (prevents zero-byte filenames)
            if (empty($title)) {
                $title = ucwords($slug);
            }

            // 🔹 Normalize emoji spacing (remove space after emoji prefix)
            if (preg_match('/^([\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}])\s+(.*)$/u', $title, $m)) {
                $title = $m[1] . $m[2];
                error_log("🎯 Codex title normalized: removed space after emoji prefix → '$title'");
            }

            // ✅ Normalize filename — remove emojis, non-breaking spaces, BOM, zero-width spaces, and invisible residues
            $cleanTitle = $title;

            // 🔧 Remove UTF-8 BOM, non-breaking, and zero-width spaces (U+00A0, U+FEFF, U+200B–U+200D)
            $cleanTitle = preg_replace('/[\x{00A0}\x{FEFF}\x{200B}-\x{200D}]+/u', ' ', $cleanTitle);
            $cleanTitle = trim($cleanTitle);

            // 🧠 Remove emoji and symbolic characters (if emoji not wanted in filenames)
            $cleanTitle = preg_replace('/[\p{So}\p{Cn}\p{Cs}]+/u', '', $cleanTitle);

            // 🧩 Remove remaining invisible Unicode spacing (U+2000–U+206F and directional marks)
            $cleanTitle = preg_replace('/[\x{2000}-\x{200F}\x{202A}-\x{202F}\x{205F}-\x{206F}]+/u', '', $cleanTitle);

            // 🧱 Strip non-standard printable chars and normalize whitespace
            $cleanTitle = preg_replace('/[^A-Za-z0-9 _()\-]+/', '', $cleanTitle);
            $cleanTitle = preg_replace('/\s+/', ' ', trim($cleanTitle));

            // 🚦 Final cleanup: collapse multiple or leading spaces
            $cleanTitle = trim(preg_replace('/\s{2,}/', ' ', $cleanTitle));

            // ✅ Always exactly one space after dash
            $fileName = 'Information Sheet - ' . $cleanTitle . '.pdf';

            // Build path + clean public URL
            $pdfPath = '/home/notyou64/public_html/skyesoft/docs/sheets/' . $fileName;
            $relativePath = str_replace('/home/notyou64/public_html', '', $pdfPath);
            $publicUrl = 'https://www.skyelighting.com' . str_replace(' ', '%20', $relativePath);

            // Error log for auditing
            error_log("📘 Generated Info Sheet: slug='$slug', title='$title', cleanTitle='$cleanTitle', url='$publicUrl'");

            // Unified JSON response
            $responsePayload = array(
                "response"  => "📘 The **" . $title . "** information sheet is ready.\n\n📄 [Open Report](" . $publicUrl . ")",
                "action"    => "sheet_generated",
                "slug"      => $slug,
                "reportUrl" => $publicUrl,
                "sessionId" => $sessionId
            );
            // **PATCH: Tag to prevent CTA injection in SemanticResponder**
            $responsePayload["preventCtaInjection"] = true;
        }
    } else {
        // 🧩 Fallback if no AI match
        $responsePayload = array(
            "response"  => "⚠️ No matching Codex module found. Please rephrase your request.",
            "action"    => "none",
            "sessionId" => $sessionId
        );
        // **PATCH: Tag to prevent CTA injection in SemanticResponder**
        $responsePayload["preventCtaInjection"] = true;
    }
    
    // 🩹 FINAL CLEANUP PATCH: prevent duplicate "Open Report" links
    if (isset($responsePayload["response"])) {

        // Remove any repeated Markdown-style links
        $responsePayload["response"] = preg_replace(
            '/(📄\s*\[Open Report\][^\n]*){2,}/u',
            '📄 [Open Report]',
            $responsePayload["response"]
        );

        // Remove any repeated plain-text “📄 Open Report” phrases
        $responsePayload["response"] = preg_replace(
            '/(📄\s*Open Report\s*){2,}/u',
            '📄 Open Report',
            $responsePayload["response"]
        );

        // Collapse extra blank lines to keep spacing tidy
        $responsePayload["response"] = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $responsePayload["response"]
        );
    }

    // 5️⃣ Output unified JSON (always)
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
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
if (!$handled && is_string($lowerPrompt) && preg_match('/\b(create|read|update|delete)\s+(?!a\b|the\b)([a-zA-Z0-9]+)/i', $lowerPrompt, $matches)) {
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

// 🧩 Codex Acronym Resolver (Pre-normalization)
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
            error_log("🔁 Acronym '$acro' matched Codex key '$slug' in prompt.");
            // Automatically rewrite the prompt to full title for matching
            $prompt = preg_replace('/\b' . preg_quote($acro, '/') . '\b/i', $slug, $prompt);
            break;
        }
    }
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

    // 4a. 🔧 CRUD Intent Detection Layer
    $crudIntent = null;
    $crudKeywords = [
        "create" => ["create", "add", "new", "generate", "initiate", "start"],
        "read"   => ["read", "view", "show", "display", "open"],
        "update" => ["update", "edit", "modify", "revise", "change"],
        "delete" => ["delete", "remove", "clear", "erase", "discard"]
    ];

    foreach ($crudKeywords as $intent => $words) {
        foreach ($words as $w) {
            if (stripos($prompt, $w) !== false) {
                $crudIntent = $intent;
                break 2;
            }
        }
    }

    if ($crudIntent) {
        error_log("🧭 Detected CRUD intent: $crudIntent");
        $responsePayload["crudIntent"] = $crudIntent;
        $responsePayload["crudActionLabel"] = ucfirst($crudIntent);
        $responseText = "Understood — preparing to " . $crudIntent . " the relevant record. ";
    }

    // 🔍 Safeguard: detect likely truncation
    if ($aiResponse && !preg_match('/[.!?…]$/', trim($aiResponse))) {
        error_log("⚠️ AI response may be truncated: " . substr($aiResponse, -50));
    }

    if (!empty($aiResponse) && is_string($aiResponse) && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") === false) {

        // Default response text
        if (!isset($responseText)) $responseText = $aiResponse . " ⁂";
        else $responseText .= $aiResponse . " ⁂";

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
                    if ($crudIntent) {
                        $responseText .= " (" . strtoupper($crudIntent) . " mode)";
                        $responsePayload["action"] = $crudIntent;
                    }
                    $ctaAdded = true;
                    break;
                }
            }
        }

        // ... remainder of your Information Sheets / Glossary / Included Docs checks remain unchanged ...

        // Prepare final payload
        $responsePayload["response"]  = $responseText;
        if (!isset($responsePayload["action"])) $responsePayload["action"] = "answer";
        $responsePayload["sessionId"] = $sessionId;
        $handled = true;
    }
}

// 5. 🌐 Google Search Fallback
if (
    !$handled ||
    (!empty($aiResponse) && is_string($aiResponse) && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") !== false)
) {

    $searchResults = googleSearch($prompt);

    // Log for debugging
    file_put_contents(
        __DIR__ . '/error.log',
        date('Y-m-d H:i:s') . " - Google Search called for prompt: $prompt\n" .
        "Results keys: " . (!empty($searchResults) && is_array($searchResults)
            ? implode(', ', array_keys($searchResults))
            : 'none') . "\n",
        FILE_APPEND
    );

    if (isset($searchResults['error'])) {
        // Handle API errors (e.g., keys not set, curl failure)
        $responsePayload = [
            "response"  => "⚠️ Search service unavailable: " . $searchResults['error'] . ". Please try again.",
            "action"    => "error",
            "sessionId" => $sessionId
        ];
    } elseif (!empty($searchResults['summary'])) {
        // Use the summary (handles both single and AI-summarized cases)
        $responsePayload = [
            "response"  => $searchResults['summary'] . " (via Google Search)",
            "action"    => "answer",
            "sessionId" => $sessionId
        ];

        // Optionally add raw snippets or link if available (e.g., from first raw item)
        if (isset($searchResults['raw'][0]) && is_array($searchResults['raw'][0])) {
            $firstLink = isset($searchResults['raw'][0]['link'])
                ? $searchResults['raw'][0]['link']
                : null;
            if ($firstLink) {
                $responsePayload['link'] = $firstLink;
            }
        }
    } else {
        // No useful results
        $responsePayload = [
            "response"  => "⚠️ No relevant search results found. Please try rephrasing your query.",
            "action"    => "error",
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