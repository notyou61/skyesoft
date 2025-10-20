<?php
// ======================================================================
// 🧾 Skyebot™ Intent Report Dispatcher (Semantic Edition v2.0)
// Unified Codex + SSE Integration (Regex-Free, Improved Normalization)
// ----------------------------------------------------------------------
// Purpose:
// • Dynamically generate reports for any Skyesoft Codex or SSE object
// • Automatically infer $target if missing or invalid (via resolveSkyesoftObject())
// • Route through generateReports.php and return a clean JSON payload
// ======================================================================

#region 🧱 Environment Setup
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../env_boot.php';
date_default_timezone_set('America/Phoenix');

if (session_status() === PHP_SESSION_NONE) @session_start();
$sessionId = session_id();
#endregion

#region 🧩 Input & Validation
// Expect $prompt and $target from parent context
if (!isset($prompt)) $prompt = isset($_POST['prompt']) ? $_POST['prompt'] : '';
if (!isset($target)) $target = isset($_POST['target']) ? $_POST['target'] : '';
if (!isset($dynamicData)) $dynamicData = array();

error_log("🧾 [intent_report] invoked with target: " . ($target ?: 'none') . " | prompt: {$prompt}");
#endregion

#region 🔍 Step 1: Validate or Resolve Target
$originalTarget = $target;  // 🆕 For logging

// 🆕 IMPROVED: Don't smash spaces/lowercase here—let resolver handle camelCase
if (!is_string($target) || trim($target) === '') {
    $target = null;
}

// 🆕 ALWAYS attempt resolution if no/invalid target or no direct Codex match
global $codex;  // Ensure available
if (!$target || !isset($codex[$target])) {
    $resolved = resolveSkyesoftObject($prompt, $dynamicData);
    error_log("🔗 Dispatcher resolver for '{$prompt}': " . json_encode($resolved));
    if (is_array($resolved) && isset($resolved['key']) && $resolved['confidence'] > 70) {
        $target = $resolved['key'];
        error_log("🔗 Dispatcher auto-resolved → {$target} ({$resolved['confidence']}%) [from: {$originalTarget}]");
    } else {
        sendJsonResponse("⚠️ No valid report target resolved from '{$prompt}' (conf: " . ($resolved['confidence'] ?? 0) . ").", "error", array(
            "sessionId" => $sessionId,
            "prompt"    => $prompt,
            "originalTarget" => $originalTarget
        ));
        exit;
    }
} else {
    error_log("✅ Direct target match: {$target}");
}
#endregion

#region 🧭 Step 2: Normalize Report Metadata
$reportTitle = ucwords(str_replace(array('-', '_'), ' ', $target));
$generatorUrl = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . urlencode($target);
$payload = json_encode(array("slug" => $target));  // 🆕 Use resolved $target (clean slug)

error_log("🧭 Preparing report request for '{$target}' at {$generatorUrl}");
#endregion

#region ⚙️ Step 3: Execute Report Generation
$ch = curl_init($generatorUrl);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 30
));

$result = curl_exec($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);
#endregion

#region 📄 Step 4: Parse & Respond
if ($code === 200 && preg_match('/✅ PDF created successfully:\s*(.+)$/m', $result, $matches)) {
    $link = trim($matches[1]);
    sendJsonResponse(
        "✅ Information Sheet generated for {$target}",
        "sheet_generated",
        array(
            "link"      => $link,
            "target"    => $target,
            "title"     => $reportTitle,
            "sessionId" => $sessionId,
            "resolvedFrom" => $originalTarget  // 🆕 For audit
        )
    );
    exit;
}

if ($err) {
    error_log("❌ CURL Error: {$err}");
}

sendJsonResponse("⚠️ Report generation failed (HTTP {$code}).", "error", array(
    "body"      => substr($result, 0, 200),
    "sessionId" => $sessionId,
    "target"    => $target,
    "originalTarget" => $originalTarget  // 🆕 For debug
));
exit;
#endregion