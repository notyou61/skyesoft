<?php
// 📄 File: api/jurisdictionZoning.php
// Provides jurisdiction-specific zoning lookups. 
// Always returns zoning string, "UNKNOWN" if lookup fails, or null if unsupported.

function getJurisdictionZoning($jurisdiction, $lat = null, $lon = null, $geometry = null) {
    static $jurisdictions = null;
    if ($jurisdictions === null) {
        $jurisdictions = json_decode(file_get_contents(__DIR__ . '/../assets/data/jurisdictions.json'), true);
    }

    if (!isset($jurisdictions[$jurisdiction]['api'])) {
        return null; // unsupported
    }

    $apiConfig = $jurisdictions[$jurisdiction]['api'];
    $endpoint  = $apiConfig['endpoint'];
    $origLat   = $lat;
    $origLon   = $lon;

    // Handle projection if required
    if (!empty($apiConfig['requiresProjection']) && $lat !== null && $lon !== null) {
        $projUrl = "https://gis.mesaaz.gov/arcgis/rest/services/Utilities/Geometry/GeometryServer/project";
        $params = http_build_query([
            "f" => "json",
            "inSR" => 4326,
            "outSR" => $apiConfig['projectionTarget'],
            "geometries" => json_encode([
                "geometryType" => "esriGeometryPoint",
                "geometries" => [["x" => $lon, "y" => $lat]]
            ])
        ]);
        $projResp = @file_get_contents($projUrl . "?" . $params);
        if ($projResp !== false) {
            $projData = json_decode($projResp, true);
            if (!empty($projData['geometries'][0]['x'])) {
                $lon = $projData['geometries'][0]['x'];
                $lat = $projData['geometries'][0]['y'];
            }
        }
    }

    // Phoenix special case
    if (strtoupper($jurisdiction) === "PHOENIX") {
        if ($lat !== null && $lon !== null) {
            $geom = json_encode([
                "x" => $lon,
                "y" => $lat,
                "spatialReference" => ["wkid" => 4326]
            ]);
            $url = $endpoint . "?f=json"
                 . "&geometry=" . urlencode($geom)
                 . "&geometryType=esriGeometryPoint"
                 . "&inSR=4326"
                 . "&spatialRel=esriSpatialRelIntersects"
                 . "&outFields=" . implode(',', $apiConfig['outFields'])
                 . "&returnGeometry=false";
        } else {
            return "UNKNOWN";
        }
    }
    // Polygon handling
    elseif ($geometry && in_array('polygon', $apiConfig['modes'])) {
        $geom = json_encode($geometry);
        $inSR = !empty($apiConfig['alt_srid']) ? $apiConfig['alt_srid'] : $apiConfig['srid'];
        $url = $endpoint . "?f=json"
            . "&geometry=" . urlencode($geom)
            . "&geometryType=esriGeometryPolygon"
            . "&inSR=" . $inSR
            . "&spatialRel=esriSpatialRelIntersects"
            . "&outFields=" . implode(',', $apiConfig['outFields'])
            . "&returnGeometry=false";
    }
    // Generic point handling (Mesa and others)
    elseif ($lat !== null && $lon !== null && in_array('point', $apiConfig['modes'])) {
        $geom = json_encode([
            "x" => $lon,
            "y" => $lat,
            "spatialReference" => ["wkid" => $apiConfig['srid']]
        ]);
        $url = $endpoint . "?f=json"
             . "&geometry=" . urlencode($geom)
             . "&geometryType=esriGeometryPoint"
             . "&inSR=" . $apiConfig['srid']
             . "&spatialRel=esriSpatialRelIntersects"
             . "&outFields=" . implode(',', $apiConfig['outFields'])
             . "&returnGeometry=false";
    } else {
        return "UNKNOWN";
    }

    // Execute request
    $resp = @file_get_contents($url);
    if ($resp !== false) {
        $data = json_decode($resp, true);
        if (!empty($data['features'][0]['attributes'])) {
            $attrs  = $data['features'][0]['attributes'];
            $fields = $apiConfig['responseFields'];
            foreach (['primary', 'secondary', 'tertiary'] as $level) {
                if (!empty($fields[$level])) {
                    $fieldName = $fields[$level];
                    if (isset($attrs[$fieldName]) && trim($attrs[$fieldName]) !== "") {
                        return trim($attrs[$fieldName]);
                    }
                }
            }
        }
    }

    // Error logging for debugging
    $logDir = __DIR__ . "/../assets/logs";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $debugFile = $logDir . "/zoning_debug.log";

    $logMsg  = strtoupper($jurisdiction) . " zoning debug:\n";
    $logMsg .= "Original coords (4326): $origLon, $origLat\n";
    $logMsg .= "Projected coords (" . $apiConfig['srid'] . "): $lon, $lat\n";
    $logMsg .= "URL: " . $url . "\n";
    if ($resp !== false) {
        $logMsg .= "RAW RESPONSE: " . substr($resp, 0, 5000) . "\n";
    } else {
        $logMsg .= "RAW RESPONSE: (no response)\n";
    }
    $logMsg .= "ATTRS: " . (isset($attrs) ? json_encode($attrs) : "NONE") . "\n";

    file_put_contents($debugFile, $logMsg . "\n---\n", FILE_APPEND | LOCK_EX);

    return "UNKNOWN";
}