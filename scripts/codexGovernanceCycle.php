<?php
/**
 * Codex Governance Cycle (CP-STRESS-001)
 *
 * Runs the non-destructive governance pipeline:
 *  1. Repository Audit -> api/repositoryAudit.php
 *  2. Codex Validator  -> api/codexValidator.php
 *  3. (Optional) Document generation hooks for audit trail
 *
 * Outputs a structured JSON log under:
 *    assets/data/codex-governance-log_YYYYMMDD_HHMMSS.json
 *
 * This script DOES NOT auto-edit codex.json.
 * It recommends; humans ratify.
 */

$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    fwrite(STDERR, "❌ Unable to resolve base directory.\n");
    exit(1);
}

$logDir = $baseDir . '/assets/data';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true)) {
    fwrite(STDERR, "❌ Unable to create log directory at {$logDir}\n");
    exit(1);
}

$timestamp   = date('Ymd_His');
$logFileName = "codex-governance-log_{$timestamp}.json";
$logFilePath = $logDir . '/' . $logFileName;

$results = [
    'meta' => [
        'cycle'       => 'Codex Governance Cycle',
        'version'     => 'v1.0.0',
        'initiatedAt' => date('c'),
        'baseDir'     => $baseDir,
    ],
    'steps' => []
];

function runStep($label, $command)
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'command'  => $command,
        'exitCode' => $exitCode,
        'output'   => $output,
        'status'   => $exitCode === 0 ? 'ok' : 'error',
    ];
}

// 1) Repository Audit
$results['steps']['repositoryAudit'] = runStep(
    'repositoryAudit',
    escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($baseDir . '/api/repositoryAudit.php')
);

// 2) Codex Validator
if (file_exists($baseDir . '/api/codexValidator.php')) {
    $results['steps']['codexValidator'] = runStep(
        'codexValidator',
        escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($baseDir . '/api/codexValidator.php')
    );
} else {
    $results['steps']['codexValidator'] = [
        'status'  => 'skipped',
        'reason'  => 'codexValidator.php not found in /api',
        'command' => null,
    ];
}

// 3) Placeholder: Stress Test hook (non-fatal if not wired)
$results['steps']['codexStressTest'] = [
    'status' => 'deferred',
    'note'   => 'Codex Stress Test is invoked via generateDocuments.php slug=codexStressTest in the HTTP layer.'
];

// Write governance log
file_put_contents(
    $logFilePath,
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

echo "✅ Codex Governance Cycle completed.\n";
echo "   Log written to: assets/data/{$logFileName}\n";
