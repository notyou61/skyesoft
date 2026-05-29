<?php
declare(strict_types=1);
// =============================================
//  Skyesoft — generateReports.php
//  Version: 1.0.2
//  Last Updated: 2026-05-29
// =============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);   // Keep on for now

// =============================================
// SECTION 02 - Request Intake
// =============================================
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$reportType = $input['reportType'] ?? null;
$proposalId = $input['proposalId'] ?? null;

// =============================================
// SECTION 03 - Validation
// =============================================
if (!$reportType) {
    http_response_code(400);
    echo json_encode(['error' => 'reportType is required']);
    exit;
}

// =============================================
// SECTION 04 - Report Registry
// =============================================
require_once __DIR__ . '/../reports/reportRegistry.php';

$reportHandler = getReportHandler($reportType);
if (!$reportHandler) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown report type: ' . $reportType]);
    exit;
}

// =============================================
// SECTION 05+ - Report Generation
// =============================================
try {
    require_once $reportHandler['file'];
    
    // Use the specific generator function (no more generic generateReport)
    $report = generateContactProposalReport($input);
    
    if (!$report || !is_array($report)) {
        throw new Exception('Report generation failed - invalid report object');
    }
    
    // Universal rendering
    require_once __DIR__ . '/../reports/templates/baseReport.php';
    $pdfContent = renderReport($report);
    
    // =============================================
    // SECTION 07 - PDF Delivery
    // =============================================
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $report['reportType'] . '_' . date('YmdHis') . '.pdf"');
    echo $pdfContent;
    
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h3>Report Generation Error</h3>";
    echo "<strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<strong>File:</strong> " . htmlspecialchars($e->getFile()) . "<br>";
    echo "<strong>Line:</strong> " . $e->getLine() . "<br><br>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}