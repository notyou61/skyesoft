<?php
// Geocoding Utilities
function geocodeAddress($address) {
    return array(
        'address' => $address,
        'lat'     => null,
        'lon'     => null,
        'fips'    => null
    );
}

