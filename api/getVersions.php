<?php
header('Content-Type: application/json');

// Path to the Version Parliament registry
$path = __DIR__ . '/../assets/data/versions.json';

// Verify file exists
if (!file_exists($path)) {
    echo json_encode([
        "success" => false,
        "error" => "versions.json not found",
        "data" => null
    ]);
    exit;
}

// Read file
$json = file_get_contents($path);
if ($json === false) {
    echo json_encode([
        "success" => false,
        "error" => "Unable to read versions.json",
        "data" => null
    ]);
    exit;
}

// Decode JSON
$data = json_decode($json, true);
if ($data === null) {
    echo json_encode([
        "success" => false,
        "error" => "versions.json contains invalid JSON",
        "data" => null
    ]);
    exit;
}

// Respond with version metadata
echo json_encode([
    "success" => true,
    "data" => $data
]);