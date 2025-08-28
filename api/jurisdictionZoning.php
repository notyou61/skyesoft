<?php
// 📄 api/jurisdictionZoning.php
// Centralized jurisdiction zoning lookup

function getJurisdictionZoning($jurisdiction, $latitude, $longitude, $geometry = null) {
    $zoning = "CONTACT LOCAL JURISDICTION"; // default
    // Normalize jurisdiction input
    switch (strtoupper($jurisdiction)) {
        // Phoenix Metro Area Example
        case "PHOENIX":
            if ($geometry && isset($geometry['coordinates']['rings'])) {
                // ✅ Polygon query
                $geomJson = json_encode($geometry['coordinates']);
                $url = "https://maps.phoenix.gov/pub/rest/services/Public/Zoning/MapServer/0/query"
                    . "?f=json"
                    . "&geometry=" . urlencode($geomJson)
                    . "&geometryType=esriGeometryPolygon"
                    . "&inSR=4326"
                    . "&spatialRel=esriSpatialRelIntersects"
                    . "&outFields=ZONING,GEN_ZONE,LABEL1"
                    . "&returnGeometry=false";
            } else {
                // ✅ Fallback to point query
                $url = "https://maps.phoenix.gov/pub/rest/services/Public/Zoning/MapServer/0/query"
                    . "?f=json"
                    . "&geometry=" . urlencode('{"x":' . $longitude . ',"y":' . $latitude . '}')
                    . "&geometryType=esriGeometryPoint"
                    . "&inSR=4326"
                    . "&spatialRel=esriSpatialRelIntersects"
                    . "&outFields=ZONING,GEN_ZONE,LABEL1"
                    . "&returnGeometry=false";
            }

            $resp = @file_get_contents($url);
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if (!empty($data['features'][0]['attributes']['ZONING'])) {
                    $attrs = $data['features'][0]['attributes'];
                    $zoning = $attrs['ZONING'];
                    if (!empty($attrs['GEN_ZONE'])) {
                        $zoning .= " (" . $attrs['GEN_ZONE'] . ")";
                    }
                }
            }
            break;

        // Mesa Example
        case "MESA":
            $url = "https://services2.arcgis.com/Uq9r85Potqm3MfRV/arcgis/rest/services/Zoning/FeatureServer/0/query"
                . "?f=json&geometry=" . $longitude . "," . $latitude
                . "&geometryType=esriGeometryPoint&inSR=4326&spatialRel=esriSpatialRelIntersects&outFields=ZONE";
            $resp = @file_get_contents($url);
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if (!empty($data['features'][0]['attributes']['ZONE'])) {
                    $zoning = $data['features'][0]['attributes']['ZONE'];
                }
            }
            break;

        // Gilbert Example
        case "GILBERT":
            $url = "https://gis.gilbertaz.gov/arcgis/rest/services/Zoning/MapServer/0/query"
                . "?f=json&geometry=" . $longitude . "," . $latitude
                . "&geometryType=esriGeometryPoint&inSR=4326&spatialRel=esriSpatialRelIntersects&outFields=ZONING";
            $resp = @file_get_contents($url);
            if ($resp !== false) {
                $data = json_decode($resp, true);
                if (!empty($data['features'][0]['attributes']['ZONING'])) {
                    $zoning = $data['features'][0]['attributes']['ZONING'];
                }
            }
            break;

        // 🔄 Add more cases here...

        default:
            $zoning = "CONTACT LOCAL JURISDICTION";
            break;
    }

    return $zoning;
}
// 🧪 Allow direct curl testing
if (php_sapi_name() !== 'cli') {
    $jurisdiction = isset($_GET['jurisdiction']) ? $_GET['jurisdiction'] : null;
    $latitude     = isset($_GET['latitude']) ? $_GET['latitude'] : null;
    $longitude    = isset($_GET['longitude']) ? $_GET['longitude'] : null;
    $geometry     = isset($_GET['geometry']) ? json_decode($_GET['geometry'], true) : null;

    if ($jurisdiction && ($geometry || ($latitude && $longitude))) {
        $result = getJurisdictionZoning($jurisdiction, $latitude, $longitude, $geometry);
        header('Content-Type: application/json');
        echo json_encode([
            "jurisdiction" => $jurisdiction,
            "zoning" => $result
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            "error" => "Missing parameters. Provide jurisdiction + (latitude/longitude) or geometry."
        ], JSON_PRETTY_PRINT);
    }
}
