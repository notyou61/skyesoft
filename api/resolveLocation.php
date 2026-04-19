<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — resolveLocation.php
//  Version: 1.2.0
// ======================================================================

error_log("RUNNING NEW VERSION — NO PENDING");

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

    // Canonical merge (Google is authoritative)
    $result = array_merge($result, $google);

    #endregion

    #region STEP 3 — Census (Geography Enrichment)

    $census = getCensusGeography($result['lat'], $result['lng']);

    $result['county'] = $census['county'] ?? null;
    $result['countyFips'] = $census['countyFips'] ?? null;

    #endregion

    #region STEP 4 — Maricopa Parcel (Conditional Lookup)

    if (
        $result['state'] === 'AZ' &&
        strpos(strtoupper($result['county'] ?? ''), 'MARICOPA') !== false
    ) {

        // DRY: use helper for clean street extraction
        $street = extractStreetAddress($google['address'] ?? '');
        $street = preg_replace('/\s+/', ' ', $street);
        $street = trim($street);

        if (!empty($street) && !empty($google['city'])) {

            error_log('[PARCEL INPUT] ' . json_encode([
                'street' => $street,
                'city'   => $google['city']
            ]));

            $parcel = getMaricopaParcelFromAddress($street, $google['city']);

            error_log('[PARCEL RESULT] ' . json_encode($parcel));

            if (is_array($parcel) && !empty($parcel['parcelNumber'])) {

                $result['parcelNumber'] = $parcel['parcelNumber'];

                if (!empty($parcel['jurisdiction'])) {
                    $result['jurisdiction'] = $parcel['jurisdiction'];
                } else {
                    $result['jurisdiction'] = $google['city'];
                }

            } else {

                // Parcel lookup failed but location is valid
                $result['parcelNumber'] = null;
                $result['jurisdiction'] = $google['city'] ?? 'Maricopa County';

            }

        } else {

            // Missing usable address components
            $result['parcelNumber'] = null;
            $result['jurisdiction'] = 'Maricopa County';

        }

    } else {

        // Non-Maricopa fallback
        $result['parcelNumber'] = null;
        $result['jurisdiction'] = $result['city'] ?? $result['county'] ?? 'Unknown';

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

function getMaricopaParcelFromAddress(string $address, string $city): ?array {

    $apiKey = getenv("MARICOPA_COUNTY_API_KEY");
    if (!$apiKey || empty(trim($address)) || empty(trim($city))) {
        return null;
    }

    // 🔥 Improved query (include city + state)
    $fullQuery = trim($address . ' ' . $city . ' AZ');
    $query = urlencode($fullQuery);

    // Normalize helper
    $normalize = function(string $str): string {
        $str = strtoupper(trim($str));
        $str = preg_replace('/\s+/', ' ', $str);

        // Standardize street types
        $str = str_replace([' AVENUE ', ' AVE '], ' AVE ', $str);
        $str = str_replace([' STREET ', ' ST '], ' ST ', $str);
        $str = str_replace([' ROAD ', ' RD '], ' RD ', $str);
        $str = str_replace([' DRIVE ', ' DR '], ' DR ', $str);
        $str = str_replace([' BOULEVARD ', ' BLVD '], ' BLVD ', $str);

        return preg_replace('/[^A-Z0-9 ]/', '', $str);
    };

    // 🔥 Normalize using address + city (more consistent matching)
    $targetNorm = $normalize($address . ' ' . $city);

    preg_match('/^(\d+)/', $targetNorm, $targetNumMatch);
    $targetStreetNum = $targetNumMatch[1] ?? '';

    $bestParcel = null;
    $bestScore = -1;

    for ($page = 1; $page <= 2; $page++) {

        $url = "https://mcassessor.maricopa.gov/search/property/?q={$query}";
        if ($page > 1) {
            $url .= "&page={$page}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                "AUTHORIZATION: {$apiKey}",
                "User-Agent: Skyesoft"
            ]
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        file_put_contents(
            __DIR__ . '/mca_debug.log',
            date('Y-m-d H:i:s') . " | PAGE: {$page} | URL: {$url}\nSTATUS: {$status}\nERROR: {$error}\nRESPONSE LENGTH: " . strlen($response) . "\n\n",
            FILE_APPEND
        );

        if ($error || $status !== 200 || empty($response)) {
            continue;
        }

        // 🔥 HTML guard
        if (strpos($response, '<html') !== false) {
            error_log('[PARCEL ERROR] HTML response received');
            continue;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['Results'])) {
            continue;
        }

        foreach ($data['Results'] as $r) {

            $apiAddress = $r['SitusAddress'] ?? '';
            if (empty($apiAddress)) continue;

            $apiNorm = $normalize($apiAddress);

            preg_match('/^(\d+)/', $apiNorm, $apiNumMatch);
            $apiStreetNum = $apiNumMatch[1] ?? '';

            $score = 0;

            // Strong: street number match
            if (!empty($targetStreetNum) && $targetStreetNum === $apiStreetNum) {
                $score += 50;
            }

            // Name overlap
            if (strpos($apiNorm, $targetNorm) !== false || strpos($targetNorm, $apiNorm) !== false) {
                $score += 40;
            } else {
                // Partial word overlap fallback
                $targetWords = array_filter(explode(' ', $targetNorm));
                $apiWords = array_filter(explode(' ', $apiNorm));
                $common = count(array_intersect($targetWords, $apiWords));
                if ($common >= 2) {
                    $score += 25;
                }
            }

            // Length similarity
            $lenDiff = abs(strlen($apiNorm) - strlen($targetNorm));
            if ($lenDiff < 8) {
                $score += 15;
            } elseif ($lenDiff < 15) {
                $score += 5;
            }

            // 🔥 Slightly stricter threshold
            if ($score < 50) continue;

            // Extract APN
            $apnRaw = preg_replace('/\D/', '', $r['APN'] ?? '');
            if (strlen($apnRaw) < 8) continue;

            $formattedAPN = (strlen($apnRaw) === 8)
                ? substr($apnRaw, 0, 3) . '-' . substr($apnRaw, 3, 2) . '-' . substr($apnRaw, 5, 3)
                : $r['APN'];

            // Jurisdiction from API
            $rawJur = strtoupper(trim($r['TaxAreaDescription'] ?? ''));

            if (in_array($rawJur, ['UNINCORPORATED', 'NO TOWN OR CITY', ''], true)) {
                $jurisdiction = 'Maricopa County';
            } else {
                $jurisdiction = ucwords(strtolower($rawJur));
            }

            if ($score > $bestScore) {
                $bestScore = $score;

                $bestParcel = [
                    'parcelNumber' => $formattedAPN,
                    'jurisdiction' => $jurisdiction,

                    // 🔧 Debug fields (optional — remove in production)
                    'matchedAddress' => $apiAddress,
                    'score' => $score
                ];
            }
        }

        // Early exit on strong match
        if ($bestScore >= 80) {
            break;
        }
    }

    if ($bestParcel) {
        return $bestParcel;
    }

    error_log("No strong parcel match found for '{$address}, {$city}' (norm: {$targetNorm})");
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