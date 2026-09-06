<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — openApplicationsStatusReport.php
 *  Internal Open Permit Applications Status Report
 *  Codex-Governed Module • PHP 8.3
 * ===================================================================== */

// #region SECTION 0 — REPORT ERROR LOGGING

function logOpenApplicationsStatusReportError(
    string $message
): void {
    // Build a uniquely labeled report log entry
    $logEntry = sprintf(
        "[%s] [openApplicationsStatusReport] %s%s",
        date('Y-m-d H:i:s T'),
        $message,
        PHP_EOL
    );

    // Append to the host-managed writable log
    error_log(
        $logEntry,
        3,
        __DIR__ . '/error_log'
    );
}

// Capture fatal runtime errors outside try/catch
register_shutdown_function(
    static function (): void {
        $lastError = error_get_last();

        // Ignore normal successful shutdowns
        if (
            !is_array($lastError) ||
            !in_array(
                (int)$lastError['type'],
                [
                    E_ERROR,
                    E_PARSE,
                    E_CORE_ERROR,
                    E_COMPILE_ERROR,
                    E_USER_ERROR
                ],
                true
            )
        ) {
            return;
        }

        // Record the fatal runtime error
        logOpenApplicationsStatusReportError(
            sprintf(
                'Fatal error: %s in %s on line %d',
                (string)$lastError['message'],
                (string)$lastError['file'],
                (int)$lastError['line']
            )
        );
    }
);

// Confirm the deployed report begins execution
logOpenApplicationsStatusReportError(
    'Checkpoint 1 — report execution started.'
);

// #endregion

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

function renderOpenApplicationsSectionHeading(
    string $title,
    string|false $rootDir
): string {
    // Define report icons (single source of truth)
    $iconFilesByTitle = [
        'Report Summary' => 'memo.png',
        'Permit Application Process' => 'integration.png',
        'Permit Fees' => 'document.png',
        'Active Special Requirements' => 'warning.png',
        'Application Notes' => 'notes.png'
    ];

    // Resolve the configured safe local icon
    $iconFile = basename(
        $iconFilesByTitle[$title] ?? 'document.png'
    );
    $iconPath = $rootDir !== false
        ? $rootDir .
            '/assets/images/icons/' .
            $iconFile
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
            '<img src="%s" width="10" height="10" ' .
            'style="margin-right:4px;vertical-align:-1px;" alt="">',
            htmlspecialchars(
                'file://' . $iconPath,
                ENT_QUOTES,
                'UTF-8'
            )
        );
    }

    return sprintf(
        '<div class="section-heading">%s<span>%s</span></div>',
        $iconHtml,
        escapeOpenApplicationsReportValue($title)
    );
}

function formatOpenApplicationDuration(
    array $application,
    int $reportGeneratedUnix
): string {
    $receivedUnix = is_numeric(
        $application['applicationCreatedUnix'] ?? null
    )
        ? (int)$application['applicationCreatedUnix']
        : null;
    $finaledUnix = is_numeric(
        $application['applicationFinaledUnix'] ?? null
    )
        ? (int)$application['applicationFinaledUnix']
        : null;

    if ($receivedUnix === null || $receivedUnix <= 0) {
        return 'Duration unavailable';
    }

    $isFinaled = $finaledUnix !== null && $finaledUnix > 0;
    $endUnix = $isFinaled
        ? $finaledUnix
        : $reportGeneratedUnix;
    $calendarDays = calculateOpenApplicationCalendarDays(
        $receivedUnix,
        $endUnix
    );

    if ($calendarDays === null) {
        return 'Duration cannot be calculated from the recorded dates';
    }

    return $isFinaled
        ? sprintf(
            '%d calendar day%s from Received to Finaled',
            $calendarDays,
            $calendarDays === 1 ? '' : 's'
        )
        : sprintf(
            '%d calendar day%s open as of the report date',
            $calendarDays,
            $calendarDays === 1 ? '' : 's'
        );
}

function formatOpenApplicationWorkflowPosition(
    array $application
): string {
    $currentStage = formatOpenApplicationsReportValue(
        $application['applicationStageName'] ?? null
    );
    $currentStatus = formatOpenApplicationsReportValue(
        $application['applicationStatusName'] ?? null
    );
    $nextStage = trim((string)(
        $application['applicationNextStageName'] ?? ''
    ));

    return sprintf(
        'Current: %s — %s%s',
        $currentStage,
        $currentStatus,
        $nextStage !== ''
            ? '; Next configured Stage: ' . $nextStage
            : '; No later active Stage is configured'
    );
}

function resolveOpenApplicationStageIconFile(
    array $application,
    bool $nativeSize = false
): string {
    // Define Stage icons (single source of truth)
    $iconFilesByStage = [
        'pre-submittal' => 'clipboard.png',
        'submitted' => 'upArrow.png',
        'jurisdiction review' => 'temple.png',
        'approval / issuance' => 'shield.png',
        'inspection' => 'tools.png',
        'finaled' => 'trophy.png'
    ];
    $stageName = formatOpenApplicationsReportValue(
        $application['applicationStageName'] ?? null
    );
    $stageKey = strtolower(trim($stageName));
    $iconFile = $iconFilesByStage[$stageKey] ?? '';

    // Preserve the general fallback outside the workflow table
    if ($iconFile === '') {
        return $nativeSize ? '' : 'document.png';
    }

    if ($nativeSize) {
        $iconParts = pathinfo($iconFile);
        $iconFile =
            ($iconParts['filename'] ?? '') .
            '-10.' .
            ($iconParts['extension'] ?? 'png');
    }

    return basename($iconFile);
}

function renderOpenApplicationStageValue(
    array $application,
    string|false $rootDir
): string {
    $stageName = formatOpenApplicationsReportValue(
        $application['applicationStageName'] ?? null
    );
    $iconFile = resolveOpenApplicationStageIconFile(
        $application
    );
    $iconPath = $rootDir !== false
        ? $rootDir . '/assets/images/icons/' . $iconFile
        : '';
    $iconHtml = '';

    // Render the local Stage icon when available
    if ($iconPath !== '' && is_file($iconPath)) {
        $iconHtml = sprintf(
            '<img class="stage-icon" src="%s" alt="">',
            htmlspecialchars(
                'file://' . $iconPath,
                ENT_QUOTES,
                'UTF-8'
            )
        );
    }

    return $iconHtml .
        '<span>' .
        escapeOpenApplicationsReportValue($stageName) .
        '</span>';
}

function renderOpenApplicationsWorkflowStageCell(
    array $application,
    string|false $rootDir
): string {
    $stageName = formatOpenApplicationsReportValue(
        $application['applicationStageName'] ?? null
    );
    $iconFile = resolveOpenApplicationStageIconFile(
        $application,
        true
    );
    $iconPath = $rootDir !== false && $iconFile !== ''
        ? $rootDir . '/assets/images/icons/' . $iconFile
        : '';
    $escapedStageName = escapeOpenApplicationsReportValue(
        $stageName
    );

    // Render a plain label until its native-size icon exists
    if ($iconPath === '' || !is_file($iconPath)) {
        return '<th>' . $escapedStageName . '</th>';
    }

    return sprintf(
        '<th class="workflow-stage" ' .
        'style="background-image:url(\'%s\');">%s</th>',
        htmlspecialchars(
            'file://' . $iconPath,
            ENT_QUOTES,
            'UTF-8'
        ),
        $escapedStageName
    );
}

function formatOpenApplicationsFeeStatus(
    array $application
): string {
    $feeStatus = trim((string)(
        $application['applicationFeeStatus'] ?? 'No Fees'
    ));

    $totalPaid = round((float)(
        $application['applicationFeeTotalPaid'] ?? 0
    ), 2);

    $totalOutstanding = round((float)(
        $application['applicationFeeTotalOutstanding'] ?? 0
    ), 2);

    if ($feeStatus === 'Paid') {
        return sprintf(
            'Paid — $%s',
            number_format($totalPaid, 2)
        );
    }

    if ($feeStatus === 'Partially Paid') {
        return sprintf(
            'Partially Paid — $%s paid; $%s awaiting payment',
            number_format($totalPaid, 2),
            number_format($totalOutstanding, 2)
        );
    }

    if ($feeStatus === 'Awaiting Payment') {
        return sprintf(
            'Awaiting Payment — $%s',
            number_format($totalOutstanding, 2)
        );
    }

    return 'No Fees';
}

function buildOpenApplicationsFeeLedger(
    array $fees
): array {
    $events = [];

    // Expand each Fee into dated accounting events
    foreach ($fees as $fee) {
        $feeId = (int)($fee['feeID'] ?? 0);
        $amount = round((float)($fee['feeAmount'] ?? 0), 2);
        $category = trim((string)($fee['feeCategory'] ?? 'Permit'));
        $note = trim((string)($fee['feeNote'] ?? ''));
        $description = $category . ' Fee';
        $isPaid = is_numeric($fee['feePaidUnix'] ?? null) &&
            (int)$fee['feePaidUnix'] > 0;

        if ($note !== '') {
            $description .= ' — ' . $note;
        }

        $assessedUnix = is_numeric($fee['feeAssessedUnix'] ?? null)
            ? (int)$fee['feeAssessedUnix']
            : (int)($fee['feeCreatedUnix'] ?? 0);

        $events[] = [
            'unix' => $assessedUnix,
            'sortOrder' => 1,
            'feeID' => $feeId,
            'category' => $category,
            'description' => $description,
            'charge' => $amount,
            'credit' => 0.00,
            'type' => 'assessment'
        ];

        if ($isPaid) {
            $events[] = [
                'unix' => (int)$fee['feePaidUnix'],
                'sortOrder' => 2,
                'feeID' => $feeId,
                'category' => $category,
                'description' => 'Payment — ' . $description,
                'charge' => 0.00,
                'credit' => $amount,
                'type' => 'payment'
            ];
        }

        if (
            is_numeric($fee['feeVoidedUnix'] ?? null) &&
            (int)$fee['feeVoidedUnix'] > 0
        ) {
            $voidReason = trim((string)(
                $fee['feeVoidReason'] ?? ''
            ));
            $voidDescription = 'Void reversal — ' . $description;

            if ($voidReason !== '') {
                $voidDescription .= ' (' . $voidReason . ')';
            }

            if ($isPaid) {
                $voidDescription .=
                    ' — paid before void; refund status not recorded';
            }

            $events[] = [
                'unix' => (int)$fee['feeVoidedUnix'],
                'sortOrder' => 3,
                'feeID' => $feeId,
                'category' => $category,
                'description' => $voidDescription,
                'charge' => 0.00,
                'credit' => $isPaid ? 0.00 : $amount,
                'type' => 'void'
            ];
        }
    }

    usort(
        $events,
        static function (array $left, array $right): int {
            return [
                $left['unix'],
                $left['sortOrder'],
                $left['feeID']
            ] <=> [
                $right['unix'],
                $right['sortOrder'],
                $right['feeID']
            ];
        }
    );

    $balance = 0.00;

    // Calculate the authoritative running balance
    foreach ($events as &$event) {
        $balance = round(
            $balance + $event['charge'] - $event['credit'],
            2
        );
        $event['balance'] = $balance;
    }
    unset($event);

    return $events;
}

function buildOpenApplicationsFeeBreakdown(
    array $feeLedger
): array {
    // Define authoritative Fee categories (tblApplicationFees)
    $categoryOrder = [
        'Application',
        'Review',
        'Permit'
    ];
    $breakdown = [];

    // Total activity within each recorded Fee category
    foreach ($feeLedger as $event) {
        $category = trim((string)($event['category'] ?? 'Permit'));

        if (!isset($breakdown[$category])) {
            $breakdown[$category] = [
                'category' => $category,
                'assessed' => 0.00,
                'credits' => 0.00,
                'outstanding' => 0.00
            ];
        }

        $breakdown[$category]['assessed'] = round(
            $breakdown[$category]['assessed'] +
                (float)($event['charge'] ?? 0),
            2
        );
        $breakdown[$category]['credits'] = round(
            $breakdown[$category]['credits'] +
                (float)($event['credit'] ?? 0),
            2
        );
        $breakdown[$category]['outstanding'] = round(
            $breakdown[$category]['assessed'] -
                $breakdown[$category]['credits'],
            2
        );
    }

    $rows = [];

    // Return configured categories first, then future values safely
    foreach ($categoryOrder as $category) {
        if (isset($breakdown[$category])) {
            $rows[] = $breakdown[$category];
            unset($breakdown[$category]);
        }
    }

    foreach ($breakdown as $row) {
        $rows[] = $row;
    }

    return $rows;
}

function formatOpenApplicationsLedgerAmount(
    float $amount
): string {
    return $amount > 0
        ? '$' . number_format($amount, 2)
        : '—';
}

function formatOpenApplicationsRequirementStatus(
    array $application
): string {
    $activeCount = (int)(
        $application[
            'applicationActiveRequirementCount'
        ] ?? 0
    );

    return $activeCount > 0
        ? sprintf(
            '%d Active Requirement%s',
            $activeCount,
            $activeCount === 1 ? '' : 's'
        )
        : 'No Active Requirements';
}

// #endregion

// #region SECTION III — Authoritative Open Application Data

$applications = loadOpenApplicationsReportData($db);

logOpenApplicationsStatusReportError(
    'Checkpoint 2 — Application data loaded.'
);
$workflow = loadOpenApplicationsWorkflowData($db);
$workflowStages = is_array($workflow['stages'] ?? null)
    ? $workflow['stages']
    : [];
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
                $reportSummarySource,
            'workflowIncluded' =>
                true,
            'specialRequirementsIncluded' =>
                true,
            'internalNotesIncluded' =>
                true
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
                $reportSummarySource,
            'workflowIncluded' =>
                true,
            'specialRequirementsIncluded' =>
                true,
            'internalNotesIncluded' =>
                true
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
            page-break-inside: avoid;
        }

        .section-heading {
            margin: 0 0 3px;
            padding: 0 0 2px;
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

        .report-summary-body {
            padding: 6px 8px;
            color: #333;
            font-size: 10px;
            line-height: 1.35;
            background: #f0f4f9;
            border: 1px solid #b8cbe5;
            border-left: 4px solid #14377c;
        }

        .workflow-section {
            margin: 0 0 9px;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .workflow-introduction {
            margin-bottom: 4px;
            padding: 5px 7px;
            color: #444;
            font-size: 9px;
            line-height: 1.3;
            background: #f8f9fa;
            border: 1px solid #ccc;
        }

        .workflow-table,
        .fee-breakdown-table,
        .fee-ledger-table,
        .requirement-table,
        .notes-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .workflow-table tr,
        .fee-breakdown-table tr,
        .fee-ledger-table tr,
        .requirement-table tr,
        .notes-table tr {
            page-break-inside: avoid;
        }

        .workflow-table th,
        .workflow-table td,
        .fee-breakdown-table th,
        .fee-breakdown-table td,
        .fee-ledger-table th,
        .fee-ledger-table td,
        .requirement-table th,
        .requirement-table td,
        .notes-table th,
        .notes-table td {
            padding: 3px 5px;
            border: 1px solid #ccc;
            text-align: left;
            vertical-align: top;
        }

        .workflow-table th {
            width: 24%;
            color: #333;
            font-size: 9px;
            background: #f8f9fa;
        }

        .workflow-table td {
            width: 76%;
            color: #111;
            font-size: 8.5px;
            background: #fff;
        }

        .workflow-table th.workflow-stage {
            width: 24%;
            padding-left: 19px;
            background-color: #f8f9fa;
            background-repeat: no-repeat;
            background-position: 5px 4px;
        }

        .workflow-table td.workflow-detail {
            width: 76%;
        }

        .workflow-description {
            margin-bottom: 2px;
            color: #444;
        }

        .workflow-status {
            display: block;
            margin-top: 1px;
            color: #555;
        }

        .workflow-reference-subsection {
            margin-top: 8px;
        }

        /* Allow long Applications to flow without font scaling */
        .application-block {
            margin: 0 0 9px;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .application-table th.application-heading {
            width: 100%;
            padding: 4px 6px;
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            line-height: 1.2;
            text-align: left;
            white-space: normal;
            background: #14377c;
            page-break-after: avoid;
        }

        .application-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .application-table tr {
            page-break-inside: avoid;
        }

        .application-table th,
        .application-table td {
            padding: 2.5px 5px;
            border: 1px solid #ccc;
            font-size: 10px;
            line-height: 1.2;
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

        .stage-icon {
            display: inline-block;
            width: 1em;
            height: 1em;
            margin-right: 4px;
            vertical-align: -0.12em;
            object-fit: contain;
        }

        .scope-value {
            white-space: pre-line;
        }

        .application-subsection {
            margin-top: 4px;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .application-subheading {
            margin: 0;
            padding: 3px 5px;
            color: #14377c;
            font-size: 9px;
            font-weight: bold;
            background: #f0f4f9;
            border: 1px solid #b8cbe5;
            border-bottom: 0;
        }

        .fee-ledger-table th {
            color: #333;
            font-size: 8px;
            background: #f8f9fa;
        }

        .fee-breakdown-table {
            margin-bottom: 4px;
        }

        .fee-breakdown-table th {
            color: #333;
            font-size: 8px;
            background: #e7eef8;
        }

        .fee-breakdown-table td {
            color: #111;
            font-size: 8.5px;
            background: #fff;
        }

        .fee-breakdown-table .fee-amount {
            text-align: right;
            white-space: nowrap;
        }

        .fee-breakdown-table .fee-balance {
            color: #14377c;
            font-weight: bold;
        }

        .fee-ledger-table td {
            color: #111;
            font-size: 8.5px;
            background: #fff;
        }

        .fee-ledger-table .fee-amount {
            text-align: right;
            white-space: nowrap;
        }

        .fee-ledger-table .fee-payment {
            color: #20733a;
        }

        .fee-ledger-table .fee-void {
            color: #b91c1c;
        }

        .fee-ledger-table .fee-balance {
            color: #14377c;
            font-weight: bold;
        }

        .fee-ledger-table tfoot td {
            font-weight: bold;
            background: #f0f4f9;
        }

        .requirement-table th,
        .notes-table th {
            color: #333;
            font-size: 8px;
            background: #f8f9fa;
        }

        .requirement-table td,
        .notes-table td {
            color: #111;
            font-size: 8.5px;
            background: #fff;
        }

        .requirement-status {
            color: #b45309;
            font-weight: bold;
        }

        .note-meta {
            width: 30%;
            font-weight: bold;
            white-space: nowrap;
        }

        .empty-detail {
            padding: 4px 6px;
            color: #666;
            font-size: 8.5px;
            background: #f8f9fa;
            border: 1px solid #ccc;
        }

        .no-applications {
            padding: 12px;
            color: #555;
            text-align: center;
            background: #f8f9fa;
            border: 1px solid #ccc;
            page-break-inside: avoid;
        }

        .stage-icon {
            display: inline-block;
            width: 1em;
            height: 1em;
            margin-right: 4px;
            vertical-align: -0.12em;
            object-fit: contain;
        }

    </style>
</head>
<body>

<div class="report">
    <div class="report-summary">
        <?= renderOpenApplicationsSectionHeading(
            'Report Summary',
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
            <table class="application-table">
                <tr>
                    <th class="application-heading" colspan="2">
                        <?= escapeOpenApplicationsReportValue(
                            $applicationHeading
                        ) ?>
                    </th>
                </tr>
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
                    <td class="scope-value"><?= nl2br(
                        escapeOpenApplicationsReportValue(
                            formatOpenApplicationsReportValue(
                                $application['applicationScope']
                            )
                        )
                    ) ?></td>
                </tr>
                <tr>
                    <th>Stage</th>
                    <td class="status-value"><?=
                        renderOpenApplicationStageValue(
                            $application,
                            $rootDir
                        )
                    ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td class="status-value"><?= escapeOpenApplicationsReportValue(
                        $application['applicationStatusName']
                    ) ?></td>
                </tr>
                <tr>
                    <th>Special Requirements</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsRequirementStatus(
                            $application
                        )
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
                <tr>
                    <th>Workflow Position</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationWorkflowPosition(
                            $application
                        )
                    ) ?></td>
                </tr>
                <tr>
                    <th>Permit Duration</th>
                    <td><?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationDuration(
                            $application,
                            $reportGeneratedUnix
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

            <?php
            $applicationRequirements = is_array(
                $application['applicationSpecialRequirements'] ?? null
            )
                ? $application['applicationSpecialRequirements']
                : [];
            $applicationFees = is_array(
                $application['applicationFees'] ?? null
            )
                ? $application['applicationFees']
                : [];
            $feeLedger = buildOpenApplicationsFeeLedger(
                $applicationFees
            );
            $feeBreakdown = buildOpenApplicationsFeeBreakdown(
                $feeLedger
            );
            $applicationNotes = is_array(
                $application['applicationNotes'] ?? null
            )
                ? $application['applicationNotes']
                : [];
            ?>

            <div class="application-subsection">
                <div class="application-subheading">
                    Permit Fees — <?= escapeOpenApplicationsReportValue(
                        formatOpenApplicationsFeeStatus($application)
                    ) ?>
                </div>

                <?php if ($feeLedger !== []): ?>
                    <table class="fee-breakdown-table">
                        <thead>
                            <tr>
                                <th style="width:40%;">Fee Type</th>
                                <th style="width:20%;text-align:right;">Assessed</th>
                                <th style="width:20%;text-align:right;">Payments / Credits</th>
                                <th style="width:20%;text-align:right;">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feeBreakdown as $feeCategory): ?>
                                <tr>
                                    <td><?= escapeOpenApplicationsReportValue(
                                        $feeCategory['category'] . ' Fees'
                                    ) ?></td>
                                    <td class="fee-amount"><?=
                                        escapeOpenApplicationsReportValue(
                                            '$' . number_format(
                                                $feeCategory['assessed'],
                                                2
                                            )
                                        )
                                    ?></td>
                                    <td class="fee-amount"><?=
                                        escapeOpenApplicationsReportValue(
                                            '$' . number_format(
                                                $feeCategory['credits'],
                                                2
                                            )
                                        )
                                    ?></td>
                                    <td class="fee-amount fee-balance"><?=
                                        escapeOpenApplicationsReportValue(
                                            '$' . number_format(
                                                $feeCategory['outstanding'],
                                                2
                                            )
                                        )
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <table class="fee-ledger-table">
                        <thead>
                            <tr>
                                <th style="width:12%;">Date</th>
                                <th style="width:52%;">Transaction</th>
                                <th style="width:12%;text-align:right;">Charge</th>
                                <th style="width:12%;text-align:right;">Payment / Credit</th>
                                <th style="width:12%;text-align:right;">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feeLedger as $feeEvent): ?>
                                <?php
                                $feeEventClass = $feeEvent['type'] === 'void'
                                    ? 'fee-void'
                                    : (
                                        $feeEvent['type'] === 'payment'
                                            ? 'fee-payment'
                                            : ''
                                    );
                                ?>
                                <tr class="<?= $feeEventClass ?>">
                                    <td><?= escapeOpenApplicationsReportValue(
                                        formatOpenApplicationsReportDate(
                                            $feeEvent['unix']
                                        )
                                    ) ?></td>
                                    <td><?= escapeOpenApplicationsReportValue(
                                        $feeEvent['description']
                                    ) ?></td>
                                    <td class="fee-amount"><?=
                                        escapeOpenApplicationsReportValue(
                                            formatOpenApplicationsLedgerAmount(
                                                $feeEvent['charge']
                                            )
                                        )
                                    ?></td>
                                    <td class="fee-amount"><?=
                                        escapeOpenApplicationsReportValue(
                                            formatOpenApplicationsLedgerAmount(
                                                $feeEvent['credit']
                                            )
                                        )
                                    ?></td>
                                    <td class="fee-amount fee-balance"><?=
                                        escapeOpenApplicationsReportValue(
                                            '$' . number_format(
                                                $feeEvent['balance'],
                                                2
                                            )
                                        )
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4">Current Outstanding Balance</td>
                                <td class="fee-amount fee-balance">
                                    $<?= number_format(
                                        (float)(
                                            $application[
                                                'applicationFeeTotalOutstanding'
                                            ] ?? 0
                                        ),
                                        2
                                    ) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                <?php else: ?>
                    <div class="empty-detail">
                        No permit Fees have been recorded.
                    </div>
                <?php endif; ?>
            </div>

            <div class="application-subsection">
                <div class="application-subheading">
                    Active Special Requirements
                </div>

                <?php if ($applicationRequirements !== []): ?>
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
                                $applicationRequirements as $requirement
                            ): ?>
                                <tr>
                                    <td><?= nl2br(
                                        escapeOpenApplicationsReportValue(
                                            formatOpenApplicationsReportValue(
                                                $requirement[
                                                    'applicationSpecialRequirementDescription'
                                                ] ?? null
                                            )
                                        )
                                    ) ?></td>
                                    <td class="requirement-status"><?=
                                        escapeOpenApplicationsReportValue(
                                            formatOpenApplicationsReportValue(
                                                $requirement[
                                                    'applicationSpecialRequirementStatusName'
                                                ] ?? null
                                            )
                                        )
                                    ?></td>
                                    <td><?= escapeOpenApplicationsReportValue(
                                        formatOpenApplicationsReportValue(
                                            $requirement[
                                                'applicationSpecialRequirementResponsibleParty'
                                            ] ?? null
                                        )
                                    ) ?></td>
                                    <td><?= escapeOpenApplicationsReportValue(
                                        formatOpenApplicationsReportDate(
                                            is_numeric($requirement[
                                                'applicationSpecialRequirementRequiredUnix'
                                            ] ?? null)
                                                ? (int)$requirement[
                                                    'applicationSpecialRequirementRequiredUnix'
                                                ]
                                                : null
                                        )
                                    ) ?></td>
                                    <td><?= escapeOpenApplicationsReportValue(
                                        formatOpenApplicationsReportDate(
                                            is_numeric($requirement[
                                                'applicationSpecialRequirementDueUnix'
                                            ] ?? null)
                                                ? (int)$requirement[
                                                    'applicationSpecialRequirementDueUnix'
                                                ]
                                                : null
                                        )
                                    ) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-detail">
                        No active Special Requirements have been recorded.
                    </div>
                <?php endif; ?>
            </div>

            <div class="application-subsection">
                <div class="application-subheading">
                    Application Notes
                </div>

                <?php if ($applicationNotes !== []): ?>
                    <table class="notes-table">
                        <?php foreach ($applicationNotes as $note): ?>
                            <?php
                            $authorName = trim(
                                (string)($note['contactFirstName'] ?? '') .
                                ' ' .
                                (string)($note['contactLastName'] ?? '')
                            );
                            $noteUnix = is_numeric(
                                $note['noteUpdatedUnix'] ?? null
                            )
                                ? (int)$note['noteUpdatedUnix']
                                : (
                                    is_numeric(
                                        $note['noteCreatedUnix'] ?? null
                                    )
                                        ? (int)$note['noteCreatedUnix']
                                        : null
                                );
                            $noteLabel = formatOpenApplicationsReportDate(
                                $noteUnix
                            );

                            if ($authorName !== '') {
                                $noteLabel .= ($noteLabel !== '' ? ' - ' : '') .
                                    $authorName;
                            }
                            ?>
                            <tr>
                                <th class="note-meta"><?=
                                    escapeOpenApplicationsReportValue(
                                        formatOpenApplicationsReportValue(
                                            $noteLabel
                                        )
                                    )
                                ?></th>
                                <td><?= nl2br(
                                    escapeOpenApplicationsReportValue(
                                        $note['noteText'] ?? ''
                                    )
                                ) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="empty-detail">
                        No Application Notes have been recorded.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

        <?php if ($workflowStages !== []): ?>
            <div class="workflow-section" style="page-break-inside:avoid;">
                <?= renderOpenApplicationsSectionHeading(
                    'Permit Application Process',
                    $rootDir
                ) ?>

                <div class="workflow-introduction">
                    These are the active Stages and Stage-specific Statuses
                    configured in Skyesoft, shown in lifecycle order. Each
                    Application above identifies its current position and next
                    configured Stage. Actual processing may vary by jurisdiction
                    and project requirements.
                </div>

                <table class="workflow-table">
                    <?php foreach ($workflowStages as $workflowStage): ?>
                        <?php
                        // Resolve Stage details
                        $stageDescription = trim((string)(
                            $workflowStage[
                                'applicationStageDescription'
                            ] ?? ''
                        ));
                        $stageStatuses = is_array(
                            $workflowStage['statuses'] ?? null
                        )
                            ? $workflowStage['statuses']
                            : [];
                        ?>

                        <tr>
                            <?= renderOpenApplicationsWorkflowStageCell(
                                $workflowStage,
                                $rootDir
                            ) ?>
                            <td class="workflow-detail">
                                <?php if ($stageDescription !== ''): ?>
                                    <div class="workflow-description">
                                        <?= escapeOpenApplicationsReportValue(
                                            $stageDescription
                                        ) ?>
                                    </div>
                                <?php endif; ?>

                                <?php foreach (
                                    $stageStatuses as $workflowStatus
                                ): ?>
                                    <?php
                                    // Resolve Status description
                                    $statusDescription = trim((string)(
                                        $workflowStatus[
                                            'applicationStatusDescription'
                                        ] ?? ''
                                    ));
                                    ?>

                                    <span class="workflow-status">
                                        <strong><?=
                                            escapeOpenApplicationsReportValue(
                                                $workflowStatus[
                                                    'applicationStatusName'
                                                ]
                                            )
                                        ?></strong><?=
                                            $statusDescription !== ''
                                                ? ' — ' .
                                                    escapeOpenApplicationsReportValue(
                                                        $statusDescription
                                                    )
                                                : ''
                                        ?>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <div class="workflow-reference-subsection">
                    <?= renderOpenApplicationsSectionHeading(
                        'Permit Fees',
                        $rootDir
                    ) ?>

                    <div class="workflow-introduction">
                        These are the Fee categories configured in Skyesoft.
                        Each Application above shows its recorded Fees by
                        category, followed by its dated transactions and running
                        outstanding balance.
                    </div>

                    <table class="workflow-table">
                        <tr>
                            <th>Application Fee</th>
                            <td class="workflow-detail">
                                A jurisdiction charge recorded for Application
                                intake, filing, or initial processing.
                            </td>
                        </tr>
                        <tr>
                            <th>Review Fee</th>
                            <td class="workflow-detail">
                                A jurisdiction charge recorded for plan,
                                document, or substantive Application review.
                            </td>
                        </tr>
                        <tr>
                            <th>Permit Fee</th>
                            <td class="workflow-detail">
                                A jurisdiction charge recorded for Permit
                                approval, issuance, or release.
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>
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
    logOpenApplicationsStatusReportError(
        'Checkpoint 3 — starting report HTML rendering.'
    );

    $pdf->WriteHTML($reportHtml);

    logOpenApplicationsStatusReportError(
        'Checkpoint 4 — report HTML rendered.'
    );

    $pdfFilename = 'Open-Permit-Applications-Status-Report.pdf';
    $pdfContent = $pdf->Output(
        '',
        \Mpdf\Output\Destination::STRING_RETURN
    );

    logOpenApplicationsStatusReportError(
        'Checkpoint 5 — PDF output created.'
    );
} catch (Throwable $exception) {
    logOpenApplicationsStatusReportError(
        sprintf(
            '%s: %s in %s on line %d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        )
    );

    failOpenApplicationsStatusReport(
        'The Open Permit Applications Status PDF could not be generated.',
        500
    );
}

logOpenApplicationsStatusReportError(
    'Checkpoint 6 — recording report Action.'
);

$actionId = $recordReportAction();

if ($actionId <= 0) {
    logOpenApplicationsStatusReportError(
        'Report Action could not be recorded.'
    );

    failOpenApplicationsStatusReport(
        'The report Action could not be recorded.',
        500
    );
}

logOpenApplicationsStatusReportError(
    sprintf(
        'Checkpoint 7 — report Action %d recorded.',
        $actionId
    )
);

logOpenApplicationsStatusReportError(
    sprintf(
        'Checkpoint 8 — sending %d bytes with signature %s.',
        strlen($pdfContent),
        substr($pdfContent, 0, 5)
    )
);

// Remove any output buffered before the PDF response
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Send the completed PDF
header('Content-Type: application/pdf');
header(
    'Content-Disposition: inline; filename="' .
    $pdfFilename .
    '"'
);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

echo $pdfContent;
exit;

// #endregion