<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 4.1.0  (Improved Buckeye + Unincorporated Support)
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
        'searchTier'       => null,
    ];

    if (!$searchAddress) {
        error_log('[RESOLVE-PARCEL] No searchAddress provided');
        return $result;
    }

    $original = $searchAddress;
    $upper = strtoupper(trim($searchAddress));

    error_log('[RESOLVE-PARCEL] Original: ' . $original);

    // =====================================================
    // IMPROVED NORMALIZATION
    // =====================================================
    $normalized = $upper;

    // Remove common suffixes and noise
    $normalized = str_replace([', USA', ' USA', ','], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    // Remove city names and ZIP for cleaner street matching
    $normalized = preg_replace('/\b(PHOENIX|BUCKEYE|SCOTTSDALE|GLENDALE|MESA|CHANDLER|GILBERT|TEMPE|PEORIA|SURPRISE|AVONDALE|GOODYEAR|MARICOPA|QUEEN CREEK|EL MIRAGE|YOUNGSTOWN|WICKENBURG|FOUNTAIN HILLS|PARADISE VALLEY|CAREFREE|CAVE CREEK|LITCHFIELD PARK|TOLLESON)\b/i', '', $normalized);
    $normalized = preg_replace('/\bAZ\b|\bARIZONA\b|\d{5}(-\d{4})?/', '', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    // Extract components more reliably
    preg_match('/^(\d+)\s*([NSEW]?)\.?\s*([A-Z0-9\s]+?)(?:\s+(ST|STREET|AVE|AVENUE|RD|ROAD|BLVD|DR|DRIVE|LN|LANE|WAY|CT|COURT|PL|PLACE))?\s*$/i', $normalized, $matches);

    $streetNumber = $matches[1] ?? '';
    $directional  = strtoupper(trim($matches[2] ?? ''));
    $streetName   = strtoupper(trim($matches[3] ?? ''));

    $searchTerms = [];

    // =====================================================
    // TIER 1: Try with city included (sometimes helps)
    // =====================================================
    if ($streetNumber && $streetName) {
        $searchTerms['exact_with_city'] = trim("$streetNumber $directional $streetName BUCKEYE");
    }

    // =====================================================
    // TIER 2: Clean street match (most reliable)
    // =====================================================
    if ($streetNumber && $streetName) {
        $searchTerms['street'] = trim("$streetNumber $directional $streetName");
    }

    // =====================================================
    // TIER 3: Number + Street Root (broader fallback)
    // =====================================================
    if ($streetNumber && $streetName) {
        $rootName = preg_replace('/\b(ST|STREET|AVE|AVENUE|RD|ROAD|BLVD|DR|DRIVE|LN|LANE|WAY|CT|COURT|PL|PLACE)\b/i', '', $streetName);
        $rootName = trim($rootName);
        if ($rootName) {
            $searchTerms['root'] = trim("$streetNumber $directional $rootName");
        }
    }

    $data = null;
    $tierUsed = null;

    foreach ($searchTerms as $tier => $term) {
        if (strlen(trim($term)) < 6) continue;

        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'             => $where,
            'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry'    => 'false',
            'f'                 => 'json'
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        error_log("[RESOLVE-PARCEL] Tier [{$tier}] trying term: {$term}");

        $response = @file_get_contents($url);

        if ($response === false) continue;

        $data = json_decode($response, true);

        if (isset($data['features']) && count($data['features']) > 0) {
            $tierUsed = $tier;
            error_log("[RESOLVE-PARCEL] Tier [{$tier}] SUCCESS → " . count($data['features']) . " result(s)");
            break;
        }
    }

    if (!isset($data['features']) || empty($data['features'])) {
        error_log('[RESOLVE-PARCEL] No parcels found after all tiers');
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

    error_log('[RESOLVE-PARCEL] Final Result → Tier: ' . ($result['searchTier'] ?? 'unknown') . 
              ' | Parcels: ' . $result['parcelCount']);

    return $result;
}