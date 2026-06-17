<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.13.1 — Improved Jurisdiction + Full Candidates
 * Primary: Lat/Lng point-in-polygon
 * Candidates: Address-based search
 * Jurisdiction: Prefers coordinate-aware resolution
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
    ?string $searchAddress = null
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
    ];

    $original = trim($searchAddress ?? '');
    $normalized = normalizeParcelSearchAddress($original);

    error_log('[RESOLVE-PARCEL] === START ===');
    error_log('[RESOLVE-PARCEL] Lat: ' . ($latitude ?? 'NULL'));
    error_log('[RESOLVE-PARCEL] Lng: ' . ($longitude ?? 'NULL'));
    error_log('[RESOLVE-PARCEL] County: ' . ($county ?? 'NULL'));
    error_log('[RESOLVE-PARCEL] Search Address: ' . $original);

    // Extract street number (for address fallbacks)
    preg_match('/^(\d+)/', $normalized, $numMatch);
    $targetNumber = $numMatch[1] ?? '';

    $primaryFound = false;

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
                    $primaryFound = true;
                    $result['searchSource'] = 'arcgis_coordinate';
                    $result['searchTier'] = 'coordinate';

                    error_log('[RESOLVE-PARCEL] ✅ Tier 1 Coordinate primaryParcel: ' . $coordParcels[0]['parcelNumber']);
                }
            }
        }
    }

    // =====================================================
    // TIER 3: ArcGIS Address Search → CANDIDATES (always run)
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
    // FINALIZE: Jurisdiction (Coordinate-First)
    // =====================================================
    $jurRaw = null;

    // Prefer jurisdiction from primary parcel (coordinate)
    if ($result['primaryParcel'] !== null) {
        $jurRaw = $result['primaryParcel']['jurisdiction'] ?? $result['primaryParcel']['city'] ?? '';
    }

    // If parcel metadata is empty/invalid ("NO CITY/TOWN" or blank), fall back to coordinate jurisdiction resolver
    if (empty($jurRaw) || stripos($jurRaw, 'NO CITY') !== false || stripos($jurRaw, 'MARICOPA') !== false) {
        error_log('[RESOLVE-PARCEL] Parcel jurisdiction weak — attempting coordinate jurisdiction fallback');
        if ($latitude !== null && $longitude !== null && function_exists('resolveJurisdictionByCoordinates')) {
            $coordJur = resolveJurisdictionByCoordinates($latitude, $longitude);
            if (!empty($coordJur['label'])) {
                $jurRaw = $coordJur['label'];
                error_log('[RESOLVE-PARCEL] Used coordinate jurisdiction: ' . $jurRaw);
            }
        }
    }

    // Final jurisdiction resolution
    if (!empty($jurRaw)) {
        $jur = resolveJurisdiction($jurRaw);
        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($jurRaw));
        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';
    } elseif ($county) {
        $result['jurisdictionName'] = ucwords(strtolower($county));
        $result['jurisdictionType'] = 'County';
    }

    // Success determination
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
                  ' | Jurisdiction: ' . ($result['jurisdictionName'] ?? ''));
    } else {
        error_log('[RESOLVE-PARCEL] ❌ No parcels found');
    }

    return $result;
}