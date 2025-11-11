<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$reportsDir = '/home/notyou64/public_html/skyesoft/reports/';
$testFile = $reportsDir . 'test_basic_' . date('Ymd_His') . '.txt';

// Ensure directory exists
if (!is_dir($reportsDir)) {
    echo "❌ Directory does not exist: $reportsDir";
    exit;
}

// Ensure directory writable
if (!is_writable($reportsDir)) {
    echo "❌ Directory is not writable: $reportsDir";
    exit;
}

// Write the file
$content = "Basic write test\nCreated at: " . date('Y-m-d H:i:s') . "\n";
$result = file_put_contents($testFile, $content);

if ($result === false) {
    echo "❌ Failed to write test file at: $testFile";
} else {
    echo "✅ Success — wrote $result bytes to $testFile";
}
