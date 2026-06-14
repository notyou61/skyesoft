<?php
declare(strict_types=1);

/**
 * Skyesoft — Parcel Resolution Utility
 * Version: 4.1.0
 * Includes reliable MCA function directly (no external require)
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

    // Extract street and city
    $parts = explode(',', $searchAddress);
    $street = trim($parts[0] ?? $searchAddress);
    $city   = trim($parts[1] ?? 'Phoenix');

    error_log('[RESOLVE-PARCEL] Looking up: ' . $searchAddress);

    // Call the reliable MCA function (included below)
    $mcaResult = getMaricopaParcelFromAddress($street, $city);

    if ($mcaResult && !empty($mcaResult['parcelNumber'])) {
        $result['success'] = true;
        $result['parcelCount'] = 1;
        $result['parcelDetails'] = [[
            'parcelNumber' => $mcaResult['parcelNumber'],
            'ownerName'    => $mcaResult['ownerName'] ?? '',
            'siteAddress'  => $searchAddress,
            'city'         => $city,
            'jurisdiction' => $mcaResult['jurisdiction'] ?? 'Phoenix',
            'source'       => 'mca_official_search'
        ]];

        $result['jurisdictionName'] = $mcaResult['jurisdiction'] ?? 'Phoenix';
        $result['jurisdictionType'] = 'City';

        error_log('[RESOLVE-PARCEL] ✅ MCA Success — Parcel ' . $mcaResult['parcelNumber']);
        return $result;
    }

    error_log('[RESOLVE-PARCEL] ❌ MCA lookup failed for ' . $searchAddress);
    return $result;
}

/* =====================================================
   RELIABLE MCA FUNCTION (included directly)
   ===================================================== */
function getMaricopaParcelFromAddress(
    string $street,
    string $city,
    string $state = 'AZ',
    string $zip = '',
    string $suite = ''
): ?array {

    $apiKey = getenv("MARICOPA_COUNTY_API_KEY");

    if (empty($apiKey) || empty(trim($street)) || empty(trim($city))) {
        error_log('[MCA] Missing API key or required inputs');
        return null;
    }

    $street = trim($street);
    $suite  = trim($suite);
    $city   = trim($city);
    $state  = strtoupper(trim($state));
    $zip    = trim($zip);

    $fullQuery = trim($street . ($suite ? ' ' . $suite : '') . ', ' . $city . ', ' . $state . ($zip ? ' ' . $zip : ''));

    error_log('[MCA FULL QUERY] ' . $fullQuery);

    $context = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header'  => "AUTHORIZATION: {$apiKey}\r\nUser-Agent: Skyesoft/1.0\r\n"
        ]
    ]);

    $attempts = [
        $fullQuery,
        $street . ' ' . $city,
        preg_replace('/\s*,\s*.*/', '', $street),
        preg_replace('/\s+(RD|ST|AVE|AV|BLVD|DR|LN|PL|WAY|CT|HWY|PKWY|TER|CIR)$/i', '', $street)
    ];

    $attempts = array_unique(array_filter($attempts, fn($a) => trim($a) !== ''));

    $data = null;

    foreach ($attempts as $attempt) {
        $query = urlencode(trim($attempt));
        $url = "https://mcassessor.maricopa.gov/search/property/?q={$query}";

        $response = @file_get_contents($url, false, $context);

        if ($response && stripos($response, '<html') === false) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && !empty($decoded['Results'])) {
                $data = $decoded;
                error_log('[MCA SUCCESS] with attempt: ' . $attempt);
                break;
            }
        }
    }

    if (!$data || empty($data['Results'])) {
        error_log('[MCA] No results for: ' . $fullQuery);
        return null;
    }

    // Extract target number
    preg_match('/^\d+/', $street, $tNum);
    $targetNumber = $tNum[0] ?? '';

    foreach ($data['Results'] as $r) {
        $apiAddress = trim($r['SitusAddress'] ?? '');
        if (empty($apiAddress)) continue;

        $apiUpper = strtoupper($apiAddress);
        preg_match('/^\d+/', $apiUpper, $aNum);
        $apiNumber = $aNum[0] ?? '';

        if ($targetNumber && $apiNumber && $apiNumber !== $targetNumber) continue;

        $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($r['APN'] ?? ''));
        if (strlen($apnRaw) < 8) continue;

        if (preg_match('/^(\d{3})(\d{2})(\d{3})([A-Z]?)$/', $apnRaw, $m)) {
            $formatted = "{$m[1]}-{$m[2]}-{$m[3]}{$m[4]}";
        } else {
            $formatted = $apnRaw;
        }

        $jurisdiction = ucwords(strtolower(trim($r['SitusCity'] ?? 'Phoenix')));

        error_log('[MCA ACCEPTED] ' . $apiAddress . ' → ' . $formatted);

        return [
            'parcelNumber' => $formatted,
            'jurisdiction' => $jurisdiction,
            'ownerName'    => trim($r['OwnerName'] ?? '')
        ];
    }

    error_log('[MCA] No valid match after filtering');
    return null;
}

#endregion