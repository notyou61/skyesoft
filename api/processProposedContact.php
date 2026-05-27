<?php
declare(strict_types=1);

/**
 * Skyesoft — processProposedContact.php
 * Main Orchestration + Report Generation
 * Version: 1.6.0
 * Purpose: Full pipeline from raw input → proposal snapshot → PDF report
 */

// =====================================================
// FORCE FRESH + UTILS
// =====================================================
error_log("=== PROCESSPROPOSEDCONTACT.PHP v1.6.0 ===" . date('Y-m-d H:i:s'));

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

require_once __DIR__ . '/utils/detectAndProposeContact.utils.php';

$runForcedTest = false;

if ($runForcedTest) {
    $forcedAddress = "3145 N 33rd Ave Phoenix AZ 85017";
    $forcedParcels = lookupMaricopaParcel($forcedAddress);
    error_log("[TEST] Maricopa lookup returned " . count($forcedParcels) . " parcels");
}

// =====================================================
// RUNTIME SETUP
// =====================================================
if (!headers_sent()) {
    header('Content-Type: application/json');
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/envLoader.php';

skyesoftLoadEnv();
$pdo = getPDO();

error_log('[pipeline-entry] processProposedContact START ' . microtime(true));

// =====================================================
// INPUT RESOLUTION (Preserved from original)
// =====================================================
$rawInputOriginal = '';
$activitySessionId = $_POST['activitySessionId'] ?? $_SESSION['activitySessionId'] ?? session_id() ?? '';

if (isset($query) && is_string($query) && trim($query) !== '') {
    $rawInputOriginal = $query;
} else {
    $rawJson = file_get_contents('php://input');
    $input = json_decode($rawJson, true) ?? [];
    $rawInputOriginal = $input['input'] ?? '';
    if (!empty($input['activitySessionId'])) $activitySessionId = $input['activitySessionId'];
}

$rawInput = trim($rawInputOriginal);
if ($rawInput === '') {
    jsonError('No input provided');
}

// =====================================================
// PC-4 Explicit Location-Only Support (Preserved)
// =====================================================
$isExplicitLocationOnlyIntent = false;
$declaredEntityName = null;

if (preg_match('/(?:add\s+)?(?:this\s+as\s+)?(?:a\s+new\s+)?location\s+only\s+for\s+([^\n\r:]+)/i', $rawInput, $matches)) {
    $isExplicitLocationOnlyIntent = true;
    $declaredEntityName = trim($matches[1]);
    $rawInput = preg_replace('/(?:add\s+)?(?:this\s+as\s+)?(?:a\s+new\s+)?location\s+only\s+for\s+[^\n\r:]+:?/i', '', $rawInput);
    $rawInput = trim($rawInput);
}

if ($rawInput === '') jsonError('No input after directive processing');

// =====================================================
// AI PARSING + ENRICHMENT + PCM (Preserved)
// =====================================================
$systemPrompt = "..."; // (Keep your full prompt from original)
$extractionPrompt = "Clean and normalize the following... INPUT: {$rawInput}";

$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) jsonError('OPENAI_API_KEY not found');

// ... (Keep all AI call, schema enforcement, phone extraction, normalization, Google, Census, Maricopa parcel, duplicate checks, PCM logic exactly as in original) ...

// [INSERT YOUR FULL SECTIONS 04 through PCM DECISION HERE — unchanged]

// =====================================================
// PROPOSAL SNAPSHOT + PDF REPORT GENERATION
// =====================================================

$proposalResult = createProposalSnapshot(
    $rawInputOriginal,
    $parsed,
    $pcm,
    $locationValidation,
    $data ?? [],
    $meta,
    $resolution ?? [],
    $persistence ?? [],
    $activitySessionId
);

$proposalId = $proposalResult['proposalId'];

// Generate PDF Report
$reportPath = generateProposalReport($proposalId, $proposalResult['snapshot']);

if ($reportPath && file_exists($reportPath)) {
    $reportUrl = "/skyesoft/reports/proposals/{$proposalId}.pdf";
    error_log("[REPORT] ✅ PDF generated: {$reportPath}");
} else {
    $reportUrl = null;
    error_log("[REPORT] ⚠️ PDF generation failed");
}

// =====================================================
// FINAL RESPONSE
// =====================================================
require_once __DIR__ . '/detectAndProposeContact.response.php';

// Override/add report info in response
// (The response.php will pick up $proposalId if we declare it global)
global $proposalId, $reportUrl;

echo json_encode([
    'status'        => 'proposed',
    'confidence'    => $aiData['confidence'] ?? 85,
    'success'       => true,
    'proposalId'    => $proposalId,
    'proposalReport'=> [
        'proposalId' => $proposalId,
        'reportUrl'  => $reportUrl,
        'viewUrl'    => "/skyesoft/reports/proposals/view.php?id={$proposalId}",
        'status'     => $reportUrl ? 'generated' : 'pending'
    ],
    // ... rest of your original response structure
], JSON_UNESCAPED_SLASHES);