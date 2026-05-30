<?php
declare(strict_types=1);
// =============================================
//  Skyesoft — generateReports.php
//  Version: 1.0.2
//  Last Updated: 2026-05-29
// =============================================

#region SECTION 00 - Error Reporting Setup

error_reporting(E_ALL);
ini_set('display_errors', 1);   // Keep on for now

#endregion

#region SECTION 01 - Request Intake
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$reportType = $input['reportType'] ?? null;
$proposalId = $input['proposalId'] ?? null;

#endregion

#region SECTION 02 - Validation

if (!$reportType) {
    http_response_code(400);
    echo json_encode(['error' => 'reportType is required']);
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

// Load the report definition file identified by the registry.
// Each report type owns its own generator and business logic.
// Examples:
//   contact_proposal → contactProposalReport.php
//   zoning           → zoningReport.php
//   permit_status    → permitStatusReport.php
require_once $reportHandler['file'];

// Resolve the generator function name from the registry.
// The controller does not know which report it is generating.
// It simply asks the registry which generator should execute.
$generator =
    $reportHandler['generator']
    ?? null;

// Registry integrity check.
// Every report definition must declare a generator function.
if (!$generator) {
    throw new Exception(
        'Report generator not defined in registry.'
    );
}

// Verify the requested generator exists after loading
// the report definition file.
if (!function_exists($generator)) {
    throw new Exception(
        'Report generator not found: '
        . $generator
    );
}

// Execute the report-specific generator.
// The generator receives the request payload and returns
// a standardized report object for rendering.
//
// Expected return structure:
// [
//     'reportType'     => '',
//     'reportTitle'    => '',
//     'reportSummary'  => '',
//     'reportBodyHtml' => ''
// ]
$report =
    call_user_func(
        $generator,
        $input
    );

// Validate that the generator returned a usable
// report object before attempting PDF rendering.
if (
    !$report ||
    !is_array($report)
) {
    throw new Exception(
        'Report generation failed - invalid report object'
    );
}

// Load the universal report renderer.
// This renderer is responsible for:
//   • Header
//   • Footer
//   • Page numbering
//   • Common styling
//   • PDF creation
//
// The renderer should not contain report-specific logic.
require_once __DIR__
    . '/../reports/templates/baseReport.php';

// Convert the standardized report object into
// final PDF output using the shared report template.
$pdfContent =
    renderReport(
        $report
    );

#region SECTION 05 - PDF Delivery

// Return the generated PDF directly to the browser.
// Using "inline" allows viewing in-browser while still
// providing a meaningful generated filename.
header(
    'Content-Type: application/pdf'
);

header(
    'Content-Disposition: inline; filename="'
    . ($report['reportType'] ?? 'report')
    . '_'
    . date('YmdHis')
    . '.pdf"'
);

echo $pdfContent;

#endregion

} catch (Throwable $e) {

// Development-friendly exception output.
// During production deployment this section may be
// replaced with standardized logging and user-safe
// error messaging.
http_response_code(500);

echo "<h3>Report Generation Error</h3>";

echo "<strong>Message:</strong> "
    . htmlspecialchars($e->getMessage())
    . "<br>";

echo "<strong>File:</strong> "
    . htmlspecialchars($e->getFile())
    . "<br>";

echo "<strong>Line:</strong> "
    . $e->getLine()
    . "<br><br>";

echo "<pre>"
    . htmlspecialchars($e->getTraceAsString())
    . "</pre>";

}

#endregion