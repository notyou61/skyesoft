<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 5.11.0 — Balanced Filtering (Matches Working Behavior)
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
        'success'          => false,
        'parcelCount'      => 0,
        'parcelDetails'    => [],
        'jurisdictionName' => null,
        'jurisdictionType' => null,
        'searchSource'     => null,
        'searchTier'       => null,
    ];

    if (empty($searchAddress)) return $result;

    $original = trim($searchAddress);
    $normalized = normalizeParcelSearchAddress($original);

    // Extract street number (mandatory)
    preg_match('/^(\d+)/', $normalized, $numMatch);
    $targetNumber = $numMatch[1] ?? '';

    error_log('[RESOLVE-PARCEL] Searching: ' . $original . ' | Street Number: ' . $targetNumber);

    // =====================================================
    // TIER 1: Official MCA Search API (preferred when it works)
    // =====================================================
    $token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';
    if (!empty($token)) {
        $url = 'https://mcassessor.maricopa.gov/search/property/?q=' . urlencode($original);

        $context = stream_context_create([
            'http' => [
                'header'  => "AUTHORIZATION: {$token}\r\nAccept: application/json\r\n",
                'timeout' => 10
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response && stripos($response, '<html') === false) {
            $data = json_decode($response, true);

            if (!empty($data['Results'])) {
                $matches = [];

                foreach ($data['Results'] as $r) {
                    $apiAddr = strtoupper($r['SitusAddress'] ?? '');
                    preg_match('/^(\d+)/', $apiAddr, $apiNumMatch);
                    $apiNum = $apiNumMatch[1] ?? '';

                    // Strict street number match
                    if ($targetNumber && $apiNum && $apiNum !== $targetNumber) continue;

                    $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($r['APN'] ?? ''));
                    if (strlen($apnRaw) < 8) continue;

                    $formatted = preg_match('/^(\d{3})(\d{2})(\d{3})([A-Z]?)$/', $apnRaw, $m)
                        ? "{$m[1]}-{$m[2]}-{$m[3]}{$m[4]}"
                        : $apnRaw;

                    $matches[] = [
                        'parcelNumber' => $formatted,
                        'ownerName'    => trim($r['OwnerName'] ?? ''),
                        'siteAddress'  => $r['SitusAddress'] ?? '',
                        'city'         => $r['SitusCity'] ?? '',
                        'jurisdiction' => $r['SitusCity'] ?? 'Maricopa County',
                        'source'       => 'mca_official_search'
                    ];
                }

                if (!empty($matches)) {
                    $result['success']       = true;
                    $result['parcelCount']   = count($matches);
                    $result['parcelDetails'] = $matches;
                    $result['searchSource']  = 'mca_official_search';
                    $result['searchTier']    = 'official';

                    $jur = resolveJurisdiction($matches[0]['jurisdiction']);
                    $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($matches[0]['jurisdiction']));
                    $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';

                    error_log('[RESOLVE-PARCEL] ✅ Official API: ' . count($matches) . ' parcel(s)');
                    return $result;
                }
            }
        }
    }

    // =====================================================
    // TIER 2: ArcGIS Fallback (with balanced filtering)
    // =====================================================
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

    if ($response === false) return $result;

    $data = json_decode($response, true);
    if (empty($data['features'])) return $result;

    $parcelDetails = [];

    foreach ($data['features'] as $feature) {
        $attr = $feature['attributes'] ?? [];
        if (empty($attr['APN'])) continue;

        $dbAddr = normalizeParcelSearchAddress($attr['PHYSICAL_ADDRESS'] ?? '');

        // Strict street number match
        preg_match('/^(\d+)/', $dbAddr, $dbNumMatch);
        if ($targetNumber && ($dbNumMatch[1] ?? '') !== $targetNumber) continue;

        // Reasonable similarity check (not too strict)
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
            'source'       => 'arcgis'
        ];
    }

    if (empty($parcelDetails)) {
        error_log('[RESOLVE-PARCEL] No parcels passed balanced ArcGIS filter');
        return $result;
    }

    $result['success']       = true;
    $result['parcelCount']   = count($parcelDetails);
    $result['parcelDetails'] = $parcelDetails;
    $result['searchSource']  = 'arcgis';
    $result['searchTier']    = 'arcgis';

    if (!empty($parcelDetails)) {
        $jurRaw = $parcelDetails[0]['jurisdiction'] ?? '';
        $jur = resolveJurisdiction($jurRaw);
        $result['jurisdictionName'] = $jur['label'] ?? ucwords(strtolower($jurRaw));
        $result['jurisdictionType'] = $jur['jurisdictionType'] ?? 'City';
    }

    error_log('[RESOLVE-PARCEL] ✅ ArcGIS result: ' . $result['parcelCount'] . ' parcel(s)');

    return $result;
}