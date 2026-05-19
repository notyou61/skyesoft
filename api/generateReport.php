<?php
// ======================================================================
//  🧠 Skyesoft — generateReport.php
//  📄 Dynamic Operational Report Endpoint
//  🧩 Universal PDF Report Dispatcher
// ======================================================================

// =====================================================
// 📦 LOAD REPORT ENGINE
// =====================================================

require_once __DIR__ . '/utils/reportGeneratorMPDF.php';

// =====================================================
// 🚀 REPORT EXECUTION
// =====================================================

try {

    // -------------------------------------------------
    // Read JSON payload
    // -------------------------------------------------

    $input = json_decode(
        file_get_contents('php://input'),
        true
    ) ?? [];

    // -------------------------------------------------
    // Validate input
    // -------------------------------------------------

    if (empty($input)) {
        throw new Exception(
            'No input data received'
        );
    }

    // -------------------------------------------------
    // Create report engine
    // -------------------------------------------------

    $generator = new ReportGeneratorMPDF();

    // -------------------------------------------------
    // Set template
    // -------------------------------------------------

    $generator->setTemplate(
        'contactProposal'
    );

    // -------------------------------------------------
    // Set payload
    // -------------------------------------------------

    $generator->setPayload(
        $input
    );

    // -------------------------------------------------
    // Filename
    // -------------------------------------------------

    $filename =
        'Proposed_Contact_Report_'
        . date('Y-m-d_His')
        . '.pdf';

    // -------------------------------------------------
    // Stream PDF
    // -------------------------------------------------

    $generator->streamPdf(
        $filename
    );

} catch (Exception $e) {

    http_response_code(500);

    echo
        'Report generation failed: '
        . htmlspecialchars(
            $e->getMessage()
        );

    error_log(
        '[Report Error] '
        . $e->getMessage()
    );
}