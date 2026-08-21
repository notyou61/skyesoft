<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinelDailyReport.php
 *  Sentinel Daily Governance Report
 *  Codex-Governed Module • PHP 8.3
 *  Implements: Structural Code Standard
 * ===================================================================== */

#region SECTION I — Metadata & Error Handling

// Set Skyesoft reporting timezone (Phoenix, Arizona)
date_default_timezone_set('America/Phoenix');

header('Content-Type: text/html; charset=UTF-8');

function fail(string $message): never
{
    http_response_code(500);

    echo '<h1>Sentinel Daily Report</h1>';
    echo '<p>❌ ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';

    exit;
}

#endregion

#region SECTION II — Configuration Loading

$rootDir = realpath(__DIR__ . '/../');

if ($rootDir === false) {
    fail('Unable to resolve Skyesoft root directory.');
}

$statePath = $rootDir . '/data/runtimeEphemeral/sentinelState.json';

if (!is_file($statePath)) {
    fail('Sentinel runtime state is unavailable.');
}

#endregion

#region SECTION III — Helpers & Utilities

function formatUnixDate(?int $unix): string
{
    if ($unix === null || $unix <= 0) {
        return 'Not Available';
    }

    return date('m/d/Y g:i:s A', $unix);
}

function formatGovernanceStatus(string $status): string
{
    switch ($status) {
        case 'clean':
            return 'Clean';

        case 'violations-pending':
            return 'Violations Pending';

        case 'constitutional-breach':
            return 'Constitutional Breach';

        default:
            return 'Unknown';
    }
}

function formatExecutionStatus(string $status): string
{
    switch ($status) {
        case 'ok':
            return 'OK';

        case 'audit-failed':
            return 'Audit Failed';

        case 'mutator-failed':
            return 'Mutator Failed';

        case 'verify-failed':
            return 'Verification Failed';

        default:
            return 'Unknown';
    }
}

#endregion

#region SECTION IV — Report Data

$rawState = file_get_contents($statePath);

if ($rawState === false) {
    fail('Unable to read Sentinel runtime state.');
}

$state = json_decode($rawState, true);

if (!is_array($state)) {
    fail('Sentinel runtime state contains invalid JSON.');
}

$initialRunUnix = isset($state['initialRunUnix'])
    ? (int) $state['initialRunUnix']
    : null;

$lastRunUnix = isset($state['lastRunUnix'])
    ? (int) $state['lastRunUnix']
    : null;

$runCount = (int) ($state['runCount'] ?? 0);

$executionStatus = (string) ($state['executionStatus'] ?? 'unknown');
$executionError = $state['executionError'] ?? null;

$unresolvedViolations = (int) ($state['unresolvedViolations'] ?? 0);
$constitutionalViolations = (int) ($state['constitutionalViolations'] ?? 0);

$governanceStatus = (string) ($state['governanceStatus'] ?? 'unknown');

#endregion

#region SECTION V — Report Rendering

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Skyesoft Sentinel Daily Report</title>

    <style>
        body {
            margin: 40px;
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
            background: #fff;
        }

        .report {
            max-width: 900px;
            margin: 0 auto;
        }

        h1 {
            margin-bottom: 5px;
        }

        .subtitle {
            margin-top: 0;
            color: #666;
        }

        table {
            width: 100%;
            margin-top: 30px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 12px;
            border: 1px solid #ccc;
            text-align: left;
        }

        th {
            width: 40%;
            background: #f3f3f3;
        }

        .sectionTitle {
            margin-top: 35px;
            padding-bottom: 6px;
            border-bottom: 1px solid #ccc;
        }

        .error {
            color: #a00000;
        }
    </style>
</head>

<body>

<div class="report">

    <h1>Skyesoft Sentinel Daily Report</h1>

    <p class="subtitle">
        Governance and execution status reported from Sentinel runtime state.
    </p>

    <h2 class="sectionTitle">Governance Status</h2>

    <table>
        <tr>
            <th>Governance Status</th>
            <td>
                <?= htmlspecialchars(
                    formatGovernanceStatus($governanceStatus),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>Unresolved Violations</th>
            <td><?= number_format($unresolvedViolations) ?></td>
        </tr>

        <tr>
            <th>Constitutional Violations</th>
            <td><?= number_format($constitutionalViolations) ?></td>
        </tr>
    </table>

    <h2 class="sectionTitle">Sentinel Execution</h2>

    <table>
        <tr>
            <th>Execution Status</th>
            <td>
                <?= htmlspecialchars(
                    formatExecutionStatus($executionStatus),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>Execution Error</th>
            <td class="<?= $executionError !== null ? 'error' : '' ?>">
                <?= htmlspecialchars(
                    $executionError !== null
                        ? (string) $executionError
                        : 'None',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>
    </table>

    <h2 class="sectionTitle">Runtime</h2>

    <table>
        <tr>
            <th>Initial Run</th>
            <td><?= htmlspecialchars(formatUnixDate($initialRunUnix)) ?></td>
        </tr>

        <tr>
            <th>Last Run</th>
            <td><?= htmlspecialchars(formatUnixDate($lastRunUnix)) ?></td>
        </tr>

        <tr>
            <th>Total Runs</th>
            <td><?= number_format($runCount) ?></td>
        </tr>
    </table>

</div>

</body>
</html>
<?php

#endregion