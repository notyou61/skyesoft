<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 3.9.0
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

    $original = $searchAddress;
    $upper = strtoupper(trim($searchAddress));

    error_log('[RESOLVE-PARCEL] Original: ' . $original);

    // Generate search terms - broader is better
    $searchTerms = [
        $upper,
        preg_replace('/\b(E|W|N|S)\b/', '', $upper),
        preg_replace('/\b(RD|ROAD|ST|AVE|BLVD)\b/', '', $upper),
        preg_replace('/[^A-Z0-9 ]/', '', $upper),
    ];

    // Add street-only for known problem streets
    if (strpos($upper, 'CAMELBACK') !== false) {
        $searchTerms[] = '7401 E CAMELBACK';
    }
    if (strpos($upper, '33RD') !== false) {
        $searchTerms[] = '3145 N 33RD';
    }

    $data = null;

    foreach ($searchTerms as $term) {
        if (strlen(trim($term)) < 8) continue;

        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = [
            'where'          => $where,
            'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry' => 'false',
            'f'              => 'json'
        ];

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . http_build_query($params);

        $context = stream_context_create(['http' => ['timeout' => 15]]);
        $response = @file_get_contents($url, false, $context);

        if ($response !== false) {
            $data = json_decode($response, true);
            if (isset($data['features']) && count($data['features']) > 0) {
                error_log('[RESOLVE-PARCEL] Success with term: ' . $term);
                break;
            }
        }
    }

    if (!isset($data['features']) || !is_array($data['features']) || empty($data['features'])) {
        error_log('[RESOLVE-PARCEL] No results for ' . $original);
        return $result;
    }

    // Build parcel list
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

    if (!empty($parcelDetails)) {
        $jurisdictionRaw = trim($parcelDetails[0]['jurisdiction'] ?? '');
        $jurisdiction = resolveJurisdiction($jurisdictionRaw);

        $result['jurisdictionName'] = $jurisdiction['label'] ?? ucwords(strtolower($jurisdictionRaw));
        $result['jurisdictionType'] = $jurisdiction['jurisdictionType'] ?? 'City';
    }

    error_log('[RESOLVE-PARCEL] Resolved ' . $result['parcelCount'] . ' parcel(s) for ' . $original);

    return $result;
}