<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 3.6.0
 */

require_once __DIR__ . '/resolveJurisdiction.php';

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
        'jurisdictionType' => null,
        'jurisdictionKey'  => null
    ];

    if (!$searchAddress) {
        error_log('[RESOLVE-PARCEL] No searchAddress provided');
        return $result;
    }

    // =====================================================
    // NORMALIZE ADDRESS (More flexible)
    // =====================================================
    $normalized = strtoupper(trim($searchAddress));
    $normalized = str_replace([', USA', ',', '  '], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    error_log('[RESOLVE-PARCEL] Original: ' . $searchAddress);
    error_log('[RESOLVE-PARCEL] Normalized: ' . $normalized);

    // =====================================================
    // MCA ASSESSOR QUERY - Broader matching
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

    $context = stream_context_create(['http' => ['timeout' => 15]]);
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

    // =====================================================
    // BUILD PARCEL LIST
    // =====================================================
    $parcelDetails = [];
    foreach ($data['features'] as $feature) {
        $attr = $feature['attributes'] ?? [];
        if (empty($attr['APN'])) continue;

        $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

        $parcelDetails[] = [
            'parcelNumber' => $apnRaw,
            'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
            'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
            'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
            'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
            'source'       => 'mca_arcgis_mcassessor'
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;

    // =====================================================
    // JURISDICTION RESOLUTION
    // =====================================================
    if (!empty($parcelDetails)) {
        $jurisdictionRaw = trim($parcelDetails[0]['jurisdiction'] ?? '');

        $jurisdiction = resolveJurisdiction($jurisdictionRaw);

        $result['jurisdictionName'] = $jurisdiction['label'] ?? ucwords(strtolower($jurisdictionRaw));
        $result['jurisdictionType'] = $jurisdiction['jurisdictionType'] ?? null;
        $result['jurisdictionKey']  = $jurisdiction['jurisdictionKey'] ?? null;
    }

    error_log('[RESOLVE-PARCEL] Resolved ' . $result['parcelCount'] . ' parcel(s) for address: ' . $searchAddress);

    return $result;
}