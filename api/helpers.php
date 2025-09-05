<?php
// /api/helpers.php
// Shared helper functions for Skyebot backend

function handleCodexCommand($prompt, $dynamicData) {
    $codex = isset($dynamicData['codex']) ? $dynamicData['codex'] : array();

    $messages = array(
        array("role" => "system", "content" =>
            "You are Skyebot™. Use the provided Codex to answer semantically. " .
            "Codex contains glossary, modules, and constitution. " .
            "Interpret user intent naturally — if they ask for glossary terms, modules, or constitution, pull from the relevant section. " .
            "If user asks for 'show' or 'list', provide a clean readable list. " .
            "Never invent content outside the Codex."
        ),
        array("role" => "system", "content" => json_encode($codex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        array("role" => "user", "content" => $prompt)
    );

    $response = callOpenAi($messages);
    sendJsonResponse($response, "codex", array("sessionId" => session_id()));
}
function sendJsonResponse($response, $action = "none", $extra = array(), $status = 200) {
    http_response_code($status);
    $data = array_merge(array(
        "response" => is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response,
        "action"   => $action,
        "sessionId"=> session_id()
    ), $extra);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}
function authenticateUser($username, $password) {
    $validCredentials = array('admin' => password_hash('secret', PASSWORD_DEFAULT));
    return isset($validCredentials[$username]) && password_verify($password, $validCredentials[$username]);
}
function createCrudEntity($entity, $details) {
    file_put_contents(__DIR__ . "/create_$entity.log", json_encode($details) . "\n", FILE_APPEND);
    return true;
}
function readCrudEntity($entity, $criteria) {
    return "Sample $entity details for: " . json_encode($criteria);
}
function updateCrudEntity($entity, $updates) {
    file_put_contents(__DIR__ . "/update_$entity.log", json_encode($updates) . "\n", FILE_APPEND);
    return true;
}
function deleteCrudEntity($entity, $target) {
    file_put_contents(__DIR__ . "/delete_$entity.log", json_encode($target) . "\n", FILE_APPEND);
    return true;
}
function performLogout() {
    session_unset();
    session_destroy();
    session_write_close();
    session_start();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    setcookie('skyelogin_user', '', time() - 3600, '/', 'www.skyelighting.com');
}
function normalizeAddress($address) {
    $address = preg_replace('/\s+/', ' ', trim($address));
    $address = ucwords(strtolower($address));
    return preg_replace_callback('/\b(\d+)(St|Nd|Rd|Th)\b/i',
        function($m) { return $m[1] . strtolower($m[2]); }, $address);
}
function getAssessorApi($stateFIPS, $countyFIPS) {
    if ($stateFIPS !== "04") return null;
    switch ($countyFIPS) {
        case "013": return "https://mcassessor.maricopa.gov/api";
        case "019": return "https://placeholder.pima.az.gov/api";
        default: return null;
    }
}

function normalizeJurisdiction($jurisdiction, $county = null) {
    if (!$jurisdiction) return null;
    $jurisdiction = strtoupper(trim($jurisdiction));
    if ($jurisdiction === "NO CITY/TOWN") return $county ?: "Unincorporated Area";

    static $jurisdictions = null;
    if ($jurisdictions === null) {
        $path = __DIR__ . "/../assets/data/jurisdictions.json";
        $jurisdictions = file_exists($path) ? json_decode(file_get_contents($path), true) : array();
    }
    foreach ($jurisdictions as $name => $info) {
        if (!empty($info['aliases'])) {
            foreach ($info['aliases'] as $alias) {
                if (strtoupper($alias) === $jurisdiction) return $name;
            }
        }
    }
    return ucwords(strtolower($jurisdiction));
}
function getApplicableDisclaimers($reportType, $context = array()) {
    $file = __DIR__ . "/../assets/data/reportDisclaimers.json";
    if (!file_exists($file)) return array("⚠️ Disclaimer library not found.");
    $all = json_decode(file_get_contents($file), true);
    if (!$all || !isset($all[$reportType])) return array("⚠️ No disclaimers defined for $reportType.");

    $result = $all[$reportType]['dataSources'] ?? array();
    if (is_array($context)) {
        foreach ($context as $key => $value) {
            if ($value && isset($all[$reportType][$key])) {
                $result = array_merge($result, $all[$reportType][$key]);
            }
        }
    }
    return array_values(array_unique($result ?: array("⚠️ No applicable disclaimers resolved.")));
}
function callOpenAi($messages) {
    $apiKey = getenv("OPENAI_API_KEY");
    $model = "gpt-4o-mini";

    $lastUserMessage = end($messages);
    $promptText = strtolower($lastUserMessage['content'] ?? "");

    $dynamicPath = __DIR__ . "/dynamicData.json";
    if (file_exists($dynamicPath)) {
        $data = json_decode(file_get_contents($dynamicPath), true);
        if (!empty($data['modules']['reportGenerationSuite']['reportTypesSpec'])) {
            foreach (array_keys($data['modules']['reportGenerationSuite']['reportTypesSpec']) as $reportKey) {
                if (strpos($promptText, strtolower($reportKey)) !== false || strpos($promptText, 'report') !== false) {
                    $model = "gpt-4o"; break;
                }
            }
        }
    }

    $payload = json_encode(array(
        "model" => $model, "messages" => $messages,
        "temperature" => 0.1, "max_tokens" => 200
    ), JSON_UNESCAPED_SLASHES);

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER => array("Content-Type: application/json", "Authorization: Bearer " . $apiKey),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25
    ));
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return trim($result["choices"][0]["message"]["content"] ?? "❌ Invalid AI response");
}
function googleSearch($query) {
    $apiKey = getenv("GOOGLE_SEARCH_KEY");
    $cx     = getenv("GOOGLE_SEARCH_CX");
    if (!$apiKey || !$cx) return ["error" => "Google Search API not configured."];

    $url = "https://www.googleapis.com/customsearch/v1?q=" . urlencode($query) .
           "&key=$apiKey&cx=$cx";

    $res = file_get_contents($url);
    return $res ? json_decode($res, true) : ["error" => "Google Search failed."];
}