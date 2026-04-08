<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — resolveLocation.php
//  Version: 1.1.0
//  Last Updated: 2026-04-07
//  Codex Tier: 2 — Location Intelligence Engine
//
//  Role:
//  Resolves and enriches location data using:
//   • Google Places (identity)
//   • Census API (county)
//   • Maricopa API (parcel + jurisdiction)
//
//  Responsibilities:
//   • Validate locationPlaceId
//   • Resolve geospatial data
//   • Apply jurisdiction logic
//
//  Forbidden:
//   • No database writes
//   • No contact/entity logic
// ======================================================================

#region SECTION 0 — Core Function

function resolveLocation(array $input): array {

    #region Default Structure

    $result = [
        'placeId' => null,
        'lat' => null,
        'lng' => null,
        'city' => null,
        'state' => null,
        'zip' => null,
        'address' => null, // 🔥 NEW
        'county' => null,
        'countyFips' => null,
        'jurisdiction' => null,
        'parcelNumber' => null
    ];

    #endregion


    #region STEP 1 — Google Geocode (REAL)

    $google = getGoogleGeocode($input);

    // 🛡️ Strong validation
    if (
        !$google ||
        empty($google['placeId']) ||
        empty($google['lat']) ||
        empty($google['lng'])
    ) {
        return $result;
    }

    $result['placeId'] = $google['placeId'];
    $result['lat'] = $google['lat'];
    $result['lng'] = $google['lng'];
    $result['city'] = $google['city'];
    $result['state'] = $google['state'];
    $result['zip'] = $google['zip'];
    $result['address'] = $google['address'] ?? null; // 🔥 NEW

    #endregion

    #region STEP 2 — Census Geography

    $census = getCensusGeography($result['lat'], $result['lng']);

    $result['county'] = $census['county'] ?? null;
    $result['countyFips'] = $census['countyFips'] ?? null;

    #endregion

    #region STEP 3 — Conditional Parcel Logic

    if (
        $result['state'] === 'AZ' &&
        $result['county'] === 'Maricopa County'
    ) {

        $address = $result['address'];

        if ($address) {

            $parcel = getMaricopaParcelFromAddress($address);

            if ($parcel) {
                $result['parcelNumber'] = $parcel['parcelNumber'];
                $result['jurisdiction'] = $parcel['jurisdiction'];
            } else {
                $result['jurisdiction'] = 'Maricopa County';
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

#region SECTION 1 — Census API Integration

function getCensusGeography(?float $lat, ?float $lng): array {

    if (!$lat || !$lng) {
        return [
            'state' => null,
            'county' => null,
            'countyFips' => null
        ];
    }

    $url = "https://geocoding.geo.census.gov/geocoder/geographies/coordinates"
        . "?x={$lng}&y={$lat}&benchmark=Public_AR_Current&vintage=Current_Current&format=json";

    try {

        $response = file_get_contents($url);

        if (!$response) {
            throw new RuntimeException("Census API request failed.");
        }

        $data = json_decode($response, true);

        $geo = $data['result']['geographies']['Counties'][0] ?? null;

        if (!$geo) {
            return [
                'state' => null,
                'county' => null,
                'countyFips' => null
            ];
        }

        return [
            'state' => $geo['STATE'] ?? null,
            'county' => $geo['NAME'] ?? null,
            'countyFips' => $geo['COUNTY'] ?? null
        ];

    } catch (Throwable $e) {

        // Fail silently (non-blocking per Codex)
        return [
            'state' => null,
            'county' => null,
            'countyFips' => null
        ];
    }
}

#endregion

#region SECTION 2 — Maricopa Parcel API

function getMaricopaParcelFromAddress(string $address): ?array {

    if (!$address) return null;

    $apiKey = getenv("MARICOPA_COUNTY_API_KEY");
    if (!$apiKey) return null;

    $url = "https://mcassessor.maricopa.gov/api/v1/search/property?query=" . urlencode($address);

    $headers = [
        "Authorization: Bearer {$apiKey}",
        "Accept: application/json",
        "User-Agent: Skyesoft/1.0"
    ];

    try {

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 5
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if (!$response) return null;

        $data = json_decode($response, true);

        if (
            !isset($data['results']) ||
            !is_array($data['results']) ||
            empty($data['results'])
        ) {
            return null;
        }

        $parcelNumber = $data['results'][0]['parcelNumber'] ?? null;

        if (!$parcelNumber) return null;

        // 🔍 Get parcel detail
        $detailUrl = "https://mcassessor.maricopa.gov/api/v1/parcel/{$parcelNumber}";

        $detailResponse = @file_get_contents($detailUrl, false, $context);

        if (!$detailResponse) return null;

        $detail = json_decode($detailResponse, true);

        return [
            'parcelNumber' => $parcelNumber,
            'jurisdiction' => $detail['propertyAddress']['city'] ?? 'Maricopa County'
        ];

    } catch (Throwable $e) {
        return null;
    }
}

#endregion

#region SECTION 3 — Google Geocoding API

function getGoogleGeocode(array $input): ?array {

    // Build address input
    $address = $input['address']
        ?? trim(
            ($input['city'] ?? '') . ' ' .
            ($input['state'] ?? '') . ' ' .
            ($input['zip'] ?? '')
        );

    if (!$address) return null;

    $apiKey = getenv("GOOGLE_MAPS_BACKEND_API_KEY");

    if (!$apiKey) return null;

    $url = "https://maps.googleapis.com/maps/api/geocode/json?address="
        . urlencode($address)
        . "&key={$apiKey}";

    try {

        $response = @file_get_contents($url);

        if (!$response) return null;

        $data = json_decode($response, true);

        // 🛡️ NEW — Google status validation (MTCO fix)
        if (($data['status'] ?? null) !== 'OK') {
            return null;
        }

        if (
            empty($data['results']) ||
            !isset($data['results'][0])
        ) {
            return null;
        }

        $result = $data['results'][0];

        $location = $result['geometry']['location'] ?? null;
        $components = $result['address_components'] ?? [];

        if (!$location) return null;

        // Extract components
        $city = null;
        $state = null;
        $zip = null;

        foreach ($components as $comp) {
            if (in_array('locality', $comp['types'])) {
                $city = $comp['long_name'];
            }
            if (in_array('administrative_area_level_1', $comp['types'])) {
                $state = $comp['short_name'];
            }
            if (in_array('postal_code', $comp['types'])) {
                $zip = $comp['long_name'];
            }
        }

        return [
            'placeId' => $result['place_id'] ?? null,
            'lat' => $location['lat'],
            'lng' => $location['lng'],
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'address' => $result['formatted_address'] ?? $address
        ];

    } catch (Throwable $e) {
        return null;
    }
}

#endregion