<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinelDailyEmail.php
 *  Sentinel Daily Email Transport
 *  Codex-Governed Module • PHP 8.3
 * ===================================================================== */

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

#endregion

#region SECTION II — Email Configuration

// Define Sentinel report recipient
$recipientEmail = 'steve.skye@skyelighting.com';

// Define authenticated Skyelighting sender identity
$senderEmail = 'steve.skye@skyelighting.com';
$senderName = 'Skyesoft Sentinel';

// Define transport-test subject
$subject = 'Skyesoft Sentinel Daily Report — Email Test';

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
                            Email Transport Test
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
                                    Transport test
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
                                Sentinel Email Test
                            </strong>

                            <br><br>

                            This message confirms that the Skyesoft
                            Sentinel reporting process can send an HTML
                            email from the production server.

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

#region SECTION V — Email Headers

$headers = [];

$headers[] =
    'MIME-Version: 1.0';

$headers[] =
    'Content-Type: text/html; charset=UTF-8';

$headers[] =
    'From: ' .
    $senderName .
    ' <' .
    $senderEmail .
    '>';

$headers[] =
    'Reply-To: ' .
    $senderEmail;

$headers[] =
    'X-Mailer: PHP/' .
    phpversion();

$headerString = implode(
    "\r\n",
    $headers
);

#endregion

#region SECTION VI — Send Email

if (
    $recipientEmail === ''
    || $recipientEmail === 'YOUR_EMAIL_ADDRESS_HERE'
) {
    error_log(
        'SENTINEL EMAIL ERROR: Recipient email has not been configured.'
    );

    exit(1);
}

// Send HTML email
$mailSent = mail(
    $recipientEmail,
    $subject,
    $html,
    $headerString
);

if (!$mailSent) {
    error_log(
        'SENTINEL EMAIL ERROR: PHP mail() returned false.'
    );

    exit(1);
}

#endregion

#region SECTION VII — Success Logging

error_log(
    'SENTINEL EMAIL SUCCESS: Test email sent to ' .
    $recipientEmail .
    ' at ' .
    $reportDate .
    ' ' .
    $reportTime .
    ' MST.'
);

exit(0);

#endregion