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

// Resolve Skyesoft installation root
$rootDir = realpath(__DIR__ . '/../');

if ($rootDir === false) {
    fail('Unable to resolve Skyesoft root directory.');
}

// Define Sentinel runtime state
$statePath = $rootDir . '/data/runtimeEphemeral/sentinelState.json';

// Define authoritative Skyesoft version metadata
$versionsPath = $rootDir . '/data/authoritative/versions.json';

// Define standard Christy Signs report logo
$logoPath = $rootDir . '/assets/images/christyLogo.png';

// Validate Sentinel runtime state
if (!is_file($statePath)) {
    fail('Sentinel runtime state is unavailable.');
}

// Validate authoritative version metadata
if (!is_file($versionsPath)) {
    fail('Authoritative version metadata is unavailable: ' . $versionsPath);
}

// Load canonical Skyesoft database connection
require_once $rootDir . '/api/dbConnect.php';

if (!function_exists('getPDO')) {
    fail('Skyesoft database connection is unavailable.');
}

// Initialize database connection
$pdo = getPDO();

#endregion

#region SECTION III — Helpers & Utilities

/**
 * Escape report output.
 */
function escapeReportValue(mixed $value): string
{
    return htmlspecialchars(
        trim((string) ($value ?? '')),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Format a Unix timestamp in Phoenix local time.
 */
function formatUnixDate(?int $unix): string
{
    if ($unix === null || $unix <= 0) {
        return 'Not Available';
    }

    return date('F j, Y g:i A', $unix);
}

/**
 * Format Sentinel governance status.
 */
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

/**
 * Format Sentinel execution status.
 */
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

/**
 * Format elapsed time from a Unix timestamp.
 */
function formatElapsedTime(?int $unix): string
{
    if ($unix === null || $unix <= 0) {
        return 'Not Available';
    }

    $elapsedSeconds = time() - $unix;

    if ($elapsedSeconds < 0) {
        return 'Not Available';
    }

    $days = (int) floor($elapsedSeconds / 86400);
    $hours = (int) floor(($elapsedSeconds % 86400) / 3600);
    $minutes = (int) floor(($elapsedSeconds % 3600) / 60);

    if ($days > 0) {
        return $days . ' days, ' . $hours . ' hours';
    }

    if ($hours > 0) {
        return $hours . ' hours, ' . $minutes . ' minutes';
    }

    return $minutes . ' minutes';
}

/**
 * Build a standard Skyesoft report section heading.
 */
function buildSectionHeading(string $title): string
{
    return '<div class="section-heading">'
        . '<span class="section-heading-title">'
        . escapeReportValue($title)
        . '</span>'
        . '</div>';
}

#endregion

#region SECTION IV — Report Data

// Load Sentinel runtime state
$rawState = file_get_contents($statePath);

if ($rawState === false) {
    fail('Unable to read Sentinel runtime state.');
}

$state = json_decode($rawState, true);

if (!is_array($state)) {
    fail('Sentinel runtime state contains invalid JSON.');
}

// Resolve Sentinel runtime details
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

// Load authoritative version metadata
$rawVersions = file_get_contents($versionsPath);

if ($rawVersions === false) {
    fail('Unable to read authoritative version metadata.');
}

$versions = json_decode($rawVersions, true);

if (!is_array($versions)) {
    fail('Authoritative version metadata contains invalid JSON.');
}

// Resolve current Skyesoft version details
$siteVersion = (string) ($versions['system']['siteVersion'] ?? 'Unknown');
$siteState = (string) ($versions['system']['state'] ?? 'Unknown');
$commitHash = (string) ($versions['system']['commitHash'] ?? 'Unknown');

$lastUpdateUnix = isset($versions['system']['lastUpdateUnix'])
    ? (int) $versions['system']['lastUpdateUnix']
    : null;

// Resolve report-generation time
$reportGeneratedUnix = time();
$reportDate = date('F j, Y', $reportGeneratedUnix);
$reportTime = date('g:i A', $reportGeneratedUnix);

// Resolve standard Christy Signs logo
$logoUrl = '../assets/images/christyLogo.png';
$logoAvailable = is_file($logoPath);

// #region Entity Classification Audit

// Initialize Entity classification counts
$entityCounts = [
    'company' => 0,
    'customer' => 0,
    'vendor' => 0,
    'jurisdiction' => 0
];

try {

    // Count valid Entities by classification
    $entityCountSql = "
        SELECT
            entityType,
            COUNT(*) AS entityCount
        FROM tblEntities
        WHERE entityIsNotValid = 0
        GROUP BY entityType
    ";

    $entityCountStmt = $pdo->prepare($entityCountSql);
    $entityCountStmt->execute();

    $entityCountRows = $entityCountStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($entityCountRows as $entityCountRow) {

        $entityType = (string) ($entityCountRow['entityType'] ?? '');

        if (array_key_exists($entityType, $entityCounts)) {
            $entityCounts[$entityType] =
                (int) ($entityCountRow['entityCount'] ?? 0);
        }
    }

    // Load valid Company-classified Entities
    $companySql = "
        SELECT
            entityId,
            entityName
        FROM tblEntities
        WHERE entityType = 'company'
            AND entityIsNotValid = 0
        ORDER BY entityId
    ";

    $companyStmt = $pdo->prepare($companySql);
    $companyStmt->execute();

    $companyEntities = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Validate sole Company classification
    $companyNeedsAttention =
        count($companyEntities) !== 1 ||
        strcasecmp(
            (string) ($companyEntities[0]['entityName'] ?? ''),
            'Christy Signs'
        ) !== 0;

} catch (Throwable $throwable) {

    fail(
        'Unable to complete Entity classification audit: ' .
        $throwable->getMessage()
    );
}

// #endregion

#endregion

#region SECTION V — Report Rendering

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Skyesoft Sentinel Daily Report</title>

    <style>

        /* =============================================================
         * Page
         * ============================================================= */

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 32px 24px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.35;
            color: #222;
            background: #fff;
        }

        .report {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }


        /* =============================================================
         * Report Header
         * ============================================================= */

        .header-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
            border-bottom: 3px solid #14377c;
        }

        .header-table td {
            padding: 0 0 5px;
            border: 0;
            vertical-align: bottom;
        }

        .header-logo {
            display: block;
            width: auto;
            height: 74px;
        }

        .logo-fallback {
            color: #14377c;
            font-size: 22px;
            font-weight: bold;
        }

        .header-report-details {
            width: 100%;
            padding-left: 8px;
            text-align: left;
        }
        .header-title {
            margin: 0;
            color: #14377c;
            font-size: 25px;
            font-weight: bold;
            line-height: 1;
        }

        .header-subtitle-main {
            margin-top: 2px;
            color: #333;
            font-size: 17px;
            font-weight: bold;
            line-height: 1.05;
        }

        .header-subtitle-sub {
            margin-top: 1px;
            color: #555;
            font-size: 14px;
            line-height: 1.05;
        }

        .header-report-date {
            margin-top: 1px;
            color: #666;
            font-size: 12px;
            line-height: 1.05;
        }


        /* =============================================================
         * Report Sections
         * ============================================================= */

        .section-block {
            margin-top: 24px;
        }

        .section-heading {
            margin-bottom: 7px;
            padding-bottom: 4px;
            color: #14377c;
            font-size: 17px;
            font-weight: bold;
            border-bottom: 2px solid #14377c;
        }

        .section-heading-title {
            display: inline-block;
            vertical-align: middle;
        }


        /* =============================================================
         * Data Tables
         * ============================================================= */

        .data-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 8px 10px;
            border: 1px solid #ccc;
            vertical-align: top;
            text-align: left;
        }

        .data-table th {
            width: 32%;
            color: #333;
            font-weight: bold;
            background: #f8f9fa;
        }

        .data-table td {
            width: 68%;
            color: #111;
            background: #fff;
        }


        /* =============================================================
         * Status Presentation
         * ============================================================= */

        .status {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }

        .status--resolved {
            color: #176638;
            background: #eaf7ef;
            border: 1px solid #9fd0ae;
        }

        .status--review {
            color: #8a5a00;
            background: #fff5dc;
            border: 1px solid #e8c46e;
        }

        .status--error {
            color: #8a1f1f;
            background: #fbeaea;
            border: 1px solid #d9a0a0;
        }

        .error {
            color: #a00000;
            font-weight: bold;
        }


        /* =============================================================
         * Report Callouts
         * ============================================================= */

        .callout-box {
            margin: 8px 0 0;
            padding: 9px 12px;
            background: #f0f4f9;
            border: 1px solid #b8cbe5;
            border-left: 4px solid #14377c;
        }

        .callout-title {
            margin-bottom: 4px;
            color: #14377c;
            font-size: 14px;
            font-weight: bold;
        }

        .callout-body {
            color: #333;
            font-size: 13px;
            line-height: 1.4;
        }


        /* =============================================================
         * Report Footer
         * ============================================================= */

        .report-footer {
            width: 100%;
            margin-top: 32px;
            padding-top: 7px;
            color: #666;
            font-size: 12px;
            border-top: 1px solid #ccc;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .footer-table td {
            padding: 0;
            border: 0;
        }

        .footer-right {
            text-align: right;
        }


        /* =============================================================
         * Responsive Browser Display
         * ============================================================= */

        @media (max-width: 650px) {

            body {
                padding: 18px 12px;
            }

            .header-logo {
                height: 55px;
            }

            .header-title {
                font-size: 19px;
            }

            .header-subtitle-main {
                font-size: 14px;
            }

            .data-table th {
                width: 40%;
            }

            .data-table td {
                width: 60%;
            }
        }

    </style>

</head>

<body>

<div class="report">

    <!-- =============================================================
         Report Header
         ============================================================= -->

    <table class="header-table">
        <tr>

            <td style="width: 18%; white-space: nowrap;">

                <?php if ($logoAvailable): ?>

                    <img
                        src="<?= escapeReportValue($logoUrl) ?>"
                        class="header-logo"
                        alt="Christy Signs"
                    >

                <?php else: ?>

                    <div class="logo-fallback">
                        Christy Signs
                    </div>

                <?php endif; ?>

            </td>

            <td style="width: 82%; text-align: left;">

                <div class="header-report-details">

                    <div class="header-title">
                        Skyesoft Sentinel Daily Report
                    </div>

                    <div class="header-subtitle-main">
                        System Governance &amp; Health
                    </div>

                    <div class="header-subtitle-sub">
                        Skyesoft Sentinel
                    </div>

                    <div class="header-report-date">
                        Report Date:
                        <?= escapeReportValue($reportDate) ?>
                        ·
                        <?= escapeReportValue($reportTime) ?>
                        MST
                    </div>

                </div>

            </td>

        </tr>
    </table>


    <!-- =============================================================
         Governance Status
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Governance Status') ?>

        <table class="data-table">

            <tr>
                <th>Governance Status</th>

                <td>
                    <?php
                    $governanceDisplay = formatGovernanceStatus(
                        $governanceStatus
                    );

                    $governanceClass = match ($governanceStatus) {
                        'clean' => 'status--resolved',
                        'violations-pending' => 'status--review',
                        'constitutional-breach' => 'status--error',
                        default => 'status--review'
                    };
                    ?>

                    <span class="status <?= $governanceClass ?>">
                        <?= escapeReportValue($governanceDisplay) ?>
                    </span>
                </td>
            </tr>

            <tr>
                <th>Unresolved Violations</th>
                <td>
                    <strong>
                        <?= number_format($unresolvedViolations) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <th>Constitutional Violations</th>
                <td>
                    <strong>
                        <?= number_format($constitutionalViolations) ?>
                    </strong>
                </td>
            </tr>

        </table>

    </div>


    <!-- =============================================================
         Sentinel Execution
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Sentinel Execution') ?>

        <table class="data-table">

            <tr>
                <th>Execution Status</th>

                <td>
                    <?php
                    $executionDisplay = formatExecutionStatus(
                        $executionStatus
                    );

                    $executionClass = $executionStatus === 'ok'
                        ? 'status--resolved'
                        : 'status--error';
                    ?>

                    <span class="status <?= $executionClass ?>">
                        <?= escapeReportValue($executionDisplay) ?>
                    </span>
                </td>
            </tr>

            <tr>
                <th>Execution Error</th>

                <td class="<?= $executionError !== null ? 'error' : '' ?>">
                    <?= escapeReportValue(
                        $executionError !== null
                            ? (string) $executionError
                            : 'None'
                    ) ?>
                </td>
            </tr>

        </table>

    </div>


    <!-- =============================================================
         Version Details
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Version Details') ?>

        <table class="data-table">

            <tr>
                <th>Skyesoft Version</th>
                <td>
                    <strong>
                        <?= escapeReportValue($siteVersion) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <th>Environment</th>
                <td>
                    <?= escapeReportValue(strtoupper($siteState)) ?>
                </td>
            </tr>

            <tr>
                <th>Deployed</th>
                <td>
                    <?= escapeReportValue(
                        formatUnixDate($lastUpdateUnix)
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Version Age</th>
                <td>
                    <?= escapeReportValue(
                        formatElapsedTime($lastUpdateUnix)
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Commit</th>
                <td>
                    <?= escapeReportValue($commitHash) ?>
                </td>
            </tr>

        </table>

    </div>


    <!-- =============================================================
         Runtime
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Runtime') ?>

        <table class="data-table">

            <tr>
                <th>Initial Run</th>
                <td>
                    <?= escapeReportValue(
                        formatUnixDate($initialRunUnix)
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Last Run</th>
                <td>
                    <?= escapeReportValue(
                        formatUnixDate($lastRunUnix)
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Total Runs</th>
                <td>
                    <strong>
                        <?= number_format($runCount) ?>
                    </strong>
                </td>
            </tr>

        </table>

    </div>


    <!-- =============================================================
         Database Integrity
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Database Integrity') ?>

        <div
            class="callout-title"
            style="margin-bottom: 7px;"
        >
            Entity Classification
        </div>

    <table class="data-table">

        <tr>
            <th>

                <table
                    cellpadding="0"
                    cellspacing="0"
                    border="0"
                    style="
                        width: auto;
                        border-collapse: collapse;
                    "
                >
                    <tr>

                        <td
                            style="
                                padding: 0 5px 0 0;
                                border: 0;
                                background: transparent;
                                color: #333333;
                                font-weight: bold;
                                white-space: nowrap;
                            "
                        >
                            Company
                        </td>

                        <?php if ($companyNeedsAttention): ?>

                            <td
                                style="
                                    padding: 2px 6px;
                                    border: 1px solid #e8c46e;
                                    background: #fff5dc;
                                    color: #8a5a00;
                                    font-size: 11px;
                                    font-weight: bold;
                                    line-height: 1.1;
                                    white-space: nowrap;
                                "
                            >
                                Needs Attention
                            </td>

                        <?php endif; ?>

                    </tr>
                </table>

            </th>

            <td>
                <strong>
                    <?= number_format($entityCounts['company']) ?>
                </strong>
            </td>
        </tr>

        <tr>
            <th>Customers</th>

            <td>
                <?= number_format($entityCounts['customer']) ?>
            </td>
        </tr>

        <tr>
            <th>Vendors</th>

            <td>
                <?= number_format($entityCounts['vendor']) ?>
            </td>
        </tr>

        <tr>
            <th>Jurisdictions</th>

            <td>
                <?= number_format($entityCounts['jurisdiction']) ?>
            </td>
        </tr>

    </table>

    </div>


    <!-- =============================================================
         Report Basis
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Report Basis') ?>

        <div class="callout-box">

            <div class="callout-title">
                Sentinel Status
            </div>

            <div class="callout-body">
                This report summarizes Skyesoft Sentinel governance,
                execution, version, runtime, and database integrity
                state as of
                <strong><?= escapeReportValue($reportDate) ?></strong>
                at
                <strong><?= escapeReportValue($reportTime) ?> MST</strong>.
                Runtime information is sourced from the Sentinel runtime
                state, version information is sourced from Skyesoft's
                authoritative version metadata, and database integrity
                information is sourced from the live Skyesoft database.
            </div>

        </div>

    </div>


    <!-- =============================================================
         Report Footer
         ============================================================= -->

    <div class="report-footer">

        <table class="footer-table">
            <tr>

                <td style="width: 70%;">
                    Prepared by Steve Skye | Christy Signs
                </td>

                <td
                    class="footer-right"
                    style="width: 30%;"
                >
                    Skyesoft Sentinel
                </td>

            </tr>
        </table>

    </div>

</div>

</body>
</html>
<?php

#endregion