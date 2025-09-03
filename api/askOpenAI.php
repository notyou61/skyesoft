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

$snapshotSummary = json_encode($dynamicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
- Always respond naturally in plain text sentences.
- For logout → return JSON only: {"actionType":"Create","actionName":"Logout"}.
- For CRUD and report creation → return JSON in the defined format.
- Otherwise → use Codex for company rules, or general AI knowledge.

sseSnapshot:
$snapshotSummary

codexGlossary:
$codexGlossaryBlock

codexOther:
$codexOtherBlock

reportTypes:
$reportTypesBlock
PROMPT;
#endregion

#region 🎯 Routing Layer
$handled = false;

// Quick Agentic Actions (logout, login, CRUD)
if (!$handled && preg_match('/\b(log\s*out|logout|exit|sign\s*out|quit|leave|end\s+session|done\s+for\s+now|close)\b/i', $lowerPrompt)) {
    handleQuickAction($lowerPrompt);
    $handled = true;
}

if (!$handled && preg_match('/\b(log\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+))\b/i', $lowerPrompt)) {
    handleQuickAction($lowerPrompt);
    $handled = true;
}

if (!$handled && preg_match('/\b(create|read|update|delete)\s+([a-zA-Z0-9]+)\b/i', $lowerPrompt, $matches)) {
    $actionType = ucfirst(strtolower($matches[1]));
    $entity = $matches[2];
    $details = array("entity" => $entity, "prompt" => $prompt);
    
    if ($actionType === "Create") {
        $success = createCrudEntity($entity, $details);
        sendJsonResponse($success ? "Created $entity successfully" : "Failed to create $entity", "crud", array(
            "actionType" => "Create",
            "actionName" => $entity
        ));
    } elseif ($actionType === "Read") {
        $result = readCrudEntity($entity, $details);
        sendJsonResponse($result, "crud", array(
            "actionType" => "Read",
            "actionName" => $entity
        ));
    } elseif ($actionType === "Update") {
        $success = updateCrudEntity($entity, $details);
        sendJsonResponse($success ? "Updated $entity successfully" : "Failed to update $entity", "crud", array(
            "actionType" => "Update",
            "actionName" => $entity
        ));
    } elseif ($actionType === "Delete") {
        $success = deleteCrudEntity($entity, $details);
        sendJsonResponse($success ? "Deleted $entity successfully" : "Failed to delete $entity", "crud", array(
            "actionType" => "Delete",
            "actionName" => $entity
        ));
    }
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

// Codex Commands
if (!$handled && preg_match('/\b(show glossary|all glossary|list all terms|full glossary|show modules|list modules|all modules|mtco|lgbas|codex|constitution|version|vision|rag|documents|sources of truth|ai behavior)\b/i', $lowerPrompt)) {
    handleCodexCommand($lowerPrompt, $dynamicData, $codexGlossaryBlock, $codexOtherBlock);
    $handled = true;
}

// Default: General AI (jazz style)
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

#region 🛠 Helper Functions

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
 * Call OpenAI API with Google Search fallback
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

    $aiResponse = trim($result["choices"][0]["message"]["content"]);

    // 🔎 Google Search fallback if AI says it "doesn't know"
    if (preg_match('/don\'t have|can\'t provide|please provide|not sure/i', $aiResponse)) {
        $lastUserMessage = end($messages);
        if (isset($lastUserMessage['content'])) {
            $searchResult = googleSearch($lastUserMessage['content']);
            if (!isset($searchResult['error']) && isset($searchResult['items'][0])) {
                $first = $searchResult['items'][0];
                $aiResponse = "🔎 I looked this up: " . $first['title'] . " — " .
                              $first['snippet'] . " (" . $first['link'] . ")";
            } else {
                $aiResponse .= " (No useful Google results found)";
            }
        }
    }

    return $aiResponse;
}

/**
 * Perform a Google Custom Search API query.
 *
 * Uses GOOGLE_SEARCH_KEY and GOOGLE_SEARCH_CX from environment variables.
 * Returns decoded JSON results from Google or an error array on failure.
 *
 * @param string $query  The search term(s).
 * @return array         The decoded JSON response or error info.
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
function runReportLogic($aiResponse) {
    sendJsonResponse($aiResponse, "report", array("sessionId" => session_id()));
}

#endregion