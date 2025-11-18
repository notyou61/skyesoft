<?php
/**
 * Skyesoft Pure File Walker
 * ---------------------------------------------------------
 * Phase B1 of the File Census System
 * - No hardcoding
 * - No classification
 * - No filtering beyond system noise patterns
 * - Builds raw file index for Codex integration
 * - Saves to /assets/data/rawFileIndex.json
 */

$root = realpath(__DIR__ . "/..");
$outputFile = $root . "/assets/data/rawFileIndex.json";

// System noise patterns to exclude
$excludePatterns = [
    '/\.git/', '/node_modules/', '/vendor/', '/cache/', '/dist/', '/build/',
    '/\.idea/', '/\.vscode/', '/\.DS_Store/', '/Thumbs\.db/',
    '/\.netlify/', '/logs/', '/temp/', '/tmp/'
];

// Valid file extensions (everything else ignored)
$validExts = [
    "php","html","htm","js","css","json",
    "png","jpg","jpeg","gif","svg","ico",
    "txt","md"
];

// ----------------------------------------------------------------
// WALK THE DIRECTORY
// ----------------------------------------------------------------
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$files = [];

foreach ($rii as $file) {

    $full = $file->getPathname();
    $rel  = str_replace($root . DIRECTORY_SEPARATOR, "", $full);

    // Skip system noise via regex patterns
    $skip = false;
    foreach ($excludePatterns as $pattern) {
        if (preg_match($pattern, $rel)) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    // Only allow valid file types
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, $validExts)) continue;

    // Build raw record
    $files[] = [
        "path"     => $rel,
        "filename" => basename($rel),
        "folder"   => dirname($rel),
        "ext"      => $ext
    ];
}

// ----------------------------------------------------------------
// DEDUPE
// ----------------------------------------------------------------
$unique = [];
foreach ($files as $f) {
    $unique[$f["path"]] = $f;
}
$files = array_values($unique);

// ----------------------------------------------------------------
// WRITE OUTPUT
// ----------------------------------------------------------------
file_put_contents($outputFile, json_encode($files, JSON_PRETTY_PRINT));

echo "✔ Raw file index saved to assets/data/rawFileIndex.json\n";