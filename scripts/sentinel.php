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
$statePath    = $rootDir . '/data/runtimeEphemeral/sentinelState.json';

error_log("SENTINEL WRITING: " . $statePath);

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

#region SECTION II — Audit → Mutate → Verify (Resilient Orchestrator)

define('SKYESOFT_LIB_MODE', true);

/* Required contract injection for Auditor */
$violationBatch = 'VB-' . time();

$executionStatus = 'ok';   // ok | audit-failed | mutator-failed | verify-failed
$executionError  = null;
$summary1        = null;
$summary2        = null;

/* -------------------------------------------------------------
 * PASS 1 — Audit
 * ------------------------------------------------------------- */
try {

    ob_start();
    $summary1 = require $auditorPath;
    ob_end_clean();

    if (!is_array($summary1) || ($summary1['runComplete'] ?? false) !== true) {
        throw new RuntimeException('Auditor did not return runComplete=true');
    }

} catch (Throwable $e) {

    $executionStatus = 'audit-failed';
    $executionError  = $e->getMessage();
}

/* -------------------------------------------------------------
 * PASS 2 — Mutate (only if PASS 1 succeeded)
 * ------------------------------------------------------------- */
if ($executionStatus === 'ok' && ($summary1['mutatableCount'] ?? 0) > 0) {

    try {

        ob_start();
        require $mutatorPath;
        ob_end_clean();

        /* PASS 3 — Verification Audit */
        ob_start();
        $summary2 = require $auditorPath;
        ob_end_clean();

        if (!is_array($summary2) || ($summary2['runComplete'] ?? false) !== true) {
            throw new RuntimeException('Verification audit failed');
        }

    } catch (Throwable $e) {

        $executionStatus = 'mutator-failed';
        $executionError  = $e->getMessage();
    }
}

/* ---------------------------------------------------------------------
 *  SECTION II.B — Runtime State Projection
 * --------------------------------------------------------------------- */

/* ---- Read canonical ledger (read-only) ---- */
$ledgerRaw = @file_get_contents($auditLogPath);
$ledger = is_string($ledgerRaw)
    ? json_decode($ledgerRaw, true)
    : null;

$unresolved = 0;
$constitutional = 0;

if (
    is_array($ledger) &&
    isset($ledger["violations"]) &&
    is_array($ledger["violations"])
) {
    foreach ($ledger["violations"] as $rec) {

        if (($rec["resolved"] ?? null) === null) {
            $unresolved++;

            if (
                isset($rec["ruleId"]) &&
                $rec["ruleId"] === "merkleIntegrity"
            ) {
                $constitutional++;
            }
        }
    }
}

/* ---- Determine governance status ---- */
if ($constitutional > 0) {
    $governanceStatus = 'constitutional-breach';
} elseif ($unresolved > 0) {
    $governanceStatus = 'violations-pending';
} else {
    $governanceStatus = 'clean';
}

/* ---- Build runtime state ---- */
$now = time();

$state = [
    "initialRunUnix"           => null,
    "lastRunUnix"              => $now,
    "runCount"                 => 1,

    // Execution layer
    "executionStatus"          => $executionStatus,
    "executionError"           => $executionError,

    // Governance layer
    "unresolvedViolations"     => $unresolved,
    "constitutionalViolations" => $constitutional,
    "governanceStatus"         => $governanceStatus,

    // Midnight Sentinel Report layer
    "midnightReport" => [
        "lastAttemptUnix" => null,
        "lastRunUnix"     => null,
        "runCount"        => 0,
        "lastStatus"      => null,
        "lastError"       => null
    ]
];

/* ---- Merge with existing state ---- */
if (file_exists($statePath)) {

    $existingRaw = file_get_contents($statePath);

    $existing = is_string($existingRaw)
        ? json_decode($existingRaw, true)
        : null;

    if (is_array($existing)) {

        // Preserve Sentinel lifecycle state
        $state["initialRunUnix"] =
            $existing["initialRunUnix"] ?? $now;

        $state["runCount"] =
            (int) ($existing["runCount"] ?? 0) + 1;

        // Preserve structured Midnight Sentinel Report state
        if (
            isset($existing["midnightReport"]) &&
            is_array($existing["midnightReport"])
        ) {
            $state["midnightReport"]["lastAttemptUnix"] =
                isset($existing["midnightReport"]["lastAttemptUnix"])
                    ? (int) $existing["midnightReport"]["lastAttemptUnix"]
                    : null;

            $state["midnightReport"]["lastRunUnix"] =
                isset($existing["midnightReport"]["lastRunUnix"])
                    ? (int) $existing["midnightReport"]["lastRunUnix"]
                    : null;

            $state["midnightReport"]["runCount"] =
                (int) (
                    $existing["midnightReport"]["runCount"] ?? 0
                );

            $state["midnightReport"]["lastStatus"] =
                $existing["midnightReport"]["lastStatus"] ?? null;

            $state["midnightReport"]["lastError"] =
                $existing["midnightReport"]["lastError"] ?? null;

        } elseif (isset($existing["dailyEmailLastRunUnix"])) {

            // Migrate legacy Daily Email state into Midnight Report state
            $state["midnightReport"]["lastRunUnix"] =
                (int) $existing["dailyEmailLastRunUnix"];

            $state["midnightReport"]["lastStatus"] =
                'success';
        }
    }
}

if ($state["initialRunUnix"] === null) {
    $state["initialRunUnix"] = $now;
}

/* ---- Ensure runtime directory exists ---- */
$runtimeDir = dirname($statePath);

if (!is_dir($runtimeDir)) {
    if (!mkdir($runtimeDir, 0755, true)) {
        error_log("SENTINEL ERROR: Failed creating runtimeDir {$runtimeDir}");
        exit(1);
    }
}

/* ---- Confirm runtime directory writable ---- */
if (!is_writable($runtimeDir)) {
    error_log("SENTINEL ERROR: runtimeDir not writable {$runtimeDir}");
    exit(1);
}

/* ---- Atomic write ---- */
$writeResult = file_put_contents(
    $statePath,
    json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($writeResult === false) {
    error_log("SENTINEL ERROR: Failed writing sentinelState.json");
    exit(1);
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

#region SECTION III.B — Midnight Sentinel Report Scheduler

// Configure Midnight Sentinel Report schedule (Phoenix local time)
$midnightReportHour = 0;
$midnightReportWindowMinutes = 15;

// Resolve Midnight Sentinel Report script & concurrency lock path
$midnightReportPath =
    $scriptsDir . '/sentinelDailyEmail.php';

$midnightReportLockPath =
    $rootDir . '/data/runtimeEphemeral/dailyEmail.lock';

// Initialize Phoenix timezone
$midnightReportTimezone =
    new DateTimeZone('America/Phoenix');

// Resolve current Phoenix date and time
$midnightReportNow =
    new DateTimeImmutable(
        'now',
        $midnightReportTimezone
    );

$midnightReportDateNow =
    $midnightReportNow->format('Y-m-d');

$midnightReportHourNow =
    (int) $midnightReportNow->format('G');

$midnightReportMinuteNow =
    (int) $midnightReportNow->format('i');

// Determine whether current execution is inside midnight window
$isMidnightReportWindow =
    $midnightReportHourNow === $midnightReportHour &&
    $midnightReportMinuteNow < $midnightReportWindowMinutes;

// Resolve date of last successful report from in-memory state
$lastMidnightReportDate = null;

if (
    isset($state['midnightReport']['lastRunUnix']) &&
    is_int($state['midnightReport']['lastRunUnix'])
) {
    $lastMidnightReportDateTime =
        (new DateTimeImmutable())
            ->setTimestamp(
                $state['midnightReport']['lastRunUnix']
            )
            ->setTimezone(
                $midnightReportTimezone
            );

    $lastMidnightReportDate =
        $lastMidnightReportDateTime->format('Y-m-d');
}

// Preliminary check: determine whether today's report requires delivery
$midnightReportDue =
    $isMidnightReportWindow &&
    $lastMidnightReportDate !== $midnightReportDateNow;

if ($midnightReportDue) {

    // Confirm Midnight Sentinel Report script exists
    if (!is_file($midnightReportPath)) {

        error_log(
            'SENTINEL MIDNIGHT REPORT ERROR: Missing email script: ' .
            $midnightReportPath
        );

    } else {

        // Acquire process-level lock to prevent overlapping executions
        $lockFp = @fopen(
            $midnightReportLockPath,
            'c+'
        );

        if (
            $lockFp !== false &&
            flock(
                $lockFp,
                LOCK_EX | LOCK_NB
            )
        ) {

            try {

                // Re-read authoritative state after acquiring execution lock
                $lockedStateRaw =
                    file_get_contents($statePath);

                $lockedState =
                    is_string($lockedStateRaw)
                        ? json_decode(
                            $lockedStateRaw,
                            true
                        )
                        : null;

                if (!is_array($lockedState)) {
                    throw new RuntimeException(
                        'Unable to read Sentinel runtime state ' .
                        'after acquiring Midnight Report lock.'
                    );
                }

                // Ensure Midnight Sentinel Report state exists
                if (
                    !isset($lockedState['midnightReport']) ||
                    !is_array($lockedState['midnightReport'])
                ) {
                    $lockedState['midnightReport'] = [
                        'lastAttemptUnix' => null,
                        'lastRunUnix'     => null,
                        'runCount'        => 0,
                        'lastStatus'      => null,
                        'lastError'       => null
                    ];
                }

                // Resolve last successful report execution inside lock
                $lockedLastMidnightReportDate = null;

                if (
                    isset(
                        $lockedState['midnightReport']['lastRunUnix']
                    ) &&
                    is_int(
                        $lockedState['midnightReport']['lastRunUnix']
                    )
                ) {
                    $lockedLastMidnightReportDateTime =
                        (new DateTimeImmutable())
                            ->setTimestamp(
                                $lockedState[
                                    'midnightReport'
                                ][
                                    'lastRunUnix'
                                ]
                            )
                            ->setTimezone(
                                $midnightReportTimezone
                            );

                    $lockedLastMidnightReportDate =
                        $lockedLastMidnightReportDateTime
                            ->format('Y-m-d');
                }

                // Confirm today's report still requires delivery
                if (
                    $lockedLastMidnightReportDate ===
                    $midnightReportDateNow
                ) {
                    error_log(
                        'SENTINEL MIDNIGHT REPORT NOTICE: ' .
                        'Report already delivered for ' .
                        $midnightReportDateNow .
                        '.'
                    );

                } else {

                    // Record report execution attempt
                    $lockedState[
                        'midnightReport'
                    ][
                        'lastAttemptUnix'
                    ] = time();

                    // Resolve active PHP executable
                    $phpBinary = PHP_BINARY;

                    // Build isolated Midnight Report command
                    $midnightReportCommand =
                        escapeshellarg($phpBinary) .
                        ' ' .
                        escapeshellarg($midnightReportPath) .
                        ' 2>&1';

                    // Initialize child-process result
                    $midnightReportOutput   = [];
                    $midnightReportExitCode = 1;
                    $midnightReportError    = null;

                    // Execute Midnight Sentinel Report
                    exec(
                        $midnightReportCommand,
                        $midnightReportOutput,
                        $midnightReportExitCode
                    );

                    // Record successful report delivery
                    if ($midnightReportExitCode === 0) {

                        $lockedState[
                            'midnightReport'
                        ][
                            'lastRunUnix'
                        ] = time();

                        $lockedState[
                            'midnightReport'
                        ][
                            'runCount'
                        ] =
                            (int) $lockedState[
                                'midnightReport'
                            ][
                                'runCount'
                            ] + 1;

                        $lockedState[
                            'midnightReport'
                        ][
                            'lastStatus'
                        ] = 'success';

                        $lockedState[
                            'midnightReport'
                        ][
                            'lastError'
                        ] = null;

                    } else {

                        // Build report execution error
                        $midnightReportError =
                            'Delivery failed with exit code ' .
                            $midnightReportExitCode .
                            '. Output: ' .
                            implode(
                                ' | ',
                                $midnightReportOutput
                            );

                        // Record failed report attempt
                        $lockedState[
                            'midnightReport'
                        ][
                            'lastStatus'
                        ] = 'failed';

                        $lockedState[
                            'midnightReport'
                        ][
                            'lastError'
                        ] = $midnightReportError;
                    }

                    // Persist updated Midnight Report state
                    $midnightReportStateWrite =
                        file_put_contents(
                            $statePath,
                            json_encode(
                                $lockedState,
                                JSON_PRETTY_PRINT |
                                JSON_UNESCAPED_SLASHES
                            ),
                            LOCK_EX
                        );

                    if ($midnightReportStateWrite === false) {

                        error_log(
                            'SENTINEL MIDNIGHT REPORT ERROR: ' .
                            'Report execution completed, but updated state could not be ' .
                            'written to sentinelState.json.'
                        );

                    } else {

                        // Synchronize current process state in memory
                        $state = $lockedState;

                        if ($midnightReportExitCode === 0) {

                            error_log(
                                'SENTINEL MIDNIGHT REPORT SUCCESS: ' .
                                'Report delivered for ' .
                                $midnightReportDateNow .
                                '.'
                            );

                        } else {

                            error_log(
                                'SENTINEL MIDNIGHT REPORT ERROR: ' .
                                $midnightReportError
                            );
                        }
                    }
                }

            } finally {

                // Release process lock
                flock(
                    $lockFp,
                    LOCK_UN
                );

                fclose($lockFp);
            }

        } else {

            if ($lockFp !== false) {
                fclose($lockFp);
            }

            error_log(
                'SENTINEL MIDNIGHT REPORT NOTICE: ' .
                'Execution skipped; another Midnight Report ' .
                'process is currently running.'
            );
        }
    }
}

#endregion

#region SECTION IV — Final Exit

exit(0);

#endregion