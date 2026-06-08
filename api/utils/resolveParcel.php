<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 1.1.0
 */

function resolveParcel(
    ?float $latitude = null,
    ?float $longitude = null,
    ?string $county = null,
    ?string $countyFips = null
): array {

    $result = [
        'success'          => false,
        'parcelCount'      => 0,
        'parcelDetails'    => [],
        'jurisdictionName' => null,
        'jurisdictionType' => null
    ];

    if ($latitude === null || $longitude === null) {
        error_log('[RESOLVE-PARCEL] Missing latitude or longitude');
        return $result;
    }

    if ($countyFips !== '013' && strtolower($county ?? '') !== 'maricopa') {
        error_log('[RESOLVE-PARCEL] Non-Maricopa county — skipping');
        return $result;
    }

    // =====================================================
    // BUILD BUFFERED QUERY (more reliable than exact point)
    // =====================================================
    $point = "{$longitude},{$latitude}";

    $queryParams = [
        'where'          => '1=1',
        'geometry'       => $point,
        'geometryType'   => 'esriGeometryPoint',
        'inSR'           => '4326',
        'spatialRel'     => 'esriSpatialRelIntersects',
        'distance'       => 8,                          // ← Small buffer (feet)
        'units'          => 'esriSRUnit_Foot',
        'outFields'      => 'APN,OWNER_NAME,PROP_USE_DESC,SITE_ADDR,CITY,STATE,ZIP,MCR,TRACT,BLK,LOT_SIZE,SUBDIVISION',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ];

    $url = 'https://gis.maricopa.gov/gis/rest/services/Parcels/MapServer/0/query?' . 
           http_build_query($queryParams);

    $context = stream_context_create([
        'http' => ['timeout' => 12]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('[RESOLVE-PARCEL] ArcGIS request failed');
        return $result;
    }

    $data = json_decode($response, true);

    if (!isset($data['features']) || !is_array($data['features'])) {
        error_log('[RESOLVE-PARCEL] Invalid ArcGIS response');
        return $result;
    }

    $parcelDetails = [];

    foreach ($data['features'] as $feature) {
        $attrs = $feature['attributes'] ?? [];

        $parcelDetails[] = [
            'parcelNumber' => $attrs['APN'] ?? null,
            'ownerName'    => $attrs['OWNER_NAME'] ?? null,
            'propertyUse'  => $attrs['PROP_USE_DESC'] ?? null,
            'siteAddress'  => $attrs['SITE_ADDR'] ?? null,
            'city'         => $attrs['CITY'] ?? null,
            'state'        => $attrs['STATE'] ?? null,
            'zip'          => $attrs['ZIP'] ?? null,
            'mcr'          => $attrs['MCR'] ?? null,
            'subdivision'  => $attrs['SUBDIVISION'] ?? null,
            'lotSize'      => $attrs['LOT_SIZE'] ?? null
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;

    if (!empty($parcelDetails)) {
        $result['jurisdictionName'] = $parcelDetails[0]['city'] ?? 'Maricopa County';
        $result['jurisdictionType'] = 'City';
    }

    error_log('[RESOLVE-PARCEL] Resolved ' . $result['parcelCount'] . ' parcel(s)');

    return $result;
}