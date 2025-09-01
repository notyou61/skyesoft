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

    // Handle projection if required (stub — implement if needed)
    if (!empty($apiConfig['requiresProjection'])) {
        // TODO: call ArcGIS GeometryServer with $lat/$lon → $apiConfig['projectionTarget']
    }

    // Build geometry (point vs polygon)
    if ($geometry && in_array('polygon', $apiConfig['modes'])) {
        // get geometry as GeoJSON polygon
        $geom = json_encode($geometry);
        // Use alternate SRID if provided
        $inSR = !empty($apiConfig['alt_srid']) ? $apiConfig['alt_srid'] : $apiConfig['srid'];
        // Polygon query
        $url = $endpoint . "?f=json"
            . "&geometry=" . urlencode($geom)
            . "&geometryType=esriGeometryPolygon"
            . "&inSR=" . $inSR
            . "&spatialRel=esriSpatialRelIntersects"
            . "&outFields=" . implode(',', $apiConfig['outFields'])
            . "&returnGeometry=false";
    } elseif ($lat !== null && $lon !== null && in_array('point', $apiConfig['modes'])) {
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
        // Parse response
        if (!empty($data['features'][0]['attributes'])) {
            $attrs  = $data['features'][0]['attributes'];
            $fields = $apiConfig['responseFields'];
            // For each level, return the first non-empty field
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
    error_log("Phoenix zoning debug: " . json_encode($attrs));
    // If we reach here, no zoning found
    return "UNKNOWN";
}