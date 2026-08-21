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

// Load Skyesoft environment configuration
require_once $rootDir . '/api/utils/envLoader.php';

skyesoftLoadEnv();

// Load PHPMailer runtime classes
require_once $rootDir . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once $rootDir . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once $rootDir . '/vendor/phpmailer/phpmailer/src/SMTP.php';

#endregion

#region SECTION II — Email Configuration

// Load authenticated SMTP configuration
$smtpHost = skyesoftGetEnv('SMTP_HOST');
$smtpPort = skyesoftGetEnv('SMTP_PORT');
$smtpUsername = skyesoftGetEnv('SMTP_USERNAME');
$smtpPassword = skyesoftGetEnv('SMTP_PASSWORD');
$smtpFromEmail = skyesoftGetEnv('SMTP_FROM_EMAIL');
$smtpFromName = skyesoftGetEnv('SMTP_FROM_NAME');

// Define Sentinel report recipient
$recipientEmail = 'steve.skye@skyelighting.com';

// Define transport-test subject
$subject = 'Skyesoft Sentinel Daily Report — SMTP Test';

// Validate required SMTP configuration
$requiredSmtpValues = [
    'SMTP_HOST' => $smtpHost,
    'SMTP_PORT' => $smtpPort,
    'SMTP_USERNAME' => $smtpUsername,
    'SMTP_PASSWORD' => $smtpPassword,
    'SMTP_FROM_EMAIL' => $smtpFromEmail,
    'SMTP_FROM_NAME' => $smtpFromName
];

$missingSmtpValues = [];

foreach ($requiredSmtpValues as $key => $value) {
    if ($value === null || trim($value) === '') {
        $missingSmtpValues[] = $key;
    }
}

if ($missingSmtpValues !== []) {
    error_log(
        'SENTINEL EMAIL ERROR: Missing SMTP environment values: ' .
        implode(', ', $missingSmtpValues)
    );

    http_response_code(500);

    echo '<h2>Sentinel SMTP Configuration Error</h2>';
    echo '<p>Required SMTP configuration is missing.</p>';
    echo '<p>Missing: ' .
        htmlspecialchars(
            implode(', ', $missingSmtpValues),
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</p>';

    exit(1);
}

// Normalize SMTP configuration
$smtpPortNumber = (int) $smtpPort;

#endregion

#region SECTION III — Report Context

// Resolve current Phoenix report time
$reportUnix = time();

$reportDate = date(
    'F j, Y',
    $reportUnix
);

$reportTime = date(
    'g:i A',
    $reportUnix
);

#endregion

#region SECTION IV — HTML Email

$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Skyesoft Sentinel Daily Report</title>
</head>

<body
    style="
        margin: 0;
        padding: 24px;
        background: #ffffff;
        font-family: Arial, Helvetica, sans-serif;
        color: #222222;
    "
>

<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
>
    <tr>
        <td align="center">

            <table
                width="700"
                cellpadding="0"
                cellspacing="0"
                border="0"
                style="
                    width: 700px;
                    max-width: 100%;
                    border-collapse: collapse;
                "
            >

                <tr>
                    <td
                        style="
                            padding: 0 0 8px;
                            border-bottom: 3px solid #14377c;
                        "
                    >
                        <div
                            style="
                                color: #14377c;
                                font-size: 22px;
                                font-weight: bold;
                            "
                        >
                            Skyesoft Sentinel Daily Report
                        </div>

                        <div
                            style="
                                margin-top: 3px;
                                color: #333333;
                                font-size: 15px;
                                font-weight: bold;
                            "
                        >
                            System Governance &amp; Health
                        </div>

                        <div
                            style="
                                margin-top: 3px;
                                color: #666666;
                                font-size: 12px;
                            "
                        >
                            ' . htmlspecialchars(
                                $reportDate,
                                ENT_QUOTES,
                                'UTF-8'
                            ) . '
                            &middot;
                            ' . htmlspecialchars(
                                $reportTime,
                                ENT_QUOTES,
                                'UTF-8'
                            ) . '
                            MST
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top: 24px;">

                        <div
                            style="
                                padding-bottom: 4px;
                                color: #14377c;
                                font-size: 16px;
                                font-weight: bold;
                                border-bottom: 2px solid #14377c;
                            "
                        >
                            Authenticated SMTP Test
                        </div>

                    </td>
                </tr>

                <tr>
                    <td style="padding-top: 8px;">

                        <table
                            width="100%"
                            cellpadding="0"
                            cellspacing="0"
                            border="0"
                            style="
                                border-collapse: collapse;
                            "
                        >

                            <tr>

                                <td
                                    width="32%"
                                    style="
                                        padding: 8px 10px;
                                        border: 1px solid #cccccc;
                                        background: #f8f9fa;
                                        font-weight: bold;
                                    "
                                >
                                    Email System
                                </td>

                                <td
                                    width="68%"
                                    style="
                                        padding: 8px 10px;
                                        border: 1px solid #cccccc;
                                    "
                                >
                                    Microsoft 365 Authenticated SMTP
                                </td>

                            </tr>

                            <tr>

                                <td
                                    style="
                                        padding: 8px 10px;
                                        border: 1px solid #cccccc;
                                        background: #f8f9fa;
                                        font-weight: bold;
                                    "
                                >
                                    SMTP Server
                                </td>

                                <td
                                    style="
                                        padding: 8px 10px;
                                        border: 1px solid #cccccc;
                                    "
                                >
                                    ' . htmlspecialchars(
                                        $smtpHost,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) . '
                                </td>

                            </tr>

                            <tr>

                                <td
                                    style="
                                        padding: 8px 10px;
                                        border: 1px solid #cccccc;
                                        background: #f8f9fa;
                                        font-weight: bold;
                                    "
                                >
                                    Generated
                                </td>

                                <td
                                    style="
                                        padding: 8px 10px;
                                        border: 1px solid #cccccc;
                                    "
                                >
                                    ' . htmlspecialchars(
                                        $reportDate . ' ' . $reportTime . ' MST',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) . '
                                </td>

                            </tr>

                        </table>

                    </td>
                </tr>

                <tr>
                    <td style="padding-top: 16px;">

                        <div
                            style="
                                padding: 10px 12px;
                                background: #f0f4f9;
                                border: 1px solid #b8cbe5;
                                border-left: 4px solid #14377c;
                                font-size: 13px;
                                line-height: 1.4;
                            "
                        >

                            <strong style="color: #14377c;">
                                Sentinel SMTP Test
                            </strong>

                            <br><br>

                            This message confirms that the Skyesoft
                            Sentinel reporting process successfully
                            authenticated with the configured SMTP
                            service and delivered an HTML email.

                        </div>

                    </td>
                </tr>

                <tr>
                    <td
                        style="
                            padding-top: 24px;
                            color: #666666;
                            font-size: 11px;
                        "
                    >

                        <div
                            style="
                                padding-top: 6px;
                                border-top: 1px solid #cccccc;
                            "
                        >
                            Skyesoft Sentinel | Christy Signs
                        </div>

                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
';

#endregion

#region SECTION V — SMTP Transport

// Initialize PHPMailer
$mail = new PHPMailer(true);

// Configure SMTP transport
$mail->isSMTP();

$mail->Host = $smtpHost;
$mail->Port = $smtpPortNumber;

$mail->SMTPAuth = true;

$mail->Username = $smtpUsername;
$mail->Password = $smtpPassword;

// Microsoft 365 SMTP submission uses STARTTLS
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

// Require UTF-8 message encoding
$mail->CharSet = 'UTF-8';

// Disable SMTP debug output for normal execution
$mail->SMTPDebug = 0;

// Define sender
$mail->setFrom(
    $smtpFromEmail,
    $smtpFromName
);

// Define reply address
$mail->addReplyTo(
    $smtpFromEmail,
    $smtpFromName
);

// Define recipient
$mail->addAddress(
    $recipientEmail
);

// Define message format
$mail->isHTML(true);

$mail->Subject = $subject;
$mail->Body = $html;

// Define plain-text fallback
$mail->AltBody =
    'Skyesoft Sentinel Daily Report — SMTP Test' .
    PHP_EOL .
    PHP_EOL .
    'Authenticated SMTP test generated ' .
    $reportDate .
    ' at ' .
    $reportTime .
    ' MST.';

#endregion

#region SECTION VI — Send Email

$smtpSuccess = false;
$smtpError = null;

try {

    // Send authenticated SMTP message
    $mail->send();

    $smtpSuccess = true;

    error_log(
        'SENTINEL EMAIL SUCCESS: Authenticated SMTP message sent to ' .
        $recipientEmail .
        ' at ' .
        $reportDate .
        ' ' .
        $reportTime .
        ' MST.'
    );

} catch (Exception $exception) {

    $smtpError = $mail->ErrorInfo !== ''
        ? $mail->ErrorInfo
        : $exception->getMessage();

    error_log(
        'SENTINEL EMAIL ERROR: SMTP delivery failed: ' .
        $smtpError
    );
}

#endregion

#region SECTION VII — Delivery Diagnostic

// Return browser diagnostic
header('Content-Type: text/html; charset=UTF-8');

http_response_code(
    $smtpSuccess
        ? 200
        : 500
);

echo '<div style="
    max-width: 760px;
    margin: 40px auto;
    font-family: Arial, Helvetica, sans-serif;
    color: #222;
">';

echo '<h1 style="
    margin-bottom: 6px;
    color: #14377c;
">
    Skyesoft Sentinel SMTP Test
</h1>';

echo '<p style="
    margin-top: 0;
    color: #666;
">
    Authenticated Microsoft 365 email transport diagnostic.
</p>';

echo '<table style="
    width: 100%;
    margin-top: 24px;
    border-collapse: collapse;
">';

// Recipient
echo '<tr>';

echo '<th style="
    width: 35%;
    padding: 10px;
    text-align: left;
    background: #f8f9fa;
    border: 1px solid #ccc;
">
    Recipient
</th>';

echo '<td style="
    padding: 10px;
    border: 1px solid #ccc;
">' .
    htmlspecialchars(
        $recipientEmail,
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
    border: 1px solid #ccc;
">
    Sender
</th>';

echo '<td style="
    padding: 10px;
    border: 1px solid #ccc;
">' .
    htmlspecialchars(
        $smtpFromEmail,
        ENT_QUOTES,
        'UTF-8'
    ) .
'</td>';

echo '</tr>';

// SMTP server
echo '<tr>';

echo '<th style="
    padding: 10px;
    text-align: left;
    background: #f8f9fa;
    border: 1px solid #ccc;
">
    SMTP Server
</th>';

echo '<td style="
    padding: 10px;
    border: 1px solid #ccc;
">' .
    htmlspecialchars(
        $smtpHost . ':' . $smtpPortNumber,
        ENT_QUOTES,
        'UTF-8'
    ) .
'</td>';

echo '</tr>';

// Transport result
echo '<tr>';

echo '<th style="
    padding: 10px;
    text-align: left;
    background: #f8f9fa;
    border: 1px solid #ccc;
">
    SMTP Result
</th>';

echo '<td style="
    padding: 10px;
    border: 1px solid #ccc;
    font-weight: bold;
">';

if ($smtpSuccess) {

    echo '<span style="color:#176638;">';
    echo 'SUCCESS — Authenticated SMTP delivery completed.';
    echo '</span>';

} else {

    echo '<span style="color:#a00000;">';
    echo 'FAILED — Authenticated SMTP delivery failed.';
    echo '</span>';

}

echo '</td>';

echo '</tr>';

echo '</table>';

// Result callout
echo '<div style="
    margin-top: 18px;
    padding: 11px 13px;
    background: #f0f4f9;
    border: 1px solid #b8cbe5;
    border-left: 4px solid #14377c;
">';

if ($smtpSuccess) {

    echo '<strong style="color:#14377c;">SMTP Transport Operational</strong>';

    echo '<p style="margin-bottom:0;">';
    echo 'PHPMailer authenticated with the configured SMTP service and ';
    echo 'reported successful message delivery. Check the recipient inbox.';
    echo '</p>';

} else {

    echo '<strong style="color:#a00000;">SMTP Transport Error</strong>';

    echo '<p style="margin-bottom:0;">';

    echo htmlspecialchars(
        $smtpError ?? 'No SMTP error information was returned.',
        ENT_QUOTES,
        'UTF-8'
    );

    echo '</p>';

}

echo '</div>';

echo '</div>';

exit(
    $smtpSuccess
        ? 0
        : 1
);

#endregion