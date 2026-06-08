<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 2.0.0
 *
 * Uses the official Maricopa County Assessor API
 * https://mcassessor.maricopa.gov
 */

function resolveParcel(
    ?float $latitude = null,
    ?float $longitude = null,
    ?string $county = null,
    ?string $countyFips = null,
    ?string $searchAddress = null
): array {

    $result = [
        'success'          => false,
        'parcelCount'      => 0,
        'parcelDetails'    => [],
        'jurisdictionName' => null,
        'jurisdictionType' => null
    ];

    // Only proceed for Maricopa County
    if ($countyFips !== '013' && strtolower($county ?? '') !== 'maricopa') {
        error_log('[RESOLVE-PARCEL] Skipping non-Maricopa county');
        return $result;
    }

    // Build search query - prefer address if available, otherwise use lat/lng
    $query = $searchAddress ?: (($latitude && $longitude) ? "{$latitude},{$longitude}" : null);

    if (!$query) {
        error_log('[RESOLVE-PARCEL] No search query available');
        return $result;
    }

    // =====================================================
    // CALL MARICOPA ASSESSOR SEARCH API
    // =====================================================
    $url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($query);

    error_log('[RESOLVE-PARCEL] Calling Assessor API: ' . $url);

    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header'  => "User-Agent: Skyesoft/1.0\r\n"
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('[RESOLVE-PARCEL] Assessor API request failed');
        return $result;
    }

    $data = json_decode($response, true);

    if (!is_array($data) || empty($data['results'])) {
        error_log('[RESOLVE-PARCEL] No results from Assessor API');
        return $result;
    }

    $parcelDetails = [];

    foreach ($data['results'] as $item) {
        // Focus on Real Property results
        if (($item['type'] ?? '') !== 'Real Property') {
            continue;
        }

        $parcelDetails[] = [
            'parcelNumber'   => $item['apn'] ?? null,
            'ownerName'      => $item['owner'] ?? null,
            'siteAddress'    => $item['address'] ?? null,
            'city'           => $item['city'] ?? null,
            'state'          => 'AZ',
            'zip'            => $item['zip'] ?? null,
            'propertyType'   => $item['property_type'] ?? null,
            'subdivision'    => $item['subdivision'] ?? null,
            'mcr'            => $item['mcr'] ?? null
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;

    if (!empty($parcelDetails)) {
        $result['jurisdictionName'] = $parcelDetails[0]['city'] ?? 'Maricopa County';
        $result['jurisdictionType'] = 'City';
    }

    error_log('[RESOLVE-PARCEL] Resolved ' . $result['parcelCount'] . ' parcel(s) via Assessor API');

    return $result;
}