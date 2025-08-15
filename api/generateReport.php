<?php
// 📄 generateReport.php

// Prevent HTML output from errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Set JSON content type
header('Content-Type: application/json');

// Ensure timezone
date_default_timezone_set('America/Phoenix');

// Configuration
$reportsDir = '/home/notyou64/public_html/skyesoft/reports/';
$dataDir = __DIR__ . '/../data/'; // keep consistent base for data
$publicUrlBase = 'https://www.skyelighting.com/skyesoft/reports/';
// Ensure reports directory exists
if (!is_dir($reportsDir)) {
    if (!mkdir($reportsDir, 0755, true)) {
        error_log("❌ Failed to create reports directory: $reportsDir");
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create reports directory.'
        ]);
        exit;
    }
}

// Check if reports directory is writable
if (!is_writable($reportsDir)) {
    error_log("❌ Reports directory not writable: $reportsDir");
    echo json_encode([
        'success' => false,
        'error' => 'Reports directory is not writable.',
        'details' => 'Check permissions for ' . $reportsDir
    ]);
    exit;
}

// Inputs
$reportType = isset($_POST['reportType']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['reportType']) : 'custom';
$reportData = isset($_POST['reportData']) && is_array($_POST['reportData']) ? $_POST['reportData'] : [];

// Load report definitions
$reportTypesFile = $dataDir . 'report_types.json';
if (!file_exists($reportTypesFile)) {
    error_log("❌ Missing report types file: $reportTypesFile");
    echo json_encode([
        'success' => false,
        'error' => 'Missing report types file.'
    ]);
    exit;
}

// Decode JSON
$reportTypes = json_decode(file_get_contents($reportTypesFile), true);
if ($reportTypes === null) {
    error_log("❌ Invalid report types file: " . json_last_error_msg());
    echo json_encode([
        'success' => false,
        'error' => 'Invalid report types file: ' . json_last_error_msg()
    ]);
    exit;
}

// Find matching report definition
$reportDef = null;
foreach ($reportTypes as $type) {
    if ($type['reportType'] === $reportType) {
        $reportDef = $type;
        break;
    }
}
if (!$reportDef) {
    error_log("❌ Unknown report type: $reportType");
    echo json_encode([
        'success' => false,
        'error' => 'Unknown report type: ' . $reportType
    ]);
    exit;
}

// Build report title
$title = $reportDef['titleTemplate'];
foreach ($reportData as $key => $value) {
    $title = str_replace('{' . $key . '}', is_array($value) ? implode(', ', $value) : $value, $title);
}

// Generate HTML content
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head><body>';
$html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
$html .= '<p><em>Generated on ' . date('F j, Y, g:i a') . '</em></p><hr>';

function formatReportValue($value) {
    if (is_array($value)) {
        $formatted = [];
        foreach ($value as $k => $v) {
            $formatted[] = htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }
        return implode(', ', $formatted);
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

foreach ($reportData as $field => $value) {
    $html .= '<p><strong>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $field)), ENT_QUOTES, 'UTF-8') . ':</strong> ' . formatReportValue($value) . '</p>';
}

$html .= '</body></html>';

// Debugging
error_log("🛠 Attempting to save report...");
error_log("📂 Reports dir: $reportsDir");
error_log("📂 Realpath: " . realpath($reportsDir));
error_log("📂 Dir exists: " . (is_dir($reportsDir) ? 'YES' : 'NO'));
error_log("✏ Writable: " . (is_writable($reportsDir) ? 'YES' : 'NO'));
error_log("📄 HTML length: " . strlen($html));
error_log("🆔 Report type: $reportType");

// Save file
$filenameSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($reportType)) . '_' . date('Ymd_His') . '.html';
$filePath = $reportsDir . $filenameSafe;

error_log("[DEBUG] Target file path: $filePath (Realpath: " . realpath(dirname($filePath)) . ")");

$result = file_put_contents($filePath, $html);

if ($result === false) {
    error_log("❌ Failed to write report to: $filePath");
    echo json_encode([
        'success' => false,
        'error' => 'Failed to save report file.',
        'details' => 'Check permissions and path for ' . $filePath
    ]);
    exit;
}

error_log("✅ Report saved successfully. Bytes written: $result");

// Return result
echo json_encode([
    'success' => true,
    'message' => 'Report created successfully.',
    'reportUrl' => $publicUrlBase . $filenameSafe,
    'filename' => $filenameSafe
]);