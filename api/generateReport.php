<?php
// api/generateReport.php
// Dynamic Report Endpoint - Handles all report types

require_once __DIR__ . '/../utils/reportGenerator.php';

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $reportType = $input['reportType'] ?? 'contactProposal';

    if (empty($reportType)) {
        throw new Exception("reportType is required");
    }

    $generator = new ReportGenerator();
    $generator->setTemplate($reportType);   // e.g. contactProposal, jobProposal, etc.
    $generator->setPayload($input);

    // Dynamic filename
    $filename = ($input['filename'] ?? 'Skyesoft_Report') . '_' . date('Y-m-d_His') . '.pdf';

    $generator->streamPdf($filename);

} catch (Exception $e) {
    http_response_code(500);
    echo "Report generation failed: " . htmlspecialchars($e->getMessage());
    error_log("[Report Error] " . $e->getMessage());
}