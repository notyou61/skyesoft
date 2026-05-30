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

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

// Debug: Log what we received
error_log("Report Request Received: " . json_encode(array_keys($input)));

$reportType = strtolower(trim($input['reportType'] ?? ''));

// Fallback: Try to find reportType deeper in the payload
if (empty($reportType) && !empty($input['data'])) {
    $reportType = 'contact_proposal'; // Default for now
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

    // Generate the report data
    $report = call_user_func($generator, $input);

    if (empty($report) || !is_array($report)) {
        throw new Exception('Report generator returned invalid data');
    }

    // Load universal renderer
    require_once __DIR__ . '/../reports/templates/baseReport.php';

    // Generate PDF
    $pdfContent = renderReport($report);

    if (empty($pdfContent)) {
        throw new Exception('PDF generation returned empty content');
    }

    // === SUCCESS: Deliver PDF for viewing in new tab ===
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Proposed_Contact_Report_' 
           . date('Ymd_His') . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    echo $pdfContent;
    exit;

} catch (Throwable $e) {
    // === ERROR: Return clear JSON for frontend ===
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