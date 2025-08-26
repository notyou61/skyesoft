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
    ? trim(strip_tags(filter_var($input["prompt"], FILTER_DEFAULT))) 
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

// 🧐 Glossary filter: only inject term if user asked "What is …"
$glossaryTerm = null;
if (preg_match('/\bwhat is\s+([a-z0-9\-]+)\??/i', strtolower($prompt), $matches)) {
    $glossaryTerm = strtoupper($matches[1]);
}

// If glossary filter is triggered
if ($glossaryTerm) {
    $filteredGlossary = [];
    $allGlossaryLines = explode("\n", $codexGlossaryBlock);

    foreach ($allGlossaryLines as $line) {
        if (stripos($line, $glossaryTerm) === 0) {
            $filteredGlossary[] = $line;
        }
    }

    // Only inject the requested entry
    $codexGlossaryBlock = !empty($filteredGlossary)
        ? implode("\n", $filteredGlossary)
        : "$glossaryTerm: No information available";
}
// System prompt
$systemPrompt = <<<PROMPT
You are Skyebot™, an assistant for a signage company.  

⚠️ CRITICAL RULES:
- For CRUD and Report actions → you must ALWAYS reply in valid JSON only.  
- For glossary lookups, date/time queries, and KPIs → reply in natural plain text.  
- For logout → always return JSON (never plain text).  
- Never return markdown, code fences, or mixed formats.  

You have four sources of truth:  
- codexGlossary: internal company terms/definitions  
- codexOther: other company knowledge base items (version, modules, constitution, etc.)  
- sseSnapshot: current operational data (date, time, weather, KPIs, etc.)  
- reportTypes: standardized report templates  

---
## Logout Rules
- If the user says quit, exit, logout, log out, sign out, or end session → 
- you must reply in plain text:
- "You have been logged out"
+ If the user says quit, exit, logout, log out, sign out, or end session →
+ always return this JSON object (nothing else, no text, no symbols):
+ {
+   "actionType": "Create",
+   "actionName": "Logout"
+ }


---
## CRUD + Report Rules
When creating reports or CRUD actions, always use JSON.  
Example Report format:
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
## Glossary + SSE Rules
- If the user asks "What is …" or for a definition:
-   → Return ONLY the definition for that specific term in plain text.
-   → Do NOT return the entire glossary unless the user explicitly asks
-      "show glossary" or "list all glossary terms."
+ If the user asks "What is …", "Define …", or provides a bare term (e.g., "MTCO"):
+   → Return ONLY the definition for that exact term in plain text.
+   → Do NOT include multiple terms unless the user explicitly says 
+     "show glossary" or "list all glossary terms".
+   → Do NOT repeat the same definition twice.
+   → If a term exists in both codexGlossary and codexOther, prefer the glossary entry.
+   → If the term is not found, reply: "<term>: No information available".

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
 * Perform logout (shared between quick action and CRUD)
 */
function performLogout() {
    session_unset();
    session_destroy();
    session_write_close();

    // start a new clean session ID for response
    session_start();
    $newSessionId = session_id();

    // cookie cleanup
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

    sendJsonResponse("You have been logged out", "none", [
        "actionType" => "Create",
        "actionName" => "Logout",
        "sessionId" => $newSessionId,
        "loggedIn" => false
    ]);
}
/**
 * Handle quick actions (AI JSON or raw text)
 */
function handleQuickAction($input) {
    $action = is_array($input) && isset($input['actionName'])
        ? strtolower($input['actionName'])
        : strtolower(trim($input));

    if (in_array($action, ['logout', 'sign out', 'signout', 'exit', 'quit'])) {
        performLogout();
        return;
    }
    if (in_array($action, ['login', 'sign in'])) {
        // login handler...
        return;
    }

    sendJsonResponse("Unknown quick action: $action", "none");
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
// Fix Ordinal Suffix (fix ordinal suffixes)
function fixOrdinalSuffix($matches) {
    return $matches[1] . strtolower($matches[2]);
}
// Normalize address (fix spacing, casing, suffixes)
function normalizeAddress($address) {
    $address = preg_replace('/\s+/', ' ', trim($address));
    $address = strtolower($address);
    $address = ucwords($address);

    // Use named callback (safe for PHP 5.6)
    $address = preg_replace_callback(
        '/\b(\d+)(St|Nd|Rd|Th)\b/i',
        'fixOrdinalSuffix',
        $address
    );

    return $address;
}
/**
 * Get Assessor API URL for Arizona counties by FIPS code
 */
function getAssessorApi($stateFIPS, $countyFIPS) {
    if ($stateFIPS !== "04") {
        return null; // Not Arizona
    }

    switch ($countyFIPS) {
        case "013": return "https://mcassessor.maricopa.gov/api";  // Maricopa (real)
        case "019": return "https://placeholder.pima.az.gov/api";  // Pima
        case "001": return "https://placeholder.apache.az.gov/api"; 
        case "003": return "https://placeholder.cochise.az.gov/api"; 
        case "005": return "https://placeholder.coconino.az.gov/api"; 
        case "007": return "https://placeholder.gila.az.gov/api"; 
        case "009": return "https://placeholder.graham.az.gov/api"; 
        case "011": return "https://placeholder.greenlee.az.gov/api"; 
        case "015": return "https://placeholder.mohave.az.gov/api"; 
        case "017": return "https://placeholder.navajo.az.gov/api"; 
        case "021": return "https://placeholder.pinal.az.gov/api"; 
        case "023": return "https://placeholder.santacruz.az.gov/api"; 
        case "025": return "https://placeholder.yavapai.az.gov/api"; 
        case "027": return "https://placeholder.yuma.az.gov/api"; 
        default:    return null;
    }
}
/**
 * Normalize Jurisdiction Names
 *
 * Converts assessor-provided jurisdiction values into consistent labels.
 *
 * @param string $jurisdiction  Raw jurisdiction string (e.g., "NO CITY/TOWN", "PHOENIX")
 * @param string|null $county   Optional county name (used for unincorporated mapping)
 * @return string|null          Normalized jurisdiction name
 */
function normalizeJurisdiction($jurisdiction, $county = null) {
    if (!$jurisdiction) return null;
    $jurisdiction = strtoupper(trim($jurisdiction));

    if ($jurisdiction === "NO CITY/TOWN") {
        return $county ? $county : "Unincorporated Area";
    }

    switch ($jurisdiction) {
        case "CITY OF PHOENIX":
        case "PHOENIX":
            return "Phoenix";
    }

    // Default: convert to Title Case
    return ucwords(strtolower($jurisdiction));
}
/**
 * Load and apply disclaimers for a report
 *
 * @param string $reportType  The type of report (e.g., "Zoning Report")
 * @param array  $context     Context flags (e.g., array('multipleParcels' => true, 'pucMismatch' => false))
 * @return array              Array of disclaimers applicable to this report
 */
function getApplicableDisclaimers($reportType, $context = array()) {
    // Load disclaimers from JSON file
    $file = __DIR__ . "/../assets/data/reportDisclaimers.json"; // adjust path if needed
    
    // Fail gracefully if file missing
    if (!file_exists($file)) {
        return array("⚠️ Disclaimer library not found.");
    }

    $json = file_get_contents($file);
    $allDisclaimers = json_decode($json, true);

    // Handle JSON parse errors
    if ($allDisclaimers === null) {
        return array("⚠️ Disclaimer library is invalid JSON.");
    }

    // Ensure the report type is defined
    if (!isset($allDisclaimers[$reportType])) {
        return array("⚠️ No disclaimers defined for " . $reportType . ".");
    }

    $reportDisclaimers = $allDisclaimers[$reportType];
    $result = array();

    // Always include "dataSources" if present
    if (isset($reportDisclaimers['dataSources']) && is_array($reportDisclaimers['dataSources'])) {
        foreach ($reportDisclaimers['dataSources'] as $ds) {
            if (is_string($ds) && trim($ds) !== "") {
                $result[] = $ds;
            }
        }
    }

    // Conditionally include others based on $context flags
    if (is_array($context)) {
        foreach ($context as $key => $value) {
            if ($value && isset($reportDisclaimers[$key])) {
                if (is_array($reportDisclaimers[$key])) {
                    foreach ($reportDisclaimers[$key] as $d) {
                        if (is_string($d) && trim($d) !== "") {
                            $result[] = $d;
                        }
                    }
                } elseif (is_string($reportDisclaimers[$key])) {
                    $result[] = $reportDisclaimers[$key];
                }
            }
        }
    }

    // Deduplicate disclaimers
    $result = array_values(array_unique($result));

    return $result;
}
/**
 * Handle AI report output (detect JSON vs plain text)
 */
function handleReportRequest($prompt, $reportTypes, &$conversation) {
    // ✅ Extract and normalize address
    $address = null;
    if (preg_match('/\d{3,5}\s+.*?\b\d{5}\b/', $prompt, $matches)) {
        $address = trim($matches[0]);
    } else {
        $address = trim($prompt);
    }
    $address = normalizeAddress($address);

    // Validation: require street number + 5-digit ZIP
    $hasStreetNum = preg_match('/\b\d{3,5}\b/', $address);
    $hasZip = preg_match('/\b\d{5}\b/', $address);
    if (!$hasStreetNum || !$hasZip) {
        $response = array(
            "error" => true,
            "response" => "⚠️ Please include both a street number and a 5-digit ZIP code to create a zoning report.",
            "providedInput" => $address
        );
        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }

    // ✅ Census Location API
    $locUrl = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress"
        . "?address=" . urlencode($address)
        . "&benchmark=Public_AR_Current&format=json";
    $locData = json_decode(@file_get_contents($locUrl), true);

    $county = null; $stateFIPS = null; $countyFIPS = null;
    $latitude = null; $longitude = null; $matchedAddress = null;

    if ($locData && isset($locData['result']['addressMatches'][0])) {
        $match = $locData['result']['addressMatches'][0];
        if (isset($match['matchedAddress'])) $matchedAddress = $match['matchedAddress'];
        if (isset($match['coordinates'])) {
            $longitude = $match['coordinates']['x'];
            $latitude  = $match['coordinates']['y'];
        }
    }

    // ✅ Census Geographies API
    $geoUrl = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress"
        . "?address=" . urlencode($address)
        . "&benchmark=Public_AR_Current&vintage=Current_Current&layers=all&format=json";
    $geoData = json_decode(@file_get_contents($geoUrl), true);

    if ($geoData && isset($geoData['result']['addressMatches'][0]['geographies']['Counties'][0])) {
        $countyData = $geoData['result']['addressMatches'][0]['geographies']['Counties'][0];
        $county     = isset($countyData['NAME']) ? $countyData['NAME'] : null;
        $stateFIPS  = isset($countyData['STATE']) ? $countyData['STATE'] : null;
        $countyFIPS = isset($countyData['COUNTY']) ? $countyData['COUNTY'] : null;
    }

    // ✅ Assessor API
    $assessorApi = getAssessorApi($stateFIPS, $countyFIPS);

    // ✅ Parcel lookup (Maricopa only for now)
    $parcels = array();
    if ($countyFIPS === "013" && $stateFIPS === "04" && $matchedAddress) {
        $shortAddress = preg_replace('/,.*$/', '', $matchedAddress);
        $gisUrl = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query";
        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . strtoupper($shortAddress) . "%')";
        $params = "f=json&where=" . urlencode($where) . "&outFields=APN,PHYSICAL_ADDRESS,OWNER_NAME&returnGeometry=false";
        $gisData = json_decode(@file_get_contents($gisUrl . "?" . $params), true);

        if ($gisData && isset($gisData['features']) && is_array($gisData['features'])) {
            foreach ($gisData['features'] as $feature) {
                $apn   = isset($feature['attributes']['APN']) ? $feature['attributes']['APN'] : null;
                $situs = isset($feature['attributes']['PHYSICAL_ADDRESS']) ? $feature['attributes']['PHYSICAL_ADDRESS'] : null;
                $owner = isset($feature['attributes']['OWNER_NAME']) ? $feature['attributes']['OWNER_NAME'] : null;

                // Parcel details
                $details = null; $jurisdiction = null; $lotSize = null; $puc = null;
                $subdivision = null; $mcr = null; $lot = null; $tractBlock = null;
                $floor = null; $yearBuilt = null; $str = null; $attrs = array();

                if ($apn) {
                    $detailsUrl = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
                        . "?f=json&where=APN='" . urlencode($apn) . "'&outFields=*&returnGeometry=false";
                    $detailsJson = @file_get_contents($detailsUrl);
                    $detailsData = json_decode($detailsJson, true);

                    if ($detailsData && isset($detailsData['features'][0]['attributes'])) {
                        $attrs = $detailsData['features'][0]['attributes'];
                        $details       = $attrs;
                        $jurisdiction  = isset($attrs['JURISDICTION']) ? $attrs['JURISDICTION'] : null;
                        $lotSize       = isset($attrs['LAND_SIZE']) ? $attrs['LAND_SIZE'] : null;
                        $puc           = isset($attrs['PUC']) ? $attrs['PUC'] : null;
                        $subdivision   = isset($attrs['SUBNAME']) ? $attrs['SUBNAME'] : null;
                        $mcr           = isset($attrs['MCRNUM']) ? $attrs['MCRNUM'] : null;
                        $lot           = isset($attrs['LOT_NUM']) ? $attrs['LOT_NUM'] : null;
                        $tractBlock    = isset($attrs['TRACT']) ? $attrs['TRACT'] : null;
                        $floor         = isset($attrs['FLOOR']) ? $attrs['FLOOR'] : null;
                        $yearBuilt     = isset($attrs['CONST_YEAR']) ? $attrs['CONST_YEAR'] : null;
                        $str           = isset($attrs['STR']) ? $attrs['STR'] : null;
                    }
                }

                $parcels[] = array(
                    "apn" => $apn,
                    "situs" => $situs,
                    "owner" => $owner,
                    "jurisdiction" => $jurisdiction,
                    "lotSizeSqFt" => $lotSize,
                    "puc" => $puc,
                    "subdivision" => $subdivision,
                    "mcr" => $mcr,
                    "lot" => $lot,
                    "tractBlock" => $tractBlock,
                    "floor" => $floor,
                    "constructionYear" => $yearBuilt,
                    "str" => $str,
                    "detailsRaw" => $details
                );
            }
        }
    }

    // ✅ Context for disclaimers
    $context = array(
        "multipleParcels" => (count($parcels) > 1),
        "unsupportedJurisdiction" => false,
        "pucMismatch" => false,
        "splitZoning" => false
    );

    // Jurisdiction check (for now, only Phoenix supported)
    if (count($parcels) > 0) {
        $j = strtoupper(trim($parcels[0]['jurisdiction']));
        if ($j !== "PHOENIX") {
            $context['unsupportedJurisdiction'] = true;
        }
    }

    // PUC vs Zoning mismatch check
    if (count($parcels) > 0) {
        $puc = isset($parcels[0]['puc']) ? $parcels[0]['puc'] : null;
        $zone = null;

        $detailsRaw = isset($parcels[0]['detailsRaw']) ? $parcels[0]['detailsRaw'] : null;
        if (is_array($detailsRaw) && isset($detailsRaw['CITY_ZONING'])) {
            $zone = $detailsRaw['CITY_ZONING'];
        }

        if ($puc && $zone && $puc !== $zone) {
            $context['pucMismatch'] = true;
        }
    }
    // ✅ Pull disclaimers dynamically
    $disclaimers = getApplicableDisclaimers("Zoning Report", $context);
    // ✅ Response
    $response = array(
        "error" => false,
        "response" => "📄 Zoning report request created for " . $address . ".",
        "actionType" => "Create",
        "reportType" => "Zoning Report",
        "inputs" => array(
            "address" => $address,
            "matchedAddress" => $matchedAddress,
            "county" => $county,
            "stateFIPS" => $stateFIPS,
            "countyFIPS" => $countyFIPS,
            "latitude" => $latitude,
            "longitude" => $longitude,
            "assessorApi" => $assessorApi,
            "parcels" => $parcels
        ),
        "disclaimers" => array("Zoning Report" => $disclaimers)
    );
    // Header and output
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
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
        $title = str_replace(
            '{projectName}',
            isset($enrichedData['projectName']) ? $enrichedData['projectName'] : 'Untitled Project',
            $title
        );
        $title = str_replace('{address}', isset($enrichedData['address']) ? $enrichedData['address'] : 'Unknown Address', $title);
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
/**
 * Fallback report logic handler (stub for now).
 * This prevents fatal errors when quick actions are not triggered.
 * Extend this with your real CRUD/report logic as needed.
 */
function runReportLogic($aiResponse) {
    sendJsonResponse($aiResponse, "report", ["sessionId" => session_id()]);
}
#endregion