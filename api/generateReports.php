<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — generateReports.php
//  Main Report Controller
//  Version: 1.3.0
//  Last Updated: 2026-05-30
// =============================================

#region SECTION 00 - Error Reporting & Headers

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

// Debug received data
error_log("Report Request Received Keys: " . json_encode(array_keys($input)));

$reportType = strtolower(trim($input['reportType'] ?? ''));

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

#region SECTION 04 - Report Generation

try {
    // Load the specific report generator
    require_once $reportHandler['file'];

    $generator = $reportHandler['generator'] ?? null;
    if (!$generator || !function_exists($generator)) {
        throw new Exception('Generator function not found: ' . $generator);
    }

    // =====================================================
    // EPHEMERAL STREET VIEW IMAGE (for contact_proposal only)
    // =====================================================
    if ($reportType === 'contact_proposal') {

        // Extract lat/lng from multiple possible payload structures
        $lat = $input['latitude']
            ?? $input['data']['location']['latitude']
            ?? $input['proposal']['data']['location']['latitude']
            ?? null;

        $lng = $input['longitude']
            ?? $input['data']['location']['longitude']
            ?? $input['proposal']['data']['location']['longitude']
            ?? null;

        $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY')
            ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
            ?: '';

        if ($lat && $lng && $googleKey) {

            // Load the helper function if not already available
            if (!function_exists('generateStreetViewImage')) {
                require_once __DIR__ . '/../reports/contactProposalReport.php';
            }

            $streetViewPath = generateStreetViewImage(
                (string)$lat,
                (string)$lng,
                $googleKey
            );

            if ($streetViewPath) {
                if (!isset($input['reportArtifacts'])) {
                    $input['reportArtifacts'] = [];
                }

                $input['reportArtifacts']['streetview'] = $streetViewPath;

                error_log("[generateReports] ✅ Street View image generated: " . $streetViewPath);
            } else {
                error_log("[generateReports] ❌ Street View generation returned null");
            }
        } else {
            error_log("[generateReports] Skipping Street View - missing lat/lng or API key");
        }
    }

    // Generate the report data
    $report = call_user_func($generator, $input);

    if (empty($report) || !is_array($report)) {
        throw new Exception('Report generator returned invalid data');
    }

    // Load universal renderer
    require_once __DIR__ . '/../reports/templates/baseReport.php';

    $pdfContent = renderReport($report);

    if (empty($pdfContent)) {
        throw new Exception('PDF generation returned empty content');
    }

    // === DYNAMIC FILENAME ===
    $filename = $report['reportFilename']
             ?? $report['reportTitle']
             ?? 'Proposed Contact Report';

    // Clean filename
    $filename = preg_replace('/[\\\\\/:"*?<>|]+/', '', trim($filename));
    if (empty($filename)) {
        $filename = 'Proposed_Contact_Report';
    }

    // === DIRECT PDF DELIVERY (Stable & Reliable) ===
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo $pdfContent;
    exit;

} catch (Throwable $e) {
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

// Generate Ephemeral Street View Image
function generateStreetViewImage(string $lat, string $lng, string $googleKey): ?string
{
    if (empty($googleKey) || empty($lat) || empty($lng)) {
        error_log("[generateReports] generateStreetViewImage() - Missing lat, lng, or API key");
        return null;
    }

    $url = 'https://maps.googleapis.com/maps/api/streetview?size=640x320'
        . '&location=' . $lat . ',' . $lng
        . '&heading=200&pitch=5&fov=80&key=' . $googleKey;

    $imageData = @file_get_contents($url);

    if ($imageData === false || strlen($imageData) < 1000) {
        error_log("[generateReports] generateStreetViewImage() - Google API call failed or returned invalid image");
        return null;
    }

    // Save to controlled ephemeral directory
    $ephemeralDir = __DIR__ . '/../data/runtimeEphemeral/streetview/';

    if (!is_dir($ephemeralDir)) {
        mkdir($ephemeralDir, 0755, true);
    }

    $tempPath = $ephemeralDir . 'streetview-' . uniqid() . '.jpg';

    if (file_put_contents($tempPath, $imageData) === false) {
        error_log("[generateReports] generateStreetViewImage() - Failed to write image to disk: " . $tempPath);
        return null;
    }

    return $tempPath;
}

#endregion