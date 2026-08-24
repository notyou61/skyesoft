<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — testMicrosoftGraphAttachment.php
 *  Microsoft Graph Sentinel Attachment Diagnostic
 *  Read-Only Diagnostic • PHP 8.3
 * ===================================================================== */

#region SECTION I — Environment Setup

// Set Skyesoft reporting timezone (Phoenix, Arizona)
date_default_timezone_set('America/Phoenix');

// Resolve Skyesoft installation root
$rootDir = realpath(__DIR__);

if ($rootDir === false) {
    outputFailure(
        'Unable to resolve Skyesoft root directory.'
    );
}

// Resolve Microsoft Graph environment file
$msEnvPath = dirname(__DIR__, 2) . '/secure/microsoft.env';

if (!is_file($msEnvPath)) {
    outputFailure(
        'Microsoft Graph environment file not found: ' .
        $msEnvPath
    );
}

// Read Microsoft Graph environment file
$msEnvLines = file(
    $msEnvPath,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
);

if ($msEnvLines === false) {
    outputFailure(
        'Unable to read Microsoft Graph environment file.'
    );
}

// Load Microsoft Graph environment values
foreach ($msEnvLines as $msEnvLine) {

    // Ignore comments
    if (strpos(trim($msEnvLine), '#') === 0) {
        continue;
    }

    // Ignore malformed entries
    if (strpos($msEnvLine, '=') === false) {
        continue;
    }

    [$msEnvKey, $msEnvValue] = explode(
        '=',
        $msEnvLine,
        2
    );

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
    outputFailure(
        'Microsoft Graph configuration is incomplete.'
    );
}

#endregion

#region SECTION II — Microsoft Graph Authentication

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

$tokenError = curl_error(
    $tokenCurl
);

$tokenHttpCode = (int) curl_getinfo(
    $tokenCurl,
    CURLINFO_HTTP_CODE
);

curl_close(
    $tokenCurl
);

if ($tokenResponse === false) {
    outputFailure(
        'Microsoft Graph token request failed: ' .
        $tokenError
    );
}

// Decode Microsoft Graph token response
$tokenData = json_decode(
    $tokenResponse,
    true
);

if (
    $tokenHttpCode !== 200 ||
    !is_array($tokenData) ||
    empty($tokenData['access_token'])
) {
    outputFailure(
        'Microsoft Graph authentication failed. HTTP ' .
        $tokenHttpCode .
        '.'
    );
}

// Resolve Microsoft Graph access token
$accessToken = (string) $tokenData['access_token'];

#endregion

#region SECTION III — Locate Latest Sentinel Message

// Configure Sentinel message subject
$sentinelSubject = 'Skyesoft Sentinel Daily Report';

// Build Sent Items message query
$messageUrl =
    'https://graph.microsoft.com/v1.0/users/' .
    rawurlencode($msMailbox) .
    '/mailFolders/sentitems/messages' .
    '?$select=id,subject,sentDateTime,hasAttachments' .
    '&$orderby=sentDateTime%20desc' .
    '&$top=25';

// Request recent Sent Items messages
$messageResponse = graphGet(
    $messageUrl,
    $accessToken
);

$messageData = json_decode(
    $messageResponse,
    true
);

if (
    !is_array($messageData) ||
    !isset($messageData['value']) ||
    !is_array($messageData['value'])
) {
    outputFailure(
        'Microsoft Graph returned an invalid Sent Items response.'
    );
}

// Initialize matching message
$sentinelMessage = null;

// Locate newest matching Sentinel message
foreach ($messageData['value'] as $message) {

    if (
        isset($message['subject']) &&
        $message['subject'] === $sentinelSubject
    ) {
        $sentinelMessage = $message;
        break;
    }
}

if (!is_array($sentinelMessage)) {
    outputFailure(
        'No Sentinel Daily Report message was found in Sent Items.'
    );
}

// Resolve message ID
$messageId = (string) (
    $sentinelMessage['id'] ?? ''
);

if ($messageId === '') {
    outputFailure(
        'Sentinel message did not contain a Microsoft Graph message ID.'
    );
}

#endregion

#region SECTION IV — Retrieve Sentinel Attachment

// Build attachment request
$attachmentUrl =
    'https://graph.microsoft.com/v1.0/users/' .
    rawurlencode($msMailbox) .
    '/messages/' .
    rawurlencode($messageId) .
    '/attachments';

// Request message attachments
$attachmentResponse = graphGet(
    $attachmentUrl,
    $accessToken
);

$attachmentData = json_decode(
    $attachmentResponse,
    true
);

if (
    !is_array($attachmentData) ||
    !isset($attachmentData['value']) ||
    !is_array($attachmentData['value'])
) {
    outputFailure(
        'Microsoft Graph returned an invalid attachment response.'
    );
}

// Initialize PDF attachment
$pdfAttachment = null;

// Locate Sentinel PDF attachment
foreach ($attachmentData['value'] as $attachment) {

    $attachmentName = strtolower(
        (string) ($attachment['name'] ?? '')
    );

    $attachmentContentType = strtolower(
        (string) ($attachment['contentType'] ?? '')
    );

    if (
        $attachmentContentType === 'application/pdf' ||
        str_ends_with($attachmentName, '.pdf')
    ) {
        $pdfAttachment = $attachment;
        break;
    }
}

if (!is_array($pdfAttachment)) {
    outputFailure(
        'No PDF attachment was found on the Sentinel message.'
    );
}

#endregion

#region SECTION V — Validate Exchange Attachment

// Resolve Exchange attachment properties
$pdfFilename = (string) (
    $pdfAttachment['name'] ?? ''
);

$pdfExchangeSize = (int) (
    $pdfAttachment['size'] ?? 0
);

$pdfContentType = (string) (
    $pdfAttachment['contentType'] ?? ''
);

$pdfContentBytes = (string) (
    $pdfAttachment['contentBytes'] ?? ''
);

if ($pdfContentBytes === '') {
    outputFailure(
        'Microsoft Graph returned no contentBytes for the PDF attachment.'
    );
}

// Decode Exchange attachment
$pdfDecodedContent = base64_decode(
    $pdfContentBytes,
    true
);

if ($pdfDecodedContent === false) {
    outputFailure(
        'Exchange PDF attachment contains invalid Base64 content.'
    );
}

// Calculate attachment diagnostics
$pdfDecodedBytes = strlen(
    $pdfDecodedContent
);

// Determine whether Exchange PDF download was requested
$downloadRequested =
    isset($_GET['download']) &&
    $_GET['download'] === '1';

if ($downloadRequested) {

    // Sanitize Exchange attachment filename
    $downloadFilename = preg_replace(
        '/[^A-Za-z0-9._-]/',
        '_',
        $pdfFilename
    );

    if (
        !is_string($downloadFilename) ||
        $downloadFilename === ''
    ) {
        $downloadFilename =
            'Skyesoft_Sentinel_Daily_Report.pdf';
    }

    // Clear active output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Return exact PDF retrieved from Microsoft Exchange
    header('Content-Type: application/pdf');

    header(
        'Content-Disposition: inline; filename="' .
        $downloadFilename .
        '"'
    );

    header(
        'Content-Length: ' .
        strlen($pdfDecodedContent)
    );

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo $pdfDecodedContent;

    exit;
}

$pdfHeader = substr(
    $pdfDecodedContent,
    0,
    5
);

$pdfTrailer = substr(
    rtrim($pdfDecodedContent),
    -5
);

$pdfHash = hash(
    'sha256',
    $pdfDecodedContent
);

// Determine basic PDF validity
$pdfHeaderValid =
    $pdfHeader === '%PDF-';

$pdfTrailerValid =
    $pdfTrailer === '%%EOF';

#endregion

#region SECTION VI — Diagnostic Result

// Build diagnostic result
$result = [
    'success' => true,

    'message' => [
        'subject' => $sentinelMessage['subject'] ?? null,
        'sentDateTime' => $sentinelMessage['sentDateTime'] ?? null,
        'hasAttachments' =>
            $sentinelMessage['hasAttachments'] ?? null,
    ],

    'attachment' => [
        'filename' => $pdfFilename,
        'contentType' => $pdfContentType,
        'exchangeReportedSize' => $pdfExchangeSize,
        'decodedBytes' => $pdfDecodedBytes,
        'pdfHeader' => $pdfHeader,
        'pdfHeaderValid' => $pdfHeaderValid,
        'pdfTrailer' => $pdfTrailer,
        'pdfTrailerValid' => $pdfTrailerValid,
        'sha256' => $pdfHash,
    ],

    'diagnosis' => (
        $pdfHeaderValid &&
        $pdfTrailerValid
    )
        ? 'Exchange contains a structurally valid PDF attachment.'
        : 'Exchange attachment failed basic PDF structural validation.',
];

// Return diagnostic JSON
header(
    'Content-Type: application/json; charset=UTF-8'
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES
);

exit;

#endregion

#region SECTION VII — Functions

/**
 * Execute authenticated Microsoft Graph GET request.
 */
function graphGet(
    string $url,
    string $accessToken
): string {

    // Initialize Graph request
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    // Execute Graph request
    $response = curl_exec(
        $curl
    );

    $error = curl_error(
        $curl
    );

    $httpCode = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );

    curl_close(
        $curl
    );

    if ($response === false) {
        outputFailure(
            'Microsoft Graph request failed: ' .
            $error
        );
    }

    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {
        outputFailure(
            'Microsoft Graph request returned HTTP ' .
            $httpCode .
            ': ' .
            $response
        );
    }

    return $response;
}

/**
 * Return diagnostic failure response.
 */
function outputFailure(
    string $message
): never {

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    http_response_code(
        500
    );

    echo json_encode(
        [
            'success' => false,
            'message' => $message,
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

#endregion