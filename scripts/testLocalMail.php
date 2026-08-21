<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — testLocalMail.php
 *  Local Mail Transport Diagnostic
 *  Codex-Governed Module • PHP 8.3+
 * ===================================================================== */

#region SECTION I — Environment Setup

// Set Skyesoft reporting timezone (Phoenix, Arizona)
date_default_timezone_set('America/Phoenix');

// Return browser-readable HTML
header('Content-Type: text/html; charset=UTF-8');

#endregion

#region SECTION II — Mail Configuration

// Define test recipient
$recipientEmail = 'steve.skye@skyelighting.com';

// Define local transport sender
$senderEmail = 'steve.skye@skyelighting.com';
$senderName = 'Skyesoft Sentinel';

// Define diagnostic message
$subject = 'Skyesoft Local Mail Transport Test';

$reportDate = date('F j, Y');
$reportTime = date('g:i:s A');

#endregion

#region SECTION III — Message Content

// Build HTML diagnostic message
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Skyesoft Local Mail Test</title>
</head>

<body
    style="
        margin: 0;
        padding: 24px;
        font-family: Arial, Helvetica, sans-serif;
        color: #222222;
        background: #ffffff;
    "
>

    <div
        style="
            max-width: 700px;
            margin: 0 auto;
        "
    >

        <h1
            style="
                margin-bottom: 5px;
                color: #14377c;
                font-size: 22px;
            "
        >
            Skyesoft Local Mail Transport Test
        </h1>

        <p
            style="
                margin-top: 0;
                color: #666666;
            "
        >
            GoDaddy local PHP mail() diagnostic.
        </p>

        <div
            style="
                margin-top: 24px;
                padding: 14px;
                background: #f0f4f9;
                border: 1px solid #b8cbe5;
                border-left: 4px solid #14377c;
            "
        >

            <strong style="color: #14377c;">
                Local Mail Test
            </strong>

            <p>
                This message was generated directly by the Skyesoft
                production server using PHP mail().
            </p>

            <p style="margin-bottom: 0;">
                Generated:
                ' . htmlspecialchars(
                    $reportDate . ' at ' . $reportTime . ' MST',
                    ENT_QUOTES,
                    'UTF-8'
                ) . '
            </p>

        </div>

    </div>

</body>
</html>
';

#endregion

#region SECTION IV — Mail Headers

// Build local mail transport headers
$headers = [];

$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/html; charset=UTF-8';

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

// Convert header array for PHP mail()
$headerString = implode(
    "\r\n",
    $headers
);

#endregion

#region SECTION V — Local Mail Test

// Record transport start time
$startTime = microtime(true);

// Submit message to local mail transport
$mailAccepted = mail(
    $recipientEmail,
    $subject,
    $html,
    $headerString
);

// Calculate submission duration
$elapsedSeconds = microtime(true) - $startTime;

#endregion

#region SECTION VI — Diagnostic Rendering

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Skyesoft Local Mail Test</title>

    <style>
        body {
            margin: 40px;
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
            background: #fff;
        }

        .report {
            max-width: 800px;
            margin: 0 auto;
        }

        h1 {
            color: #14377c;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 10px 12px;
            border: 1px solid #ccc;
            text-align: left;
        }

        th {
            width: 35%;
            background: #f3f3f3;
        }

        .success {
            color: #087830;
            font-weight: bold;
        }

        .failed {
            color: #b00000;
            font-weight: bold;
        }

        .notice {
            margin-top: 20px;
            padding: 12px;
            background: #f0f4f9;
            border: 1px solid #b8cbe5;
            border-left: 4px solid #14377c;
        }
    </style>
</head>

<body>

<div class="report">

    <h1>Skyesoft Local Mail Test</h1>

    <p>
        Local PHP mail transport diagnostic from the production server.
    </p>

    <table>

        <tr>
            <th>Transport</th>
            <td>PHP mail() / GoDaddy local mail transport</td>
        </tr>

        <tr>
            <th>Recipient</th>
            <td>
                <?= htmlspecialchars(
                    $recipientEmail,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>Sender</th>
            <td>
                <?= htmlspecialchars(
                    $senderEmail,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>Generated</th>
            <td>
                <?= htmlspecialchars(
                    $reportDate . ' ' . $reportTime . ' MST',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>Submission Time</th>
            <td>
                <?= number_format($elapsedSeconds, 3) ?> seconds
            </td>
        </tr>

        <tr>
            <th>PHP mail() Result</th>

            <td class="<?= $mailAccepted ? 'success' : 'failed' ?>">

                <?= $mailAccepted
                    ? 'TRUE — Message accepted by local mail transport.'
                    : 'FALSE — Local mail transport rejected the message.' ?>

            </td>
        </tr>

    </table>

    <div class="notice">

        <?php if ($mailAccepted): ?>

            <strong>Local Transport Accepted Message</strong>

            <p style="margin-bottom: 0;">
                PHP successfully handed the message to the local
                GoDaddy mail transport. This does not by itself confirm
                final inbox delivery. Check the recipient inbox and
                junk folder.
            </p>

        <?php else: ?>

            <strong>Local Transport Failed</strong>

            <p style="margin-bottom: 0;">
                PHP mail() did not accept the message for local delivery.
            </p>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
<?php

#endregion