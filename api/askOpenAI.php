<?php
// 📄 File: api/askOpenAI.php
// Entry point for Skyebot AI interactions (Jazz-style refactor)

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
$dynamicData = [];
$snapshotSummary = '{}';

// Fetch JSON via curl
$ch = curl_init($dynamicUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Skyebot/1.0 (+skyelighting.com)'
]);
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
    $snapshotSummary = json_encode([
        "timeDateArray" => [
            "currentLocalTime" => date('h:i:s A'),
            "currentDate" => date('Y-m-d'),
            "timeZone" => date_default_timezone_get()
        ],
        "weatherData" => [
            "description" => "Unavailable",
            "temp" => null
        ],
        "announcements" => [],
        "kpiData" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
$conversation = isset($data["conversation"]) && is_array($data["conversation"]) ? $data["conversation"] : array();
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
    "glossary" => array_keys($dynamicData['codex']['glossary'] ?? []),
    "modules" => array_keys($dynamicData['codex']['modules'] ?? []),
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

// 1. 🔑 Quick Agentic Actions (Logout, Login, CRUD)
if (preg_match('/\b(log\s*out|logout|exit|sign\s*out|quit|leave|end\s+session|done\s+for\s+now|close)\b/i', $lowerPrompt)) {
    performLogout();
    $responsePayload = [
        "actionType" => "Logout",
        "status" => "success",
        "sessionId" => $sessionId
    ];
    $handled = true;
} elseif (preg_match('/\b(log\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+))\b/i', $lowerPrompt, $matches)) {
    $username = $matches[1];
    $password = $matches[2];
    if (authenticateUser($username, $password)) {
        $_SESSION['user'] = $username;
        $responsePayload = [
            "actionType" => "Create",
            "actionName" => "Login",
            "response" => "Logged in as $username",
            "sessionId" => $sessionId,
            "loggedIn" => true
        ];
    } else {
        $responsePayload = [
            "response" => "Invalid credentials",
            "action" => "none",
            "sessionId" => $sessionId
        ];
    }
    $handled = true;
} elseif (preg_match('/\b(create|read|update|delete)\s+([a-zA-Z0-9]+)\b/i', $lowerPrompt, $matches)) {
    $actionType = ucfirst(strtolower($matches[1]));
    $entity = $matches[2];
    $details = array("entity" => $entity, "prompt" => $prompt);
    
    if ($actionType === "Create") {
        $success = createCrudEntity($entity, $details);
        $responsePayload = [
            "response" => $success ? "Created $entity successfully" : "Failed to create $entity",
            "action" => "crud",
            "actionType" => "Create",
            "actionName" => $entity,
            "sessionId" => $sessionId
        ];
    } elseif ($actionType === "Read") {
        $result = readCrudEntity($entity, $details);
        $responsePayload = [
            "response" => $result,
            "action" => "crud",
            "actionType" => "Read",
            "actionName" => $entity,
            "sessionId" => $sessionId
        ];
    } elseif ($actionType === "Update") {
        $success = updateCrudEntity($entity, $details);
        $responsePayload = [
            "response" => $success ? "Updated $entity successfully" : "Failed to update $entity",
            "action" => "crud",
            "actionType" => "Update",
            "actionName" => $entity,
            "sessionId" => $sessionId
        ];
    } elseif ($actionType === "Delete") {
        $success = deleteCrudEntity($entity, $details);
        $responsePayload = [
            "response" => $success ? "Deleted $entity successfully" : "Failed to delete $entity",
            "action" => "crud",
            "actionType" => "Delete",
            "actionName" => $entity,
            "sessionId" => $sessionId
        ];
    }
    $handled = true;
}

// 2. 📑 Reports (only if intent matches reportTypesSpec)
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
                "response" => "ℹ️ Report definition for $detectedReport.",
                "spec" => $reportTypesSpec[$detectedReport],
                "inputs" => ["rawPrompt" => $prompt],
                "sessionId" => $sessionId
            ];
            $handled = true;
        } else {
            $responsePayload = [
                "actionType" => "Create",
                "actionName" => "Report",
                "reportType" => $detectedReport,
                "response" => "ℹ️ Codex information about requested report type.",
                "details" => isset($reportTypesSpec[$detectedReport]) ? $reportTypesSpec[$detectedReport] : [],
                "sessionId" => $sessionId
            ];
            $handled = true;
        }
    }
}

// 3. 🧭 SemanticResponder (time, weather, KPIs, announcements, glossary, codex modules)
if (!$handled) {
    if (preg_match('/\b(show glossary|all glossary|list all terms|full glossary|show modules|list modules|all modules)\b/i', $lowerPrompt)) {
        handleCodexCommand(
            $lowerPrompt,
            $dynamicData,
            isset($injectBlocks['glossary']) ? json_encode($injectBlocks['glossary'], JSON_PRETTY_PRINT) : '',
            isset($injectBlocks['constitution']) ? json_encode($injectBlocks['constitution'], JSON_PRETTY_PRINT) : '',
            isset($injectBlocks['modules']) ? json_encode($injectBlocks['modules'], JSON_PRETTY_PRINT) : ''
        );
        $handled = true;
    } else {
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
}

// 4. 🌐 Google Search Fallback (if AI requests it or answer missing)
if (!$handled || (isset($aiResponse) && stripos($aiResponse, "NEEDS_GOOGLE_SEARCH") !== false)) {
    $searchResults = googleSearch($prompt);

    if (!empty($searchResults['items'][0]['snippet'])) {
        $best = $searchResults['items'][0];
        $responsePayload = [
            "response" => $best['snippet'] . " (via Google Search)",
            "link" => $best['link'],
            "action" => "answer",
            "sessionId" => $sessionId
        ];
    } else {
        $responsePayload = [
            "response" => "⚠️ Unable to resolve your query. Please try again.",
            "action" => "error",
            "sessionId" => $sessionId
        ];
    }
}

// 5. ✅ Final Output
if ($responsePayload) {
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    sendJsonResponse("❌ Unable to process request.", "error", ["sessionId" => $sessionId]);
}
#endregion

#region 🛠 Helper Functions
/**
 * Handle codex commands (glossary, modules, etc.)
 * @param string $prompt
 * @param array $dynamicData
 * @param string $codexGlossaryBlock
 * @param string $codexConstitutionBlock
 * @param string $codexModulesBlock
 */
function handleCodexCommand($prompt, $dynamicData, $codexGlossaryBlock, $codexConstitutionBlock, $codexModulesBlock) {
    $lowerPrompt = strtolower($prompt);
    
    if (preg_match('/\b(show glossary|all glossary|list all terms|full glossary)\b/i', $lowerPrompt)) {
        $displayed = array();
        $uniqueGlossary = "";
        foreach (explode("\n", $codexGlossaryBlock) as $line) {
            $key = strtolower(trim(strtok($line, ":")));
            if ($key && !isset($displayed[$key])) {
                $uniqueGlossary .= $line . "\n\n";
                $displayed[$key] = true;
            }
        }
        $formattedGlossary = nl2br(htmlspecialchars($uniqueGlossary));
        sendJsonResponse($formattedGlossary, "none", array("sessionId" => session_id()));
    } elseif (preg_match('/\b(show modules|list modules|all modules)\b/i', $lowerPrompt)) {
        $modulesArr = array();
        if (isset($dynamicData['readme']['modules']) && is_array($dynamicData['readme']['modules'])) {
            foreach ($dynamicData['readme']['modules'] as $mod) {
                if (isset($mod['name'], $mod['purpose']) && is_string($mod['name']) && is_string($mod['purpose'])) {
                    $modulesArr[] = $mod['name'] . ": " . $mod['purpose'];
                }
            }
        }
        $modulesDisplay = empty($modulesArr) ? "No modules found in Codex." : nl2br(htmlspecialchars(implode("\n\n", $modulesArr)));
        sendJsonResponse($modulesDisplay, "none", array("sessionId" => session_id()));
    } else {
        // Fallback to AI for semantic Codex queries
        $messages = [
            [
                "role" => "system",
                "content" => "Use the provided Codex data to answer semantically:\n\n" . json_encode($dynamicData['codex'] ?? [], JSON_PRETTY_PRINT)
            ],
            [
                "role" => "user",
                "content" => $prompt
            ]
        ];
        $response = callOpenAi($messages);
        sendJsonResponse($response, "none", array("sessionId" => session_id()));
    }
}

/**
 * Send JSON response with proper HTTP status code
 * @param mixed $response
 * @param string $action
 * @param array $extra
 * @param int $status
 */
function sendJsonResponse($response, $action = "none", $extra = array(), $status = 200) {
    http_response_code($status);
    $data = array_merge(array(
        "response" => is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response,
        "action" => $action,
        "sessionId" => session_id()
    ), $extra);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Authenticate a user (placeholder)
 * @param string $username
 * @param string $password
 * @return bool
 */
function authenticateUser($username, $password) {
    $validCredentials = array('admin' => password_hash('secret', PASSWORD_DEFAULT));
    return isset($validCredentials[$username]) && password_verify($password, $validCredentials[$username]);
}

/**
 * Create a new entity (placeholder)
 * @param string $entity
 * @param array $details
 * @return bool
 */
function createCrudEntity($entity, $details) {
    file_put_contents(__DIR__ . "/create_$entity.log", json_encode($details) . "\n", FILE_APPEND);
    return true;
}

/**
 * Read an entity based on criteria (placeholder)
 * @param string $entity
 * @param array $criteria
 * @return mixed
 */
function readCrudEntity($entity, $criteria) {
    return "Sample $entity details for: " . json_encode($criteria);
}

/**
 * Update an entity (placeholder)
 * @param string $entity
 * @param array $updates
 * @return bool
 */
function updateCrudEntity($entity, $updates) {
    file_put_contents(__DIR__ . "/update_$entity.log", json_encode($updates) . "\n", FILE_APPEND);
    return true;
}

/**
 * Delete an entity (placeholder)
 * @param string $entity
 * @param array $target
 * @return bool
 */
function deleteCrudEntity($entity, $target) {
    file_put_contents(__DIR__ . "/delete_$entity.log", json_encode($target) . "\n", FILE_APPEND);
    return true;
}

/**
 * Perform logout
 */
function performLogout() {
    session_unset();
    session_destroy();
    session_write_close();
    session_start();
    $newSessionId = session_id();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    setcookie('skyelogin_user', '', time() - 3600, '/', 'www.skyelighting.com');
}

/**
 * Normalize address
 * @param string $address
 * @return string
 */
function normalizeAddress($address) {
    $address = preg_replace('/\s+/', ' ', trim($address));
    $address = strtolower($address);
    $address = ucwords($address);
    $address = preg_replace_callback(
        '/\b(\d+)(St|Nd|Rd|Th)\b/i',
        function($matches) { return $matches[1] . strtolower($matches[2]); },
        $address
    );
    return $address;
}

/**
 * Get Assessor API URL for Arizona counties by FIPS code
 * @param string $stateFIPS
 * @param string $countyFIPS
 * @return string|null
 */
function getAssessorApi($stateFIPS, $countyFIPS) {
    if ($stateFIPS !== "04") return null;
    switch ($countyFIPS) {
        case "013": return "https://mcassessor.maricopa.gov/api";
        case "019": return "https://placeholder.pima.az.gov/api";
        default: return null;
    }
}

/**
 * Normalize Jurisdiction Names
 * @param string $jurisdiction
 * @param string|null $county
 * @return string|null
 */
function normalizeJurisdiction($jurisdiction, $county = null) {
    if (!$jurisdiction) return null;
    $jurisdiction = strtoupper(trim($jurisdiction));
    if ($jurisdiction === "NO CITY/TOWN") {
        return $county ? $county : "Unincorporated Area";
    }
    static $jurisdictions = null;
    if ($jurisdictions === null) {
        $path = __DIR__ . "/../assets/data/jurisdictions.json";
        if (file_exists($path)) {
            $jurisdictions = json_decode(file_get_contents($path), true);
        } else {
            $jurisdictions = array();
        }
    }
    foreach ($jurisdictions as $name => $info) {
        if (!empty($info['aliases'])) {
            foreach ($info['aliases'] as $alias) {
                if (strtoupper($alias) === $jurisdiction) {
                    return $name;
                }
            }
        }
    }
    return ucwords(strtolower($jurisdiction));
}

/**
 * Load and apply disclaimers for a report
 * @param string $reportType
 * @param array $context
 * @return array
 */
function getApplicableDisclaimers($reportType, $context = array()) {
    $file = __DIR__ . "/../assets/data/reportDisclaimers.json";
    if (!file_exists($file)) {
        return array("⚠️ Disclaimer library not found.");
    }
    $json = file_get_contents($file);
    $allDisclaimers = json_decode($json, true);
    if ($allDisclaimers === null) {
        return array("⚠️ Disclaimer library is invalid JSON.");
    }
    if (!isset($allDisclaimers[$reportType])) {
        return array("⚠️ No disclaimers defined for $reportType.");
    }
    $reportDisclaimers = $allDisclaimers[$reportType];
    $result = array();
    if (isset($reportDisclaimers['dataSources']) && is_array($reportDisclaimers['dataSources'])) {
        foreach ($reportDisclaimers['dataSources'] as $ds) {
            if (is_string($ds) && trim($ds) !== "") $result[] = $ds;
        }
    }
    if (is_array($context)) {
        foreach ($context as $key => $value) {
            if ($value && isset($reportDisclaimers[$key]) && is_array($reportDisclaimers[$key])) {
                $result = array_merge($result, $reportDisclaimers[$key]);
            }
        }
    }
    return empty($result) ? array("⚠️ No applicable disclaimers resolved.") : array_values(array_unique($result));
}

/**
 * Call OpenAI API with Google Search fallback
 * @param array $messages
 * @return string
 */
function callOpenAi($messages) {
    $apiKey = getenv("OPENAI_API_KEY");

    $dynamicPath = __DIR__ . "/dynamicData.json";
    $reportKeys = array();
    if (file_exists($dynamicPath)) {
        $dynamicData = json_decode(file_get_contents($dynamicPath), true);
        if (!empty($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])) {
            $reportKeys = array_keys($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec']);
        }
    }

    $model = "gpt-4o-mini";
    $lastUserMessage = end($messages);
    $promptText = isset($lastUserMessage['content']) ? strtolower($lastUserMessage['content']) : "";

    foreach ($reportKeys as $reportKey) {
        if (strpos($promptText, strtolower($reportKey)) !== false ||
            strpos($promptText, 'report') !== false) {
            $model = "gpt-4o";
            break;
        }
    }

    $payload = json_encode(array(
        "model" => $model,
        "messages" => $messages,
        "temperature" => 0.1,
        "max_tokens" => 200
    ), JSON_UNESCAPED_SLASHES);

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $response = curl_exec($ch);
    if ($response === false) {
        $curlError = curl_error($ch);
        file_put_contents(__DIR__ . '/error.log', "OpenAI API Curl Error: " . $curlError . "\n", FILE_APPEND);
        sendJsonResponse("❌ Curl error: " . $curlError, "none", array("sessionId" => session_id()));
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents(__DIR__ . '/error.log', "JSON Decode Error: " . json_last_error_msg() . "\nResponse: $response\n", FILE_APPEND);
        sendJsonResponse("❌ JSON decode error from AI.", "none", array("sessionId" => session_id()));
    }

    if (!isset($result["choices"][0]["message"]["content"])) {
        if (isset($result["error"]["message"])) {
            file_put_contents(__DIR__ . '/error.log', "OpenAI API Error: " . $result["error"]["message"] . "\nResponse: $response\n", FILE_APPEND);
            sendJsonResponse("❌ API error: " . $result["error"]["message"], "none", array("sessionId" => session_id()));
        } else {
            file_put_contents(__DIR__ . '/error.log', "Invalid OpenAI Response: " . $response . "\n", FILE_APPEND);
            sendJsonResponse("❌ Invalid response structure from AI.", "none", array("sessionId" => session_id()));
        }
    }

    return trim($result["choices"][0]["message"]["content"]);
}

/**
 * Perform a Google Custom Search API query.
 * @param string $query
 * @return array
 */
function googleSearch($query) {
    $apiKey = getenv("GOOGLE_SEARCH_KEY");
    $cx = getenv("GOOGLE_SEARCH_CX");

    if (!$apiKey || !$cx) {
        return ["error" => "Google Search API not configured."];
    }

    $url = "https://www.googleapis.com/customsearch/v1?q=" . urlencode($query) .
           "&key=" . $apiKey . "&cx=" . $cx;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || $err) {
        return ["error" => "Curl error: $err"];
    }

    $json = json_decode($res, true);
    if (!$json || isset($json['error'])) {
        return ["error" => isset($json['error']['message']) ? $json['error']['message'] : "Invalid response"];
    }

    return $json;
}
#endregion