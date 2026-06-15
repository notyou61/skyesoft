<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 4.0.0  (Tiered Search Strategy)
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
        'jurisdictionKey'  => null,
        'searchTier'       => null,           // NEW: Shows which tier was used
    ];

    if (!$searchAddress) {
        error_log('[RESOLVE-PARCEL] No searchAddress provided');
        return $result;
    }

    $original = $searchAddress;
    $upper = strtoupper(trim($searchAddress));

    error_log('[RESOLVE-PARCEL] Original: ' . $original);

    // =====================================================
    // NORMALIZE ADDRESS FOR SEARCHING
    // =====================================================
    $normalized = $upper;
    $normalized = str_replace([', USA', ','], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    // Extract components
    preg_match('/^(\d+)\s+([NSEW]?)\s*([A-Z0-9\s]+)/', $normalized, $matches);
    $streetNumber = $matches[1] ?? '';
    $directional  = trim($matches[2] ?? '');
    $streetName   = trim($matches[3] ?? '');

    $searchTerms = [];

    // =====================================================
    // TIER 1: Exact Physical Address Match (Preferred)
    // =====================================================
    if ($streetNumber && $streetName) {
        $exactTerm = trim("$streetNumber $directional $streetName");
        $searchTerms['exact'] = $exactTerm;
    }

    // =====================================================
    // TIER 2: Street Number + Street Name
    // =====================================================
    if ($streetNumber && $streetName) {
        $streetTerm = trim("$streetNumber $streetName");
        $searchTerms['street'] = $streetTerm;
    }

    // =====================================================
    // TIER 3: Number + Street Root (Last Resort)
    // =====================================================
    if ($streetNumber && $streetName) {
        // Remove common suffixes for broader matching
        $rootName = preg_replace('/\b(ST|STREET|AVE|AVENUE|RD|ROAD|BLVD|DR|DRIVE|LN|LANE|WAY)\b/i', '', $streetName);
        $rootName = trim($rootName);
        if ($rootName) {
            $searchTerms['root'] = trim("$streetNumber $rootName");
        }
    }

    $data = null;
    $tierUsed = null;

    foreach ($searchTerms as $tier => $term) {
        if (strlen(trim($term)) < 5) continue;

        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'             => $where,
            'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry'    => 'false',
            'f'                 => 'json'
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        error_log("[RESOLVE-PARCEL] Tier {$tier} trying: {$term}");

        $response = @file_get_contents($url);

        if ($response === false) continue;

        $data = json_decode($response, true);

        if (isset($data['features']) && count($data['features']) > 0) {
            $tierUsed = $tier;
            error_log("[RESOLVE-PARCEL] Tier {$tier} SUCCESS with " . count($data['features']) . " results");
            break;
        }
    }

    if (!isset($data['features']) || empty($data['features'])) {
        error_log('[RESOLVE-PARCEL] No results found for any tier');
        $result['searchTier'] = 'none';
        return $result;
    }

    // =====================================================
    // BUILD RESULTS
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
    $result['searchTier']    = $tierUsed ?? 'unknown';

    if (!empty($parcelDetails)) {
        $jurisdictionRaw = trim($parcelDetails[0]['jurisdiction'] ?? '');
        $jurisdiction = resolveJurisdiction($jurisdictionRaw);

        $result['jurisdictionName'] = $jurisdiction['label'] ?? ucwords(strtolower($jurisdictionRaw));
        $result['jurisdictionType'] = $jurisdiction['jurisdictionType'] ?? 'City';
        $result['jurisdictionKey']  = $jurisdiction['jurisdictionKey'] ?? null;
    }

    error_log('[RESOLVE-PARCEL] Final: Tier=' . ($result['searchTier'] ?? 'unknown') . 
              ' | Count=' . $result['parcelCount']);

    return $result;
}