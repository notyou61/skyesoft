<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.13.2 — Jurisdiction + Postal vs Permitting Awareness
 * Primary: Lat/Lng → Parcel
 * Candidates: Address search
 * Jurisdiction: Smart fallback with postal/permit distinction
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
        // New: Distinguish postal vs permitting jurisdiction
        'postalCity'           => null,
        'permittingJurisdiction' => null,
        'note'                 => null,
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
                        'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),      // Postal city from assessor
                        'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),       // Permitting jurisdiction
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
    // JURISDICTION RESOLUTION (Postal vs Permitting)
    // =====================================================
    $parcelJurRaw = null;
    if ($result['primaryParcel'] !== null) {
        $parcelJurRaw = $result['primaryParcel']['jurisdiction'] ?? $result['primaryParcel']['city'] ?? '';
        $result['postalCity'] = $result['primaryParcel']['city'] ?? null;
    }

    $finalJurRaw = $parcelJurRaw;

    // If parcel jurisdiction is weak ("NO CITY/TOWN", blank, or Maricopa County fallback)
    if (empty($finalJurRaw) || stripos($finalJurRaw, 'NO CITY') !== false || stripos($finalJurRaw, 'MARICOPA') !== false) {
        error_log('[RESOLVE-PARCEL] Weak parcel jurisdiction — using coordinate jurisdiction resolver');
        if ($latitude !== null && $longitude !== null && function_exists('resolveJurisdictionByCoordinates')) {
            $coordJur = resolveJurisdictionByCoordinates($latitude, $longitude);
            if (!empty($coordJur['label'])) {
                $finalJurRaw = $coordJur['label'];
                $result['note'] = 'Jurisdiction derived from coordinates (postal city differs from permitting authority)';
            }
        } elseif ($county) {
            $finalJurRaw = $county;
            $result['note'] = 'Jurisdiction defaulted to county';
        }
    }

    // Final jurisdiction normalization
    if (!empty($finalJurRaw)) {
        $jur = resolveJurisdiction($finalJurRaw);
        $result['permittingJurisdiction'] = $jur['label'] ?? ucwords(strtolower($finalJurRaw));
        $result['jurisdictionName']       = $result['permittingJurisdiction'];
        $result['jurisdictionType']       = $jur['jurisdictionType'] ?? 'City';
    }

    // Success flag
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
                  ' | Note: ' . ($result['note'] ?? ''));
    }

    return $result;
}