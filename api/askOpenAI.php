<?php
// 📄 File: api/askOpenAI.php (Refactored with Routing Layer Pattern)

#region 🛡️ Headers and Setup
require_once __DIR__ . '/env_boot.php';
header("Content-Type: application/json");
date_default_timezone_set("America/Phoenix");
session_start();

// Load API key
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    sendJsonResponse("❌ API key not found.", "none", ["sessionId" => session_id()]);
    exit;
}
#endregion

#region 📨 Parse Input
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendJsonResponse("❌ Invalid JSON input.", "none", ["sessionId" => session_id()]);
    exit;
}

// Sanitize prompt
$prompt = isset($input["prompt"]) 
    ? trim(strip_tags(filter_var($input["prompt"], FILTER_UNSAFE_RAW))) 
    : "";
$conversation = isset($input["conversation"]) && is_array($input["conversation"]) ? $input["conversation"] : [];
$sseSnapshot = isset($input["sseSnapshot"]) && is_array($input["sseSnapshot"]) ? $input["sseSnapshot"] : [];

if (empty($prompt)) {
    sendJsonResponse("❌ Empty prompt.", "none", ["sessionId" => session_id()]);
    exit;
}

$lowerPrompt = strtolower($prompt);
#endregion

#region 📂 Load Codex and SSE Data
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codexData = [];
if (file_exists($codexPath)) {
    $codexRaw = file_get_contents($codexPath);
    $codexData = json_decode($codexRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents(__DIR__ . '/error.log', "Codex JSON Error: " . json_last_error_msg() . "\n", FILE_APPEND);
        $codexData = [];
    }
}
$sseRaw = @file_get_contents('https://www.skyelighting.com/skyesoft/api/getDynamicData.php');
$sseData = $sseRaw ? json_decode($sseRaw, true) : [];
if (json_last_error() !== JSON_ERROR_NONE) {
    file_put_contents(__DIR__ . '/error.log', "SSE JSON Error: " . json_last_error_msg() . "\n", FILE_APPEND);
    $sseData = [];
}
$skyebotSOT = ['codex' => $codexData, 'sse' => $sseData, 'other' => []];
file_put_contents(__DIR__ . '/debug-skyebotSOT.log', print_r($skyebotSOT, true));
#endregion

#region 📚 Build Codex Data
$codexGlossary = [];
$codexGlossaryAssoc = [];
if (isset($codexData['modules']['glossaryModule']['contents'])) {
    $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
}
if (isset($codexData['glossary']) && is_array($codexData['glossary'])) {
    $codexGlossaryAssoc = $codexData['glossary'];
}
$codexGlossaryBlock = "";
$codexTerms = [];
if (!empty($codexGlossary) && is_array($codexGlossary)) {
    foreach ($codexGlossary as $termDef) {
        if (strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
            $codexTerms[] = trim($def);
            $codexTerms[] = trim($term) . ": " . trim($def);
        } else {
            $codexGlossaryBlock .= $termDef . "\n";
            $codexTerms[] = trim($termDef);
        }
    }
}
if (!empty($codexGlossaryAssoc)) {
    foreach ($codexGlossaryAssoc as $term => $def) {
        $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
        $codexTerms[] = trim($def);
        $codexTerms[] = trim($term) . ": " . trim($def);
    }
}
$modulesArr = [];
if (isset($codexData['readme']['modules'])) {
    foreach ($codexData['readme']['modules'] as $mod) {
        if (isset($mod['name'], $mod['purpose'])) {
            $modulesArr[] = $mod['name'] . ": " . $mod['purpose'];
        }
    }
}
$codexOtherBlock = "";
$codexOtherTerms = [];
if (isset($codexData['version']['number'])) {
    $codexOtherBlock .= "Codex Version: " . $codexData['version']['number'] . "\n";
    $codexOtherTerms[] = $codexData['version']['number'];
    $codexOtherTerms[] = "Codex Version: " . $codexData['version']['number'];
}
if (isset($codexData['changelog'][0]['description'])) {
    $codexOtherBlock .= "Latest Changelog: " . $codexData['changelog'][0]['description'] . "\n";
    $codexOtherTerms[] = $codexData['changelog'][0]['description'];
    $codexOtherTerms[] = "Latest Changelog: " . $codexData['changelog'][0]['description'];
}
if (isset($codexData['readme']['title'])) {
    $codexOtherBlock .= "Readme Title: " . $codexData['readme']['title'] . "\n";
    $codexOtherTerms[] = $codexData['readme']['title'];
    $codexOtherTerms[] = "Readme Title: " . $codexData['readme']['title'];
}
if (isset($codexData['readme']['vision'])) {
    $codexOtherBlock .= "Vision: " . $codexData['readme']['vision'] . "\n";
    $codexOtherTerms[] = $codexData['readme']['vision'];
    $codexOtherTerms[] = "Vision: " . $codexData['readme']['vision'];
}
if ($modulesArr) {
    $modBlock = implode("\n", $modulesArr);
    $codexOtherBlock .= "Modules:\n" . $modBlock . "\n";
    $codexOtherTerms = array_merge($codexOtherTerms, $modulesArr);
}
if (isset($codexData['meta']['description'])) {
    $codexOtherBlock .= "Meta: " . $codexData['meta']['description'] . "\n";
    $codexOtherTerms[] = $codexData['meta']['description'];
    $codexOtherTerms[] = "Meta: " . $codexData['meta']['description'];
}
if (isset($codexData['constitution']['description'])) {
    $codexOtherBlock .= "Skyesoft Constitution: " . $codexData['constitution']['description'] . "\n";
    $codexOtherTerms[] = $codexData['constitution']['description'];
    $codexOtherTerms[] = "Skyesoft Constitution: " . $codexData['constitution']['description'];
}
if (isset($codexData['ragExplanation']['summary'])) {
    $codexOtherBlock .= "RAG Explanation: " . $codexData['ragExplanation']['summary'] . "\n";
    $codexOtherTerms[] = $codexData['ragExplanation']['summary'];
    $codexOtherTerms[] = "RAG Explanation: " . $codexData['ragExplanation']['summary'];
}
if (isset($codexData['includedDocuments']['summary'])) {
    $codexOtherBlock .= "Included Documents: " . $codexData['includedDocuments']['summary'] . "\n";
    $codexOtherTerms[] = $codexData['includedDocuments']['summary'];
    $codexOtherTerms[] = "Included Documents: " . $codexData['includedDocuments']['summary'];
    if (isset($codexData['includedDocuments']['documents'])) {
        $docList = implode(", ", $codexData['includedDocuments']['documents']);
        $codexOtherBlock .= "Documents: " . $docList . "\n";
        $codexOtherTerms[] = $docList;
        $codexOtherTerms[] = "Documents: " . $docList;
    }
}
if (isset($codexData['shared']['sourcesOfTruth'])) {
    $sotList = implode("; ", $codexData['shared']['sourcesOfTruth']);
    $codexOtherBlock .= "Sources of Truth: " . $sotList . "\n";
    $codexOtherTerms[] = $sotList;
    $codexOtherTerms[] = "Sources of Truth: " . $sotList;
}
if (isset($codexData['shared']['aiBehaviorRules'])) {
    $ruleList = implode(" | ", $codexData['shared']['aiBehaviorRules']);
    $codexOtherBlock .= "AI Behavior Rules: " . $ruleList . "\n";
    $codexOtherTerms[] = $ruleList;
    $codexOtherTerms[] = "AI Behavior Rules: " . $ruleList;
}
#endregion

#region 📊 Build SSE Snapshot Summary
$snapshotSummary = "";
$sseValues = [];
function flattenSse($arr, &$summary, &$values, $prefix = "") {
    foreach ($arr as $k => $v) {
        $key = $prefix ? "$prefix.$k" : $k;
        if (is_array($v)) {
            if (array_keys($v) === range(0, count($v) - 1)) {
                foreach ($v as $i => $entry) {
                    if (is_array($entry)) {
                        $title = isset($entry['title']) ? $entry['title'] : '';
                        $desc = isset($entry['description']) ? $entry['description'] : '';
                        $summary .= "$key[$i]: $title $desc\n";
                        $values[] = trim("$title $desc");
                    } else {
                        $summary .= "$key[$i]: $entry\n";
                        $values[] = $entry;
                    }
                }
            } else {
                flattenSse($v, $summary, $values, $key);
            }
        } else {
            $summary .= "$key: $v\n";
            $values[] = $v;
        }
    }
}
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    flattenSse($sseSnapshot, $snapshotSummary, $sseValues);
}
#endregion

#region 📝 Build System Prompt and Report Types
$reportTypesPath = '/home/notyou64/public_html/data/report_types.json';
if (!file_exists($reportTypesPath) || !is_readable($reportTypesPath)) {
    sendJsonResponse(
        "❌ Missing or unreadable report_types.json",
        "none",
        ["sessionId" => session_id(), "error" => "Missing or unreadable report_types.json", "path" => $reportTypesPath]
    );
    exit;
}

$reportTypesJson = file_get_contents($reportTypesPath);
if ($reportTypesJson === false) {
    sendJsonResponse(
        "❌ Unable to read report_types.json",
        "none",
        ["sessionId" => session_id(), "error" => "Unable to read report_types.json", "path" => $reportTypesPath]
    );
    exit;
}

$reportTypes = json_decode($reportTypesJson, true);
if (!is_array($reportTypes)) {
    sendJsonResponse(
        "❌ Failed to parse report_types.json",
        "none",
        ["sessionId" => session_id(), "error" => "JSON decode failed", "path" => $reportTypesPath]
    );
    exit;
}

$reportTypesBlock = json_encode($reportTypes);

$actionTypesArray = [
    "Create"  => ["Contact", "Order", "Application", "Location", "Login", "Logout", "Report"],
    "Read"    => ["Contact", "Order", "Application", "Location", "Report"],
    "Update"  => ["Contact", "Order", "Application", "Location", "Report"],
    "Delete"  => ["Contact", "Order", "Application", "Location", "Report"],
    "Clarify" => ["Options"]
];

$systemPrompt = <<<PROMPT
You are Skyebot™, an assistant for a signage company.  

You must always reply in valid JSON only — never text, markdown, or explanations.  

You have four sources of truth:  
- codexGlossary: internal company terms/definitions  
- codexOther: other company knowledge base items (version, modules, constitution, etc.)  
- sseSnapshot: current operational data (date, time, weather, KPIs, etc.)  
- reportTypes: standardized report templates  

---
## General Rules
- Responses must be one of these actionTypes: Create, Read, Update, Delete, Clarify.  
- Allowed actionNames: Contact, Order, Application, Location, Login, Logout, Report, Options.  
- If unsure, still return JSON — never plain text or scalar values (e.g., "8", "null").  
- If required values are missing, include them as empty strings ("").  
- If multiple interpretations exist, return a Clarify JSON object listing options.  

---
## Report Rules
When creating reports, always use this format:
{
  "actionType": "Create",
  "actionName": "Report",
  "details": {
    "reportType": "<one of reportTypes>",
    "title": "<auto-generate using titleTemplate + identifying field(s)>",
    "data": {
      // all requiredFields from report_types.json
      // optionalFields if provided
      // extra user-provided fields included as-is
    }
  }
}

- RequiredFields come from report_types.json.  
- Project-based reports must include projectName + address.  
- Non-project reports must use the relevant identifier (e.g., vehicleId, employeeId).  
- Titles must follow titleTemplate from report_types.json.  
- If projectName is missing, auto-generate as "Untitled Project – <address>".

---
## Glossary Rules
- If the user asks "What is …" or requests a definition/explanation of a known term  
  (from codexGlossary, codexOther, or sseSnapshot), return JSON in this form:
  {
    "actionType": "Read",
    "actionName": "Options",
    "details": {
      "term": "<the term>",
      "definition": "<the definition>"
    }
  }
- If the term is not found, return:
  {
    "actionType": "Read",
    "actionName": "Options",
    "details": {
      "term": "<user’s term>",
      "definition": "No information available."
    }
  }

---
## Logout Rules
- If the user says quit, exit, logout, log out, sign out, or end session, always return:
  {
    "actionType": "Create",
    "actionName": "Logout"
  }

---
codexGlossary:  
$codexGlossaryBlock  

codexOther:  
$codexOtherBlock  

sseSnapshot:  
$snapshotSummary  

reportTypes:  
$reportTypesBlock
PROMPT;
#endregion

#region 🎯 Routing Layer
$handled = false;

// --- Quick Agentic Actions ---
if (!$handled && (
    preg_match('/\blog\s*out\b|\blogout\b|\bexit\b|\bsign\s*out\b/i', $lowerPrompt) ||
    preg_match('/\blog\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+)/i', $lowerPrompt)
)) {
    handleQuickAction($lowerPrompt);
    $handled = true;
}

// --- SSE Direct Responses (time, date, weather) ---
if (!$handled && (
    preg_match('/\b(time|current time|local time|clock)\b/i', $lowerPrompt) ||
    preg_match('/\b(date|today.?s date|current date)\b/i', $lowerPrompt) ||
    preg_match('/\b(weather|forecast)\b/i', $lowerPrompt)
)) {
    handleSseShortcut($lowerPrompt, $sseSnapshot);
    $handled = true;
}

// --- Codex Commands (glossary, modules, constitution, etc.) ---
if (!$handled && (
    preg_match('/\b(show glossary|all glossary|list all terms|full glossary)\b/i', $lowerPrompt) ||
    preg_match('/\b(show modules|list modules|all modules)\b/i', $lowerPrompt) ||
    preg_match('/\b(mtco|lgbas|codex|constitution|version|vision|rag|documents|sources of truth|ai behavior)\b/i', $lowerPrompt)
)) {
    handleCodexCommand($lowerPrompt, $codexData, $codexGlossaryBlock, $codexOtherBlock, $codexTerms, $codexOtherTerms);
    $handled = true;
}

// --- Report Requests ---
if (!$handled && preg_match('/\b(zoning|report)\b/i', $lowerPrompt)) {
    handleReportRequest($lowerPrompt, $reportTypes, $conversation);
    $handled = true;
}

// --- Default: AI ---
if (!$handled) {
    $messages = [["role" => "system", "content" => $systemPrompt]];
    if (!empty($conversation)) {
        $history = array_slice($conversation, -2);
        foreach ($history as $entry) {
            if (isset($entry["role"], $entry["content"])) {
                $messages[] = ["role" => $entry["role"], "content" => $entry["content"]];
            }
        }
    }
    $messages[] = ["role" => "user", "content" => $prompt];
    $aiResponse = callOpenAi($messages);
    if (is_string($aiResponse) && trim($aiResponse) !== '') {
        $aiResponse .= " ⁂";
    }
    sendJsonResponse($aiResponse, "chat", ["sessionId" => session_id()]);
    exit;
}
#endregion

#region 🛠 Helper Functions
/**
 * Send JSON response with proper HTTP status code
 * @param string $response
 * @param string $action
 * @param array $extra
 * @param int $status
 */
function sendJsonResponse($response, $action = "none", $extra = [], $status = 200) {
    http_response_code($status);
    $data = array_merge([
        "response" => $response,
        "action" => $action,
        "sessionId" => session_id()
    ], $extra);
    echo json_encode($data);
    exit;
}

/**
 * Authenticate a user (placeholder)
 * @param string $username
 * @param string $password
 * @return bool
 */
function authenticateUser($username, $password) {
    $validCredentials = ['admin' => password_hash('secret', PASSWORD_DEFAULT)];
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
 * Handle quick login/logout actions
 * @param string $prompt
 */
function handleQuickAction($prompt) {
    $lowerPrompt = strtolower($prompt);
    if (preg_match('/\blog\s*out\b|\blogout\b|\bexit\b|\bsign\s*out\b/i', $lowerPrompt)) {
        session_unset();
        session_destroy();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                isset($params["httponly"]) ? $params["httponly"] : false
            );
        }
        setcookie('skyelogin_user', '', time() - 3600, '/', 'www.skyelighting.com');
        sendJsonResponse("You have been logged out (quick action).", "none", [
            "actionType" => "create",
            "actionName" => "logout",
            "sessionId" => session_id(),
            "loggedIn" => false
        ]);
    } elseif (preg_match('/\blog\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+)/i', $lowerPrompt, $matches)) {
        $username = $matches[1];
        $password = $matches[2];
        if (authenticateUser($username, $password)) {
            $_SESSION['user_id'] = $username;
            sendJsonResponse("Login successful (quick action).", "none", [
                "actionType" => "create",
                "actionName" => "login",
                "details" => ["username" => $username],
                "sessionId" => session_id(),
                "loggedIn" => true
            ]);
        } else {
            sendJsonResponse("Login failed (quick action).", "none", [
                "actionType" => "create",
                "actionName" => "login",
                "details" => ["username" => $username],
                "sessionId" => session_id(),
                "loggedIn" => false
            ]);
        }
    }
}

/**
 * Handle SSE shortcut responses (time, date, weather)
 * @param string $prompt
 * @param array $sseSnapshot
 */
function handleSseShortcut($prompt, $sseSnapshot) {
    $lowerPrompt = strtolower($prompt);
    if (
        preg_match('/\b(time|current time|local time|clock)\b/i', $lowerPrompt) &&
        strlen($lowerPrompt) < 40 &&
        isset($sseSnapshot['timeDateArray']['currentLocalTime'])
    ) {
        $tz = (isset($sseSnapshot['timeDateArray']['timeZone']) && $sseSnapshot['timeDateArray']['timeZone'] === "America/Phoenix") ? " MST" : "";
        sendJsonResponse($sseSnapshot['timeDateArray']['currentLocalTime'] . $tz, "none", ["sessionId" => session_id()]);
    } elseif (
        preg_match('/\b(date|today.?s date|current date)\b/i', $lowerPrompt) &&
        strlen($lowerPrompt) < 40 &&
        isset($sseSnapshot['timeDateArray']['currentDate'])
    ) {
        sendJsonResponse($sseSnapshot['timeDateArray']['currentDate'], "none", ["sessionId" => session_id()]);
    } elseif (
        preg_match('/\b(weather|forecast)\b/i', $lowerPrompt) &&
        strlen($lowerPrompt) < 40 &&
        isset($sseSnapshot['weatherData']['description'])
    ) {
        $temp = isset($sseSnapshot['weatherData']['temp']) ? $sseSnapshot['weatherData']['temp'] . "°F, " : "";
        $desc = $sseSnapshot['weatherData']['description'];
        sendJsonResponse($temp . $desc, "none", ["sessionId" => session_id()]);
    }
}

/**
 * Handle codex commands (glossary, modules, etc.)
 * @param string $prompt
 * @param array $codexData
 * @param string $codexGlossaryBlock
 * @param string $codexOtherBlock
 * @param array $codexTerms
 * @param array $codexOtherTerms
 */
function handleCodexCommand($prompt, $codexData, $codexGlossaryBlock, $codexOtherBlock, $codexTerms, $codexOtherTerms) {
    $lowerPrompt = strtolower($prompt);
    if (preg_match('/\b(show glossary|all glossary|list all terms|full glossary)\b/i', $lowerPrompt)) {
        $displayed = [];
        $uniqueGlossary = "";
        foreach (explode("\n", $codexGlossaryBlock) as $line) {
            $key = strtolower(trim(strtok($line, ":")));
            if ($key && !isset($displayed[$key])) {
                $uniqueGlossary .= $line . "\n\n";
                $displayed[$key] = true;
            }
        }
        $formattedGlossary = nl2br(htmlspecialchars($uniqueGlossary));
        sendJsonResponse($formattedGlossary, "none", ["sessionId" => session_id()]);
    } elseif (preg_match('/\b(show modules|list modules|all modules)\b/i', $lowerPrompt)) {
        $modulesArr = [];
        if (isset($codexData['readme']['modules'])) {
            foreach ($codexData['readme']['modules'] as $mod) {
                if (isset($mod['name'], $mod['purpose'])) {
                    $modulesArr[] = $mod['name'] . ": " . $mod['purpose'];
                }
            }
        }
        $modulesDisplay = empty($modulesArr) ? "No modules found in Codex." : nl2br(htmlspecialchars(implode("\n\n", $modulesArr)));
        sendJsonResponse($modulesDisplay, "none", ["sessionId" => session_id()]);
    } elseif (preg_match('/\b(mtco|lgbas|codex|constitution|version|vision|rag|documents|sources of truth|ai behavior)\b/i', $lowerPrompt)) {
        $allValid = array_merge($codexTerms, $codexOtherTerms);
        $isValid = false;
        $aiResponse = "";
        foreach ($allValid as $entry) {
            if (stripos($lowerPrompt, strtolower(trim($entry))) !== false) {
                $aiResponse = trim($entry);
                $isValid = true;
                break;
            }
        }
        if (!$isValid) {
            if (preg_match('/\b(mtco|lgbas|codex|constitution|glossary)\b/i', $lowerPrompt)) {
                $aiResponse = $codexGlossaryBlock !== "" ? trim($codexGlossaryBlock) : "No Codex glossary available.";
            } elseif (!empty($codexOtherBlock)) {
                $aiResponse = trim($codexOtherBlock);
            } else {
                $aiResponse = "No information available.";
            }
        }
        sendJsonResponse($aiResponse, "none", ["sessionId" => session_id()]);
    }
}

/**
 * Handle report requests
 * @param string $prompt
 * @param array $reportTypes
 * @param array $conversation
 */
function handleReportRequest($prompt, $reportTypes, $conversation) {
    $lowerPrompt = strtolower($prompt);
    $messages = [["role" => "system", "content" => $GLOBALS['systemPrompt']]];
    if (!empty($conversation)) {
        $history = array_slice($conversation, -2);
        foreach ($history as $entry) {
            if (isset($entry["role"], $entry["content"])) {
                $messages[] = ["role" => $entry["role"], "content" => $entry["content"]];
            }
        }
    }
    $messages[] = ["role" => "user", "content" => $prompt];
    $aiResponse = callOpenAi($messages);

    // Clean up possible code fences
    $cleanAiResponse = trim($aiResponse);
    $cleanAiResponse = preg_replace('/^```(?:json)?/i', '', $cleanAiResponse);
    $cleanAiResponse = preg_replace('/```$/', '', $cleanAiResponse);
    $cleanAiResponse = trim($cleanAiResponse);

    $crudData = json_decode($cleanAiResponse, true);
    if (!is_array($crudData)) {
        $crudData = [
            "actionType" => "Create",
            "actionName" => "Report",
            "details" => [
                "reportType" => "unknown",
                "title" => "Invalid Report",
                "data" => [
                    "projectName" => "Untitled Project",
                    "address" => "",
                    "parcel" => "",
                    "jurisdiction" => ""
                ]
            ]
        ];
        file_put_contents(__DIR__ . '/error.log', "AI response not in CRUD schema: " . $aiResponse . "\n", FILE_APPEND);
    }

    if (
        isset($crudData['actionName']) &&
        strtolower($crudData['actionName']) === 'report' &&
        isset($crudData['details']['reportType'], $crudData['details']['data'])
    ) {
        $prepResult = prepareReportData($crudData, $reportTypes);
        if (!$prepResult['valid']) {
            sendJsonResponse(
                "❌ Report preparation failed: " . implode("; ", $prepResult['errors']),
                "none",
                [
                    "sessionId" => session_id(),
                    "error" => "Preparation failed",
                    "details" => $prepResult['errors']
                ]
            );
        }

        $crudData = $prepResult['data'];
        $reportType = strtolower($crudData['details']['reportType']);
        $data = $crudData['details']['data'];

        $postFields = [
            "reportType" => $reportType,
            "reportData" => $data
        ];
        $ch = curl_init("https://www.skyelighting.com/skyesoft/api/generateReport.php");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $reportResult = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($reportResult === false) {
            file_put_contents(__DIR__ . '/error.log', "Report generation curl error: " . $curlError . "\n", FILE_APPEND);
            sendJsonResponse(
                "❌ Report generation failed due to curl error: " . $curlError,
                "none",
                ["sessionId" => session_id()]
            );
        }

        $reportJson = json_decode($reportResult, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            file_put_contents(__DIR__ . '/error.log', "Invalid JSON from report generation: " . json_last_error_msg() . "\nRaw: $reportResult\n", FILE_APPEND);
            sendJsonResponse(
                "❌ Report generation failed: Invalid JSON response",
                "none",
                ["sessionId" => session_id(), "error" => "Invalid JSON"]
            );
        }

        $reportUrl = isset($reportJson['details']['reportUrl']) ? $reportJson['details']['reportUrl'] : null;

        if (is_array($reportJson) && !empty($reportJson['success']) && $reportUrl) {
            sendJsonResponse(
                "📄 Report created successfully. <a href='" . htmlspecialchars($reportUrl, ENT_QUOTES, 'UTF-8') . "' target='_blank'>View Report</a>",
                "none",
                [
                    "sessionId" => session_id(),
                    "reportUrl" => $reportUrl,
                    "details" => $reportJson['details']
                ]
            );
        } else {
            file_put_contents(__DIR__ . '/error.log', "Report creation failed: " . json_encode($reportJson) . "\n", FILE_APPEND);
            sendJsonResponse(
                "❌ Report creation failed.",
                "none",
                [
                    "sessionId" => session_id(),
                    "error" => isset($reportJson['error']) ? $reportJson['error'] : "Unknown error",
                    "raw" => $reportJson
                ]
            );
        }
    } else {
        sendJsonResponse(
            "❌ Invalid report structure from AI.",
            "none",
            [
                "sessionId" => session_id(),
                "rawAiResponse" => $aiResponse
            ]
        );
    }
}

/**
 * Call OpenAI API
 * @param array $messages
 * @return string
 */
function callOpenAi($messages) {
    $apiKey = getenv("OPENAI_API_KEY");
    $payload = json_encode([
        "model" => "gpt-4",
        "messages" => $messages,
        "temperature" => 0.1,
        "max_tokens" => 200
    ], JSON_UNESCAPED_SLASHES);
    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $response = curl_exec($ch);
    if ($response === false) {
        $curlError = curl_error($ch);
        file_put_contents(__DIR__ . '/error.log', "OpenAI API Curl Error: " . $curlError . "\n", FILE_APPEND);
        sendJsonResponse("❌ Curl error: " . $curlError, "none", ["sessionId" => session_id()]);
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents(__DIR__ . '/error.log', "JSON Decode Error: " . json_last_error_msg() . "\nResponse: $response\n", FILE_APPEND);
        sendJsonResponse("❌ JSON decode error from AI.", "none", ["sessionId" => session_id()]);
    }

    if (!isset($result["choices"][0]["message"]["content"])) {
        if (isset($result["error"]["message"])) {
            file_put_contents(__DIR__ . '/error.log', "OpenAI API Error: " . $result["error"]["message"] . "\nResponse: $response\n", FILE_APPEND);
            sendJsonResponse("❌ API error: " . $result["error"]["message"], "none", ["sessionId" => session_id()]);
        } else {
            file_put_contents(__DIR__ . '/error.log', "Invalid OpenAI Response: " . $response . "\n", FILE_APPEND);
            sendJsonResponse("❌ Invalid response structure from AI.", "none", ["sessionId" => session_id()]);
        }
    }

    return trim($result["choices"][0]["message"]["content"]);
}

/**
 * Prepares and enriches report data
 * @param array $crudData
 * @param array $reportTypes
 * @return array
 */
function prepareReportData($crudData, $reportTypes) {
    $result = ['valid' => false, 'data' => [], 'errors' => []];

    if (
        !is_array($crudData) ||
        !isset($crudData['actionType'], $crudData['actionName'], $crudData['details']) ||
        $crudData['actionType'] !== 'Create' ||
        strtolower($crudData['actionName']) !== 'report' ||
        !isset($crudData['details']['reportType'], $crudData['details']['data'])
    ) {
        $result['errors'][] = 'Invalid CRUD structure for report generation.';
        return $result;
    }

    $reportType = strtolower($crudData['details']['reportType']);
    $data = $crudData['details']['data'];

    $reportTypeDef = null;
    foreach ($reportTypes as $typeDef) {
        if (isset($typeDef['reportType']) && strtolower($typeDef['reportType']) === $reportType) {
            $reportTypeDef = $typeDef;
            break;
        }
    }

    if (!$reportTypeDef) {
        $result['errors'][] = "Report type '$reportType' not found in report_types.json.";
        return $result;
    }

    $requiredFields = isset($reportTypeDef['requiredFields']) ? $reportTypeDef['requiredFields'] : [];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missingFields[] = $field;
        }
    }

    $enrichedData = $data;

    if (in_array('projectName', $requiredFields) && (!isset($enrichedData['projectName']) || $enrichedData['projectName'] === '')) {
        $address = isset($enrichedData['address']) ? $enrichedData['address'] : 'Unknown Address';
        $enrichedData['projectName'] = "Untitled Project – $address";
    }

    if (!isset($crudData['details']['title']) || $crudData['details']['title'] === '') {
        $titleTemplate = isset($reportTypeDef['titleTemplate']) ? $reportTypeDef['titleTemplate'] : '{reportType} Report – {projectName}';
        $title = $titleTemplate;
        $title = str_replace('{reportType}', ucfirst($reportType), $title);
        $title = str_replace('{projectName}', $enrichedData['projectName'] ?? 'Untitled Project', $title);
        $title = str_replace('{address}', $enrichedData['address'] ?? 'Unknown Address', $title);
        $crudData['details']['title'] = $title;
    }

    if ($reportType === 'zoning' && (!empty($missingFields) || !isset($enrichedData['parcel']) || !isset($enrichedData['jurisdiction']))) {
        if (!isset($enrichedData['address']) || $enrichedData['address'] === '') {
            $result['errors'][] = 'Address is required for zoning report enrichment.';
            return $result;
        }

        $parcelCh = curl_init('https://www.skyelighting.com/skyesoft/api/getParcel.php');
        curl_setopt($parcelCh, CURLOPT_POST, true);
        curl_setopt($parcelCh, CURLOPT_POSTFIELDS, http_build_query(['address' => $enrichedData['address']]));
        curl_setopt($parcelCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($parcelCh, CURLOPT_TIMEOUT, 10);
        $parcelResult = curl_exec($parcelCh);
        $curlError = curl_error($parcelCh);
        curl_close($parcelCh);

        if ($parcelResult === false) {
            $result['errors'][] = "Parcel lookup failed: $curlError";
            file_put_contents(__DIR__ . '/error.log', date('Y-m-d H:i:s') . " - Parcel lookup failed: $curlError\nAddress: {$enrichedData['address']}\n", FILE_APPEND);
        } else {
            $parcelJson = json_decode($parcelResult, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $result['errors'][] = 'Invalid JSON from parcel lookup: ' . json_last_error_msg();
                file_put_contents(__DIR__ . '/error.log', date('Y-m-d H:i:s') . " - Invalid JSON from parcel lookup: " . json_last_error_msg() . "\nRaw: $parcelResult\n", FILE_APPEND);
            } elseif (!is_array($parcelJson)) {
                $result['errors'][] = 'Parcel lookup returned non-array data.';
                file_put_contents(__DIR__ . '/error.log', date('Y-m-d H:i:s') . " - Parcel lookup returned non-array data: $parcelResult\n", FILE_APPEND);
            } else {
                if (empty($enrichedData['parcel']) && isset($parcelJson['parcel'])) {
                    $enrichedData['parcel'] = $parcelJson['parcel'];
                }
                if (empty($enrichedData['jurisdiction']) && isset($parcelJson['jurisdiction'])) {
                    $enrichedData['jurisdiction'] = $parcelJson['jurisdiction'];
                }
            }
        }
    }

    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($enrichedData[$field]) || $enrichedData[$field] === '') {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        $result['errors'] = array_merge($result['errors'], ["Missing required fields after enrichment: " . implode(', ', $missingFields)]);
        return $result;
    }

    $crudData['details']['data'] = $enrichedData;
    $result['valid'] = true;
    $result['data'] = $crudData;
    return $result;
}
#endregion