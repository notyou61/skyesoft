<?php
// 📄 File: api/jurisdictionZoning.php
// Provides jurisdiction-specific zoning lookups. 
// Always returns zoning string or null. No direct output.

function getJurisdictionZoning($jurisdiction, $latitude = null, $longitude = null, $geometry = null) {
    $zoning = null;

    if (empty($jurisdiction)) {
        return null;
    }

    switch (strtoupper($jurisdiction)) {

        // ✅ Phoenix (supports point or parcel polygon geometry)
        case "PHOENIX":
            if ($geometry && isset($geometry['rings'])) {
                // Polygon geometry (from parcel)
                $geom = json_encode($geometry);
                $url = "https://maps.phoenix.gov/pub/rest/services/Public/Zoning/MapServer/0/query"
                     . "?f=json"
                     . "&geometry=" . urlencode($geom)
                     . "&geometryType=esriGeometryPolygon"
                     . "&inSR=102100"
                     . "&spatialRel=esriSpatialRelIntersects"
                     . "&outFields=ZONING,GEN_ZONE,LABEL1"
                     . "&returnGeometry=false";
            } elseif ($latitude !== null && $longitude !== null) {
                // Point geometry (centroid)
                $geom = json_encode([
                    "x" => $longitude,
                    "y" => $latitude,
                    "spatialReference" => ["wkid" => 4326]
                ]);
                $url = "https://maps.phoenix.gov/pub/rest/services/Public/Zoning/MapServer/0/query"
                     . "?f=json"
                     . "&geometry=" . urlencode($geom)
                     . "&geometryType=esriGeometryPoint"
                     . "&inSR=4326"
                     . "&spatialRel=esriSpatialRelIntersects"
                     . "&outFields=ZONING,GEN_ZONE,LABEL1"
                     . "&returnGeometry=false";
            } else {
                return null;
            }

            $resp = @file_get_contents($url);
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if (!empty($data['features'][0]['attributes']['ZONING'])) {
                    $z = $data['features'][0]['attributes']['ZONING'];
                    $g = isset($data['features'][0]['attributes']['GEN_ZONE']) 
                       ? $data['features'][0]['attributes']['GEN_ZONE'] 
                       : '';
                    $zoning = trim($z . ($g ? " ($g)" : ""));
                }
            }
            break;

        // ✅ Mesa (point only, placeholder service)
        case "MESA":
            if ($latitude !== null && $longitude !== null) {
                $url = "https://services2.arcgis.com/Uq9r85Potqm3MfRV/arcgis/rest/services/Zoning/FeatureServer/0/query"
                     . "?f=json"
                     . "&geometry=" . $longitude . "," . $latitude
                     . "&geometryType=esriGeometryPoint"
                     . "&inSR=4326"
                     . "&spatialRel=esriSpatialRelIntersects"
                     . "&outFields=ZONE";
                $resp = @file_get_contents($url);
                if ($resp !== false) {
                    $data = json_decode($resp, true);
                    if (!empty($data['features'][0]['attributes']['ZONE'])) {
                        $zoning = $data['features'][0]['attributes']['ZONE'];
                    }
                }
            }
            break;

        // ✅ Gilbert (point only, placeholder service)
        case "GILBERT":
            if ($latitude !== null && $longitude !== null) {
                $url = "https://gis.gilbertaz.gov/arcgis/rest/services/Zoning/MapServer/0/query"
                     . "?f=json"
                     . "&geometry=" . $longitude . "," . $latitude
                     . "&geometryType=esriGeometryPoint"
                     . "&inSR=4326"
                     . "&spatialRel=esriSpatialRelIntersects"
                     . "&outFields=ZONING";
                $resp = @file_get_contents($url);
                if ($resp !== false) {
                    $data = json_decode($resp, true);
                    if (!empty($data['features'][0]['attributes']['ZONING'])) {
                        $zoning = $data['features'][0]['attributes']['ZONING'];
                    }
                }
            }
            break;

        default:
            // Unsupported jurisdiction
            return null;
    }

    return $zoning;
}
