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

    // #region Default Structure

    $result = [
        'placeId' => null,
        'lat' => null,
        'lng' => null,
        'city' => null,
        'state' => null,
        'zip' => null,
        'county' => null,
        'countyFips' => null,
        'jurisdiction' => null,
        'parcelNumber' => null
    ];

    // #endregion

    // #region STEP 1 — Google Places (Stub / Replace Later)

    $google = getGooglePlaceDetails($input);

    if (!$google || empty($google['placeId'])) {
        return $result;
    }

    $result['placeId'] = $google['placeId'];
    $result['lat'] = $google['lat'];
    $result['lng'] = $google['lng'];
    $result['city'] = $google['city'];
    $result['state'] = $google['state'];
    $result['zip'] = $google['zip'];

    // #endregion

    // #region STEP 2 — Census (REAL IMPLEMENTATION)

    $census = getCensusGeography($result['lat'], $result['lng']);

    $result['county'] = $census['county'];
    $result['countyFips'] = $census['countyFips'];

    // #endregion

    // #region STEP 3 — Conditional Parcel Logic (Still Stubbed)

    if ($result['state'] === 'AZ' && $result['county'] === 'Maricopa County') {

        $address = $google['address'] ?? null;

        $parcel = getMaricopaParcelFromAddress($address);

        if ($parcel) {
            $result['parcelNumber'] = $parcel['parcelNumber'];
            $result['jurisdiction'] = $parcel['jurisdiction'];
        } else {
            $result['jurisdiction'] = 'Maricopa County';
        }

    } else {
        $result['jurisdiction'] = $census['county'];
    }

    // #endregion

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

    $query = urlencode($address);
    $url = "https://mcassessor.maricopa.gov/search/property/?q={$query}";

    $headers = [
        "AUTHORIZATION: " . getenv("MCA_API_TOKEN"),
        "user-agent: null"
    ];

    try {

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers)
            ]
        ]);

        $response = file_get_contents($url, false, $context);

        if (!$response) return null;

        $data = json_decode($response, true);

        $first = $data['results'][0]['parcelNumber'] ?? null;

        if (!$first) return null;

        // 🔍 Fetch parcel details
        $parcelUrl = "https://mcassessor.maricopa.gov/parcel/{$first}";

        $parcelResponse = file_get_contents($parcelUrl, false, $context);

        if (!$parcelResponse) return null;

        $parcelData = json_decode($parcelResponse, true);

        return [
            'parcelNumber' => $first,
            'jurisdiction' => $parcelData['propertyAddress']['city'] ?? null
        ];

    } catch (Throwable $e) {
        return null;
    }
}

#endregion

#region SECTION 3 — Google Lookup Stub (TEMP)

function getGooglePlaceDetails(array $input): ?array {

    // Expecting input to already contain structured location data
    return [
        'placeId' => $input['placeId'] ?? null,
        'lat' => $input['lat'] ?? null,
        'lng' => $input['lng'] ?? null,
        'city' => $input['city'] ?? null,
        'state' => $input['state'] ?? null,
        'zip' => $input['zip'] ?? null,
        'address' => $input['address'] ?? null
    ];
}

#endregion