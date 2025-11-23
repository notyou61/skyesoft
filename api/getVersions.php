<?php
// ======================================================================
// Skyesoft API Endpoint: getVersions.php
// Provides Codex-governed version metadata to UI, SSE, and modules.
// PHP 5.6 compatible — no cURL, no modern features.
// ======================================================================

// 1. Define the path to versions.json (Repository Standard B-9)
$versionsFile = __DIR__ . '/../assets/data/versions.json';

// 2. Fail safely if file not found
if (!file_exists($versionsFile)) {
    header('Content-Type: application/json');
    echo json_encode(array(
        "success" => false,
        "error" => "versions.json not found",
        "file" => $versionsFile
    ));
    exit;
}

// 3. Attempt to read the file
$json = @file_get_contents($versionsFile);

// If reading failed, emit error
if ($json === false) {
    header('Content-Type: application/json');
    echo json_encode(array(
        "success" => false,
        "error" => "Unable to read versions.json"
    ));
    exit;
}

// 4. Attempt to decode JSON
$data = json_decode($json, true);

if (!is_array($data)) {
    header('Content-Type: application/json');
    echo json_encode(array(
        "success" => false,
        "error" => "Invalid JSON in versions.json"
    ));
    exit;
}

// 5. Success — emit Codex-governed version metadata
header('Content-Type: application/json');
echo json_encode(array(
    "success" => true,
    "data" => $data
));
exit;