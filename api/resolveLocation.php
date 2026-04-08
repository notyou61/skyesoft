<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — resolveLocation.php
//  Version: 1.2.0
// ======================================================================

#region SECTION 0 — Core Function

function resolveLocation(array $input): array {

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

    #region STEP 1 — Google

    $google = getGoogleGeocode($input);

    if (
        !$google ||
        empty($google['placeId']) ||
        empty($google['lat']) ||
        empty($google['lng'])
    ) {
        return $result;
    }

    $result = array_merge($result, $google);

    #endregion

    #region STEP 1 — Census

    $census = getCensusGeography($result['lat'], $result['lng']);

    $result['county'] = $census['county'] ?? null;
    $result['countyFips'] = $census['countyFips'] ?? null;

    #endregion

    #region STEP 2 — Maricopa Parcel

    if (
        $result['state'] === 'AZ' &&
        strtoupper($result['county'] ?? '') === 'MARICOPA'
    ) {

        if (!empty($result['address']) && !empty($result['city'])) {

            $street = extractStreetAddress($result['address']);

            $parcel = getMaricopaParcelFromAddress(
                $street,
                $result['city']
            );

            // Validate parcel response (structure + value)
            if (is_array($parcel) && !empty($parcel['parcelNumber'])) {

                // Set parcel
                $result['parcelNumber'] = $parcel['parcelNumber'];

                // Only override jurisdiction if provided
                if (!empty($parcel['jurisdiction'])) {
                    $result['jurisdiction'] = $parcel['jurisdiction'];
                }

            } else {

                // Fallback: ensure parcel is not blank
                if (empty($result['parcelNumber'])) {
                    $result['parcelNumber'] = 'Pending';
                }

                // Fallback jurisdiction
                if (empty($result['jurisdiction'])) {
                    $result['jurisdiction'] = $result['city'] ?? 'Maricopa County';
                }
            }

        } else {

            // Missing address data fallback
            if (empty($result['parcelNumber'])) {
                $result['parcelNumber'] = 'Pending';
            }

            $result['jurisdiction'] = 'Maricopa County';
        }

    } else {

        // Non-Maricopa fallback
        if (empty($result['parcelNumber'])) {
            $result['parcelNumber'] = 'Pending';
        }

        $result['jurisdiction'] =
            $result['city']
            ?? $result['county']
            ?? 'Unknown';
    }

    #endregion

    #region STEP 3 — Results

    return $result;

    #endregion
}

#endregion

#region SECTION 1 — Helpers

function extractStreetAddress(string $fullAddress): string {
    return trim(explode(',', $fullAddress)[0]);
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

#region SECTION 3 — Maricopa API (CORRECTED)

function getMaricopaParcelFromAddress(string $address, string $city): ?array {

    $apiKey = getenv("MARICOPA_COUNTY_API_KEY");
    if (!$apiKey || !$address || !$city) return null;

    // Build search query (same as curl test)
    $query = urlencode($address . ' ' . $city);

    $url = "https://mcassessor.maricopa.gov/search/property/?q={$query}";

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "AUTHORIZATION: $apiKey",
            "User-Agent: Skyesoft"
        ]
    ]);

    $response = curl_exec($ch);
    $error  = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    // Debug logging
    file_put_contents(
        __DIR__ . '/mca_debug.log',
        "URL: $url\nSTATUS: $status\nERROR: $error\nRESPONSE:\n$response\n\n",
        FILE_APPEND
    );

    if ($error || $status !== 200 || !$response) return null;

    $data = json_decode($response, true);

    if (empty($data['Results'])) return null;

    $targetAddress = strtoupper(trim($address));

    foreach ($data['Results'] as $r) {

        $apiAddress = strtoupper(trim($r['SitusAddress'] ?? ''));

        // Normalize function (inline for clarity)
        $normalize = function($str) {
            return preg_replace('/[^A-Z0-9]/', '', strtoupper($str));
        };

        $targetNorm = $normalize($targetAddress);
        $apiNorm    = $normalize($apiAddress);

        // Match using normalized comparison
        if ($apiNorm === $targetNorm) {

            $apn = preg_replace('/\D+/', '', $r['APN']);

            if (strlen($apn) === 8) {
                $formatted =
                    substr($apn, 0, 3) . '-' .
                    substr($apn, 3, 2) . '-' .
                    substr($apn, 5, 3);
            } else {
                $formatted = $r['APN'];
            }

            return [
                'parcelNumber' => $formatted,
                'jurisdiction' => ucwords(strtolower($city))
            ];
        }
    }

    return null;
}

#endregion

#region SECTION 4 — Google

function getGoogleGeocode(array $input): ?array {

    $address = $input['address'] ?? null;
    if (!$address) return null;

    $apiKey = getenv("GOOGLE_MAPS_BACKEND_API_KEY");
    if (!$apiKey) return null;

    $url = "https://maps.googleapis.com/maps/api/geocode/json?address="
        . urlencode($address) . "&key={$apiKey}";

    $response = @file_get_contents($url);
    if (!$response) return null;

    $data = json_decode($response, true);

    if (($data['status'] ?? '') !== 'OK') return null;

    $r = $data['results'][0];

    $location = $r['geometry']['location'];

    $city = $state = $zip = null;

    foreach ($r['address_components'] as $c) {
        if (in_array('locality', $c['types'])) $city = $c['long_name'];
        if (in_array('administrative_area_level_1', $c['types'])) $state = $c['short_name'];
        if (in_array('postal_code', $c['types'])) $zip = $c['long_name'];
    }

    return [
        'placeId' => $r['place_id'],
        'lat' => $location['lat'],
        'lng' => $location['lng'],
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'address' => $r['formatted_address']
    ];
}

#endregion