<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinel.php
 *  Role: Sentinel (Scheduled Audit Trigger & Recorder)
 *  Authority: AGS (Audit Governance Standard)
 *  PHP: 8.3+
 *
 *  Purpose:
 *   • Execute the auditor on a fixed schedule (cron)
 *   • Capture auditor output verbatim
 *   • Append results to auditResults.json
 *
 *  Explicitly Forbidden:
 *   • NO filesystem scanning
 *   • NO audit logic
 *   • NO violation interpretation
 *   • NO IDs, lifecycle, or state mutation
 * ===================================================================== */

#region SECTION 0 — Environment Setup

$rootDir    = dirname(__DIR__);            // /skyesoft
$scriptsDir = $rootDir . '/scripts';
$dataDir    = $rootDir . '/data/records';

$auditorPath = $scriptsDir . '/auditor.php';
$auditLog    = $dataDir . '/auditResults.json';

#endregion SECTION 0 — Environment Setup

#region SECTION I — Guard Conditions

// Auditor must exist
if (!file_exists($auditorPath)) {
    // Cron-safe silent failure
    exit(1);
}

// Ensure records directory exists
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Ensure audit log exists
if (!file_exists($auditLog)) {
    file_put_contents(
        $auditLog,
        json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

#endregion SECTION I — Guard Conditions

#region SECTION II — Execute Auditor
ob_start();
require $auditorPath;
$auditorOutput = trim(ob_get_clean());

// Auditor produced no output — sentinel records nothing
if ($auditorOutput === '') {
    exit(0);
}

$decoded = json_decode($auditorOutput, true);

// Non-JSON output is ignored by Sentinel
if (!is_array($decoded)) {
    exit(0);
}
#endregion SECTION II — Execute Auditor

#region SECTION III — Append Audit Record (Immutable)

$log = json_decode(file_get_contents($auditLog), true);
if (!is_array($log)) {
    $log = [];
}

$log[] = [
    'timestamp' => time(),
    'source'    => 'sentinel',
    'audit'     => $decoded
];

file_put_contents(
    $auditLog,
    json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

#endregion SECTION III — Append Audit Record

#region SECTION IV — Exit

exit(0);

#endregion SECTION IV — Exit