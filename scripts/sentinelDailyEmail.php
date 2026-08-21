<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinelDailyEmail.php
 *  Sentinel Daily Email Transport
 *  Codex-Governed Module • PHP 8.3
 * ===================================================================== */

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

#region SECTION I — Environment Setup

// Set Skyesoft reporting timezone (Phoenix, Arizona)
date_default_timezone_set('America/Phoenix');

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

// Configure report subject
$subject = 'Skyesoft Sentinel Daily Report';

#endregion

#region SECTION III — Report Generation

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

    // Capture rendered HTML
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

#region SECTION IV — GoDaddy SMTP Transport

// Initialize PHPMailer
$mail = new PHPMailer(true);

// Configure SMTP transport
$mail->isSMTP();

$mail->Host = $smtpHost;
$mail->Port = $smtpPort;

// GoDaddy local relay does not require authentication
$mail->SMTPAuth = false;

// GoDaddy local relay does not require SMTP encryption
$mail->SMTPSecure = '';

// Limit SMTP connection wait
$mail->Timeout = 15;

// Disable SMTP protocol output during normal execution
$mail->SMTPDebug = 0;

// Require UTF-8 message encoding
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

// Configure HTML message
$mail->isHTML(true);

$mail->Subject = $subject;
$mail->Body = $html;

// Configure plain-text fallback
$mail->AltBody =
    'Skyesoft Sentinel Daily Report' .
    PHP_EOL .
    PHP_EOL .
    'The Sentinel Daily Report was generated successfully. ' .
    'Please view this message using an HTML-capable email client.';

#endregion

#region SECTION V — Send Email

$sendSuccess = false;
$sendError = null;

try {

    // Send Sentinel report through GoDaddy local relay
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

#region SECTION VI — Execution Result

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