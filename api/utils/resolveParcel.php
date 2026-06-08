<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 1.0.0
 *
 * Purpose:
 *   Resolve parcel information using latitude/longitude against
 *   Maricopa County GIS data.
 *
 * Inputs:
 *   - latitude
 *   - longitude
 *   - county (optional filter)
 *   - countyFips (optional filter)
 *
 * Output:
 *   [
 *     'success'        => bool,
 *     'parcelCount'    => int,
 *     'parcelDetails'  => array,
 *     'jurisdictionName' => string|null,
 *     'jurisdictionType' => string|null
 *   ]
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

    // =====================================================
    // BASIC VALIDATION
    // =====================================================
    if ($latitude === null || $longitude === null) {
        error_log('[RESOLVE-PARCEL] Missing latitude or longitude');
        return $result;
    }

    // Only proceed for Maricopa County for now
    if ($countyFips !== '013' && strtolower($county ?? '') !== 'maricopa') {
        error_log('[RESOLVE-PARCEL] Non-Maricopa county — skipping parcel lookup');
        return $result;
    }

    // =====================================================
    // MARICOPA COUNTY PARCEL QUERY (ArcGIS)
    // =====================================================
    $point = "{$longitude},{$latitude}";

    $queryParams = [
        'where'        => '1=1',
        'geometry'     => $point,
        'geometryType' => 'esriGeometryPoint',
        'inSR'         => '4326',
        'spatialRel'   => 'esriSpatialRelIntersects',
        'outFields'    => 'APN,OWNER_NAME,PROP_USE_DESC,SITE_ADDR,CITY,STATE,ZIP',
        'returnGeometry' => 'false',
        'f'            => 'json'
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
        error_log('[RESOLVE-PARCEL] Invalid response from ArcGIS');
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
            'zip'          => $attrs['ZIP'] ?? null
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;

    // Simple jurisdiction inference (can be expanded later)
    if (!empty($parcelDetails)) {
        $result['jurisdictionName'] = $parcelDetails[0]['city'] ?? 'Maricopa County';
        $result['jurisdictionType'] = 'City';
    }

    error_log('[RESOLVE-PARCEL] Resolved ' . $result['parcelCount'] . ' parcel(s)');

    return $result;
}