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
$logFile = __DIR__ . '/create_Report.log';

// Heartbeat 1: Log request start
error_log("[DEBUG] Heartbeat 1: Request started at " . date('Y-m-d H:i:s'), 3, $logFile);
error_log("[DEBUG] Heartbeat 2: POST data: " . json_encode($_POST), 3, $logFile);

// Heartbeat 3: Check reports directory
error_log("[DEBUG] Heartbeat 3: Checking reports directory: $reportsDir", 3, $logFile);
if (!is_dir($reportsDir)) {
    error_log("[DEBUG] Heartbeat 3: Reports directory does not exist, attempting to create", 3, $logFile);
    if (!mkdir($reportsDir, 0755, true)) {
        error_log("[ERROR] Heartbeat 3: Failed to create reports directory: $reportsDir", 3, $logFile);
        echo json_encode([
            'success' => false,
            'response' => 'Failed to create reports directory.',
            'actionType' => 'Create',
            'actionName' => 'Report'
        ]);
        exit;
    }
    error_log("[DEBUG] Heartbeat 3: Reports directory created: $reportsDir", 3, $logFile);
}

// Heartbeat 4: Check if reports directory is writable
if (!is_writable($reportsDir)) {
    error_log("[ERROR] Heartbeat 4: Reports directory not writable: $reportsDir", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Reports directory is not writable.',
        'actionType' => 'Create',
        'actionName' => 'Report',
        'details' => 'Check permissions for ' . $reportsDir
    ]);
    exit;
}
error_log("[DEBUG] Heartbeat 4: Reports directory writable: $reportsDir", 3, $logFile);

// Heartbeat 5: Normalize inputs
$reportType = isset($_POST['reportType']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['reportType']) : 'custom';
$reportData = isset($_POST['reportData']) && is_array($_POST['reportData']) ? $_POST['reportData'] : [];
error_log("[DEBUG] Heartbeat 5: Report type: $reportType", 3, $logFile);
error_log("[DEBUG] Heartbeat 5: Report data: " . json_encode($reportData), 3, $logFile);

// Heartbeat 6: Load report definitions
$reportTypesFile = $dataDir . 'report_types.json';
$alternativePaths = [
    __DIR__ . '/report_types.json',
    __DIR__ . '/../report_types.json'
];
$reportTypes = null;

error_log("[DEBUG] Heartbeat 6: Checking report types file: $reportTypesFile", 3, $logFile);
if (file_exists($reportTypesFile)) {
    error_log("[DEBUG] Heartbeat 6: Report types file found: $reportTypesFile", 3, $logFile);
    $reportTypes = json_decode(file_get_contents($reportTypesFile), true);
} else {
    error_log("[DEBUG] Heartbeat 6: Primary report types file not found, checking alternatives", 3, $logFile);
    foreach ($alternativePaths as $path) {
        if (file_exists($path)) {
            error_log("[DEBUG] Heartbeat 6: Report types file found at alternative path: $path", 3, $logFile);
            $reportTypes = json_decode(file_get_contents($path), true);
            $reportTypesFile = $path;
            break;
        }
    }
    if ($reportTypes === null) {
        error_log("[DEBUG] Heartbeat 6: No report types file found, using fallback definition", 3, $logFile);
        $reportTypes = [
            [
                'reportType' => 'zoning',
                'titleTemplate' => 'Zoning Report – {projectName}'
            ],
            [
                'reportType' => 'custom',
                'titleTemplate' => 'Custom Report – {projectName}'
            ]
        ];
    }
}

if ($reportTypes === null) {
    error_log("[ERROR] Heartbeat 6: Invalid report types file: " . json_last_error_msg(), 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Invalid report types file: ' . json_last_error_msg(),
        'actionType' => 'Create',
        'actionName' => 'Report'
    ]);
    exit;
}
error_log("[DEBUG] Heartbeat 6: Report types loaded successfully from: $reportTypesFile", 3, $logFile);

// Heartbeat 7: Find matching report definition
$reportDef = null;
foreach ($reportTypes as $type) {
    if ($type['reportType'] === $reportType) {
        $reportDef = $type;
        break;
    }
}
if (!$reportDef) {
    error_log("[ERROR] Heartbeat 7: Unknown report type: $reportType", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Unknown report type: ' . $reportType,
        'actionType' => 'Create',
        'actionName' => 'Report'
    ]);
    exit;
}
error_log("[DEBUG] Heartbeat 7: Report definition found for type: $reportType", 3, $logFile);

// Build report title
$title = $reportDef['titleTemplate'];
foreach ($reportData as $key => $value) {
    $title = str_replace('{' . $key . '}', is_array($value) ? implode(', ', $value) : $value, $title);
}
error_log("[DEBUG] Heartbeat 7: Report title: $title", 3, $logFile);

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
error_log("[DEBUG] Heartbeat 7: HTML generated, length: " . strlen($html), 3, $logFile);

// Save file
$filenameSafe = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($reportType)) . '_' . date('Ymd_His') . '.html';
$filePath = $reportsDir . $filenameSafe;

error_log("[DEBUG] Heartbeat 7: Target file path: $filePath", 3, $logFile);
error_log("[DEBUG] Heartbeat 7: Parent dir realpath: " . realpath(dirname($filePath)), 3, $logFile);
error_log("[DEBUG] Heartbeat 7: Parent dir exists: " . (is_dir(dirname($filePath)) ? 'YES' : 'NO'), 3, $logFile);
error_log("[DEBUG] Heartbeat 7: Parent dir writable: " . (is_writable(dirname($filePath)) ? 'YES' : 'NO'), 3, $logFile);

$result = file_put_contents($filePath, $html);

if ($result === false) {
    error_log("[ERROR] Heartbeat 7: Failed to write report to: $filePath", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Failed to save report file.',
        'actionType' => 'Create',
        'actionName' => 'Report',
        'details' => 'Check permissions and path for ' . $filePath
    ]);
    exit;
}

error_log("[DEBUG] Heartbeat 7: Report saved successfully. Bytes written: $result", 3, $logFile);
error_log("[DEBUG] Heartbeat 7: File exists after write: " . (file_exists($filePath) ? 'YES' : 'NO'), 3, $logFile);

// Verify file accessibility
$publicUrl = $publicUrlBase . $filenameSafe;
$headers = @get_headers($publicUrl);
$httpStatus = $headers ? $headers[0] : 'Unknown';
error_log("[DEBUG] Heartbeat 7: HTTP status for $publicUrl: $httpStatus", 3, $logFile);

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
?>