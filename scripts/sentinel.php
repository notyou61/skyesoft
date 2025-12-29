<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinel.php
 *  Role: Sentinel (Audit Orchestrator & Notifier)
 *  Authority: AGS (Audit Governance Standard)
 *  PHP: 8.3+
 *
 *  Purpose:
 *   • Execute auditor to detect violations
 *   • React to explicit audit completion
 *   • Trigger mutator only when declared safe
 *   • Re-audit to verify resolution
 *   • Batch notification on canonical registry
 *
 *  Constitutional Rule:
 *   • Sentinel NEVER infers lifecycle state
 *   • Auditor MUST explicitly declare runComplete
 * ===================================================================== */

#region SECTION 0 — Environment Setup

$rootDir      = dirname(__DIR__);
$scriptsDir   = $rootDir . '/scripts';
$dataDir      = $rootDir . '/data/records';

$auditorPath  = $scriptsDir . '/auditor.php';
$mutatorPath  = $scriptsDir . '/mutator.php';
$auditLogPath = $dataDir . '/auditResults.json';

#endregion SECTION 0

#region SECTION I — Guard Conditions

foreach ([$auditorPath, $mutatorPath] as $path) {
    if (!file_exists($path)) {
        error_log("SENTINEL ERROR: Missing required file {$path}");
        exit(1);
    }
}

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

if (!file_exists($auditLogPath)) {
    file_put_contents(
        $auditLogPath,
        json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

#endregion SECTION I

#region SECTION II — Audit → Mutate → Verify (Contractual)

define('SKYESOFT_LIB_MODE', true);

/*
 * Create audit batch ONCE per run
 * Owned by Sentinel
 * Immutable for duration of this execution
 */
$auditBatch = 'BATCH-' . time();

// PASS 1 — Audit
$summary1 = require $auditorPath;

if (!is_array($summary1) || ($summary1['runComplete'] ?? false) !== true) {
    error_log("SENTINEL ERROR: Auditor failed to declare runComplete");
    exit(1);
}

// Guard against multiple mutation passes
$mutationPerformed = false;

// PASS 2 — Mutate (only if Auditor explicitly reports mutatable issues)
if (
    ($summary1['mutatableCount'] ?? 0) > 0 &&
    !$mutationPerformed
) {
    $mutationPerformed = true;

    require $mutatorPath;

    // PASS 3 — Verification Audit
    $summary2 = require $auditorPath;

    if (!is_array($summary2) || ($summary2['runComplete'] ?? false) !== true) {
        error_log("SENTINEL ERROR: Verification audit failed runComplete");
        exit(1);
    }
}

#endregion SECTION II

#region SECTION III — Notifier (Canonical Flat Registry Only)

$now = time();

// Reload canonical audit log
$log = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($log)) {
    $log = [];
}

/*
 * STEP 1 — Build set of already-notified violations
 * Rule: notificationSent must be a real timestamp
 */
$alreadyNotified = [];
foreach ($log as $rec) {
    if (
        ($rec['type'] ?? null) === 'violation' &&
        is_int($rec['notificationSent'] ?? null)
    ) {
        $alreadyNotified[$rec['violationId']] = true;
    }
}

/*
 * STEP 2 — Collect eligible unresolved violations
 */
$targets = [];
foreach ($log as $idx => $rec) {
    if (
        ($rec['type'] ?? null) === 'violation' &&
        ($rec['resolved'] ?? null) === null &&
        !isset($alreadyNotified[$rec['violationId']])
    ) {
        $targets[] = $idx;
    }
}

/*
 * STEP 3 — Apply batch notification
 */
if (!empty($targets)) {
    $batchId = 'BATCH-' . $now;

    foreach ($targets as $idx) {
        $log[$idx]['notificationBatch']   = $batchId;
        $log[$idx]['notificationSent'] = $now;
    }

    file_put_contents(
        $auditLogPath,
        json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    error_log(
        "NOTIFIER BATCH\n" .
        json_encode(
            [
                'batchId' => $batchId,
                'count'   => count($targets),
                'items'   => array_map(
                    fn ($idx) => [
                        'violationId' => $log[$idx]['violationId'],
                        'ruleId'      => $log[$idx]['ruleId'],
                        'observation' => $log[$idx]['observation'],
                    ],
                    $targets
                ),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )
    );
}

#endregion SECTION III

#region SECTION IV — Final Exit

exit(0);

#endregion SECTION IV