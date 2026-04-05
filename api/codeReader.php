<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — codeReader.php
//  Version: 1.0.0
//  Last Updated: 2026-04-05
//  Codex Tier: 2 — System Inspection / Read-Only Utility
//
//  Role:
//  Secure file inspection endpoint for Skyebot and developer tooling.
//  Provides controlled, read-only access to approved source files.
//
//  Capabilities:
//   • Read code from whitelisted directories
//   • Return file contents (truncated for safety)
//   • Support AI-assisted code review workflows
//
//  Forbidden:
//   • No file mutation (read-only only)
//   • No access to sensitive directories (e.g., /secure, .env)
//   • No directory traversal outside allowed scope
// ======================================================================

// #region 🔐 Code Reader (Safe)

// Establish base directory (skyesoft root)
$baseDir = realpath(__DIR__ . '/../');

// Allowed directories (strict whitelist)
$allowedDirs = [
    realpath($baseDir . '/assets/js'),
    realpath($baseDir . '/api'),
    realpath($baseDir . '/scripts')
];

// Input
$relativePath = $_GET['file'] ?? '';

if (!$relativePath) {
    echo json_encode(['error' => 'No file specified']);
    exit;
}

// Normalize path
$fullPath = realpath($baseDir . '/' . $relativePath);

// Validate path against whitelist
$valid = false;
foreach ($allowedDirs as $dir) {
    if ($fullPath && strpos($fullPath, $dir) === 0) {
        $valid = true;
        break;
    }
}

// Final validation
if (!$valid || !file_exists($fullPath)) {
    echo json_encode(['error' => 'Access denied or file not found']);
    exit;
}

// Read file contents
$content = file_get_contents($fullPath);

// Optional safety limit (prevent large payloads)
$content = substr($content, 0, 20000);

// Output
echo json_encode([
    'file' => $relativePath,
    'content' => $content
]);

// #endregion