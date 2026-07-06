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
    $pdo = getPDO();
    if ($actionId > 0) {
        error_log("[PREP] Loading actionResponseData for actionId: {$actionId} (universal prep)");
        $stmt = $pdo->prepare("SELECT actionResponseData FROM tblActions WHERE actionId = ? LIMIT 1");
        $stmt->execute([$actionId]);
        $action = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($action && !empty($action['actionResponseData'])) {
            $payload = json_decode($action['actionResponseData'], true);
            if (is_array($payload)) {
                $input = array_merge($input, $payload);
                error_log("[PREP] ✅ Successfully merged actionResponseData. New keys: " . json_encode(array_keys($input)));
            } else {
                error_log("[PREP] ⚠️ Invalid JSON in actionResponseData for actionId {$actionId}");
            }
        } else {
            error_log("[PREP] ⚠️ No actionResponseData found for actionId {$actionId}");
        }
    } else {
        error_log("[PREP] No actionId provided — using direct input only");
    }
    // =====================================================
    // LOAD FULL PROPOSAL SNAPSHOT (Critical for reportArtifacts)
    // =====================================================
    $proposalId = $input['proposalId'] ?? $input['data']['proposalId'] ?? null;
    if ($proposalId) {
        $snapshotPath = __DIR__ . '/../data/runtimeEphemeral/proposals/' . $proposalId . '.json';
        if (file_exists($snapshotPath)) {
            $fullSnapshot = json_decode(file_get_contents($snapshotPath), true);
            if (is_array($fullSnapshot)) {
                $input = array_merge($input, $fullSnapshot);
                error_log("[PREP] ✅ Loaded full snapshot for proposalId: " . $proposalId);
            }
        } else {
            error_log("[PREP] ⚠️ Snapshot not found: " . $snapshotPath);
        }
    } else {
        error_log("[PREP] ⚠️ No proposalId found in input");
    }
    // =====================================================
    // UNIVERSAL IMAGE & ARTIFACT PREPARATION (MAPPED TO CANONICAL ARTIFACTS)
    // =====================================================
    error_log("[IMAGES] Starting universal artifact mapping for reportType: {$reportType}");
    if ($proposalId) {
        if (!isset($input['reportArtifacts'])) {
            $input['reportArtifacts'] = [];
        }
        $streetViewPath = resolveReportArtifactPath((string)$proposalId, 'STR', 'jpg');
        if ($streetViewPath && file_exists($streetViewPath)) {
            $input['reportArtifacts']['streetview'] = $streetViewPath;
            $input['reportArtifacts']['streetviewUrl'] = str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $streetViewPath);
            error_log("[IMAGES] ✅ Canonical Street View mapped: " . $streetViewPath);
        }
        $satellitePath = resolveReportArtifactPath((string)$proposalId, 'SAT', 'png');
        if ($satellitePath && file_exists($satellitePath)) {
            $input['reportArtifacts']['satellite'] = $satellitePath;
            $input['reportArtifacts']['satelliteUrl'] = str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $satellitePath);
            error_log("[IMAGES] ✅ Canonical Satellite mapped: " . $satellitePath);
        }
        $parcelPath = resolveReportArtifactPath((string)$proposalId, 'PAR', 'png');
        if ($parcelPath && file_exists($parcelPath)) {
            $input['reportArtifacts']['parcelmap'] = $parcelPath;
            $input['reportArtifacts']['parcelmapUrl'] = str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $parcelPath);
            $input['reportArtifacts']['parcel_maps'] = [$parcelPath];
            error_log("[IMAGES] ✅ Canonical Parcel Map mapped: " . $parcelPath);
        }
    } else {
        error_log("[IMAGES] Skipping artifact mapping - no valid proposalId provided");
    }
    require_once $reportHandler['file'];
    $generator = $reportHandler['generator'] ?? null;
    if (!$generator || !function_exists($generator)) {
        throw new Exception('Generator function not found: ' . ($generator ?? 'null'));
    }
    error_log("[PREP] Calling generator: {$generator} with prepared input");
    $report = call_user_func($generator, $input);
    if (empty($report) || !is_array($report)) {
        throw new Exception('Report generator returned invalid data');
    }
    require_once __DIR__ . '/../reports/templates/baseReport.php';
    $pdfContent = renderReport($report);
    if (empty($pdfContent)) {
        throw new Exception('PDF generation returned empty content');
    }
    $filename = $report['reportFilename'] ?? $report['reportTitle'] ?? ucwords(str_replace('_', ' ', $reportType)) . ' Report';
    $filename = preg_replace('/[\\\\\/:"*?<>|]+/', '', trim($filename));
    if (empty($filename)) {
        $filename = 'Report';
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $pdfContent;
    exit;
} catch (Throwable $e) {
    error_log("[generateReports] ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString()
    ]);
    exit;
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