<?php

// #region Microsoft Graph Authentication Test

header('Content-Type: application/json; charset=utf-8');

// #region Environment

// Load secure Microsoft Graph configuration
$envPath = dirname(__DIR__) . '/secure/microsoft.env';

if (!file_exists($envPath)) {
    fail('Microsoft environment file not found.');
}

$envLines = file(
    $envPath,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
);

foreach ($envLines as $envLine) {

    // Ignore comments
    if (strpos(trim($envLine), '#') === 0) {
        continue;
    }

    // Ignore malformed entries
    if (strpos($envLine, '=') === false) {
        continue;
    }

    list($envKey, $envValue) = explode('=', $envLine, 2);

    $envKey   = trim($envKey);
    $envValue = trim($envValue);

    if ($envKey !== '') {
        $_ENV[$envKey] = $envValue;
    }
}

// #endregion

// #region Configuration

// Read Microsoft Graph credentials
$tenantId = $_ENV['SKYESOFT_MS_TENANT_ID'] ?? '';
$clientId = $_ENV['SKYESOFT_MS_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['SKYESOFT_MS_CLIENT_SECRET'] ?? '';
$mailbox = $_ENV['SKYESOFT_MS_MAILBOX'] ?? '';

if (
    $tenantId === '' ||
    $clientId === '' ||
    $clientSecret === '' ||
    $mailbox === ''
) {
    fail('One or more Microsoft Graph environment values are missing.');
}

// #endregion

// #region OAuth Token Request

// Build Microsoft OAuth token endpoint
$tokenUrl =
    'https://login.microsoftonline.com/' .
    rawurlencode($tenantId) .
    '/oauth2/v2.0/token';

// Build client-credentials request
$tokenFields = http_build_query([
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'scope'         => 'https://graph.microsoft.com/.default',
    'grant_type'    => 'client_credentials',
]);

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL            => $tokenUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $tokenFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($curl);
$curlError = curl_error($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

curl_close($curl);

if ($response === false) {
    fail(
        'Microsoft token request failed at the HTTP level: ' .
        $curlError
    );
}

$tokenData = json_decode($response, true);

if (
    $httpCode !== 200 ||
    !is_array($tokenData) ||
    empty($tokenData['access_token'])
) {
    fail(
        'Microsoft rejected the token request.',
        [
            'httpCode' => $httpCode,
            'response' => $tokenData,
        ]
    );
}

// #endregion

// #region Result

// Confirm authentication without exposing the access token
echo json_encode(
    [
        'success' => true,
        'message' => 'Microsoft Graph authentication successful.',
        'mailbox' => $mailbox,
        'tokenType' => $tokenData['token_type'] ?? null,
        'expiresIn' => (int) ($tokenData['expires_in'] ?? 0),
    ],
    JSON_PRETTY_PRINT
);

// #endregion

// #endregion

// #region Helpers

/**
 * Return a controlled test failure.
 *
 * @param string $message Failure message.
 * @param mixed  $details Optional diagnostic details.
 *
 * @return void
 */
function fail($message, $details = null)
{
    $response = [
        'success' => false,
        'message' => $message,
    ];

    if ($details !== null) {
        $response['details'] = $details;
    }

    echo json_encode(
        $response,
        JSON_PRETTY_PRINT
    );

    exit;
}

// #endregion