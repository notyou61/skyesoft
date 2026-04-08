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

    #region STEP 2 — Census

    $census = getCensusGeography($result['lat'], $result['lng']);

    $result['county'] = $census['county'] ?? null;
    $result['countyFips'] = $census['countyFips'] ?? null;

    #endregion

    #region STEP 3 — Maricopa Parcel

    if (
        $result['state'] === 'AZ' &&
        $result['county'] === 'Maricopa County'
    ) {

        if (!empty($result['address']) && !empty($result['city'])) {

            $street = extractStreetAddress($result['address']);

            $parcel = getMaricopaParcelFromAddress($street, $result['city']);

            if ($parcel) {
                $result['parcelNumber'] = $parcel['parcelNumber'];
                $result['jurisdiction'] = $parcel['jurisdiction'];
            } else {
                $result['jurisdiction'] = $result['city'];
            }

        } else {
            $result['jurisdiction'] = 'Maricopa County';
        }

    } else {
        $result['jurisdiction'] = $result['county'] ?? 'Unknown';
    }

    #endregion

    return $result;
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

#region SECTION 3 — Maricopa API

function getMaricopaParcelFromAddress(string $street, string $city): ?array {

    $apiKey = getenv("MARICOPA_COUNTY_API_KEY");
    if (!$apiKey) return null;

    $query = http_build_query([
        'street' => strtoupper(trim($street)),
        'city'   => strtoupper(trim($city))
    ]);

    $url = "https://api.mcassessor.maricopa.gov/api/v1/parcel/search?$query";

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "Ocp-Apim-Subscription-Key: {$apiKey}"
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents(
        __DIR__ . '/mca_debug.log',
        "URL: $url\nSTATUS: $status\nRESPONSE:\n$response\n\n",
        FILE_APPEND
    );

    if ($status !== 200 || !$response) return null;

    $data = json_decode($response, true);

    if (!is_array($data) || count($data) !== 1) return null;

    $parcel = $data[0]['apn'] ?? null;
    if (!$parcel) return null;

    $digits = preg_replace('/\D+/', '', $parcel);

    return [
        'parcelNumber' =>
            substr($digits, 0, 3) . '-' .
            substr($digits, 3, 2) . '-' .
            substr($digits, 5),
        'jurisdiction' => $city
    ];
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