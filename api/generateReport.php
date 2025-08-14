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

// Directory for reports
$reportsDir = __DIR__ . '/../reports/';
if (!is_dir($reportsDir)) {
    if (!@mkdir($reportsDir, 0755, true)) {
        echo json_encode(array('success' => false, 'error' => 'Failed to create reports directory.'));
        exit;
    }
}

// Inputs
$reportType = isset($_POST['reportType']) ? $_POST['reportType'] : 'custom';
$reportData = isset($_POST['reportData']) ? $_POST['reportData'] : array();

// Load report definitions
// Prefer production absolute path, fallback to local relative for dev
$reportTypesFile = '/home/notyou64/public_html/data/report_types.json';
if (!file_exists($reportTypesFile)) {
    $reportTypesFile = __DIR__ . '/../data/report_types.json';
}
// Check if file exists
if (!file_exists($reportTypesFile)) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing report types file: ' . $reportTypesFile
    ]);
    exit;
}
// Decode JSON
$reportTypes = json_decode(file_get_contents($reportTypesFile), true);
if ($reportTypes === null) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid report types file: ' . json_last_error()
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
    echo json_encode(array('success' => false, 'error' => 'Unknown report type: ' . $reportType));
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
        $formatted = array();
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

// Absolute path to match public reports URL
$reportsDir = '/home/notyou64/public_html/skyesoft/reports/';
if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0777, true);
}

// Save file
$filenameSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($reportType)) . '_' . date('Ymd_His') . '.html';
$filePath = $reportsDir . $filenameSafe;

if (!@file_put_contents($filePath, $html)) {
    echo json_encode(array('success' => false, 'error' => 'Failed to save report file.'));
    exit;
}

// Public URL
$publicUrl = 'https://www.skyelighting.com/skyesoft/reports/' . $filenameSafe;

// Return result
echo json_encode(array(
    'success' => true,
    'message' => 'Report created successfully.',
    'reportUrl' => $publicUrl,
    'filename' => $filenameSafe
));