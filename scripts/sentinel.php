<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinel.php
 *  Role: Sentinel (Audit Orchestrator & Notifier)
 * ===================================================================== */

#region SECTION 0 — Environment Setup

$rootDir = realpath(__DIR__ . '/../');
if ($rootDir === false) {
    error_log("SENTINEL ERROR: Unable to resolve rootDir");
    exit(1);
}

$scriptsDir   = $rootDir . '/scripts';
$dataDir      = $rootDir . '/data/records';

$auditorPath  = $scriptsDir . '/auditor.php';
$mutatorPath  = $scriptsDir . '/mutator.php';
$auditLogPath = $dataDir . '/auditResults.json';

#endregion

#region SECTION I — Guard Conditions

foreach ([$auditorPath, $mutatorPath] as $path) {
    if (!is_file($path)) {
        error_log("SENTINEL ERROR: Missing required file {$path}");
        exit(1);
    }
}

if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0755, true)) {
        error_log("SENTINEL ERROR: Failed creating dataDir {$dataDir}");
        exit(1);
    }
}

if (!is_writable($dataDir)) {
    error_log("SENTINEL ERROR: dataDir not writable {$dataDir}");
    exit(1);
}

if (!file_exists($auditLogPath)) {
    $initWrite = file_put_contents(
        $auditLogPath,
        json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    if ($initWrite === false) {
        error_log("SENTINEL ERROR: Failed initializing audit log");
        exit(1);
    }
}

#endregion

#region SECTION II — Audit → Mutate → Verify

define('SKYESOFT_LIB_MODE', true);

$violationBatch = 'VB-' . time();

/* PASS 1 — Audit */
ob_start();
$summary1 = require $auditorPath;
ob_end_clean();

if (!is_array($summary1) || ($summary1['runComplete'] ?? false) !== true) {
    error_log("SENTINEL ERROR: Auditor failed runComplete");
    exit(1);
}

$mutationPerformed = false;

/* PASS 2 — Mutate */
if (($summary1['mutatableCount'] ?? 0) > 0 && !$mutationPerformed) {

    $mutationPerformed = true;

    ob_start();
    require $mutatorPath;
    ob_end_clean();

    /* PASS 3 — Verification Audit */
    ob_start();
    $summary2 = require $auditorPath;
    ob_end_clean();

    if (!is_array($summary2) || ($summary2['runComplete'] ?? false) !== true) {
        error_log("SENTINEL ERROR: Verification audit failed runComplete");
        exit(1);
    }
}

#endregion

#region SECTION III — Notifier

$now = time();

$rawLog = file_get_contents($auditLogPath);
if ($rawLog === false) {
    error_log("SENTINEL ERROR: Failed reading audit log");
    exit(1);
}

$log = json_decode($rawLog, true);
if (!is_array($log)) {
    error_log("SENTINEL ERROR: Corrupt audit log JSON");
    exit(1);
}

$alreadyNotified = [];
foreach ($log as $rec) {
    if (
        ($rec['type'] ?? null) === 'violation' &&
        is_int($rec['notificationSent'] ?? null)
    ) {
        $alreadyNotified[$rec['violationId']] = true;
    }
}

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

if (!empty($targets)) {

    $batchId = 'BATCH-' . $now;

    foreach ($targets as $idx) {
        $log[$idx]['notificationSent'] = $now;
    }

    $writeResult = file_put_contents(
        $auditLogPath,
        json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($writeResult === false) {
        error_log("SENTINEL ERROR: Failed writing notification batch");
        exit(1);
    }

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

#endregion

#region SECTION IV — Final Exit

exit(0);

#endregion