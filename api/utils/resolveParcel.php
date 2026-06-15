<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.0.0  (Official Assessor Search Primary)
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

        'county'           => $county,
        'countyFips'       => $countyFips,

        'googlePlaceId'    => null,

        'searchSource'     => null,
        'searchTier'       => null,
    ];

    if (!$searchAddress) {
        error_log('[RESOLVE-PARCEL] No searchAddress provided');
        $result['searchTier'] = 'none';
        return $result;
    }

    $original = trim($searchAddress);

    // =====================================================
    // TIER 1: Official Maricopa Assessor Search API (Primary)
    // =====================================================
    $officialUrl = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($original);

    error_log('[RESOLVE-PARCEL] Trying Official Search: ' . $officialUrl);

    $officialResponse = @file_get_contents($officialUrl);

    if ($officialResponse !== false) {
        $officialData = json_decode($officialResponse, true);

        if (!empty($officialData['results'])) {
            $parcelDetails = [];

            foreach ($officialData['results'] as $item) {
                // The structure may vary slightly — adjust keys if needed
                $apn = $item['apn'] ?? $item['APN'] ?? null;
                if (!$apn) continue;

                $apnFormatted = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $apn));

                $parcelDetails[] = [
                    'parcelNumber' => $apnFormatted,
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

                // Set jurisdiction from first result if available
                if (!empty($parcelDetails[0]['jurisdiction'])) {
                    $jurRaw = $parcelDetails[0]['jurisdiction'];
                    $jurisdiction = resolveJurisdiction($jurRaw);
                    $result['jurisdictionName'] = $jurisdiction['label'] ?? ucwords(strtolower($jurRaw));
                    $result['jurisdictionType'] = $jurisdiction['jurisdictionType'] ?? 'City';
                }

                error_log('[RESOLVE-PARCEL] Official Search success → ' . count($parcelDetails) . ' parcel(s)');
                return $result;
            }
        }
    }

    // =====================================================
    // TIER 2: ArcGIS Layer Fallback (only if Tier 1 fails)
    // =====================================================
    error_log('[RESOLVE-PARCEL] Official Search failed or returned no results. Falling back to ArcGIS layer.');

    // --- Existing ArcGIS tiered logic (slightly cleaned) ---
    $upper = strtoupper($original);
    $normalized = preg_replace('/\s+/', ' ', str_replace([', USA', ','], ' ', $upper));

    preg_match('/^(\d+)\s*([NSEW]?)\s*([A-Z0-9\s]+)/', $normalized, $m);
    $streetNumber = $m[1] ?? '';
    $directional  = trim($m[2] ?? '');
    $streetName   = trim($m[3] ?? '');

    $searchTerms = [];
    if ($streetNumber && $streetName) {
        $searchTerms['street'] = trim("$streetNumber $directional $streetName");
        $root = preg_replace('/\b(ST|STREET|AVE|AVENUE|RD|ROAD|BLVD|DR|LN|WAY)\b/i', '', $streetName);
        if (trim($root)) $searchTerms['root'] = trim("$streetNumber $directional $root");
    }

    $arcgisData = null;
    $tierUsed   = null;

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

        $response = @file_get_contents($url);
        if ($response === false) continue;

        $arcgisData = json_decode($response, true);

        if (!empty($arcgisData['features'])) {
            $tierUsed = $tier;
            break;
        }
    }

    if (!empty($arcgisData['features'])) {
        $parcelDetails = [];
        foreach ($arcgisData['features'] as $feature) {
            $attr = $feature['attributes'] ?? [];
            if (empty($attr['APN'])) continue;

            $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

            $parcelDetails[] = [
                'parcelNumber' => $apnRaw,
                'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
                'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
                'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
                'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
                'source'       => 'arcgis_layer'
            ];
        }

        $result['success']       = true;
        $result['parcelCount']   = count($parcelDetails);
        $result['parcelDetails'] = $parcelDetails;
        $result['searchSource']  = 'arcgis_layer';
        $result['searchTier']    = $tierUsed ?? 'arcgis_fallback';

        if (!empty($parcelDetails)) {
            $jurRaw = $parcelDetails[0]['jurisdiction'] ?? '';
            $jurisdiction = resolveJurisdiction($jurRaw);
            $result['jurisdictionName'] = $jurisdiction['label'] ?? ucwords(strtolower($jurRaw));
            $result['jurisdictionType'] = $jurisdiction['jurisdictionType'] ?? 'City';
        }

        error_log('[RESOLVE-PARCEL] ArcGIS fallback success → ' . count($parcelDetails) . ' parcel(s)');
        return $result;
    }

    // =====================================================
    // Nothing found
    // =====================================================
    $result['searchTier'] = 'none';
    error_log('[RESOLVE-PARCEL] No parcels found for: ' . $original);
    return $result;
}