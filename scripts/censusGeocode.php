<?php
// scripts/censusGeocode.php

function censusGeocodeAddress($address) {
  if (!$address) return null;

  $baseUrl = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress";
  $params = http_build_query([
    "address"   => $address,
    "benchmark" => "Public_AR_Current",
    "vintage"   => "Current_Current",
    "format"    => "json"
  ]);

  $resp = @file_get_contents($baseUrl . "?" . $params);
  if ($resp === false) return null;

  $data = json_decode($resp, true);
  if (empty($data['result']['addressMatches'][0])) return null;

  $match = $data['result']['addressMatches'][0];
  $geo   = $match['geographies'];

  $county = $geo['Counties'][0] ?? [];

  return [
    "matchedAddress" => $match['matchedAddress'] ?? null,
    "lat"            => $match['coordinates']['y'] ?? null,
    "lon"            => $match['coordinates']['x'] ?? null,
    "stateAbbr"      => $geo['States'][0]['STUSAB'] ?? null,
    "state"          => $geo['States'][0]['NAME'] ?? null,
    "city"           => $geo['Places'][0]['NAME'] ?? null,
    "county"         => $county['BASENAME'] ?? null,
    "countyFips"     => $county['GEOID'] ?? null
  ];
}
