<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.14.2 — Google Place Enrichment + Improved Jurisdiction
 * Primary: Lat/Lng → Parcel
 * Candidates: Address search
 * Enrichment: Google Place Details (optional input)
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
 * resolveParcel - Now accepts optional Google Place Details for enrichment
 */
function resolveParcel(
    ?float $latitude = null,
    ?float $longitude = null,
    ?string $county = null,
    ?string $countyFips = null,
    ?string $searchAddress = null,
    ?array $googlePlaceInput = null   // Pass full Place Details response here
): array {

    $result = [
        'success'              => false,
        'primaryParcel'        => null,
        'candidateParcels'     => [],
        'candidateParcelCount' => 0,
        // Legacy compatibility
        'parcelCount'          => 0,
        'parcelDetails'        => [],
        'jurisdictionName'     => null,
        'jurisdictionType'     => null,
        'searchSource'         => null,
        'searchTier'           => null,
        // Postal vs Permitting
        'postalCity'           => null,
        'permittingJurisdiction' => null,
        'note'                 => null,
        // Google Place Enrichment
        'googlePlace'          => null,
    ];

    $original = trim($searchAddress ?? '');
    $normalized = normalizeParcelSearchAddress($original);

    error_log('[RESOLVE-PARCEL] === START ===');
    error_log('[RESOLVE-PARCEL] Lat: ' . ($latitude ?? 'NULL'));
    error_log('[RESOLVE-PARCEL] Lng: ' . ($longitude ?? 'NULL'));
    error_log('[RESOLVE-PARCEL] County: ' . ($county ?? 'NULL'));
    error_log('[RESOLVE-PARCEL] Search Address: ' . $original);

    // Extract street number
    preg_match('/^(\d+)/', $normalized, $numMatch);
    $targetNumber = $numMatch[1] ?? '';

    // =====================================================
    // GOOGLE PLACE ENRICHMENT (if provided)
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

        error_log('[RESOLVE-PARCEL] ✅ Google Place enriched: ' . ($result['googlePlace']['name'] ?? 'N/A'));
    }

    // =====================================================
    // TIER 1: COORDINATE-BASED LOOKUP (Primary)
    // =====================================================
    if ($latitude !== null && $longitude !== null) {
        error_log('[RESOLVE-PARCEL] Attempting Tier 1: Coordinate lookup');

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
                $coordParcels = [];

                foreach ($data['features'] as $feature) {
                    $attr = $feature['attributes'] ?? [];
                    if (empty($attr['APN'])) continue;

                    $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

                    $coordParcels[] = [
                        'parcelNumber' => $apnRaw,
                        'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
                        'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
                        'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
                        'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
                        'source'       => 'arcgis_coordinate'
                    ];
                }

                if (!empty($coordParcels)) {
                    $result['primaryParcel'] = $coordParcels[0];
                    $result['searchSource'] = 'arcgis_coordinate';
                    $result['searchTier'] = 'coordinate';
                    $result['postalCity'] = $coordParcels[0]['city'] ?? null;

                    error_log('[RESOLVE-PARCEL] ✅ Tier 1 Coordinate primaryParcel: ' . $coordParcels[0]['parcelNumber']);
                }
            }
        }
    }

    // =====================================================
    // TIER 3: ArcGIS Address Search → CANDIDATES
    // =====================================================
    if (!empty($normalized)) {
        error_log('[RESOLVE-PARCEL] Attempting Tier 3: ArcGIS Address candidates');
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
                $parcelDetails = [];

                foreach ($data['features'] as $feature) {
                    $attr = $feature['attributes'] ?? [];
                    if (empty($attr['APN'])) continue;

                    $dbAddr = normalizeParcelSearchAddress($attr['PHYSICAL_ADDRESS'] ?? '');

                    preg_match('/^(\d+)/', $dbAddr, $dbNumMatch);
                    if ($targetNumber && ($dbNumMatch[1] ?? '') !== $targetNumber) continue;

                    $similarity = similar_text($dbAddr, $normalized);
                    $contains   = strpos($dbAddr, $normalized) !== false;

                    if (!$contains && $similarity < 75) continue;

                    $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

                    $parcelDetails[] = [
                        'parcelNumber' => $apnRaw,
                        'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
                        'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
                        'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
                        'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
                        'source'       => 'arcgis_address'
                    ];
                }

                if (!empty($parcelDetails)) {
                    $result['candidateParcels']     = $parcelDetails;
                    $result['candidateParcelCount'] = count($parcelDetails);

                    if (empty($result['searchSource'])) {
                        $result['searchSource'] = 'arcgis_address';
                        $result['searchTier']   = 'arcgis';
                    }

                    error_log('[RESOLVE-PARCEL] ✅ Tier 3 Address candidates: ' . $result['candidateParcelCount']);
                }
            }
        }
    }

    // =====================================================
    // JURISDICTION RESOLUTION (Improved - prefers postal city)
    // =====================================================
    $parcelJurRaw = null;
    if ($result['primaryParcel'] !== null) {
        $parcelJurRaw = $result['primaryParcel']['jurisdiction'] ?? $result['primaryParcel']['city'] ?? '';
        $result['postalCity'] = $result['primaryParcel']['city'] ?? null;
    }

    $finalJurRaw = $parcelJurRaw;

    // If GIS returns weak jurisdiction ("NO CITY/TOWN" or "MARICOPA"), prefer the parcel's own city first
    if (empty($finalJurRaw) || stripos($finalJurRaw, 'NO CITY') !== false || stripos($finalJurRaw, 'MARICOPA') !== false) {

        if (!empty($result['postalCity'])) {
            $finalJurRaw = $result['postalCity'];
            $result['note'] = 'Used postal city from parcel (NO CITY/TOWN returned by GIS)';
        } 
        elseif ($latitude !== null && $longitude !== null && function_exists('resolveJurisdictionByCoordinates')) {
            $coordJur = resolveJurisdictionByCoordinates($latitude, $longitude);
            if (!empty($coordJur['label'])) {
                $finalJurRaw = $coordJur['label'];
                $result['note'] = 'Jurisdiction derived from coordinates';
            }
        } 
        elseif ($county) {
            $finalJurRaw = $county;
            $result['note'] = 'Jurisdiction defaulted to county';
        }
    }

    if (!empty($finalJurRaw)) {
        $jur = resolveJurisdiction($finalJurRaw);
        $result['permittingJurisdiction'] = $jur['label'] ?? ucwords(strtolower($finalJurRaw));
        $result['jurisdictionName']       = $result['permittingJurisdiction'];
        $result['jurisdictionType']       = $jur['jurisdictionType'] ?? 'City';
    }

    if ($result['primaryParcel'] !== null || $result['candidateParcelCount'] > 0) {
        $result['success'] = true;
    }

    // Legacy compatibility
    if ($result['primaryParcel'] !== null) {
        $result['parcelDetails'] = [$result['primaryParcel']];
        $result['parcelCount']   = 1;
    } else {
        $result['parcelDetails'] = $result['candidateParcels'];
        $result['parcelCount']   = $result['candidateParcelCount'];
    }

    if ($result['success']) {
        error_log('[RESOLVE-PARCEL] ✅ Final — Primary: ' . ($result['primaryParcel']['parcelNumber'] ?? 'none') .
                  ' | Candidates: ' . $result['candidateParcelCount'] .
                  ' | Jurisdiction: ' . ($result['jurisdictionName'] ?? '') .
                  ' | Google Place: ' . (!empty($result['googlePlace']['name']) ? 'Yes' : 'No'));
    }

    return $result;
}