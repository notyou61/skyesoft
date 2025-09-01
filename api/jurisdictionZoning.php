<?php
// 📄 File: api/jurisdictionZoning.php
// Provides jurisdiction-specific zoning lookups. 
// Always returns zoning string, "UNKNOWN" if lookup fails, or null if unsupported.

function getJurisdictionZoning($jurisdiction, $latitude = null, $longitude = null, $geometry = null) {
    $zoning = null;
    if (empty($jurisdiction)) {
        return null;
    }

    switch (strtoupper($jurisdiction)) {
        // ✅ Phoenix (supports point or parcel polygon geometry)
        case "PHOENIX":
            if ($geometry && isset($geometry['rings'])) {
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
                return "UNKNOWN";
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

        // ✅ Mesa zoning
        case "MESA":
            if ($latitude !== null && $longitude !== null) {
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

        // ✅ Gilbert zoning
        case "GILBERT":
            if ($latitude !== null && $longitude !== null) {
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

        // ✅ Scottsdale zoning
        case "SCOTTSDALE":
            if ($latitude !== null && $longitude !== null) {
                $endpoint = "https://maps.scottsdaleaz.gov/arcgis/rest/services/OpenData/MapServer/24/query";
                $geometry = json_encode(array(
                    "x" => $longitude,
                    "y" => $latitude,
                    "spatialReference" => array("wkid" => 4326)
                ));
                $params = array(
                    "f" => "json",
                    "geometry" => $geometry,
                    "geometryType" => "esriGeometryPoint",
                    "inSR" => 4326,
                    "spatialRel" => "esriSpatialRelIntersects",
                    "outFields" => "*",
                    "outSR" => 4326
                );
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $endpoint);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $result = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($result, true);
                if (isset($data["features"][0]["attributes"])) {
                    $attrs = $data["features"][0]["attributes"];
                    if (isset($attrs["full_zoning"])) {
                        $zoning = $attrs["full_zoning"];
                    } elseif (isset($attrs["comparable_zoning"])) {
                        $zoning = $attrs["comparable_zoning"];
                    }
                }
            }
            break;

        // Default: unsupported jurisdiction
        default:
            return null;
    }

    // If supported but lookup failed, return UNKNOWN instead of null
    if ($zoning === null) {
        return "UNKNOWN";
    }
    return $zoning;
}
