<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.14.5 — Improved Address Candidate Search
 * Primary: Lat/Lng → Best Parcel
 * Candidates: Address search (excluding primary)
 */

require_once __DIR__ . '/resolveJurisdiction.php';

function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}

/**
 * Build multiple search terms for address-based candidate lookup.
 */
function buildParcelCandidateSearchTerms(string $address): array {
    $normalized = normalizeParcelSearchAddress($address);

    $terms = [];

    // Full normalized input
    $terms[] = $normalized;

    // Remove AZ
    $terms[] = preg_replace('/\bAZ\b/', '', $normalized);

    // Street + City + Zip (no state)
    if (preg_match('/^(.+?)\s+(PHOENIX|SCOTTSDALE|BUCKEYE|GOODYEAR|AVONDALE|TEMPE|MESA|CHANDLER|GLENDALE|PEORIA|SURPRISE)\s+(?:AZ\s+)?(\d{5})$/i', $normalized, $m)) {
        $terms[] = trim($m[1] . ' ' . $m[2] . ' ' . $m[3]);
    }

    // Street-only fallback
    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9]+\s+[A-Z0-9]+(?:\s+(?:RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY))?)/', $normalized, $m)) {
        $terms[] = trim($m[1]);
    }

    $terms = array_map(function ($term) {
        return trim(preg_replace('/\s+/', ' ', $term));
    }, $terms);

    return array_values(array_unique(array_filter($terms)));
}

function resolveParcel(
    ?float $latitude = null,
    ?float $longitude = null,
    ?string $county = null,
    ?string $countyFips = null,
    ?string $searchAddress = null,
    ?array $googlePlaceInput = null
): array {

    $result = [
        'success'                => false,
        'primaryParcel'          => null,
        'candidateParcels'       => [],
        'candidateParcelCount'   => 0,
        'parcelCount'            => 0,
        'parcelDetails'          => [],
        'jurisdictionName'       => null,
        'jurisdictionType'       => null,
        'searchSource'           => null,
        'searchTier'             => null,
        'postalCity'             => null,
        'permittingJurisdiction' => null,
        'note'                   => null,
        'googlePlace'            => null,
    ];

    $original = trim($searchAddress ?? '');
    $normalized = normalizeParcelSearchAddress($original);

    error_log('[RESOLVE-PARCEL] === START === Address: ' . $original);

    preg_match('/^(\d+)/', $normalized, $numMatch);
    $targetNumber = $numMatch[1] ?? '';

    // =====================================================
    // GOOGLE PLACE ENRICHMENT
    // =====================================================
    if (!empty($googlePlaceInput)) {
        $placeData = $googlePlaceInput['result'] ?? $googlePlaceInput;
        $result['googlePlace'] = [
            'placeId'          => $placeData['place_id'] ?? null,
            'name'             => $placeData['name'] ?? null,
            'formattedAddress' => $placeData['formatted_address'] ?? null,
            'latitude'         => $latitude,
            'longitude'        => $longitude,
            'businessStatus'   => $placeData['business_status'] ?? null,
            'types'            => $placeData['types'] ?? [],
            'website'          => $placeData['website'] ?? null,
            'phoneNumber'      => $placeData['formatted_phone_number'] ?? null,
            'rating'           => $placeData['rating'] ?? null,
            'reviewCount'      => $placeData['user_ratings_total'] ?? null,
            'googleMapsUrl'    => $placeData['url'] ?? null,
            'source'           => 'google_place_details'
        ];
    }

    // =====================================================
    // TIER 1: COORDINATE-BASED LOOKUP → PRIMARY PARCEL
    // =====================================================
    $primaryApn = null;

    if ($latitude !== null && $longitude !== null) {
        $params = http_build_query([
            'where'             => '1=1',
            'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
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

        if ($response !== false) {
            $data = json_decode($response, true);
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

                    $primaryApn = $apnRaw;
                    $result['searchSource'] = 'arcgis_coordinate';
                    $result['searchTier'] = 'coordinate';
                    $result['postalCity'] = $result['primaryParcel']['city'] ?? null;
                }
            }
        }
    }

    // =====================================================
    // ADDRESS-BASED SEARCH → CANDIDATE PARCELS
    // =====================================================
    if (!empty($primaryApn) && !empty($normalized)) {

        $candidateTerms = buildParcelCandidateSearchTerms($original);
        $candidates = [];

        foreach ($candidateTerms as $term) {

            $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" .
                str_replace("'", "''", $term) .
                "%')";

            $params = http_build_query([
                'where'             => $where,
                'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
                'returnGeometry'    => 'false',
                'f'                 => 'json',
                'resultRecordCount' => 40
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

                if ($apnRaw === $primaryApn) continue;
                if (isset($candidates[$apnRaw])) continue;

                $candidates[$apnRaw] = [
                    'parcelNumber' => $apnRaw,
                    'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
                    'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
                    'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
                    'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
                    'source'       => 'arcgis_address',
                    'matchedTerm'  => $term
                ];
            }

            if (!empty($candidates)) break;
        }

        $result['candidateParcels'] = array_values($candidates);
        $result['candidateParcelCount'] = count($result['candidateParcels']);
    }

    // =====================================================
    // JURISDICTION (NO CITY/TOWN = County)
    // =====================================================
    if (!empty($result['primaryParcel'])) {
        $jurRaw = $result['primaryParcel']['jurisdiction'] ?? '';

        if (empty($jurRaw) || stripos($jurRaw, 'NO CITY') !== false) {
            $result['jurisdictionName']       = 'Maricopa County';
            $result['jurisdictionType']       = 'County';
            $result['permittingJurisdiction'] = 'Maricopa County';
            $result['note'] = 'County jurisdiction (NO CITY/TOWN returned by GIS)';
        } else {
            $jur = resolveJurisdiction($jurRaw);
            $result['jurisdictionName']       = $jur['label'] ?? ucwords(strtolower($jurRaw));
            $result['jurisdictionType']       = $jur['jurisdictionType'] ?? 'City';
            $result['permittingJurisdiction'] = $result['jurisdictionName'];
        }
    }

    // Success + totals
    if ($result['primaryParcel'] !== null || $result['candidateParcelCount'] > 0) {
        $result['success'] = true;
    }

    $result['parcelCount'] = ($result['primaryParcel'] ? 1 : 0) + $result['candidateParcelCount'];

    // Legacy compatibility
    if ($result['primaryParcel'] !== null) {
        $result['parcelDetails'] = [$result['primaryParcel']];
    } else {
        $result['parcelDetails'] = $result['candidateParcels'];
    }

    return $result;
}