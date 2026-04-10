<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — Address Validation Layer (Census)
//  Version: 1.0.0
//  Codex Tier: 2 — Validation Enforcement
//
//  Role:
//  Validates U.S. addresses using Census Geocoder (FREE)
//
//  Purpose:
//   • Confirm address exists in national dataset
//   • Prevent fake or invalid addresses
//
//  Output:
//   • valid (bool)
//   • normalized address + coordinates
//
// ======================================================================

#region SECTION X — Address Validation (CENSUS)

function validateAddressCensus(string $fullAddress): array
{
    #region INPUT VALIDATION

    $fullAddress = trim($fullAddress);

    if ($fullAddress === '') {
        return [
            'valid' => false,
            'reason' => 'Empty address input'
        ];
    }

    #endregion

    #region REQUEST BUILD

    $query = http_build_query([
        'address'   => $fullAddress,
        'benchmark' => 'Public_AR_Current',
        'format'    => 'json'
    ]);

    $url = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress?$query";

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
            'Census request failed: ' . ($error['message'] ?? 'unknown')
        );
    }

    #endregion

    #region RESPONSE PARSE

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Invalid Census response.');
    }

    $matches = $data['result']['addressMatches'] ?? [];

    if (!is_array($matches) || count($matches) === 0) {
        return [
            'valid' => false,
            'reason' => 'Address not found in Census database'
        ];
    }

    $match = $matches[0];

    #endregion

    #region NORMALIZATION

    return [
        'valid' => true,
        'normalized' => [
            'address' => $match['matchedAddress'] ?? $fullAddress,
            'lat'     => $match['coordinates']['y'] ?? null,
            'lng'     => $match['coordinates']['x'] ?? null
        ]
    ];

    #endregion
}

#endregion