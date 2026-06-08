<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 3.1.0
 *
 * Uses Maricopa County Assessor ArcGIS (address-based query)
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
        error_log('[RESOLVE-PARCEL] Skipping non-Maricopa county');
        return $result;
    }

    if (!$searchAddress) {
        error_log('[RESOLVE-PARCEL] No searchAddress provided');
        return $result;
    }

    // =====================================================
    // NORMALIZE ADDRESS
    // =====================================================
    $normalized = strtoupper(trim($searchAddress));
    $normalized = str_replace([', USA', ','], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = preg_split('/\bPHOENIX\b|\bAZ\b|\d{5}/', $normalized)[0] ?? $normalized;
    $normalized = trim($normalized);

    if (strlen($normalized) < 5) {
        error_log('[RESOLVE-PARCEL] Normalized address too short');
        return $result;
    }

    // =====================================================
    // ARCGIS QUERY (Address LIKE)
    // =====================================================
    $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . 
             str_replace("'", "''", $normalized) . "%')";

    $params = [
        'where'          => $where,
        'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ];

    $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . 
           http_build_query($params);

    $context = stream_context_create([
        'http' => ['timeout' => 12]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('[RESOLVE-PARCEL] ArcGIS request failed');
        return $result;
    }

    $data = json_decode($response, true);

    if (!isset($data['features']) || !is_array($data['features'])) {
        error_log('[RESOLVE-PARCEL] Invalid ArcGIS response');
        return $result;
    }

    $parcelDetails = [];

    foreach ($data['features'] as $feature) {
        $attr = $feature['attributes'] ?? [];

        if (empty($attr['APN'])) {
            continue;
        }

        $apnRaw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $attr['APN']));

        $parcelDetails[] = [
            'parcelNumber'          => $apnRaw,
            'parcelNumberFormatted' => formatAPN($apnRaw),
            'ownerName'             => trim($attr['OWNER_NAME'] ?? ''),
            'siteAddress'           => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
            'city'                  => trim($attr['PHYSICAL_CITY'] ?? ''),
            'jurisdiction'          => trim($attr['JURISDICTION'] ?? ''),
            'source'                => 'mca_arcgis_mcassessor'
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;

    if (!empty($parcelDetails)) {
        $result['jurisdictionName'] = $parcelDetails[0]['city'] ?? 'Maricopa County';
        $result['jurisdictionType'] = 'City';
    }

    error_log('[RESOLVE-PARCEL] Resolved ' . $result['parcelCount'] . ' parcel(s)');

    return $result;
}

// =====================================================
// HELPER: Format APN nicely (e.g. 108-03-009E)
// =====================================================
function formatAPN(string $apnRaw): string {

    $apnRaw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $apnRaw));

    if (strlen($apnRaw) < 8) {
        return $apnRaw;
    }

    return substr($apnRaw, 0, 3) . '-' .
           substr($apnRaw, 3, 2) . '-' .
           substr($apnRaw, 5);
}