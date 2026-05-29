<?php
declare(strict_types=1);
// =============================================
//  Skyesoft — generateReports.php
//  Version: 1.0.0
//  Last Updated: 2026-05-29
//  Codex Tier: 2 — Report Generation Engine
//
//  Role:
//  Centralized report controller responsible for:
//   • Report request intake
//   • Report validation
//   • Report routing
//   • Report assembly
//   • PDF generation
//   • Report delivery
//
//  Supported Report Types:
//   • Contact Proposal Reports
//   • Zoning Reports
//   • Sign Ordinance Reports
//   • Permit Status Reports
//   • Photo Survey Reports
//   • Financial Reports
//   • Custom Reports
//
//  Architecture:
//   generateReports.php
//      ↓
//   reportRegistry.php
//      ↓
//   reportDefinition
//      ↓
//   baseReportTemplate
//      ↓
//   PDF Output
//
//  Design Standards:
//   • Common header across all reports
//   • Common footer across all reports
//   • Standardized report object contract
//   • Modular report definitions
//   • Separation of controller and presentation layers
//
//  Forbidden:
//   • No direct database mutations
//   • No business rule processing
//   • No AI extraction logic
//   • No contact proposal generation
//   • No parcel lookup operations
//   • No external API orchestration
//
//  Dependencies:
//   • reportRegistry.php
//   • baseReport.php
//   • mPDF
//
//  Source of Truth:
//   • Codex Report Generation Suite
//   • Skyesoft Constitution
//
// =============================================

// =============================================
// SECTION 00 - Header
// =============================================
require_once __DIR__ . '/../config/bootstrap.php'; // or appropriate bootstrap

// =============================================
// SECTION 01 - Bootstrap + Error Config
// =============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// =============================================
// SECTION 02 - Request Intake
// =============================================
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$reportType = $input['reportType'] ?? null;
$proposalId = $input['proposalId'] ?? null;   // or other identifiers

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
// SECTION 05 - Report Loading + Generation
// =============================================
try {
    require_once $reportHandler['file'];
    
    $report = generateReport($input);  // Each report file implements this
    
    if (!$report || !is_array($report)) {
        throw new Exception('Report generation failed - invalid report object');
    }
    
    // =============================================
    // SECTION 06 - Universal Rendering
    // =============================================
    require_once __DIR__ . '/../reports/templates/baseReport.php';
    $pdfContent = renderReport($report);
    
    // =============================================
    // SECTION 07 - PDF Delivery
    // =============================================
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $report['reportType'] . '_' . date('YmdHis') . '.pdf"');
    echo $pdfContent;
    
} catch (Exception $e) {
    // =============================================
    // SECTION 08 - Error Handling
    // =============================================
    http_response_code(500);
    error_log("Report Generation Error [{$reportType}]: " . $e->getMessage());
    echo json_encode([
        'error' => 'Report generation failed',
        'message' => $e->getMessage()
    ]);
}