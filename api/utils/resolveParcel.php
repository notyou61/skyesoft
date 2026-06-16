<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.5.0 — Strict Dynamic Filter (Proven from Working Test)
 */

require_once __DIR__ . '/resolveJurisdiction.php';

function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}

function buildParcelSearchTerms($address) {
    $normalized = normalizeParcelSearchAddress($address);
    $terms = [$normalized];

    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9\s]+)/', $normalized, $matches)) {
        $terms[] = trim($matches[1]);
    }

    $terms[] = preg_replace('/\b(RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY)\b/', '', $normalized);

    return array_unique(array_filter(array_map('trim', $terms)));
}

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
        return $result;
    }

    $original = trim($searchAddress);
    $normalizedInput = normalizeParcelSearchAddress($original);
    $searchTerms = buildParcelSearchTerms($original);

    error_log('[RESOLVE-PARCEL] Searching: ' . $original);

    $data = null;
    $matchedTerm = null;

    foreach ($searchTerms as $term) {
        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'             => $where,
            'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry'    => 'false',
            'f'                 => 'json',
            'resultRecordCount' => 30
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        $response = @file_get_contents($url);
        if ($response === false) continue;

        $data = json_decode($response, true);
        if (empty($data['features'])) continue;

        // STRICT FILTER
        $filtered = [];
        foreach ($data['features'] as $feature) {
            $attr = $feature['attributes'] ?? [];
            $dbAddr = normalizeParcelSearchAddress($attr['PHYSICAL_ADDRESS'] ?? '');

            if (strpos($dbAddr, $normalizedInput) !== false || 
                similar_text($dbAddr, $normalizedInput) > 82) {
                $filtered[] = $attr;
            }
        }

        if (!empty($filtered)) {
            $matchedTerm = $term;
            $data['features'] = $filtered;
            break;
        }
    }

    if (empty($data['features'])) {
        error_log('[RESOLVE-PARCEL] No matching parcels found for ' . $original);
        return $result;
    }

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
            'source'       => 'arcgis'
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;
    $result['searchSource']  = 'arcgis';
    $result['searchTier']    = 'filtered';

    if (!empty($parcelDetails)) {
        $jurRaw = $parcelDetails[0]['jurisdiction'] ?? '';
        $jur = resolveJurisdiction($jurRaw);
        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($jurRaw));
        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';
    }

    error_log('[RESOLVE-PARCEL] ✅ Success - ' . $result['parcelCount'] . ' parcels for ' . $original);

    return $result;
}