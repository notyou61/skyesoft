<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — locationParcelSiteDetails.php (Helper Module)
//  Version: 1.0.0
// ======================================================================

/**
 * Resolve parcel geometry & frontages via GIS if not present in saved report data.
 * Does NOT write or update any database tables.
 */
function resolveReportParcelSiteDetails(array $reportData): array
{
    $geometry = $reportData['parcelGeometry'] ?? null;
    $frontages = $reportData['frontages'] ?? [];
    $apn = $reportData['apn'] ?? $reportData['parcelNumber'] ?? $reportData['locationParcelNumber'] ?? null;
    $jurisdiction = $reportData['jurisdiction'] ?? $reportData['locationJurisdiction'] ?? 'Phoenix';

    if (!empty($geometry) && !empty($geometry['rings'])) {
        return [
            'parcelGeometry' => $geometry,
            'frontages'      => $frontages,
            'source'         => 'saved_record'
        ];
    }

    if (empty($apn)) {
        return [
            'parcelGeometry' => null,
            'frontages'      => $frontages,
            'source'         => 'unavailable'
        ];
    }

    $cleanApn = preg_replace('/[^A-Za-z0-9]/', '', (string)$apn);
    if ($cleanApn === '') {
        return [
            'parcelGeometry' => null,
            'frontages'      => $frontages,
            'source'         => 'unavailable'
        ];
    }

    $parcelUrl = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/MaricopaDynamicQueryService/MapServer/3/query?' . http_build_query([
        'f'              => 'json',
        'where'          => "APN='" . str_replace("'", "''", $cleanApn) . "'",
        'outFields'      => '*',
        'returnGeometry' => 'true',
        'outSR'          => '2223'
    ]);

    $res = httpGetJsonDetailed($parcelUrl, 10);
    $features = $res['data']['features'] ?? [];
    if (empty($features)) {
        return [
            'parcelGeometry' => null,
            'frontages'      => $frontages,
            'source'         => 'gis_lookup_failed'
        ];
    }

    $rings = $features[0]['geometry']['rings'] ?? [];
    $extent = calculateGeometryExtent($rings);
    if (empty($rings) || $extent === null) {
        return [
            'parcelGeometry' => null,
            'frontages'      => $frontages,
            'source'         => 'gis_geometry_invalid'
        ];
    }

    $fetchedGeometry = [
        'geometryType'     => 'polygon',
        'spatialReference' => ['wkid' => 2223, 'units' => 'feet'],
        'rings'            => normalizeGeometryRings($rings),
        'bounds'           => normalizeGeometryBounds($extent)
    ];

    if (empty($frontages)) {
        $streetEnvelope = [
            'xmin' => $extent['xmin'] - 125,
            'ymin' => $extent['ymin'] - 125,
            'xmax' => $extent['xmax'] + 125,
            'ymax' => $extent['ymax'] + 125,
            'spatialReference' => ['wkid' => 2223]
        ];

        $streetUrl = 'https://services.arcgis.com/ykpntM6e3tHvzKRJ/arcgis/rest/services/Maricopa_County_Streets/FeatureServer/0/query?' . http_build_query([
            'f'              => 'json',
            'where'          => 'IsBuilt=1 AND IsPublic=1',
            'geometry'       => json_encode($streetEnvelope),
            'geometryType'   => 'esriGeometryEnvelope',
            'inSR'           => '2223',
            'outSR'          => '2223',
            'spatialRel'     => 'esriSpatialRelIntersects',
            'outFields'      => '*',
            'returnGeometry' => 'true'
        ]);

        $streetRes = httpGetJsonDetailed($streetUrl, 10);
        $streetFeatures = $streetRes['data']['features'] ?? [];
        $frontages = calculateParcelFrontages($rings, $streetFeatures, time());

        if (strtolower(trim((string)$jurisdiction)) === 'phoenix' && !empty($frontages)) {
            $phoenixResult = enrichPhoenixFrontages($frontages, $streetEnvelope);
            $frontages = $phoenixResult['frontages'];
        }
    }

    return [
        'parcelGeometry' => $fetchedGeometry,
        'frontages'      => array_values($frontages),
        'source'         => 'gis_live_lookup'
    ];
}

/**
 * Compute parcel surface area in sq. ft. from spatial coordinate rings using Shoelace Formula.
 */
function calculatePolygonAreaSqFt(array $rings): float
{
    $totalArea = 0.0;
    foreach ($rings as $index => $ring) {
        $count = count($ring);
        if ($count < 3) {
            continue;
        }

        $ringArea = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $j = ($i + 1) % $count;
            $ringArea += ((float)$ring[$i][0] * (float)$ring[$j][1]);
            $ringArea -= ((float)$ring[$j][0] * (float)$ring[$i][1]);
        }
        $ringArea = abs($ringArea) / 2.0;

        // Outer ring is added; inner rings (holes) are subtracted
        if ($index === 0) {
            $totalArea += $ringArea;
        } else {
            $totalArea -= $ringArea;
        }
    }
    return max(0.0, $totalArea);
}

/**
 * Determine parcel configuration layout based on frontages count.
 */
function determineParcelConfiguration(int $frontageCount): string
{
    switch ($frontageCount) {
        case 0:
            return 'Interior / Off-Street Parcel';
        case 1:
            return 'Interior Lot';
        case 2:
            return 'Corner Lot / Double Frontage';
        case 3:
            return 'Through Lot / Three-Street Frontage';
        default:
            return 'Multi-Frontage / Island Parcel (' . $frontageCount . ' Streets)';
    }
}

/**
 * Generate an mPDF-compatible inline SVG diagram of the parcel geometry and frontages.
 */
function generateParcelDiagramSvg(?array $parcelGeometry, array $frontages, int $svgWidth = 680, int $svgHeight = 340): string
{
    if (empty($parcelGeometry) || empty($parcelGeometry['rings']) || empty($parcelGeometry['bounds'])) {
        return '<div style="border:1px dashed #cbd5e1; background-color:#f8fafc; padding:25px; text-align:center; color:#64748b; font-size:11px; font-family:sans-serif;">'
            . '<strong>Parcel Diagram Unavailable</strong><br>GIS polygon geometry could not be mapped for this property record.'
            . '</div>';
    }

    $bounds = $parcelGeometry['bounds'];
    $minX = (float)$bounds['xmin'];
    $maxX = (float)$bounds['xmax'];
    $minY = (float)$bounds['ymin'];
    $maxY = (float)$bounds['ymax'];

    $pWidth  = max(1.0, $maxX - $minX);
    $pHeight = max(1.0, $maxY - $minY);

    $padding = 45;
    $drawW = $svgWidth - ($padding * 2);
    $drawH = $svgHeight - ($padding * 2);

    $scale = min($drawW / $pWidth, $drawH / $pHeight);

    $offsetX = $padding + ($drawW - ($pWidth * $scale)) / 2;
    $offsetY = $padding + ($drawH - ($pHeight * $scale)) / 2;

    $transformPoint = function(array $pt) use ($minX, $maxY, $scale, $offsetX, $offsetY): array {
        $x = $offsetX + (((float)$pt[0] - $minX) * $scale);
        // Reverse Y-axis for SVG projection
        $y = $offsetY + (($maxY - (float)$pt[1]) * $scale);
        return [round($x, 2), round($y, 2)];
    };

    $svg  = '<svg width="100%" height="' . $svgHeight . '" viewBox="0 0 ' . $svgWidth . ' ' . $svgHeight . '" xmlns="http://www.w3.org/2000/svg" style="background-color:#ffffff; border:1px solid #e2e8f0; font-family:Helvetica, Arial, sans-serif;">';
    
    // Background Grid Pattern
    $svg .= '<defs>';
    $svg .= '<pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">';
    $svg .= '<path d="M 20 0 L 0 0 0 20" fill="none" stroke="#f1f5f9" stroke-width="1"/>';
    $svg .= '</pattern>';
    $svg .= '</defs>';
    $svg .= '<rect width="100%" height="100%" fill="url(#grid)"/>';

    // Draw Base Polygon Rings
    foreach ($parcelGeometry['rings'] as $ringIndex => $ring) {
        $pointsAttr = [];
        foreach ($ring as $pt) {
            $t = $transformPoint($pt);
            $pointsAttr[] = $t[0] . ',' . $t[1];
        }
        $fillColor = ($ringIndex === 0) ? '#f8fafc' : '#ffffff';
        $strokeColor = '#94a3b8';
        $svg .= '<polygon points="' . implode(' ', $pointsAttr) . '" fill="' . $fillColor . '" stroke="' . $strokeColor . '" stroke-width="2" stroke-dasharray="3,3"/>';
    }

    // Highlight Frontage Segments
    $labelsToDraw = [];
    foreach ($frontages as $frontage) {
        $tier = $frontage['roadTier'] ?? 'lowVolume';
        $color = ($tier === 'highVolume' || $tier === 'freeway') ? '#ef4444' : '#2563eb';
        $streetName = $frontage['streetName'] ?? 'Public Street';
        $lengthFt = number_format((float)($frontage['frontageLengthFeet'] ?? 0), 1) . "'";

        foreach (($frontage['parcelSegments'] ?? []) as $segment) {
            if (!isset($segment['start'], $segment['end'])) {
                continue;
            }
            $p1 = $transformPoint($segment['start']);
            $p2 = $transformPoint($segment['end']);

            $svg .= '<line x1="' . $p1[0] . '" y1="' . $p1[1] . '" x2="' . $p2[0] . '" y2="' . $p2[1] . '" stroke="' . $color . '" stroke-width="5" stroke-linecap="round"/>';

            $midX = ($p1[0] + $p2[0]) / 2;
            $midY = ($p1[1] + $p2[1]) / 2;
            $labelsToDraw[] = [
                'x'          => $midX,
                'y'          => $midY,
                'label'      => $streetName . ' (' . $lengthFt . ')',
                'color'      => $color
            ];
        }
    }

    // Draw Labels with Callout Boxes
    foreach ($labelsToDraw as $lbl) {
        $text = htmlspecialchars($lbl['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $boxW = strlen($text) * 6 + 12;
        $boxH = 18;
        $bx = $lbl['x'] - ($boxW / 2);
        $by = $lbl['y'] - ($boxH / 2);

        $svg .= '<rect x="' . $bx . '" y="' . $by . '" width="' . $boxW . '" height="' . $boxH . '" rx="3" fill="#ffffff" stroke="' . $lbl['color'] . '" stroke-width="1.5"/>';
        $svg .= '<text x="' . $lbl['x'] . '" y="' . ($lbl['y'] + 4) . '" font-size="9" font-weight="bold" fill="#1e293b" text-anchor="middle">' . $text . '</text>';
    }

    // Legend
    $svg .= '<g transform="translate(15, ' . ($svgHeight - 25) . ')">';
    $svg .= '<rect x="0" y="0" width="12" height="12" fill="#ef4444" rx="2"/>';
    $svg .= '<text x="18" y="10" font-size="9" fill="#475569" font-weight="bold">High-Volume Roadway</text>';
    $svg .= '<rect x="140" y="0" width="12" height="12" fill="#2563eb" rx="2"/>';
    $svg .= '<text x="158" y="10" font-size="9" fill="#475569" font-weight="bold">Low-Volume Roadway</text>';
    $svg .= '</g>';

    $svg .= '</svg>';
    return $svg;
}