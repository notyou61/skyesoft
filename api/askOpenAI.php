<?php
// 📄 File: api/askOpenAI.php (PHP 5.6 Compatible, Optimized for IDE)
// Force JSON output
header('Content-Type: application/json');

// Debug logger
function debugLog($message, $context = array()) {
    $logDir  = __DIR__ . "/../logs"; // this resolves to /home/notyou64/public_html/skyesoft/logs
    $logFile = $logDir . "/skyesoft-debug.log";

    // Ensure logs directory exists
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $entry = date("Y-m-d H:i:s") . " | " . $message;
    if (!empty($context)) {
        $entry .= " | " . json_encode($context);
    }
    $entry .= "\n";

    file_put_contents($logFile, $entry, FILE_APPEND);
}

// ✅ Test log entry when script runs
debugLog("✅ askOpenAI.php started", [
    "script" => __FILE__,
    "phpVersion" => phpversion()
]);


// 🛡️ Dependency Checks
#region Dependency Checks 
if (!extension_loaded('curl')) {
    logError("CURL extension not loaded.");
    sendJsonResponse("❌ CURL extension required.", "none", array("sessionId" => session_id()));
}

if (!file_exists(__DIR__ . '/env_boot.php')) {
    logError("env_boot.php not found.");
    sendJsonResponse("❌ env_boot.php not found.", "none", array("sessionId" => session_id()));
}
require_once __DIR__ . '/env_boot.php';
#endregion

// 🛠 Session Configuration
#region Session Configuration
ini_set('session.cookie_secure', 1); // HTTPS only, if applicable
ini_set('session.use_only_cookies', 1);
ini_set('session.httponly', 1);
session_start();
#endregion

// 📡 Headers and Setup
#region Headers and Setup
header("Content-Type: application/json");
date_default_timezone_set("America/Phoenix");

// Load API key
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    sendJsonResponse("❌ API key not found.", "none", array("sessionId" => session_id()));
}

// Initialize logging
$logFile = __DIR__ . '/error.log';
/**
 * Log errors to file
 * @param string $message
 * @param array $context
 */
function logError($message, $context = array()) {
    global $logFile;
    $logEntry = date('Y-m-d H:i:s') . " - $message\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
#endregion

// 📨 Parse Input
#region Parse Input
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendJsonResponse("❌ Invalid JSON input.", "none", array("sessionId" => session_id()));
}

// Sanitize prompt and validate inputs
$prompt = isset($input["prompt"]) 
    ? trim(htmlspecialchars(strip_tags(filter_var($input["prompt"], FILTER_UNSAFE_RAW)), ENT_QUOTES, 'UTF-8')) 
    : "";
$conversation = isset($input["conversation"]) && is_array($input["conversation"]) ? $input["conversation"] : [];
$sseSnapshot = isset($input["sseSnapshot"]) && is_array($input["sseSnapshot"]) ? $input["sseSnapshot"] : [];

if (empty($prompt)) {
    sendJsonResponse("❌ Empty prompt.", "none", array("sessionId" => session_id()));
}

$lowerPrompt = strtolower($prompt);

// Basic address validation
$addressRegex = '/^\d+\s+[A-Za-z0-9\s]+,\s*[A-Za-z\s]+,\s*[A-Z]{2}\s*\d{5}(-\d{4})?$/';
$isAddress = preg_match($addressRegex, trim($prompt));
#endregion

// ⚡️ Quick Agentic Actions
#region Quick Agentic Actions 
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
    sendJsonResponse("You have been logged out (quick action).", "none", array(
        "actionType" => "create",
        "actionName" => "logout",
        "sessionId" => session_id(),
        "loggedIn" => false
    ));
}

if (preg_match('/\blog\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+)/i', $lowerPrompt, $matches)) {
    $username = $matches[1];
    $password = $matches[2];
    if (authenticateUser($username, $password)) {
        $_SESSION['user_id'] = $username;
        sendJsonResponse("Login successful (quick action).", "none", array(
            "actionType" => "create",
            "actionName" => "login",
            "details" => array("username" => $username),
            "sessionId" => session_id(),
            "loggedIn" => true
        ));
    } else {
        sendJsonResponse("Login failed (quick action).", "none", array(
            "actionType" => "create",
            "actionName" => "login",
            "details" => array("username" => $username),
            "sessionId" => session_id(),
            "loggedIn" => false
        ));
    }
}
#endregion

// 📂 Load Codex and SSE Data
#region Load Codex and SSE Data 
/**
 * Load and parse codex data
 * @return array
 */
function loadCodexData() {
    $codexPath = __DIR__ . '/../docs/codex/codex.json';
    $codexData = array();
    if (file_exists($codexPath)) {
        $codexRaw = file_get_contents($codexPath);
        $codexData = json_decode($codexRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logError("Codex JSON Error: " . json_last_error_msg(), array("path" => $codexPath));
            $codexData = array();
        }
    }
    return $codexData;
}

/**
 * Load and parse SSE data
 * @return array
 */
function loadSseData() {
    $sseRaw = @file_get_contents('https://www.skyelighting.com/skyesoft/api/getDynamicData.php');
    $sseData = $sseRaw ? json_decode($sseRaw, true) : array();
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("SSE JSON Error: " . json_last_error_msg(), array("raw" => $sseRaw));
        $sseData = array();
    }
    return $sseData;
}

$codexData = loadCodexData();
$sseData = loadSseData();
$skyebotSOT = array('codex' => $codexData, 'sse' => $sseData, 'other' => array());
file_put_contents(__DIR__ . '/debug-skyebotSOT.log', print_r($skyebotSOT, true));
#endregion

// 🚦 Direct Responses for Time/Date/Weather
#region Direct Responses for Time/Date/Weather
if (
    preg_match('/\b(time|current time|local time|clock)\b/i', $lowerPrompt) &&
    strlen($lowerPrompt) < 40 &&
    isset($sseSnapshot['timeDateArray']['currentLocalTime'])
) {
    $tz = (isset($sseSnapshot['timeDateArray']['timeZone']) && $sseSnapshot['timeDateArray']['timeZone'] === "America/Phoenix") ? " MST" : "";
    sendJsonResponse($sseSnapshot['timeDateArray']['currentLocalTime'] . $tz, "none", array("sessionId" => session_id()));
}

if (
    preg_match('/\b(date|today.?s date|current date)\b/i', $lowerPrompt) &&
    strlen($lowerPrompt) < 40 &&
    isset($sseSnapshot['timeDateArray']['currentDate'])
) {
    sendJsonResponse($sseSnapshot['timeDateArray']['currentDate'], "none", array("sessionId" => session_id()));
}

if (
    preg_match('/\b(weather|forecast)\b/i', $lowerPrompt) &&
    strlen($lowerPrompt) < 40 &&
    isset($sseSnapshot['weatherData']['description'])
) {
    $temp = isset($sseSnapshot['weatherData']['temp']) ? $sseSnapshot['weatherData']['temp'] . "°F, " : "";
    $desc = $sseSnapshot['weatherData']['description'];
    sendJsonResponse($temp . $desc, "none", array("sessionId" => session_id()));
}
#endregion

// 📚 Build Codex Data
#region Build Codex Data 
/**
 * Build codex glossary block and terms
 * @param array $codexData
 * @return array
 */
function buildCodexGlossary($codexData) {
    $codexGlossary = array();
    $codexGlossaryAssoc = array();
    if (isset($codexData['modules']['glossaryModule']['contents'])) {
        $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
    }
    if (isset($codexData['glossary']) && is_array($codexData['glossary'])) {
        $codexGlossaryAssoc = $codexData['glossary'];
    }
    $codexGlossaryBlock = "";
    $codexTerms = array();
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
    return array('block' => $codexGlossaryBlock, 'terms' => $codexTerms);
}

/**
 * Build codex other block and terms
 * @param array $codexData
 * @return array
 */
function buildCodexOther($codexData) {
    $modulesArr = array();
    if (isset($codexData['readme']['modules'])) {
        foreach ($codexData['readme']['modules'] as $mod) {
            if (isset($mod['name'], $mod['purpose'])) {
                $modulesArr[] = $mod['name'] . ": " . $mod['purpose'];
            }
        }
    }
    $codexOtherBlock = "";
    $codexOtherTerms = array();
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
    return array('block' => $codexOtherBlock, 'terms' => $codexOtherTerms);
}

$codexGlossaryData = buildCodexGlossary($codexData);
$codexGlossaryBlock = $codexGlossaryData['block'];
$codexTerms = $codexGlossaryData['terms'];
$codexOtherData = buildCodexOther($codexData);
$codexOtherBlock = $codexOtherData['block'];
$codexOtherTerms = $codexOtherData['terms'];
#endregion

// 📊 Build SSE Snapshot Summary
#region Build SSE Snapshot Summary 
/**
 * Build SSE snapshot summary
 * @param array $sseSnapshot
 * @return array
 */
function buildSseSummary($sseSnapshot) {
    $snapshotSummary = "";
    $sseValues = array();
    /**
     * Flatten SSE array recursively
     * @param array $arr
     * @param string $summary
     * @param array $values
     * @param string $prefix
     * @param int $depth
     * @param int $maxDepth
     */
    function flattenSse($arr, &$summary, &$values, $prefix = "", $depth = 0, $maxDepth = 10) {
        if ($depth >= $maxDepth) {
            $summary .= "$prefix: [Truncated due to depth limit]\n";
            return;
        }
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
                    flattenSse($v, $summary, $values, $key, $depth + 1, $maxDepth);
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
    return array('summary' => $snapshotSummary, 'values' => $sseValues);
}

$sseSummaryData = buildSseSummary($sseSnapshot);
$snapshotSummary = $sseSummaryData['summary'];
$sseValues = $sseSummaryData['values'];
#endregion

// 📋 User Codex Commands
#region User Codex Commands
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
    sendJsonResponse(nl2br(htmlspecialchars($uniqueGlossary)), "none", array("sessionId" => session_id()));
}

if (preg_match('/\b(show modules|list modules|all modules)\b/i', $lowerPrompt)) {
    $modulesArr = array();
    if (isset($codexData['readme']['modules'])) {
        foreach ($codexData['readme']['modules'] as $mod) {
            if (isset($mod['name'], $mod['purpose'])) {
                $modulesArr[] = $mod['name'] . ": " . $mod['purpose'];
            }
        }
    }
    $modulesDisplay = empty($modulesArr) ? "No modules found in Codex." : nl2br(htmlspecialchars(implode("\n\n", $modulesArr)));
    sendJsonResponse($modulesDisplay, "none", array("sessionId" => session_id()));
}
#endregion

// 📝 Build System Prompt and Report Types
#region Build System Prompt and Report Types

// Define paths for report types
$localPath  = __DIR__ . "/../../data/report_types.json"; // dev environment
$serverPath = "/home/notyou64/public_html/data/report_types.json"; // GoDaddy live

// Resolve reportTypes path
if (file_exists($localPath)) {
    $reportTypesPath = $localPath;
} elseif (file_exists($serverPath)) {
    $reportTypesPath = $serverPath;
} else {
    echo json_encode(array(
        "response"  => "❌ Missing or unreadable report_types.json",
        "action"    => "none",
        "sessionId" => session_id(),
        "error"     => "File not found",
        "checked"   => array($localPath, $serverPath)
    ));
    exit;
}

// Load and validate report_types.json
$reportTypesJson = file_get_contents($reportTypesPath);
$reportTypes     = json_decode($reportTypesJson, true);

if (!is_array($reportTypes)) {
    echo json_encode(array(
        "response"  => "❌ Failed to parse report_types.json",
        "action"    => "none",
        "sessionId" => session_id(),
        "error"     => "JSON decode failed",
        "path"      => $reportTypesPath
    ));
    exit;
}

// Build clarifyOptions dynamically
$clarifyOptions = array();
foreach ($reportTypes as $type) {
    if (isset($type['reportType'])) {
        $clarifyOptions[] = $type['reportType'];
    }
}

// Action types
$actionTypesArray = array(
    "Create"  => array("Contact", "Order", "Application", "Location", "Login", "Logout", "Report"),
    "Read"    => array("Contact", "Order", "Application", "Location", "Report"),
    "Update"  => array("Contact", "Order", "Application", "Location", "Report"),
    "Delete"  => array("Contact", "Order", "Application", "Location", "Report"),
    "Clarify" => array("Options")
);

// Convert reportTypes to JSON for inclusion in system prompt
$reportTypesBlock = json_encode($reportTypes);

debugLog("Resolving report_types.json", ["localPath" => $localPath, "serverPath" => $serverPath]);
debugLog("Using path", ["reportTypesPath" => $reportTypesPath]);
debugLog("Decoded reportTypes", $reportTypes);

// Build system prompt safely as double-quoted string (variables interpolate)
$systemPrompt = "
You are Skyebot™, an assistant for the signage company Skyelighting.  

You must always reply in valid JSON only — never text, markdown, or explanations.  

You have four sources of truth:  
- codexGlossary: internal company terms/definitions  
- codexOther: other company knowledge base items (version, modules, constitution, etc.)  
- sseSnapshot: current operational data (date, time, weather, KPIs, etc.)  
- reportTypes: standardized report templates (from report_types.json)  

---
## General Rules
- Responses must be one of these: Create, Read, Update, Delete, Clarify.  
- Allowed actionNames: Contact, Order, Application, Location, Login, Logout, Report, Options.  
- If unsure, still return JSON — never plain text.  
- If required values are missing, include them as empty strings (\"\").  
- If multiple interpretations exist, return a Clarify JSON object listing options.  

---
## Report Rules
When creating reports, always use this format:
{
  \"actionType\": \"Create\",
  \"actionName\": \"Report\",
  \"details\": {
    \"reportType\": \"<one of reportTypes>\",
    \"title\": \"<auto-generate using titleTemplate + identifying field(s)>\",
    \"data\": {
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

---
## Glossary Rules
- If the user asks \"What is …\" or requests a definition/explanation of a known term  
  (from codexGlossary, codexOther, or sseSnapshot), return JSON in this form:

  {
    \"actionType\": \"Read\",
    \"actionName\": \"Options\",
    \"details\": {
      \"term\": \"<the term>\",
      \"definition\": \"<the definition>\"
    }
  }

- If the term is not found, return:
  {
    \"actionType\": \"Read\",
    \"actionName\": \"Options\",
    \"details\": {
      \"term\": \"<user’s term>\",
      \"definition\": \"No information available.\"
    }
  }

---
## Logout Rules
- If the user says quit, exit, logout, log out, sign out, or end session, always return:

  {
    \"actionType\": \"Create\",
    \"actionName\": \"Logout\"
  }

---
codexGlossary:  
$codexGlossaryBlock  

codexOther:  
$codexOtherBlock  

sseSnapshot:  
$snapshotSummary  

reportTypes (from report_types.json):  
$reportTypesBlock
";

#endregion

// 📄 Report Generation Hook
#region Report Generation Hook
$crudData = array();

if (isset($aiResponse) && !empty($aiResponse)) {
    $decoded = json_decode($aiResponse, true);

    if (is_array($decoded)) {
        // ✅ AI returned valid JSON object
        $crudData = $decoded;
    } else {
        // 🚨 AI returned invalid or scalar JSON ("4", "null", etc.)
        $crudData = array(
            "actionType" => "Create",
            "actionName" => "Report",
            "details" => array(
                "reportType" => "unknown",
                "title" => "Invalid Report",
                "data" => array(
                    "projectName" => "Untitled Project",
                    "address" => "",
                    "parcel" => "",
                    "jurisdiction" => ""
                )
            )
        );
        logError("AI response not in CRUD schema: " . $aiResponse);
    }
}

// ✅ Only proceed if actionName = Report
if (
    is_array($crudData) &&
    isset($crudData['actionName']) &&
    strtolower($crudData['actionName']) === 'report' &&
    isset($crudData['details']['reportType']) &&
    isset($crudData['details']['data'])
) {
    $reportType = strtolower($crudData['details']['reportType']);
    $data       = $crudData['details']['data'];

    // 🔍 Validate required fields
    $validation = validateReportData($reportType, $data, $reportTypes);
    if (!$validation['valid']) {
        sendJsonResponse(
            "❌ Missing required fields: " . implode(", ", $validation['missing']) . " (needed for $reportType report).",
            "none",
            array("sessionId" => session_id(), "error" => "Validation failed")
        );
    }

    // Prepare cURL call to generator
    $postFields = array(
        "reportType" => $reportType,
        "reportData" => $data
    );
    $ch = curl_init("https://www.skyelighting.com/skyesoft/api/generateReport.php");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $reportResult = curl_exec($ch);
    if ($reportResult === false) {
        logError("Report generation curl error: " . curl_error($ch));
        sendJsonResponse("❌ Report generation failed due to curl error.", "none", array("sessionId" => session_id()));
    }
    curl_close($ch);

    // Parse result
    $reportJson = json_decode($reportResult, true);
    $reportUrl  = (is_array($reportJson) && isset($reportJson['details']['reportUrl']))
        ? $reportJson['details']['reportUrl']
        : null;

    if (is_array($reportJson) && !empty($reportJson['success']) && $reportUrl) {
        sendJsonResponse(
            "📄 Report created successfully. <a href='" . htmlspecialchars($reportUrl, ENT_QUOTES, 'UTF-8') . "' target='_blank'>View Report</a>",
            "none",
            array("sessionId" => session_id(), "reportUrl" => $reportUrl, "details" => $reportJson['details'])
        );
    } else {
        sendJsonResponse(
            "❌ Report creation failed.",
            "none",
            array(
                "sessionId" => session_id(),
                "error" => isset($reportJson['error']) ? $reportJson['error'] : "Unknown error",
                "raw"   => $reportJson
            )
        );
    }
    exit;
}
#endregion

// 💬 Build OpenAI Message Array
#region Build OpenAI Message Array
$messages = array(array("role" => "system", "content" => $systemPrompt));
if (!empty($conversation)) {
    $history = array_slice($conversation, -2);
    foreach ($history as $entry) {
        if (isset($entry["role"], $entry["content"])) {
            $messages[] = array("role" => $entry["role"], "content" => $entry["content"]);
        }
    }
}
$messages[] = array("role" => "user", "content" => $prompt);

#endregion

// 🚀 OpenAI API Request
#region OpenAI API Request

// Load model (defaults to gpt-5 if not set)
$model = getenv("OPENAI_MODEL") ?: "gpt-5";

// Prepare payload
$payload = json_encode(array(
    "model" => $model,   // ✅ use the variable instead of hardcoding
    "messages" => $messages,
    "temperature" => 0.1,
    "max_completion_tokens" => 300
), JSON_UNESCAPED_SLASHES);

// Initialize cURL
$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Content-Type: application/json",
    "Authorization: " . "Bearer " . $apiKey
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

// Retry logic
$maxRetries = 3;
$retryDelay = 1;
for ($i = 0; $i < $maxRetries; $i++) {
    $response = curl_exec($ch);
    if ($response !== false) break;
    logError("OpenAI API Curl Error: " . curl_error($ch), array("attempt" => $i + 1));
    if ($i < $maxRetries - 1) {
        sleep($retryDelay);
        $retryDelay *= 2;
    }
}

if ($response === false) {
    $curlError = curl_error($ch);
    logError("OpenAI API Curl Error after retries: " . $curlError);
    sendJsonResponse("❌ Curl error: " . $curlError, "none", array("sessionId" => session_id()));
    curl_close($ch);
    exit;
}
curl_close($ch);

// Decode API response
$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logError("JSON Decode Error: " . json_last_error_msg(), array("response" => $response));
    sendJsonResponse("❌ JSON decode error from AI.", "none", array("sessionId" => session_id()));
    exit;
}

// Validate structure
if (!isset($result["choices"][0]["message"]["content"])) {
    $errorMsg = isset($result["error"]["message"]) ? $result["error"]["message"] : "Invalid response structure";
    logError("OpenAI API Error: " . $errorMsg, array("response" => $response));
    sendJsonResponse("❌ API error: " . $errorMsg, "none", array("sessionId" => session_id()));
    exit;
}

// Extract response safely
$aiResponse = $result["choices"][0]["message"]["content"];

#endregion

// 📄 Report Generation Hook
#region Report Generation Hook
$crudData = json_decode($aiResponse, true);
if (
    is_array($crudData) &&
    isset($crudData['actionName']) &&
    strtolower($crudData['actionName']) === 'report' &&
    isset($crudData['details']['reportType'], $crudData['details']['data'])
) {
    // 📄 Report Validation
    /**
     * Validate report data against required fields
     * @param string $reportType
     * @param array $data
     * @param array $reportTypes
     * @return array
     */
    function validateReportData($reportType, $data, $reportTypes) {
        $requiredFields = array();
        
        // Find the matching reportType object
        foreach ($reportTypes as $typeDef) {
            if (isset($typeDef['reportType']) && $typeDef['reportType'] === $reportType) {
                $requiredFields = isset($typeDef['requiredFields']) ? $typeDef['requiredFields'] : array();
                break;
            }
        }

        $missingFields = array();
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === "") {
                $missingFields[] = $field;
            }
        }

        return $missingFields
            ? array("valid" => false, "missing" => $missingFields)
            : array("valid" => true);
    }

    // Ensure reportType and data are set
    $reportType = strtolower($crudData['details']['reportType']);
    $data = $crudData['details']['data'];

    // 📄 Report Validation + Auto-fill for zoning reports
    if ($reportType === 'zoning' && !empty($data['address'])) {
        if (empty($data['parcel']) || empty($data['jurisdiction'])) {
            $parcelCh = curl_init("https://www.skyelighting.com/skyesoft/api/getParcel.php");
            curl_setopt($parcelCh, CURLOPT_POST, true);
            curl_setopt($parcelCh, CURLOPT_POSTFIELDS, http_build_query(array("address" => $data['address'])));
            curl_setopt($parcelCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($parcelCh, CURLOPT_TIMEOUT, 10);
            $parcelResult = curl_exec($parcelCh);
            $curlError = curl_error($parcelCh);
            curl_close($parcelCh);

            if ($parcelResult === false) {
                logError("Parcel lookup failed due to CURL error", array(
                    "address" => $data['address'],
                    "error" => $curlError
                ));
            } else {
                $parcelJson = json_decode($parcelResult, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    logError("Invalid JSON from parcel lookup", array(
                        "address" => $data['address'],
                        "raw" => $parcelResult,
                        "json_error" => json_last_error_msg()
                    ));
                } elseif (!is_array($parcelJson)) {
                    logError("Parcel lookup returned non-array data", array(
                        "address" => $data['address'],
                        "raw" => $parcelResult
                    ));
                } else {
                    if (empty($data['parcel']) && isset($parcelJson['parcel'])) {
                        $data['parcel'] = $parcelJson['parcel'];
                    }
                    if (empty($data['jurisdiction']) && isset($parcelJson['jurisdiction'])) {
                        $data['jurisdiction'] = $parcelJson['jurisdiction'];
                    }
                }
            }
        }
    } elseif ($reportType === 'zoning' && empty($data['address'])) {
        logError("Missing address for zoning report", array(
            "reportType" => $reportType,
            "data" => $data
        ));
    }

    // Run validation after attempting auto-fill
    $validation = validateReportData($reportType, $data, $reportTypes);

    if (!$validation['valid']) {
        sendJsonResponse(
            "❌ Missing required fields: " . implode(", ", $validation['missing']) . " (needed for $reportType report).",
            "none",
            array(
                "sessionId" => session_id(),
                "error" => "Validation failed",
                "missing" => $validation['missing'],
                "data" => $data // Include partially filled data
            )
        );
    }
    // ✅ Passed validation → forward to generator
    $postFields = array(
        "reportType" => $crudData['details']['reportType'],
        "reportData" => $data
    );
    $ch = curl_init("https://www.skyelighting.com/skyesoft/api/generateReport.php");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $reportResult = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($reportResult === false) {
        logError("Report generation curl error", array("error" => $curlError));
        sendJsonResponse(
            "❌ Report generation failed due to curl error: " . $curlError,
            "none",
            array("sessionId" => session_id())
        );
    }

    $reportJson = json_decode($reportResult, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("Invalid JSON from report generation", array(
            "raw" => $reportResult,
            "json_error" => json_last_error_msg()
        ));
        sendJsonResponse(
            "❌ Report generation failed: Invalid JSON response",
            "none",
            array("sessionId" => session_id(), "error" => "Invalid JSON")
        );
    }

    $reportUrl = isset($reportJson['details']['reportUrl']) ? $reportJson['details']['reportUrl'] : null;

    if (!empty($reportJson['success']) && $reportUrl) {
        sendJsonResponse(
            "📄 Report created successfully. <a href='" . htmlspecialchars($reportUrl, ENT_QUOTES, 'UTF-8') . "' target='_blank'>View Report</a>",
            "none",
            array(
                "sessionId" => session_id(),
                "reportUrl" => $reportUrl,
                "details" => $reportJson['details']
            )
        );
    } else {
        logError("Report creation failed", array("raw" => $reportJson));
        sendJsonResponse(
            "❌ Report creation failed.",
            "none",
            array(
                "sessionId" => session_id(),
                "error" => isset($reportJson['error']) ? $reportJson['error'] : "Unknown error",
                "raw" => $reportJson
            )
        );
    }
}
#endregion

// ✅ Agentic CRUD Action Handler
#region Agentic CRUD Action Handler 
/**
 * Handle Create actions
 * @param string $actionName
 * @param array $data
 * @return array
 */
function handleCreateAction($actionName, $data) {
    switch ($actionName) {
        case 'Contact':
            $result = createCrudEntity('Contact', $data['details']);
            return array(
                "response" => $result ? "Contact created successfully." : "Failed to create Contact.",
                "actionType" => "Create",
                "actionName" => "Contact",
                "details" => $data['details'],
                "sessionId" => session_id()
            );
        case 'Order':
            $result = createCrudEntity('Order', $data['details']);
            return array(
                "response" => $result ? "Order created successfully." : "Failed to create Order.",
                "actionType" => "Create",
                "actionName" => "Order",
                "details" => $data['details'],
                "sessionId" => session_id()
            );
        case 'Application':
            $result = createCrudEntity('Application', $data['details']);
            return array(
                "response" => $result ? "Application created successfully." : "Failed to create Application.",
                "actionType" => "Create",
                "actionName" => "Application",
                "details" => $data['details'],
                "sessionId" => session_id()
            );
        case 'Location':
            $result = createCrudEntity('Location', $data['details']);
            return array(
                "response" => $result ? "Location created successfully." : "Failed to create Location.",
                "actionType" => "Create",
                "actionName" => "Location",
                "details" => $data['details'],
                "sessionId" => session_id()
            );
        case 'Login':
            $username = isset($data['details']['username']) ? $data['details']['username'] : '';
            $password = isset($data['details']['password']) ? $data['details']['password'] : '';
            if (authenticateUser($username, $password)) {
                $_SESSION['user_id'] = $username;
                return array(
                    "response" => "Login successful.",
                    "actionType" => "Create",
                    "actionName" => "Login",
                    "details" => array("username" => $username),
                    "sessionId" => session_id(),
                    "loggedIn" => true
                );
            }
            return array(
                "response" => "Login failed.",
                "actionType" => "Create",
                "actionName" => "Login",
                "details" => array("username" => $username),
                "sessionId" => session_id(),
                "loggedIn" => false
            );
        case 'Logout':
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
            return array(
                "response" => "You have been logged out.",
                "actionType" => "Create",
                "actionName" => "Logout",
                "sessionId" => session_id(),
                "loggedIn" => false
            );
        case 'Report':
            return $data; // Handled in Report Generation Hook
        default:
            return array(
                "response" => "Invalid Create action.",
                "actionType" => "Create",
                "actionName" => $actionName,
                "sessionId" => session_id()
            );
    }
}

/**
 * Handle Read actions
 * @param string $actionName
 * @param array $data
 * @return array
 */
function handleReadAction($actionName, $data) {
    switch ($actionName) {
        case 'Contact':
            $result = readCrudEntity('Contact', $data['criteria']);
            return array(
                "response" => $result !== false ? $result : "No Contact found matching criteria.",
                "actionType" => "Read",
                "actionName" => "Contact",
                "criteria" => $data['criteria'],
                "sessionId" => session_id()
            );
        case 'Order':
            $result = readCrudEntity('Order', $data['criteria']);
            return array(
                "response" => $result !== false ? $result : "No Order found matching criteria.",
                "actionType" => "Read",
                "actionName" => "Order",
                "criteria" => $data['criteria'],
                "sessionId" => session_id()
            );
        case 'Application':
            $result = readCrudEntity('Application', $data['criteria']);
            return array(
                "response" => $result !== false ? $result : "No Application found matching criteria.",
                "actionType" => "Read",
                "actionName" => "Application",
                "criteria" => $data['criteria'],
                "sessionId" => session_id()
            );
        case 'Location':
            $result = readCrudEntity('Location', $data['criteria']);
            return array(
                "response" => $result !== false ? $result : "No Location found matching criteria.",
                "actionType" => "Read",
                "actionName" => "Location",
                "criteria" => $data['criteria'],
                "sessionId" => session_id()
            );
        case 'Report':
            return $data; // Handled in Report Generation Hook
        default:
            return array(
                "response" => "Invalid Read action.",
                "actionType" => "Read",
                "actionName" => $actionName,
                "sessionId" => session_id()
            );
    }
}

/**
 * Handle Update actions
 * @param string $actionName
 * @param array $data
 * @return array
 */
function handleUpdateAction($actionName, $data) {
    switch ($actionName) {
        case 'Contact':
            $result = updateCrudEntity('Contact', $data['updates']);
            return array(
                "response" => $result ? "Contact updated successfully." : "Failed to update Contact.",
                "actionType" => "Update",
                "actionName" => "Contact",
                "updates" => $data['updates'],
                "sessionId" => session_id()
            );
        case 'Order':
            $result = updateCrudEntity('Order', $data['updates']);
            return array(
                "response" => $result ? "Order updated successfully." : "Failed to update Order.",
                "actionType" => "Update",
                "actionName" => "Order",
                "updates" => $data['updates'],
                "sessionId" => session_id()
            );
        case 'Application':
            $result = updateCrudEntity('Application', $data['updates']);
            return array(
                "response" => $result ? "Application updated successfully." : "Failed to update Application.",
                "actionType" => "Update",
                "actionName" => "Application",
                "updates" => $data['updates'],
                "sessionId" => session_id()
            );
        case 'Location':
            $result = updateCrudEntity('Location', $data['updates']);
            return array(
                "response" => $result ? "Location updated successfully." : "Failed to update Location.",
                "actionType" => "Update",
                "actionName" => "Location",
                "updates" => $data['updates'],
                "sessionId" => session_id()
            );
        case 'Report':
            return $data; // Handled in Report Generation Hook
        default:
            return array(
                "response" => "Invalid Update action.",
                "actionType" => "Update",
                "actionName" => $actionName,
                "sessionId" => session_id()
            );
    }
}

/**
 * Handle Delete actions
 * @param string $actionName
 * @param array $data
 * @return array
 */
function handleDeleteAction($actionName, $data) {
    switch ($actionName) {
        case 'Contact':
            $result = deleteCrudEntity('Contact', $data['target']);
            return array(
                "response" => $result ? "Contact deleted successfully." : "Failed to delete Contact.",
                "actionType" => "Delete",
                "actionName" => "Contact",
                "target" => $data['target'],
                "sessionId" => session_id()
            );
        case 'Order':
            $result = deleteCrudEntity('Order', $data['target']);
            return array(
                "response" => $result ? "Order deleted successfully." : "Failed to delete Order.",
                "actionType" => "Delete",
                "actionName" => "Order",
                "target" => $data['target'],
                "sessionId" => session_id()
            );
        case 'Application':
            $result = deleteCrudEntity('Application', $data['target']);
            return array(
                "response" => $result ? "Application deleted successfully." : "Failed to delete Application.",
                "actionType" => "Delete",
                "actionName" => "Application",
                "target" => $data['target'],
                "sessionId" => session_id()
            );
        case 'Location':
            $result = deleteCrudEntity('Location', $data['target']);
            return array(
                "response" => $result ? "Location deleted successfully." : "Failed to delete Location.",
                "actionType" => "Delete",
                "actionName" => "Location",
                "target" => $data['target'],
                "sessionId" => session_id()
            );
        case 'Report':
            return $data; // Handled in Report Generation Hook
        default:
            return array(
                "response" => "Invalid Delete action.",
                "actionType" => "Delete",
                "actionName" => $actionName,
                "sessionId" => session_id()
            );
    }
}

/**
 * Handle Clarify actions
 * @param string $actionName
 * @param array $data
 * @param bool $isAddress
 * @param array $clarifyOptions
 * @return array
 */
function handleClarifyAction($actionName, $data, $isAddress, $clarifyOptions) {
    if ($actionName === 'Options') {
        $address = isset($data['details']['address']) ? $data['details']['address'] : null;
        if ($isAddress && count($clarifyOptions) > 1) {
            return array(
                "response" => "Multiple options available for address.",
                "actionType" => "Clarify",
                "actionName" => "Options",
                "options" => $clarifyOptions,
                "details" => array("address" => $address),
                "sessionId" => session_id()
            );
        } elseif ($isAddress && count($clarifyOptions) === 1) {
            $reportType = strtolower($clarifyOptions[0]);
            return array(
                "actionType" => "Create",
                "actionName" => "Report",
                "details" => array(
                    "reportType" => $reportType,
                    "title" => ucfirst($reportType) . " – Untitled Project",
                    "data" => array(
                        "projectName" => "Untitled Project – " . ($address ? $address : "Unknown Address"),
                        "address" => $address
                    )
                )
            );
        }
        return array(
            "response" => "Invalid Clarify action.",
            "actionType" => "Clarify",
            "actionName" => "Options",
            "sessionId" => session_id()
        );
    }
    return array(
        "response" => "Invalid Clarify action.",
        "actionType" => "Clarify",
        "actionName" => $actionName,
        "sessionId" => session_id()
    );
}

$actionHandlers = array(
    'Create' => 'handleCreateAction',
    'Read' => 'handleReadAction',
    'Update' => 'handleUpdateAction',
    'Delete' => 'handleDeleteAction',
    'Clarify' => 'handleClarifyAction'
);

if (
    is_array($crudData) &&
    isset($crudData["actionType"], $crudData["actionName"]) &&
    isset($actionTypesArray[$crudData["actionType"]]) &&
    in_array($crudData["actionName"], $actionTypesArray[$crudData["actionType"]]) &&
    isset($actionHandlers[$crudData["actionType"]])
) {
    $handler = $actionHandlers[$crudData["actionType"]];
    $result = $handler($crudData["actionName"], $crudData, $isAddress, $clarifyOptions);

    // For Create Report or Clarify Options generating a report, return raw JSON
    if (
        isset($result["actionType"], $result["actionName"]) &&
        $result["actionType"] === "Create" &&
        $result["actionName"] === "Report"
    ) {
        echo json_encode($result);
    } else {
        // Otherwise, use standard response format
        sendJsonResponse(
            isset($result["response"]) ? $result["response"] : "Action processed.",
            "none",
            array_diff_key($result, array("response" => null))
        );
    }
} elseif (is_array($crudData) && isset($crudData["actionType"], $crudData["actionName"])) {
    sendJsonResponse(
        "Invalid or incomplete CRUD action data.",
        "none",
        array(
            "actionType" => $crudData["actionType"],
            "actionName" => $crudData["actionName"],
            "sessionId" => session_id()
        )
    );
}
#endregion

// ✅ Final Post-Validation Step
#region Final Post-Validation Step
$allValid = array_merge($codexTerms, $codexOtherTerms, $sseValues);
$isValid = false;
foreach ($allValid as $entry) {
    if (stripos($aiResponse, trim($entry)) !== false || stripos(trim($entry), $aiResponse) !== false) {
        $isValid = true;
        break;
    }
}

if (!$isValid || $aiResponse === "") {
    if (preg_match('/\b(mtco|lgbas|codex|constitution|glossary)\b/i', $lowerPrompt)) {
        $aiResponse = $codexGlossaryBlock !== "" ? trim($codexGlossaryBlock) : "No Codex glossary available.";
    } elseif (!empty($codexOtherBlock) && preg_match('/\b(version|modules|vision|rag|documents|sources of truth|ai behavior)\b/i', $lowerPrompt)) {
        $aiResponse = trim($codexOtherBlock);
    } else {
        foreach ($allValid as $entry) {
            if (stripos($lowerPrompt, strtolower(trim($entry))) !== false) {
                $aiResponse = trim($entry);
                $isValid = true;
                break;
            }
        }
        if (!$isValid) {
            $aiResponse = "No information available.";
        }
    }
}
#endregion

// 📤 Output
#region Output

// Clean up possible code fences from AI
$cleanAiResponse = trim($aiResponse);
$cleanAiResponse = preg_replace('/^```(?:json)?/i', '', $cleanAiResponse); // strip opening ```
$cleanAiResponse = preg_replace('/```$/', '', $cleanAiResponse);           // strip closing ```
$cleanAiResponse = trim($cleanAiResponse);

$decoded = json_decode($cleanAiResponse, true);

// If decoding fails or response is not JSON, show raw AI response
if (!is_array($decoded)) {
    sendJsonResponse(
        "❌ AI returned non-JSON response. See rawAiResponse for details.",
        "none",
        array(
            "rawAiResponse" => $aiResponse
        ),
        200
    );
} else {
    // Normal output path
    sendJsonResponse($decoded, "chat", array("sessionId" => session_id()));
}

#endregion

// 🛠 Helper Functions
#region Helper Functions 
/**
 * Send JSON response with proper HTTP status code
 * @param string $response
 * @param string $action
 * @param array $extra
 * @param int $status
 */
function sendJsonResponse($response, $action = "none", $extra = array(), $status = 200) {
    http_response_code($status);
    $data = array_merge(array(
        "response" => $response,
        "action" => $action,
        "sessionId" => session_id()
    ), $extra);
    echo json_encode($data);
    exit;
}

/**
 * Authenticate a user (placeholder).
 * @param string $username
 * @param string $password
 * @return bool
 */
function authenticateUser($username, $password) {
    $validCredentials = array('admin' => password_hash('secret', PASSWORD_DEFAULT));
    return isset($validCredentials[$username]) && password_verify($password, $validCredentials[$username]);
}

/**
 * Create a new entity (placeholder).
 * @param string $entity
 * @param array $details
 * @return bool
 */
function createCrudEntity($entity, $details) {
    file_put_contents(__DIR__ . "/create_$entity.log", json_encode($details) . "\n", FILE_APPEND);
    return true;
}

/**
 * Read an entity based on criteria (placeholder).
 * @param string $entity
 * @param array $criteria
 * @return mixed
 */
function readCrudEntity($entity, $criteria) {
    return "Sample $entity details for: " . json_encode($criteria);
}

/**
 * Update an entity (placeholder).
 * @param string $entity
 * @param array $updates
 * @return bool
 */
function updateCrudEntity($entity, $updates) {
    file_put_contents(__DIR__ . "/update_$entity.log", json_encode($updates) . "\n", FILE_APPEND);
    return true;
}

/**
 * Delete an entity (placeholder).
 * @param string $entity
 * @param array $target
 * @return bool
 */
function deleteCrudEntity($entity, $target) {
    file_put_contents(__DIR__ . "/delete_$entity.log", json_encode($target) . "\n", FILE_APPEND);
    return true;
}
#endregion