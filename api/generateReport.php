<?php
// api/generateReport.php
// Dynamic Report Endpoint

// Correct path from api/ folder to api/utils/
require_once __DIR__ . '/utils/reportGenerator.php';

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($input)) {
        throw new Exception("No input data received");
    }

    $generator = new ReportGenerator();
    $generator->setTemplate('contactProposal');
    $generator->setPayload($input);

    $filename = 'Proposed_Contact_Report_' . date('Y-m-d_His') . '.pdf';

    $generator->streamPdf($filename);

} catch (Exception $e) {
    http_response_code(500);
    echo "Report generation failed: " . htmlspecialchars($e->getMessage());
    error_log("[Report Error] " . $e->getMessage());
}