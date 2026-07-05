<?php
declare(strict_types=1);


// =============================================
//  Skyesoft — generateReports.php
//  Main Report Controller
//  Version: 1.3.0
//  Last Updated: 2026-05-30
// =============================================

#region SECTION 00 - Error Reporting & Headers

// This file is the main entry point for generating PDF reports based on incoming data.
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

// Force all error_log() messages from this file to go to our known log file
ini_set('error_log', __DIR__ . '/logs/php-error.log');

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

#endregion

#region SECTION 01 - Request Intake

// Handle both direct JSON and form POST with 'payload' key
$input = [];

if (!empty($_POST['payload'])) {
    // New Form POST method (recommended)
    $input = json_decode($_POST['payload'], true) ?? [];
    error_log("[generateReports] Received via POST['payload'] — Keys: " . json_encode(array_keys($input)));
} 
else {
    // Legacy: raw JSON body
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    error_log("[generateReports] Received via php://input or direct POST");
}

require_once __DIR__ . '/dbConnect.php';   // or wherever getPDO() is defined

// Debug received data
error_log("Report Request Received Keys: " . json_encode(array_keys($input)));

$reportType = strtolower(trim($input['reportType'] ?? ''));

// =====================================================
// GET Request Support (for Location Review Reports)
// =====================================================
if (empty($reportType) && !empty($_GET['reportType'])) {
    $reportType = strtolower(trim($_GET['reportType']));
}

$actionId = isset($_GET['actionId'])
    ? (int)$_GET['actionId']
    : (int)($input['actionId'] ?? 0);

error_log("[generateReports] reportType = '{$reportType}' | actionId = {$actionId}");

error_log("=== generateReports.php LOADED ===" . date('Y-m-d H:i:s'));
error_log("[generateReports] reportType received = '" . $reportType . "'");

// Fallback for safety
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
    // =====================================================
    // UNIVERSAL DATA LOADING FROM tblActions (Core of Refactor)
    // =====================================================
    $pdo = getPDO(); // Ensure PDO is available (already required via dbConnect.php)

    if ($actionId > 0) {
        error_log("[PREP] Loading actionResponseData for actionId: {$actionId} (universal prep)");

        $stmt = $pdo->prepare("
            SELECT actionResponseData
            FROM tblActions
            WHERE actionId = ?
            LIMIT 1
        ");

        $stmt->execute([$actionId]);
        $action = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($action && !empty($action['actionResponseData'])) {
            $payload = json_decode($action['actionResponseData'], true);

            if (is_array($payload)) {
                $input = array_merge($input, $payload); // Deep merge if needed in future
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
    // UNIVERSAL IMAGE & ARTIFACT PREPARATION (MAPPED TO CANONICAL ARTIFACTS)
    // =====================================================
    error_log("[IMAGES] Starting universal artifact mapping for reportType: {$reportType}");
    $proposalId = $input['proposalId'] ?? $input['data']['proposalId'] ?? null;
    if ($proposalId) {
        if (!isset($input['reportArtifacts'])) {
            $input['reportArtifacts'] = [];
        }
        // Single-line comment explanation: Map existing widescreen street view record from root artifacts directory
        $streetViewPath = resolveReportArtifactPath((string)$proposalId, 'STR', 'jpg');
        if ($streetViewPath && file_exists($streetViewPath)) {
            $input['reportArtifacts']['streetview'] = $streetViewPath;
            error_log("[IMAGES] ✅ Canonical Street View mapped: " . $streetViewPath);
        }
        // Single-line comment explanation: Map existing high-resolution satellite record from root artifacts directory
        $satellitePath = resolveReportArtifactPath((string)$proposalId, 'SAT', 'png');
        if ($satellitePath && file_exists($satellitePath)) {
            $input['reportArtifacts']['satellite'] = $satellitePath;
            error_log("[IMAGES] ✅ Canonical Satellite mapped: " . $satellitePath);
        }
        // Single-line comment explanation: Map existing parcel map container asset array from root artifacts directory
        $parcelPath = resolveReportArtifactPath((string)$proposalId, 'PAR', 'png');
        if ($parcelPath && file_exists($parcelPath)) {
            $input['reportArtifacts']['parcel_maps'] = [$parcelPath];
            error_log("[IMAGES] ✅ Canonical Parcel Map mapped: " . $parcelPath);
        }
    } else {
        error_log("[IMAGES] Skipping artifact mapping - no valid proposalId provided");
    }

    // =====================================================
    // LOAD & EXECUTE SPECIFIC REPORT GENERATOR
    // =====================================================
    require_once $reportHandler['file'];

    $generator = $reportHandler['generator'] ?? null;
    if (!$generator || !function_exists($generator)) {
        throw new Exception('Generator function not found: ' . ($generator ?? 'null'));
    }

    // Generators now receive fully prepared $input (data + artifacts)
    error_log("[PREP] Calling generator: {$generator} with prepared input");
    $report = call_user_func($generator, $input);

    if (empty($report) || !is_array($report)) {
        throw new Exception('Report generator returned invalid data');
    }

    // =====================================================
    // UNIVERSAL RENDERING
    // =====================================================
    require_once __DIR__ . '/../reports/templates/baseReport.php';

    $pdfContent = renderReport($report);

    if (empty($pdfContent)) {
        throw new Exception('PDF generation returned empty content');
    }

    // === DYNAMIC FILENAME ===
    $filename = $report['reportFilename']
             ?? $report['reportTitle']
             ?? ucwords(str_replace('_', ' ', $reportType)) . ' Report';

    // Clean filename
    $filename = preg_replace('/[\\\\\/:"*?<>|]+/', '', trim($filename));
    if (empty($filename)) {
        $filename = 'Report';
    }

    // === DIRECT PDF DELIVERY ===
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

// Infer Street View Heading based on address (simple heuristic)
function inferStreetViewHeading(string $address): int
{
    preg_match(
        '/^\s*(\d+)/',
        $address,
        $matches
    );

    $streetNumber =
        isset($matches[1])
            ? (int)$matches[1]
            : 0;

    $isOdd =
        $streetNumber > 0
            ? ($streetNumber % 2 === 1)
            : true;

    $upper =
        strtoupper($address);

    // Avenue = North/South roadway
    if (
        strpos($upper, ' AVE') !== false
    ) {

        return $isOdd
            ? 90      // East
            : 270;    // West
    }

    // Street / Road = East/West roadway
    if (
        strpos($upper, ' ST') !== false ||
        strpos($upper, ' RD') !== false
    ) {

        return $isOdd
            ? 180     // South
            : 0;      // North
    }

    // Fallback
    return 90;
}

// Generate Ephemeral Street View Image
function generateStreetViewImage(
    string $lat,
    string $lng,
    string $googleKey,
    string $address = ''
): ?string
{
    if (empty($googleKey) || empty($lat) || empty($lng)) {
        error_log("[generateReports] generateStreetViewImage() - Missing lat, lng or API key");
        return null;
    }

    $heading =
        inferStreetViewHeading(
            $address
        );

    $fov = 85;

    $url =
        'https://maps.googleapis.com/maps/api/streetview?size=640x320'
        . '&location=' . $lat . ',' . $lng
        . '&heading=' . $heading
        . '&pitch=5'
        . '&fov=' . $fov
        . '&key=' . $googleKey;

    error_log("[generateReports] generateStreetViewImage() - Calling URL: " . $url);

    // Use curl instead of file_get_contents for better reliability and error reporting
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($imageData === false || $httpCode != 200) {
        error_log("[generateReports] generateStreetViewImage() - cURL failed. HTTP Code: $httpCode | cURL Error: $curlError");
        return null;
    }

    if (strlen($imageData) < 1000) {
        error_log("[generateReports] generateStreetViewImage() - Google returned invalid/small image (size: " . strlen($imageData) . " bytes)");
        return null;
    }

    // Save the image
    $ephemeralDir = __DIR__ . '/../data/runtimeEphemeral/streetview/';
    if (!is_dir($ephemeralDir)) {
        mkdir($ephemeralDir, 0755, true);
    }

    $tempPath = $ephemeralDir . 'streetview-' . uniqid() . '.jpg';

    if (file_put_contents($tempPath, $imageData) === false) {
        error_log("[generateReports] generateStreetViewImage() - Failed to write image to disk");
        return null;
    }

    return $tempPath;
}

function generateParcelMapImage(string $lat, string $lng, string $apn, string $googleKey): ?string
{
    if (empty($googleKey) || empty($lat) || empty($lng) || empty($apn)) {
        error_log("[PARCEL MAP] Missing parameters for APN: $apn");
        return null;
    }

    $safeApn = preg_replace('/[^A-Za-z0-9]/', '', $apn);
    $filename = 'parcelmap-' . $safeApn . '-' . uniqid() . '.png';

    $ephemeralDir = __DIR__ . '/../data/runtimeEphemeral/parcelMaps/';
    if (!is_dir($ephemeralDir)) {
        mkdir($ephemeralDir, 0755, true);
    }

    $outputPath = $ephemeralDir . $filename;

    $mapUrl = 'https://maps.googleapis.com/maps/api/staticmap?'
        . 'center=' . $lat . ',' . $lng
        . '&zoom=20'
        . '&size=900x550'
        . '&maptype=satellite'
        . '&markers=color:red%7Csize:mid%7Clabel:' . urlencode(substr($apn, -5)) . '%7C' . $lat . ',' . $lng
        . '&key=' . $googleKey;

    $imageData = @file_get_contents($mapUrl);

    if ($imageData === false || strlen($imageData) < 3000) {
        error_log("[PARCEL MAP] Failed to fetch image for APN: $apn");
        return null;
    }

    if (file_put_contents($outputPath, $imageData) === false) {
        error_log("[PARCEL MAP] Failed to write image: $outputPath");
        return null;
    }

    return $outputPath;
}

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