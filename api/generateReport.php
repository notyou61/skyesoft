<?php
// 📄 generateReport.php

// Ensure timezone
date_default_timezone_set('America/Phoenix');

// Directory for reports
$reportsDir = __DIR__ . '/../reports/';
if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0755, true);
}

// Inputs
$reportType = $_POST['reportType'] ?? 'custom';
$reportData = $_POST['reportData'] ?? [];

// Load report definitions
$reportTypesFile = __DIR__ . '/report_types.json';
if (!file_exists($reportTypesFile)) {
    echo json_encode(["success" => false, "error" => "Missing report types file."]);
    exit;
}
$reportTypes = json_decode(file_get_contents($reportTypesFile), true);

// Find matching report definition
$reportDef = null;
foreach ($reportTypes as $type) {
    if ($type['reportType'] === $reportType) {
        $reportDef = $type;
        break;
    }
}
if (!$reportDef) {
    echo json_encode(["success" => false, "error" => "Unknown report type: $reportType"]);
    exit;
}

// Build report title
$title = $reportDef['titleTemplate'];
foreach ($reportData as $key => $value) {
    $title = str_replace('{' . $key . '}', $value, $title);
}

// Generate HTML content
$html = "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>{$title}</title></head><body>";
$html .= "<h1>{$title}</h1>";
$html .= "<p><em>Generated on " . date("F j, Y, g:i a") . "</em></p><hr>";

foreach ($reportData as $field => $value) {
    $html .= "<p><strong>" . ucfirst(str_replace('_', ' ', $field)) . ":</strong> {$value}</p>";
}

$html .= "</body></html>";

// Save file
$filenameSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($reportType)) . '_' . date('Ymd_His') . '.html';
$filePath = $reportsDir . $filenameSafe;
file_put_contents($filePath, $html);

// Public URL
$publicUrl = "https://www.skyelighting.com/skyesoft/reports/" . $filenameSafe;

// Return result
echo json_encode([
    "success" => true,
    "message" => "Report created successfully.",
    "reportUrl" => $publicUrl,
    "filename" => $filenameSafe
]);
