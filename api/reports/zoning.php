<?php
// 📄 File: api/reports/zoning.php
// Zoning Report Generator (PHP 5.6 compatible, DRY refactor)

function generateZoningReport($prompt, &$conversation) {
    // 🔹 Load disclaimers config
    $disclaimersConfig = json_decode(file_get_contents(__DIR__ . '/../../assets/data/reportDisclaimers.json'), true);
    $baseDisclaimers   = $disclaimersConfig['Zoning Report']['dataSources'];

    // 🔹 Step 1: Extract and normalize address
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

    // Validation
    if (!preg_match('/\b\d{3,5}\b/', $address) || !preg_match('/\b\d{5}\b/', $address)) {
        return array(
            "error" => true,
            "response" => "⚠️ Please include both a street number and a 5-digit ZIP code to create a zoning report.",
            "providedInput" => $address,
            "actionType" => "Create",
            "reportType" => "Zoning Report",
            "disclaimers" => array("Zoning Report" => $baseDisclaimers)
        );
    }

    // 🔹 Init
    $county = $stateFIPS = $countyFIPS = $latitude = $longitude = $matchedAddress = $state = null;
    $assessorApi   = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query";
    $parcels       = array();
    $parcelStatus  = "none";
    $primaryZoning = "UNKNOWN";

    // 🔹 Step 2: Census Location API
    $locUrl  = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress"
             . "?address=" . urlencode($address) . "&benchmark=Public_AR_Current&format=json";
    $locData = json_decode(@file_get_contents($locUrl), true);
    if ($locData && isset($locData['result']['addressMatches'][0])) {
        $match          = $locData['result']['addressMatches'][0];
        $matchedAddress = isset($match['matchedAddress']) ? $match['matchedAddress'] : null;
        $longitude      = isset($match['coordinates']['x']) ? $match['coordinates']['x'] : null;
        $latitude       = isset($match['coordinates']['y']) ? $match['coordinates']['y'] : null;
    }

    // 🔹 Step 3: Census Geographies API
    $geoUrl  = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress"
             . "?address=" . urlencode($address)
             . "&benchmark=Public_AR_Current&vintage=Current_Current&layers=all&format=json";
    $geoData = json_decode(@file_get_contents($geoUrl), true);
    if ($geoData && isset($geoData['result']['addressMatches'][0]['geographies']['Counties'][0])) {
        $countyData = $geoData['result']['addressMatches'][0]['geographies']['Counties'][0];
        $county     = (isset($countyData['NAME']) ? $countyData['NAME'] : null) . " County";
        $stateFIPS  = isset($countyData['STATE']) ? $countyData['STATE'] : null;
        $countyFIPS = isset($countyData['COUNTY']) ? $countyData['COUNTY'] : null;
    }

    // 🔹 Ensure ZIP in matchedAddress
    if ($matchedAddress && !preg_match('/\b\d{5}\b/', $matchedAddress)) {
        if (preg_match('/\b\d{5}\b/', $address, $zipMatch)) {
            $matchedAddress .= " " . $zipMatch[0];
        }
    }

    // 🔹 Step 4: Parcel lookup (Maricopa only)
    if ($countyFIPS === "013" && $stateFIPS === "04" && $matchedAddress) {
        preg_match('/\b\d{5}\b/', $matchedAddress, $zipMatch);
        $zip          = isset($zipMatch[0]) ? $zipMatch[0] : null;
        $shortAddress = preg_replace('/,.*$/', '', strtoupper($matchedAddress));

        $runParcelQuery = function($where) {
            $url  = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query"
                  . "?f=json&where=" . urlencode($where)
                  . "&outFields=APN,PHYSICAL_ADDRESS,OWNER_NAME,PHYSICAL_ZIP,JURISDICTION"
                  . "&returnGeometry=true&outSR=4326";
            $resp = json_decode(@file_get_contents($url), true);
            return isset($resp['features']) ? $resp['features'] : array();
        };

        $features = array();
        $candidates = array(
            "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')" . ($zip ? " AND PHYSICAL_ZIP = '" . $zip . "'" : ""),
            "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" .
                preg_replace('/\s(BLVD|ROAD|RD|DR|DRIVE|STREET|ST|AVE|AVENUE)\b/i', '', $shortAddress) . "%')" 
                . ($zip ? " AND PHYSICAL_ZIP = '" . $zip . "'" : ""),
            "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . trim(preg_replace('/^\d+/', '', $shortAddress)) . "%')" 
                . ($zip ? " AND PHYSICAL_ZIP = '" . $zip . "'" : ""),
            "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%" . $shortAddress . "%')"
        );
        foreach ($candidates as $i => $where) {
            $features = $runParcelQuery($where);
            if (!empty($features)) {
                $parcelStatus = ($i <= 1 ? "exact" : "fuzzy");
                break;
            }
        }

        foreach ($features as $f) {
            $a    = $f['attributes'];
            $apn  = isset($a['APN']) ? $a['APN'] : null;
            $situs = isset($a['PHYSICAL_ADDRESS']) ? trim($a['PHYSICAL_ADDRESS']) : "";
            $zip   = isset($a['PHYSICAL_ZIP']) ? $a['PHYSICAL_ZIP'] : null;
            $jurisdiction = isset($a['JURISDICTION']) ? strtoupper(trim($a['JURISDICTION'])) : $county;

            $geometry = null;
            if (isset($f['geometry']['rings'][0]) && !empty($f['geometry']['rings'][0])) {
                $coords = $f['geometry']['rings'][0];
                $minLat = $maxLat = $coords[0][1];
                $minLon = $maxLon = $coords[0][0];
                $sumLat = $sumLon = 0;
                $count  = count($coords);
                foreach ($coords as $pt) {
                    $lon = $pt[0]; $lat = $pt[1];
                    if ($lat < $minLat) $minLat = $lat;
                    if ($lat > $maxLat) $maxLat = $lat;
                    if ($lon < $minLon) $minLon = $lon;
                    if ($lon > $maxLon) $maxLon = $lon;
                    $sumLat += $lat; $sumLon += $lon;
                }
                $geometry = array(
                    "centroid" => array("lat" => $sumLat / $count, "lon" => $sumLon / $count),
                    "bbox"     => array("minLat" => $minLat, "maxLat" => $maxLat, "minLon" => $minLon, "maxLon" => $maxLon)
                );
            }

            $parcels[] = array(
                "apn"          => $apn,
                "situs"        => $situs,
                "jurisdiction" => $jurisdiction,
                "zip"          => $zip,
                "geometry"     => $geometry
            );
        }
    }

    // 🔹 Step 5: Jurisdiction zoning lookup
    $normalizedJurisdiction = null;
    if (!empty($parcels) && !empty($parcels[0]['jurisdiction'])) {
        $normalizedJurisdiction = normalizeJurisdiction($parcels[0]['jurisdiction'], "Maricopa County");
    }

    // Populate parcel zoning first
    foreach ($parcels as $k => $parcel) {
        $lat = isset($parcel['geometry']['centroid']['lat']) ? $parcel['geometry']['centroid']['lat'] : $latitude;
        $lon = isset($parcel['geometry']['centroid']['lon']) ? $parcel['geometry']['centroid']['lon'] : $longitude;
        $nj  = normalizeJurisdiction($parcel['jurisdiction'], "Maricopa County");
        $parcels[$k]['jurisdiction']       = $nj;
        $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning($nj, $lat, $lon, $parcel['geometry']);
    }

    // Then query zoning at the address point
    if ($normalizedJurisdiction) {
        $primaryZoning = getJurisdictionZoning($normalizedJurisdiction, $latitude, $longitude, null);
        if (!$primaryZoning) $primaryZoning = "UNKNOWN";

        // Gilbert-specific override
        if ($normalizedJurisdiction === "GILBERT" && $primaryZoning === "PF/I (Public Facility/Institutional)") {
            foreach ($parcels as $parcel) {
                if ($parcel['jurisdictionZoning'] === "HVC (Heritage Village Center District)") {
                    $primaryZoning = $parcel['jurisdictionZoning'];
                    $baseDisclaimers[] = "⚠️ Address point zoning adjusted to match nearby parcel zoning.";
                    break;
                }
            }
        }
    }

    // 🔹 Step 6: Context for disclaimers
    $context = array(
        "multipleParcels"         => (count($parcels) > 1),
        "multiParcelSite"         => false,
        "mixedParcelZoning"       => false,
        "unsupportedJurisdiction" => false,
        "pucMismatch"             => false,
        "splitZoning"             => false,
        "fuzzyMatch"              => ($parcelStatus === "fuzzy"),
        "noParcel"                => ($parcelStatus === "none"),
        "jurisdiction"            => $normalizedJurisdiction ? strtolower(trim($normalizedJurisdiction)) : null
    );

    if (count($parcels) > 1) {
        $zonings = array();
        foreach ($parcels as $parcel) {
            if (!empty($parcel['jurisdictionZoning'])) {
                $zonings[] = $parcel['jurisdictionZoning'];
            }
        }
        $uniqueZonings = array_unique($zonings);
        $context["multiParcelSite"]   = (count($uniqueZonings) === 1);
        $context["mixedParcelZoning"] = (count($uniqueZonings) > 1);
    }

    if ($primaryZoning === "UNKNOWN") {
        $baseDisclaimers[] = $disclaimersConfig['Zoning Report']['zoningUnavailable'][0];
    }

    // 🔹 Step 7: Disclaimers
    $disclaimers = getApplicableDisclaimers("Zoning Report", $context);

    // 🔹 Step 8: Return report
    $conversation['lastAddress'] = $address;
    $conversation['lastZoning']  = $primaryZoning;

    return array(
        "error"            => false,
        "response"         => "📄 Zoning report request created for " . $address . ".",
        "actionType"       => "Create",
        "reportType"       => "Zoning Report",
        "jurisdictionZoning" => $primaryZoning,
        "inputs" => array(
            "address"        => $address,
            "matchedAddress" => $matchedAddress ? $matchedAddress : $address,
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
