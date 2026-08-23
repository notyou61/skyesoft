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

// #region Sentinel Runtime State

// Load Sentinel runtime state
$rawState = file_get_contents($statePath);

if ($rawState === false) {
    fail('Unable to read Sentinel runtime state.');
}

$state = json_decode(
    $rawState,
    true
);

if (!is_array($state)) {
    fail('Sentinel runtime state contains invalid JSON.');
}

// #endregion

// #region Sentinel Runtime Details

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

// #endregion

// #region Authoritative Version Metadata

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

// #endregion

// #region Report Generation Details

// Resolve report-generation time
$reportGeneratedUnix = time();

$reportDate = date(
    'F j, Y',
    $reportGeneratedUnix
);

$reportTime = date(
    'g:i A',
    $reportGeneratedUnix
);

// #endregion

// #region Report Branding

// Resolve standard Christy Signs logo
$logoUrl = '../assets/images/christyLogo.png';
$logoAvailable = is_file($logoPath);

// #endregion

// #region Database Health

// Initialize Database Health metrics storage
$dbHealth = [
    'actions' => [
        'total'           => 0,
        'definedTypes'    => 0,
        'unknownTypes'    => 0,
        'coordPairErrors' => 0
    ],
    'entities' => [
        'total'         => 0,
        'valid'         => 0,
        'invalid'       => 0,
        'companies'     => 0,
        'customers'     => 0,
        'vendors'       => 0,
        'jurisdictions' => 0
    ],
    'locations' => [
        'total'           => 0,
        'valid'           => 0,
        'invalid'         => 0,
        'blankRequired'   => 0,
        'coordPairErrors' => 0
    ],
    'contacts' => [
        'total'         => 0,
        'valid'         => 0,
        'invalid'       => 0,
        'inactive'      => 0,
        'blankRequired' => 0
    ],
    'orders' => [
        'status' => 'Under Construction'
    ],
    'applications' => [
        'status' => 'Under Construction'
    ]
];

try {

    // #region Actions Health Audit

    // Query defined Action Types catalog size independently
    $actionTypesSql = "
        SELECT COUNT(*) AS definedTypes
        FROM tblActionTypes
    ";

    $actionTypesStmt = $pdo->prepare($actionTypesSql);
    $actionTypesStmt->execute();
    $actionTypesRow = $actionTypesStmt->fetch(PDO::FETCH_ASSOC);

    $dbHealth['actions']['definedTypes'] = (int) ($actionTypesRow['definedTypes'] ?? 0);

    // Audit total actions, unknown types, and coordinate pair completeness
    $actionsSql = "
        SELECT
            COUNT(*) AS totalActions,
            SUM(CASE WHEN at.actionTypeId IS NULL THEN 1 ELSE 0 END) AS unknownTypes,
            SUM(
                CASE 
                    WHEN (a.latitude IS NULL AND a.longitude IS NOT NULL)
                      OR (a.latitude IS NOT NULL AND a.longitude IS NULL)
                    THEN 1 
                    ELSE 0 
                END
            ) AS coordPairErrors
        FROM tblActions a
        LEFT JOIN tblActionTypes at ON a.actionTypeId = at.actionTypeId
    ";

    $actionsStmt = $pdo->prepare($actionsSql);
    $actionsStmt->execute();
    $actionsRow = $actionsStmt->fetch(PDO::FETCH_ASSOC);

    if ($actionsRow) {
        $dbHealth['actions']['total']           = (int) ($actionsRow['totalActions'] ?? 0);
        $dbHealth['actions']['unknownTypes']    = (int) ($actionsRow['unknownTypes'] ?? 0);
        $dbHealth['actions']['coordPairErrors'] = (int) ($actionsRow['coordPairErrors'] ?? 0);
    }

    // #endregion

    // #region Entity Health Audit

    // Audit total entities, validity states, and classifications
    $entitySql = "
        SELECT
            COUNT(*) AS totalEntities,
            SUM(CASE WHEN entityIsNotValid = 0 THEN 1 ELSE 0 END) AS validEntities,
            SUM(CASE WHEN entityIsNotValid <> 0 THEN 1 ELSE 0 END) AS invalidEntities,
            SUM(CASE WHEN entityType = 'company' AND entityIsNotValid = 0 THEN 1 ELSE 0 END) AS companyCount,
            SUM(CASE WHEN entityType = 'customer' AND entityIsNotValid = 0 THEN 1 ELSE 0 END) AS customerCount,
            SUM(CASE WHEN entityType = 'vendor' AND entityIsNotValid = 0 THEN 1 ELSE 0 END) AS vendorCount,
            SUM(CASE WHEN entityType = 'jurisdiction' AND entityIsNotValid = 0 THEN 1 ELSE 0 END) AS jurisdictionCount
        FROM tblEntities
    ";

    $entityStmt = $pdo->prepare($entitySql);
    $entityStmt->execute();
    $entityRow = $entityStmt->fetch(PDO::FETCH_ASSOC);

    if ($entityRow) {
        $dbHealth['entities']['total']         = (int) ($entityRow['totalEntities'] ?? 0);
        $dbHealth['entities']['valid']         = (int) ($entityRow['validEntities'] ?? 0);
        $dbHealth['entities']['invalid']       = (int) ($entityRow['invalidEntities'] ?? 0);
        $dbHealth['entities']['companies']     = (int) ($entityRow['companyCount'] ?? 0);
        $dbHealth['entities']['customers']     = (int) ($entityRow['customerCount'] ?? 0);
        $dbHealth['entities']['vendors']       = (int) ($entityRow['vendorCount'] ?? 0);
        $dbHealth['entities']['jurisdictions'] = (int) ($entityRow['jurisdictionCount'] ?? 0);
    }

    // #endregion

    // #region Location Health Audit

    // Audit total locations, validity, required text fields (locationName, locationPlaceId), and coordinate pairs
    $locationSql = "
        SELECT
            COUNT(*) AS totalLocations,
            SUM(CASE WHEN locationIsNotValid = 0 THEN 1 ELSE 0 END) AS validLocations,
            SUM(CASE WHEN locationIsNotValid <> 0 THEN 1 ELSE 0 END) AS invalidLocations,
            SUM(
                CASE 
                    WHEN locationName IS NULL OR TRIM(locationName) = ''
                      OR locationPlaceId IS NULL OR TRIM(locationPlaceId) = ''
                    THEN 1 
                    ELSE 0 
                END
            ) AS blankRequired,
            SUM(
                CASE 
                    WHEN (locationLatitude IS NULL AND locationLongitude IS NOT NULL)
                      OR (locationLatitude IS NOT NULL AND locationLongitude IS NULL)
                    THEN 1 
                    ELSE 0 
                END
            ) AS coordPairErrors
        FROM tblLocations
    ";

    $locationStmt = $pdo->prepare($locationSql);
    $locationStmt->execute();
    $locationRow = $locationStmt->fetch(PDO::FETCH_ASSOC);

    if ($locationRow) {
        $dbHealth['locations']['total']           = (int) ($locationRow['totalLocations'] ?? 0);
        $dbHealth['locations']['valid']           = (int) ($locationRow['validLocations'] ?? 0);
        $dbHealth['locations']['invalid']         = (int) ($locationRow['invalidLocations'] ?? 0);
        $dbHealth['locations']['blankRequired']   = (int) ($locationRow['blankRequired'] ?? 0);
        $dbHealth['locations']['coordPairErrors'] = (int) ($locationRow['coordPairErrors'] ?? 0);
    }

    // #endregion

    // #region Contact Health Audit

    // Audit total contacts, validity, active/inactive state (isActive), and required text fields
    $contactSql = "
        SELECT
            COUNT(*) AS totalContacts,
            SUM(CASE WHEN contactIsNotValid = 0 THEN 1 ELSE 0 END) AS validContacts,
            SUM(CASE WHEN contactIsNotValid <> 0 THEN 1 ELSE 0 END) AS invalidContacts,
            SUM(CASE WHEN isActive = 0 THEN 1 ELSE 0 END) AS inactiveContacts,
            SUM(
                CASE 
                    WHEN contactFirstName IS NULL OR TRIM(contactFirstName) = ''
                      OR contactLastName IS NULL OR TRIM(contactLastName) = ''
                      OR contactPrimaryPhone IS NULL OR TRIM(contactPrimaryPhone) = ''
                      OR contactEmail IS NULL OR TRIM(contactEmail) = ''
                    THEN 1 
                    ELSE 0 
                END
            ) AS blankRequired
        FROM tblContacts
    ";

    $contactStmt = $pdo->prepare($contactSql);
    $contactStmt->execute();
    $contactRow = $contactStmt->fetch(PDO::FETCH_ASSOC);

    if ($contactRow) {
        $dbHealth['contacts']['total']         = (int) ($contactRow['totalContacts'] ?? 0);
        $dbHealth['contacts']['valid']         = (int) ($contactRow['validContacts'] ?? 0);
        $dbHealth['contacts']['invalid']       = (int) ($contactRow['invalidContacts'] ?? 0);
        $dbHealth['contacts']['inactive']      = (int) ($contactRow['inactiveContacts'] ?? 0);
        $dbHealth['contacts']['blankRequired'] = (int) ($contactRow['blankRequired'] ?? 0);
    }

    // #endregion

    // #region Orders Health

    // Reserved for future operational orders audit checks

    // #endregion

    // #region Applications Health

    // Reserved for future applications audit checks

    // #endregion

} catch (Throwable $throwable) {

    fail(
        'Unable to complete Database Health audit: ' .
        $throwable->getMessage()
    );
}

// #endregion

// #region Artifact Repository Health

// Resolve canonical Artifact repository
$artifactsPath = $rootDir . '/artifacts';

// Initialize Artifact repository health
$artifactRepositoryAvailable = false;
$artifactTotalFiles = 0;
$artifactRecFiles = 0;
$artifactTmpFiles = 0;
$artifactSysFiles = 0;
$artifactOtherFiles = 0;
$artifactTotalBytes = 0;

$artifactOldestFilename = null;
$artifactOldestModifiedUnix = null;

$artifactNewestFilename = null;
$artifactNewestModifiedUnix = null;

// Validate canonical Artifact repository
if (
    is_dir($artifactsPath) &&
    is_readable($artifactsPath)
) {

    $artifactRepositoryAvailable = true;

    try {

        // Initialize recursive Artifact iterator
        $artifactIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $artifactsPath,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($artifactIterator as $artifactFile) {

            // Ignore directories
            if (!$artifactFile->isFile()) {
                continue;
            }

            // Resolve Artifact file details
            $artifactFilename = $artifactFile->getFilename();
            $artifactModifiedUnix = $artifactFile->getMTime();
            $artifactFileSize = $artifactFile->getSize();

            // Increment repository totals
            $artifactTotalFiles++;
            $artifactTotalBytes += $artifactFileSize;

            // Classify Artifact lifecycle
            if (stripos($artifactFilename, 'REC-') === 0) {

                $artifactRecFiles++;

            } elseif (
                stripos($artifactFilename, 'TMP-') === 0 ||
                stripos($artifactFilename, 'tmp-') === 0
            ) {

                $artifactTmpFiles++;

            } elseif (stripos($artifactFilename, 'SYS-') === 0) {

                $artifactSysFiles++;

            } else {

                $artifactOtherFiles++;
            }

            // Resolve oldest modified Artifact
            if (
                $artifactOldestModifiedUnix === null ||
                $artifactModifiedUnix < $artifactOldestModifiedUnix
            ) {
                $artifactOldestModifiedUnix = $artifactModifiedUnix;
                $artifactOldestFilename = $artifactFilename;
            }

            // Resolve newest modified Artifact
            if (
                $artifactNewestModifiedUnix === null ||
                $artifactModifiedUnix > $artifactNewestModifiedUnix
            ) {
                $artifactNewestModifiedUnix = $artifactModifiedUnix;
                $artifactNewestFilename = $artifactFilename;
            }
        }

    } catch (Throwable $throwable) {

        $artifactRepositoryAvailable = false;

        error_log(
            'SENTINEL ARTIFACT HEALTH ERROR: ' .
            $throwable->getMessage()
        );
    }
}

// Determine Artifact repository attention state
$artifactNeedsAttention =
    !$artifactRepositoryAvailable ||
    $artifactTmpFiles > 0 ||
    $artifactOtherFiles > 0;

// Resolve human-readable repository size
$artifactSizeUnits = [
    'B',
    'KB',
    'MB',
    'GB',
    'TB'
];

$artifactSizeValue = (float) $artifactTotalBytes;
$artifactSizeUnitIndex = 0;

while (
    $artifactSizeValue >= 1024 &&
    $artifactSizeUnitIndex < count($artifactSizeUnits) - 1
) {
    $artifactSizeValue /= 1024;
    $artifactSizeUnitIndex++;
}

$artifactTotalSizeDisplay =
    number_format(
        $artifactSizeValue,
        $artifactSizeUnitIndex === 0 ? 0 : 2
    ) .
    ' ' .
    $artifactSizeUnits[$artifactSizeUnitIndex];

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
            border: 0;
            vertical-align: middle;
        }

        .header-logo-cell {
            width: 1%;
            padding: 0 12px 8px 0;
            white-space: nowrap;
            text-align: left;
            vertical-align: middle;
        }

        .header-logo {
            display: block;
            width: auto;
            height: 74px;
            margin: 0;
            border: 0;
        }

        .logo-fallback {
            color: #14377c;
            font-size: 22px;
            font-weight: bold;
        }

        .header-details-cell {
            width: auto;
            padding: 0 0 8px 0;
            text-align: left;
            vertical-align: middle;
        }

        .header-report-details {
            width: 100%;
            margin: 0;
            padding: 0;
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
            margin: 2px 0 0;
            color: #333;
            font-size: 17px;
            font-weight: bold;
            line-height: 1.05;
        }

        .header-report-date {
            margin: 1px 0 0;
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

            .header-report-date {
                font-size: 11px;
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

            <td class="header-logo-cell">

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

            <td class="header-details-cell">

                <div class="header-report-details">

                    <div class="header-title">
                        Skyesoft Sentinel Daily Report
                    </div>

                    <div class="header-subtitle-main">
                        System Governance &amp; Health
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
         Database Health
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Database Health') ?>

        <!-- #region Actions -->

        <table class="data-table">

            <tr>
                <th colspan="2">
                    Actions
                </th>
            </tr>

            <tr>
                <th>Total Records</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['actions']['total']
                        ) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <th>Defined Action Types</th>

                <td>
                    <?= number_format(
                        $dbHealth['actions']['definedTypes']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Unknown Action Types</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['actions']['unknownTypes']
                        ) ?>
                    </strong>

                    <?php if (
                        $dbHealth['actions']['unknownTypes'] > 0
                    ): ?>

                        <span class="status status--review">
                            Needs Attention
                        </span>

                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Coordinate Pair Errors</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['actions']['coordPairErrors']
                        ) ?>
                    </strong>

                    <?php if (
                        $dbHealth['actions']['coordPairErrors'] > 0
                    ): ?>

                        <span class="status status--review">
                            Needs Attention
                        </span>

                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Session Sequence Audit</th>

                <td>
                    <span class="status status--review">
                        Pending
                    </span>
                </td>
            </tr>

            <tr>
                <th>Required Data Audit</th>

                <td>
                    <span class="status status--review">
                        Pending
                    </span>
                </td>
            </tr>

        </table>

        <!-- #endregion -->

        <!-- #region Entities -->

        <table
            class="data-table"
            style="margin-top: 8px;"
        >

            <tr>
                <th colspan="2">
                    Entities
                </th>
            </tr>

            <tr>
                <th>Total Records</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['entities']['total']
                        ) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <th>Valid Records</th>

                <td>
                    <?= number_format(
                        $dbHealth['entities']['valid']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Invalid Records</th>

                <td>
                    <?= number_format(
                        $dbHealth['entities']['invalid']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Companies</th>

                <td>
                    <?= number_format(
                        $dbHealth['entities']['companies']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Customers</th>

                <td>
                    <?= number_format(
                        $dbHealth['entities']['customers']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Vendors</th>

                <td>
                    <?= number_format(
                        $dbHealth['entities']['vendors']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Jurisdictions</th>

                <td>
                    <?= number_format(
                        $dbHealth['entities']['jurisdictions']
                    ) ?>
                </td>
            </tr>

        </table>

        <!-- #endregion -->

        <!-- #region Locations -->

        <table
            class="data-table"
            style="margin-top: 8px;"
        >

            <tr>
                <th colspan="2">
                    Locations
                </th>
            </tr>

            <tr>
                <th>Total Records</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['locations']['total']
                        ) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <th>Valid Records</th>

                <td>
                    <?= number_format(
                        $dbHealth['locations']['valid']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Invalid Records</th>

                <td>
                    <?= number_format(
                        $dbHealth['locations']['invalid']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Required Data Missing</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['locations']['blankRequired']
                        ) ?>
                    </strong>

                    <?php if (
                        $dbHealth['locations']['blankRequired'] > 0
                    ): ?>

                        <span class="status status--review">
                            Needs Attention
                        </span>

                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Coordinate Pair Errors</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['locations']['coordPairErrors']
                        ) ?>
                    </strong>

                    <?php if (
                        $dbHealth['locations']['coordPairErrors'] > 0
                    ): ?>

                        <span class="status status--review">
                            Needs Attention
                        </span>

                    <?php endif; ?>
                </td>
            </tr>

        </table>

        <!-- #endregion -->

        <!-- #region Contacts -->

        <table
            class="data-table"
            style="margin-top: 8px;"
        >

            <tr>
                <th colspan="2">
                    Contacts
                </th>
            </tr>

            <tr>
                <th>Total Records</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['contacts']['total']
                        ) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <th>Valid Records</th>

                <td>
                    <?= number_format(
                        $dbHealth['contacts']['valid']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Invalid Records</th>

                <td>
                    <?= number_format(
                        $dbHealth['contacts']['invalid']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Inactive Records</th>

                <td>
                    <?= number_format(
                        $dbHealth['contacts']['inactive']
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Required Data Missing</th>

                <td>
                    <strong>
                        <?= number_format(
                            $dbHealth['contacts']['blankRequired']
                        ) ?>
                    </strong>

                    <?php if (
                        $dbHealth['contacts']['blankRequired'] > 0
                    ): ?>

                        <span class="status status--review">
                            Needs Attention
                        </span>

                    <?php endif; ?>
                </td>
            </tr>

        </table>

        <!-- #endregion -->

        <!-- #region Orders -->

        <table
            class="data-table"
            style="margin-top: 8px;"
        >

            <tr>
                <th>Orders</th>

                <td>
                    <span class="status status--review">
                        <?= escapeReportValue(
                            $dbHealth['orders']['status']
                        ) ?>
                    </span>
                </td>
            </tr>

        </table>

        <!-- #endregion -->

        <!-- #region Applications -->

        <table
            class="data-table"
            style="margin-top: 8px;"
        >

            <tr>
                <th>Applications</th>

                <td>
                    <span class="status status--review">
                        <?= escapeReportValue(
                            $dbHealth['applications']['status']
                        ) ?>
                    </span>
                </td>
            </tr>

        </table>

        <!-- #endregion -->

    </div>

    <!-- =============================================================
         Artifact Repository Health
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Artifact Repository Health') ?>

        <table class="data-table">

            <tr>
                <th>Repository Status</th>

                <td>
                    <span
                        class="status <?= $artifactNeedsAttention
                            ? 'status--review'
                            : 'status--resolved' ?>"
                    >
                        <?= $artifactRepositoryAvailable
                            ? (
                                $artifactNeedsAttention
                                    ? 'Needs Attention'
                                    : 'Healthy'
                            )
                            : 'Unavailable' ?>
                    </span>
                </td>
            </tr>

            <tr>
                <th>Total Files</th>

                <td>
                    <strong>
                        <?= number_format($artifactTotalFiles) ?>
                    </strong>
                </td>
            </tr>

            <tr>
                <th>Permanent Records (REC)</th>

                <td>
                    <?= number_format($artifactRecFiles) ?>
                </td>
            </tr>

            <tr>
                <th>Temporary Files (TMP)</th>

                <td>
                    <strong>
                        <?= number_format($artifactTmpFiles) ?>
                    </strong>

                    <?php if ($artifactTmpFiles > 0): ?>

                        <span
                            style="
                                display: inline-block;
                                margin-left: 4px;
                                padding: 0 4px;
                                border: 1px solid #e8c46e;
                                background: #fff5dc;
                                color: #8a5a00;
                                font-size: 6.5pt;
                                font-weight: bold;
                                line-height: 1;
                                white-space: nowrap;
                                vertical-align: baseline;
                            "
                        >
                            Needs Attention
                        </span>

                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>System Files (SYS)</th>

                <td>
                    <?= number_format($artifactSysFiles) ?>
                </td>
            </tr>

            <tr>
                <th>Other Files</th>

                <td>
                    <strong>
                        <?= number_format($artifactOtherFiles) ?>
                    </strong>

                    <?php if ($artifactOtherFiles > 0): ?>

                        <span
                            style="
                                display: inline-block;
                                margin-left: 4px;
                                padding: 0 4px;
                                border: 1px solid #e8c46e;
                                background: #fff5dc;
                                color: #8a5a00;
                                font-size: 6.5pt;
                                font-weight: bold;
                                line-height: 1;
                                white-space: nowrap;
                                vertical-align: baseline;
                            "
                        >
                            Needs Attention
                        </span>

                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Repository Size</th>

                <td>
                    <?= escapeReportValue(
                        $artifactTotalSizeDisplay
                    ) ?>
                </td>
            </tr>

            <tr>
                <th>Oldest Modified</th>

                <td>
                    <?php if ($artifactOldestFilename !== null): ?>

                        <?= escapeReportValue(
                            $artifactOldestFilename
                        ) ?>

                        &nbsp;&middot;&nbsp;

                        <?= escapeReportValue(
                            formatUnixDate(
                                $artifactOldestModifiedUnix
                            )
                        ) ?>

                    <?php else: ?>

                        None

                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th>Newest Modified</th>

                <td>
                    <?php if ($artifactNewestFilename !== null): ?>

                        <?= escapeReportValue(
                            $artifactNewestFilename
                        ) ?>

                        &nbsp;&middot;&nbsp;

                        <?= escapeReportValue(
                            formatUnixDate(
                                $artifactNewestModifiedUnix
                            )
                        ) ?>

                    <?php else: ?>

                        None

                    <?php endif; ?>
                </td>
            </tr>

        </table>

    </div>

    <!-- =============================================================
         Jurisdiction Currency
         ============================================================= -->

    <div class="section-block">

        <?= buildSectionHeading('Jurisdiction Currency') ?>

        <table class="data-table">

            <tr>
                <th>Currency Status</th>

                <td>
                    Currency Check Pending
                </td>
            </tr>

            <tr>
                <th>Phoenix</th>

                <td>
                    Automated Currency Check Pending
                </td>
            </tr>

            <tr>
                <th>Other Jurisdictions</th>

                <td>
                    Under Construction
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
                execution, version, runtime, database health,
                artifact repository health, and jurisdiction currency
                state as of
                <strong><?= escapeReportValue($reportDate) ?></strong>
                at
                <strong><?= escapeReportValue($reportTime) ?> MST</strong>.
                Runtime information is sourced from the Sentinel runtime
                state, version information is sourced from Skyesoft's
                authoritative version metadata, database health
                information is sourced from the live Skyesoft database,
                and artifact repository health is sourced from the
                canonical server Artifact repository.
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