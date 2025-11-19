<?php
/**
 * Skyesoft Repository Inspector
 * Safe read-only viewer for directories.
 * Usage:
 *   php repoInspector.php assets
 *   php repoInspector.php assets/js
 *   php repoInspector.php modules
 */

$basePath = __DIR__ . '/..'; // root of repo

// Get argument
$relative = $argv[1] ?? null;

if (!$relative) {
    echo "Usage: php repoInspector.php <directory>\n";
    exit;
}

$target = realpath($basePath . '/' . $relative);

if (!$target || !is_dir($target)) {
    echo "❌ Directory not found: $relative\n";
    exit;
}

// Security: ensure it's inside repo
if (strpos($target, realpath($basePath)) !== 0) {
    echo "❌ Access denied. Path must remain inside repository.\n";
    exit;
}

echo "📁 Skyesoft Repository Inspector\n";
echo "Path: $relative\n";
echo "---------------------------------------------\n";

$items = scandir($target);

foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;

    $path = $target . '/' . $item;
    $type = is_dir($path) ? '[DIR]' : '[FILE]';

    $size = is_file($path) ? filesize($path) : '-';

    echo sprintf("%-6s  %-40s  %s\n",
        $type,
        $item,
        $size === '-' ? '' : $size . ' bytes'
    );
}