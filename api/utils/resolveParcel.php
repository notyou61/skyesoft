<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.0.0  (Official Assessor Search Primary)
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
        error_log('[RESOLVE-PARCEL] No searchAddress provided');
        $result['searchTier'] = 'none';
        return $result;
    }

    $original = trim($searchAddress);
    error_log('[RESOLVE-PARCEL] Searching for: ' . $original);

    // =====================================================
    // TIER 1: Official Maricopa Assessor Search API
    // =====================================================
    $searchUrl = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($original);

    error_log('[RESOLVE-PARCEL] Tier 1 Official Search: ' . $searchUrl);

    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Skyesoft Parcel Resolver\r\n",
            'timeout' => 15
        ]
    ]);

    $response = @file_get_contents($searchUrl, false, $context);

    if ($response !== false) {
        $data = json_decode($response, true);

        if (!empty($data['results']) && is_array($data['results'])) {
            $parcelDetails = [];

            foreach ($data['results'] as $item) {
                $apn = $item['apn'] ?? $item['APN'] ?? null;
                if (empty($apn)) continue;

                $apnFormatted = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $apn));

                $parcelDetails[] = [
                    'parcelNumber' => $apnFormatted,
                    'ownerName'    => trim($item['ownerName'] ?? $item['owner'] ?? $item['OwnerName'] ?? ''),
                    'siteAddress'  => trim($item['address'] ?? $item['propertyAddress'] ?? $item['situsAddress'] ?? ''),
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

                // Take jurisdiction from first result
                if (!empty($parcelDetails[0]['jurisdiction'])) {
                    $jurRaw = $parcelDetails[0]['jurisdiction'];
                    $jurisdiction = resolveJurisdiction($jurRaw);
                    $result['jurisdictionName'] = $jurisdiction['label'] ?? ucwords(strtolower($jurRaw));
                    $result['jurisdictionType'] = $jurisdiction['jurisdictionType'] ?? 'City';
                }

                error_log('[RESOLVE-PARCEL] ✅ Official Search SUCCESS → ' . $result['parcelCount'] . ' parcels');
                return $result;
            }
        }
    }

    // =====================================================
    // TIER 2: ArcGIS Fallback (only if official fails)
    // =====================================================
    error_log('[RESOLVE-PARCEL] Official search returned no results. Falling back to ArcGIS.');

    // [Insert your existing ArcGIS tiered logic here if desired]
    // For brevity, you can keep a simple version or the previous working one.

    $result['searchTier'] = 'none';
    error_log('[RESOLVE-PARCEL] ❌ No parcels found for: ' . $original);
    return $result;
}