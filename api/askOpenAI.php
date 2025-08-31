<?php
// 📄 File: api/askOpenAI.php

#region 📦 Filter Parcels Helper
/**
 * Filters down the parcels returned by the Assessor API to the most relevant ones.
 * 1. Normalizes the input address.
 * 2. Matches parcels with the same street number + name.
 * 3. Keeps multi-parcel sites if they share the same address.
 * 4. Falls back to nearest parcel centroid if no direct match is found.
 */
function filterParcels($parcels, $inputAddress, $latitude, $longitude) {
    if (empty($parcels)) return array();

    // Normalize address
    $inputAddress = normalizeAddress($inputAddress);

    // Extract street number + name from input
    $m = array();
    preg_match(
        '/^([0-9]+)\s+([A-Z\s]+)\s+(RD|ROAD|AVE|AVENUE|BLVD|BOULEVARD|ST|STREET|DR|DRIVE|LN|LANE|PL|PLACE|WAY|CT|COURT)\b/i',
        $inputAddress,
        $m
    );
    $num  = isset($m[1]) ? $m[1] : null;
    $name = isset($m[2]) ? trim(strtoupper($m[2])) : null;

    $filtered = array();
    foreach ($parcels as $p) {
        $situs = strtoupper(trim(isset($p["situs"]) ? $p["situs"] : ""));
        $sm = array();
        if (preg_match('/^([0-9]+)\s+([A-Z\s]+)/', $situs, $sm)) {
            if ($num && $name && $sm[1] === $num && trim($sm[2]) === $name) {
                $filtered[] = $p;
            }
        }
    }

    // Fallback: centroid-based proximity (<= 5m)
    if (empty($filtered) && $latitude && $longitude) {
        $closest = null; $min = PHP_INT_MAX;
        foreach ($parcels as $p) {
            if (isset($p["geometry"]["coordinates"]["rings"][0])) {
                $coords = $p["geometry"]["coordinates"]["rings"][0];
                $sumLat = 0; $sumLon = 0; $count = count($coords);
                foreach ($coords as $pt) {
                    $sumLon += $pt[0]; $sumLat += $pt[1];
                }
                if ($count > 0) {
                    $cLon = $sumLon / $count;
                    $cLat = $sumLat / $count;
                    $dLat = deg2rad($cLat - $latitude);
                    $dLon = deg2rad($cLon - $longitude);
                    $a = sin($dLat/2)*sin($dLat/2) +
                         cos(deg2rad($latitude)) * cos(deg2rad($cLat)) *
                         sin($dLon/2)*sin($dLon/2);
                    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
                    $dist = 6371000 * $c;
                    if ($dist < $min && $dist <= 5) {
                        $min = $dist;
                        $closest = $p;
                    }
                }
            }
        }
        if ($closest) $filtered = array($closest);
    }

    return $filtered;
}

#endregion

#region 🛡️ Input & Session Bootstrap
// Read and decode input once
$data = json_decode(file_get_contents("php://input"), true);

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    @session_start(); // @ suppresses "already started" notice
}

// Handle invalid JSON early
if ($data === null) {
    echo json_encode([
        "response"  => "❌ Invalid or empty JSON payload.",
        "action"    => "none",
        "sessionId" => session_id()
    ], JSON_PRETTY_PRINT);
    exit;
}

// Load jurisdiction zoning lookup helper
include_once __DIR__ . "/jurisdictionZoning.php";
#endregion

#region 🛡️ Headers and Setup
require_once __DIR__ . '/env_boot.php';
header("Content-Type: application/json");
date_default_timezone_set("America/Phoenix");

// Load API key
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    sendJsonResponse("❌ API key not found.", "none", ["sessionId" => session_id()]);
    exit;
}
#endregion

#region 📨 Parse Input
// Use $data from bootstrap region
$prompt = isset($data["prompt"]) 
    ? trim(strip_tags(filter_var($data["prompt"], FILTER_DEFAULT))) 
    : "";
$conversation = isset($data["conversation"]) && is_array($data["conversation"]) ? $data["conversation"] : [];
$sseSnapshot = isset($data["sseSnapshot"]) && is_array($data["sseSnapshot"]) ? $data["sseSnapshot"] : [];

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
// Initialize variables as empty strings to ensure type safety
$codexGlossaryBlock = '';
$codexOtherBlock = '';
$codexGlossary = [];
$codexGlossaryAssoc = [];
$codexTerms = [];
$codexOtherTerms = [];

if (isset($codexData['modules']['glossaryModule']['contents']) && is_array($codexData['modules']['glossaryModule']['contents'])) {
    $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
}
if (isset($codexData['glossary']) && is_array($codexData['glossary'])) {
    $codexGlossaryAssoc = $codexData['glossary'];
}

if (!empty($codexGlossary)) {
    foreach ($codexGlossary as $termDef) {
        if (!is_string($termDef)) continue; // Skip non-string entries
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
        if (!is_string($term) || !is_string($def)) continue; // Ensure string types
        $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
        $codexTerms[] = trim($def);
        $codexTerms[] = trim($term) . ": " . trim($def);
    }
}
if (empty($codexGlossaryBlock)) {
    $codexGlossaryBlock = "No glossary data available.\n";
}

$modulesArr = [];
if (isset($codexData['readme']['modules']) && is_array($codexData['readme']['modules'])) {
    foreach ($codexData['readme']['modules'] as $mod) {
        if (isset($mod['name'], $mod['purpose']) && is_string($mod['name']) && is_string($mod['purpose'])) {
            $modulesArr[] = $mod['name'] . ": " . $mod['purpose'];
        }
    }
}

if (isset($codexData['version']['number']) && is_string($codexData['version']['number'])) {
    $codexOtherBlock .= "Codex Version: " . $codexData['version']['number'] . "\n";
    $codexOtherTerms[] = $codexData['version']['number'];
    $codexOtherTerms[] = "Codex Version: " . $codexData['version']['number'];
}
if (isset($codexData['changelog'][0]['description']) && is_string($codexData['changelog'][0]['description'])) {
    $codexOtherBlock .= "Latest Changelog: " . $codexData['changelog'][0]['description'] . "\n";
    $codexOtherTerms[] = $codexData['changelog'][0]['description'];
    $codexOtherTerms[] = "Latest Changelog: " . $codexData['changelog'][0]['description'];
}
if (isset($codexData['readme']['title']) && is_string($codexData['readme']['title'])) {
    $codexOtherBlock .= "Readme Title: " . $codexData['readme']['title'] . "\n";
    $codexOtherTerms[] = $codexData['readme']['title'];
    $codexOtherTerms[] = "Readme Title: " . $codexData['readme']['title'];
}
if (isset($codexData['readme']['vision']) && is_string($codexData['readme']['vision'])) {
    $codexOtherBlock .= "Vision: " . $codexData['readme']['vision'] . "\n";
    $codexOtherTerms[] = $codexData['readme']['vision'];
    $codexOtherTerms[] = "Vision: " . $codexData['readme']['vision'];
}
if ($modulesArr) {
    $modBlock = implode("\n", $modulesArr);
    $codexOtherBlock .= "Modules:\n" . $modBlock . "\n";
    $codexOtherTerms = array_merge($codexOtherTerms, $modulesArr);
}
if (isset($codexData['meta']['description']) && is_string($codexData['meta']['description'])) {
    $codexOtherBlock .= "Meta: " . $codexData['meta']['description'] . "\n";
    $codexOtherTerms[] = $codexData['meta']['description'];
    $codexOtherTerms[] = "Meta: " . $codexData['meta']['description'];
}
if (isset($codexData['constitution']['description']) && is_string($codexData['constitution']['description'])) {
    $codexOtherBlock .= "Skyesoft Constitution: " . $codexData['constitution']['description'] . "\n";
    $codexOtherTerms[] = $codexData['constitution']['description'];
    $codexOtherTerms[] = "Skyesoft Constitution: " . $codexData['constitution']['description'];
}
if (isset($codexData['ragExplanation']['summary']) && is_string($codexData['ragExplanation']['summary'])) {
    $codexOtherBlock .= "RAG Explanation: " . $codexData['ragExplanation']['summary'] . "\n";
    $codexOtherTerms[] = $codexData['ragExplanation']['summary'];
    $codexOtherTerms[] = "RAG Explanation: " . $codexData['ragExplanation']['summary'];
}
if (isset($codexData['includedDocuments']['summary']) && is_string($codexData['includedDocuments']['summary'])) {
    $codexOtherBlock .= "Included Documents: " . $codexData['includedDocuments']['summary'] . "\n";
    $codexOtherTerms[] = $codexData['includedDocuments']['summary'];
    $codexOtherTerms[] = "Included Documents: " . $codexData['includedDocuments']['summary'];
    if (isset($codexData['includedDocuments']['documents']) && is_array($codexData['includedDocuments']['documents'])) {
        $docList = implode(", ", array_filter($codexData['includedDocuments']['documents'], 'is_string'));
        $codexOtherBlock .= "Documents: " . $docList . "\n";
        $codexOtherTerms[] = $docList;
        $codexOtherTerms[] = "Documents: " . $docList;
    }
}
if (isset($codexData['shared']['sourcesOfTruth']) && is_array($codexData['shared']['sourcesOfTruth'])) {
    $sotList = implode("; ", array_filter($codexData['shared']['sourcesOfTruth'], 'is_string'));
    $codexOtherBlock .= "Sources of Truth: " . $sotList . "\n";
    $codexOtherTerms[] = $sotList;
    $codexOtherTerms[] = "Sources of Truth: " . $sotList;
}
if (isset($codexData['shared']['aiBehaviorRules']) && is_array($codexData['shared']['aiBehaviorRules'])) {
    $ruleList = implode(" | ", array_filter($codexData['shared']['aiBehaviorRules'], 'is_string'));
    $codexOtherBlock .= "AI Behavior Rules: " . $ruleList . "\n";
    $codexOtherTerms[] = $ruleList;
    $codexOtherTerms[] = "AI Behavior Rules: " . $ruleList;
}
if (empty($codexOtherBlock)) {
    $codexOtherBlock = "No additional codex data available.\n";
}
#endregion

#region 📊 Build SSE Snapshot Summary
$snapshotSummary = '';
$sseValues = [];
function flattenSse($arr, &$summary, &$values, $prefix = "") {
    foreach ($arr as $k => $v) {
        $key = $prefix ? "$prefix.$k" : $k;
        if (is_array($v)) {
            if (array_keys($v) === range(0, count($v) - 1)) {
                foreach ($v as $i => $entry) {
                    if (is_array($entry)) {
                        $title = isset($entry['title']) && is_string($entry['title']) ? $entry['title'] : '';
                        $desc = isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : '';
                        $summary .= "$key[$i]: $title $desc\n";
                        $values[] = trim("$title $desc");
                    } else {
                        if (is_string($entry)) {
                            $summary .= "$key[$i]: $entry\n";
                            $values[] = $entry;
                        }
                    }
                }
            } else {
                flattenSse($v, $summary, $values, $key);
            }
        } else {
            if (is_string($v)) {
                $summary .= "$key: $v\n";
                $values[] = $v;
            }
        }
    }
}
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    flattenSse($sseSnapshot, $snapshotSummary, $sseValues);
}
if (empty($snapshotSummary)) {
    $snapshotSummary = "No SSE snapshot data available.\n";
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

$reportTypesBlock = json_encode($reportTypes, JSON_UNESCAPED_SLASHES);
if (!is_string($reportTypesBlock)) {
    $reportTypesBlock = "No report types data available.\n";
}

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
        : "$glossaryTerm: No information available\n";
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
- always return this JSON object (nothing else, no text, no symbols):
- {
-   "actionType": "Create",
-   "actionName": "Logout"
- }

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
- If the user asks "What is …", "Define …", or provides a bare term (e.g., "MTCO"):
-   → Return ONLY the definition for that exact term in plain text.
-   → Do NOT include multiple terms unless the user explicitly says 
-      "show glossary" or "list all glossary terms".
-   → Do NOT repeat the same definition twice.
-   → If a term exists in both codexGlossary and codexOther, prefer the glossary entry.
-   → If the term is not found, reply: "<term>: No information available".

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

    // Start a new clean session ID for response
    session_start();
    $newSessionId = session_id();

    // Cookie cleanup
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
        if (isset($codexData['readme']['modules']) && is_array($codexData['readme']['modules'])) {
            foreach ($codexData['readme']['modules'] as $mod) {
                if (isset($mod['name'], $mod['purpose']) && is_string($mod['name']) && is_string($mod['purpose'])) {
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
 * Fix Ordinal Suffix (fix ordinal suffixes)
 */
function fixOrdinalSuffix($matches) {
    return $matches[1] . strtolower($matches[2]);
}
/**
 * Normalize address (fix spacing, casing, suffixes)
 */
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
 * @param string $reportType  The type of report (e.g., "Zoning Report")
 * @param array  $context     Context flags (e.g., array('multipleParcels' => true, 'pucMismatch' => false))
 * @return array              Array of disclaimers applicable to this report
 */
function getApplicableDisclaimers($reportType, $context = array()) {
    $file = __DIR__ . "/../assets/data/reportDisclaimers.json";
    if (!file_exists($file)) {
        file_put_contents(__DIR__ . '/error.log',
            date('Y-m-d H:i:s') . " - Disclaimer file missing: $file\n",
            FILE_APPEND
        );
        return array("⚠️ Disclaimer library not found.");
    }

    $json = file_get_contents($file);
    $allDisclaimers = json_decode($json, true);
    if ($allDisclaimers === null) {
        file_put_contents(__DIR__ . '/error.log',
            date('Y-m-d H:i:s') . " - Disclaimer JSON parse error: " . json_last_error_msg() . "\n",
            FILE_APPEND
        );
        return array("⚠️ Disclaimer library is invalid JSON.");
    }

    if (!isset($allDisclaimers[$reportType])) {
        file_put_contents(__DIR__ . '/error.log',
            date('Y-m-d H:i:s') . " - No disclaimers defined for report type: $reportType\n",
            FILE_APPEND
        );
        return array("⚠️ No disclaimers defined for " . $reportType . ".");
    }

    $reportDisclaimers = $allDisclaimers[$reportType];
    $result = array();

    if (isset($reportDisclaimers['dataSources']) && is_array($reportDisclaimers['dataSources'])) {
        foreach ($reportDisclaimers['dataSources'] as $ds) {
            if (is_string($ds) && trim($ds) !== "") {
                $result[] = $ds;
            }
        }
    }

    if (is_array($context)) {
        foreach ($context as $key => $value) {
            if ($value && isset($reportDisclaimers[$key]) && is_array($reportDisclaimers[$key])) {
                $result = array_merge($result, $reportDisclaimers[$key]);
            }
        }
    }

    if (empty($result)) {
        return array("⚠️ No applicable disclaimers resolved.");
    }

    return array_values(array_unique($result));
}
/**
 * 📦 Parcel Lookup Helper (PHP 5.6 compatible)
 */
function lookupParcels($inputAddress, $zip = null, $latitude = null, $longitude = null) {
    $normalized = strtoupper($inputAddress);
    $shortAddress = preg_replace('/,.*$/', '', $normalized);

    $status = "none";
    $features = array();

    // --- Helper to run GIS query ---
    $runQuery = function($where, $label) {
        $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
            . "?f=json&where=" . urlencode($where)
            . "&outFields=APN,PHYSICAL_ADDRESS,OWNER_NAME,PHYSICAL_ZIP&returnGeometry=false&outSR=4326";
        $resp = json_decode(@file_get_contents($url), true);
        return isset($resp['features']) ? $resp['features'] : array();
    };

    // Step 1: Full address + ZIP
    $where1 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')";
    if ($zip) $where1 .= " AND PHYSICAL_ZIP = '" . $zip . "'";
    $features = $runQuery($where1, "Step 1");
    if (!empty($features)) $status = "exact";

    // Step 2: Relax suffix
    if (empty($features)) {
        $relaxed = preg_replace('/\s(BLVD|ROAD|RD|DR|DRIVE|STREET|ST|AVE|AVENUE)\b/i', '', $shortAddress);
        $where2 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $relaxed . "%')";
        if ($zip) $where2 .= " AND PHYSICAL_ZIP = '" . $zip . "'";
        $features = $runQuery($where2, "Step 2");
        if (!empty($features)) $status = "exact";
    }

    // Step 3: Fuzzy street match
    if (empty($features) && $zip) {
        $streetOnly = trim(preg_replace('/^\d+/', '', $shortAddress));
        $where3 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $streetOnly . "%') AND PHYSICAL_ZIP = '" . $zip . "'";
        $features = $runQuery($where3, "Step 3");
        if (!empty($features)) $status = "fuzzy";
    }

    // Step 4: Last resort — full address no ZIP
    if (empty($features)) {
        $where4 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')";
        $features = $runQuery($where4, "Step 4");
        if (!empty($features)) $status = "fuzzy";
    }

    // Build matches (with jurisdiction lookup)
    $matches = array();
    foreach ($features as $f) {
        $a = $f['attributes'];
        $apn = isset($a['APN']) ? $a['APN'] : null;

        // Default jurisdiction = null
        $jurisdiction = null;

        // Pull jurisdiction from detailed Assessor lookup if APN is available
        if ($apn) {
            $detailsUrl = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
                . "?f=json&where=APN='" . urlencode($apn) . "'&outFields=*&returnGeometry=false";
            $detailsJson = @file_get_contents($detailsUrl);
            $detailsData = json_decode($detailsJson, true);

            if ($detailsData && isset($detailsData['features'][0]['attributes']['JURISDICTION'])) {
                $jurisdiction = strtoupper(trim($detailsData['features'][0]['attributes']['JURISDICTION']));
            }
        }

        $matches[] = array(
            "apn"          => $apn,
            "situs"        => isset($a['PHYSICAL_ADDRESS']) ? trim($a['PHYSICAL_ADDRESS']) : null,
            "zip"          => isset($a['PHYSICAL_ZIP']) ? $a['PHYSICAL_ZIP'] : null,
            "jurisdiction" => $jurisdiction
        );
    }

    return array(
        "parcelStatus" => $status,
        "matches"      => $matches
    );
}

/**
 * Handle AI report output (detect JSON vs plain text)
 */
function handleReportRequest($prompt, $reportTypes, &$conversation) {
    // ✅ Extract and normalize address
    $address = null;
    $cleanPrompt = preg_replace(
        '/\b(zoning|permit|report|lookup|check|for|at|create|make|please)\b/i',
        '',
        $prompt
    );
    if (preg_match('/\d{1,5}[^,]+(?:,[^,]+){0,2}\b\d{5}\b/', $cleanPrompt, $matches)) {
        $address = trim($matches[0]);
    } else {
        $address = trim($cleanPrompt);
    }
    $address = normalizeAddress($address);

    // ✅ Validation: require street number + 5-digit ZIP
    $hasStreetNum = preg_match('/\b\d{3,5}\b/', $address);
    $hasZip       = preg_match('/\b\d{5}\b/', $address);
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

    // ✅ Census Geographies API (primary)
    $geoUrl = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress"
        . "?address=" . urlencode($address)
        . "&benchmark=Public_AR_Current&vintage=Current_Current&layers=all&format=json";
    $geoData = json_decode(@file_get_contents($geoUrl), true);

    if ($geoData && isset($geoData['result']['addressMatches'][0]['geographies']['Counties'][0])) {
        $countyData = $geoData['result']['addressMatches'][0]['geographies']['Counties'][0];
        $county     = isset($countyData['NAME']) ? $countyData['NAME'] : null;
        $stateFIPS  = isset($countyData['STATE']) ? $countyData['STATE'] : null;
        $countyFIPS = isset($countyData['COUNTY']) ? $countyData['COUNTY'] : null;
    } else {
        // 🚨 Census failed → fallback to Google Geocoding API
        $googleKey = getenv("GOOGLE_MAPS_BACKEND_API_KEY");
        if ($googleKey) {
            $googleUrl = "https://maps.googleapis.com/maps/api/geocode/json"
                . "?address=" . urlencode($address)
                . "&key=" . $googleKey;
            $googleData = json_decode(@file_get_contents($googleUrl), true);

            if ($googleData && isset($googleData['results'][0])) {
                $gResult = $googleData['results'][0];
                if (!$matchedAddress && isset($gResult['formatted_address'])) {
                    $matchedAddress = strtoupper($gResult['formatted_address']);
                }
                if (isset($gResult['geometry']['location'])) {
                    $latitude  = $gResult['geometry']['location']['lat'];
                    $longitude = $gResult['geometry']['location']['lng'];
                }
                foreach ($gResult['address_components'] as $comp) {
                    if (in_array("administrative_area_level_2", $comp['types'])) {
                        $county = $comp['long_name'];
                    }
                    if (in_array("administrative_area_level_1", $comp['types'])) {
                        $state = $comp['short_name'];
                    }
                }
                if ($county === "Maricopa County" && $state === "AZ") {
                    $stateFIPS  = "04";
                    $countyFIPS = "013";
                }
            }
        }
    }

    // ✅ Set assessorApi
    $assessorApi = getAssessorApi($stateFIPS, $countyFIPS);

    // ✅ Ensure ZIP in matchedAddress
    if ($matchedAddress && !preg_match('/\b\d{5}\b/', $matchedAddress)) {
        if (preg_match('/\b\d{5}\b/', $address, $zipMatch)) {
            $matchedAddress .= " " . $zipMatch[0];
        }
    }

    // ✅ Parcel lookup (Maricopa only)
    $parcels = array();
    $parcelStatus = "none";
    // Normalize jurisdiction
    if ($countyFIPS === "013" && $stateFIPS === "04" && $matchedAddress) {
        preg_match('/\b\d{5}\b/', $matchedAddress, $zipMatch);
        $zip = isset($zipMatch[0]) ? $zipMatch[0] : null;

        $result = lookupParcels($matchedAddress, $zip, $latitude, $longitude);
        $parcelStatus = $result["parcelStatus"];
        // Build parcels array with normalized jurisdiction
        foreach ($result["matches"] as $m) {
            $parcels[] = array(
                "apn"          => $m["apn"],
                "situs"        => $m["situs"],
                "jurisdiction" => $m["jurisdiction"], // ✅ from Assessor
                "zip"          => $m["zip"]
            );
        }
    }

    // ✅ Jurisdiction zoning lookup (only if we have parcels)
    if (count($parcels) > 0 && !empty($parcels[0]['jurisdiction'])) {
        foreach ($parcels as $k => $parcel) {
            $lat = $latitude;
            $lon = $longitude;
            if (!empty($parcel['geometry']['coordinates']['rings'][0])) {
                $coords = $parcel['geometry']['coordinates']['rings'][0];
                $sumLat = 0; $sumLon = 0; $count = count($coords);
                foreach ($coords as $pt) { $sumLon += $pt[0]; $sumLat += $pt[1]; }
                if ($count > 0) {
                    $lon = $sumLon / $count;
                    $lat = $sumLat / $count;
                }
            }
            $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning(
                $parcel['jurisdiction'], $lat, $lon,
                isset($parcel['geometry']) ? $parcel['geometry'] : null
            );
        }
    }

    // ✅ Context for disclaimers
    $context = array(
        "multipleParcels"         => (count($parcels) > 1),
        "unsupportedJurisdiction" => false,
        "pucMismatch"             => false,
        "splitZoning"             => false
    );

    if (count($parcels) > 0) {
        $j = strtoupper(trim($parcels[0]['jurisdiction']));
        $context[strtolower($j)] = true;
    }
    if ($parcelStatus === "fuzzy") {
        $context["fuzzyMatch"] = true;
    }
    if ($parcelStatus === "none") {
        $context["noParcel"] = true;
    }

    // ✅ Disclaimers
    $disclaimers = getApplicableDisclaimers("Zoning Report", $context);

    // ✅ Response
    $response = array(
        "error"      => false,
        "response"   => "📄 Zoning report request created for " . $address . ".",
        "actionType" => "Create",
        "reportType" => "Zoning Report",
        "inputs"     => array(
            "address"        => $address,
            "matchedAddress" => $matchedAddress,
            "county"         => $county,
            "stateFIPS"      => $stateFIPS,
            "countyFIPS"     => $countyFIPS,
            "latitude"       => $latitude,
            "longitude"      => $longitude,
            "assessorApi"    => $assessorApi,
            "parcelStatus"   => $parcelStatus, // ✅ new
            "parcels"        => $parcels
        ),
        "disclaimers" => array("Zoning Report" => $disclaimers)
    );

    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;

}

    /**
     * Filter parcels by address match, with proximity fallback
     * @param array $parcels Array of parcels from Assessor API
     * @param string $inputAddress Normalized input address (e.g., "3931 S Gilbert Rd, Gilbert, AZ 85295")
     * @param float|null $latitude Geocoded latitude
     * @param float|null $longitude Geocoded longitude
     * @return array Filtered parcels
     */
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
        $title = str_replace('{reportType}', ucfirst($reportType), $titleTemplate);
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
 * Fallback report logic handler.
 * Processes AI response for report generation and ensures JSON output.
 * Extend this with real CRUD/report logic as needed.
 * @param string $aiResponse The response from OpenAI or other processing
 * @return void
 */
function runReportLogic($aiResponse) {
    sendJsonResponse($aiResponse, "report", ["sessionId" => session_id()]);
}
#endregion