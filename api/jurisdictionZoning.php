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
    $endpoint = $apiConfig['endpoint'];

    // Handle projection if required
    if (!empty($apiConfig['requiresProjection'])) {
        // call GeometryServer here with $lat/$lon → $apiConfig['projectionTarget']
    }

    // Build geometry (point vs polygon)
    if ($geometry && in_array('polygon', $apiConfig['modes'])) {
        $geom = json_encode($geometry);
        $url = $endpoint . "?f=json"
             . "&geometry=" . urlencode($geom)
             . "&geometryType=esriGeometryPolygon"
             . "&inSR=" . $apiConfig['alt_srid']
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
        if (!empty($data['features'][0]['attributes'][$apiConfig['responseFields']['primary']])) {
            $z = $data['features'][0]['attributes'][$apiConfig['responseFields']['primary']];
            $g = !empty($apiConfig['responseFields']['secondary']) && 
                 !empty($data['features'][0]['attributes'][$apiConfig['responseFields']['secondary']])
               ? $data['features'][0]['attributes'][$apiConfig['responseFields']['secondary']]
               : '';
            return trim($z . ($g ? " ($g)" : ""));
        }
    }

    return "UNKNOWN";
}