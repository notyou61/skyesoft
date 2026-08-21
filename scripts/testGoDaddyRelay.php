<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — testGoDaddyRelay.php
 *  GoDaddy Local SMTP Relay Diagnostic
 * ===================================================================== */

#region SECTION I — Environment Setup

// Set Skyesoft timezone
date_default_timezone_set('America/Phoenix');

// Load PHPMailer classes directly
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

#endregion

#region SECTION II — Test Configuration

// Configure test addresses
$senderEmail = 'steve.skye@skyelighting.com';
$recipientEmail = 'steve.skye.skyelighting@gmail.com';

// Initialize result
$success = false;
$errorMessage = '';

#endregion

#region SECTION III — GoDaddy SMTP Relay Test

try {

    // Initialize PHPMailer
    $mail = new PHPMailer(true);

    // Enable SMTP protocol diagnostics
    $mail->SMTPDebug = 2;

    // Render SMTP conversation for diagnostic review
    $mail->Debugoutput = function ($message, $level) {

        echo '<pre style="white-space: pre-wrap;">';
        echo htmlspecialchars(
            'SMTP [' . $level . '] ' . $message,
            ENT_QUOTES,
            'UTF-8'
        );
        echo '</pre>';
    };

    // Use GoDaddy local SMTP relay
    $mail->isSMTP();
    $mail->Host = 'localhost';
    $mail->Port = 25;

    // GoDaddy local relay does not use authentication
    $mail->SMTPAuth = false;
    $mail->SMTPSecure = '';

    // Set timeout
    $mail->Timeout = 15;

    // Configure sender and recipient
    $mail->setFrom($senderEmail, 'Skyesoft Sentinel');
    $mail->addAddress($recipientEmail);

    // Configure message
    $mail->Subject = 'Skyesoft GoDaddy SMTP Relay Test';
    $mail->Body =
        "Skyesoft GoDaddy SMTP relay test.\n\n" .
        "Generated: " .
        date('F j, Y g:i:s A T');

    // Send test message
    $mail->send();

    // Record success
    $success = true;

} catch (Exception $exception) {

    // Capture PHPMailer transport error
    $errorMessage = $mail->ErrorInfo ?? $exception->getMessage();
}

#endregion

#region SECTION IV — Diagnostic Output

header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Skyesoft GoDaddy Relay Test</title>
</head>

<body style="font-family: Arial, sans-serif; margin: 40px;">

    <h1>Skyesoft GoDaddy SMTP Relay Test</h1>

    <p><strong>SMTP Host:</strong> localhost</p>
    <p><strong>SMTP Port:</strong> 25</p>
    <p><strong>Authentication:</strong> Off</p>
    <p><strong>Encryption:</strong> Off</p>
    <p><strong>Sender:</strong> <?= htmlspecialchars($senderEmail) ?></p>
    <p><strong>Recipient:</strong> <?= htmlspecialchars($recipientEmail) ?></p>

    <?php if ($success): ?>

        <h2 style="color: green;">
            SUCCESS — GoDaddy SMTP relay accepted the message.
        </h2>

    <?php else: ?>

        <h2 style="color: red;">
            FAILED — GoDaddy SMTP relay did not accept the message.
        </h2>

        <p>
            <strong>Error:</strong>
            <?= htmlspecialchars($errorMessage) ?>
        </p>

    <?php endif; ?>

</body>
</html>
<?php

#endregion