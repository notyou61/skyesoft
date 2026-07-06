<?php
declare(strict_types=1);
// =============================================
//  Skyesoft — generateReports.php
//  Main Report Controller
//  Version: 1.4.0 (Canonical Artifacts Only)
// =============================================

#region SECTION 00 - Error Reporting & Headers
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}
ini_set('error_log', __DIR__ . '/logs/php-error.log');
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
#endregion

#region SECTION 01 - Request Intake
$input = [];
if (!empty($_POST['payload'])) {
    $input = json_decode($_POST['payload'], true) ?? [];
    error_log("[generateReports] Received via POST['payload'] — Keys: " . json_encode(array_keys($input)));
} else {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    error_log("[generateReports] Received via php://input or direct POST");
}
require_once __DIR__ . '/dbConnect.php';
error_log("Report Request Received Keys: " . json_encode(array_keys($input)));
$reportType = strtolower(trim($input['reportType'] ?? ''));
if (empty($reportType) && !empty($_GET['reportType'])) {
    $reportType = strtolower(trim($_GET['reportType']));
}
$actionId = isset($_GET['actionId']) ? (int)$_GET['actionId'] : (int)($input['actionId'] ?? 0);
error_log("[generateReports] reportType = '{$reportType}' | actionId = {$actionId}");
error_log("=== generateReports.php LOADED ===" . date('Y-m-d H:i:s'));
if (empty($reportType) && !empty($input['data'])) {
    $reportType = 'contact_proposal';
}
$proposalId = $input['proposalId'] ?? null;
#endregion

#region SECTION 02 - Validation
if (empty($reportType)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'reportType is required',
        'received_keys' => array_keys($input)
    ]);
    exit;
}
#endregion

#region SECTION 03 - Report Registry
require_once __DIR__ . '/../reports/reportRegistry.php';
$reportHandler = getReportHandler($reportType);
if (!$reportHandler) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown report type: ' . $reportType]);
    exit;
}
#endregion

#region SECTION 04 - Report Preparation & Generation (Universal)
try {
    $pdo = getPDO(); //[cite: 3]
    if ($actionId > 0) { //[cite: 3]
        error_log("[PREP] Loading actionResponseData for actionId: {$actionId} (universal prep)"); //[cite: 3]
        $stmt = $pdo->prepare("SELECT actionResponseData FROM tblActions WHERE actionId = ? LIMIT 1"); //[cite: 3]
        $stmt->execute([$actionId]); //[cite: 3]
        $action = $stmt->fetch(PDO::FETCH_ASSOC); //[cite: 3]
        if ($action && !empty($action['actionResponseData'])) { //[cite: 3]
            $payload = json_decode($action['actionResponseData'], true); //[cite: 3]
            if (is_array($payload)) { //[cite: 3]
                $input = array_merge($input, $payload); //[cite: 3]
                error_log("[PREP] ✅ Successfully merged actionResponseData. New keys: " . json_encode(array_keys($input))); //[cite: 3]
            } else { //[cite: 3]
                error_log("[PREP] ⚠️ Invalid JSON in actionResponseData for actionId {$actionId}"); //[cite: 3]
            }
        } else { //[cite: 3]
            error_log("[PREP] ⚠️ No actionResponseData found for actionId {$actionId}"); //[cite: 3]
        }
    } else { //[cite: 3]
        error_log("[PREP] No actionId provided — using direct input only"); //[cite: 3]
    }
    // =====================================================
    // LOAD FULL PROPOSAL SNAPSHOT (Critical for reportArtifacts)
    // =====================================================
    $proposalId = $input['proposalId'] ?? $input['data']['proposalId'] ?? null; //[cite: 3]
    if ($proposalId) { //[cite: 3]
        $snapshotPath = __DIR__ . '/../data/runtimeEphemeral/proposals/' . $proposalId . '.json'; //[cite: 3]
        if (file_exists($snapshotPath)) { //[cite: 3]
            $fullSnapshot = json_decode(file_get_contents($snapshotPath), true); //[cite: 3]
            if (is_array($fullSnapshot)) { //[cite: 3]
                $input = array_merge($input, $fullSnapshot); //[cite: 3]
                error_log("[PREP] ✅ Loaded full snapshot for proposalId: " . $proposalId); //[cite: 3]
            }
        } else { //[cite: 3]
            error_log("[PREP] ⚠️ Snapshot not found: " . $snapshotPath); //[cite: 3]
        }
    } else { //[cite: 3]
        error_log("[PREP] ⚠️ No proposalId found in input"); //[cite: 3]
    }

    // =====================================================
    // EXTRACT AND INJECT DYNAMIC AI CONTENT LINE
    // =====================================================
    $dynamicContentLine = $input['narratives']['contentLine'] ?? $input['contentLine'] ?? null;
    if (!empty($dynamicContentLine)) {
        error_log("[PREP] Injecting dynamic contentLine: '{$dynamicContentLine}'");
        $input['reportSubtitle'] = $dynamicContentLine;
    }

    // =====================================================
    // UNIVERSAL IMAGE & ARTIFACT PREPARATION (MAPPED TO CANONICAL ARTIFACTS)
    // =====================================================
    error_log("[IMAGES] Starting universal artifact mapping for reportType: {$reportType}"); //[cite: 3]
    if ($proposalId) { //[cite: 3]
        if (!isset($input['reportArtifacts'])) { //[cite: 3]
            $input['reportArtifacts'] = []; //[cite: 3]
        }
        $streetViewPath = resolveReportArtifactPath((string)$proposalId, 'STR', 'jpg'); //[cite: 3]
        if ($streetViewPath && file_exists($streetViewPath)) { //[cite: 3]
            $input['reportArtifacts']['streetview'] = $streetViewPath; //[cite: 3]
            $input['reportArtifacts']['streetviewUrl'] = str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $streetViewPath); //[cite: 3]
            error_log("[IMAGES] ✅ Canonical Street View mapped: " . $streetViewPath); //[cite: 3]
        }
        $satellitePath = resolveReportArtifactPath((string)$proposalId, 'SAT', 'png'); //[cite: 3]
        if ($satellitePath && file_exists($satellitePath)) { //[cite: 3]
            $input['reportArtifacts']['satellite'] = $satellitePath; //[cite: 3]
            $input['reportArtifacts']['satelliteUrl'] = str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $satellitePath); //[cite: 3]
            error_log("[IMAGES] ✅ Canonical Satellite mapped: " . $satellitePath); //[cite: 3]
        }
        $parcelPath = resolveReportArtifactPath((string)$proposalId, 'PAR', 'png'); //[cite: 3]
        if ($parcelPath && file_exists($parcelPath)) { //[cite: 3]
            $input['reportArtifacts']['parcelmap'] = $parcelPath; //[cite: 3]
            $input['reportArtifacts']['parcelmapUrl'] = str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $parcelPath); //[cite: 3]
            $input['reportArtifacts']['parcel_maps'] = [$parcelPath]; //[cite: 3]
            error_log("[IMAGES] ✅ Canonical Parcel Map mapped: " . $parcelPath); //[cite: 3]
        }
    } else { //[cite: 3]
        error_log("[IMAGES] Skipping artifact mapping - no valid proposalId provided"); //[cite: 3]
    }
    require_once $reportHandler['file']; //[cite: 3]
    $generator = $reportHandler['generator'] ?? null; //[cite: 3]
    if (!$generator || !function_exists($generator)) { //[cite: 3]
        throw new Exception('Generator function not found: ' . ($generator ?? 'null')); //[cite: 3]
    }
    error_log("[PREP] Calling generator: {$generator} with prepared input"); //[cite: 3]
    $report = call_user_func($generator, $input); //[cite: 3]
    if (empty($report) || !is_array($report)) { //[cite: 3]
        throw new Exception('Report generator returned invalid data'); //[cite: 3]
    }

    // Force override if the downstream generator enforces the legacy default
    if (!empty($dynamicContentLine)) {
        $report['reportSubtitle'] = $dynamicContentLine;
    }

    require_once __DIR__ . '/../reports/templates/baseReport.php'; //[cite: 3]
    $pdfContent = renderReport($report); //[cite: 3]
    if (empty($pdfContent)) { //[cite: 3]
        throw new Exception('PDF generation returned empty content'); //[cite: 3]
    }
    $filename = $report['reportFilename'] ?? $report['reportTitle'] ?? ucwords(str_replace('_', ' ', $reportType)) . ' Report'; //[cite: 3]
    $filename = preg_replace('/[\\\\\/:"*?<>|]+/', '', trim($filename)); //[cite: 3]
    if (empty($filename)) { //[cite: 3]
        $filename = 'Report'; //[cite: 3]
    }
    header('Content-Type: application/pdf'); //[cite: 3]
    header('Content-Disposition: inline; filename="' . $filename . '.pdf"'); //[cite: 3]
    header('Content-Length: ' . strlen($pdfContent)); //[cite: 3]
    header('Cache-Control: no-cache, must-revalidate'); //[cite: 3]
    header('Pragma: no-cache'); //[cite: 3]
    echo $pdfContent; //[cite: 3]
    exit; //[cite: 3]
} catch (Throwable $e) { //[cite: 3]
    error_log("[generateReports] ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine()); //[cite: 3]
    http_response_code(500); //[cite: 3]
    echo json_encode([ //[cite: 3]
        'error'   => $e->getMessage(), //[cite: 3]
        'file'    => basename($e->getFile()), //[cite: 3]
        'line'    => $e->getLine(), //[cite: 3]
        'trace'   => $e->getTraceAsString() //[cite: 3]
    ]); //[cite: 3]
    exit; //[cite: 3]
}
#endregion

#region SECTION 05 - Helper Functions
// Locate an existing proposal artifact from the canonical root directory
function resolveReportArtifactPath(string $proposalId, string $purpose, string $ext = 'jpg'): ?string
{
    $artifactsDir = '/home/notyou64/public_html/skyesoft/artifacts/';
    if (is_dir($artifactsDir)) {
        $pattern = "TMP-IMG-{$purpose}-" . str_pad($proposalId, 6, '0', STR_PAD_LEFT) . "-*.{$ext}";
        $matches = glob($artifactsDir . $pattern);
        if (!empty($matches)) {
            return $matches[0];
        }
    }
    error_log("[generateReports] ⚠️ Missing expected artifact in root: ID {$proposalId} | Purpose: {$purpose}");
    return null;
}
#endregion