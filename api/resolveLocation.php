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
        $street = extractStreetAddress($rawAddress);

        // Assign ONLY schema-compliant fields
        $result['address'] = $street;
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

        // Extract clean street
        $street = extractStreetAddress($google['address'] ?? '');
        $street = preg_replace('/\s+/', ' ', $street);
        $street = trim($street);

        if (!empty($street) && !empty($google['city'])) {

            error_log('[PARCEL INPUT] ' . json_encode([
                'street' => $street,
                'city'   => $google['city']
            ]));

            $parcel = getMaricopaParcelFromAddress(
                $street,
                $google['city'] ?? '',
                $google['state'] ?? 'AZ',
                $google['zip'] ?? ''
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

function extractStreetAddress(string $fullAddress): string {
    $parts = array_map('trim', explode(',', $fullAddress));
    return $parts[0] ?? $fullAddress;
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
    string $zip = ''
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
    $fullQuery = trim("{$street}, {$city}, {$state} {$zip}");

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

    $attempts = [
        $fullQuery,       // 🔥 PRIMARY (full context)
        $targetStreet,    // fallback
        preg_replace('/\s+(RD|ST|AVE|BLVD|DR|LN)$/i', '', $targetStreet),
        preg_replace('/^\d+\s+/', '', $targetStreet)
    ];

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "AUTHORIZATION: {$apiKey}\r\nUser-Agent: Skyesoft\r\n"
        ]
    ]);

    $data = null;

    foreach ($attempts as $attempt) {

        if (empty(trim($attempt))) continue;

        $query = urlencode(trim($attempt));
        $url   = "https://mcassessor.maricopa.gov/search/property/?q={$query}";

        $response = @file_get_contents($url, false, $context);

        file_put_contents(
            __DIR__ . '/mca_debug.log',
            date('Y-m-d H:i:s') .
            " | URL: {$url}\nATTEMPT: {$attempt}\nLEN: " .
            ($response !== false ? strlen($response) : 0) . "\n\n",
            FILE_APPEND
        );

        if (
            $response === false ||
            $response === '' ||
            stripos($response, '<html') !== false
        ) {
            continue;
        }

        $decoded = json_decode($response, true);

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

    #region STEP 5 — Match + Extract

    preg_match('/^\d+/', $targetStreet, $numMatch);
    $targetNumber = $numMatch[0] ?? '';

    foreach ($data['Results'] as $r) {

        $apiAddress = trim($r['SitusAddress'] ?? '');
        if ($apiAddress === '') continue;

        $apiNorm = $normalize($apiAddress);

        if (
            $targetNumber !== '' &&
            strpos($apiNorm, $targetNumber) === 0 &&
            strpos($apiNorm, substr($targetNorm, 0, 8)) !== false
        ) {

            error_log('[MCA MATCH FOUND] ' . $apiAddress);

            $type = strtoupper($r['PropertyType'] ?? '');
            if (strpos($type, 'MINERAL') !== false) continue;

            $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($r['APN'] ?? ''));
            if ($apnRaw === '' || strlen($apnRaw) < 8) continue;

            if (preg_match('/^(\d{3})(\d{2})(\d{3})([A-Z]?)$/', $apnRaw, $m)) {
                $formatted = "{$m[1]}-{$m[2]}-{$m[3]}{$m[4]}";
            } else {
                $formatted = $apnRaw;
            }

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