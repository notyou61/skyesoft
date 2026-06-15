<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 4.3.0  (Strict Post-Filter)
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

    // Extract input city
    $inputCity = '';
    if (preg_match('/\b(BUCKEYE|GOODYEAR|AVONDALE|MARICOPA)\b/i', $upper, $m)) {
        $inputCity = strtoupper($m[1]);
    }

    // Normalize for searching
    $normalized = $upper;
    $normalized = str_replace([', USA', ','], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    // Extract street components
    preg_match('/^(\d+)\s*([NSEW]?)\s*([A-Z0-9\s]+)/', $normalized, $matches);
    $streetNumber = $matches[1] ?? '';
    $directional  = trim($matches[2] ?? '');
    $streetName   = trim($matches[3] ?? '');

    $searchTerms = [];

    // Tier 1: Address + City
    if ($streetNumber && $streetName && $inputCity) {
        $searchTerms['address_city'] = trim("$streetNumber $directional $streetName $inputCity");
    }

    // Tier 2: Street only
    if ($streetNumber && $streetName) {
        $searchTerms['street'] = trim("$streetNumber $directional $streetName");
    }

    // Tier 3: Root
    if ($streetNumber && $streetName) {
        $root = preg_replace('/\b(ST|STREET|AVE|AVENUE|RD|ROAD|BLVD|DR|LN|WAY)\b/i', '', $streetName);
        if (trim($root)) {
            $searchTerms['root'] = trim("$streetNumber $directional $root");
        }
    }

    $data = null;
    $tierUsed = null;

    foreach ($searchTerms as $tier => $term) {
        if (strlen($term) < 6) continue;

        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'      => $where,
            'outFields'  => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry' => 'false',
            'f'          => 'json'
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        error_log("[RESOLVE-PARCEL] Tier [{$tier}] trying: {$term}");

        $response = @file_get_contents($url);
        if ($response === false) continue;

        $data = json_decode($response, true);

        if (!empty($data['features'])) {
            $tierUsed = $tier;
            break;
        }
    }

    if (empty($data['features'])) {
        $result['searchTier'] = 'none';
        return $result;
    }

    // =====================================================
    // STRICT POST-FILTER (This is the key improvement)
    // =====================================================
    $goodMatches = [];
    $otherMatches = [];

    foreach ($data['features'] as $feature) {
        $attr = $feature['attributes'] ?? [];
        $city = strtoupper(trim($attr['PHYSICAL_CITY'] ?? ''));

        $isGood = false;

        // Prefer correct city or "No City/Town"
        if ($inputCity && strpos($city, $inputCity) !== false) {
            $isGood = true;
        } elseif (strpos($city, 'NO CITY') !== false || $city === '') {
            $isGood = true;
        }

        if ($isGood) {
            $goodMatches[] = $attr;
        } else {
            $otherMatches[] = $attr;
        }
    }

    // If we have any good matches, use ONLY those. Otherwise fall back.
    $finalResults = !empty($goodMatches) ? $goodMatches : $otherMatches;

    // Build output
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
              ' | Final Count: ' . $result['parcelCount']);

    return $result;
}