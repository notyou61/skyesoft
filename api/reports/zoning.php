<?php
// 📄 File: api/reports/zoning.php
// Purpose: Generate zoning reports with parcel lookup, jurisdiction zoning integration, and context-driven disclaimers.

/**
 * Generate a Zoning Report
 */
function generateZoningReport($prompt, &$conversation) {
    // --------------------------------------------------------
    // 🏁 Step 1: Extract and Normalize Address
    // --------------------------------------------------------
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

    // Validation: require street number + ZIP
    $hasStreetNum = preg_match('/\b\d{3,5}\b/', $address);
    $hasZip       = preg_match('/\b\d{5}\b/', $address);
    if (!$hasStreetNum || !$hasZip) {
        return [
            "error" => true,
            "response" => "⚠️ Please include both a street number and a 5-digit ZIP code to create a zoning report.",
            "providedInput" => $address
        ];
    }

    // --------------------------------------------------------
    // 🌐 Step 2: Geocode via Census (fallback Google if fails)
    // --------------------------------------------------------
    $county = null; $stateFIPS = null; $countyFIPS = null;
    $latitude = null; $longitude = null;
    $matchedAddress = null; $state = null;

    // Census Locations API
    $locUrl = "https://geocoding.geo.census.gov/geocoder/locations/onelineaddress"
        . "?address=" . urlencode($address)
        . "&benchmark=Public_AR_Current&format=json";
    $locData = json_decode(@file_get_contents($locUrl), true);
    if ($locData && isset($locData['result']['addressMatches'][0])) {
        $match = $locData['result']['addressMatches'][0];
        $matchedAddress = $match['matchedAddress'] ?? $matchedAddress;
        if (isset($match['coordinates'])) {
            $longitude = $match['coordinates']['x'];
            $latitude  = $match['coordinates']['y'];
        }
    }

    // Census Geographies API (primary source of county/FIPS)
    $geoUrl = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress"
        . "?address=" . urlencode($address)
        . "&benchmark=Public_AR_Current&vintage=Current_Current&layers=all&format=json";
    $geoData = json_decode(@file_get_contents($geoUrl), true);
    if ($geoData && isset($geoData['result']['addressMatches'][0]['geographies']['Counties'][0])) {
        $countyData = $geoData['result']['addressMatches'][0]['geographies']['Counties'][0];
        $county     = $countyData['NAME'] ?? null;
        $stateFIPS  = $countyData['STATE'] ?? null;
        $countyFIPS = $countyData['COUNTY'] ?? null;
    } else {
        // Fallback: Google Geocoding API
        $googleKey = getenv("GOOGLE_MAPS_BACKEND_API_KEY");
        if ($googleKey) {
            $googleUrl = "https://maps.googleapis.com/maps/api/geocode/json"
                . "?address=" . urlencode($address)
                . "&key=" . $googleKey;
            $googleData = json_decode(@file_get_contents($googleUrl), true);
            if ($googleData && isset($googleData['results'][0])) {
                $gResult = $googleData['results'][0];
                $matchedAddress = $matchedAddress ?: strtoupper($gResult['formatted_address']);
                if (isset($gResult['geometry']['location'])) {
                    $latitude  = $gResult['geometry']['location']['lat'];
                    $longitude = $gResult['geometry']['location']['lng'];
                }
                foreach ($gResult['address_components'] as $comp) {
                    if (in_array("administrative_area_level_2", $comp['types'])) {
                        $county = $comp['long_name'];
                    }
                    if (in_array("administrative_area_level_1", $comp['types'])) {
                        $state = $comp['short_name'];
                    }
                }
                if ($county === "Maricopa County" && $state === "AZ") {
                    $stateFIPS  = "04";
                    $countyFIPS = "013";
                }
            }
        }
    }

    // --------------------------------------------------------
    // 🗂 Step 3: Parcel Lookup (Maricopa Assessor API only)
    // --------------------------------------------------------
    $assessorApi = getAssessorApi($stateFIPS, $countyFIPS);
    $parcels = []; $parcelStatus = "none";

    if ($countyFIPS === "013" && $stateFIPS === "04" && $matchedAddress) {
        // … existing parcel lookup logic unchanged …
        // (runs fuzzy match, queries APNs, attaches geometry)
        // At the end fills $parcels[]
    }

    // --------------------------------------------------------
    // 🏘 Step 4: Jurisdiction + Zoning Lookup
    // --------------------------------------------------------
    $context = [
        "multipleParcels"         => (count($parcels) > 1),
        "unsupportedJurisdiction" => false,
        "multiParcelSite"         => false,
        "mixedParcelZoning"       => false,
        "pucMismatch"             => false,
        "splitZoning"             => false,
        "centroidUsed"            => false,
        "zoningUnavailable"       => false
    ];

    if (count($parcels) > 0) {
        foreach ($parcels as $k => $parcel) {
            $lat = $latitude; $lon = $longitude;
            if (isset($parcel['geometry']['centroid'])) {
                $lat = $parcel['geometry']['centroid']['lat'];
                $lon = $parcel['geometry']['centroid']['lon'];
                $context["centroidUsed"] = true; // ℹ️ using centroid fallback
            }

            // Normalize jurisdiction names (e.g., NO CITY/TOWN → Maricopa County)
            $normalizedJurisdiction = normalizeJurisdiction(
                $parcel['jurisdiction'],
                "Maricopa County"
            );
            $parcels[$k]['jurisdiction'] = $normalizedJurisdiction;

            // Jurisdiction zoning call
            $parcels[$k]['jurisdictionZoning'] = getJurisdictionZoning(
                $normalizedJurisdiction,
                $lat,
                $lon,
                $parcel['geometry']
            );

            // Flag unsupported jurisdiction
            if ($normalizedJurisdiction === "Maricopa County" || $parcels[$k]['jurisdictionZoning'] === null) {
                $context["unsupportedJurisdiction"] = true;
                if ($parcels[$k]['jurisdictionZoning'] === null) {
                    $context["zoningUnavailable"] = true;
                }
            }
        }

        // Multi-parcel handling
        $zonings = array_filter(array_map(fn($p) => $p['jurisdictionZoning'], $parcels));
        if (count($zonings) > 1) {
            if (count(array_unique($zonings)) === 1) {
                $context["multiParcelSite"] = true; // all same zoning
            } else {
                $context["mixedParcelZoning"] = true; // zoning differs
            }
        }
    }

    // Parcel Status flags
    if ($parcelStatus === "fuzzy") $context["fuzzyMatch"] = true;
    if ($parcelStatus === "none")  $context["noParcel"]   = true;

    // --------------------------------------------------------
    // ⚠️ Step 5: Disclaimers
    // --------------------------------------------------------
    $disclaimers = getApplicableDisclaimers("Zoning Report", $context);

    // --------------------------------------------------------
    // 📄 Step 6: Return Structured Report
    // --------------------------------------------------------
    return [
        "error"      => false,
        "response"   => "📄 Zoning report request created for " . $address . ".",
        "actionType" => "Create",
        "reportType" => "Zoning Report",
        "inputs"     => [
            "address"        => $address,
            "matchedAddress" => $matchedAddress ?: $address,
            "county"         => $county,
            "stateFIPS"      => $stateFIPS,
            "countyFIPS"     => $countyFIPS,
            "latitude"       => $latitude,
            "longitude"      => $longitude,
            "assessorApi"    => $assessorApi,
            "parcelStatus"   => $parcelStatus,
            "parcels"        => $parcels
        ],
        "disclaimers" => ["Zoning Report" => $disclaimers]
    ];
}
