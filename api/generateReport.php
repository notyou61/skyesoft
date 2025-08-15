<?php
// 📄 generateReport.php

// Start session for sessionId
session_start();

// Prevent HTML output from errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log'); // Keep for redundancy

// Set JSON content type
header('Content-Type: application/json');

// Ensure timezone
date_default_timezone_set('America/Phoenix');

// Configuration
$reportsDir = '/home/notyou64/public_html/skyesoft/reports/';
$dataDir = __DIR__ . '/../data/';
$publicUrlBase = 'https://www.skyelighting.com/skyesoft/reports/';
$logFile = __DIR__ . '/create_Report.log'; // Log to create_Report.log

// Log request start
error_log("[DEBUG] Request started at " . date('Y-m-d H:i:s'), 3, $logFile);
error_log("[DEBUG] POST data: " . json_encode($_POST), 3, $logFile);

// Ensure reports directory exists
if (!is_dir($reportsDir)) {
    error_log("[DEBUG] Reports directory does not exist, attempting to create: $reportsDir", 3, $logFile);
    if (!mkdir($reportsDir, 0755, true)) {
        error_log("[ERROR] Failed to create reports directory: $reportsDir", 3, $logFile);
        echo json_encode([
            'success' => false,
            'response' => 'Failed to create reports directory.',
            'actionType' => 'Create',
            'actionName' => 'Report'
        ]);
        exit;
    }
    error_log("[DEBUG] Reports directory created: $reportsDir", 3, $logFile);
}

// Check if reports directory is writable
if (!is_writable($reportsDir)) {
    error_log("[ERROR] Reports directory not writable: $reportsDir", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Reports directory is not writable.',
        'actionType' => 'Create',
        'actionName' => 'Report',
        'details' => 'Check permissions for ' . $reportsDir
    ]);
    exit;
}
error_log("[DEBUG] Reports directory writable: $reportsDir", 3, $logFile);

// Inputs
$reportType = isset($_POST['reportType']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['reportType']) : 'custom';
$reportData = isset($_POST['reportData']) && is_array($_POST['reportData']) ? $_POST['reportData'] : [];
error_log("[DEBUG] Report type: $reportType", 3, $logFile);
error_log("[DEBUG] Report data: " . json_encode($reportData), 3, $logFile);

// Load report definitions
$reportTypesFile = '/home/notyou64/public_html/data/report_types.json';
// Check if report types file exists
if (!file_exists($reportTypesFile)) {
    error_log("[ERROR] Missing report types file: $reportTypesFile", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Missing report types file.',
        'actionType' => 'Create',
        'actionName' => 'Report'
    ]);
    exit;
}
error_log("[DEBUG] Report types file found: $reportTypesFile", 3, $logFile);

// Decode JSON
$reportTypes = json_decode(file_get_contents($reportTypesFile), true);
if ($reportTypes === null) {
    error_log("[ERROR] Invalid report types file: " . json_last_error_msg(), 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Invalid report types file: ' . json_last_error_msg(),
        'actionType' => 'Create',
        'actionName' => 'Report'
    ]);
    exit;
}
error_log("[DEBUG] Report types loaded successfully", 3, $logFile);

// Find matching report definition
$reportDef = null;
foreach ($reportTypes as $type) {
    if ($type['reportType'] === $reportType) {
        $reportDef = $type;
        break;
    }
}
if (!$reportDef) {
    error_log("[ERROR] Unknown report type: $reportType", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Unknown report type: ' . $reportType,
        'actionType' => 'Create',
        'actionName' => 'Report'
    ]);
    exit;
}
error_log("[DEBUG] Report definition found for type: $reportType", 3, $logFile);

// Build report title
$title = $reportDef['titleTemplate'];
foreach ($reportData as $key => $value) {
    $title = str_replace('{' . $key . '}', is_array($value) ? implode(', ', $value) : $value, $title);
}
error_log("[DEBUG] Report title: $title", 3, $logFile);

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
error_log("[DEBUG] HTML generated, length: " . strlen($html), 3, $logFile);

// Save file
$filenameSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($reportType)) . '_' . date('Ymd_His') . '.html';
$filePath = $reportsDir . $filenameSafe;

error_log("[DEBUG] Target file path: $filePath", 3, $logFile);
error_log("[DEBUG] Parent dir realpath: " . realpath(dirname($filePath)), 3, $logFile);
error_log("[DEBUG] Parent dir exists: " . (is_dir(dirname($filePath)) ? 'YES' : 'NO'), 3, $logFile);
error_log("[DEBUG] Parent dir writable: " . (is_writable(dirname($filePath)) ? 'YES' : 'NO'), 3, $logFile);

$result = file_put_contents($filePath, $html);

if ($result === false) {
    error_log("[ERROR] Failed to write report to: $filePath", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Failed to save report file.',
        'actionType' => 'Create',
        'actionName' => 'Report',
        'details' => 'Check permissions and path for ' . $filePath
    ]);
    exit;
}

error_log("[DEBUG] Report saved successfully. Bytes written: $result", 3, $logFile);
error_log("[DEBUG] File exists after write: " . (file_exists($filePath) ? 'YES' : 'NO'), 3, $logFile);

// Verify file accessibility
$publicUrl = $publicUrlBase . $filenameSafe;
$headers = @get_headers($publicUrl);
$httpStatus = $headers ? $headers[0] : 'Unknown';
error_log("[DEBUG] HTTP status for $publicUrl: $httpStatus", 3, $logFile);

// Return result
echo json_encode([
    'success' => true,
    'response' => 'Report created successfully.',
    'actionType' => 'Create',
    'actionName' => 'Report',
    'details' => [
        'reportType' => $reportType,
        'title' => $title,
        'data' => $reportData,
        'reportUrl' => $publicUrl,
        'filename' => $filenameSafe,
        'fileExists' => file_exists($filePath),
        'httpStatus' => $httpStatus
    ],
    'sessionId' => session_id() ?: 'unknown'
]);