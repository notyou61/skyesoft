<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 4.2.0 — Using proven ArcGIS logic from working test
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
        'jurisdictionKey'  => null
    ];

    if (!$searchAddress) {
        error_log('[RESOLVE-PARCEL] No searchAddress provided');
        return $result;
    }

    error_log('[RESOLVE-PARCEL] Looking up: ' . $searchAddress);

    // Use the proven lookup from your working test
    $lookup = lookupMaricopaParcels($searchAddress);

    $features = $lookup['features'] ?? [];
    $parcelCount = count($features);

    if ($parcelCount > 0) {
        $result['success'] = true;
        $result['parcelCount'] = $parcelCount;

        foreach ($features as $feature) {
            $a = $feature['attributes'] ?? [];
            $apnRaw = trim($a['APN'] ?? '');

            if (empty($apnRaw)) continue;

            $result['parcelDetails'][] = [
                'parcelNumber' => $apnRaw,
                'ownerName'    => trim($a['OWNER_NAME'] ?? ''),
                'siteAddress'  => trim($a['PHYSICAL_ADDRESS'] ?? $searchAddress),
                'city'         => trim($a['PHYSICAL_CITY'] ?? ''),
                'jurisdiction' => trim($a['JURISDICTION'] ?? ''),
                'source'       => 'arcgis_mcassessor'
            ];
        }

        // Jurisdiction from first parcel
        $firstJur = $result['parcelDetails'][0]['jurisdiction'] ?? '';
        $jurResult = resolveJurisdiction($firstJur);

        $result['jurisdictionName'] = $jurResult['label'] ?? $firstJur;
        $result['jurisdictionType'] = $jurResult['jurisdictionType'] ?? 'City';
        $result['jurisdictionKey']  = $jurResult['jurisdictionKey'] ?? null;

        error_log('[RESOLVE-PARCEL] ✅ Success — Found ' . $parcelCount . ' parcel(s)');
    } else {
        error_log('[RESOLVE-PARCEL] ❌ No parcels found for ' . $searchAddress);
    }

    return $result;
}

// =====================================================
// Proven Lookup Function (from your working test)
// =====================================================
function lookupMaricopaParcels($address) {
    $searchTerms = buildParcelSearchTerms($address);

    foreach ($searchTerms as $term) {
        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" .
            str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'          => $where,
            'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry' => 'false',
            'f'              => 'json'
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        $context = stream_context_create(['http' => ['timeout' => 20]]);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) continue;

        $data = json_decode($response, true);
        if (!empty($data['features'])) {
            return [
                'term'     => $term,
                'features' => $data['features']
            ];
        }
    }

    return ['term' => null, 'features' => []];
}

function buildParcelSearchTerms($address) {
    $normalized = normalizeParcelSearchAddress($address);
    $terms = [];

    $terms[] = $normalized;

    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9]+)/', $normalized, $matches)) {
        $terms[] = trim($matches[1]);
    }

    $terms[] = preg_replace('/\b(RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY)\b/', '', $normalized);

    $terms = array_unique(array_filter(array_map('trim', $terms)));
    return $terms;
}

function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}