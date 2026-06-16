<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.3.0  (Limited Results + Better Filtering)
 */

require_once __DIR__ . '/resolveJurisdiction.php';

// =====================================================
// Helpers
// =====================================================
function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}

function buildParcelSearchTerms($address) {
    $normalized = normalizeParcelSearchAddress($address);
    $terms = [];

    $terms[] = $normalized;

    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9\s]+)/', $normalized, $matches)) {
        $terms[] = trim($matches[1]);
    }

    $terms[] = preg_replace('/\b(RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY|CT|PL)\b/', '', $normalized);

    if (preg_match('/^(\d+)\s+([A-Z]+)/', $normalized, $m)) {
        $terms[] = $m[1] . ' ' . $m[2];
    }

    $terms = array_unique(array_filter(array_map('trim', $terms)));
    return $terms;
}

// =====================================================
// Main Function
// =====================================================
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
        'searchSource'     => null,
        'searchTier'       => null,
    ];

    if (empty($searchAddress)) {
        $result['searchTier'] = 'none';
        return $result;
    }

    $original = trim($searchAddress);
    error_log('[RESOLVE-PARCEL] Searching: ' . $original);

    // Try Official API first (if token available)
    $token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';
    if (!empty($token)) {
        $url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($original);

        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Skyesoft Parcel Resolver\r\nAccept: application/json\r\nAuthorization: $token\r\n",
                'timeout' => 12
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            $data = json_decode($response, true);
            if (!empty($data['results']) && is_array($data['results'])) {
                $parcelDetails = [];
                foreach ($data['results'] as $item) {
                    $apn = $item['apn'] ?? $item['APN'] ?? null;
                    if (empty($apn)) continue;

                    $parcelDetails[] = [
                        'parcelNumber' => strtoupper(preg_replace('/[^A-Z0-9-]/', '', $apn)),
                        'ownerName'    => trim($item['ownerName'] ?? $item['owner'] ?? ''),
                        'siteAddress'  => trim($item['address'] ?? $item['propertyAddress'] ?? ''),
                        'city'         => trim($item['city'] ?? $item['situsCity'] ?? ''),
                        'jurisdiction' => trim($item['jurisdiction'] ?? $item['situsCity'] ?? ''),
                        'source'       => 'mca_official_search'
                    ];
                }
                if (!empty($parcelDetails)) {
                    $result['success']       = true;
                    $result['parcelCount']   = count($parcelDetails);
                    $result['parcelDetails'] = $parcelDetails;
                    $result['searchSource']  = 'mca_official_search';
                    $result['searchTier']    = 'official';

                    if (!empty($parcelDetails[0]['jurisdiction'])) {
                        $jur = resolveJurisdiction($parcelDetails[0]['jurisdiction']);
                        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($parcelDetails[0]['jurisdiction']));
                        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';
                    }
                    return $result;
                }
            }
        }
    }

    // =====================================================
    // ArcGIS Fallback - Limited & Filtered
    // =====================================================
    $searchTerms = buildParcelSearchTerms($original);

    $data = null;
    $matchedTerm = null;

    foreach ($searchTerms as $term) {
        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'          => $where,
            'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry' => 'false',
            'f'              => 'json',
            'resultRecordCount' => 50   // Limit results
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        $response = @file_get_contents($url);
        if ($response === false) continue;

        $data = json_decode($response, true);
        if (!empty($data['features'])) {
            $matchedTerm = $term;
            break;
        }
    }

    if (empty($data['features'])) {
        $result['searchTier'] = 'none';
        return $result;
    }

    // Build results (limit to 10 max)
    $parcelDetails = [];
    $count = 0;
    foreach ($data['features'] as $feature) {
        if ($count >= 10) break;
        $attr = $feature['attributes'] ?? [];
        if (empty($attr['APN'])) continue;

        $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

        $parcelDetails[] = [
            'parcelNumber' => $apnRaw,
            'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
            'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
            'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
            'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
            'source'       => 'arcgis'
        ];
        $count++;
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;
    $result['searchSource']  = 'arcgis';
    $result['searchTier']    = 'arcgis';

    if (!empty($parcelDetails)) {
        $jurRaw = $parcelDetails[0]['jurisdiction'] ?? '';
        $jur = resolveJurisdiction($jurRaw);
        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($jurRaw));
        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';
    }

    error_log('[RESOLVE-PARCEL] Success → ' . $result['parcelCount'] . ' parcels');

    return $result;
}