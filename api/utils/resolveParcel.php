<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.14.8 — City-Filtered Candidates + Google Place ID
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

    if (preg_match('/^(.+?)\s+(PHOENIX|SCOTTSDALE|BUCKEYE|GOODYEAR|AVONDALE|TEMPE|MESA|CHANDLER|GLENDALE|PEORIA|SURPRISE)\s+(?:AZ\s+)?(\d{5})$/i', $normalized, $m)) {
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
        'googlePlaceID'          => null,
    ];

    $original = trim($searchAddress ?? '');
    $normalized = normalizeParcelSearchAddress($original);

    error_log('[RESOLVE-PARCEL] === START === Address: ' . $original);

    // =====================================================
    // GOOGLE PLACE ID
    // =====================================================
    if (!empty($googlePlaceInput)) {
        if (isset($googlePlaceInput['placeId'])) {
            $result['googlePlaceID'] = $googlePlaceInput['placeId'];
        } elseif (isset($googlePlaceInput['result']['place_id'])) {
            $result['googlePlaceID'] = $googlePlaceInput['result']['place_id'];
        }
    }

    // =====================================================
    // TIER 1: COORDINATE → PRIMARY PARCEL
    // =====================================================
    $primaryApn = null;
    $primaryCity = null;

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
                    $primaryCity = $result['primaryParcel']['city'] ?? null;
                    $result['searchSource'] = 'arcgis_coordinate';
                    $result['searchTier'] = 'coordinate';
                    $result['postalCity'] = $primaryCity;
                }
            }
        }
    }

    // =====================================================
    // ADDRESS-BASED CANDIDATES (City-Filtered)
    // =====================================================
    if (!empty($primaryApn) && !empty($normalized) && !empty($primaryCity)) {
        $candidateTerms = buildParcelCandidateSearchTerms($original);
        $candidates = [];

        foreach ($candidateTerms as $term) {
            $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

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

                // City filter - only same city as primary parcel
                $candidateCity = trim($attr['PHYSICAL_CITY'] ?? '');
                if (strtoupper($candidateCity) !== strtoupper($primaryCity)) {
                    continue;
                }

                if (isset($candidates[$apnRaw])) continue;

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

            if (!empty($candidates)) break;
        }

        $result['candidateParcels'] = array_values($candidates);
        $result['candidateParcelCount'] = count($result['candidateParcels']);
    }

    // =====================================================
    // JURISDICTION + NARRATIVE NOTE
    // =====================================================
    if (!empty($result['primaryParcel'])) {
        $jurRaw = $result['primaryParcel']['jurisdiction'] ?? '';
        $postal = $result['postalCity'] ?? 'Unknown';

        if (empty($jurRaw) || stripos($jurRaw, 'NO CITY') !== false) {
            $result['jurisdictionName']       = 'Maricopa County';
            $result['jurisdictionType']       = 'County';
            $result['permittingJurisdiction'] = 'Maricopa County';
            $result['note'] = "The postal city is {$postal} (for mailing purposes). This property is in unincorporated Maricopa County, so the permitting jurisdiction is Maricopa County.";
        } else {
            $jur = resolveJurisdiction($jurRaw);
            $cityName = $jur['label'] ?? ucwords(strtolower($jurRaw));

            $result['jurisdictionName']       = $cityName;
            $result['jurisdictionType']       = $jur['jurisdictionType'] ?? 'City';
            $result['permittingJurisdiction'] = $cityName;
            $result['note'] = "The postal city is {$postal}. The permitting jurisdiction is {$cityName}.";
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