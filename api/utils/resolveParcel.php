<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.14.4 — Primary + Candidate Parcels (Coordinate + Address)
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
    // (Only run if we have a primary parcel from coordinates)
    // =====================================================
    if (!empty($primaryApn) && !empty($normalized)) {
        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $normalized) . "%')";

        $params = http_build_query([
            'where'             => $where,
            'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry'    => 'false',
            'f'                 => 'json',
            'resultRecordCount' => 40
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;
        $response = @file_get_contents($url);

        if ($response !== false) {
            $data = json_decode($response, true);
            if (!empty($data['features'])) {
                $candidates = [];

                foreach ($data['features'] as $feature) {
                    $attr = $feature['attributes'] ?? [];
                    if (empty($attr['APN'])) continue;

                    $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

                    // Skip the primary parcel
                    if ($apnRaw === $primaryApn) continue;

                    $candidates[] = [
                        'parcelNumber' => $apnRaw,
                        'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
                        'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
                        'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
                        'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
                        'source'       => 'arcgis_address'
                    ];
                }

                $result['candidateParcels'] = $candidates;
                $result['candidateParcelCount'] = count($candidates);
            }
        }
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

    // parcelCount = Primary + Candidates
    $result['parcelCount'] = ($result['primaryParcel'] ? 1 : 0) + $result['candidateParcelCount'];

    // Legacy compatibility
    if ($result['primaryParcel'] !== null) {
        $result['parcelDetails'] = [$result['primaryParcel']];
    } else {
        $result['parcelDetails'] = $result['candidateParcels'];
    }

    return $result;
}