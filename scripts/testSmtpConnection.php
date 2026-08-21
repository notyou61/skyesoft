<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — testSmtpConnection.php
 *  SMTP Network Connectivity Diagnostic
 *  Codex-Governed Module • PHP 8.3+
 * ===================================================================== */

#region SECTION I — Configuration

// Set Skyesoft reporting timezone (Phoenix, Arizona)
date_default_timezone_set('America/Phoenix');

// Define SMTP connection target
$smtpHost = 'smtp.office365.com';
$smtpPort = 587;
$timeoutSeconds = 15;

#endregion

#region SECTION II — Connection Test

// Initialize connection diagnostics
$errorNumber = 0;
$errorMessage = '';
$startTime = microtime(true);

// Attempt raw TCP connection to Microsoft SMTP
$socket = @fsockopen(
    $smtpHost,
    $smtpPort,
    $errorNumber,
    $errorMessage,
    $timeoutSeconds
);

// Calculate connection duration
$elapsedSeconds = microtime(true) - $startTime;

// Determine connection result
$connectionSuccessful = is_resource($socket);

// Close successful test connection
if ($connectionSuccessful) {
    fclose($socket);
}

#endregion

#region SECTION III — Report Rendering

header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Skyesoft SMTP Connection Test</title>

    <style>
        body {
            margin: 40px;
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
        }

        .report {
            max-width: 800px;
            margin: 0 auto;
        }

        h1 {
            color: #123d82;
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
            font-weight: bold;
            color: #087830;
        }

        .failed {
            font-weight: bold;
            color: #b00000;
        }
    </style>
</head>

<body>

<div class="report">

    <h1>Skyesoft SMTP Connection Test</h1>

    <p>
        Raw TCP connectivity test from the Skyesoft production server.
    </p>

    <table>
        <tr>
            <th>SMTP Host</th>
            <td><?= htmlspecialchars($smtpHost, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>

        <tr>
            <th>SMTP Port</th>
            <td><?= $smtpPort ?></td>
        </tr>

        <tr>
            <th>Timeout</th>
            <td><?= $timeoutSeconds ?> seconds</td>
        </tr>

        <tr>
            <th>Connection Time</th>
            <td><?= number_format($elapsedSeconds, 3) ?> seconds</td>
        </tr>

        <tr>
            <th>Result</th>
            <td class="<?= $connectionSuccessful ? 'success' : 'failed' ?>">
                <?= $connectionSuccessful
                    ? 'SUCCESS — TCP connection established.'
                    : 'FAILED — TCP connection could not be established.' ?>
            </td>
        </tr>

        <tr>
            <th>Error Number</th>
            <td><?= $errorNumber ?></td>
        </tr>

        <tr>
            <th>Error Message</th>
            <td>
                <?= htmlspecialchars(
                    $errorMessage !== ''
                        ? $errorMessage
                        : 'None',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>
    </table>

</div>

</body>
</html>
<?php

#endregion