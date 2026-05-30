<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — generateReports.php
//  Main Report Controller
//  Version: 1.2.0
//  Last Updated: 2026-05-30
// =============================================

#region SECTION 00 - Error Reporting

error_reporting(E_ALL);
ini_set('display_errors', 1);

#endregion

#region SECTION 01 - Request Intake

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$reportType = strtolower(trim($input['reportType'] ?? ''));
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
    require_once $reportHandler['file'];

    $generator = $reportHandler['generator'] ?? null;
    if (!$generator || !function_exists($generator)) {
        throw new Exception('Report generator not found: ' . $generator);
    }

    $report = call_user_func($generator, $input);

    if (!$report || !is_array($report)) {
        throw new Exception('Report generation failed - invalid report object');
    }

    require_once __DIR__ . '/../reports/templates/baseReport.php';

    $pdfContent = renderReport($report);

    // Success - Return PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' 
        . ($report['reportType'] ?? 'report') 
        . '_' . date('YmdHis') . '.pdf"');
    
    echo $pdfContent;

} catch (Throwable $e) {

    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

#endregion