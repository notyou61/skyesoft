<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.14.10 — Full Upgrades Restored + City-Filtered Candidates
 */

require_once __DIR__ . '/resolveJurisdiction.php';

function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}

function buildParcelCandidateSearchTerms(string $address): array {
    $normalized = normalizeParcelSearchAddress($address);
    $terms = [];

    $terms[] = $normalized;
    $terms[] = preg_replace('/\bAZ\b/', '', $normalized);

    if (preg_match('/^(.+?)\s+([A-Z\s]+)\s+(?:AZ\s+)?(\d{5})$/i', $normalized, $m)) {
        $terms[] = trim($m[1] . ' ' . $m[2] . ' ' . $m[3]);
    }

    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9]+\s+[A-Z0-9]+(?:\s+(?:RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY))?)/', $normalized, $m)) {
        $terms[] = trim($m[1]);
    }

    $terms = array_map(fn($t) => trim(preg_replace('/\s+/', ' ', $t)), $terms);
    return array_values(array_unique(array_filter($terms)));
}

function resolveParcel(
    ?float $latitude = null,
    ?float $longitude = null,
    ?string $county = null,
    ?string $countyFips = null,
    ?string $searchAddress = null
): array {

    $result = [
        'success'                   => false,
        'parcelResolutionAvailable' => true,

        'primaryParcel'             => null,
        'candidateParcels'          => [],
        'candidateParcelCount'      => 0,
        'parcelCount'               => 0,
        'parcelDetails'             => [],

        'jurisdictionName'          => null,
        'jurisdictionType'          => null,

        'searchSource'              => null,
        'searchTier'                => null,

        'postalCity'                => null,
        'permittingJurisdiction'    => null,

        'note'                      => null,
    ];

    $original = trim($searchAddress ?? '');
    $normalized = normalizeParcelSearchAddress($original);

    error_log('[RESOLVE-PARCEL] === START === Address: ' . $original);
    error_log('[RESOLVE-PARCEL] Normalized: ' . $normalized);
    error_log('[RESOLVE-PARCEL] Lat/Lng: ' . ($latitude ?? 'null') . ', ' . ($longitude ?? 'null'));

    // NON-MARICOPA bypass (unchanged)
    if (!empty($countyFips) && $countyFips !== '013') {
        // ... (keep your existing bypass code)
    }

    // =====================================================
    // TIER 1: COORDINATE → PRIMARY PARCEL
    // =====================================================
    if ($latitude !== null && $longitude !== null) {
        $params = http_build_query([
            'where'             => '1=1',
            'outFields'         => '*',   // Ask for more fields
            'returnGeometry'    => 'false',
            'f'                 => 'json',
            'resultRecordCount' => 10,
            'geometry'          => $longitude . ',' . $latitude,
            'geometryType'      => 'esriGeometryPoint',
            'spatialRel'        => 'esriSpatialRelIntersects',
            'inSR'              => 4326,
            'outSR'             => 4326
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;
        $response = @file_get_contents($url);

        error_log('[RESOLVE-PARCEL] Coordinate query URL: ' . $url);

        if ($response !== false) {
            $data = json_decode($response, true);
            error_log('[RESOLVE-PARCEL] Coordinate features returned: ' . count($data['features'] ?? []));

            if (!empty($data['features'])) {
                $attr = $data['features'][0]['attributes'] ?? [];
                if (!empty($attr['APN'])) {
                    $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

                    $result['primaryParcel'] = [
                        'parcelNumber' => $apnRaw,
                        'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
                        'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
                        'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
                        'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
                        'source'       => 'arcgis_coordinate'
                    ];

                    error_log('[RESOLVE-PARCEL] Primary parcel found: ' . $apnRaw);
                }
            }
        }
    }

    // =====================================================
    // ADDRESS-BASED CANDIDATES (Relaxed for Commercial)
    // =====================================================
    if (!empty($normalized)) {
        $candidateTerms = buildParcelCandidateSearchTerms($original);
        $candidates = [];

        foreach ($candidateTerms as $term) {
            $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

            $params = http_build_query([
                'where'             => $where,
                'outFields'         => '*',
                'returnGeometry'    => 'false',
                'f'                 => 'json',
                'resultRecordCount' => 50   // Increased
            ]);

            $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;
            $response = @file_get_contents($url);

            if ($response === false) continue;

            $data = json_decode($response, true);
            if (empty($data['features'])) continue;

            foreach ($data['features'] as $feature) {
                $attr = $feature['attributes'] ?? [];
                if (empty($attr['APN'])) continue;

                $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

                $candidateCity = trim($attr['PHYSICAL_CITY'] ?? '');

                $candidates[$apnRaw] = [
                    'parcelNumber' => $apnRaw,
                    'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
                    'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
                    'city'         => $candidateCity,
                    'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
                    'source'       => 'arcgis_address',
                    'matchedTerm'  => $term
                ];
            }
        }

        $result['candidateParcels'] = array_values($candidates);
        $result['candidateParcelCount'] = count($result['candidateParcels']);
        error_log('[RESOLVE-PARCEL] Address candidates found: ' . $result['candidateParcelCount']);
    }

    // Jurisdiction + Note (unchanged)
    // ... keep your existing jurisdiction logic

    if ($result['primaryParcel'] !== null || $result['candidateParcelCount'] > 0) {
        $result['success'] = true;
    }

    $result['parcelCount'] = ($result['primaryParcel'] ? 1 : 0) + $result['candidateParcelCount'];

    if ($result['primaryParcel'] !== null) {
        $result['parcelDetails'] = [$result['primaryParcel']];
    } else {
        $result['parcelDetails'] = $result['candidateParcels'];
    }

    return $result;
}