<?php
// 📄 File: api/askOpenAI.php
// Entry point for Skyebot AI interactions

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
#endregion

#region 📂 Load Unified Context (DynamicData)
$dynamicUrl = __DIR__ . '/getDynamicData.php';
$dynamicData = array();

$dynamicRaw = @file_get_contents($dynamicUrl);
if ($dynamicRaw !== false) {
    $dynamicData = json_decode($dynamicRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents(__DIR__ . '/error.log',
            "DynamicData JSON Error: " . json_last_error_msg() . "\nRaw: $dynamicRaw\n",
            FILE_APPEND
        );
        $dynamicData = array();
    }
} else {
    file_put_contents(__DIR__ . '/error.log',
        date('Y-m-d H:i:s') . " - Failed to load getDynamicData.php\n",
        FILE_APPEND
    );
}
#endregion

#region 📚 Build Context Blocks
$codexGlossaryBlock = !empty($dynamicData['modules']['glossaryModule']['contents'])
    ? json_encode($dynamicData['modules']['glossaryModule']['contents'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : "No glossary data available.\n";

$codexOtherBlock = json_encode(array(
    "meta"           => isset($dynamicData['meta']) ? $dynamicData['meta'] : array(),
    "constitution"   => isset($dynamicData['constitution']) ? $dynamicData['constitution'] : array(),
    "ragExplanation" => isset($dynamicData['ragExplanation']) ? $dynamicData['ragExplanation'] : array(),
    "shared"         => isset($dynamicData['shared']) ? $dynamicData['shared'] : array()
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$reportTypesBlock = !empty($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])
    ? json_encode($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : "No report types data available.\n";

// 🔹 Flattened snapshot for easier AI interpretation
$snapshotSummary = json_encode(array(
    "time"          => isset($dynamicData['timeDateArray']['currentLocalTime']) ? $dynamicData['timeDateArray']['currentLocalTime'] : null,
    "date"          => isset($dynamicData['timeDateArray']['currentDate']) ? $dynamicData['timeDateArray']['currentDate'] : null,
    "timezone"      => isset($dynamicData['timeDateArray']['timeZone']) ? $dynamicData['timeDateArray']['timeZone'] : null,
    "weather"       => isset($dynamicData['weatherData']['description']) ? $dynamicData['weatherData']['description'] : null,
    "temperature"   => isset($dynamicData['weatherData']['temp']) ? $dynamicData['weatherData']['temp'] : null,
    "forecast"      => isset($dynamicData['weatherData']['forecast']) ? $dynamicData['weatherData']['forecast'] : null,
    "kpis"          => isset($dynamicData['kpiData']) ? $dynamicData['kpiData'] : null,
    "orders"        => isset($dynamicData['kpiData']['orders']) ? $dynamicData['kpiData']['orders'] : null,
    "contacts"      => isset($dynamicData['kpiData']['contacts']) ? $dynamicData['kpiData']['contacts'] : null,
    "approvals"     => isset($dynamicData['kpiData']['approvals']) ? $dynamicData['kpiData']['approvals'] : null,
    "announcements" => isset($dynamicData['announcements']) ? $dynamicData['announcements'] : null
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
#endregion

#region 🛡️ Input & Session Bootstrap
$data = json_decode(file_get_contents("php://input"), true);
if ($data === null || $data === false) {
    echo json_encode(array(
        "response"  => "❌ Invalid or empty JSON payload.",
        "action"    => "none",
        "sessionId" => session_id()
    ), JSON_PRETTY_PRINT);
    exit;
}

$prompt = isset($data["prompt"])
    ? trim(strip_tags(filter_var($data["prompt"], FILTER_DEFAULT)))
    : "";
$conversation = isset($data["conversation"]) && is_array($data["conversation"]) ? $data["conversation"] : array();
$lowerPrompt = strtolower($prompt);

if (empty($prompt)) {
    sendJsonResponse("❌ Empty prompt.", "none", array("sessionId" => session_id()));
    exit;
}
#endregion

#region 🛡️ Headers and Setup
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    sendJsonResponse("❌ API key not found.", "none", array("sessionId" => session_id()));
    exit;
}
#endregion

#region 📝 System Prompt
$systemPrompt = <<<PROMPT
You are Skyebot™, an assistant for a signage company.

⚠️ CRITICAL RULES:
- For CRUD and Report actions → reply in valid JSON only.
- For glossary lookups, codex questions, and stream data (time, date, weather, KPIs, announcements) → reply in plain text using sseSnapshot.
- For logout → always return JSON.
- For general queries (outside codex/stream) → reply in plain text using general AI knowledge.
- Never return markdown, code fences, or mixed formats.

You have four sources of truth:
- codexGlossary: internal company terms/definitions
- codexOther: company knowledge (meta, constitution, etc.)
- sseSnapshot: live operational data (raw JSON below)
- reportTypes: standardized report templates

---
## Stream Queries
If the user asks about time, date, weather, KPIs, announcements:
→ Use sseSnapshot, interpret naturally (combine fields if needed).
→ Do not invent values.

## Reports
If all required fields are present → generate report JSON.
If fields are missing → return a Codex Report JSON (explanation only).

## Logout
If user says quit, exit, logout, log out, sign out, or end session:
→ Always return JSON: {"actionType":"Create","actionName":"Logout"}

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

// Quick Agentic Actions (logout, login)
if (!$handled && (
    preg_match('/\b(log\s*out|logout|exit|sign\s*out|quit)\b/i', $lowerPrompt) ||
    preg_match('/\b(log\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+))\b/i', $lowerPrompt)
)) {
    handleQuickAction($lowerPrompt);
    $handled = true;
}

// Stream Queries (time, date, weather, KPIs, announcements)
if (!$handled && queryMatchesStream($lowerPrompt, $dynamicData)) {
    handleStreamQuery($lowerPrompt, $dynamicData);
    $handled = true;
}

// Codex Commands (glossary, modules, etc.)
if (!$handled && (
    preg_match('/\b(show glossary|all glossary|list all terms|full glossary)\b/i', $lowerPrompt) ||
    preg_match('/\b(show modules|list modules|all modules)\b/i', $lowerPrompt) ||
    preg_match('/\b(mtco|lgbas|codex|constitution|version|vision|rag|documents|sources of truth|ai behavior)\b/i', $lowerPrompt)
)) {
    handleCodexCommand($lowerPrompt, $dynamicData, $codexGlossaryBlock, $codexOtherBlock);
    $handled = true;
}

// Report Requests
if (!$handled && preg_match('/\b(zoning|report)\b/i', $lowerPrompt)) {
    $reportTypesDecoded = json_decode($reportTypesBlock, true);

    if (is_array($reportTypesDecoded)) {
        if (preg_match('/\b\d{1,5}\b/', $prompt) && preg_match('/\b\d{5}\b/', $prompt)) {
            handleReportRequest($lowerPrompt, $reportTypesDecoded, $conversation);
        } else {
            $response = array(
                "actionType" => "Create",
                "actionName" => "Report",
                "reportType" => "Codex Report",
                "response"   => "ℹ️ Codex information about requested report type.",
                "details"    => isset($reportTypesDecoded['Zoning Report']) ? $reportTypesDecoded['Zoning Report'] : array()
            );
            sendJsonResponse($response, "report", array("sessionId" => session_id()));
            exit;
        }
    } else {
        sendJsonResponse("❌ Report types not available.", "error", array("sessionId" => session_id()));
    }

    $handled = true;
}

// Default: General AI
if (!$handled) {
    $messages = array(array("role" => "system", "content" => $systemPrompt));
    if (!empty($conversation)) {
        $history = array_slice($conversation, -2);
        foreach ($history as $entry) {
            if (isset($entry["role"]) && isset($entry["content"])) {
                $messages[] = array("role" => $entry["role"], "content" => $entry["content"]);
            }
        }
    }
    $messages[] = array("role" => "user", "content" => $prompt);
    $aiResponse = callOpenAi($messages);
    if (is_string($aiResponse) && trim($aiResponse) !== '') {
        $aiResponse .= " ⁂";
    }
    sendJsonResponse($aiResponse, "chat", array("sessionId" => session_id()));
    exit;
}
#endregion

#region 🛠 Stream Query Helpers (must come before Routing Layer)
function queryMatchesStream($prompt, $dynamicData) {
    global $streamMap;
    $lowerPrompt = strtolower($prompt);

    foreach ($streamMap as $keyword => $path) {
        if (strpos($lowerPrompt, $keyword) !== false) {
            $pathParts = explode('.', $path);
            $current = $dynamicData;
            foreach ($pathParts as $part) {
                if (!isset($current[$part])) continue 2;
                $current = $current[$part];
            }
            if ($current !== null) return true;
        }
    }
    return false;
}

function handleStreamQuery($prompt, $dynamicData) {
    global $streamMap;
    $p = strtolower($prompt);

    foreach ($streamMap as $keyword => $path) {
        if (strpos($p, $keyword) !== false) {
            $pathParts = explode('.', $path);
            $current = $dynamicData;
            foreach ($pathParts as $part) {
                if (!isset($current[$part])) {
                    $current = null;
                    break;
                }
                $current = $current[$part];
            }

            if ($current !== null) {
                if ($keyword === 'forecast' && is_array($current)) {
                    $days = array();
                    foreach ($current as $f) {
                        $days[] = $f['date'] . " (" . $f['description'] . ", High " . $f['high'] . " / Low " . $f['low'] . ")";
                    }
                    $response = "Forecast: " . implode("; ", $days);
                } else {
                    $response = ucfirst($keyword) . ": " . (is_array($current) ? json_encode($current) : $current);
                }
                sendJsonResponse($response, "chat", array("sessionId" => session_id()));
                exit;
            }
        }
    }
    return false;
}
#endregion

#region 🛠 Helper Functions
// Prevent duplicate function definitions (PHP 5.6 compatible)
if (!function_exists('queryMatchesStream')) {
    /**
     * Check if prompt matches stream data (time, date, weather, KPIs, announcements)
     * @param string $prompt
     * @param array $dynamicData
     * @return bool
     */
    function queryMatchesStream($prompt, $dynamicData) {
        $lowerPrompt = strtolower($prompt);
        return (
            preg_match('/\b(time|current time|local time|clock|date|today.?s date|current date|weather|forecast|kpis?|orders?|approvals?|announcements?)\b/i', $lowerPrompt) &&
            (
                isset($dynamicData['timeDateArray']['currentLocalTime']) ||
                isset($dynamicData['timeDateArray']['currentDate']) ||
                isset($dynamicData['weather']['current']) ||
                isset($dynamicData['KPIs']) ||
                isset($dynamicData['announcements'])
            )
        );
    }
}

if (!function_exists('handleStreamQuery')) {
    /**
     * Handle stream-based queries (time, date, weather, KPIs, announcements)
     * @param string $prompt
     * @param array $dynamicData
     */
    function handleStreamQuery($prompt, $dynamicData) {
        $p = strtolower($prompt);
        $response = "⚠️ No stream data available.";

        if (isset($dynamicData['weather']['current']) && (
            strpos($p, 'weather') !== false || strpos($p, 'forecast') !== false || strpos($p, 'temp') !== false
        )) {
            $response = "Current weather: " . $dynamicData['weather']['current'];
        }
        elseif (isset($dynamicData['timeDateArray']['currentLocalTime']) && strpos($p, 'time') !== false) {
            $response = "Local time: " . $dynamicData['timeDateArray']['currentLocalTime'];
        }
        elseif (isset($dynamicData['timeDateArray']['currentDate']) && strpos($p, 'date') !== false) {
            $response = "Today’s date: " . $dynamicData['timeDateArray']['currentDate'];
        }
        elseif (isset($dynamicData['KPIs']) && (
            strpos($p, 'order') !== false || strpos($p, 'contact') !== false || strpos($p, 'approval') !== false
        )) {
            $kpis = $dynamicData['KPIs'];
            $response = "KPIs — Orders: " . $kpis['orders'] . ", Contacts: " . $kpis['contacts'] . ", Approvals: " . $kpis['approvals'];
        }
        elseif (isset($dynamicData['announcements']) && (
            strpos($p, 'announcement') !== false || strpos($p, 'bulletin') !== false
        )) {
            $titles = array();
            foreach ($dynamicData['announcements'] as $a) {
                if (isset($a['title'])) {
                    $titles[] = $a['title'];
                }
            }
            $response = "Announcements: " . implode("; ", $titles);
        }

        // Ensure we always pass a string
        if (!is_string($response)) {
            $response = json_encode($response);
        }

        sendJsonResponse($response, "chat", array("sessionId" => session_id()));
        exit;
    }
}

/**
 * Handle codex commands (glossary, modules, etc.)
 * @param string $prompt
 * @param array $dynamicData
 * @param string $codexGlossaryBlock
 * @param string $codexOtherBlock
 */
function handleCodexCommand($prompt, $dynamicData, $codexGlossaryBlock, $codexOtherBlock) {
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
    } elseif (preg_match('/\b(mtco|lgbas|codex|constitution|version|vision|rag|documents|sources of truth|ai behavior)\b/i', $lowerPrompt)) {
        $term = preg_match('/\bwhat is\s+([a-z0-9\-]+)\??/i', $lowerPrompt, $matches) ? strtoupper($matches[1]) : null;
        if ($term) {
            $filteredGlossary = array();
            foreach (explode("\n", $codexGlossaryBlock) as $line) {
                if (stripos($line, $term) === 0) {
                    $filteredGlossary[] = $line;
                }
            }
            $response = !empty($filteredGlossary) ? implode("\n", $filteredGlossary) : "$term: No information available";
            sendJsonResponse($response, "none", array("sessionId" => session_id()));
        } else {
            $response = $codexOtherBlock !== "" ? trim($codexOtherBlock) : "No Codex information available.";
            sendJsonResponse($response, "none", array("sessionId" => session_id()));
        }
    }
}

/**
 * Send JSON response with proper HTTP status code
 * Updated for PHP 5.6 compatibility to handle both string and array responses
 * @param mixed $response (string or array)
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
    sendJsonResponse("You have been logged out", "none", array(
        "actionType" => "Create",
        "actionName" => "Logout",
        "sessionId" => $newSessionId,
        "loggedIn" => false
    ));
}

/**
 * Handle quick actions (login, logout)
 * @param string $input
 */
function handleQuickAction($input) {
    $action = strtolower(trim($input));
    if (in_array($action, array('logout', 'sign out', 'signout', 'exit', 'quit'))) {
        performLogout();
        return;
    }
    if (preg_match('/\blog\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+)/i', $action, $matches)) {
        $username = $matches[1];
        $password = $matches[2];
        if (authenticateUser($username, $password)) {
            $_SESSION['user'] = $username;
            sendJsonResponse("Logged in as $username", "none", array(
                "actionType" => "Create",
                "actionName" => "Login",
                "sessionId" => session_id(),
                "loggedIn" => true
            ));
        } else {
            sendJsonResponse("Invalid credentials", "none", array("sessionId" => session_id()));
        }
        return;
    }
    sendJsonResponse("Unknown quick action: $action", "none");
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
 * Handle AI report output
 * @param string $prompt
 * @param array $reportTypes
 * @param array $conversation
 */
function handleReportRequest($prompt, $reportTypes, &$conversation) {
    $detectedReportType = null;
    $p = strtolower($prompt);

    foreach ($reportTypes as $key => $type) {
        $candidate = is_array($type) ? $key : $type;
        if (is_string($candidate) && strpos($p, strtolower($candidate)) !== false) {
            $detectedReportType = $candidate;
            break;
        }
    }

    if ($detectedReportType === null) {
        if (strpos($p, "zoning") !== false) {
            $detectedReportType = "Zoning Report";
        } elseif (strpos($p, "sign") !== false) {
            $detectedReportType = "Sign Ordinance Report";
        } elseif (strpos($p, "photo") !== false) {
            $detectedReportType = "Photo Survey Report";
        } elseif (strpos($p, "custom") !== false) {
            $detectedReportType = "Custom Report";
        }
    }

    switch ($detectedReportType) {
        case "Zoning Report":
            $report = generateZoningReport($prompt, $conversation);
            break;
        case "Sign Ordinance Report":
            $report = generateSignOrdinanceReport($prompt, $conversation);
            break;
        case "Photo Survey Report":
            $report = generatePhotoSurveyReport($prompt, $conversation);
            break;
        case "Custom Report":
            $report = generateCustomReport($prompt, $conversation);
            break;
        default:
            $report = array(
                "error"    => true,
                "response" => "⚠️ Unknown or unsupported report type.",
                "inputs"   => array("prompt" => $prompt)
            );
            break;
    }

    header('Content-Type: application/json');
    echo json_encode($report, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Filter parcels by address match, with proximity fallback
 * @param array $parcels
 * @param string $inputAddress
 * @param float|null $latitude
 * @param float|null $longitude
 * @return array
 */
function filterParcels($parcels, $inputAddress, $latitude, $longitude) {
    if (empty($parcels)) return array();

    $inputAddress = normalizeAddress($inputAddress);
    $m = array();
    preg_match(
        '/^([0-9]+)\s+([A-Z\s]+)\s+(RD|ROAD|AVE|AVENUE|BLVD|BOULEVARD|ST|STREET|DR|DRIVE|LN|LANE|PL|PLACE|WAY|CT|COURT)\b/i',
        $inputAddress,
        $m
    );
    $num = isset($m[1]) ? $m[1] : null;
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

    if (empty($filtered) && $latitude && $longitude) {
        $closest = null;
        $min = PHP_INT_MAX;
        foreach ($parcels as $p) {
            if (isset($p["geometry"]["coordinates"]["rings"][0])) {
                $coords = $p["geometry"]["coordinates"]["rings"][0];
                $sumLat = 0;
                $sumLon = 0;
                $count = count($coords);
                foreach ($coords as $pt) {
                    $sumLon += $pt[0];
                    $sumLat += $pt[1];
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

/**
 * Call OpenAI API
 * @param array $messages
 * @return string
 */
function callOpenAi($messages) {
    $apiKey = getenv("OPENAI_API_KEY");
    $payload = json_encode(array(
        "model" => "gpt-4",
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
 * Prepares and enriches report data
 * @param array $crudData
 * @param array $reportTypes
 * @return array
 */
function prepareReportData($crudData, $reportTypes) {
    $result = array('valid' => false, 'data' => array(), 'errors' => array());

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

    $requiredFields = isset($reportTypeDef['requiredFields']) ? $reportTypeDef['requiredFields'] : array();
    $missingFields = array();
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
        curl_setopt($parcelCh, CURLOPT_POSTFIELDS, http_build_query(array('address' => $enrichedData['address'])));
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

    $missingFields = array();
    foreach ($requiredFields as $field) {
        if (!isset($enrichedData[$field]) || $enrichedData[$field] === '') {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        $result['errors'] = array_merge($result['errors'], array("Missing required fields after enrichment: " . implode(', ', $missingFields)));
        return $result;
    }

    $crudData['details']['data'] = $enrichedData;
    $result['valid'] = true;
    $result['data'] = $crudData;
    return $result;
}

/**
 * Fallback report logic handler
 * @param string $aiResponse
 */
function runReportLogic($aiResponse) {
    sendJsonResponse($aiResponse, "report", array("sessionId" => session_id()));
}
#endregion