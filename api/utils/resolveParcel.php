<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 4.0.1
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

    // Dynamic search terms - broad first for multi-parcel properties
    $searchTerms = [
        preg_replace('/[^A-Z0-9 ]/', '', $upper),                       // Very broad
        preg_replace('/\b(RD|ROAD|ST|AVE|BLVD|LN|DR|WAY)\b/', '', $upper),
        preg_replace('/\b(E|W|N|S)\b/', '', $upper),
        $upper,
    ];

    // Street + number only is often most effective
    if (preg_match('/(\d+)\s+([A-Z ]+)/', $upper, $m)) {
        array_unshift($searchTerms, trim($m[1] . ' ' . $m[2]));
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

        $context = stream_context_create(['http' => ['timeout' => 18]]);
        $response = @file_get_contents($url, false, $context);

        if ($response !== false) {
            $data = json_decode($response, true);
            if (isset($data['features']) && count($data['features']) > 0) {
                error_log('[RESOLVE-PARCEL] ✅ Success with "' . $term . '" → ' . count($data['features']) . ' parcels');
                break;
            }
        }
    }

    if (empty($data['features'])) {
        error_log('[RESOLVE-PARCEL] ❌ No parcels found for ' . $original);
        return $result;
    }

    // Build results
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