<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.7.0 — Hybrid (Official API + ArcGIS Fallback)
 */

require_once __DIR__ . '/resolveJurisdiction.php';

function normalizeParcelSearchAddress($address) {
    $address = strtoupper(trim($address));
    $address = preg_replace('/\s+(#|STE|SUITE|UNIT|APT)\s*[A-Z0-9\-]+/i', '', $address);
    $address = preg_replace('/[^A-Z0-9\s]/', ' ', $address);
    $address = preg_replace('/\s+/', ' ', $address);
    return trim($address);
}

function buildParcelSearchTerms($address) {
    $normalized = normalizeParcelSearchAddress($address);
    $terms = [$normalized];

    if (preg_match('/^(\d+\s+[NSEW]?\s*[A-Z0-9\s]+)/', $normalized, $matches)) {
        $terms[] = trim($matches[1]);
    }

    $terms[] = preg_replace('/\b(RD|ROAD|ST|STREET|AVE|AVENUE|BLVD|DR|DRIVE|LN|LANE|WAY|PKWY)\b/', '', $normalized);

    return array_unique(array_filter(array_map('trim', $terms)));
}

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

    if (empty($searchAddress)) return $result;

    $original = trim($searchAddress);
    $normalizedInput = normalizeParcelSearchAddress($original);
    $token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';

    error_log('[RESOLVE-PARCEL] Searching: ' . $original);

    // =====================================================
    // TIER 1: Official MCA Search API (uses your API key)
    // =====================================================
    if (!empty($token)) {
        $attempts = [
            $original,
            $normalizedInput,
            preg_replace('/\s*,\s*.*/', '', $original),
        ];

        foreach ($attempts as $attempt) {
            if (strlen(trim($attempt)) < 5) continue;

            $url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode(trim($attempt));

            $context = stream_context_create([
                'http' => [
                    'header'  => "AUTHORIZATION: {$token}\r\nAccept: application/json\r\n",
                    'timeout' => 12
                ]
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response && stripos($response, '<html') === false) {
                $data = json_decode($response, true);
                if (!empty($data['Results'])) {
                    // Simple filter by street number
                    preg_match('/^\d+/', $normalizedInput, $tNum);
                    $targetNum = $tNum[0] ?? '';

                    foreach ($data['Results'] as $r) {
                        $apiAddr = strtoupper($r['SitusAddress'] ?? '');
                        preg_match('/^\d+/', $apiAddr, $aNum);
                        $apiNum = $aNum[0] ?? '';

                        if ($targetNum && $apiNum && $apiNum !== $targetNum) continue;

                        $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($r['APN'] ?? ''));
                        if (strlen($apnRaw) < 8) continue;

                        if (preg_match('/^(\d{3})(\d{2})(\d{3})([A-Z]?)$/', $apnRaw, $m)) {
                            $formatted = "{$m[1]}-{$m[2]}-{$m[3]}{$m[4]}";
                        } else {
                            $formatted = $apnRaw;
                        }

                        $result['success'] = true;
                        $result['parcelCount'] = 1;
                        $result['parcelDetails'] = [[
                            'parcelNumber' => $formatted,
                            'ownerName'    => trim($r['OwnerName'] ?? ''),
                            'siteAddress'  => $r['SitusAddress'] ?? '',
                            'city'         => $r['SitusCity'] ?? '',
                            'jurisdiction' => $r['SitusCity'] ?? 'Maricopa County',
                            'source'       => 'mca_official_search'
                        ]];
                        $result['searchSource'] = 'mca_official_search';
                        $result['searchTier']   = 'official';

                        $jur = resolveJurisdiction($r['SitusCity'] ?? '');
                        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($r['SitusCity'] ?? 'Maricopa County'));
                        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';

                        error_log('[RESOLVE-PARCEL] ✅ Official API success: ' . $formatted);
                        return $result;
                    }
                }
            }
        }
    }

    // =====================================================
    // TIER 2: ArcGIS Fallback (proven multi-term logic)
    // =====================================================
    $searchTerms = buildParcelSearchTerms($original);
    $data = null;
    $matchedTerm = null;

    foreach ($searchTerms as $term) {
        $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . str_replace("'", "''", $term) . "%')";

        $params = http_build_query([
            'where'             => $where,
            'outFields'         => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
            'returnGeometry'    => 'false',
            'f'                 => 'json',
            'resultRecordCount' => 50
        ]);

        $url = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query?' . $params;

        $response = @file_get_contents($url);
        if ($response === false) continue;

        $data = json_decode($response, true);
        if (empty($data['features'])) continue;

        // Reasonable post-filter
        $filtered = [];
        foreach ($data['features'] as $feature) {
            $attr = $feature['attributes'] ?? [];
            $dbAddr = normalizeParcelSearchAddress($attr['PHYSICAL_ADDRESS'] ?? '');

            if (strpos($dbAddr, $normalizedInput) !== false || 
                similar_text($dbAddr, $normalizedInput) > 80) {
                $filtered[] = $attr;
            }
        }

        if (!empty($filtered)) {
            $matchedTerm = $term;
            $data['features'] = $filtered;
            break;
        }
    }

    if (empty($data['features'])) {
        error_log('[RESOLVE-PARCEL] No parcels found after both tiers');
        return $result;
    }

    $parcelDetails = [];
    foreach ($data['features'] as $feature) {
        $attr = $feature['attributes'] ?? [];
        if (empty($attr['APN'])) continue;

        $apnRaw = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $attr['APN']));

        $parcelDetails[] = [
            'parcelNumber' => $apnRaw,
            'ownerName'    => trim($attr['OWNER_NAME'] ?? ''),
            'siteAddress'  => trim($attr['PHYSICAL_ADDRESS'] ?? ''),
            'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
            'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
            'source'       => 'arcgis'
        ];
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;
    $result['searchSource']  = 'arcgis';
    $result['searchTier']    = 'arcgis_fallback';

    if (!empty($parcelDetails)) {
        $jurRaw = $parcelDetails[0]['jurisdiction'] ?? '';
        $jur = resolveJurisdiction($jurRaw);
        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($jurRaw));
        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';
    }

    error_log('[RESOLVE-PARCEL] ✅ ArcGIS fallback: ' . $result['parcelCount'] . ' parcels');

    return $result;
}