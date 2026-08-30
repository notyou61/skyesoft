<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — applicationStatusReport.php
 *  External Permit Application Status Report
 *  Codex-Governed Module • PHP 8.3
 * ===================================================================== */

#region SECTION I — Environment & Authentication

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

#endregion

#region SECTION II — Report Helpers

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

function formatApplicationReportValue(mixed $value): string
{
    $resolved = trim((string)($value ?? ''));

    return $resolved !== '' ? $resolved : 'Not Available';
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

#endregion

#region SECTION III — Authoritative Application Data

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
$statusSummary = buildApplicationStatusSummary(
    (string)$application['applicationStageName']
);

#endregion

#region SECTION IV — Explicit Report Action

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

#endregion

#region SECTION V — Report Rendering

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
$reportLine = sprintf(
    'Report Date: %s · Application #%d',
    formatApplicationReportDate($reportGeneratedUnix),
    (int)$application['applicationID']
);

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
        <?= getSkyesoftReportFrameStyles() ?>

        html,
        body {
            margin: 0;
            padding: 0;
            color: #222;
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
            line-height: 1.35;
        }

        .report {
            width: 100%;
            margin: 0;
        }

        .section {
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

        .data-table,
        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 8px 10px;
            border: 1px solid #ccc;
            text-align: left;
            vertical-align: top;
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

        .scope-box,
        .summary-box {
            padding: 9px 12px;
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
            margin-bottom: 4px;
            color: #14377c;
            font-size: 14px;
            font-weight: bold;
        }

        .summary-body {
            color: #333;
            font-size: 13px;
            line-height: 1.4;
        }

    </style>
</head>
<body>

<div class="report">
    <?= renderSkyesoftReportHeader([
        'title' => 'Permit Application Status Report',
        'subtitle' => $application['applicationTitle'],
        'reportLine' => $reportLine,
        'logoSource' => $logoSource,
        'logoAvailable' => $logoAvailable
    ]) ?>

    <div class="section">
        <div class="section-heading">Project Information</div>
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
        <div class="section-heading">Application Status</div>
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
        <div class="section-heading">Permit Identification</div>
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
        <div class="section-heading">Permit Milestones</div>
        <table class="data-table">
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
        <div class="section-heading">Application Scope</div>
        <div class="scope-box"><?= escapeApplicationReportValue(
            formatApplicationReportValue(
                $application['applicationScope']
            )
        ) ?></div>
    </div>

    <div class="section">
        <div class="section-heading">Status Summary</div>
        <div class="summary-box">
            <div class="summary-title">Application Status</div>
            <div class="summary-body">
                This report reflects the permit application status recorded by Christy Signs as of
                <?= escapeApplicationReportValue(
                    formatApplicationReportDate($reportGeneratedUnix)
                ) ?>. Jurisdiction processing times and requirements may change during review. Christy Signs will continue coordinating the application through the applicable permit process.
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

#endregion