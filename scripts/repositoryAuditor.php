<?php
// ======================================================================
//  FILE: repositoryAuditor.php (PHP 5.6 Compatible)
//  PURPOSE: Skyesoft Codex v2.0 Repository Auditor
//  VERSION: v2.0.0-PHP56
//  AUTHOR: CPAP-01 (Adjusted for PHP 5.6)
// ======================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// ---------------------------------------------------------------
// STEP 1 – Root Paths
// ---------------------------------------------------------------
$root = realpath(dirname(__DIR__));
$codexPath = $root . '/codex/codex.json';

if (!file_exists($codexPath)) {
    echo json_encode(array("error" => "Codex not found at codex/codex.json"));
    exit;
}

$codex = json_decode(file_get_contents($codexPath), true);

if (!isset($codex["standards"]["items"]["repositoryStandard"])) {
    echo json_encode(array("error" => "repositoryStandard missing from Codex"));
    exit;
}

$repoStandard = $codex["standards"]["items"]["repositoryStandard"];
$schema = isset($repoStandard["filesystemSchema"]) ? $repoStandard["filesystemSchema"] : null;

if (!$schema) {
    echo json_encode(array("error" => "filesystemSchema missing from Codex"));
    exit;
}

// ---------------------------------------------------------------
// STEP 2 – Extract Codex v2.0 Rules
// ---------------------------------------------------------------
$allowedRootsRaw = $schema["root"]["allowedRoots"];
$allowedRoots = array();

foreach ($allowedRootsRaw as $r) {
    $allowedRoots[] = rtrim($r, '/');
}

// required directories actually used by Skyesoft
$requiredSubdirs = array(
    'assets/css',
    'assets/js',
    'assets/data',
    'assets/images',
    'modules/reports'
);

$allowedExtensions   = isset($schema["rules"]["allowedExtensions"]) ? $schema["rules"]["allowedExtensions"] : array();
$prohibitedExtensions = isset($schema["rules"]["prohibitedExtensions"]) ? $schema["rules"]["prohibitedExtensions"] : array();
$allowedRootFiles = isset($schema["root"]["allowedRootFiles"]) ? $schema["root"]["allowedRootFiles"] : array();

// max directory depth
$maxDepth = isset($schema["rules"]["maxDepth"]) ? $schema["rules"]["maxDepth"] : 3;

// ---------------------------------------------------------------
// STEP 3 – Prepare Scan
// ---------------------------------------------------------------
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$unexpectedRootFiles = array();
$misplacedFiles = array();
$forbiddenFiles = array();
$missingDirs = array();
$depthViolations = array();
$extensionViolations = array();

// ---------------------------------------------------------------
// STEP 4 – Required Directory Checks
// ---------------------------------------------------------------
foreach ($requiredSubdirs as $dir) {
    if (!is_dir($root . "/" . $dir)) {
        $missingDirs[] = $dir;
    }
}

// ---------------------------------------------------------------
// STEP 5 – Scan Files
// ---------------------------------------------------------------
foreach ($iterator as $file) {

    $full = $file->getPathname();
    $rel  = str_replace($root . '/', '', $full);

    // skip git internals
    if (strpos($rel, '.git/') === 0) continue;

    // skip vendor and node_modules
    if (strpos($rel, 'node_modules/') === 0) continue;
    if (strpos($rel, 'vendor/') === 0) continue;

    // ---------------------
    // Depth violation check
    // ---------------------
    if (substr_count($rel, '/') > $maxDepth) {
        $depthViolations[] = $rel;
    }

    // ---------------------
    // Unexpected files in root
    // ---------------------
    if (strpos($rel, '/') === false) {
        if (!in_array($rel, $allowedRootFiles)) {
            $unexpectedRootFiles[] = $rel;
        }
        continue;
    }

    // ---------------------
    // Misplaced root folder
    // ---------------------
    $rootFolder = explode('/', $rel);
    $rootFolder = $rootFolder[0];

    if (!in_array($rootFolder, $allowedRoots)) {
        $misplacedFiles[] = $rel;
    }

    // ---------------------
    // Forbidden extensions
    // ---------------------
    foreach ($prohibitedExtensions as $ext) {
        $len = strlen($ext);
        if (substr($rel, -$len) === $ext) {
            $forbiddenFiles[] = $rel;
        }
    }

    // ---------------------
    // Allowed extension rules
    // ---------------------
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

    if (isset($allowedExtensions[$ext])) {

        $valid = false;

        foreach ($allowedExtensions[$ext] as $dirPrefix) {
            // must be inside correct folder
            if (strpos($rel, $dirPrefix . '/') === 0) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            $extensionViolations[] = $rel;
        }
    }
}

// ---------------------------------------------------------------
// STEP 6 – Generate Audit File
// ---------------------------------------------------------------
$audit = array(
    "date" => date('c'),
    "unexpectedRootFiles" => array_values(array_unique($unexpectedRootFiles)),
    "misplacedFiles" => array_values(array_unique($misplacedFiles)),
    "forbiddenFiles" => array_values(array_unique($forbiddenFiles)),
    "missingRequiredDirs" => array_values(array_unique($missingDirs)),
    "depthViolations" => array_values(array_unique($depthViolations)),
    "extensionViolations" => array_values(array_unique($extensionViolations)),
    "summary" => array(
        "unexpectedRootCount" => count($unexpectedRootFiles),
        "misplacedCount" => count($misplacedFiles),
        "forbiddenCount" => count($forbiddenFiles),
        "missingDirCount" => count($missingDirs),
        "depthViolations" => count($depthViolations),
        "extensionViolations" => count($extensionViolations)
    )
);

// ---------------------------------------------------------------
// STEP 7 – Save JSON Audit
// ---------------------------------------------------------------
$savePath = $root . '/assets/data/repositoryAudit.json';
file_put_contents($savePath, json_encode($audit, JSON_PRETTY_PRINT));

// ---------------------------------------------------------------
// STEP 8 – Output Response
// ---------------------------------------------------------------
echo json_encode(array(
    "status" => "success",
    "message" => "Repository audit completed under Codex v2.0 (PHP 5.6).",
    "output" => "assets/data/repositoryAudit.json",
    "summary" => $audit["summary"]
));
?>
