<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — Address Validation + County Resolution (Census)
//  Version: 1.1.0
//  Codex Tier: 2 — Validation Enforcement
//
//  Role:
//  Validates U.S. addresses using Census Geocoder (FREE) and resolves county.
//
//  Purpose:
//   • Confirm address exists in national dataset
//   • Return county name and FIPS code when available
//
//  Output Structure:
//   - valid (bool)
//   - normalized address + coordinates
//   - county + countyFips (when valid)
// ======================================================================

#region SECTION 00 — Address Validation (CENSUS + COUNTY)

function validateAddressCensus(string $fullAddress): array
{
    $fullAddress = trim($fullAddress);

    if ($fullAddress === '') {
        return [
            'valid'  => false,
            'reason' => 'Empty address input'
        ];
    }

    // Use Geographies endpoint to get county + FIPS
    $query = http_build_query([
        'address'   => $fullAddress,
        'benchmark' => 'Public_AR_Current',
        'vintage'   => 'Current_Current',
        'format'    => 'json'
    ]);

    $url = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress?$query";

    $context = stream_context_create([
        'http' => [
            'timeout' => 10
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return [
            'valid'  => false,
            'reason' => 'Census request failed'
        ];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            'valid'  => false,
            'reason' => 'Invalid Census response'
        ];
    }

    $matches = $data['result']['addressMatches'] ?? [];

    if (!is_array($matches) || count($matches) === 0) {
        return [
            'valid'  => false,
            'reason' => 'Address not found in Census database'
        ];
    }

    $match = $matches[0];

    // Extract county information from geographies
    $geographies = $match['geographies'] ?? [];
    $county      = $geographies['Counties'][0] ?? null;

    $countyName = null;
    $countyFips = null;

    if ($county) {
        $countyName = trim(str_replace(' County', '', $county['NAME'] ?? ''));
        
        $stateFips  = str_pad($county['STATE'] ?? '04', 2, '0', STR_PAD_LEFT);
        $countyCode = str_pad($county['COUNTY'] ?? '', 3, '0', STR_PAD_LEFT);
        $countyFips = $stateFips . $countyCode;
    }

    return [
        'valid' => true,
        'normalized' => [
            'address' => $match['matchedAddress'] ?? $fullAddress,
            'lat'     => $match['coordinates']['y'] ?? null,
            'lng'     => $match['coordinates']['x'] ?? null
        ],
        'county'     => $countyName,
        'countyFips' => $countyFips
    ];
}

#endregion