<?php
// Zoning Report Generator (PHP 5.6 compatible)
// 📄 File: api/reports/zoning.php
// 
function generateZoningReport($prompt, &$conversation) {
    // ✅ Extract and normalize address
    $address = null;
    $cleanPrompt = preg_replace(
        '/\b(zoning|permit|report|lookup|check|for|at|create|make|please)\b/i',
        '',
        $prompt
    );
    if (preg_match('/\d{1,5}[^,]+(?:,[^,]+){0,2}\b\d{5}\b/', $cleanPrompt, $matches)) {
        $address = trim($matches[0]);
    } else {
        $address = trim($cleanPrompt);
    }
    $address = normalizeAddress($address);

    // ✅ Validation: require street number + 5-digit ZIP
    $hasStreetNum = preg_match('/\b\d{3,5}\b/', $address);
    $hasZip       = preg_match('/\b\d{5}\b/', $address);
    if (!$hasStreetNum || !$hasZip) {
        return array(
            "error" => true,
            "response" => "⚠️ Please include both a street number and a 5-digit ZIP code to create a zoning report.",
            "providedInput" => $address
        );
    }

    // ✅ Initialize
    $county = null;
    $stateFIPS = null;
    $countyFIPS = null;
    $latitude = null;
    $longitude = null;
    $matchedAddress = null;
    $state = null;

    // ✅ Census Location API
    $locUrl = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress"
        . "?address=" . urlencode($address)
        . "&benchmark=Public_AR_Current&format=json";
    $locData = json_decode(@file_get_contents($locUrl), true);

    if ($locData && isset($locData['result']['addressMatches'][0])) {
        $match = $locData['result']['addressMatches'][0];
        if (isset($match['matchedAddress'])) {
            $matchedAddress = $match['matchedAddress'];
        }
        if (isset($match['coordinates'])) {
            $longitude = $match['coordinates']['x'];
            $latitude  = $match['coordinates']['y'];
        }
    }

    // ✅ Census Geographies API (primary)
    $geoUrl = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress"
        . "?address=" . urlencode($address)
        . "&benchmark=Public_AR_Current&vintage=Current_Current&layers=all&format=json";
    $geoData = json_decode(@file_get_contents($geoUrl), true);

    if ($geoData && isset($geoData['result']['addressMatches'][0]['geographies']['Counties'][0])) {
        $countyData = $geoData['result']['addressMatches'][0]['geographies']['Counties'][0];
        $county     = isset($countyData['NAME']) ? $countyData['NAME'] : null;
        $stateFIPS  = isset($countyData['STATE']) ? $countyData['STATE'] : null;
        $countyFIPS = isset($countyData['COUNTY']) ? $countyData['COUNTY'] : null;
    } else {
        // 🚨 Census failed → fallback to Google Geocoding API
        $googleKey = getenv("GOOGLE_MAPS_BACKEND_API_KEY");
        if ($googleKey) {
            $googleUrl = "https://maps.googleapis.com/maps/api/geocode/json"
                . "?address=" . urlencode($address)
                . "&key=" . $googleKey;
            $googleData = json_decode(@file_get_contents($googleUrl), true);

            if ($googleData && isset($googleData['results'][0])) {
                $gResult = $googleData['results'][0];
                if (!$matchedAddress && isset($gResult['formatted_address'])) {
                    $matchedAddress = strtoupper($gResult['formatted_address']);
                }
                if (isset($gResult['geometry']['location'])) {
                    $latitude  = $gResult['geometry']['location']['lat'];
                    $longitude = $gResult['geometry']['location']['lng'];
                }
                if (isset($gResult['address_components'])) {
                    foreach ($gResult['address_components'] as $comp) {
                        if (in_array("administrative_area_level_2", $comp['types'])) {
                            $county = $comp['long_name'];
                        }
                        if (in_array("administrative_area_level_1", $comp['types'])) {
                            $state = $comp['short_name'];
                        }
                    }
                }
                if ($county === "Maricopa County" && $state === "AZ") {
                    $stateFIPS  = "04";
                    $countyFIPS = "013";
                }
            }
        }
    }

    // ✅ Set assessorApi
    $assessorApi = getAssessorApi($stateFIPS, $countyFIPS);

    // ✅ Ensure ZIP in matchedAddress
    if ($matchedAddress && !preg_match('/\b\d{5}\b/', $matchedAddress)) {
        if (preg_match('/\b\d{5}\b/', $address, $zipMatch)) {
            $matchedAddress .= " " . $zipMatch[0];
        }
    }

    // ✅ Parcel lookup (Maricopa only)
    $parcels = array();
    $parcelStatus = "none";

    if ($countyFIPS === "013" && $stateFIPS === "04" && $matchedAddress) {
        preg_match('/\b\d{5}\b/', $matchedAddress, $zipMatch);
        $zip = isset($zipMatch[0]) ? $zipMatch[0] : null;

        $normalized = strtoupper($matchedAddress);
        $shortAddress = preg_replace('/,.*$/', '', $normalized);

        // --- Helper inline query runner ---
        $runParcelQuery = function($where) {
            $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
                . "?f=json&where=" . urlencode($where)
                . "&outFields=APN,PHYSICAL_ADDRESS,OWNER_NAME,PHYSICAL_ZIP&returnGeometry=true&outSR=4326";
            $resp = json_decode(@file_get_contents($url), true);
            return isset($resp['features']) ? $resp['features'] : array();
        };

        $features = array();

        // Step 1: full address + ZIP
        $where1 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')";
        if ($zip) $where1 .= " AND PHYSICAL_ZIP = '" . $zip . "'";
        $features = $runParcelQuery($where1);
        if (!empty($features)) $parcelStatus = "exact";

        // Step 2: relaxed suffix
        if (empty($features)) {
            $relaxed = preg_replace('/\s(BLVD|ROAD|RD|DR|DRIVE|STREET|ST|AVE|AVENUE)\b/i', '', $shortAddress);
            $where2 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $relaxed . "%')";
            if ($zip) $where2 .= " AND PHYSICAL_ZIP = '" . $zip . "'";
            $features = $runParcelQuery($where2);
            if (!empty($features)) $parcelStatus = "exact";
        }

        // Step 3: fuzzy street match
        if (empty($features) && $zip) {
            $streetOnly = trim(preg_replace('/^\d+/', '', $shortAddress));
            $where3 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $streetOnly . "%') AND PHYSICAL_ZIP = '" . $zip . "'";
            $features = $runParcelQuery($where3);
            if (!empty($features)) $parcelStatus = "fuzzy";
        }

        // Step 4: last resort — full address no ZIP
        if (empty($features)) {
            $where4 = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')";
            $features = $runParcelQuery($where4);
            if (!empty($features)) $parcelStatus = "fuzzy";
        }

        // Enrich each parcel with jurisdiction + simplified geometry
        foreach ($features as $f) {
            $a = $f['attributes'];
            $apn   = isset($a['APN']) ? $a['APN'] : null;
            $situs = isset($a['PHYSICAL_ADDRESS']) ? trim($a['PHYSICAL_ADDRESS']) : null;
            $zip   = isset($a['PHYSICAL_ZIP']) ? $a['PHYSICAL_ZIP'] : null;

            $jurisdiction = null;
            if (!empty($apn)) {
                $detailsUrl = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
                            . "?f=json&where=APN='" . urlencode($apn) . "'&outFields=JURISDICTION&returnGeometry=false";
                $detailsJson = @file_get_contents($detailsUrl);
                $detailsData = json_decode($detailsJson, true);
                if ($detailsData && isset($detailsData['features'][0]['attributes']['JURISDICTION'])) {
                    $jurisdiction = strtoupper(trim($detailsData['features'][0]['attributes']['JURISDICTION']));
                }
            }

            // Simplify geometry
            $geometry = null;
            if (isset($f['geometry']['rings'][0]) && count($f['geometry']['rings'][0]) > 0) {
                $coords = $f['geometry']['rings'][0];

                $minLat = $maxLat = $coords[0][1];
                $minLon = $maxLon = $coords[0][0];
                $sumLat = 0;
                $sumLon = 0;
                $count  = 0;

                foreach ($coords as $pt) {
                    $lon = $pt[0];
                    $lat = $pt[1];

                    if ($lat < $minLat) $minLat = $lat;
                    if ($lat > $maxLat) $maxLat = $lat;
                    if ($lon < $minLon) $minLon = $lon;
                    if ($lon > $maxLon) $maxLon = $lon;

                    $sumLat += $lat;
                    $sumLon += $lon;
                    $count++;
                }

                $centroidLat = $count > 0 ? $sumLat / $count : null;
                $centroidLon = $count > 0 ? $sumLon / $count : null;

                $geometry = array(
                    "centroid" => array("lat" => $centroidLat, "lon" => $centroidLon),
                    "bbox" => array(
                        "minLat" => $minLat,
                        "maxLat" => $maxLat,
                        "minLon" => $minLon,
                        "maxLon" => $maxLon
                    )
                );
            }

            $parcels[] = array(
                "apn"          => $apn,
                "situs"        => $situs,
                "jurisdiction" => $jurisdiction ? $jurisdiction : $county,
                "zip"          => $zip,
                "geometry"     => $geometry
            );
        }
    }

    // ✅ Jurisdiction zoning lookup
    if (count($parcels) > 0 && !empty($parcels[0]['jurisdiction'])) {
        foreach ($parcels as $k => $parcel) {
            $lat = $latitude;
            $lon = $longitude;

            if (isset($parcel['geometry']['centroid'])) {
                $lat = $parcel['geometry']['centroid']['lat'];
                $lon = $parcel['geometry']['centroid']['lon'];
            }

            $normalizedJurisdiction = normalizeJurisdiction(
                $parcel['jurisdiction'],
                "Maricopa County"
            );

            $parcels[$k]['jurisdiction'] = $normalizedJurisdiction;

            // --- Regionalized Zoning Lookup ---
            switch ($normalizedJurisdiction) {
                // --- Jurisdiction: Phoenix ---
                case "PHOENIX":
                    $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning("Phoenix", $lat, $lon, $parcel['geometry']);
                    break;
                // --- Jurisdiction: Mesa ---
                case "MESA":
                    $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning("Mesa", $lat, $lon, $parcel['geometry']);
                    break;
                // --- Jurisdiction: Gilbert ---
                case "GILBERT":
                    $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning("Gilbert", $lat, $lon, $parcel['geometry']);
                    break;
                // --- Jurisdiction: Scottsdale ---
                case "SCOTTSDALE":
                    $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning("Scottsdale", $lat, $lon, $parcel['geometry']);
                    break;
                // --- Fallback: Other Jurisdictions ---
                default:
                    $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning($normalizedJurisdiction, $lat, $lon, $parcel['geometry']);
                    break;
            }
        }
    }

    // ✅ Context for disclaimers (extended)
    $context = array(
        "multipleParcels"         => (count($parcels) > 1),
        "multiParcelSite"         => false,
        "mixedParcelZoning"       => false,
        "unsupportedJurisdiction" => false,
        "pucMismatch"             => false,
        "splitZoning"             => false
    );

    if (count($parcels) > 0) {
        $context["jurisdiction"] = strtolower(trim($parcels[0]['jurisdiction']));
        $zonings = array();
        foreach ($parcels as $parcel) {
            if (!empty($parcel['jurisdictionZoning'])) {
                $zonings[] = $parcel['jurisdictionZoning'];
            }
            if ($parcel['jurisdiction'] == "Maricopa County") {
                $context["unsupportedJurisdiction"] = true;
            }
        }
        $uniqueZonings = array_unique($zonings);
        if (count($parcels) > 1) {
            if (count($uniqueZonings) === 1) {
                $context["multiParcelSite"] = true;
            } elseif (count($uniqueZonings) > 1) {
                $context["mixedParcelZoning"] = true;
            }
        }
    }

    if ($parcelStatus === "fuzzy") {
        $context["fuzzyMatch"] = true;
    }
    if ($parcelStatus === "none") {
        $context["noParcel"] = true;
    }

    // ✅ Disclaimers
    $disclaimers = getApplicableDisclaimers("Zoning Report", $context);

    if (!$matchedAddress) {
        $matchedAddress = $address;
    }

    return array(
        "error"      => false,
        "response"   => "📄 Zoning report request created for " . $address . ".",
        "actionType" => "Create",
        "reportType" => "Zoning Report",
        "inputs"     => array(
            "address"        => $address,
            "matchedAddress" => $matchedAddress,
            "county"         => $county,
            "stateFIPS"      => $stateFIPS,
            "countyFIPS"     => $countyFIPS,
            "latitude"       => $latitude,
            "longitude"      => $longitude,
            "assessorApi"    => $assessorApi,
            "parcelStatus"   => $parcelStatus,
            "parcels"        => $parcels
        ),
        "disclaimers" => array("Zoning Report" => $disclaimers)
    );
}
