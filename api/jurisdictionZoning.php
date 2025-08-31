<?php
// 📄 File: api/jurisdictionZoning.php
// Provides jurisdiction-specific zoning lookups. 
// Always returns zoning string or null. No direct output.

function getJurisdictionZoning($jurisdiction, $latitude = null, $longitude = null, $geometry = null) {
    // Initialize zoning variable
    $zoning = null;
    // Validate jurisdiction input
    if (empty($jurisdiction)) {
        return null;
    }
    // Handle different jurisdictions
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

        // ✅ Mesa zoning (point query via local GIS)
        case "MESA":
            if ($latitude !== null && $longitude !== null) {
                // Project from WGS84 (4326) → Mesa’s local 2868
                $geom = json_encode([
                    "geometryType" => "esriGeometryPoint",
                    "geometries"   => [[ "x" => $longitude, "y" => $latitude ]]
                ]);
                $projectUrl = "https://utility.arcgisonline.com/arcgis/rest/services/Geometry/GeometryServer/project"
                    . "?f=json"
                    . "&inSR=4326"
                    . "&outSR=2868"
                    . "&geometries=" . urlencode($geom);

                $projResp = @file_get_contents($projectUrl);
                $projData = json_decode($projResp, true);

                if (!empty($projData['geometries'][0])) {
                    $pt = $projData['geometries'][0];
                    $geometry = json_encode([
                        "x" => $pt['x'],
                        "y" => $pt['y'],
                        "spatialReference" => ["wkid" => 2868]
                    ]);

                    $url = "https://gis.mesaaz.gov/mesaaz/rest/services/Accela/Accela_Base/MapServer/33/query"
                        . "?f=json"
                        . "&geometry=" . urlencode($geometry)
                        . "&geometryType=esriGeometryPoint"
                        . "&inSR=2868"
                        . "&spatialRel=esriSpatialRelIntersects"
                        . "&outFields=Zoning,Description"
                        . "&returnGeometry=false";

                    $resp = @file_get_contents($url);
                    if ($resp !== false) {
                        $data = json_decode($resp, true);
                        if (!empty($data['features'][0]['attributes']['Zoning'])) {
                            $attrs = $data['features'][0]['attributes'];
                            $zoning = $attrs['Zoning'];
                            if (!empty($attrs['Description'])) {
                                $zoning .= " (" . $attrs['Description'] . ")";
                            }
                        }
                    }
                }
            }
            break;

        // ✅ Gilbert zoning (point query via Growth_Development_Maps_1/MapServer/8)
        case "GILBERT":
            if ($latitude !== null && $longitude !== null) {
                // Project WGS84 (4326) -> Web Mercator (102100)
                $geom = json_encode([
                    "geometryType" => "esriGeometryPoint",
                    "geometries"   => [[ "x" => $longitude, "y" => $latitude ]]
                ]);
                $projectUrl = "https://utility.arcgisonline.com/arcgis/rest/services/Geometry/GeometryServer/project"
                    . "?f=json"
                    . "&inSR=4326"
                    . "&outSR=102100"
                    . "&geometries=" . urlencode($geom);

                $projResp = @file_get_contents($projectUrl);
                $projData = json_decode($projResp, true);

                if (!empty($projData['geometries'][0])) {
                    $pt = $projData['geometries'][0];
                    $geometry = json_encode([
                        "x" => $pt['x'],
                        "y" => $pt['y'],
                        "spatialReference" => ["wkid" => 102100]
                    ]);

                    $url = "https://maps.gilbertaz.gov/arcgis/rest/services/OD/Growth_Development_Maps_1/MapServer/8/query"
                        . "?f=json"
                        . "&geometry=" . urlencode($geometry)
                        . "&geometryType=esriGeometryPoint"
                        . "&inSR=102100"
                        . "&spatialRel=esriSpatialRelIntersects"
                        . "&outFields=ZCODE,Description"
                        . "&returnGeometry=false";

                    $resp = @file_get_contents($url);
                    if ($resp !== false) {
                        $data = json_decode($resp, true);
                        if (!empty($data['features'][0]['attributes'])) {
                            $attrs = $data['features'][0]['attributes'];
                            if (!empty($attrs['ZCODE'])) {
                                $zoning = $attrs['ZCODE'];
                                if (!empty($attrs['Description'])) {
                                    $zoning .= " (" . $attrs['Description'] . ")";
                                }
                            }
                        }
                    }
                }
            }
            break;
        // ✅ Scottsdale zoning (point query only, project to 102100)
        case "SCOTTSDALE":
            if ($latitude !== null && $longitude !== null) {
                // Project WGS84 → Web Mercator (102100)
                $geom = json_encode([
                    "geometryType" => "esriGeometryPoint",
                    "geometries"   => [[ "x" => $longitude, "y" => $latitude ]]
                ]);
                $projectUrl = "https://utility.arcgisonline.com/arcgis/rest/services/Geometry/GeometryServer/project"
                    . "?f=json"
                    . "&inSR=4326"
                    . "&outSR=102100"
                    . "&geometries=" . urlencode($geom);

                $projResp = @file_get_contents($projectUrl);
                $projData = json_decode($projResp, true);

                if (!empty($projData['geometries'][0])) {
                    $pt = $projData['geometries'][0];
                    $geometry = json_encode([
                        "x" => $pt['x'],
                        "y" => $pt['y'],
                        "spatialReference" => ["wkid" => 102100]
                    ]);

                    $url = "https://gis.scottsdaleaz.gov/arcgis/rest/services/Planning/Zoning/MapServer/0/query"
                        . "?f=json"
                        . "&geometry=" . urlencode($geometry)
                        . "&geometryType=esriGeometryPoint"
                        . "&inSR=102100"
                        . "&spatialRel=esriSpatialRelIntersects"
                        . "&outFields=ZONE_CODE,ZONE_DESC"
                        . "&returnGeometry=false";

                    $resp = @file_get_contents($url);
                    if ($resp !== false) {
                        $data = json_decode($resp, true);
                        if (!empty($data['features'][0]['attributes']['ZONE_CODE'])) {
                            $attrs = $data['features'][0]['attributes'];
                            $z     = $attrs['ZONE_CODE'];
                            $d     = !empty($attrs['ZONE_DESC']) ? $attrs['ZONE_DESC'] : '';
                            $zoning = trim($z . ($d ? " ($d)" : ""));
                        }
                    }
                }
            }
            break;

        // Default case for unsupported jurisdictions
        default:
            // Unsupported jurisdiction
            return null;
    }
    // Return the zoning result (string or null)
    return $zoning;
}
