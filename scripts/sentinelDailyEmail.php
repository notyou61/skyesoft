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

// Configure GoDaddy local SMTP relay
$smtpHost = 'localhost';
$smtpPort = 25;

// Configure Sentinel sender
$senderEmail = 'steve.skye@skyelighting.com';
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

try {

    // Initialize mPDF
    $mpdf = new Mpdf([
        'format' => 'Letter',
        'orientation' => 'P',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 10,
        'margin_bottom' => 10
    ]);

    // Render full Sentinel report into PDF
    $mpdf->WriteHTML($html);

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

    header('Content-Type: application/pdf');

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

#region SECTION VI — GoDaddy SMTP Transport

// Initialize PHPMailer
$mail = new PHPMailer(true);

// Configure GoDaddy local SMTP relay
$mail->isSMTP();
$mail->Host = $smtpHost;
$mail->Port = $smtpPort;
$mail->SMTPAuth = false;
$mail->SMTPSecure = '';

// Configure SMTP runtime
$mail->Timeout = 15;
$mail->SMTPDebug = 0;
$mail->CharSet = 'UTF-8';

// Configure sender
$mail->setFrom(
    $senderEmail,
    $senderName
);

// Configure reply address
$mail->addReplyTo(
    $senderEmail,
    $senderName
);

// Configure recipient
$mail->addAddress(
    $recipientEmail
);

// Configure Sentinel email
$mail->isHTML(true);
$mail->Subject = $subject;
$mail->Body = $emailHtml;

// Attach detailed Sentinel PDF report
$mail->addStringAttachment(
    $pdfContent,
    $pdfFilename,
    'base64',
    'application/pdf'
);

#endregion

#region SECTION VII — Send Email

// Initialize email execution result
$sendSuccess = false;
$sendError = null;

try {

    // Send Sentinel email through GoDaddy local relay
    $mail->send();

    $sendSuccess = true;

    error_log(
        'SENTINEL EMAIL SUCCESS: Daily report sent to ' .
        $recipientEmail .
        ' at ' .
        date('Y-m-d H:i:s T') .
        '.'
    );

} catch (Exception $exception) {

    // Capture SMTP transport error
    $sendError = $mail->ErrorInfo !== ''
        ? $mail->ErrorInfo
        : $exception->getMessage();

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
        GoDaddy local SMTP relay transport.
    </p>';

    echo '<table style="
        width: 100%;
        margin-top: 24px;
        border-collapse: collapse;
    ">';

    // SMTP host
    echo '<tr>';

    echo '<th style="
        width: 35%;
        padding: 10px;
        text-align: left;
        background: #f8f9fa;
        border: 1px solid #cccccc;
    ">
        SMTP Server
    </th>';

    echo '<td style="
        padding: 10px;
        border: 1px solid #cccccc;
    ">' .
        htmlspecialchars(
            $smtpHost . ':' . $smtpPort,
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
        echo 'SUCCESS — Sentinel report accepted by GoDaddy SMTP relay.';
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
        echo 'SMTP Transport Error';
        echo '</strong>';

        echo '<p style="margin-bottom:0;">';

        echo htmlspecialchars(
            $sendError ?? 'No SMTP error information was returned.',
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