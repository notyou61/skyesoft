<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.1.0  (Official API with Token + Fallback)
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
        'jurisdictionKey'  => null,
        'searchSource'     => null,
        'searchTier'       => null,
    ];

    if (empty($searchAddress)) {
        $result['searchTier'] = 'none';
        return $result;
    }

    $original = trim($searchAddress);
    $token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';

    error_log('[RESOLVE-PARCEL] Searching: ' . $original);

    // =====================================================
    // TIER 1: Official Assessor Search API
    // =====================================================
    if (!empty($token)) {
        $url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($original);

        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Skyesoft Parcel Resolver\r\nAccept: application/json\r\nAuthorization: $token\r\n",
                'timeout' => 15
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response !== false) {
            $data = json_decode($response, true);

            if (!empty($data['results']) && is_array($data['results'])) {
                $parcelDetails = [];

                foreach ($data['results'] as $item) {
                    $apn = $item['apn'] ?? $item['APN'] ?? null;
                    if (empty($apn)) continue;

                    $parcelDetails[] = [
                        'parcelNumber' => strtoupper(preg_replace('/[^A-Z0-9-]/', '', $apn)),
                        'ownerName'    => trim($item['ownerName'] ?? $item['owner'] ?? ''),
                        'siteAddress'  => trim($item['address'] ?? $item['propertyAddress'] ?? ''),
                        'city'         => trim($item['city'] ?? $item['situsCity'] ?? ''),
                        'jurisdiction' => trim($item['jurisdiction'] ?? $item['situsCity'] ?? ''),
                        'source'       => 'mca_official_search'
                    ];
                }

                if (!empty($parcelDetails)) {
                    $result['success']       = true;
                    $result['parcelCount']   = count($parcelDetails);
                    $result['parcelDetails'] = $parcelDetails;
                    $result['searchSource']  = 'mca_official_search';
                    $result['searchTier']    = 'official';

                    if (!empty($parcelDetails[0]['jurisdiction'])) {
                        $jur = resolveJurisdiction($parcelDetails[0]['jurisdiction']);
                        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($parcelDetails[0]['jurisdiction']));
                        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';
                    }

                    error_log('[RESOLVE-PARCEL] ✅ Official Search SUCCESS (' . $result['parcelCount'] . ' parcels)');
                    return $result;
                }
            }
        }
    }

    // =====================================================
    // TIER 2: ArcGIS Fallback
    // =====================================================
    error_log('[RESOLVE-PARCEL] Official search failed → using ArcGIS fallback');

    // [Your previous reliable ArcGIS code here — or the version I gave earlier]

    $result['searchTier'] = 'arcgis_fallback';
    // ... (keep your working ArcGIS logic)

    return $result;
}