<?php
// ======================================================================
// 🧾 Skyebot™ Intent Report Dispatcher (Semantic Edition v2.1)
// Unified Codex + SSE Integration — Public URL patch applied
// ----------------------------------------------------------------------
// Purpose:
// • Dynamically generate reports for any Skyesoft Codex or SSE object
// • Automatically infer $target if missing or invalid (via resolver)
// • Converts local PDF path → public link for user display
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
if (!isset($prompt)) $prompt = isset($_POST['prompt']) ? $_POST['prompt'] : '';
if (!isset($target)) $target = isset($_POST['target']) ? $_POST['target'] : '';
if (!isset($dynamicData)) $dynamicData = array();

error_log("🧾 [intent_report] invoked with target: " . ($target ?: 'none') . " | prompt: {$prompt}");
#endregion

#region 🔍 Step 1: Validate or Resolve Target
$originalTarget = $target;
if (!is_string($target) || trim($target) === '') $target = null;

global $codex;
if (!$target || !isset($codex[$target])) {
    $resolved = resolveSkyesoftObject($prompt, $dynamicData);
    error_log("🔗 Dispatcher resolver for '{$prompt}': " . json_encode($resolved));

    if (is_array($resolved) && isset($resolved['key']) && isset($resolved['confidence']) && $resolved['confidence'] > 70) {
        $target = $resolved['key'];
        error_log("🔗 Dispatcher auto-resolved → {$target} ({$resolved['confidence']}%) [from: {$originalTarget}]");
    } else {
        $conf = isset($resolved['confidence']) ? $resolved['confidence'] : 0;
        sendJsonResponse(
            "⚠️ No valid report target resolved from '{$prompt}' (conf: " . $conf . ").",
            "error",
            array(
                "sessionId"      => $sessionId,
                "prompt"         => $prompt,
                "originalTarget" => $originalTarget
            )
        );
        exit;
    }
} else {
    error_log("✅ Direct target match: {$target}");
}
#endregion

#region 🧭 Step 2: Normalize Report Metadata
$reportTitle  = ucwords(str_replace(array('-', '_'), ' ', $target));
$generatorUrl = 'https://www.skyelighting.com/skyesoft/api/generateReports.php?module=' . urlencode($target);
$payload      = json_encode(array("slug" => $target));
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

#region 📄 Step 4: Parse & Respond (patched for link output)
if ($code === 200 && preg_match('/✅ PDF created successfully:\s*(.+\.pdf)/i', $result, $matches)) {
    $pdfPath = trim($matches[1]);

    // 🧩 Convert local path → public URL (GoDaddy-safe)
    $publicUrl = $pdfPath;
    if (strpos($pdfPath, '/home/notyou64/public_html/skyesoft/') === 0) {
        $publicUrl = str_replace(
            '/home/notyou64/public_html',
            'https://www.skyelighting.com',
            $pdfPath
        );
    }
    $publicUrl = str_replace(' ', '%20', $publicUrl);

    sendJsonResponse(
        "📘 The **{$reportTitle}** sheet is ready.\n\n📄 [Open Report]({$publicUrl})",
        "sheet_generated",
        array(
            "link"          => $publicUrl,
            "target"        => $target,
            "title"         => $reportTitle,
            "sessionId"     => $sessionId,
            "resolvedFrom"  => $originalTarget
        )
    );
    exit;
}

if ($err) error_log("❌ CURL Error: {$err}");

sendJsonResponse("⚠️ Report generation failed (HTTP {$code}).", "error", array(
    "body"          => substr($result, 0, 200),
    "sessionId"     => $sessionId,
    "target"        => $target,
    "originalTarget"=> $originalTarget
));
exit;
#endregion