<?php
// ======================================================================
// FILE: repositoryAudit.php
// PURPOSE: Scan Skyesoft repo for obsolete or unused files
// OUTPUT: repositoryAudit.json (UTF-8, structured JSON)
// COMPATIBILITY: PHP 5.6+
// ======================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

$root = realpath(dirname(__DIR__));
if (!$root) die("Unable to resolve root\n");

$auditFile = $root . '/api/repositoryAudit.json';

$excludeDirs = array(
    '.git', 'node_modules', 'vendor', 'logs', 'documents'
);

$allFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
);

$files = array();
foreach ($allFiles as $file) {
    $path = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $skip = false;
    foreach ($excludeDirs as $ex) {
        if (strpos($path, $ex . DIRECTORY_SEPARATOR) === 0) {
            $skip = true;
            break;
        }
    }
    if (!$skip) $files[] = $path;
}

$obsoletePatterns = array(
    '/_v[0-9]+\.php$/',           // versioned legacy files
    '/old/i',                     // contains "old"
    '/test/i',                    // test scripts
    '/backup/i',                  // backups
    '/deprecated/i'               // deprecated
);

$obsolete = array();
foreach ($files as $f) {
    foreach ($obsoletePatterns as $pattern) {
        if (preg_match($pattern, $f)) {
            $obsolete[] = $f;
            break;
        }
    }
}

$results = array(
    'timestamp' => date('c'),
    'root' => $root,
    'summary' => array(
        'totalFiles' => count($files),
        'obsoleteCount' => count($obsolete)
    ),
    'obsoleteFiles' => $obsolete,
    'allFiles' => $files
);

file_put_contents($auditFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "✅ repositoryAudit.json written successfully (" . count($files) . " files scanned)\n";
exit;