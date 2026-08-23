<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinelDailyEmail.php
 *  Sentinel Daily Email Transport
 *  Codex-Governed Module • PHP 8.3
 * ===================================================================== */

use Mpdf\Mpdf;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

#region SECTION I — Environment Setup

// Set Skyesoft reporting timezone (Phoenix, Arizona)
date_default_timezone_set('America/Phoenix');

// Determine whether browser preview mode is requested
$isPreviewMode =
    PHP_SAPI !== 'cli' &&
    isset($_GET['preview']) &&
    $_GET['preview'] === '1';

// Determine whether browser PDF preview mode is requested
$isPdfPreviewMode =
    PHP_SAPI !== 'cli' &&
    isset($_GET['pdf']) &&
    $_GET['pdf'] === '1';

// Resolve Skyesoft installation root
$rootDir = realpath(__DIR__ . '/../');

if ($rootDir === false) {
    error_log(
        'SENTINEL EMAIL ERROR: Unable to resolve Skyesoft root directory.'
    );

    exit(1);
}

// Load PHPMailer runtime classes
require_once $rootDir . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once $rootDir . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once $rootDir . '/vendor/phpmailer/phpmailer/src/SMTP.php';

// Resolve Composer autoloader
$composerAutoload = $rootDir . '/vendor/autoload.php';

if (!is_file($composerAutoload)) {
    error_log(
        'SENTINEL EMAIL ERROR: Composer autoloader was not found: ' .
        $composerAutoload
    );

    exit(1);
}

// Load Composer dependencies (mPDF)
require_once $composerAutoload;

#endregion

#region SECTION II — Email Configuration

// Configure Microsoft Graph sender
$senderEmail = 'info@skyelighting.com';
$senderName = 'Skyesoft Sentinel';

// Configure Sentinel recipient
$recipientEmail = 'steve@christysigns.com';

// Configure Sentinel email subject
$subject = 'Skyesoft Sentinel Daily Report';

#endregion

#region SECTION III — Report Generation

// Initialize generated report HTML
$html = '';

// Initialize report values consumed by the email body
$governanceStatus = 'unknown';
$executionStatus = 'unknown';

$unresolvedViolations = 0;
$constitutionalViolations = 0;

$companyNeedsAttention = false;

$siteVersion = 'Unknown';

$lastUpdateUnix = null;
$lastRunUnix = null;

$runCount = 0;

$reportDate = '';
$reportTime = '';

// Resolve Sentinel report script
$reportFile = $rootDir . '/scripts/sentinelDailyReport.php';

if (!is_file($reportFile)) {
    error_log(
        'SENTINEL EMAIL ERROR: Sentinel report file was not found: ' .
        $reportFile
    );

    exit(1);
}

// Start report output capture
ob_start();

try {

    // Render existing Sentinel Daily Report
    require $reportFile;

    // Capture complete rendered report
    $html = ob_get_clean();

} catch (\Throwable $throwable) {

    // Discard incomplete report output
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_log(
        'SENTINEL EMAIL ERROR: Unable to generate Sentinel report: ' .
        $throwable->getMessage()
    );

    exit(1);
}

// Validate generated report
if ($html === false || trim($html) === '') {
    error_log(
        'SENTINEL EMAIL ERROR: Sentinel report generated no HTML output.'
    );

    exit(1);
}

#endregion

#region SECTION IV — Email Body Generation

// Resolve overall Sentinel email status
$emailNeedsAttention =
    $governanceStatus !== 'clean' ||
    $executionStatus !== 'ok' ||
    $unresolvedViolations > 0 ||
    $constitutionalViolations > 0 ||
    $companyNeedsAttention;

// Resolve overall status presentation
if ($emailNeedsAttention) {

    $overallStatus = 'ATTENTION REQUIRED';
    $overallStatusColor = '#8a5a00';
    $overallStatusBackground = '#fff5dc';
    $overallStatusBorder = '#e8c46e';

} else {

    $overallStatus = 'CLEAN';
    $overallStatusColor = '#176638';
    $overallStatusBackground = '#eaf7ef';
    $overallStatusBorder = '#9fd0ae';
}

// Resolve governance presentation
$governanceDisplay = formatGovernanceStatus(
    $governanceStatus
);

// Resolve execution presentation
$executionDisplay = formatExecutionStatus(
    $executionStatus
);

// Resolve database integrity presentation
$databaseIntegrityDisplay = $companyNeedsAttention
    ? 'Needs Attention'
    : 'OK';

// Resolve Sentinel summary
if ($emailNeedsAttention) {

    $sentinelSummary =
        'Sentinel identified one or more governance, execution, or ' .
        'database-integrity conditions requiring review. See the ' .
        'attached Skyesoft Sentinel Daily Report for complete details.';

} else {

    $sentinelSummary =
        'No governance, execution, or database-integrity conditions ' .
        'requiring attention were identified during the latest ' .
        'Sentinel review.';
}

// Resolve email logo
$emailLogoUrl =
    'https://www.skyelighting.com/skyesoft/assets/images/christyLogo.png';

// Build concise Sentinel email body
$emailHtml = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Skyesoft Sentinel Daily Report</title>
</head>

<body
    style="
        margin: 0;
        padding: 24px;
        background: #ffffff;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 14px;
        line-height: 1.35;
        color: #222222;
    "
>

<table
    role="presentation"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="
        width: 100%;
        max-width: 760px;
        margin: 0 auto;
        border-collapse: collapse;
    "
>

    <!-- =============================================================
         Email Header
         ============================================================= -->

    <tr>
        <td>

            <table
                role="presentation"
                cellpadding="0"
                cellspacing="0"
                border="0"
                style="
                    width: 100%;
                    border-collapse: collapse;
                    border-bottom: 3px solid #14377c;
                "
            >
                <tr>

                    <td
                        style="
                            width: 18%;
                            padding: 0 0 8px 0;
                            vertical-align: middle;
                            white-space: nowrap;
                        "
                    >
                        <img
                            src="' . escapeReportValue($emailLogoUrl) . '"
                            width="148"
                            alt="Christy Signs"
                            style="
                                display: block;
                                width: auto;
                                height: 74px;
                                border: 0;
                            "
                        >
                    </td>

                    <td
                        style="
                            width: 82%;
                            padding: 6px 0 6px 14px;
                            vertical-align: middle;
                        "
                    >

                        <table
                            role="presentation"
                            cellpadding="0"
                            cellspacing="0"
                            border="0"
                            style="
                                width: 100%;
                                border-collapse: collapse;
                            "
                        >
                            <tr>

                                <td
                                    style="
                                        padding-left: 14px;
                                        border-left: 1px solid #999999;
                                    "
                                >

                                    <div
                                        style="
                                            margin: 0;
                                            color: #14377c;
                                            font-size: 25px;
                                            font-weight: bold;
                                            line-height: 1;
                                        "
                                    >
                                        Skyesoft Sentinel Daily Report
                                    </div>

                                    <div
                                        style="
                                            margin-top: 2px;
                                            color: #333333;
                                            font-size: 17px;
                                            font-weight: bold;
                                            line-height: 1.05;
                                        "
                                    >
                                        System Governance &amp; Health
                                    </div>

                                    <div
                                        style="
                                            margin-top: 2px;
                                            color: #666666;
                                            font-size: 12px;
                                            line-height: 1.05;
                                        "
                                    >
                                        Report Date:
                                        ' . escapeReportValue($reportDate) . '
                                        &middot;
                                        ' . escapeReportValue($reportTime) . '
                                        MST
                                    </div>

                                </td>

                            </tr>
                        </table>

                    </td>

                </tr>
            </table>

        </td>
    </tr>


    <!-- =============================================================
        System Status
        ============================================================= -->

    <tr>
        <td style="padding-top: 22px;">

            <div
                style="
                    margin-bottom: 7px;
                    padding-bottom: 4px;
                    color: #14377c;
                    font-size: 17px;
                    font-weight: bold;
                    border-bottom: 2px solid #14377c;
                "
            >
                System Status
            </div>

            <table
                role="presentation"
                cellpadding="0"
                cellspacing="0"
                border="0"
                style="
                    width: 100%;
                    border-collapse: collapse;
                "
            >

                <tr>

                    <th
                        style="
                            width: 38%;
                            padding: 9px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            color: #333333;
                            text-align: left;
                        "
                    >
                        Overall Status
                    </th>

                    <td
                        style="
                            padding: 9px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        <span
                            style="
                                display: inline-block;
                                padding: 3px 8px;
                                border: 1px solid ' .
                                    $overallStatusBorder . ';
                                background: ' .
                                    $overallStatusBackground . ';
                                color: ' .
                                    $overallStatusColor . ';
                                font-size: 12px;
                                font-weight: bold;
                            "
                        >
                            ' . escapeReportValue($overallStatus) . '
                        </span>
                    </td>

                </tr>

                <tr>

                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Governance
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        ' . escapeReportValue($governanceDisplay) . '
                    </td>

                </tr>

                <tr>

                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Sentinel Execution
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        ' . escapeReportValue($executionDisplay) . '
                    </td>

                </tr>

                <tr>

                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Database Integrity
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        ' . escapeReportValue(
                            $databaseIntegrityDisplay
                        ) . '
                    </td>

                </tr>

                <!-- Jurisdiction currency placeholder -->
                <tr>

                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Jurisdiction Currency
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        <span
                            style="
                                display: inline-block;
                                padding: 2px 6px;
                                border: 1px solid #e8c46e;
                                background: #fff5dc;
                                color: #8a5a00;
                                font-size: 11px;
                                font-weight: bold;
                                line-height: 1;
                                white-space: nowrap;
                            "
                        >
                            Currency Check Pending
                        </span>
                    </td>

                </tr>

                <tr>

                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Unresolved Violations
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        <strong>' .
                            number_format($unresolvedViolations) .
                        '</strong>
                    </td>

                </tr>

                <tr>

                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Constitutional Violations
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        <strong>' .
                            number_format($constitutionalViolations) .
                        '</strong>
                    </td>

                </tr>

            </table>

        </td>
    </tr>


    <!-- =============================================================
         System Details
         ============================================================= -->

    <tr>
        <td style="padding-top: 22px;">

            <div
                style="
                    margin-bottom: 7px;
                    padding-bottom: 4px;
                    color: #14377c;
                    font-size: 17px;
                    font-weight: bold;
                    border-bottom: 2px solid #14377c;
                "
            >
                System Details
            </div>

            <table
                role="presentation"
                cellpadding="0"
                cellspacing="0"
                border="0"
                style="
                    width: 100%;
                    border-collapse: collapse;
                "
            >

                <tr>
                    <th
                        style="
                            width: 38%;
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Skyesoft Version
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        <strong>' .
                            escapeReportValue($siteVersion) .
                        '</strong>
                    </td>
                </tr>

                <tr>
                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Version Age
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        ' . escapeReportValue(
                            formatElapsedTime($lastUpdateUnix)
                        ) . '
                    </td>
                </tr>

                <tr>
                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Last Sentinel Run
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        ' . escapeReportValue(
                            formatUnixDate($lastRunUnix)
                        ) . '
                    </td>
                </tr>

                <tr>
                    <th
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                            background: #f8f9fa;
                            text-align: left;
                        "
                    >
                        Total Runs
                    </th>

                    <td
                        style="
                            padding: 8px 10px;
                            border: 1px solid #cccccc;
                        "
                    >
                        <strong>' .
                            number_format($runCount) .
                        '</strong>
                    </td>
                </tr>

            </table>

        </td>
    </tr>


    <!-- =============================================================
         Sentinel Summary
         ============================================================= -->

    <tr>
        <td style="padding-top: 22px;">

            <div
                style="
                    padding: 10px 12px;
                    background: #f0f4f9;
                    border: 1px solid #b8cbe5;
                    border-left: 4px solid #14377c;
                "
            >

                <div
                    style="
                        margin-bottom: 4px;
                        color: #14377c;
                        font-size: 14px;
                        font-weight: bold;
                    "
                >
                    Sentinel Summary
                </div>

                <div
                    style="
                        color: #333333;
                        font-size: 13px;
                        line-height: 1.4;
                    "
                >
                    ' . escapeReportValue($sentinelSummary) . '
                </div>

            </div>

        </td>
    </tr>


    <!-- =============================================================
         Email Footer
         ============================================================= -->

    <tr>
        <td
            style="
                padding-top: 26px;
                color: #666666;
                font-size: 12px;
            "
        >

            <table
                role="presentation"
                cellpadding="0"
                cellspacing="0"
                border="0"
                style="
                    width: 100%;
                    border-collapse: collapse;
                    border-top: 1px solid #cccccc;
                "
            >
                <tr>

                    <td
                        style="
                            width: 70%;
                            padding-top: 7px;
                        "
                    >
                        Prepared by Steve Skye | Christy Signs
                    </td>

                    <td
                        style="
                            width: 30%;
                            padding-top: 7px;
                            text-align: right;
                        "
                    >
                        Skyesoft Sentinel
                    </td>

                </tr>
            </table>

        </td>
    </tr>

</table>

</body>
</html>
';

// Validate generated email body
if (trim($emailHtml) === '') {
    error_log(
        'SENTINEL EMAIL ERROR: Sentinel email generated no HTML output.'
    );

    exit(1);
}

// Render email body without sending in preview mode
if ($isPreviewMode) {

    header('Content-Type: text/html; charset=UTF-8');

    echo $emailHtml;

    exit;
}

#endregion

#region SECTION V — PDF Attachment Generation

// Initialize PDF attachment values
$pdfContent = '';

$pdfFilename =
    'Skyesoft_Sentinel_Daily_Report_' .
    date('Y-m-d') .
    '.pdf';

// Configure fixed PDF header
$pdfHeader = '
<table
    style="
        width: 100%;
        margin: 0;
        border-collapse: collapse;
        border-bottom: 2px solid #14377c;
        font-family: Arial, Helvetica, sans-serif;
    "
>
    <tr>

        <td
            style="
                width: 17%;
                padding: 0 8px 4px 0;
                text-align: left;
                vertical-align: middle;
            "
        >
            <img
                src="' . htmlspecialchars(
                    $logoUrl,
                    ENT_QUOTES,
                    'UTF-8'
                ) . '"
                style="
                    display: block;
                    width: auto;
                    height: 54px;
                    margin: 0;
                    border: 0;
                "
                alt="Christy Signs"
            >
        </td>

        <td
            style="
                width: 83%;
                padding: 6px 0 6px 10px;
                text-align: left;
                vertical-align: middle;
            "
        >

            <div
                style="
                    margin: 0;
                    color: #14377c;
                    font-size: 16pt;
                    font-weight: bold;
                    line-height: 1;
                    text-align: left;
                "
            >
                Skyesoft Sentinel Daily Report
            </div>

            <div
                style="
                    margin: 1px 0 0;
                    color: #222222;
                    font-size: 9pt;
                    font-weight: bold;
                    line-height: 1;
                    text-align: left;
                "
            >
                System Governance &amp; Health
            </div>

            <div
                style="
                    margin: 1px 0 0;
                    color: #555555;
                    font-size: 7pt;
                    line-height: 1;
                    text-align: left;
                "
            >
                Report Date:
                ' . htmlspecialchars(
                    $reportDate,
                    ENT_QUOTES,
                    'UTF-8'
                ) . '
                &nbsp;&middot;&nbsp;
                ' . htmlspecialchars(
                    $reportTime,
                    ENT_QUOTES,
                    'UTF-8'
                ) . '
                MST
            </div>

        </td>

    </tr>
</table>
';

// Configure fixed PDF footer
$pdfFooter = '
<table
    style="
        width: 100%;
        border-top: 1px solid #cccccc;
        border-collapse: collapse;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 7pt;
        color: #666666;
    "
>
    <tr>

        <td
            style="
                width: 60%;
                padding-top: 3px;
                text-align: left;
                vertical-align: top;
            "
        >
            Prepared by Steve Skye | Christy Signs
        </td>

        <td
            style="
                width: 40%;
                padding-top: 3px;
                text-align: right;
                vertical-align: top;
            "
        >
            Skyesoft Sentinel | Page {PAGENO} of {nbpg}
        </td>

    </tr>
</table>
';

// Configure compact PDF body styling
$pdfCss = '

    body {
        margin: 0;
        padding: 0;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 7.5pt;
        line-height: 1.12;
        color: #222222;
        background: #ffffff;
    }

    .report {
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0;
    }

    /* Remove report HTML header */
    .header-table {
        display: none;
    }

    /* Keep complete sections together */
    .section-block {
        margin-top: 8px;
        page-break-inside: avoid;
    }

    .section-heading {
        margin-bottom: 3px;
        padding-bottom: 2px;
        color: #14377c;
        font-size: 9pt;
        font-weight: bold;
        line-height: 1;
        border-bottom: 1px solid #14377c;
        page-break-after: avoid;
    }

    .section-heading-title {
        display: inline-block;
        vertical-align: middle;
    }

    /* Tables */
    .data-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
    }

    .data-table th,
    .data-table td {
        padding: 2px 4px;
        border: 1px solid #cccccc;
        font-size: 7pt;
        line-height: 1.08;
        vertical-align: top;
    }

    .data-table th {
        width: 38%;
        color: #333333;
        font-weight: bold;
        text-align: left;
        background: #f8f9fa;
    }

    .data-table td {
        width: 62%;
        background: #ffffff;
    }

    /* Status */
    .status {
        padding: 1px 4px;
        font-size: 6.5pt;
        line-height: 1;
    }

    /* Callouts */
    .callout-box {
        margin: 4px 0;
        padding: 4px 6px;
        background: #f0f4f9;
        border: 1px solid #b8cbe5;
        border-left: 3px solid #14377c;
        page-break-inside: avoid;
    }

    .callout-title {
        margin-bottom: 2px;
        color: #14377c;
        font-size: 7.5pt;
        font-weight: bold;
    }

    .callout-body {
        font-size: 6.8pt;
        line-height: 1.12;
    }

    /* Remove report HTML footer */
    .report-footer {
        display: none;
    }

';

try {

    // Initialize mPDF with fixed page geometry
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'Letter',
        'orientation' => 'P',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 28,
        'margin_bottom' => 13,
        'margin_header' => 5,
        'margin_footer' => 5
    ]);

    // Configure PDF metadata
    $mpdf->SetTitle(
        'Skyesoft Sentinel Daily Report'
    );

    $mpdf->SetAuthor(
        'Steve Skye'
    );

    // Configure fixed page header
    $mpdf->SetHTMLHeader(
        $pdfHeader
    );

    // Configure fixed page footer
    $mpdf->SetHTMLFooter(
        $pdfFooter
    );

    // Load PDF-specific stylesheet
    $mpdf->WriteHTML(
        $pdfCss,
        \Mpdf\HTMLParserMode::HEADER_CSS
    );

    // Extract report body from complete HTML document
    $pdfHtml = $html;

    if (
        preg_match(
            '/<body\b[^>]*>(.*?)<\/body>/is',
            $html,
            $bodyMatch
        ) === 1
    ) {
        $pdfHtml = $bodyMatch[1];
    }

    // Remove canonical HTML report header from PDF body
    $pdfHtml = preg_replace(
        '/<table\b[^>]*class=["\'][^"\']*\bheader-table\b[^"\']*["\'][^>]*>.*?<\/table>/is',
        '',
        $pdfHtml,
        1
    ) ?? $pdfHtml;

    // Remove canonical HTML report footer from PDF body
    $pdfHtml = preg_replace(
        '/<div\b[^>]*class=["\'][^"\']*\breport-footer\b[^"\']*["\'][^>]*>.*?<\/div>/is',
        '',
        $pdfHtml,
        1
    ) ?? $pdfHtml;

    // Render body content within fixed page geometry
    $mpdf->WriteHTML(
        $pdfHtml,
        \Mpdf\HTMLParserMode::HTML_BODY
    );

    // Capture PDF in memory
    $pdfContent = $mpdf->Output(
        '',
        'S'
    );

} catch (\Throwable $throwable) {

    error_log(
        'SENTINEL EMAIL ERROR: Unable to generate PDF attachment: ' .
        $throwable->getMessage()
    );

    exit(1);
}

// Validate generated PDF attachment
if ($pdfContent === '') {
    error_log(
        'SENTINEL EMAIL ERROR: PDF attachment generated no content.'
    );

    exit(1);
}

// Render PDF without sending email in PDF preview mode
if ($isPdfPreviewMode) {

    header(
        'Content-Type: application/pdf'
    );

    header(
        'Content-Disposition: inline; filename="' .
        $pdfFilename .
        '"'
    );

    header(
        'Content-Length: ' .
        strlen($pdfContent)
    );

    echo $pdfContent;

    exit;
}

#endregion

#region SECTION VI — Microsoft Graph Transport

// Load Microsoft Graph environment from /secure (cPanel-safe, absolute anchor)
$msEnvPath = dirname(__DIR__, 3) . '/secure/microsoft.env';

if (!file_exists($msEnvPath)) {
    throw new RuntimeException(
        'Microsoft Graph environment file not found.'
    );
}

// Read Microsoft Graph environment file
$msEnvLines = file(
    $msEnvPath,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
);

foreach ($msEnvLines as $msEnvLine) {

    // Ignore comments
    if (strpos(trim($msEnvLine), '#') === 0) {
        continue;
    }

    // Ignore malformed entries
    if (strpos($msEnvLine, '=') === false) {
        continue;
    }

    list($msEnvKey, $msEnvValue) = explode('=', $msEnvLine, 2);

    $msEnvKey = trim($msEnvKey);
    $msEnvValue = trim($msEnvValue);

    if ($msEnvKey !== '') {
        $_ENV[$msEnvKey] = $msEnvValue;
    }
}

// Read Microsoft Graph credentials
$msTenantId = $_ENV['SKYESOFT_MS_TENANT_ID'] ?? '';
$msClientId = $_ENV['SKYESOFT_MS_CLIENT_ID'] ?? '';
$msClientSecret = $_ENV['SKYESOFT_MS_CLIENT_SECRET'] ?? '';
$msMailbox = $_ENV['SKYESOFT_MS_MAILBOX'] ?? '';

if (
    $msTenantId === '' ||
    $msClientId === '' ||
    $msClientSecret === '' ||
    $msMailbox === ''
) {
    throw new RuntimeException(
        'Microsoft Graph configuration is incomplete.'
    );
}

// Build Microsoft OAuth token endpoint
$tokenUrl =
    'https://login.microsoftonline.com/' .
    rawurlencode($msTenantId) .
    '/oauth2/v2.0/token';

// Build client credentials request
$tokenFields = http_build_query([
    'client_id'     => $msClientId,
    'client_secret' => $msClientSecret,
    'scope'         => 'https://graph.microsoft.com/.default',
    'grant_type'    => 'client_credentials',
]);

// Request Microsoft Graph access token
$tokenCurl = curl_init();

curl_setopt_array($tokenCurl, [
    CURLOPT_URL            => $tokenUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $tokenFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$tokenResponse = curl_exec($tokenCurl);
$tokenError = curl_error($tokenCurl);
$tokenHttpCode = (int) curl_getinfo(
    $tokenCurl,
    CURLINFO_HTTP_CODE
);

curl_close($tokenCurl);

if ($tokenResponse === false) {
    throw new RuntimeException(
        'Microsoft Graph token request failed: ' .
        $tokenError
    );
}

// Decode Microsoft Graph token response
$tokenData = json_decode($tokenResponse, true);

if (
    $tokenHttpCode !== 200 ||
    !is_array($tokenData) ||
    empty($tokenData['access_token'])
) {
    throw new RuntimeException(
        'Microsoft Graph authentication failed.'
    );
}

$accessToken = $tokenData['access_token'];

// Build Microsoft Graph email payload
$mailPayload = [
    'message' => [
        'subject' => $subject,
        'body' => [
            'contentType' => 'HTML',
            'content'     => $emailHtml,
        ],
        'toRecipients' => [
            [
                'emailAddress' => [
                    'address' => $recipientEmail,
                ],
            ],
        ],
        'attachments' => [
            [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name'        => $pdfFilename,
                'contentType' => 'application/pdf',
                'contentBytes'=> base64_encode($pdfContent),
            ],
        ],
    ],
    'saveToSentItems' => true,
];

// Encode Microsoft Graph email payload
$mailJson = json_encode($mailPayload);

if ($mailJson === false) {
    throw new RuntimeException(
        'Unable to encode Microsoft Graph email payload.'
    );
}

// Build Microsoft Graph sendMail endpoint
$sendMailUrl =
    'https://graph.microsoft.com/v1.0/users/' .
    rawurlencode($msMailbox) .
    '/sendMail';

// Send Sentinel report through Microsoft Graph
$mailCurl = curl_init();

curl_setopt_array($mailCurl, [
    CURLOPT_URL            => $sendMailUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $mailJson,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$mailResponse = curl_exec($mailCurl);
$mailError = curl_error($mailCurl);
$mailHttpCode = (int) curl_getinfo(
    $mailCurl,
    CURLINFO_HTTP_CODE
);

curl_close($mailCurl);

if ($mailResponse === false) {
    throw new RuntimeException(
        'Microsoft Graph sendMail request failed: ' .
        $mailError
    );
}

// Microsoft Graph sendMail returns HTTP 202 when accepted
if ($mailHttpCode !== 202) {
    throw new RuntimeException(
        'Microsoft Graph rejected the Sentinel email. HTTP ' .
        $mailHttpCode .
        ': ' .
        $mailResponse
    );
}

#endregion

#region SECTION VII — Email Delivery Result

// Initialize email execution result
$sendSuccess = false;
$sendError = null;

try {

    // Confirm Microsoft Graph accepted Sentinel email
    if ($mailHttpCode !== 202) {
        throw new RuntimeException(
            'Microsoft Graph did not accept the Sentinel email.'
        );
    }

    // Record successful delivery submission
    $sendSuccess = true;

    error_log(
        'SENTINEL EMAIL SUCCESS: Daily report sent through Microsoft Graph to ' .
        $recipientEmail .
        ' from ' .
        $senderEmail .
        ' at ' .
        date('Y-m-d H:i:s T') .
        '.'
    );

} catch (Throwable $throwable) {

    // Capture Microsoft Graph transport error
    $sendError = $throwable->getMessage();

    error_log(
        'SENTINEL EMAIL ERROR: Daily report delivery failed: ' .
        $sendError
    );
}

#endregion

#region SECTION VIII — Execution Result

// Determine whether execution is browser-based
$isBrowserRequest = PHP_SAPI !== 'cli';

if ($isBrowserRequest) {

    // Return browser diagnostic status
    header('Content-Type: text/html; charset=UTF-8');

    http_response_code(
        $sendSuccess
            ? 200
            : 500
    );

    echo '<div style="
        max-width: 760px;
        margin: 40px auto;
        font-family: Arial, Helvetica, sans-serif;
        color: #222222;
    ">';

    echo '<h1 style="
        margin-bottom: 6px;
        color: #14377c;
    ">
        Skyesoft Sentinel Daily Email
    </h1>';

    echo '<p style="
        margin-top: 0;
        color: #666666;
    ">
        Microsoft Graph application transport.
    </p>';

    echo '<table style="
        width: 100%;
        margin-top: 24px;
        border-collapse: collapse;
    ">';

    // Transport
    echo '<tr>';

    echo '<th style="
        width: 35%;
        padding: 10px;
        text-align: left;
        background: #f8f9fa;
        border: 1px solid #cccccc;
    ">
        Transport
    </th>';

    echo '<td style="
        padding: 10px;
        border: 1px solid #cccccc;
    ">
        Microsoft Graph
    </td>';

    echo '</tr>';

    // Mailbox
    echo '<tr>';

    echo '<th style="
        padding: 10px;
        text-align: left;
        background: #f8f9fa;
        border: 1px solid #cccccc;
    ">
        Microsoft 365 Mailbox
    </th>';

    echo '<td style="
        padding: 10px;
        border: 1px solid #cccccc;
    ">' .
        htmlspecialchars(
            $msMailbox,
            ENT_QUOTES,
            'UTF-8'
        ) .
    '</td>';

    echo '</tr>';

    // Sender
    echo '<tr>';

    echo '<th style="
        padding: 10px;
        text-align: left;
        background: #f8f9fa;
        border: 1px solid #cccccc;
    ">
        Sender
    </th>';

    echo '<td style="
        padding: 10px;
        border: 1px solid #cccccc;
    ">' .
        htmlspecialchars(
            $senderEmail,
            ENT_QUOTES,
            'UTF-8'
        ) .
    '</td>';

    echo '</tr>';

    // Recipient
    echo '<tr>';

    echo '<th style="
        padding: 10px;
        text-align: left;
        background: #f8f9fa;
        border: 1px solid #cccccc;
    ">
        Recipient
    </th>';

    echo '<td style="
        padding: 10px;
        border: 1px solid #cccccc;
    ">' .
        htmlspecialchars(
            $recipientEmail,
            ENT_QUOTES,
            'UTF-8'
        ) .
    '</td>';

    echo '</tr>';

    // HTTP response
    echo '<tr>';

    echo '<th style="
        padding: 10px;
        text-align: left;
        background: #f8f9fa;
        border: 1px solid #cccccc;
    ">
        Graph Response
    </th>';

    echo '<td style="
        padding: 10px;
        border: 1px solid #cccccc;
    ">';

    echo htmlspecialchars(
        'HTTP ' . $mailHttpCode,
        ENT_QUOTES,
        'UTF-8'
    );

    echo '</td>';

    echo '</tr>';

    // Result
    echo '<tr>';

    echo '<th style="
        padding: 10px;
        text-align: left;
        background: #f8f9fa;
        border: 1px solid #cccccc;
    ">
        Result
    </th>';

    echo '<td style="
        padding: 10px;
        border: 1px solid #cccccc;
        font-weight: bold;
    ">';

    if ($sendSuccess) {

        echo '<span style="color:#176638;">';
        echo 'SUCCESS — Sentinel report accepted by Microsoft Graph.';
        echo '</span>';

    } else {

        echo '<span style="color:#a00000;">';
        echo 'FAILED — Sentinel report delivery failed.';
        echo '</span>';

    }

    echo '</td>';

    echo '</tr>';

    echo '</table>';

    // Display transport error when applicable
    if (!$sendSuccess) {

        echo '<div style="
            margin-top: 18px;
            padding: 11px 13px;
            background: #fff5f5;
            border: 1px solid #ddb8b8;
            border-left: 4px solid #a00000;
        ">';

        echo '<strong style="color:#a00000;">';
        echo 'Microsoft Graph Transport Error';
        echo '</strong>';

        echo '<p style="margin-bottom:0;">';

        echo htmlspecialchars(
            $sendError ??
                'No Microsoft Graph error information was returned.',
            ENT_QUOTES,
            'UTF-8'
        );

        echo '</p>';

        echo '</div>';
    }

    echo '</div>';
}

// Return execution status
exit(
    $sendSuccess
        ? 0
        : 1
);

#endregion