<?php

#region 🛡️ Headers and API Key
header("Content-Type: application/json");
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    echo json_encode(["response" => "❌ API key not found.", "action" => "none"]);
    exit;
}
#endregion

#region 📨 Parse Input
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["response" => "❌ Invalid JSON input.", "action" => "none"]);
    exit;
}
$prompt = isset($input["prompt"]) ? trim(filter_var($input["prompt"], FILTER_SANITIZE_STRING)) : "";
$conversation = isset($input["conversation"]) ? $input["conversation"] : [];
$sseSnapshot = isset($input["sseSnapshot"]) ? $input["sseSnapshot"] : [];
if (empty($prompt)) {
    echo json_encode(["response" => "❌ Empty prompt.", "action" => "none"]);
    exit;
}
#endregion

#region ⚡️ Quick Agentic Actions
$lowerPrompt = strtolower($prompt);
if (preg_match('/\blog\s*out\b|\blogout\b|\bexit\b|\bsign\s*out\b/i', $lowerPrompt)) {
    session_start();
    session_unset();
    session_destroy();
    echo json_encode([
        "response" => "You have been logged out (quick action).",
        "actionType" => "Create",
        "actionName" => "Logout",
        "sessionId" => session_id(),
        "loggedIn" => false
    ]);
    exit;
}
if (preg_match('/\blog\s*in\s+as\s+([a-zA-Z0-9]+)\s+with\s+password\s+(.+)/i', $lowerPrompt, $matches)) {
    session_start();
    $username = $matches[1];
    $password = $matches[2];
    if (authenticateUser($username, $password)) {
        $_SESSION['user_id'] = $username;
        echo json_encode([
            "response" => "Login successful (quick action).",
            "actionType" => "Create",
            "actionName" => "Login",
            "details" => ["username" => $username],
            "sessionId" => session_id(),
            "loggedIn" => true
        ]);
    } else {
        echo json_encode([
            "response" => "Login failed (quick action).",
            "actionType" => "Create",
            "actionName" => "Login",
            "details" => ["username" => $username],
            "sessionId" => session_id(),
            "loggedIn" => false
        ]);
    }
    exit;
}
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

$skyebotSOT = [
    'codex' => $codexData,
    'sse'   => $sseData,
    'other' => []
];
file_put_contents(__DIR__ . '/debug-skyebotSOT.log', print_r($skyebotSOT, true));
#endregion

#region 🚦 Direct Responses for Time/Date/Weather
if (
    (strpos($lowerPrompt, "time") !== false || strpos($lowerPrompt, "clock") !== false)
    && isset($sseSnapshot['timeDateArray']['currentLocalTime'])
) {
    $tz = (isset($sseSnapshot['timeDateArray']['timeZone']) && $sseSnapshot['timeDateArray']['timeZone'] === "America/Phoenix") ? " MST" : "";
    $response = $sseSnapshot['timeDateArray']['currentLocalTime'] . $tz;
    echo json_encode(["response" => $response, "action" => "none"]);
    exit;
}

if (strpos($lowerPrompt, "date") !== false && isset($sseSnapshot['timeDateArray']['currentDate'])) {
    echo json_encode(["response" => $sseSnapshot['timeDateArray']['currentDate'], "action" => "none"]);
    exit;
}

if (strpos($lowerPrompt, "weather") !== false && isset($sseSnapshot['weatherData']['description'])) {
    $temp = isset($sseSnapshot['weatherData']['temp']) ? $sseSnapshot['weatherData']['temp'] . "°F, " : "";
    $desc = $sseSnapshot['weatherData']['description'];
    echo json_encode(["response" => $temp . $desc, "action" => "none"]);
    exit;
}
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

#region 📊 Build sseSnapshot Summary & Array
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

#region 📋 User Codex Commands
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
    echo json_encode([
        "response" => $formattedGlossary,
        "action" => "none"
    ]);
    exit;
}

if (preg_match('/\b(show modules|list modules|all modules)\b/i', $lowerPrompt)) {
    $modulesDisplay = empty($modulesArr) ? "No modules found in Codex." : nl2br(htmlspecialchars(implode("\n\n", $modulesArr)));
    echo json_encode([
        "response" => $modulesDisplay,
        "action" => "none"
    ]);
    exit;
}
#endregion

#region 📝 Build System Prompt
$actionTypesArray = [
    "Create" => ["Contact", "Order", "Application", "Location", "Login", "Logout"],
    "Read" => ["Contact", "Order", "Application", "Location"],
    "Update" => ["Contact", "Order", "Application", "Location"],
    "Delete" => ["Contact", "Order", "Application", "Location"]
];

$systemPrompt = <<<PROMPT
You are Skyebot, an assistant for a signage company. You have three sources of truth:
- codexGlossary: internal company terms/definitions
- codexOther: other company knowledge base items (version, modules, constitution, etc.)
- sseSnapshot: current operational data (date, time, weather, KPIs, etc.)

Rules:
- If the user's intent is to perform a CRUD action (Create, Read, Update, Delete), reply ONLY with a JSON object using this structure:
  - {"actionType":"Create","actionName":"Contact","details":{"name":"John Doe","email":"john@example.com"}}
  - {"actionType":"Create","actionName":"Login","details":{"username":"jane","password":"yourpassword"}}
  - {"actionType":"Create","actionName":"Logout"}
  - {"actionType":"Read","actionName":"Order","criteria":{"orderID":"1234"}}
  - {"actionType":"Update","actionName":"Application","updates":{"applicationID":"3456","status":"Approved"}}
  - {"actionType":"Delete","actionName":"Location","target":{"locationID":"21"}}
- Allowed actionTypes: Create, Read, Update, Delete
- Allowed actionNames: Contact, Order, Application, Location, Login, Logout
- For all other queries, respond ONLY with the value or definition from codexGlossary, codexOther, or sseSnapshot. No extra wording.
- If no information is found, reply: "No information available."
- Do not repeat the user’s question, explain reasoning, or add extra context.

codexGlossary:
$codexGlossaryBlock

codexOther:
$codexOtherBlock

sseSnapshot:
$snapshotSummary
PROMPT;
#endregion

#region 💬 Build OpenAI Message Array
$messages = [
    ["role" => "system", "content" => $systemPrompt]
];
if (!empty($conversation)) {
    $history = array_slice($conversation, -2);
    foreach ($history as $entry) {
        if (isset($entry["role"]) && isset($entry["content"])) {
            $messages[] = ["role" => $entry["role"], "content" => $entry["content"]];
        }
    }
}
$messages[] = ["role" => "user", "content" => $prompt];
#endregion

#region 🚀 OpenAI API Request
$payload = json_encode([
    "model" => "gpt-4",
    "messages" => $messages,
    "temperature" => 0.1,
    "max_tokens" => 200
], JSON_UNESCAPED_SLASHES);

$opts = [
    "http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
        "content" => $payload,
        "timeout" => 25
    ]
];

$context = stream_context_create($opts);
$response = @file_get_contents("https://api.openai.com/v1/chat/completions", false, $context);

if ($response === false) {
    $error = error_get_last();
    file_put_contents(__DIR__ . '/error.log', "OpenAI API Error: " . (isset($error['message']) ? $error['message'] : 'Unknown error') . "\n", FILE_APPEND);
    echo json_encode(["response" => "❌ Error reaching OpenAI API.", "action" => "none"]);
    exit;
}

$result = json_decode($response, true);
if (!isset($result["choices"][0]["message"]["content"])) {
    file_put_contents(__DIR__ . '/error.log', "Invalid OpenAI Response: " . $response . "\n", FILE_APPEND);
    echo json_encode(["response" => "❌ Invalid response from AI.", "action" => "none"]);
    exit;
}

$responseContent = trim($result["choices"][0]["message"]["content"]);
#endregion

#region ✅ Agentic CRUD Action Handler
session_start();
$crudData = json_decode($responseContent, true);

if (
    is_array($crudData) &&
    isset($crudData["actionType"], $crudData["actionName"]) &&
    isset($actionTypesArray[$crudData["actionType"]]) &&
    in_array($crudData["actionName"], $actionTypesArray[$crudData["actionType"]])
) {
    $type = $crudData["actionType"];
    $name = $crudData["actionName"];
    // Switch based on action type
    switch ($type) {
        case "Create":
            // Login Conditional
            if ($name === "Login") {
                $username = isset($crudData["details"]["username"]) ? $crudData["details"]["username"] : '';
                $password = isset($crudData["details"]["password"]) ? $crudData["details"]["password"] : '';
                if (authenticateUser($username, $password)) {
                    $_SESSION['user_id'] = $username;
                    echo json_encode([
                        "response" => "Login successful.",
                        "actionType" => $type,
                        "actionName" => $name,
                        "details" => ["username" => $username],
                        "sessionId" => session_id(),
                        "loggedIn" => true
                    ]);
                } else {
                    echo json_encode([
                        "response" => "Login failed.",
                        "actionType" => $type,
                        "actionName" => $name,
                        "details" => ["username" => $username],
                        "sessionId" => session_id(),
                        "loggedIn" => false
                    ]);
                }
                exit;
            }
            // Logout Conditional
            if ($name === "Logout") {
                // Unset session variables
                session_unset();
                // Destroy session
                session_destroy();
                // Unset PHPSESSID
                if (ini_get("session.use_cookies")) {
                    // Expire session cookie
                    $params = session_get_cookie_params();
                    // Unset session cookie
                    setcookie(session_name(), '', time() - 42000,
                        // Set Path  
                        $params["path"], $params["domain"],
                        // Secure flag
                        $params["secure"], isset($params["httponly"]) ? $params["httponly"] : false
                    );
                }
                // Expire custom login cookie
                setcookie('skyelogin_user', '', time() - 3600, '/', 'www.skyelighting.com');
                // Echo logout response
                echo json_encode([
                    // Response message
                    "response" => "You have been logged out.",
                    // Action Type
                    "actionType" => $type,
                    // Action Name
                    "actionName" => $name,
                    // Session ID
                    "sessionId" => session_id(),
                    // Logged In Status
                    "loggedIn" => false
                ]);
                // Exit after logout
                exit;
            }
            // Create CRUD Entity
            if (isset($crudData["details"]) && is_array($crudData["details"])) {
                $result = createCrudEntity($name, $crudData["details"]);
                echo json_encode([
                    "response" => $result ? "$name created successfully." : "Failed to create $name.",
                    "actionType" => $type,
                    "actionName" => $name,
                    "details" => $crudData["details"],
                    "sessionId" => session_id()
                ]);
                exit;
            }
            break;

        case "Read":
            if (isset($crudData["criteria"]) && is_array($crudData["criteria"])) {
                $result = readCrudEntity($name, $crudData["criteria"]);
                echo json_encode([
                    "response" => $result !== false ? $result : "No $name found matching criteria.",
                    "actionType" => $type,
                    "actionName" => $name,
                    "criteria" => $crudData["criteria"],
                    "sessionId" => session_id()
                ]);
                exit;
            }
            break;

        case "Update":
            if (isset($crudData["updates"]) && is_array($crudData["updates"])) {
                $result = updateCrudEntity($name, $crudData["updates"]);
                echo json_encode([
                    "response" => $result ? "$name updated successfully." : "Failed to update $name.",
                    "actionType" => $type,
                    "actionName" => $name,
                    "updates" => $crudData["updates"],
                    "sessionId" => session_id()
                ]);
                exit;
            }
            break;

        case "Delete":
            if (isset($crudData["target"]) && is_array($crudData["target"])) {
                $result = deleteCrudEntity($name, $crudData["target"]);
                echo json_encode([
                    "response" => $result ? "$name deleted successfully." : "Failed to delete $name.",
                    "actionType" => $type,
                    "actionName" => $name,
                    "target" => $crudData["target"],
                    "sessionId" => session_id()
                ]);
                exit;
            }
            break;
    }

    echo json_encode([
        "response" => "Invalid or incomplete CRUD action data.",
        "actionType" => $type,
        "actionName" => $name,
        "sessionId" => session_id()
    ]);
    exit;
}
#endregion

#region ✅ Final Post-Validation Step
$allValid = array_merge($codexTerms, $codexOtherTerms, $sseValues);
$isValid = false;
foreach ($allValid as $entry) {
    if (strcasecmp(trim($responseContent), trim($entry)) === 0) {
        $isValid = true;
        break;
    }
    if (strpos($responseContent, ":") !== false && stripos($entry, trim($responseContent)) !== false) {
        $isValid = true;
        break;
    }
}
if (!$isValid || $responseContent === "") {
    $responseContent = "No information available.";
}
#endregion

#region 📤 Output
echo json_encode([
    "response" => $responseContent,
    "action" => "none",
    "sessionId" => session_id()
]);
#endregion

/**
 * Authenticate a user (placeholder).
 * @param string $username
 * @param string $password
 * @return bool
 */
function authenticateUser($username, $password) {
    // TODO: Replace with secure authentication (e.g., password_hash, database check)
    // NEVER store or log plain passwords
    $validCredentials = ['admin' => password_hash('secret', PASSWORD_DEFAULT)]; // Example only
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