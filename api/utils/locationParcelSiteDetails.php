<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — locationParcelSiteDetails.php
//  Version: 1.0.0 (GIS Site Details & SVG Diagram Renderer)
// ======================================================================

/**
 * Calculates polygon area in sq ft using the Shoelace (Gauss's Area) formula.
 * Subtracts interior rings (holes) if present.
 */
function calculateParcelAreaSqFt(array $rings): float
{
    $totalArea = 0.0;
    foreach ($rings as $index => $ring) {
        $numPoints = count($ring);
        if ($numPoints < 3) {
            continue;
        }

        $ringArea = 0.0;
        for ($i = 0; $i < $numPoints; $i++) {
            $j = ($i + 1) % $numPoints;
            $p1 = $ring[$i];
            $p2 = $ring[$j];
            $ringArea += ((float)$p1[0] * (float)$p2[1]) - ((float)$p2[0] * (float)$p1[1]);
        }
        $ringArea = abs($ringArea) / 2.0;

        // Exterior ring (index 0) adds area; interior rings (holes) subtract area
        if ($index === 0) {
            $totalArea += $ringArea;
        } else {
            $totalArea -= $ringArea;
        }
    }
    return max(0.0, $totalArea);
}

/**
 * Determines parcel physical configuration based on frontage count and topology.
 */
function determineParcelConfiguration(int $frontageCount, array $frontages): string
{
    if ($frontageCount === 0) {
        return 'Interior Lot (Unverified Frontage)';
    }
    if ($frontageCount === 1) {
        return 'Standard Interior Lot';
    }
    if ($frontageCount === 2) {
        // Check if frontages are parallel or opposite to distinguish Corner vs Through
        $names = array_values(array_column($frontages, 'streetName'));
        return 'Corner / Through Lot';
    }
    return 'Multi-Frontage Complex Lot (' . $frontageCount . ' Frontages)';
}

/**
 * Performs a read-only GIS fallback fetch using APN and Jurisdiction
 * when the database report record lacks geometry or frontages.
 */
function fetchGisSiteDetailsFallback(string $apn, string $jurisdiction): array
{
    $cleanApn = preg_replace('/[^A-Za-z0-9]/', '', $apn);
    if (empty($cleanApn)) {
        return ['parcelGeometry' => null, 'frontages' => []];
    }

    $parcelEndpoint = 'https://gis.mcassessor.maricopa.gov/arcgis/rest/services/MaricopaDynamicQueryService/MapServer/3/query';
    $parcelUrl = $parcelEndpoint . '?' . http_build_query([
        'f'              => 'json',
        'where'          => "APN='" . str_replace("'", "''", $cleanApn) . "'",
        'outFields'      => '*',
        'returnGeometry' => 'true',
        'outSR'          => '2223'
    ]);

    $ch = curl_init($parcelUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: Skyesoft-Report/1.0']
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) {
        return ['parcelGeometry' => null, 'frontages' => []];
    }

    $data = json_decode((string)$res, true);
    $features = $data['features'] ?? [];
    if (empty($features)) {
        return ['parcelGeometry' => null, 'frontages' => []];
    }

    $rings = $features[0]['geometry']['rings'] ?? [];
    if (empty($rings)) {
        return ['parcelGeometry' => null, 'frontages' => []];
    }

    // Extent & Normalization
    $xs = []; $ys = [];
    $normRings = [];
    foreach ($rings as $ring) {
        $normRing = [];
        foreach ($ring as $pt) {
            if (isset($pt[0], $pt[1])) {
                $x = (float)$pt[0];
                $y = (float)$pt[1];
                $xs[] = $x; $ys[] = $y;
                $normRing[] = [round($x, 3), round($y, 3)];
            }
        }
        if (!empty($normRing)) {
            $normRings[] = $normRing;
        }
    }

    if (empty($xs) || empty($ys)) {
        return ['parcelGeometry' => null, 'frontages' => []];
    }

    $bounds = [
        'xmin' => round(min($xs), 3),
        'ymin' => round(min($ys), 3),
        'xmax' => round(max($xs), 3),
        'ymax' => round(max($ys), 3)
    ];

    $parcelGeometry = [
        'geometryType'     => 'polygon',
        'spatialReference' => ['wkid' => 2223, 'units' => 'feet'],
        'rings'            => $normRings,
        'bounds'           => $bounds
    ];

    // Fetch Street Frontages via County Street Layer
    $streetEndpoint = 'https://services.arcgis.com/ykpntM6e3tHvzKRJ/arcgis/rest/services/Maricopa_County_Streets/FeatureServer/0/query';
    $streetEnvelope = [
        'xmin' => $bounds['xmin'] - 125,
        'ymin' => $bounds['ymin'] - 125,
        'xmax' => $bounds['xmax'] + 125,
        'ymax' => $bounds['ymax'] + 125,
        'spatialReference' => ['wkid' => 2223]
    ];

    $streetUrl = $streetEndpoint . '?' . http_build_query([
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

    $ch2 = curl_init($streetUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: Skyesoft-Report/1.0']
    ]);
    $res2 = curl_exec($ch2);
    curl_close($ch2);

    $streetData = json_decode((string)$res2, true);
    $streetFeatures = $streetData['features'] ?? [];

    if (function_exists('calculateParcelFrontages')) {
        $frontages = calculateParcelFrontages($normRings, $streetFeatures, time());
        if (strtolower(trim($jurisdiction)) === 'phoenix' && function_exists('enrichPhoenixFrontages')) {
            $phoenixRes = enrichPhoenixFrontages($frontages, $streetEnvelope);
            $frontages = $phoenixRes['frontages'];
        }
        return [
            'parcelGeometry' => $parcelGeometry,
            'frontages'      => array_values($frontages)
        ];
    }

    return ['parcelGeometry' => $parcelGeometry, 'frontages' => []];
}

/**
 * Generates an mPDF-compatible SVG Diagram for the parcel and street frontages.
 */
function renderParcelSvgDiagram(array $geometry, array $frontages, int $width = 680, int $height = 300): string
{
    $bounds = $geometry['bounds'] ?? null;
    $rings  = $geometry['rings'] ?? [];

    if (empty($bounds) || empty($rings)) {
        return '';
    }

    $xmin = (float)$bounds['xmin'];
    $xmax = (float)$bounds['xmax'];
    $ymin = (float)$bounds['ymin'];
    $ymax = (float)$bounds['ymax'];

    $dx = max($xmax - $xmin, 1.0);
    $dy = max($ymax - $ymin, 1.0);

    $padding = 40;
    $drawW   = $width - (2 * $padding);
    $drawH   = $height - (2 * $padding);

    $scale = min($drawW / $dx, $drawH / $dy);

    // Coordinate Transform: Maps StatePlane Feet (WKID 2223) to SVG Screen Space
    // Flips GIS Y-axis (north up) for standard SVG display
    $mapX = function (float $x) use ($xmin, $padding, $drawW, $dx, $scale): float {
        return $padding + (($drawW - ($dx * $scale)) / 2) + (($x - $xmin) * $scale);
    };

    $mapY = function (float $y) use ($ymax, $padding, $drawH, $dy, $scale): float {
        return $padding + (($drawH - ($dy * $scale)) / 2) + (($ymax - $y) * $scale);
    };

    // Build Polygon Path Data
    $pathSvg = '';
    foreach ($rings as $ring) {
        if (count($ring) < 3) continue;
        $d = [];
        foreach ($ring as $i => $pt) {
            $sx = round($mapX((float)$pt[0]), 2);
            $sy = round($mapY((float)$pt[1]), 2);
            $d[] = ($i === 0 ? 'M' : 'L') . " {$sx} {$sy}";
        }
        $d[] = 'Z';
        $pathSvg .= implode(' ', $d) . ' ';
    }

    // Build Frontage Overlays & Labels
    $frontageSvg = '';
    $labelsSvg   = '';

    foreach ($frontages as $frontage) {
        $roadTier = $frontage['roadTier'] ?? 'lowVolume';
        $color    = ($roadTier === 'highVolume') ? '#d9534f' : '#0275d8'; // Red for High-Vol, Blue for Low-Vol
        $stName   = htmlspecialchars($frontage['streetName'] ?? 'Street', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lengthFt = number_format((float)($frontage['frontageLengthFeet'] ?? 0), 1);

        $segments = $frontage['parcelSegments'] ?? [];
        $midpoints = [];

        foreach ($segments as $seg) {
            $x1 = $mapX((float)$seg['start'][0]);
            $y1 = $mapY((float)$seg['start'][1]);
            $x2 = $mapX((float)$seg['end'][0]);
            $y2 = $mapY((float)$seg['end'][1]);

            $frontageSvg .= sprintf(
                '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke="%s" stroke-width="4.5" stroke-linecap="round" />',
                $x1, $y1, $x2, $y2, $color
            ) . "\n";

            $midpoints[] = [($x1 + $x2) / 2.0, ($y1 + $y2) / 2.0];
        }

        // Center label on longest or primary segment
        if (!empty($midpoints)) {
            $avgX = array_sum(array_column($midpoints, 0)) / count($midpoints);
            $avgY = array_sum(array_column($midpoints, 1)) / count($midpoints);

            $labelText = "{$stName} ({$lengthFt}')";
            $labelsSvg .= sprintf(
                '<g transform="translate(%.2f, %.2f)">
                    <rect x="-65" y="-11" width="130" height="18" rx="3" fill="#ffffff" fill-opacity="0.9" stroke="%s" stroke-width="1" />
                    <text x="0" y="2" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="9" font-weight="bold" fill="#333333">%s</text>
                </g>',
                $avgX, $avgY, $color, htmlspecialchars($labelText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ) . "\n";
        }
    }

    // Assemble SVG
    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" style="background-color: #fcfcfc; border: 1px solid #e0e0e0; border-radius: 4px;">
            <!-- Grid / Background Overlay -->
            <rect width="100%%" height="100%%" fill="#fafafa" />
            
            <!-- Base Parcel Polygon -->
            <path d="%s" fill="#e8f4f8" stroke="#555555" stroke-width="1.5" stroke-linejoin="round" />
            
            <!-- Frontage Segments -->
            %s
            
            <!-- Street Labels -->
            %s
            
            <!-- Compass / Orientation Indicator -->
            <g transform="translate(%d, 25)">
                <circle cx="0" cy="0" r="10" fill="#ffffff" stroke="#aaaaaa" stroke-width="1"/>
                <text x="0" y="-12" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="8" font-weight="bold" fill="#666666">N</text>
                <path d="M 0 6 L 0 -6 M -4 -2 L 0 -8 L 4 -2" fill="none" stroke="#d9534f" stroke-width="1.5"/>
            </g>
            
            <!-- Legend -->
            <g transform="translate(15, %d)">
                <rect x="0" y="0" width="220" height="22" fill="#ffffff" fill-opacity="0.85" rx="3" stroke="#dddddd" stroke-width="0.5"/>
                <line x1="10" y1="11" x2="25" y2="11" stroke="#d9534f" stroke-width="3"/>
                <text x="30" y="14" font-family="Helvetica, Arial, sans-serif" font-size="8" fill="#333333">High-Volume Frontage</text>
                <line x1="115" y1="11" x2="130" y2="11" stroke="#0275d8" stroke-width="3"/>
                <text x="135" y="14" font-family="Helvetica, Arial, sans-serif" font-size="8" fill="#333333">Low-Volume Frontage</text>
            </g>
        </svg>',
        $width, $height, $width, $height,
        trim($pathSvg),
        $frontageSvg,
        $labelsSvg,
        $width - 25,
        $height - 30
    );

    return $svg;
}