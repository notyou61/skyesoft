<?php
/**
 * ============================================================
 *  SKYESOFT CODEX – GOVERNANCE CYCLE ENGINE
 *  Module: codexGovernanceCycle.php
 *  Version: v1.3.0
 *
 *  Purpose:
 *    Runs the non-destructive Codex Governance Cycle:
 *      1) Repository Audit       (/api/repositoryAudit.php)
 *      2) Codex Validator        (/api/codexValidator.php)
 *      3) Stress Test Placeholder (HTTP-triggered)
 *
 *  Output:
 *    /assets/data/codex-governance-log_YYYYMMDD_HHMMSS.json
 *
 *  Behavior:
 *    • Never modifies codex.json
 *    • Produces recommendations only
 *    • Requires human ratification
 * ============================================================
 */


// ------------------------------------------------------------
//  Resolve Base Directory
// ------------------------------------------------------------
$baseDir = realpath(__DIR__ . '/..');

if ($baseDir === false) {
    fwrite(STDERR, "❌ ERROR: Unable to resolve base directory.\n");
    exit(1);
}


// ------------------------------------------------------------
//  Prepare Log Directory
// ------------------------------------------------------------
$logDir = $baseDir . '/assets/data';

if (!is_dir($logDir) && !mkdir($logDir, 0775, true)) {
    fwrite(STDERR, "❌ ERROR: Unable to create log directory: {$logDir}\n");
    exit(1);
}


// ------------------------------------------------------------
//  Build Log File Name
// ------------------------------------------------------------
$timestamp   = date('Ymd_His');
$logFileName = "codex-governance-log_{$timestamp}.json";
$logFilePath = "{$logDir}/{$logFileName}";


// ------------------------------------------------------------
//  Initialize Output Structure
// ------------------------------------------------------------
$results = [
    'meta' => [
        'cycle'       => 'Codex Governance Cycle',
        'version'     => 'v1.3.0',
        'initiatedAt' => date('c'),
        'baseDir'     => $baseDir
    ],
    'steps' => []
];


// ------------------------------------------------------------
//  Helper: Execute a Governance Step
// ------------------------------------------------------------
function runStep($label, $command)
{
    $output = [];
    $exit   = 0;

    exec($command . ' 2>&1', $output, $exit);

    return [
        'label'    => $label,
        'command'  => $command,
        'exitCode' => $exit,
        'status'   => ($exit === 0 ? 'ok' : 'error'),
        'output'   => $output
    ];
}


// ------------------------------------------------------------
//  STEP 1 – Repository Audit
// ------------------------------------------------------------
$repoAudit = $baseDir . '/api/repositoryAudit.php';

$results['steps']['repositoryAudit'] = runStep(
    'repositoryAudit',
    escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($repoAudit)
);


// ------------------------------------------------------------
//  STEP 2 – Codex Validator
// ------------------------------------------------------------
$validator = $baseDir . '/api/codexValidator.php';

if (file_exists($validator)) {
    $results['steps']['codexValidator'] = runStep(
        'codexValidator',
        escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($validator)
    );
} else {
    $results['steps']['codexValidator'] = [
        'status' => 'skipped',
        'reason' => 'codexValidator.php not found'
    ];
}


// ------------------------------------------------------------
–  STEP 3 – Deferred Stress Test
// ------------------------------------------------------------
$results['steps']['codexStressTest'] = [
    'status' => 'deferred',
    'note'   => 'Run via generateDocuments.php?slug=codexStressTest'
];


// ------------------------------------------------------------
//  Write Governance Log
// ------------------------------------------------------------
file_put_contents(
    $logFilePath,
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);


// ------------------------------------------------------------
//  Output Summary
// ------------------------------------------------------------
echo "✅ Codex Governance Cycle complete.\n";
echo "📄 Log saved to: assets/data/{$logFileName}\n";
