<?php
// 📄 File: api/askOpenAI.php
// Entry point for Skyebot AI interactions (PHP 5.6 compatible refactor)

// Require shared helpers
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
                $messages[] = array("role" => $entry["role"], "content" => $entry["content"]);
            }
        }
    }
    $messages[] = ["role" => "user", "content" => $prompt];

    $aiResponse = callOpenAi($messages);

    if ($aiResponse && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") === false) {
        $responsePayload = [
            "response" => $aiResponse . " ⁂",
            "action" => "answer",
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