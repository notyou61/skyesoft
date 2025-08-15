<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

$testFile = '/home/notyou64/public_html/reports/test_20250815.txt';
$result = file_put_contents($testFile, 'Test content ' . date('Y-m-d H:i:s'));

if ($result !== false) {
    error_log("✅ Test write result: Success, wrote $result bytes to $testFile");
    echo "Success — wrote $result bytes to $testFile";
} else {
    error_log("❌ Test write result: Failed for $testFile");
    echo "Failed — could not write to $testFile";
}
