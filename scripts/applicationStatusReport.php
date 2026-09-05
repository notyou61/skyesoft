<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — applicationStatusReport.php
 *  External Permit Application Status Report
 *  Codex-Governed Module • PHP 8.3
 * ===================================================================== */

// #region SECTION I — Environment & Authentication

date_default_timezone_set('America/Phoenix');

require_once __DIR__ . '/../api/sessionBootstrap.php';
require_once __DIR__ . '/../api/dbConnect.php';
require_once __DIR__ . '/../api/utils/actions.php';
require_once __DIR__ . '/reportFrame.php';

const ACTION_ORIGIN_USER = 1;

function failApplicationStatusReport(
    string $message,
    int $statusCode = 400
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>Application Status Report</title></head><body>';
    echo '<h1>Application Status Report</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

if (!function_exists('getPDO')) {
    failApplicationStatusReport(
        'Database initialization is unavailable.',
        500
    );
}

$db = getPDO();
$contactId = (int)(
    $_SESSION['SKYESOFT_contactId']
    ?? $_SESSION['contactId']
    ?? 0
);

if ($contactId <= 0) {
    failApplicationStatusReport(
        'An authenticated Company Contact is required.',
        401
    );
}

$actorStmt = $db->prepare("
    SELECT
        c.contactId,
        c.contactFirstName,
        c.contactLastName,
        c.contactTitle,
        e.entityName
    FROM tblContacts c
    INNER JOIN tblEntities e
        ON e.entityId = c.contactEntityId
    WHERE c.contactId = :contactId
      AND COALESCE(c.contactIsNotValid, 0) = 0
      AND COALESCE(c.isActive, 1) = 1
      AND COALESCE(e.entityIsNotValid, 0) = 0
      AND LOWER(TRIM(e.entityType)) = 'company'
    LIMIT 1
");
$actorStmt->execute(['contactId' => $contactId]);
$actor = $actorStmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($actor)) {
    failApplicationStatusReport(
        'Authenticated Company Contact was not found.',
        403
    );
}

// #endregion

// #region SECTION II — Report Helpers

function escapeApplicationReportValue(mixed $value): string
{
    return htmlspecialchars(
        trim((string)($value ?? '')),
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatApplicationReportDate(?int $unix): string
{
    if ($unix === null || $unix <= 0) {
        return 'Not Available';
    }

    return date('F j, Y', $unix);
}

function formatApplicationReportDateTime(?int $unix): string
{
    if ($unix === null || $unix <= 0) {
        return 'Not Available';
    }

    return date('F j, Y · g:i A T', $unix);
}

function buildApplicationPermitDurationSummary(
    ?int $receivedUnix,
    ?int $finaledUnix,
    int $reportGeneratedUnix
): string {
    if ($receivedUnix === null || $receivedUnix <= 0) {
        return 'Permit duration is not available because the Received date is not recorded.';
    }

    $timezone = new DateTimeZone('America/Phoenix');

    $receivedDate = (new DateTimeImmutable(
        '@' . $receivedUnix
    ))
        ->setTimezone($timezone)
        ->setTime(0, 0);

    $isFinaled = $finaledUnix !== null &&
        $finaledUnix > 0;

    $endUnix = $isFinaled
        ? $finaledUnix
        : $reportGeneratedUnix;

    $endDate = (new DateTimeImmutable(
        '@' . $endUnix
    ))
        ->setTimezone($timezone)
        ->setTime(0, 0);

    if ($endDate < $receivedDate) {
        return $isFinaled
            ? 'Permit duration cannot be calculated because the Finaled date precedes the Received date.'
            : 'Permit duration cannot be calculated because the Received date is later than the report date.';
    }

    $calendarDays = (int)$receivedDate
        ->diff($endDate)
        ->days;

    if ($isFinaled) {
        return sprintf(
            'This permit was completed in %d calendar day%s, from receipt on %s through finalization on %s.',
            $calendarDays,
            $calendarDays === 1 ? '' : 's',
            $receivedDate->format('F j, Y'),
            $endDate->format('F j, Y')
        );
    }

    return sprintf(
        'This permit Application has been open for %d calendar day%s since it was received on %s.',
        $calendarDays,
        $calendarDays === 1 ? '' : 's',
        $receivedDate->format('F j, Y')
    );
}


function formatApplicationReportValue(mixed $value): string
{
    $resolved = trim((string)($value ?? ''));

    return $resolved !== '' ? $resolved : 'Not Available';
}

function formatApplicationReportMoney(
    float|int|string|null $amount
): string {
    return '$' . number_format(
        round((float)$amount, 2),
        2,
        '.',
        ','
    );
}

function renderApplicationReportSectionHeading(
    string $title,
    string $iconFile,
    string|false $rootDir
): string {
    // Restrict icon resolution to one safe filename
    $resolvedIconFile = basename($iconFile);
    $iconPath = $rootDir !== false
        ? $rootDir .
            '/assets/images/icons/' .
            $resolvedIconFile
        : '';

    $iconHtml = '';

    // Render local report icon when available
    if ($iconPath !== '' && is_file($iconPath)) {
        $iconSource = 'file://' . $iconPath;

        $iconHtml = sprintf(
            '<img class="section-icon" src="%s" alt="">',
            htmlspecialchars(
                $iconSource,
                ENT_QUOTES,
                'UTF-8'
            )
        );
    }

    return sprintf(
        '<div class="section-heading">%s<span>%s</span></div>',
        $iconHtml,
        escapeApplicationReportValue($title)
    );
}

function buildApplicationStatusSummary(string $stageName): string
{
    switch (strtolower(trim($stageName))) {
        case 'pre-submittal':
            return 'The application package is being prepared and checked before submission to the jurisdiction.';

        case 'submitted':
            return 'The application has been submitted and is awaiting jurisdiction intake or acceptance.';

        case 'jurisdiction review':
            return 'The application is in jurisdiction review. Additional review cycles or corrections may be required before approval.';

        case 'approval / issuance':
            return 'The application is in approval and permit issuance processing with the jurisdiction.';

        case 'inspection':
            return 'The permit is in the jurisdiction inspection phase.';

        case 'finaled':
            return 'The permit lifecycle has been authoritatively finalized.';

        default:
            return 'The application remains active in the permit process. Current status information is shown below.';
    }
}

// #endregion

// #region SECTION III — Authoritative Application Data

$applicationId = (int)(
    $_POST['applicationID']
    ?? $_GET['applicationID']
    ?? 0
);

if ($applicationId <= 0) {
    failApplicationStatusReport('A valid Application is required.');
}

$applicationStmt = $db->prepare("
    SELECT
        a.applicationID,
        a.applicationTitle,
        a.applicationJurisdiction,
        a.applicationNumber,
        a.applicationPermitNumber,
        a.applicationScope,
        a.applicationSubmittedUnix,
        a.applicationApprovedUnix,
        a.applicationIssuedUnix,
        a.applicationFinaledUnix,
        a.applicationCreatedUnix,
        a.applicationUpdatedUnix,
        o.orderID,
        o.orderChristyNumber,
        e.entityName,
        l.locationName,
        l.locationAddress,
        l.locationAddressSuite,
        l.locationCity,
        l.locationState,
        l.locationZip,
        s.applicationStageName,
        st.applicationStatusName
    FROM tblApplications a
    INNER JOIN tblOrders o
        ON o.orderID = a.applicationOrderID
    INNER JOIN tblEntities e
        ON e.entityId = a.applicationEntityID
    INNER JOIN tblLocations l
        ON l.locationId = a.applicationLocationID
    INNER JOIN tblApplicationStages s
        ON s.applicationStageID = a.applicationStageID
    INNER JOIN tblApplicationStatuses st
        ON st.applicationStageID = a.applicationStageID
       AND st.applicationStatusID = a.applicationStatusID
    WHERE a.applicationID = :applicationId
      AND a.applicationIsNotValid = 0
    LIMIT 1
");
$applicationStmt->execute([
    'applicationId' => $applicationId
]);
$application = $applicationStmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($application)) {
    failApplicationStatusReport('Application was not found.', 404);
}

// Load active authoritative Special Requirements
$requirementStmt = $db->prepare("
    SELECT
        r.applicationSpecialRequirementID,
        r.applicationSpecialRequirementDescription,
        r.applicationSpecialRequirementResponsibleParty,
        r.applicationSpecialRequirementRequiredUnix,
        r.applicationSpecialRequirementDueUnix,
        s.applicationSpecialRequirementStatusName
    FROM tblApplicationSpecialRequirements r
    INNER JOIN tblApplicationSpecialRequirementStatuses s
        ON s.applicationSpecialRequirementStatusID =
           r.applicationSpecialRequirementStatusID
    WHERE r.applicationID = :applicationId
      AND r.applicationSpecialRequirementIsNotValid = 0
      AND s.applicationSpecialRequirementStatusIsClosed = 0
    ORDER BY
        r.applicationSpecialRequirementDueUnix IS NULL ASC,
        r.applicationSpecialRequirementDueUnix ASC,
        r.applicationSpecialRequirementID ASC
");

$requirementStmt->execute([
    'applicationId' => $applicationId
]);

$applicationSpecialRequirements = $requirementStmt->fetchAll(
    PDO::FETCH_ASSOC
);

// Normalize authoritative Special Requirement values
foreach ($applicationSpecialRequirements as &$requirement) {
    $requirement['applicationSpecialRequirementID'] =
        (int)$requirement['applicationSpecialRequirementID'];

    $requirement['applicationSpecialRequirementRequiredUnix'] =
        $requirement[
            'applicationSpecialRequirementRequiredUnix'
        ] !== null
            ? (int)$requirement[
                'applicationSpecialRequirementRequiredUnix'
            ]
            : null;

    $requirement['applicationSpecialRequirementDueUnix'] =
        $requirement[
            'applicationSpecialRequirementDueUnix'
        ] !== null
            ? (int)$requirement[
                'applicationSpecialRequirementDueUnix'
            ]
            : null;
}
unset($requirement);

// Load authoritative Application Fees
$feeStmt = $db->prepare("
    SELECT
        f.feeID,
        f.feeCategory,
        f.feeAmount,
        f.feeNote,
        f.feeAssessedUnix,
        f.feePaidUnix,
        f.feeVoidedUnix,
        f.feeVoidReason,
        f.feeCreatedUnix
    FROM tblApplicationFees f
    WHERE f.applicationID = :applicationId
    ORDER BY
        COALESCE(
            f.feeAssessedUnix,
            f.feeCreatedUnix
        ) ASC,
        f.feeID ASC
");

$feeStmt->execute([
    'applicationId' => $applicationId
]);

$applicationFees = $feeStmt->fetchAll(
    PDO::FETCH_ASSOC
);

$totalAssessed = 0.00;
$totalPaid = 0.00;
$totalOutstanding = 0.00;
$totalVoided = 0.00;

// Normalize Fees and calculate authoritative totals
foreach ($applicationFees as &$fee) {
    $fee['feeID'] = (int)$fee['feeID'];
    $fee['feeAmount'] = round(
        (float)$fee['feeAmount'],
        2
    );

    $fee['feeAssessedUnix'] =
        $fee['feeAssessedUnix'] !== null
            ? (int)$fee['feeAssessedUnix']
            : null;

    $fee['feePaidUnix'] =
        $fee['feePaidUnix'] !== null
            ? (int)$fee['feePaidUnix']
            : null;

    $fee['feeVoidedUnix'] =
        $fee['feeVoidedUnix'] !== null
            ? (int)$fee['feeVoidedUnix']
            : null;

    $fee['feeCreatedUnix'] =
        (int)$fee['feeCreatedUnix'];

    // Exclude voided Fees from active totals
    if ($fee['feeVoidedUnix'] !== null) {
        $totalVoided += $fee['feeAmount'];
        continue;
    }

    $totalAssessed += $fee['feeAmount'];

    if ($fee['feePaidUnix'] !== null) {
        $totalPaid += $fee['feeAmount'];
    }
}
unset($fee);

$totalAssessed = round($totalAssessed, 2);
$totalPaid = round($totalPaid, 2);
$totalOutstanding = round(
    $totalAssessed - $totalPaid,
    2
);
$totalVoided = round($totalVoided, 2);

$addressParts = array_filter([
    trim((string)$application['locationAddress']),
    trim((string)$application['locationAddressSuite']),
    trim((string)$application['locationCity']),
    trim((string)$application['locationState']),
    trim((string)$application['locationZip'])
], static function ($value): bool {
    return $value !== '';
});
$locationAddress = implode(', ', $addressParts);
$reportGeneratedUnix = time();
$permitDurationSummary =
    buildApplicationPermitDurationSummary(
        $application['applicationCreatedUnix'] !== null
            ? (int)$application['applicationCreatedUnix']
            : null,
        $application['applicationFinaledUnix'] !== null
            ? (int)$application['applicationFinaledUnix']
            : null,
        $reportGeneratedUnix
    );
$statusSummary = buildApplicationStatusSummary(
    (string)$application['applicationStageName']
);

// Resolve cached Application Status summary
$storedSummaries = is_array(
    $_SESSION['applicationStatusReportSummaries']
    ?? null
)
    ? $_SESSION['applicationStatusReportSummaries']
    : [];

$storedSummary = is_array(
    $storedSummaries[(string)$applicationId]
    ?? null
)
    ? $storedSummaries[(string)$applicationId]
    : null;

$storedSummaryAge = is_array($storedSummary)
    ? $reportGeneratedUnix -
        (int)($storedSummary['generatedUnix'] ?? 0)
    : PHP_INT_MAX;

$currentUpdatedUnix = $application[
    'applicationUpdatedUnix'
] !== null
    ? (int)$application['applicationUpdatedUnix']
    : 0;

$storedUpdatedUnix = is_array($storedSummary)
    ? (int)($storedSummary[
        'applicationUpdatedUnix'
    ] ?? 0)
    : -1;

$storedSummaryMatches =
    is_array($storedSummary) &&
    (int)($storedSummary['applicationID'] ?? 0) ===
        $applicationId &&
    $storedUpdatedUnix === $currentUpdatedUnix &&
    $storedSummaryAge >= 0 &&
    $storedSummaryAge <= 900 &&
    trim((string)(
        $storedSummary['summaryNarrative']
        ?? ''
    )) !== '';

$reportSummary = $storedSummaryMatches
    ? trim((string)$storedSummary[
        'summaryNarrative'
    ])
    : $statusSummary;

$reportSummarySource = $storedSummaryMatches
    ? trim((string)(
        $storedSummary['summarySource']
        ?? 'askOpenAI.php'
    ))
    : 'deterministic_fallback';

// #endregion

// #region SECTION IV — Explicit Report Action

$actionTypeStmt = $db->prepare("
    SELECT actionTypeId
    FROM tblActionTypes
    WHERE actionName = 'report.document.read'
      AND crud_class = 'read'
    LIMIT 1
");
$actionTypeStmt->execute();
$actionTypeId = (int)($actionTypeStmt->fetchColumn() ?: 0);

if ($actionTypeId <= 0) {
    failApplicationStatusReport(
        'Report Action Type is not configured.',
        500
    );
}

$latitude = is_numeric($_POST['latitude'] ?? null)
    ? (float)$_POST['latitude']
    : null;
$longitude = is_numeric($_POST['longitude'] ?? null)
    ? (float)$_POST['longitude']
    : null;
$activitySessionId = trim((string)(
    $_SESSION['activitySessionId']
    ?? session_id()
));
$activitySessionId = $activitySessionId !== ''
    ? $activitySessionId
    : null;

$recordReportAction = static function () use (
    $db,
    $actionTypeId,
    $contactId,
    $activitySessionId,
    $applicationId,
    $application,
    $latitude,
    $longitude
): int {
    return insertActionPrompt([
        'actionTypeId' => $actionTypeId,
        'contactId' => $contactId,
        'origin' => ACTION_ORIGIN_USER,
        'activitySessionId' => $activitySessionId,
        'promptText' => 'Generate Application Status PDF',
        'responseText' => sprintf(
            'Generated external status PDF for Application #%d — %s.',
            $applicationId,
            (string)$application['applicationTitle']
        ),
        'intent' => 'report.document.read',
        'intentConfidence' => 1.00,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'actionPayloadData' => [
            'operation' => 'application.status_report',
            'applicationID' => $applicationId,
            'audience' => 'external',
            'outputFormat' => 'pdf',
            'internalNotesIncluded' => false
        ],
        'actionResponseData' => [
            'success' => true,
            'applicationID' => $applicationId,
            'reportType' => 'application_status',
            'audience' => 'external',
            'outputFormat' => 'pdf'
        ]
    ], $db);
};

// #endregion

// #region SECTION V — Report Rendering

$rootDir = realpath(__DIR__ . '/../');
$logoPath = $rootDir !== false
    ? $rootDir . '/assets/images/christyLogo.png'
    : '';
$logoAvailable = $logoPath !== '' && is_file($logoPath);
$logoSource = $logoAvailable
    ? 'file://' . $logoPath
    : '';
$preparedBy = trim(
    (string)$actor['contactFirstName'] . ' ' .
    (string)$actor['contactLastName']
);
$reportSubject = sprintf(
    '%s (%s)',
    formatApplicationReportValue($application['locationName']),
    formatApplicationReportValue($application['orderChristyNumber'])
);
$reportLine = 'Report Date: ' . formatApplicationReportDateTime(
    $reportGeneratedUnix
);
$reportHeaderHtml = renderSkyesoftReportHeader([
    'title' => 'Permit Application Status Report',
    'subtitle' => $reportSubject,
    'reportLine' => $reportLine,
    'logoSource' => $logoSource,
    'logoAvailable' => $logoAvailable
]);

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        Application #<?= (int)$application['applicationID'] ?> Status Report
    </title>
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            color: #222;
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.25;
        }

        .report {
            width: 100%;
            margin: 0;
        }

        /* Keep each report section together */
        .section {
            margin-top: 8px;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .section-heading {
            margin-bottom: 3px;
            padding-bottom: 2px;
            color: #14377c;
            font-size: 14px;
            font-weight: bold;
            line-height: 16px;
            border-bottom: 2px solid #14377c;
        }

        .section-heading span {
            display: inline-block;
            vertical-align: middle;
        }

        .section-icon {
            display: inline-block;
            width: 15px;
            height: 15px;
            margin-right: 5px;
            vertical-align: middle;
            object-fit: contain;
        }

        /* Prevent internal content from splitting */
        .data-table,
        .fee-summary-table,
        .fee-table,
        .scope-box,
        .summary-box {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .data-table tr,
        .fee-summary-table tr,
        .fee-table tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .data-table,
        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 3px 5px;
            border: 1px solid #ccc;
            text-align: left;
            vertical-align: top;
        }

        .data-table th {
            width: 40%;
            color: #333;
            font-weight: bold;
            white-space: nowrap;
            background: #f8f9fa;
        }

        .data-table td {
            width: 60%;
            color: #111;
            background: #fff;
        }

        .scope-box,
        .summary-box {
            padding: 6px 8px;
            white-space: normal;
            background: #fff;
            border: 1px solid #ccc;
        }

        .summary-box {
            background: #f0f4f9;
            border-color: #b8cbe5;
            border-left: 4px solid #14377c;
        }

        .summary-title {
            margin-bottom: 2px;
            color: #14377c;
            font-size: 12px;
            font-weight: bold;
        }

        .summary-body {
            color: #333;
            font-size: 11px;
            line-height: 1.35;
        }

        .fee-summary-table {
            width: 100%;
            margin-bottom: 4px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .fee-summary-table td {
            width: 25%;
            padding: 5px 6px;
            border: 1px solid #ccc;
            background: #f8f9fa;
        }

        .fee-summary-label {
            display: block;
            color: #666;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .fee-summary-value {
            display: block;
            margin-top: 1px;
            color: #111;
            font-size: 11px;
            font-weight: bold;
        }

        .requirement-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .requirement-table th,
        .requirement-table td {
            padding: 3px 4px;
            border: 1px solid #ccc;
            text-align: left;
            vertical-align: top;
        }

        .requirement-table th {
            color: #333;
            font-size: 9px;
            background: #f8f9fa;
        }

        .requirement-table td {
            color: #111;
            font-size: 10px;
            background: #fff;
        }

        .requirement-status {
            color: #b45309;
            font-weight: bold;
        }

        .requirement-empty {
            padding: 6px 8px;
            color: #666;
            border: 1px solid #ccc;
            background: #f8f9fa;
        }

        .fee-summary-paid {
            color: #18743a;
        }

        .fee-summary-outstanding {
            color: #b45309;
        }

        .fee-summary-voided {
            color: #b91c1c;
        }

        .fee-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .fee-table th,
        .fee-table td {
            padding: 3px 4px;
            border: 1px solid #ccc;
            vertical-align: top;
        }

        .fee-table th {
            color: #333;
            font-size: 9px;
            text-align: left;
            background: #f8f9fa;
        }

        .fee-table td {
            color: #111;
            font-size: 10px;
            background: #fff;
        }

        .fee-amount {
            text-align: right;
            white-space: nowrap;
        }

        .fee-status-paid {
            color: #18743a;
            font-weight: bold;
        }

        .fee-status-outstanding {
            color: #b45309;
            font-weight: bold;
        }

        .fee-status-voided {
            color: #b91c1c;
            font-weight: bold;
        }

        .fee-empty {
            padding: 6px 8px;
            color: #666;
            border: 1px solid #ccc;
            background: #f8f9fa;
        }
    </style>
</head>
<body>

<div class="report">
    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Report Summary',
            'memo.png',
            $rootDir
        ) ?>

        <div class="summary-box">
            <div class="summary-body">
                <?= escapeApplicationReportValue(
                    $reportSummary
                ) ?>
            </div>
        </div>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Project Information',
            'property.png',
            $rootDir
        ) ?>
        <table class="data-table">
            <tr>
                <th>Application ID</th>
                <td>#<?= (int)$application['applicationID'] ?></td>
            </tr>
            <tr>
                <th>Christy Signs Work Order</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportValue(
                        $application['orderChristyNumber']
                    )
                ) ?></td>
            </tr>
            <tr>
                <th>Customer</th>
                <td><?= escapeApplicationReportValue(
                    $application['entityName']
                ) ?></td>
            </tr>
            <tr>
                <th>Location</th>
                <td><?= escapeApplicationReportValue(
                    $application['locationName']
                ) ?></td>
            </tr>
            <tr>
                <th>Address</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportValue($locationAddress)
                ) ?></td>
            </tr>
            <tr>
                <th>Jurisdiction</th>
                <td><?= escapeApplicationReportValue(
                    $application['applicationJurisdiction']
                ) ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Permit Identification',
            'temple.png',
            $rootDir
        ) ?>
        <table class="data-table">
            <tr>
                <th>Jurisdiction Application Number</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportValue(
                        $application['applicationNumber']
                    )
                ) ?></td>
            </tr>
            <tr>
                <th>Permit Number</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportValue(
                        $application['applicationPermitNumber']
                    )
                ) ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Application Scope',
            'clipboard.png',
            $rootDir
        ) ?>
        <div class="scope-box"><?= escapeApplicationReportValue(
            formatApplicationReportValue(
                $application['applicationScope']
            )
        ) ?></div>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Application Status',
            'inProgress.png',
            $rootDir
        ) ?>
        <table class="data-table">
            <tr>
                <th>Application Stage</th>
                <td><strong><?= escapeApplicationReportValue(
                    $application['applicationStageName']
                ) ?></strong></td>
            </tr>
            <tr>
                <th>Application Status</th>
                <td><strong><?= escapeApplicationReportValue(
                    $application['applicationStatusName']
                ) ?></strong></td>
            </tr>
            <tr>
                <th>Status Description</th>
                <td><?= escapeApplicationReportValue(
                    $statusSummary
                ) ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Permit Milestones',
            'calendar.png',
            $rootDir
        ) ?>
        <table class="data-table">
            <tr>
                <th>Received</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportDate(
                        $application['applicationCreatedUnix'] !== null
                            ? (int)$application['applicationCreatedUnix']
                            : null
                    )
                ) ?></td>
            </tr>
            <tr>
                <th>Submitted</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportDate(
                        $application['applicationSubmittedUnix'] !== null
                            ? (int)$application['applicationSubmittedUnix']
                            : null
                    )
                ) ?></td>
            </tr>
            <tr>
                <th>Approved</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportDate(
                        $application['applicationApprovedUnix'] !== null
                            ? (int)$application['applicationApprovedUnix']
                            : null
                    )
                ) ?></td>
            </tr>
            <tr>
                <th>Issued</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportDate(
                        $application['applicationIssuedUnix'] !== null
                            ? (int)$application['applicationIssuedUnix']
                            : null
                    )
                ) ?></td>
            </tr>
            <tr>
                <th>Finaled</th>
                <td><?= escapeApplicationReportValue(
                    formatApplicationReportDate(
                        $application['applicationFinaledUnix'] !== null
                            ? (int)$application['applicationFinaledUnix']
                            : null
                    )
                ) ?></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Special Requirements',
            'information.png',
            $rootDir
        ) ?>

        <?php if ($applicationSpecialRequirements !== []): ?>
            <table class="requirement-table">
                <thead>
                    <tr>
                        <th style="width:42%;">Requirement</th>
                        <th style="width:16%;">Status</th>
                        <th style="width:18%;">Responsible Party</th>
                        <th style="width:12%;">Required</th>
                        <th style="width:12%;">Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (
                        $applicationSpecialRequirements as $requirement
                    ): ?>
                        <tr>
                            <td><?= escapeApplicationReportValue(
                                formatApplicationReportValue(
                                    $requirement[
                                        'applicationSpecialRequirementDescription'
                                    ]
                                )
                            ) ?></td>
                            <td class="requirement-status"><?=
                                escapeApplicationReportValue(
                                    formatApplicationReportValue(
                                        $requirement[
                                            'applicationSpecialRequirementStatusName'
                                        ]
                                    )
                                )
                            ?></td>
                            <td><?= escapeApplicationReportValue(
                                formatApplicationReportValue(
                                    $requirement[
                                        'applicationSpecialRequirementResponsibleParty'
                                    ]
                                )
                            ) ?></td>
                            <td><?= escapeApplicationReportValue(
                                formatApplicationReportDate(
                                    $requirement[
                                        'applicationSpecialRequirementRequiredUnix'
                                    ]
                                )
                            ) ?></td>
                            <td><?= escapeApplicationReportValue(
                                formatApplicationReportDate(
                                    $requirement[
                                        'applicationSpecialRequirementDueUnix'
                                    ]
                                )
                            ) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="requirement-empty">
                No active Special Requirements have been recorded.
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Application Fees',
            'receipt.png',
            $rootDir
        ) ?>

        <table class="fee-summary-table">
            <tr>
                <td>
                    <span class="fee-summary-label">Assessed</span>
                    <span class="fee-summary-value">
                        <?= escapeApplicationReportValue(
                            formatApplicationReportMoney(
                                $totalAssessed
                            )
                        ) ?>
                    </span>
                </td>
                <td>
                    <span class="fee-summary-label">Paid</span>
                    <span class="fee-summary-value fee-summary-paid">
                        <?= escapeApplicationReportValue(
                            formatApplicationReportMoney(
                                $totalPaid
                            )
                        ) ?>
                    </span>
                </td>
                <td>
                    <span class="fee-summary-label">Outstanding</span>
                    <span class="fee-summary-value fee-summary-outstanding">
                        <?= escapeApplicationReportValue(
                            formatApplicationReportMoney(
                                $totalOutstanding
                            )
                        ) ?>
                    </span>
                </td>
                <td>
                    <span class="fee-summary-label">Voided</span>
                    <span class="fee-summary-value fee-summary-voided">
                        <?= escapeApplicationReportValue(
                            formatApplicationReportMoney(
                                $totalVoided
                            )
                        ) ?>
                    </span>
                </td>
            </tr>
        </table>

        <?php if ($applicationFees !== []): ?>
            <table class="fee-table">
                <thead>
                    <tr>
                        <th style="width:14%;">Category</th>
                        <th style="width:38%;">Description</th>
                        <th style="width:16%;">Assessed</th>
                        <th style="width:17%;">Status</th>
                        <th style="width:15%; text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applicationFees as $fee): ?>
                        <?php
                        $feeIsVoided =
                            $fee['feeVoidedUnix'] !== null;

                        $feeIsPaid =
                            !$feeIsVoided &&
                            $fee['feePaidUnix'] !== null;

                        if ($feeIsVoided) {
                            $feeStatus = 'Voided';
                            $feeStatusClass =
                                'fee-status-voided';
                            $feeStatusDate =
                                $fee['feeVoidedUnix'];
                        } elseif ($feeIsPaid) {
                            $feeStatus = 'Paid';
                            $feeStatusClass =
                                'fee-status-paid';
                            $feeStatusDate =
                                $fee['feePaidUnix'];
                        } else {
                            $feeStatus = 'Outstanding';
                            $feeStatusClass =
                                'fee-status-outstanding';
                            $feeStatusDate = null;
                        }

                        $feeAssessedUnix =
                            $fee['feeAssessedUnix']
                            ?? $fee['feeCreatedUnix'];
                        ?>
                        <tr>
                            <td>
                                <?= escapeApplicationReportValue(
                                    $fee['feeCategory']
                                ) ?>
                            </td>
                            <td>
                                <?= escapeApplicationReportValue(
                                    formatApplicationReportValue(
                                        $fee['feeNote']
                                    )
                                ) ?>

                                <?php if (
                                    $feeIsVoided &&
                                    trim((string)$fee['feeVoidReason']) !== ''
                                ): ?>
                                    <br>
                                    <span class="fee-status-voided">
                                        Void reason:
                                        <?= escapeApplicationReportValue(
                                            $fee['feeVoidReason']
                                        ) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= escapeApplicationReportValue(
                                    formatApplicationReportDate(
                                        (int)$feeAssessedUnix
                                    )
                                ) ?>
                            </td>
                            <td>
                                <span class="<?= $feeStatusClass ?>">
                                    <?= escapeApplicationReportValue(
                                        $feeStatus
                                    ) ?>
                                </span>

                                <?php if ($feeStatusDate !== null): ?>
                                    <br>
                                    <?= escapeApplicationReportValue(
                                        formatApplicationReportDate(
                                            (int)$feeStatusDate
                                        )
                                    ) ?>
                                <?php endif; ?>
                            </td>
                            <td class="fee-amount">
                                <?= escapeApplicationReportValue(
                                    formatApplicationReportMoney(
                                        $fee['feeAmount']
                                    )
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="fee-empty">
                No Application Fees have been recorded.
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <?= renderApplicationReportSectionHeading(
            'Status Summary',
            'information.png',
            $rootDir
        ) ?>

        <div class="summary-box">
            <div class="summary-title">
                Application Status
            </div>

            <div class="summary-body">
                <?= escapeApplicationReportValue(
                    $permitDurationSummary
                ) ?>

                Christy Signs is coordinating this Application and
                addressing the documented requirements necessary to
                advance it toward permit completion. Reviews, approvals,
                inspections, processing times, additional requirements,
                and final determinations remain under the control of the
                applicable agencies, jurisdictions, utilities, property
                representatives, and other governing authorities. Christy
                Signs is not responsible for their actions, processing
                delays, or changes in requirements.
            </div>
        </div>
    </div>

</div>

</body>
</html>
<?php

$reportHtml = (string)ob_get_clean();

// Resolve Composer autoloader (canonical root first)
$autoloadCandidates = array_filter([
    $rootDir !== false ? $rootDir . '/vendor/autoload.php' : null,
    $rootDir !== false ? $rootDir . '/api/vendor/autoload.php' : null,
    __DIR__ . '/vendor/autoload.php',
    $rootDir !== false
        ? dirname($rootDir) . '/vendor/autoload.php'
        : null
]);
$autoloadPath = null;

foreach ($autoloadCandidates as $autoloadCandidate) {
    if (is_file($autoloadCandidate)) {
        $autoloadPath = $autoloadCandidate;
        break;
    }
}

if ($autoloadPath === null) {
    failApplicationStatusReport(
        'The Skyesoft PDF rendering engine is unavailable.',
        500
    );
}

require_once $autoloadPath;

if (!class_exists('Mpdf\\Mpdf')) {
    failApplicationStatusReport(
        'The Skyesoft PDF rendering engine is not installed.',
        500
    );
}

// Prepare writable mPDF runtime directory
$mpdfTempDir = sys_get_temp_dir() . '/skyesoft-mpdf';

if (!is_dir($mpdfTempDir) && !mkdir($mpdfTempDir, 0775, true)) {
    failApplicationStatusReport(
        'The PDF runtime directory could not be prepared.',
        500
    );
}

try {
    $pdf = new \Mpdf\Mpdf(
        getSkyesoftReportMpdfConfig($mpdfTempDir)
    );

    $pdf->SetTitle(sprintf(
        'Application #%d Status Report',
        $applicationId
    ));
    $pdf->SetAuthor($preparedBy);
    $pdf->SetCreator('Skyesoft');
    $pdf->WriteHTML(
        getSkyesoftReportFrameStyles(),
        \Mpdf\HTMLParserMode::HEADER_CSS
    );
    $pdf->SetHTMLHeader($reportHeaderHtml);
    $pdf->SetHTMLFooter(renderSkyesoftReportFooter([
        'preparedBy' => $preparedBy,
        'reportName' => 'Skyesoft Application Status'
    ]));
    $pdf->WriteHTML($reportHtml);

    $pdfFilename = sprintf(
        'Application-%d-Status-Report.pdf',
        $applicationId
    );

    $pdfContent = $pdf->Output(
        '',
        \Mpdf\Output\Destination::STRING_RETURN
    );
} catch (Throwable $exception) {
    failApplicationStatusReport(
        'The Application Status PDF could not be generated.',
        500
    );
}

// Record one explicit read only after successful PDF generation
$actionId = $recordReportAction();

if ($actionId <= 0) {
    failApplicationStatusReport(
        'The report Action could not be recorded.',
        500
    );
}

header('Content-Type: application/pdf');
header(
    'Content-Disposition: inline; filename="' .
    $pdfFilename .
    '"'
);
header('Content-Length: ' . strlen($pdfContent));
header('Cache-Control: private, no-store, max-age=0');

echo $pdfContent;
exit;

// #endregion