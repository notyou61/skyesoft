<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility (Assessor API)
 * Version: 2.1.0
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

    if ($countyFips !== '013' && strtolower($county ?? '') !== 'maricopa') {
        error_log('[RESOLVE-PARCEL] Skipping non-Maricopa');
        return $result;
    }

    $query = $searchAddress ?: (($latitude && $longitude) ? "{$latitude},{$longitude}" : null);

    if (!$query) {
        error_log('[RESOLVE-PARCEL] No query available');
        return $result;
    }

    $url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($query);

    error_log('[RESOLVE-PARCEL] Querying Assessor API: ' . $url);

    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header'  => "User-Agent: Skyesoft/1.0\r\n"
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('[RESOLVE-PARCEL] API request failed');
        return $result;
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        error_log('[RESOLVE-PARCEL] Invalid JSON from Assessor API');
        return $result;
    }

    error_log('[RESOLVE-PARCEL] Raw results count: ' . count($data['results'] ?? []));

    $parcelDetails = [];

    foreach (($data['results'] ?? []) as $item) {

        // Try multiple possible field names (API can vary slightly)
        $apn = $item['apn'] 
            ?? $item['parcel_number'] 
            ?? $item['APN'] 
            ?? null;

        if (!$apn) {
            continue; // skip if no APN
        }

        $parcelDetails[] = [
            'parcelNumber' => $apn,
            'ownerName'    => $item['owner'] ?? $item['owner_name'] ?? null,
            'siteAddress'  => $item['address'] ?? $item['situs_address'] ?? null,
            'city'         => $item['city'] ?? null,
            'zip'          => $item['zip'] ?? null,
            'propertyType' => $item['property_type'] ?? $item['type'] ?? null,
            'subdivision'  => $item['subdivision'] ?? null,
            'mcr'          => $item['mcr'] ?? null
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;

    if (!empty($parcelDetails)) {
        $result['jurisdictionName'] = $parcelDetails[0]['city'] ?? 'Maricopa County';
        $result['jurisdictionType'] = 'City';
    }

    error_log('[RESOLVE-PARCEL] Final parcel count: ' . $result['parcelCount']);

    return $result;
}