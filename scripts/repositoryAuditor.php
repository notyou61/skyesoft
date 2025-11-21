<?php
// ======================================================================
//  FILE: repositoryAuditor.php
//  PURPOSE: Full Codex Repository Auditor (Phase 1 Governance)
//  VERSION: v1.2.0
//  AUTHOR: Parliamentarian CPAP-01
// ======================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// ---------------------------------------------------------------
//  STEP 1 – Resolve Root Paths
// ---------------------------------------------------------------
$root = realpath(dirname(__DIR__));
$codexJson = $root . '/assets/data/codex.json';

if (!file_exists($codexJson)) {
    echo json_encode(["error" => "Codex not found. Expected: /assets/data/codex.json"]);
    exit;
}

$codex = json_decode(file_get_contents($codexJson), true);

// ---------------------------------------------------------------
//  STEP 2 – Define Repository Standards
// ---------------------------------------------------------------

// Canonical Codex root directories
$allowedRoots = [
    'api',
    'assets',
    'bulletinBoards',
    'legacy',
    'libs',
    'logs',
    'modules',
    'reports',
    'scripts',
    'secure',
    'codex'
];

// Roots that should be ignored completely (dependency / build)
$ignoreRoots = [
    'node_modules',
    'vendor'
];

// Allowed single files in repository root
$allowedRootFiles = [
    '.gitignore',
    'README.md',
    'composer.lock',
    'config.php',
    'index.html',
    'package.json',
    'package-lock.json'
];

// Codex-required subdirectories
$requiredSubdirs = [
    'assets/css',
    'assets/js',
    'assets/data',
    'assets/images',
    'documents/templates',
    'modules/tis',
    'modules/skyebot',
    'modules/reports',
    'modules/automation'
];

// Forbidden extensions
$forbiddenExtensions = ['.bak', '.tmp', '.old', '.orig'];

// Legacy / obsolete name patterns
$obsoletePatterns = [
    'codexTemporalStress',
    'validateTemporal',
    'codexFullTest',
    'generateVersion',
    'generate-changelog',
    'post-commit',
    'test_',
    'experimental'
];

// ---------------------------------------------------------------
//  STEP 3 – Scan Repository
// ---------------------------------------------------------------
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$obsolete      = [];
$misplaced     = [];
$forbidden     = [];
$missingDirs   = [];
$unexpectedRootFiles = [];

// ---------------------------------------------------------------
//  STEP 4 – Check Required Directories
// ---------------------------------------------------------------
foreach ($requiredSubdirs as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        $missingDirs[] = $dir;
    }
}

// ---------------------------------------------------------------
//  STEP 5 – Analyze Every File
// ---------------------------------------------------------------
foreach ($iterator as $file) {

    $full = $file->getPathname();
    $rel  = str_replace($root . '/', '', $full);

    // Skip .git internals immediately
    if (strpos($rel, '.git') === 0) continue;

    $parts = explode('/', $rel);
    $top = $parts[0];

    // Skip ignored roots entirely
    if (in_array($top, $ignoreRoots)) {
        continue;
    }

    // -----------------------
    // Root-level file check
    // -----------------------
    if (count($parts) == 1) {
        if (!in_array($rel, $allowedRootFiles)) {
            $unexpectedRootFiles[] = $rel;
        }
        continue;
    }

    // -----------------------
    // Check allowed root folders
    // -----------------------
    if (!in_array($top, $allowedRoots)) {
        $misplaced[] = $rel;
        continue;
    }

    // -----------------------
    // Forbidden extensions
    // -----------------------
    foreach ($forbiddenExtensions as $ext) {
        if (str_ends_with($rel, $ext)) {
            $forbidden[] = $rel;
        }
    }

    // -----------------------
    // Obsolete pattern detection
    // -----------------------
    foreach ($obsoletePatterns as $pattern) {
        if (stripos($rel, $pattern) !== false) {
            $obsolete[] = $rel;
        }
    }
}

// ---------------------------------------------------------------
//  STEP 6 – Build Audit JSON
// ---------------------------------------------------------------
$audit = [
    "date" => date('c'),
    "obsoleteFiles"  => array_values(array_unique($obsolete)),
    "misplacedFiles" => array_values(array_unique($misplaced)),
    "forbiddenFiles" => array_values(array_unique($forbidden)),
    "unexpectedRootFiles" => array_values(array_unique($unexpectedRootFiles)),
    "missingRequiredDirectories" => array_values(array_unique($missingDirs)),
    "summary" => [
        "obsoleteCount"  => count($obsolete),
        "misplacedCount" => count($misplaced),
        "forbiddenCount" => count($forbidden),
        "unexpectedRootFileCount" => count($unexpectedRootFiles),
        "missingDirCount" => count($missingDirs)
    ]
];

$savePath = $root . '/assets/data/repositoryAudit.json';
file_put_contents($savePath, json_encode($audit, JSON_PRETTY_PRINT));

// ---------------------------------------------------------------
//  STEP 7 – Output
// ---------------------------------------------------------------
echo json_encode([
    "status" => "success",
    "message" => "Repository audit completed.",
    "output" => "assets/data/repositoryAudit.json",
    "summary" => $audit['summary']
]);