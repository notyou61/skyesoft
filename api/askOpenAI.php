<?php
// ======================================================================
//  Skyebot™ — askOpenAI.php
//  MTCO Tabula-Rasa Foundation (Constitution-Compliant)
//  PHP 5.6 Compatible
// ======================================================================

// ----------------------------------------------------------------------
//  ARTICLE I COMPLIANCE — SAFETY WRAPPERS
// ----------------------------------------------------------------------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

// Minimal JSON error shield
set_error_handler(function($errno, $errstr){
    echo json_encode(array(
        "response" => "⚠️ PHP Error: ".strip_tags($errstr),
        "action"   => "error"
    ));
    exit;
});

// ----------------------------------------------------------------------
//  STEP 1 — Load User Input (CLI or Web)
// ----------------------------------------------------------------------
$raw = @file_get_contents('php://input');
if (PHP_SAPI === 'cli' && empty($raw)) {
    global $argv;
    if (!empty($argv[1])) $raw = $argv[1];
}

$data = json_decode(trim($raw), true);
if (!is_array($data)) {
    echo json_encode(array(
        "response" => "❌ Invalid JSON payload.",
        "action"   => "none"
    ));
    exit;
}

$prompt = isset($data['prompt']) ? trim($data['prompt']) : "";
$conversation = isset($data['conversation']) && is_array($data['conversation'])
    ? $data['conversation'] : array();

if ($prompt === "") {
    echo json_encode(array(
        "response" => "❌ Empty prompt.",
        "action"   => "none"
    ));
    exit;
}

// ----------------------------------------------------------------------
//  STEP 2 — Load Codex + SSE (Authoritative Sources)
// ----------------------------------------------------------------------
$codexPath = __DIR__ . '/../assets/data/codex.json';
$dynamicUrl = "https://www.skyelighting.com/skyesoft/api/getDynamicData.php";

$codex = array();
if (file_exists($codexPath)) {
    $codex = json_decode(file_get_contents($codexPath), true);
}

$ch = curl_init($dynamicUrl);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false
));
$sseRaw = curl_exec($ch);
curl_close($ch);

$sse = json_decode($sseRaw, true);
if (!is_array($sse)) $sse = array();

// ----------------------------------------------------------------------
//  INLINE PARLIAMENTARIAN v1.0 — Codex-Compliant Judicial Review
//  (No external files required — designed for askOpenAI.php)
// ----------------------------------------------------------------------
function parliamentarian_review($prompt, $codex)
{
    $result = array(
        "blocked" => false,
        "reason"  => "",
        "prompt"  => $prompt
    );

    if (!$prompt || !is_array($codex)) {
        return $result;
    }

    $p = strtolower($prompt);

    // --------------------------------------------------------------
    // ARTICLE II — Codex Cannot Be Modified or Overridden
    // --------------------------------------------------------------
    if (strpos($p, "change the codex") !== false ||
        strpos($p, "override the codex") !== false ||
        strpos($p, "modify constitution") !== false ||
        strpos($p, "edit article") !== false) {

        $result["blocked"] = true;
        $result["reason"]  = "❌ Blocked by Article II — The Codex cannot be altered via prompt.";
        return $result;
    }

    // --------------------------------------------------------------
    // ARTICLE VII — SSE Cannot Be Invented
    // --------------------------------------------------------------
    if (strpos($p, "fake time") !== false ||
        strpos($p, "set time to") !== false ||
        strpos($p, "make up weather") !== false ||
        strpos($p, "override sse") !== false) {

        $result["blocked"] = true;
        $result["reason"]  = "❌ Blocked by Article VII — Time and weather must come from SSE.";
        return $result;
    }

    // --------------------------------------------------------------
    // ARTICLE IX — Operational Integrity (Prevent Drift)
    // Detect dangerous vague code changes or global rewrites
    // --------------------------------------------------------------
    $dangerous = array("rewrite system", "rewrite all", "change everything",
                       "replace all code", "refactor whole system");

    foreach ($dangerous as $term) {
        if (strpos($p, $term) !== false) {
            $result["blocked"] = true;
            $result["reason"]  = "⚠️ Blocked — Request too broad; violates Article IX: System Integrity.";
            return $result;
        }
    }

    // --------------------------------------------------------------
    // ARTICLE X — Amendment Required (Adds roles, articles, doctrines)
    // --------------------------------------------------------------
    if (strpos($p, "new article") !== false ||
        strpos($p, "add article") !== false ||
        strpos($p, "add role") !== false ||
        strpos($p, "create new standard") !== false) {

        $result["blocked"] = true;
        $result["reason"]  = "⚠️ Requires Amendment — Article X procedures must be followed.";
        return $result;
    }

    // --------------------------------------------------------------
    // SAFE NORMALIZATION (OPTIONAL)
    // Parliamentarian may rewrite prompt for clarity
    // --------------------------------------------------------------
    // Example: strip redundant words or normalize phrasing
    $result["prompt"] = trim(preg_replace('/\s+/', ' ', $prompt));

    return $result;
}

// ----------------------------------------------------------------------
//  STEP 3 — Parliamentarian Review (Always ON)
// ----------------------------------------------------------------------
$parResult = parliamentarian_review($prompt, $codex);

if ($parResult['blocked']) {
    echo json_encode(array(
        "response" => $parResult['reason'],
        "action"   => "blocked"
    ));
    exit;
}

$governedPrompt = $parResult['prompt'];


// The Parliamentarian may modify the prompt (Codex-legal normalization)
$governedPrompt = $parResult['prompt'];

// ----------------------------------------------------------------------
//  INLINE ROUTER v1.0 — Deterministic, Constitution-Compliant
//  No AI calls, no drift, rule-based, always predictable.
// ----------------------------------------------------------------------
function skyebot_route($prompt, $codex, $sse, $conversation)
{
    $p = strtolower(trim($prompt));

    // Default route
    $route = array(
        "intent"     => "general",
        "target"     => null,
        "confidence" => 0.60
    );

    // --------------------------------------------------------------
    // LOGOUT INTENT
    // --------------------------------------------------------------
    if (strpos($p, "logout") !== false ||
        strpos($p, "log out") !== false) {

        $route["intent"] = "logout";
        $route["confidence"] = 0.99;
        return $route;
    }

    // --------------------------------------------------------------
    // REPORT INTENT (Codex Modules)
    // Any phrase like "create report", "make sheet", "generate …"
    // --------------------------------------------------------------
    $reportKeywords = array("report", "sheet", "information sheet", "create report", "make report");

    foreach ($reportKeywords as $k) {
        if (strpos($p, $k) !== false) {
            $route["intent"] = "report";
            $route["confidence"] = 0.95;

            // Attempt to match Codex module keys
            if (isset($codex["modules"])) {
                foreach ($codex["modules"] as $slug => $modData) {
                    $cleanSlug = strtolower(preg_replace('/[^a-z0-9]/', '', $slug));
                    if (strpos($p, $cleanSlug) !== false ||
                        strpos($p, strtolower($slug)) !== false) {
                        $route["target"] = $slug;
                        break;
                    }
                }
            }

            return $route;
        }
    }

    // --------------------------------------------------------------
    // CRUD INTENT (Contacts, Entities, Locations)
    // --------------------------------------------------------------
    if (strpos($p, "add contact") !== false ||
        strpos($p, "create contact") !== false ||
        strpos($p, "new contact") !== false ||
        strpos($p, "update contact") !== false ||
        strpos($p, "delete contact") !== false) {

        $route["intent"] = "crud";
        $route["confidence"] = 0.90;

        // Basic target inference (will be refined later)
        $route["target"] = "contacts";
        return $route;
    }

    // --------------------------------------------------------------
    // TEMPORAL INTENT — "What time is it?", “when”, “how long”
    // --------------------------------------------------------------
    $temporalKeywords = array("what time", "time is it", "when does", "how long", "sunrise", "sunset", "holiday");

    foreach ($temporalKeywords as $k) {
        if (strpos($p, $k) !== false) {
            $route["intent"] = "temporal";
            $route["confidence"] = 0.92;
            $route["target"] = "timeIntervalStandards"; // Codex standard
            return $route;
        }
    }

    // --------------------------------------------------------------
    // DEFAULT — GENERAL CHAT
    // --------------------------------------------------------------
    return $route;
}

// ----------------------------------------------------------------------
//  STEP 4 — Semantic Routing (Intent)
// ----------------------------------------------------------------------
require_once __DIR__ . '/ai/router.php';
$route = skyebot_route($governedPrompt, $codex, $sse, $conversation);

// route = ["intent" => "...", "target" => "...", "confidence" => 0.xx]

// ----------------------------------------------------------------------
//  STEP 5 — Intent Dispatch
// ----------------------------------------------------------------------
$response = array(
    "response" => "",
    "action"   => "none"
);

switch ($route['intent']) {

    case "logout":
        require_once __DIR__ . "/dispatchers/logout.php";
        $response = skyebot_do_logout();
        break;

    case "report":
        require_once __DIR__ . "/dispatchers/report.php";
        $response = skyebot_generate_report($route['target'], $codex, $sse);
        break;

    case "crud":
        require_once __DIR__ . "/dispatchers/crud.php";
        $response = skyebot_handle_crud($route['target'], $prompt);
        break;

    case "temporal":
        require_once __DIR__ . "/dispatchers/temporal.php";
        $response = skyebot_temporal_resolve($prompt, $sse, $codex);
        break;

    case "general":
    default:
        require_once __DIR__ . "/dispatchers/general.php";
        $response = skyebot_general_chat($prompt, $codex, $sse);
        break;
}

// ----------------------------------------------------------------------
//  STEP 6 — Output
// ----------------------------------------------------------------------
echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;
