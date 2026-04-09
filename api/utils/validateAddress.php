<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — Address Validation Layer (USPS / Smarty)
//  Version: 1.1.0
//  Last Updated: 2026-04-09
//  Codex Tier: 2 — Validation Enforcement
//
//  Role:
//  Validates and normalizes U.S. addresses using USPS CASS-certified data
//  via Smarty API before location resolution.
//
//  Purpose:
//   • Ensure address is deliverable (real-world valid)
//   • Prevent insertion of fabricated or interpolated addresses
//   • Standardize address format prior to downstream processing
//
//  Behavior:
//   • Rejects request if USPS cannot validate address
//   • Returns normalized address components when valid
//   • Overwrites parsed location fields with USPS-standardized values
//
//  Inputs:
//   • location.address (street)
//   • location.city
//   • location.state
//   • location.zip (optional)
//
//  Outputs:
//   • validation.valid (bool)
//   • validation.normalized (standardized address fields)
//
//  Enforcement Rules:
//   • REQUIRED for all U.S. addresses outside GIS-enforced jurisdictions
//   • Acts as primary validation layer when parcel data is unavailable
//
//  Downstream Impact:
//   • Ensures resolveLocation() receives clean, standardized input
//   • Reduces false positives from Google geocoding
//   • Supports consistent database normalization
//
//  Failure Mode:
//   • Returns:
//       status: reject
//       reason: USPS validation failure
//   • Terminates pipeline (no partial persistence allowed)
//
//  Notes:
//   • USPS validation ≠ parcel validation
//   • Parcel enforcement (e.g., Maricopa County) occurs AFTER this step
//   • This layer is authoritative for address existence, not land ownership
//
// ======================================================================

#region SECTION X — Address Validation (USPS / CASS)

require_once __DIR__ . '/envLoader.php';

function validateAddressUSPS(array $input): array
{
    #region ENV LOAD

    skyesoftLoadEnv();

    $authId    = skyesoftGetEnv('SMARTY_AUTH_ID');
    $authToken = skyesoftGetEnv('SMARTY_AUTH_TOKEN');

    if (!$authId || !$authToken) {
        throw new RuntimeException('Smarty credentials missing.');
    }

    #endregion

    #region INPUT NORMALIZATION

    $street = trim((string)($input['address'] ?? ''));
    $city   = trim((string)($input['city'] ?? ''));
    $state  = trim((string)($input['state'] ?? ''));
    $zip    = trim((string)($input['zip'] ?? ''));

    if ($street === '' || $city === '' || $state === '') {
        return [
            'valid' => false,
            'reason' => 'Insufficient address data'
        ];
    }

    #endregion

    #region REQUEST BUILD

    $query = http_build_query([
        'auth-id'    => $authId,
        'auth-token' => $authToken,
        'street'     => $street,
        'city'       => $city,
        'state'      => $state,
        'zipcode'    => $zip
    ]);

    $url = "https://us-street.api.smarty.com/street-address?$query";

    #endregion

    #region HTTP REQUEST

    $context = stream_context_create([
        'http' => [
            'timeout' => 10
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $error = error_get_last();
        throw new RuntimeException(
            'Smarty request failed: ' . ($error['message'] ?? 'unknown')
        );
    }

    $statusLine = $http_response_header[0] ?? '';

    if (!str_contains($statusLine, '200')) {
        throw new RuntimeException('Smarty HTTP error: ' . $statusLine);
    }

    #endregion

    #region RESPONSE PARSE

    $data = json_decode($response, true);

    if (!is_array($data) || count($data) === 0) {
        return [
            'valid' => false,
            'reason' => 'Address not validated by USPS'
        ];
    }

    $candidate  = $data[0];
    $components = $candidate['components'] ?? [];

    #endregion

    #region NORMALIZATION

    $deliveryLine = $candidate['delivery_line_1'] ?? $street;

    // Normalize casing
    $normalizedAddress = ucwords(strtolower($deliveryLine));

    return [
        'valid' => true,
        'normalized' => [
            'address' => $normalizedAddress,
            'city'    => $components['city_name'] ?? $city,
            'state'   => $components['state_abbreviation'] ?? $state,
            'zip'     => $components['zipcode'] ?? $zip,
            'zip4'    => $components['plus4_code'] ?? null
        ]
    ];

    #endregion
}

#endregion