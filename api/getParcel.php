<?php
// 📄 File: api/getParcel.php
header('Content-Type: application/json');

// Simple wrapper: getParcelByAddress($street, $city, $state)
function getParcelByAddress($street, $city, $state) {
    // STEP 1: Census API → County GEOID
    $censusUrl = "https://geocoding.geo.census.gov/geocoder/geographies/address"
        . "?street=" . urlencode($street)
        . "&city=" . urlencode($city)
        . "&state=" . urlencode($state)
        . "&benchmark=Public_AR_Census2020"
        . "&vintage=Census2020_Census2020"
        . "&format=json";

    $censusResp = file_get_contents($censusUrl);
    $censusData = json_decode($censusResp, true);

    if (empty($censusData['result']['addressMatches'][0]['geographies']['Counties'][0])) {
        return ["error" => "No county found for this address"];
    }

    $county = $censusData['result']['addressMatches'][0]['geographies']['Counties'][0]['BASENAME'];
    $countyFIPS = $censusData['result']['addressMatches'][0]['geographies']['Counties'][0]['GEOID'];

    // STEP 2: Switch by county FIPS (Maricopa example only so far)
    $parcelEndpoints = [
        "04013" => "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
    ];

    if (!isset($parcelEndpoints[$countyFIPS])) {
        return ["error" => "Parcel endpoint not yet configured for {$county}"];
    }

    $endpoint = $parcelEndpoints[$countyFIPS];

    // STEP 3: Find APN by address
    $addr = strtoupper($street . " " . $city);
    $url1 = $endpoint
        . "?f=json&where=UPPER(PHYSICAL_ADDRESS)+LIKE+UPPER('%25" . urlencode($addr) . "%25')"
        . "&outFields=APN,PHYSICAL_ADDRESS,OWNER_NAME";

    $resp1 = file_get_contents($url1);
    $data1 = json_decode($resp1, true);

    if (empty($data1['features'])) {
        return ["error" => "No APN found for address in {$county}"];
    }

    $apn = $data1['features'][0]['attributes']['APN'];

    // STEP 4: Get full parcel details by APN
    $url2 = $endpoint
        . "?f=json&where=APN='" . $apn . "'"
        . "&outFields=APN,PHYSICAL_ADDRESS,OWNER_NAME,MAIL_ADDR1,MAIL_CITY,MAIL_STATE,MAIL_ZIP,LAND_SIZE,LC_CUR";

    $resp2 = file_get_contents($url2);
    $data2 = json_decode($resp2, true);

    return [
        "county" => $county,
        "apn" => $apn,
        "parcel" => $data2['features'][0]['attributes']
    ];
}

// Run if called via HTTP
if (isset($_GET['street'], $_GET['city'], $_GET['state'])) {
    echo json_encode(getParcelByAddress($_GET['street'], $_GET['city'], $_GET['state']));
} else {
    echo json_encode(["error" => "Missing required parameters (street, city, state)"]);
}