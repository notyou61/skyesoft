<?php
// 📄 Deploy Test Script
// Purpose: Confirm Git → GoDaddy deployment pipeline

header('Content-Type: text/plain');

// Show server path
echo "DEPLOY TEST FILE\n";
echo "----------------\n";
echo "File path: " . __FILE__ . "\n";

// Show current timestamp
echo "Timestamp: " . date('Y-m-d H:i:s') . " (server time)\n";

// Show Git info if available
$gitHeadFile = __DIR__ . "/.git/HEAD";
if (file_exists($gitHeadFile)) {
    $head = trim(file_get_contents($gitHeadFile));
    echo "Git HEAD: " . $head . "\n";
} else {
    echo "Git HEAD not found in this directory.\n";
}

// Done
echo "✅ If you can read this in your browser, deployment is working.\n";
?>
