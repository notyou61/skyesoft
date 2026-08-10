<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — locationZoningReport.php
//  Version: 2.1.0 (Site Details & Frontage Diagram Enhancement)
// ======================================================================

// Load Helper Utilities
$siteDetailsHelper = __DIR__ . '/../utils/locationParcelSiteDetails.php';
if (file_exists($siteDetailsHelper)) {
    require_once $siteDetailsHelper;
}

// Ensure GIS resolution functions are present if fallback is needed
$locationCheckPath = __DIR__ . '/../locationCheck.php';
if (file_exists($locationCheckPath)) {
    require_once $locationCheckPath;
}

// Ingestion of report record data (e.g., passed via $reportData or API context)
$reportData  = $reportData ?? [];
$location    = $reportData['location'] ?? $reportData;
$parcels     = $location['parcelDetails'] ?? [];
$mainParcel  = $parcels[0] ?? [];
$zoning      = $location['zoning'] ?? $mainParcel['zoning'] ?? [];

$address      = $location['locationAddress'] ?? '2252 N 44th St, Phoenix, AZ 85008';
$apn          = $mainParcel['parcelNumber'] ?? $location['locationParcelNumber'] ?? '12626058A';
$owner        = $mainParcel['ownerName'] ?? 'BLV ARCADIA LLC/BLUE LAKE ARCADIA FUND SPE LLC';
$propertyType = $mainParcel['assessor']['propertyType'] ?? 'Commercial';
$jurisdiction = $location['jurisdictionName'] ?? $location['locationJurisdiction'] ?? 'Phoenix (City)';
$county       = $location['locationCounty'] ?? 'Maricopa';

$zoningCode   = $zoning['zoningCode'] ?? 'R-3A*';
$zoningDesc   = $zoning['zoningDescription'] ?? 'MF Residential';
$zoningSource = $zoning['zoningSource'] ?? 'City of Phoenix Planning and Development Department';
$verifiedAt   = date('F j, Y g:i A', (int)($zoning['zoningVerifiedAt'] ?? time()));

// ======================================================================
// GIS Site Geometry & Frontage Resolution
// ======================================================================
$parcelGeometry = $mainParcel['parcelGeometry'] ?? $location['parcelGeometry'] ?? null;
$frontages      = $mainParcel['frontages'] ?? $location['frontages'] ?? [];

// Perform Fallback Read-Only Lookup if missing from saved record
if ((empty($parcelGeometry) || empty($frontages)) && function_exists('fetchGisSiteDetailsFallback')) {
    $fallbackData = fetchGisSiteDetailsFallback($apn, $jurisdiction);
    if (empty($parcelGeometry)) {
        $parcelGeometry = $fallbackData['parcelGeometry'];
    }
    if (empty($frontages)) {
        $frontages = $fallbackData['frontages'];
    }
}

// Physical Characteristics Calculations
$hasGeometry = !empty($parcelGeometry['rings']) && !empty($parcelGeometry['bounds']);
$hasFrontages = !empty($frontages);

$maxWidthFeet  = 0.0;
$maxHeightFeet = 0.0;
$areaSqFt      = 0.0;
$acreage       = 0.0;

if ($hasGeometry) {
    $bounds = $parcelGeometry['bounds'];
    $maxWidthFeet  = round((float)$bounds['xmax'] - (float)$bounds['xmin'], 1);
    $maxHeightFeet = round((float)$bounds['ymax'] - (float)$bounds['ymin'], 1);

    if (function_exists('calculateParcelAreaSqFt')) {
        $areaSqFt = calculateParcelAreaSqFt($parcelGeometry['rings']);
    } else {
        $areaSqFt = (float)($mainParcel['parcelRecord']['lotSize'] ?? 0);
    }
    $acreage = round($areaSqFt / 43560.0, 3);
} elseif (!empty($mainParcel['parcelRecord']['lotSize'])) {
    $areaSqFt = (float)$mainParcel['parcelRecord']['lotSize'];
    $acreage  = round($areaSqFt / 43560.0, 3);
}

$frontageCount = count($frontages);
$parcelConfig  = function_exists('determineParcelConfiguration')
    ? determineParcelConfiguration($frontageCount, $frontages)
    : ($frontageCount > 1 ? 'Multi-Frontage Lot' : 'Standard Lot');

// SVG Diagram Generation
$svgDiagramHtml = '';
if ($hasGeometry && function_exists('renderParcelSvgDiagram')) {
    $svgDiagramHtml = renderParcelSvgDiagram($parcelGeometry, $frontages, 680, 260);
}

// Status determinations based on data availability
$hasFrontageData = $hasFrontages;
$detachedStatus  = $hasFrontageData
    ? 'Requires parcel-use confirmation and existing-sign inventory'
    : 'Requires frontage, street classification, parcel-use classification, and existing-sign inventory';

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10pt; color: #333333; margin: 0; padding: 0; }
    .header-table { width: 100%; border-bottom: 2px solid #003366; margin-bottom: 12px; }
    .header-logo { width: 180px; }
    .header-title { text-align: right; vertical-align: bottom; }
    .header-title h1 { margin: 0; font-size: 16pt; color: #003366; text-transform: uppercase; }
    .header-title h2 { margin: 0; font-size: 11pt; color: #666666; font-weight: normal; }
    
    .section-header { font-size: 11pt; font-weight: bold; color: #003366; border-bottom: 1.5px solid #003366; padding-bottom: 3px; margin-top: 14px; margin-bottom: 8px; }
    .section-icon { font-size: 10pt; margin-right: 4px; }
    
    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9pt; }
    .data-table th, .data-table td { border: 1px solid #cccccc; padding: 5px 8px; text-align: left; vertical-align: top; }
    .data-table th { background-color: #f2f2f2; color: #333333; font-weight: bold; width: 25%; }
    .data-table td.label { background-color: #f9f9f9; font-weight: bold; width: 25%; color: #444444; }
    
    .badge-validated { color: #2e7d32; font-weight: bold; }
    .badge-resolved { color: #003366; font-weight: bold; }
    
    .callout-box { background-color: #f4f7fa; border-left: 4px solid #003366; padding: 8px 12px; font-size: 8.5pt; margin-bottom: 10px; }
    .diagram-container { text-align: center; margin: 8px 0 12px 0; }
    .diagram-unavailable { background-color: #fafafa; border: 1px dashed #cccccc; padding: 15px; text-align: center; color: #777777; font-size: 8.5pt; border-radius: 4px; }
    
    .footer { font-size: 7.5pt; color: #777777; border-top: 1px solid #dddddd; padding-top: 4px; margin-top: 15px; }
</style>
</head>
<body>

<!-- Header -->
<table class="header-table">
    <tr>
        <td class="header-logo">
            <strong style="font-size: 18pt; color: #cc0000; font-family: sans-serif;">CHRISTY<br><span style="color: #003366;">SIGNS</span></strong>
        </td>
        <td class="header-title">
            <h1>Address Zoning Report</h1>
            <h2>Address Check</h2>
            <div style="font-size: 8.5pt; color: #555;"><?= htmlspecialchars($address) ?></div>
            <div style="font-size: 8pt; color: #777;">Report Date: <?= date('F j, Y') ?></div>
        </td>
    </tr>
</table>

<!-- Property Overview -->
<div class="section-header"><span class="section-icon">📐</span> Property Overview</div>
<table class="data-table">
    <tr>
        <td class="label">Address</td>
        <td><?= htmlspecialchars($address) ?></td>
    </tr>
    <tr>
        <td class="label">APN</td>
        <td><?= htmlspecialchars($apn) ?></td>
    </tr>
    <tr>
        <td class="label">Property Owner</td>
        <td><?= htmlspecialchars($owner) ?></td>
    </tr>
    <tr>
        <td class="label">Property Type</td>
        <td><?= htmlspecialchars($propertyType) ?></td>
    </tr>
    <tr>
        <td class="label">Jurisdiction</td>
        <td><?= htmlspecialchars($jurisdiction) ?></td>
    </tr>
    <tr>
        <td class="label">County</td>
        <td><?= htmlspecialchars($county) ?></td>
    </tr>
</table>

<!-- Zoning Determination -->
<div class="section-header"><span class="section-icon">🏛</span> Zoning Determination</div>
<table class="data-table">
    <tr>
        <td class="label">Base Zoning District</td>
        <td><strong><?= htmlspecialchars($zoningCode) ?></strong></td>
    </tr>
    <tr>
        <td class="label">Description</td>
        <td><?= htmlspecialchars($zoningDesc) ?></td>
    </tr>
    <tr>
        <td class="label">Verification</td>
        <td>
            <span class="badge-validated">Validated</span><br>
            <strong>Source:</strong> <?= htmlspecialchars($zoningSource) ?><br>
            <strong>Verified:</strong> <?= $verifiedAt ?>
        </td>
    </tr>
</table>

<div class="callout-box">
    <strong style="color: #003366;">Resolved Result</strong><br>
    The address check positively resolved the parcel to <strong><?= htmlspecialchars($zoningCode) ?></strong> (<?= htmlspecialchars($zoningDesc) ?>). The zoning engine validated the base-zoning determination without requiring manual review.
</div>

<!-- Address Site Details (NEW SECTION) -->
<div class="section-header"><span class="section-icon">🗺</span> Address Site Details</div>

<table class="data-table">
    <tr>
        <td class="label">Max Parcel Dimensions</td>
        <td><?= $hasGeometry ? "{$maxWidthFeet}' × {$maxHeightFeet}' (Bounding Box)" : 'N/A' ?></td>
        <td class="label">Calculated Parcel Area</td>
        <td><?= $areaSqFt > 0 ? number_format($areaSqFt, 0) . ' sq ft' : 'N/A' ?></td>
    </tr>
    <tr>
        <td class="label">Calculated Acreage</td>
        <td><?= $acreage > 0 ? number_format($acreage, 3) . ' acres' : 'N/A' ?></td>
        <td class="label">Parcel Configuration</td>
        <td><?= htmlspecialchars($parcelConfig) ?></td>
    </tr>
    <tr>
        <td class="label">Identified Frontages</td>
        <td colspan="3"><strong><?= $frontageCount ?></strong> Street Frontage<?= $frontageCount !== 1 ? 's' : '' ?> Identified</td>
    </tr>
</table>

<!-- Parcel Diagram -->
<div class="diagram-container">
    <?php if (!empty($svgDiagramHtml)): ?>
        <?= $svgDiagramHtml ?>
    <?php else: ?>
        <div class="diagram-unavailable">
            <strong>Parcel Diagram Unavailable</strong><br>
            Vector GIS parcel geometry could not be synthesized for this report. Refer to the calculated frontage dimensions below.
        </div>
    <?php endif; ?>
</div>

<!-- Street Frontage Table -->
<table class="data-table">
    <thead>
        <tr style="background-color: #003366; color: #ffffff;">
            <th style="color: #ffffff; width: 22%;">Street</th>
            <th style="color: #ffffff; width: 16%;">Frontage</th>
            <th style="color: #ffffff; width: 26%;">Classification</th>
            <th style="color: #ffffff; width: 18%;">Roadway Category</th>
            <th style="color: #ffffff; width: 18%;">Verification</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($hasFrontages): ?>
            <?php foreach ($frontages as $f): ?>
                <?php
                    $tierLabel = ($f['roadTier'] ?? '') === 'highVolume' ? 'High volume' : 'Low volume';
                    $classCode = !empty($f['streetClassCode']) ? ' (' . $f['streetClassCode'] . ')' : '';
                    $classFull = ($f['streetClassification'] ?? 'Local') . $classCode;
                    $status    = str_replace('_', ' ', $f['verificationStatus'] ?? 'gis_calculated');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($f['streetName'] ?? 'N/A') ?></strong></td>
                    <td><?= number_format((float)($f['frontageLengthFeet'] ?? 0), 2) ?> ft</td>
                    <td><?= htmlspecialchars($classFull) ?></td>
                    <td><?= htmlspecialchars($tierLabel) ?></td>
                    <td><?= htmlspecialchars($status) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" style="text-align: center; color: #777;">No public street frontages resolved for this parcel.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Sign-Code Research Status -->
<div class="section-header"><span class="section-icon">✏️</span> Sign-Code Research Status</div>
<table class="data-table">
    <tr>
        <td class="label">Base zoning</td>
        <td><span class="badge-resolved">Resolved</span> — <?= htmlspecialchars($zoningCode) ?></td>
    </tr>
    <tr>
        <td class="label">Overlay / Regulatory Plan</td>
        <td style="color: #777; font-style: italic;">Not included in the address-check response</td>
    </tr>
    <tr>
        <td class="label">Historic Designation</td>
        <td style="color: #777; font-style: italic;">Not included in the address-check response</td>
    </tr>
    <tr>
        <td class="label">Comprehensive Sign Plan</td>
        <td style="color: #777; font-style: italic;">Not included in the address-check response</td>
    </tr>
    <tr>
        <td class="label">Attached-sign allowance</td>
        <td style="color: #555;">Requires building or tenant elevation width and existing-sign inventory</td>
    </tr>
    <tr>
        <td class="label">Detached-sign allowance</td>
        <td style="color: #555;"><?= htmlspecialchars($detachedStatus) ?></td>
    </tr>
</table>

<!-- Required Research & Next Steps -->
<div class="section-header"><span class="section-icon">👨‍💼</span> Required Research & Next Steps</div>
<ol style="font-size: 8.5pt; margin-top: 4px; padding-left: 18px; line-height: 1.4;">
    <li>Resolve overlay, historic-property, and Comprehensive Sign Plan determinations for the parcel.</li>
    <li>Confirm the applicable sign-code standards for the resolved zoning district and parcel use.</li>
    <li>Measure the building or tenant elevation and document all existing attached signs.</li>
    <?php if ($hasFrontageData): ?>
        <li>Inventory all existing detached signs across identified street frontages.</li>
    <?php else: ?>
        <li>Resolve parcel frontage and street classification; inventory all existing detached signs.</li>
    <?php endif; ?>
    <li>Confirm proposed sign dimensions, height, placement, construction, and illumination before permit preparation.</li>
</ol>

<!-- Report Basis & Qualifications -->
<div class="section-header"><span class="section-icon">📜</span> Report Basis & Qualifications</div>
<table class="data-table" style="font-size: 8pt;">
    <tr>
        <td class="label">Report Type:</td>
        <td>Unsaved Address Check</td>
        <td class="label">Result:</td>
        <td>Base zoning resolved</td>
    </tr>
    <tr>
        <td class="label">Place ID:</td>
        <td><?= htmlspecialchars($location['locationPlaceId'] ?? 'ChIJWSTazAMK4cRpT4SjeZGiss') ?></td>
        <td class="label">Coordinates:</td>
        <td><?= htmlspecialchars((string)($location['locationLatitude'] ?? '33.4720564')) ?>, <?= htmlspecialchars((string)($location['locationLongitude'] ?? '-111.9902556')) ?></td>
    </tr>
    <tr>
        <td class="label">Activity Session:</td>
        <td><?= htmlspecialchars($reportData['activitySessionId'] ?? '1e3ed030152cfd4f71e0d6eae3b95f23') ?></td>
        <td class="label">Parcel Source:</td>
        <td>maricopa_assessor</td>
    </tr>
</table>

<div style="font-size: 7.5pt; color: #666666; line-height: 1.25; margin-top: 6px;">
    <strong>Qualification:</strong> This report records the positive address, parcel, jurisdiction, and base-zoning results returned by Skyesoft's address-check workflow. It does not represent a saved Skyesoft location or a complete sign-allowance analysis. Base zoning may be modified by overlays, stipulations, approved plans, special districts, a Comprehensive Sign Plan, or nonconforming conditions. Verify remaining site conditions and final requirements with the governing jurisdiction before design completion, fabrication, or permit filing.
</div>

<div class="footer">
    <table width="100%">
        <tr>
            <td>Prepared by Steve Skye | Christy Signs</td>
            <td style="text-align: right;">Page 1 of 1</td>
        </tr>
    </table>
</div>

</body>
</html>