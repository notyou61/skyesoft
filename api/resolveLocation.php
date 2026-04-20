<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — resolveLocation.php
//  Version: 1.2.0
// ======================================================================

#region SECTION 0 — Core Function

// Resolve Location Function — main entry point for geocoding and enrichment
function resolveLocation(array $input): array {

    #region STEP 1 — Initialization Results

    $result = [
        'placeId' => null,
        'lat' => null,
        'lng' => null,
        'city' => null,
        'state' => null,
        'zip' => null,
        'address' => null,
        'suite' => null, // 🔥 ADD THIS
        'county' => null,
        'countyFips' => null,
        'jurisdiction' => null,
        'parcelNumber' => null
    ];

    #endregion

    #region STEP 2 — Google (Geocoding Source of Truth)

    error_log('[GEOCODE INPUT] ' . json_encode($input));

    $addressString = trim(
        ($input['address'] ?? '') . ', ' .
        ($input['city'] ?? '') . ', ' .
        ($input['state'] ?? '') . ' ' .
        ($input['zip'] ?? '')
    );

    $input['address'] = $addressString;

    $google = getGoogleGeocode($input);

    error_log('[GOOGLE RESULT] ' . json_encode($google));

    if (
        !$google ||
        empty($google['placeId']) ||
        empty($google['lat']) ||
        empty($google['lng'])
    ) {
        return $result;
    }

        // Canonical mapping (Google is authoritative, but controlled)

        // Raw formatted address from Google
        $rawAddress = trim($google['address'] ?? '');

        // Extract clean street only
        // 🔥 Split street + suite (canonical normalization)
        $split = splitAddressSuite($rawAddress);

        // Assign ONLY schema-compliant fields
        $result['address'] = $split['street'];   // street ONLY
        $result['suite']   = $split['suite'];    // extracted suite
        $result['city']    = $google['city']  ?? null;
        $result['state']   = $google['state'] ?? null;
        $result['zip']     = $google['zip']   ?? null;

        // Preserve useful metadata
        $result['placeId'] = $google['placeId'] ?? null;
        $result['lat']     = $google['lat'] ?? null;
        $result['lng']     = $google['lng'] ?? null;

    #endregion

    #region STEP 3 — Census (Geography Enrichment)

    $census = getCensusGeography($result['lat'], $result['lng']);

    $result['county'] = $census['county'] ?? null;
    $result['countyFips'] = $census['countyFips'] ?? null;

    #endregion

    #region STEP 4 — Maricopa Parcel (Authoritative Jurisdiction Source)

    if (
        $result['state'] === 'AZ' &&
        strpos(strtoupper($result['county'] ?? ''), 'MARICOPA') !== false
    ) {

        // Canonical location parts
        $street = $result['address'] ?? '';
        $suite  = $result['suite'] ?? '';
        $city   = $result['city'] ?? '';
        $state  = $result['state'] ?? 'AZ';
        $zip    = $result['zip'] ?? '';

        if (!empty($street) && !empty($city)) {

            error_log('[PARCEL INPUT] ' . json_encode([
                'street' => $street,
                'suite'  => $suite,
                'city'   => $city,
                'state'  => $state,
                'zip'    => $zip
            ]));

            $parcel = getMaricopaParcelFromAddress(
                $street,
                $city,
                $state,
                $zip,
                $suite
            );

            error_log('[PARCEL RESULT] ' . json_encode($parcel));

            if (is_array($parcel) && !empty($parcel['parcelNumber'])) {

                // ✅ Parcel success → full enrichment
                $result['parcelNumber'] = $parcel['parcelNumber'];
                $result['jurisdiction'] = $parcel['jurisdiction'] ?? 'Maricopa County';

            } else {

                // ❌ Parcel failed → jurisdiction UNKNOWN (by design)
                $result['parcelNumber'] = null;
                $result['jurisdiction'] = 'Unknown';
            }

        } else {

            // ❌ No usable address → unknown
            $result['parcelNumber'] = null;
            $result['jurisdiction'] = 'Unknown';
        }

    } else {

        // ❌ Outside Maricopa → unknown
        $result['parcelNumber'] = null;
        $result['jurisdiction'] = 'Unknown';
    }

    #endregion

    #region STEP 5 — Results

    return $result;

    #endregion
}

#endregion

#region SECTION 1 — Helpers

// Extract street address (remove suite/unit and extra commas)
function extractStreetAddress(string $fullAddress): string {
    $parts = array_map('trim', explode(',', $fullAddress));
    return $parts[0] ?? $fullAddress;
}
// Split address into street + suite
function splitAddressSuite(string $streetPart): array {

    $streetPart = trim($streetPart);

    if ($streetPart === '') {
        return ['street' => null, 'suite' => null];
    }

    // Extract suite
    if (preg_match('/\b(STE|SUITE|UNIT|#)\s*([A-Z0-9\-]+)/i', $streetPart, $m)) {

        $suite = strtoupper($m[1]) . ' ' . strtoupper($m[2]);

        // Remove suite from street
        $street = str_replace($m[0], '', $streetPart);
        $street = preg_replace('/\s+/', ' ', trim($street));

        return [
            'street' => $street,
            'suite'  => $suite
        ];
    }

    return [
        'street' => $streetPart,
        'suite'  => null
    ];
}

#endregion

#region SECTION 2 — Census

function getCensusGeography(?float $lat, ?float $lng): array {

    if (!$lat || !$lng) return [];

    $url = "https://geocoding.geo.census.gov/geocoder/geographies/coordinates"
        . "?x={$lng}&y={$lat}&benchmark=Public_AR_Current&vintage=Current_Current&format=json";

    $response = @file_get_contents($url);
    if (!$response) return [];

    $data = json_decode($response, true);

    $geo = $data['result']['geographies']['Counties'][0] ?? null;

    return [
        'county' => $geo['NAME'] ?? null,
        'countyFips' => $geo['COUNTY'] ?? null
    ];
}

#endregion

#region SECTION 3 — Maricopa API (FINAL FIXED VERSION)

// Maricopa Parcel Resolver — authoritative parcel + jurisdiction from MCA
function getMaricopaParcelFromAddress(
    string $street,
    string $city,
    string $state = 'AZ',
    string $zip = '',
    string $suite = ''
): ?array {

    #region STEP 1 — Init

    $apiKey = getenv("MARICOPA_COUNTY_API_KEY");

    if (
        empty($apiKey) ||
        empty(trim($street)) ||
        empty(trim($city))
    ) {
        return null;
    }

    #endregion

    #region STEP 2 — Build FULL Query (Critical Fix)

    // 🔥 Build full address string for MCA search
    $fullQuery = trim(
        $street .
        (!empty($suite) ? ' ' . $suite : '') .
        ', ' . $city .
        ', ' . $state .
        ' ' . $zip
    );

    error_log('[MCA FULL QUERY] ' . $fullQuery);

    #endregion

    #region STEP 3 — Normalize + Clean (Street Only for Matching)

    $normalize = function(string $str): string {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($str)));
    };

    // Remove suite/unit
    $streetClean = preg_replace('/\b(UNIT|STE|SUITE|#)\s*[\w-]+\b/i', '', $street);

    // Normalize spacing
    $streetClean = preg_replace('/\s+/', ' ', $streetClean);

    $targetStreet = trim($streetClean);
    $targetNorm   = $normalize($targetStreet);

    error_log('[MCA CLEAN STREET] ' . $targetStreet);

    #endregion

    #region STEP 4 — Progressive Query Attempts

    $attempts = array_unique(array_filter([
        $fullQuery,        // 🔥 Primary (with suite + city)
        $targetStreet,     // Clean street only
        preg_replace('/\s+(RD|ST|AVE|BLVD|DR|LN)$/i', '', $targetStreet), // remove suffix
        preg_replace('/^\d+\s+/', '', $targetStreet) // remove number
    ]));

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "AUTHORIZATION: {$apiKey}\r\nUser-Agent: Skyesoft\r\n"
        ]
    ]);

    $data = null;

    foreach ($attempts as $attempt) {

        $attempt = trim($attempt);
        if ($attempt === '') continue;

        $query = urlencode($attempt);
        $url   = "https://mcassessor.maricopa.gov/search/property/?q={$query}";

        $response = @file_get_contents($url, false, $context);

        file_put_contents(
            __DIR__ . '/mca_debug.log',
            date('Y-m-d H:i:s') .
            " | URL: {$url}\nATTEMPT: {$attempt}\nLEN: " .
            ($response !== false ? strlen($response) : 0) . "\n\n",
            FILE_APPEND
        );

        // Skip bad responses
        if (
            $response === false ||
            $response === '' ||
            stripos($response, '<html') !== false
        ) {
            continue;
        }

        $decoded = json_decode($response, true);

        // 🔥 Accept ANY valid result set
        if (is_array($decoded) && !empty($decoded['Results'])) {
            error_log('[MCA SUCCESS QUERY] ' . $attempt);
            $data = $decoded;
            break;
        }
    }

    if (!$data || empty($data['Results'])) {
        error_log('[MCA] No results from any attempt');
        return null;
    }

    #endregion

    #region STEP 5 — Match + Extract (Robust)

    // Extract number from target
    preg_match('/^\d+/', $targetStreet, $tNum);
    $targetNumber = $tNum[0] ?? '';

    // Normalize target street name (remove number)
    $targetStreetName = preg_replace('/^\d+\s+/', '', $targetNorm);

    foreach ($data['Results'] as $r) {

        $apiAddress = trim($r['SitusAddress'] ?? '');
        if ($apiAddress === '') continue;

        $apiNorm = $normalize($apiAddress);

        // Extract number from API
        preg_match('/^\d+/', $apiAddress, $aNum);
        $apiNumber = $aNum[0] ?? '';

        // Normalize API street name
        $apiStreetName = preg_replace('/^\d+\s+/', '', $apiNorm);

        // 🔥 FLEXIBLE MATCH
        if (
            $targetNumber !== '' &&
            $targetNumber === $apiNumber &&
            strpos($apiStreetName, substr($targetStreetName, 0, 6)) !== false
        ) {

            error_log('[MCA MATCH FOUND] ' . $apiAddress);

            // Skip mineral parcels
            $type = strtoupper($r['PropertyType'] ?? '');
            if (strpos($type, 'MINERAL') !== false) continue;

            // Extract APN
            $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($r['APN'] ?? ''));
            if ($apnRaw === '' || strlen($apnRaw) < 8) continue;

            // Format APN
            if (preg_match('/^(\d{3})(\d{2})(\d{3})([A-Z]?)$/', $apnRaw, $m)) {
                $formatted = "{$m[1]}-{$m[2]}-{$m[3]}{$m[4]}";
            } else {
                $formatted = $apnRaw;
            }

            // Jurisdiction
            $jurRaw = strtoupper(trim($r['SitusCity'] ?? ''));

            $jurisdiction = (
                $jurRaw === '' || strpos($jurRaw, 'NO CITY') !== false
            )
                ? 'Maricopa County'
                : ucwords(strtolower($jurRaw));

            return [
                'parcelNumber' => $formatted,
                'jurisdiction' => $jurisdiction
            ];
        }
    }

    #endregion

    error_log("No parcel match for: {$fullQuery}");
    return null;
}

#endregion

#region SECTION 4 — Google

function getGoogleGeocode(array $input): ?array {

    $addressParts = [
        trim((string)($input['address'] ?? '')),
        trim((string)($input['city'] ?? '')),
        trim((string)($input['state'] ?? '')),
        trim((string)($input['zip'] ?? ''))
    ];

    $addressParts = array_filter($addressParts, function ($part) {
        return $part !== '';
    });

    $address = implode(', ', $addressParts);

    if ($address === '') return null;

    $apiKey = getenv("GOOGLE_MAPS_BACKEND_API_KEY");
    if (!$apiKey) return null;

    $url = "https://maps.googleapis.com/maps/api/geocode/json?address="
        . urlencode($address) . "&key={$apiKey}";

    $response = @file_get_contents($url);
    if (!$response) return null;

    $data = json_decode($response, true);

    if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) return null;

    $r = $data['results'][0];
    $location = $r['geometry']['location'] ?? null;

    if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
        return null;
    }

    $city = $state = $zip = null;

    foreach (($r['address_components'] ?? []) as $c) {
        if (in_array('locality', $c['types'] ?? [], true)) $city = $c['long_name'];
        if (in_array('administrative_area_level_1', $c['types'] ?? [], true)) $state = $c['short_name'];
        if (in_array('postal_code', $c['types'] ?? [], true)) $zip = $c['long_name'];
    }

    return [
        'placeId' => $r['place_id'] ?? null,
        'lat' => $location['lat'],
        'lng' => $location['lng'],
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'address' => $r['formatted_address'] ?? $address
    ];
}

#endregion

#region SECTION 5 — Location Decision Engine
function evaluateLocation(array $resolved, PDO $pdo): array {

    // ----------------------------------------
    // A — Invalid (no placeId = hard fail)
    // ----------------------------------------
    if (empty($resolved['placeId'])) {
        return [
            "status" => "error",
            "type" => "invalid",
            "stage" => "google_geocode",
            "message" => "Unable to resolve location (missing placeId)"
        ];
    }

    // ----------------------------------------
    // H — Parcel Enforcement (Maricopa)
    // ----------------------------------------
    if (
        $resolved['state'] === 'AZ' &&
        strpos(strtoupper($resolved['county'] ?? ''), 'MARICOPA') !== false &&
        empty($resolved['parcelNumber'])
    ) {
        return [
            "status" => "error",
            "type" => "parcel_failure",
            "stage" => "parcel_enforcement",
            "message" => "Parcel number required for Maricopa County"
        ];
    }

    // ----------------------------------------
    // C — Duplicate (placeId match)
    // ----------------------------------------
    $stmt = $pdo->prepare("SELECT locationId FROM locations WHERE placeId = ?");
    $stmt->execute([$resolved['placeId']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        return [
            "status" => "resolved_existing",
            "type" => "duplicate",
            "data" => [
                "locationId" => $existing['locationId']
            ]
        ];
    }

    // ----------------------------------------
    // D — Possible Match (simple version)
    // ----------------------------------------
    $stmt = $pdo->prepare("
        SELECT locationId, address, city
        FROM locations
        WHERE city = ?
        AND address LIKE ?
        LIMIT 3
    ");
    $stmt->execute([
        $resolved['city'],
        '%' . substr($resolved['address'], 0, 10) . '%'
    ]);

    $possible = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($possible)) {
        return [
            "status" => "decision",
            "type" => "possible_match",
            "message" => "Similar locations found",
            "data" => $possible
        ];
    }

    // ----------------------------------------
    // A — Valid New
    // ----------------------------------------
    return [
        "status" => "resolved_new",
        "type" => "valid",
        "data" => []
    ];
}

#endregion