<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 4.2.0  (Stricter Address + City Matching)
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

    $original = trim($searchAddress);
    $upper = strtoupper($original);

    error_log('[RESOLVE-PARCEL] Original: ' . $original);

    // =====================================================
    // NORMALIZE + EXTRACT COMPONENTS
    // =====================================================
    $normalized = $upper;
    $normalized = str_replace([', USA', ','], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    // Extract city if present
    $inputCity = '';
    if (preg_match('/\b(BUCKEYE|GOODYEAR|AVONDALE|MARICOPA|GILA BEND)\b/i', $normalized, $cityMatch)) {
        $inputCity = strtoupper($cityMatch[1]);
    }

    // Remove city and state/ZIP for street matching
    $streetOnly = preg_replace('/\b(BUCKEYE|GOODYEAR|AVONDALE|MARICOPA|PHOENIX|SCOTTSDALE|GLENDALE|MESA|CHANDLER|GILBERT|TEMPE|PEORIA|SURPRISE|EL MIRAGE|QUEEN CREEK)\b/i', '', $normalized);
    $streetOnly = preg_replace('/\bAZ\b|\bARIZONA\b|\d{5}(-\d{4})?/', '', $streetOnly);
    $streetOnly = preg_replace('/\s+/', ' ', trim($streetOnly));

    // Extract street number and name
    preg_match('/^(\d+)\s*([NSEW]?)\s*([A-Z0-9\s]+)/', $streetOnly, $matches);
    $streetNumber = $matches[1] ?? '';
    $directional  = trim($matches[2] ?? '');
    $streetName   = trim($matches[3] ?? '');

    $searchTerms = [];

    // =====================================================
    // TIER 1: Address + City (Best)
    // =====================================================
    if ($streetNumber && $streetName && $inputCity) {
        $searchTerms['address_city'] = trim("$streetNumber $directional $streetName $inputCity");
    }

    // =====================================================
    // TIER 2: Full street match
    // =====================================================
    if ($streetNumber && $streetName) {
        $searchTerms['street'] = trim("$streetNumber $directional $streetName");
    }

    // =====================================================
    // TIER 3: Number + Street Root (Last resort)
    // =====================================================
    if ($streetNumber && $streetName) {
        $root = preg_replace('/\b(ST|STREET|AVE|AVENUE|RD|ROAD|BLVD|DR|LN|WAY)\b/i', '', $streetName);
        $root = trim($root);
        if ($root) {
            $searchTerms['root'] = trim("$streetNumber $directional $root");
        }
    }

    $data = null;
    $tierUsed = null;

    foreach ($searchTerms as $tier => $term) {
        if (strlen($term) < 6) continue;

        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'             => $where,
            'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry'    => 'false',
            'f'                 => 'json'
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        error_log("[RESOLVE-PARCEL] Tier [{$tier}] → {$term}");

        $response = @file_get_contents($url);
        if ($response === false) continue;

        $data = json_decode($response, true);

        if (!empty($data['features'])) {
            $tierUsed = $tier;
            break;
        }
    }

    if (empty($data['features'])) {
        error_log('[RESOLVE-PARCEL] No results found');
        $result['searchTier'] = 'none';
        return $result;
    }

    // =====================================================
    // FILTER RESULTS (Prefer correct city or "No City/Town")
    // =====================================================
    $filtered = [];
    foreach ($data['features'] as $feature) {
        $attr = $feature['attributes'] ?? [];
        $addr = strtoupper($attr['PHYSICAL_ADDRESS'] ?? '');
        $city = strtoupper($attr['PHYSICAL_CITY'] ?? '');

        // Strong preference for correct city or unincorporated
        $isGoodCity = ($inputCity && strpos($city, $inputCity) !== false) || 
                      strpos($city, 'NO CITY') !== false || 
                      $city === '';

        if ($isGoodCity || empty($filtered)) {
            $filtered[] = $attr;
        }
    }

    // If we have good city matches, use them. Otherwise fall back to all results.
    $finalResults = !empty($filtered) ? $filtered : $data['features'];

    // =====================================================
    // BUILD OUTPUT
    // =====================================================
    $parcelDetails = [];
    foreach ($finalResults as $attr) {
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
    $result['searchTier']    = $tierUsed ?? 'filtered';

    if (!empty($parcelDetails)) {
        $jurisdictionRaw = trim($parcelDetails[0]['jurisdiction'] ?? '');
        $jurisdiction = resolveJurisdiction($jurisdictionRaw);

        $result['jurisdictionName'] = $jurisdiction['label'] ?? ucwords(strtolower($jurisdictionRaw));
        $result['jurisdictionType'] = $jurisdiction['jurisdictionType'] ?? 'City';
    }

    error_log('[RESOLVE-PARCEL] Final → Tier: ' . ($result['searchTier'] ?? 'unknown') . 
              ' | Count: ' . $result['parcelCount']);

    return $result;
}