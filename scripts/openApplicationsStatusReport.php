<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — openApplicationsStatusReport.php
 *  Internal Open Permit Applications Status Report
 *  Codex-Governed Module • PHP 8.3
 * ===================================================================== */

// #region SECTION I — Environment & Authentication

date_default_timezone_set('America/Phoenix');

require_once __DIR__ . '/../api/sessionBootstrap.php';
require_once __DIR__ . '/../api/dbConnect.php';
require_once __DIR__ . '/../api/utils/actions.php';
require_once __DIR__ . '/../api/utils/openApplicationsReportData.php';
require_once __DIR__ . '/reportFrame.php';

const ACTION_ORIGIN_USER = 1;

function failOpenApplicationsStatusReport(
    string $message,
    int $statusCode = 400
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>Open Permit Applications Status Report</title></head><body>';
    echo '<h1>Open Permit Applications Status Report</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

if (!function_exists('getPDO')) {
    failOpenApplicationsStatusReport(
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
    failOpenApplicationsStatusReport(
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
    failOpenApplicationsStatusReport(
        'Authenticated Company Contact was not found.',
        403
    );
}

// #endregion

// #region SECTION II — Report Helpers

function escapeOpenApplicationsReportValue(mixed $value): string
{
    return htmlspecialchars(
        trim((string)($value ?? '')),
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatOpenApplicationsReportDate(?int $unix): string
{
    return $unix !== null && $unix > 0
        ? date('F j, Y', $unix)
        : '';
}

function formatOpenApplicationsReportDateTime(?int $unix): string
{
    return $unix !== null && $unix > 0
        ? date('F j, Y · g:i A T', $unix)
        : '';
}

function formatOpenApplicationsReportValue(mixed $value): string
{
    $resolved = trim((string)($value ?? ''));

    return $resolved !== '' ? $resolved : 'Not Available';
}

function buildOpenApplicationsAddress(array $application): string
{
    $addressParts = array_filter([
        trim((string)($application['locationAddress'] ?? '')),
        trim((string)($application['locationAddressSuite'] ?? '')),
        trim((string)($application['locationCity'] ?? '')),
        trim((string)($application['locationState'] ?? '')),
        trim((string)($application['locationZip'] ?? ''))
    ], static function ($value): bool {
        return $value !== '';
    });

    return implode(', ', $addressParts);
}

function renderOpenApplicationsDateRow(
    string $label,
    mixed $unix
): string {
    $resolvedUnix = is_numeric($unix) ? (int)$unix : null;
    $formattedDate = formatOpenApplicationsReportDate($resolvedUnix);

    if ($formattedDate === '') {
        return '';
    }

    return '<tr><th>' .
        escapeOpenApplicationsReportValue($label) .
        '</th><td>' .
        escapeOpenApplicationsReportValue($formattedDate) .
        '</td></tr>';
}

function renderOpenApplicationsSummaryHeading(
    string|false $rootDir
): string {
    // Resolve safe local Report Summary icon
    $iconPath = $rootDir !== false
        ? $rootDir .
            '/assets/images/icons/memo.png'
        : '';

    $iconHtml = '';

    // Render local icon when available
    if (
        $iconPath !== '' &&
        is_file($iconPath)
    ) {
        $iconSource =
            'file://' . $iconPath;

        $iconHtml = sprintf(
            '<img class="report-summary-icon" src="%s" alt="">',
            htmlspecialchars(
                $iconSource,
                ENT_QUOTES,
                'UTF-8'
            )
        );
    }

    return sprintf(
        '<div class="report-summary-heading">%s<span>%s</span></div>',
        $iconHtml,
        escapeOpenApplicationsReportValue(
            'Report Summary'
        )
    );
}

// #endregion

// #region SECTION III — Authoritative Open Application Data

$applications = loadOpenApplicationsReportData($db);
$reportGeneratedUnix = time();
$applicationCount = count($applications);
$reportPayload = buildOpenApplicationsReportPayload(
    $applications,
    $reportGeneratedUnix
);
$reportFingerprint = fingerprintOpenApplicationsReportPayload(
    $reportPayload
);
$storedSummary = $_SESSION['openApplicationsReportSummary'] ?? null;
$storedSummaryAge = is_array($storedSummary)
    ? $reportGeneratedUnix - (int)($storedSummary['generatedUnix'] ?? 0)
    : PHP_INT_MAX;
$storedSummaryMatches =
    is_array($storedSummary) &&
    hash_equals(
        $reportFingerprint,
        (string)($storedSummary['fingerprint'] ?? '')
    ) &&
    $storedSummaryAge >= 0 &&
    $storedSummaryAge <= 900 &&
    trim((string)($storedSummary['summaryNarrative'] ?? '')) !== '';
$reportSummary = $storedSummaryMatches
    ? trim((string)$storedSummary['summaryNarrative'])
    : buildOpenApplicationsFallbackSummary($reportPayload);
$reportSummarySource = $storedSummaryMatches
    ? trim((string)($storedSummary['summarySource'] ?? 'askOpenAI.php'))
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
    failOpenApplicationsStatusReport(
        'Report Action Type is not configured.',
        500
    );
}

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
    $applications,
    $applicationCount,
    $reportFingerprint,
    $reportSummarySource
): int {
    // Resolve authoritative Application identifiers
    $applicationIds = array_map(
        static function (
            array $application
        ): int {
            return (int)$application[
                'applicationID'
            ];
        },
        $applications
    );

    // Record governed PDF report Action
    return insertActionPrompt([
        'actionTypeId' =>
            $actionTypeId,
        'contactId' =>
            $contactId,
        'origin' =>
            ACTION_ORIGIN_USER,
        'activitySessionId' =>
            $activitySessionId,
        'promptText' =>
            'Generate Open Applications Status PDF',
        'responseText' => sprintf(
            'Generated internal status PDF for %d open Application%s.',
            $applicationCount,
            $applicationCount === 1
                ? ''
                : 's'
        ),
        'intent' =>
            'report.document.read',
        'intentConfidence' =>
            1.00,
        'actionPayloadData' => [
            'operation' =>
                'applications.open_status_report',
            'audience' =>
                'internal',
            'outputFormat' =>
                'pdf',
            'sort' =>
                'applicationCreatedUnix.asc',
            'reportFingerprint' =>
                $reportFingerprint,
            'summarySource' =>
                $reportSummarySource
        ],
        'actionResponseData' => [
            'success' =>
                true,
            'rowCount' =>
                $applicationCount,
            'applicationIDs' =>
                $applicationIds,
            'reportType' =>
                'open_applications_status',
            'audience' =>
                'internal',
            'outputFormat' =>
                'pdf',
            'summarySource' =>
                $reportSummarySource
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
    '%d Open Permit Application%s',
    $applicationCount,
    $applicationCount === 1 ? '' : 's'
);
$reportLine = 'Report Date: ' . formatOpenApplicationsReportDateTime(
    $reportGeneratedUnix
);
$reportHeaderHtml = renderSkyesoftReportHeader([
    'title' => 'Open Permit Applications Status Report',
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
    <title>Open Permit Applications Status Report</title>
    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            color: #222;
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.25;
        }

        .report {
            width: 100%;
            margin: 0;
        }

        /* Keep Report Summary together */
        .report-summary {
            margin: 0 0 8px;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .report-summary-heading {
            margin: 0 0 3px;
            padding: 0 0 2px;
            color: #14377c;
            font-size: 14px;
            font-weight: bold;
            line-height: 16px;
            border-bottom: 2px solid #14377c;
        }

        .report-summary-heading span {
            display: inline-block;
            vertical-align: middle;
        }

        .report-summary-icon {
            display: inline-block;
            width: 15px;
            height: 15px;
            margin-right: 5px;
            vertical-align: middle;
            object-fit: contain;
        }

        .report-summary-body {
            padding: 6px 8px;
            color: #333;
            font-size: 10px;
            line-height: 1.35;
            background: #f0f4f9;
            border: 1px solid #b8cbe5;
            border-left: 4px solid #14377c;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        /* Keep each Application together */
        .application-block {
            margin: 0 0 9px;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .application-heading {
            margin: 0 0 3px;
            padding: 4px 6px;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
            background: #14377c;
        }

        .application-table {
            width: 100%;
            border-collapse: collapse;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .application-table tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .application-table th,
        .application-table td {
            padding: 2.5px 5px;
            border: 1px solid #ccc;
            text-align: left;
            vertical-align: top;
        }

        .application-table th {
            width: 30%;
            color: #333;
            font-weight: bold;
            white-space: nowrap;
            background: #f8f9fa;
        }

        .application-table td {
            width: 70%;
            color: #111;
            background: #fff;
        }

        .status-value {
            color: #14377c;
            font-weight: bold;
        }

        .scope-value {
            white-space: pre-line;
        }

        .no-applications {
            padding: 12px;
            color: #555;
            text-align: center;
            background: #f8f9fa;
            border: 1px solid #ccc;
            break-inside: avoid;
            page-break-inside: avoid;
        }
    </style>
</head>
<body>

<div class="report">
    <div class="report-summary">
        <?= renderOpenApplicationsSummaryHeading(
            $rootDir
        ) ?>

        <div class="report-summary-body">
            <?= escapeOpenApplicationsReportValue(
                $reportSummary
            ) ?>
        </div>
    </div>

    <?php if ($applicationCount === 0): ?>
        <div class="no-applications">
            No open permit Applications were found.
        </div>
    <?php endif; ?>

    <?php foreach ($applications as $application): ?>
        <?php
        $locationAddress = buildOpenApplicationsAddress($application);
        $applicationHeading = sprintf(
            'Application #%d — %s (%s)',
            (int)$application['applicationID'],
            formatOpenApplicationsReportValue($application['locationName']),
            formatOpenApplicationsReportValue(
                $application['orderChristyNumber']
            )
        );
        ?>
        <div class="application-block">
            <div class="application-heading">
                <?= escapeOpenApplicationsReportValue(
                    $applicationHeading
                ) ?>
            </div>
            <table class="application-table">
                <tr>
                    <th>Application</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsReportValue(
                            $application['applicationTitle']
                        )
                    ) ?></td>
                </tr>
                <tr>
                    <th>Customer</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsReportValue(
                            $application['entityName']
                        )
                    ) ?></td>
                </tr>
                <tr>
                    <th>Address</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsReportValue($locationAddress)
                    ) ?></td>
                </tr>
                <tr>
                    <th>Jurisdiction</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsReportValue(
                            $application['applicationJurisdiction']
                        )
                    ) ?></td>
                </tr>
                <?php if (trim((string)$application['applicationNumber']) !== ''): ?>
                    <tr>
                        <th>Jurisdiction Application Number</th>
                        <td><?= escapeOpenApplicationsReportValue(
                            $application['applicationNumber']
                        ) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (trim((string)$application['applicationPermitNumber']) !== ''): ?>
                    <tr>
                        <th>Permit Number</th>
                        <td><?= escapeOpenApplicationsReportValue(
                            $application['applicationPermitNumber']
                        ) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th>Application Scope</th>
                    <td class="scope-value"><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsReportValue(
                            $application['applicationScope']
                        )
                    ) ?></td>
                </tr>
                <tr>
                    <th>Stage</th>
                    <td class="status-value"><?= escapeOpenApplicationsReportValue(
                        $application['applicationStageName']
                    ) ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td class="status-value"><?= escapeOpenApplicationsReportValue(
                        $application['applicationStatusName']
                    ) ?></td>
                </tr>
                <tr>
                    <th>Status Description</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsReportValue(
                            $application['applicationStatusDescription']
                        )
                    ) ?></td>
                </tr>
                <?= renderOpenApplicationsDateRow(
                    'Received',
                    $application[
                        'applicationCreatedUnix'
                    ]
                ) ?>

                <?= renderOpenApplicationsDateRow(
                    'Submitted',
                    $application[
                        'applicationSubmittedUnix'
                    ]
                ) ?>

                <?= renderOpenApplicationsDateRow(
                    'Approved',
                    $application[
                        'applicationApprovedUnix'
                    ]
                ) ?>

                <?= renderOpenApplicationsDateRow(
                    'Issued',
                    $application[
                        'applicationIssuedUnix'
                    ]
                ) ?>

                <?= renderOpenApplicationsDateRow(
                    'Finaled',
                    $application[
                        'applicationFinaledUnix'
                    ]
                ) ?>
            </table>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>
<?php

$reportHtml = (string)ob_get_clean();

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
    failOpenApplicationsStatusReport(
        'The Skyesoft PDF rendering engine is unavailable.',
        500
    );
}

require_once $autoloadPath;

if (!class_exists('Mpdf\\Mpdf')) {
    failOpenApplicationsStatusReport(
        'The Skyesoft PDF rendering engine is not installed.',
        500
    );
}

$mpdfTempDir = sys_get_temp_dir() . '/skyesoft-mpdf';

if (!is_dir($mpdfTempDir) && !mkdir($mpdfTempDir, 0775, true)) {
    failOpenApplicationsStatusReport(
        'The PDF runtime directory could not be prepared.',
        500
    );
}

try {
    $pdf = new \Mpdf\Mpdf(
        getSkyesoftReportMpdfConfig($mpdfTempDir)
    );

    $pdf->SetTitle('Open Permit Applications Status Report');
    $pdf->SetAuthor($preparedBy);
    $pdf->SetCreator('Skyesoft');
    $pdf->WriteHTML(
        getSkyesoftReportFrameStyles(),
        \Mpdf\HTMLParserMode::HEADER_CSS
    );
    $pdf->SetHTMLHeader($reportHeaderHtml);
    $pdf->SetHTMLFooter(renderSkyesoftReportFooter([
        'preparedBy' => $preparedBy,
        'reportName' => 'Skyesoft Open Applications Status'
    ]));
    $pdf->WriteHTML($reportHtml);

    $pdfFilename = 'Open-Permit-Applications-Status-Report.pdf';
    $pdfContent = $pdf->Output(
        '',
        \Mpdf\Output\Destination::STRING_RETURN
    );
} catch (Throwable $exception) {
    failOpenApplicationsStatusReport(
        'The Open Permit Applications Status PDF could not be generated.',
        500
    );
}

$actionId = $recordReportAction();

if ($actionId <= 0) {
    failOpenApplicationsStatusReport(
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