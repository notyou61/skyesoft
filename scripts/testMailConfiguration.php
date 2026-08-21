<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — testMailConfiguration.php
 *  PHP Local Mail Configuration Diagnostic
 *  Codex-Governed Module • PHP 8.3+
 * ===================================================================== */

#region SECTION I — Environment Setup

// Set Skyesoft reporting timezone (Phoenix, Arizona)
date_default_timezone_set('America/Phoenix');

// Return browser-readable HTML
header('Content-Type: text/html; charset=UTF-8');

#endregion

#region SECTION II — Mail Configuration Inspection

// Read active PHP mail configuration
$sendmailPath = (string) ini_get('sendmail_path');
$smtpHost = (string) ini_get('SMTP');
$smtpPort = (string) ini_get('smtp_port');

// Resolve expected sendmail executable
$sendmailExecutable = '/usr/sbin/sendmail';

// Inspect executable availability
$sendmailExists = file_exists($sendmailExecutable);
$sendmailExecutableStatus = is_executable($sendmailExecutable);

#endregion

#region SECTION III — Diagnostic Rendering

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Skyesoft Mail Configuration Test</title>

    <style>
        body {
            margin: 40px;
            font-family: Arial, Helvetica, sans-serif;
            color: #222;
            background: #fff;
        }

        .report {
            max-width: 850px;
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
            width: 38%;
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
    </style>
</head>

<body>

<div class="report">

    <h1>Skyesoft Mail Configuration Test</h1>

    <p>
        Active PHP local mail configuration from the production server.
    </p>

    <table>

        <tr>
            <th>PHP Version</th>
            <td>
                <?= htmlspecialchars(
                    PHP_VERSION,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>sendmail_path</th>
            <td>
                <?= htmlspecialchars(
                    $sendmailPath !== ''
                        ? $sendmailPath
                        : 'Not Configured',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>Expected Sendmail Executable</th>
            <td><?= $sendmailExecutable ?></td>
        </tr>

        <tr>
            <th>Sendmail Exists</th>
            <td class="<?= $sendmailExists ? 'success' : 'failed' ?>">
                <?= $sendmailExists ? 'YES' : 'NO' ?>
            </td>
        </tr>

        <tr>
            <th>Sendmail Executable</th>
            <td class="<?= $sendmailExecutableStatus ? 'success' : 'failed' ?>">
                <?= $sendmailExecutableStatus ? 'YES' : 'NO' ?>
            </td>
        </tr>

        <tr>
            <th>PHP SMTP Setting</th>
            <td>
                <?= htmlspecialchars(
                    $smtpHost !== ''
                        ? $smtpHost
                        : 'Not Configured',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </td>
        </tr>

        <tr>
            <th>PHP SMTP Port</th>
            <td>
                <?= htmlspecialchars(
                    $smtpPort !== ''
                        ? $smtpPort
                        : 'Not Configured',
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