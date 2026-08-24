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

    // Notifier layer
    "dailyEmailLastRunUnix"    => null
];

/* ---- Merge with existing state ---- */
if (file_exists($statePath)) {
    $existing = json_decode(file_get_contents($statePath), true);

    if (is_array($existing)) {
        $state["initialRunUnix"] =
            $existing["initialRunUnix"] ?? $now;

        $state["runCount"] =
            ($existing["runCount"] ?? 0) + 1;

        $state["dailyEmailLastRunUnix"] =
            isset($existing["dailyEmailLastRunUnix"])
                ? (int) $existing["dailyEmailLastRunUnix"]
                : null;
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

#region SECTION III.B — Daily Sentinel Email Scheduler

// Configure Sentinel Daily Email schedule (Phoenix local time)
$dailyEmailHour = 6;
$dailyEmailWindowMinutes = 15;

// Resolve Sentinel Daily Email script & concurrency lock path
$dailyEmailPath     = $scriptsDir . '/sentinelDailyEmail.php';
$dailyEmailLockPath = $rootDir . '/data/runtimeEphemeral/dailyEmail.lock';

// Initialize Phoenix timezone
$dailyEmailTimezone = new DateTimeZone('America/Phoenix');

// Resolve current Phoenix date and time
$dailyEmailNow = new DateTimeImmutable('now', $dailyEmailTimezone);

$dailyEmailDateNow   = $dailyEmailNow->format('Y-m-d');
$dailyEmailHourNow   = (int) $dailyEmailNow->format('G');
$dailyEmailMinuteNow = (int) $dailyEmailNow->format('i');

// Determine whether current execution is inside midnight window
$isDailyEmailWindow =
    $dailyEmailHourNow === $dailyEmailHour &&
    $dailyEmailMinuteNow < $dailyEmailWindowMinutes;

// Resolve date of last successful email execution from in-memory state
$lastDailyEmailDate = null;
if (is_int($state['dailyEmailLastRunUnix'])) {
    $lastDailyEmailDateTime = (new DateTimeImmutable())
        ->setTimestamp($state['dailyEmailLastRunUnix'])
        ->setTimezone($dailyEmailTimezone);

    $lastDailyEmailDate = $lastDailyEmailDateTime->format('Y-m-d');
}

// Preliminary check: determine whether today's report appears to require delivery
$dailyEmailDue =
    $isDailyEmailWindow &&
    $lastDailyEmailDate !== $dailyEmailDateNow;

if ($dailyEmailDue) {

    // Confirm Daily Email script exists
    if (!is_file($dailyEmailPath)) {

        error_log(
            'SENTINEL DAILY EMAIL ERROR: Missing email script: ' .
            $dailyEmailPath
        );

    } else {

        // Acquire process-level lock to prevent concurrent overlapping executions
        $lockFp = @fopen($dailyEmailLockPath, 'c+');

        if ($lockFp !== false && flock($lockFp, LOCK_EX | LOCK_NB)) {

            try {

                // Re-read authoritative state after acquiring execution lock
                $lockedStateRaw = file_get_contents($statePath);

                $lockedState = is_string($lockedStateRaw)
                    ? json_decode($lockedStateRaw, true)
                    : null;

                if (!is_array($lockedState)) {
                    throw new RuntimeException(
                        'Unable to read Sentinel runtime state after acquiring email lock.'
                    );
                }

                // Resolve last successful email execution inside lock
                $lockedLastDailyEmailDate = null;

                if (isset($lockedState['dailyEmailLastRunUnix'])) {

                    $lockedLastDailyEmailDateTime = (new DateTimeImmutable())
                        ->setTimestamp(
                            (int) $lockedState['dailyEmailLastRunUnix']
                        )
                        ->setTimezone($dailyEmailTimezone);

                    $lockedLastDailyEmailDate =
                        $lockedLastDailyEmailDateTime->format('Y-m-d');
                }

                // Confirm today's email still requires delivery
                if ($lockedLastDailyEmailDate === $dailyEmailDateNow) {

                    error_log(
                        'SENTINEL DAILY EMAIL NOTICE: ' .
                        'Daily report already delivered for ' .
                        $dailyEmailDateNow .
                        '.'
                    );

                } else {

                    // Resolve active PHP executable
                    $phpBinary = PHP_BINARY;

                    // Build isolated Daily Email command
                    $dailyEmailCommand =
                        escapeshellarg($phpBinary) .
                        ' ' .
                        escapeshellarg($dailyEmailPath) .
                        ' 2>&1';

                    // Initialize child-process result
                    $dailyEmailOutput   = [];
                    $dailyEmailExitCode = 1;

                    // Execute Daily Email as isolated child process
                    exec(
                        $dailyEmailCommand,
                        $dailyEmailOutput,
                        $dailyEmailExitCode
                    );

                    // Record successful daily delivery
                    if ($dailyEmailExitCode === 0) {

                        $lockedState['dailyEmailLastRunUnix'] = time();

                        $dailyEmailStateWrite = file_put_contents(
                            $statePath,
                            json_encode(
                                $lockedState,
                                JSON_PRETTY_PRINT |
                                JSON_UNESCAPED_SLASHES
                            ),
                            LOCK_EX
                        );

                        if ($dailyEmailStateWrite === false) {

                            error_log(
                                'SENTINEL DAILY EMAIL ERROR: ' .
                                'Unable to write updated state to sentinelState.json.'
                            );

                        } else {

                            // Synchronize current process state in memory
                            $state = $lockedState;

                            error_log(
                                'SENTINEL DAILY EMAIL SUCCESS: ' .
                                'Daily report delivered for ' .
                                $dailyEmailDateNow .
                                '.'
                            );
                        }

                    } else {

                        // Record child-process failure for retry
                        error_log(
                            'SENTINEL DAILY EMAIL ERROR: ' .
                            'Delivery failed with exit code ' .
                            $dailyEmailExitCode .
                            '. Output: ' .
                            implode(
                                ' | ',
                                $dailyEmailOutput
                            )
                        );
                    }
                }

            } finally {

                // Release process lock
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }

        } else {

            if ($lockFp !== false) {
                fclose($lockFp);
            }

            error_log(
                'SENTINEL DAILY EMAIL NOTICE: ' .
                'Execution skipped; another daily email process is currently running.'
            );
        }
    }
}

#endregion

#region SECTION IV — Final Exit

exit(0);

#endregion