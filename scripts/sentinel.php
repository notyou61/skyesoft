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
 *   • Trigger mutator when fixable naming issues exist
 *   • Re-audit after mutation to verify resolution
 *   • Batch notification on canonical registry
 *
 *  Governance Notes:
 *   • auditResults.json is flat canonical registry (owned by Auditor)
 *   • Sentinel orchestrates flow but mutates only notification fields
 *   • Mutator handles fixes and resolution narratives
 *   • Strict role separation maintained
 * ===================================================================== */

#region SECTION 0 — Environment Setup

$rootDir      = dirname(__DIR__);
$scriptsDir   = $rootDir . '/scripts';
$dataDir      = $rootDir . '/data/records';

$auditorPath  = $scriptsDir . '/auditor.php';
$mutatorPath  = $scriptsDir . '/mutator.php';
$auditLogPath = $dataDir . '/auditResults.json';

#endregion SECTION 0 — Environment Setup

#region SECTION I — Guard Conditions

foreach ([$auditorPath, $mutatorPath] as $path) {
    if (!file_exists($path)) {
        exit(1);
    }
}

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

if (!file_exists($auditLogPath)) {
    file_put_contents($auditLogPath, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

#endregion SECTION I — Guard Conditions

#region SECTION II — Audit → Mutate → Verify

// PASS 1 — Audit (detect + persist)
ob_start();
require $auditorPath;
ob_end_clean();

// Load canonical violations to check for mutatable issues
$log = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($log)) {
    $log = [];
}

// Detect if there are unresolved NAMING_CONFORMANCE violations
$hasMutatable = false;
// Collect already-notified violationIds
foreach ($log as $idx => $rec) {
    if (
        ($rec['type'] ?? null) === 'violation' &&
        ($rec['resolved'] ?? null) === null
    ) {
        // Stamp audit run batch if missing or from prior run
        if (!isset($rec['violationBatch']) || !is_int($rec['violationBatch'])) {
            $log[$idx]['violationBatch'] = $runBatch;
        }

        if (!isset($alreadyNotified[$rec['violationId'] ?? ''])) {
            $targets[] = $idx;
        }
    }
}


// PASS 2 — Mutate (only if needed)
if ($hasMutatable) {
    require $mutatorPath;

    // PASS 3 — Verify (re-audit to infer resolution)
    ob_start();
    require $auditorPath;
    ob_end_clean();
}

#endregion SECTION II — Audit → Mutate → Verify

#region SECTION III — Notifier (Canonical Flat Registry Only)

$now = time();

// Reload log after potential mutation + verification
$log = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($log)) {
    $log = [];
}

/*
 * STEP 1: Build set of already-notified violationIds
 */
$alreadyNotified = [];
foreach ($log as $rec) {
    if (
        ($rec['type'] ?? null) === 'violation' &&
        isset($rec['notificationSent'])
    ) {
        $alreadyNotified[$rec['violationId'] ?? ''] = true;
    }
}

/*
 * STEP 2: Collect eligible violations (unresolved + never notified)
 */
$targets = [];
foreach ($log as $idx => $rec) {
    if (
        ($rec['type'] ?? null) === 'violation' &&
        ($rec['resolved'] ?? null) === null &&
        !isset($alreadyNotified[$rec['violationId'] ?? ''])
    ) {
        $targets[] = $idx;
    }
}

/*
 * STEP 3: Apply batch notification if needed
 */
if (!empty($targets)) {
    $batchId = 'BATCH-' . $now;

    foreach ($targets as $idx) {
        $log[$idx]['violationBatch']   = $batchId;
        $log[$idx]['notificationSent'] = $now;
    }

    file_put_contents(
        $auditLogPath,
        json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    $payload = [
        "batchId" => $batchId,
        "count"   => count($targets),
        "items"   => array_map(
            fn($idx) => [
                "violationId" => $log[$idx]['violationId'] ?? null,
                "ruleId"      => $log[$idx]['ruleId'] ?? null,
                "observation" => $log[$idx]['observation'] ?? null,
            ],
            $targets
        )
    ];

    error_log(
        "NOTIFIER BATCH\n" .
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

#endregion SECTION III — Notifier

#region SECTION IV — Final Exit

exit(0);

#endregion SECTION IV — Final Exit