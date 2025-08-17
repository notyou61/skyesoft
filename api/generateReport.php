<?php
// 📄 File: generateReport.php (Merged Original + Patch)

// Start session for sessionId
session_start();

#region ⚙️ Environment & Setup

// Error handling
ini_set('display_errors', 0);              // Prevent HTML errors leaking to client
ini_set('log_errors', 1);                  // Log errors internally
ini_set('error_log', __DIR__ . '/error.log');

// Response type
header('Content-Type: application/json');

// Timezone
date_default_timezone_set('America/Phoenix');

// Directories & URLs
$reportsDir     = '/home/notyou64/public_html/skyesoft/reports/';
$dataDir        = __DIR__ . '/../data/';
$publicUrlBase  = 'https://www.skyelighting.com/skyesoft/reports/';
$logFile        = __DIR__ . '/create_Report.log';

// Logging: request start
error_log("[DEBUG] 🟢 Request started at " . date('Y-m-d H:i:s') . PHP_EOL, 3, $logFile);
error_log("[DEBUG] Incoming POST: " . json_encode($_POST) . PHP_EOL, 3, $logFile);

// Ensure reports directory exists
if (!is_dir($reportsDir)) {
    error_log("[DEBUG] Reports dir missing, creating: $reportsDir" . PHP_EOL, 3, $logFile);
    if (!mkdir($reportsDir, 0755, true)) {
        error_log("[ERROR] ❌ Failed to create reports dir: $reportsDir" . PHP_EOL, 3, $logFile);
        echo json_encode([
            'success'     => false,
            'response'    => 'Failed to create reports directory.',
            'actionType'  => 'Create',
            'actionName'  => 'Report'
        ]);
        exit;
    }
    error_log("[DEBUG] ✅ Reports directory created successfully." . PHP_EOL, 3, $logFile);
}

// Check directory permissions
if (!is_writable($reportsDir)) {
    error_log("[ERROR] ❌ Reports directory not writable: $reportsDir" . PHP_EOL, 3, $logFile);
    echo json_encode([
        'success'     => false,
        'response'    => 'Reports directory is not writable.',
        'actionType'  => 'Create',
        'actionName'  => 'Report',
        'details'     => 'Check permissions for ' . $reportsDir
    ]);
    exit;
}
error_log("[DEBUG] ✅ Reports directory is writable." . PHP_EOL, 3, $logFile);

#endregion

#region 📨 Parse Input
$reportType = isset($_POST['reportType']) ? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['reportType'])) : 'custom';
$reportData = isset($_POST['reportData']) && is_array($_POST['reportData']) ? $_POST['reportData'] : [];
error_log("[DEBUG] Heartbeat 5: Report type: $reportType", 3, $logFile);
error_log("[DEBUG] Heartbeat 5: Report data: " . json_encode($reportData), 3, $logFile);

if (empty($reportType) || empty($reportData)) {
    error_log("[ERROR] Heartbeat 5: Missing reportType or reportData", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Missing reportType or reportData',
        'actionType' => 'Create',
        'actionName' => 'Report'
    ]);
    exit;
}
#endregion

#region 📋 Validation Rules
$requiredFields = [];
if ($reportType === 'zoning') {
    $requiredFields = ['projectName', 'address', 'parcel', 'jurisdiction'];
}

$missing = [];
foreach ($requiredFields as $field) {
    if (empty($reportData[$field])) {
        $missing[] = $field;
    }
}

if (!empty($missing)) {
    error_log("[ERROR] Heartbeat 5: Missing required fields: " . implode(', ', $missing), 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Missing required field(s): ' . implode(', ', $missing),
        'actionType' => 'Create',
        'actionName' => 'Report',
        'reportType' => $reportType
    ]);
    exit;
}
error_log("[DEBUG] Heartbeat 5: Input validation passed", 3, $logFile);
#endregion

#region 📚 Load Report Definitions
$reportTypesFile = $dataDir . 'report_types.json';
$alternativePaths = [
    __DIR__ . '/report_types.json',
    __DIR__ . '/../report_types.json'
];
$reportTypes = null;

error_log("[DEBUG] Heartbeat 6: Checking report types file: $reportTypesFile", 3, $logFile);
if (file_exists($reportTypesFile)) {
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

// Find matching report definition
$reportDef = null;
foreach ($reportTypes as $type) {
    if ($type['reportType'] === $reportType) {
        $reportDef = $type;
        break;
    }
}
if (!$reportDef) {
    error_log("[ERROR] Heartbeat 6: Unknown report type: $reportType", 3, $logFile);
    echo json_encode([
        'success' => false,
        'response' => 'Unknown report type: ' . $reportType,
        'actionType' => 'Create',
        'actionName' => 'Report'
    ]);
    exit;
}
error_log("[DEBUG] Heartbeat 6: Report definition found for type: $reportType", 3, $logFile);
#endregion

#region 🏷️ Known Labels
$labels = [
    'projectName' => 'Project Name',
    'address' => 'Address',
    'parcel' => 'Parcel',
    'jurisdiction' => 'Jurisdiction',
    'jobNumber' => 'Job Number',
    'projectManager' => 'Project Manager',
    'contactPerson' => 'Contact Person',
    'contactPhone' => 'Contact Phone'
];
#endregion

#region 📝 Build Report HTML
// Build report title
$title = $reportDef['titleTemplate'];
foreach ($reportData as $key => $value) {
    $title = str_replace('{' . $key . '}', is_array($value) ? implode(', ', $value) : $value, $title);
}
$timestamp = date('F j, Y, g:i a');

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
$html .= '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
$html .= '<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h1 { border-bottom: 2px solid #444; padding-bottom: 4px; }
    h2 { margin-top: 20px; }
    p, li { margin: 4px 0; }
</style></head><body>';

$html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
$html .= '<p><em>Generated on ' . $timestamp . '</em></p>';

// Render known fields first
$remainingData = $reportData;
foreach ($labels as $key => $label) {
    if (!empty($reportData[$key])) {
        $value = is_array($reportData[$key]) ? implode(', ', $reportData[$key]) : $reportData[$key];
        $html .= '<p><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</p>';
        unset($remainingData[$key]); // Remove handled fields
    }
}

// Jazz section: any extra data
if (!empty($remainingData)) {
    $html .= '<h2>Additional Information</h2><ul>';
    foreach ($remainingData as $key => $value) {
        $formattedValue = is_array($value) ? implode(', ', $value) : $value;
        $html .= '<li><strong>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $key)), ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($formattedValue, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul>';
}

$html .= '</body></html>';
error_log("[DEBUG] Heartbeat 7: HTML generated, length: " . strlen($html), 3, $logFile);
#endregion

#region 💾 Save File
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
#endregion

#region 📤 Return JSON
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
#endregion