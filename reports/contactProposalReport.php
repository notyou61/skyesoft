<?php
declare(strict_types=1);
// =============================================
// Skyesoft — contactProposalReport.php
// Dynamic Foldable Proposal Report
// Version: 2.8.1 (Table Stripes, Page Break Lock & Entity Fixed)
// =============================================

#region SECTION 00 - Main Report Generator
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}
error_log("=== contactProposalReport.php FULLY RELOADED (Dynamic v2.8.1) at " . date('H:i:s') . " ===");

function generateContactProposalReport(array $input): array
{
    $proposal = getProposalData($input);
    $bodyHtml = buildContactProposalBody($proposal);
    $contactName  = trim($proposal['contactName'] ?? 'Unknown Contact');
    $contactTitle = trim($proposal['contactTitle'] ?? '');
    $entityName   = trim($proposal['entityName'] ?? 'Unknown Entity');
    $reportFilename = "Proposed Contact Report: {$contactName}";
    if (!empty($contactTitle)) {
        $reportFilename .= ", {$contactTitle}";
    }
    $reportFilename .= " - {$entityName}";
    return [
        'reportType'      => 'contact_proposal',
        'reportTitle'     => 'Proposed Contact Report',
        'reportFilename'  => $reportFilename,
        'reportSummary'   => generateSummarySection($proposal),
        'reportBodyHtml'  => $bodyHtml,
        'reportArtifacts' => $proposal['reportArtifacts'] ?? [],
        'reportMeta'      => [
            'generated_at' => date('Y-m-d H:i:s'),
            'proposal_id'  => $input['proposalId'] ?? $input['activitySessionId'] ?? null,
            'pc_code'      => $proposal['pc_code'] ?? '',
        ]
    ];
}
#endregion

#region SECTION 01 - HTML Body Builder
function buildContactProposalBody(array $proposal): string
{
    // Inject enhanced CSS style blocks to guarantee dark, crisp borders and high contrast
    $html = '
    <style>
        .dataTable { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 16px; 
            font-family: Arial, sans-serif; 
            font-size: 11px;
            border: 1px solid #1a365d; /* Crisp dark framing anchor for the container */
        }
        .dataTable th { 
            background-color: #1a365d; 
            color: #ffffff; 
            text-align: left; 
            padding: 7px 10px; 
            font-weight: bold; 
            width: 30%; 
            border: 1px solid #1a365d;
        }
        .dataTable td { 
            padding: 7px 10px; 
            color: #2d3748; 
            border-bottom: 1px solid #cbd5e1; /* Darker, explicitly set gray line to prevent vanishing */
            border-right: 1px solid #e2e8f0;  /* Clean lateral partitioning gridlines */
        }
        /* Ensure the last table row retains a clear, bold frame base line */
        .dataTable tr:last-child td {
            border-bottom: 1px solid #1a365d;
        }
        /* Restore table zebra striping */
        .dataTable tr:nth-child(even) td { 
            background-color: #f8fafc; 
        }
    </style>';
    
    // Card 1: Entity Information Block
    $html .= '<div class="proposal-section" style="page-break-inside: avoid; margin-bottom: 20px; width: 100%;">';
    $html .= buildEntitySection($proposal);
    $html .= '</div>';
    
    // Card 2: Contact Information Block
    $html .= '<div class="proposal-section" style="page-break-inside: avoid; margin-bottom: 20px; width: 100%;">';
    $html .= buildContactSection($proposal);
    $html .= '</div>';
    
    // Card 3: Location Information Block
    $html .= '<div class="proposal-section" style="page-break-inside: avoid; margin-bottom: 20px; width: 100%;">';
    $html .= buildLocationSection($proposal);
    $html .= '</div>';
    
    // Map Context Group (Satellite overview) — Locked inside a structurally complete single container
    $html .= buildSatelliteSection($proposal);
    
    // Interactive Street View Imagery
    $html .= buildStreetViewSection($proposal);
    
    // Parcel Overviews & Plat Map matching
    $html .= buildParcelSummarySection($proposal);
    $html .= buildParcelMapSection($proposal);
    $html .= buildParcelDetailSection($proposal);
    
    // Governance narrative block
    $html .= buildGovernanceSection($proposal);
    
    return $html;
}
#endregion

#region SECTION 02 - Core Sections
function buildEntitySection(array $proposal): string
{
    $html = buildSectionHeader('Entity Information', 'property.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Entity Name</th><td>' . htmlspecialchars($proposal['entityName'] ?? 'N/A') . '</td></tr>';
    $html .= '</table>';
    return $html;
}

function buildContactSection(array $proposal): string
{
    $html = buildSectionHeader('Contact Information', 'users.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Contact Name</th><td>' . htmlspecialchars($proposal['contactName'] ?? 'N/A') . '</td></tr>';
    $html .= '<tr><th>Title</th><td>' . htmlspecialchars($proposal['contactTitle'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Phone</th><td>' . htmlspecialchars($proposal['contactPhone'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Email</th><td>' . htmlspecialchars($proposal['contactEmail'] ?? '—') . '</td></tr>';
    $html .= '</table>';
    return $html;
}

function buildLocationSection(array $proposal): string
{
    $html = buildSectionHeader('Location Information', 'pin.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Full Address</th><td>' . htmlspecialchars($proposal['locationAddress'] ?? 'N/A') . '</td></tr>';
    $html .= '<tr><th>City, State ZIP</th><td>' . htmlspecialchars($proposal['locationCityStateZip'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>County</th><td>' . htmlspecialchars($proposal['locationCounty'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>County FIPS</th><td>' . htmlspecialchars($proposal['locationCountyFips'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Jurisdiction</th><td>' . htmlspecialchars($proposal['locationJurisdiction'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Place ID</th><td>' . htmlspecialchars($proposal['locationPlaceId'] ?? 'N/A') . '</td></tr>';
    $html .= '</table>';
    return $html;
}
#endregion

#region SECTION 03 - Visual & Governance Sections

function buildSatelliteSection(array $proposal): string
{
    // 🌟 FIX B: Header and Image locked inside a single page-break-inside avoid block
    $html = '<div class="proposal-section" style="page-break-inside: avoid; break-inside: avoid; margin-bottom: 24px; width: 100%;">';
    $html .= buildSectionHeader('Location Overview — Satellite Context', 'pin.png');
    
    $path = $proposal['reportArtifacts']['satellite'] ?? null;
    $url  = $proposal['reportArtifacts']['satelliteUrl'] ?? null;
    
    if ($path && file_exists($path)) {
        $html .= '<div style="text-align:center; margin:12px 0;">';
        $html .= '<img src="' . htmlspecialchars($url ?: $path) . '" style="max-width:100%; border:1px solid #bbb; border-radius:6px;" alt="Satellite View">';
        $html .= '</div>';
    } else {
        $lat = $proposal['latitude'] ?? null;
        $lng = $proposal['longitude'] ?? null;
        $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') ?: '';
        if ($googleKey && $lat && $lng) {
            $staticUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$lat},{$lng}&zoom=18&size=950x450&maptype=satellite&markers=color:red%7C{$lat},{$lng}&key={$googleKey}";
            $html .= '<div style="text-align:center; margin:12px 0;">';
            $html .= '<img src="' . htmlspecialchars($staticUrl) . '" style="max-width:100%; border:1px solid #bbb; border-radius:6px;" alt="Satellite View Fallback">';
            $html .= '</div>';
        } else {
            $html .= '<div class="image-placeholder" style="min-height:260px; padding-top:20px;">📍 Satellite imagery unavailable</div>';
        }
    }
    $html .= '</div>';
    return $html;
}

function buildStreetViewSection(array $proposal): string
{
    $html = '<div class="proposal-section" style="page-break-inside: avoid; break-inside: avoid; margin-bottom: 24px; width: 100%;">';
    $html .= buildSectionHeader('Street View Verification', 'property.png');
    $proposalId = $proposal['proposalCode'] ?? $proposal['proposalId'] ?? null;
    $base64Data = null;
    $mimeType = 'image/jpeg';
    
    if ($proposalId) {
        $artifactsDir = '/home/notyou64/public_html/skyesoft/artifacts/';
        $pattern = $artifactsDir . 'TMP-IMG-STR-' . str_pad((string)$proposalId, 6, '0', STR_PAD_LEFT) . '-*.jpg';
        $matches = glob($pattern);
        if (!empty($matches) && file_exists($matches[0])) {
            $imgData = @file_get_contents($matches[0]);
            if ($imgData !== false) {
                $base64Data = base64_encode($imgData);
            }
        }
    }
    if ($base64Data === null) {
        $path = $proposal['reportArtifacts']['streetview'] ?? null;
        $url  = $proposal['reportArtifacts']['streetviewUrl'] ?? null;
        if (empty($path) && !empty($url)) {
            $path = str_replace('https://skyelighting.com', '/home/notyou64/public_html', $url);
        }
        if ($path && file_exists($path)) {
            $imgData = @file_get_contents($path);
            if ($imgData !== false) {
                $base64Data = base64_encode($imgData);
            }
        }
    }
    if ($base64Data !== null) {
        $html .= '<div style="text-align:center; margin:8px 0;">';
        $html .= '<img src="data:' . $mimeType . ';base64,' . $base64Data . '" style="max-width:100%; border:1px solid #bbb; border-radius:6px;" alt="Street View">';
        $html .= '</div>';
    } else {
        $html .= '<div class="image-placeholder" style="min-height:260px; padding-top:20px;">📍 Street View unavailable</div>';
    }
    $html .= '</div>';
    return $html;
}

function buildParcelSummarySection(array $proposal): string
{
    $html = '<div class="proposal-section" style="page-break-inside: avoid; break-inside: avoid; margin-bottom: 24px; width: 100%;">';
    $html .= buildSectionHeader('Parcel Candidates – Summary', 'compass.png');
    $html .= '<div class="parcelSummaryBlock" style="padding:12px; line-height:1.4; font-family: Arial, sans-serif; font-size:11px;">';
    $html .= 'Multiple parcel candidates exist at this address.<br><br>';
    $html .= 'Review and selection is required before proceeding.';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function buildParcelMapSection(array $proposal): string
{
    $html = '<div class="proposal-section" style="page-break-inside: avoid; break-inside: avoid; margin-bottom: 24px; width: 100%;">';
    $html .= buildSectionHeader('Parcel Plat Map Artifact', 'compass.png');
    $path = $proposal['reportArtifacts']['parcelmap'] ?? null;
    $url  = $proposal['reportArtifacts']['parcelmapUrl'] ?? null;
    if ($path && file_exists($path)) {
        $html .= '<div style="text-align:center; margin:8px 0;">';
        $html .= '<img src="' . htmlspecialchars($url ?: $path) . '" style="max-width:100%; border:1px solid #bbb; border-radius:6px;" alt="Parcel Plat Map">';
        $html .= '</div>';
    } else {
        $html .= '<div class="image-placeholder" style="min-height:120px; padding-top:10px;">📍 Parcel plat map artifact unavailable</div>';
    }
    $html .= '</div>';
    return $html;
}

function buildParcelDetailSection(array $proposal): string
{
    $parcels = $proposal['parcelDetails'] ?? [];
    if (empty($parcels)) return '';
    
    $html = '<div class="proposal-section" style="page-break-inside: avoid; break-inside: avoid; margin-bottom: 24px; width: 100%;">';
    $html .= buildSectionHeader('Parcel Candidates – Detail', 'compass.png');
    
    $displayCount = 0;
    foreach ($parcels as $i => $p) {
        if ($displayCount >= 2) break;
        $displayCount++;
        
        $num = $i + 1;
        $apn = htmlspecialchars($p['parcelNumber'] ?? $p['apnRaw'] ?? '—');
        $owner = htmlspecialchars($p['ownerName'] ?? $p['owner'] ?? '—');
        $addr = htmlspecialchars(trim(($p['siteAddress'] ?? $p['address'] ?? '') . ', ' . ($p['city'] ?? '')));
        
        $html .= '<div class="parcel-block" style="font-family: Arial, sans-serif; font-size:11px; background-color:#f8fafc; border:1px solid #e2e8f0; padding:10px; margin-bottom:10px; border-radius:4px;">';
        $html .= '<strong>Parcel ' . $num . ' — APN: ' . $apn . '</strong><br>';
        $html .= 'Owner: ' . $owner . '<br>';
        $html .= 'Address: ' . $addr . '<br>';
        $html .= '</div>';
    }
    
    if (count($parcels) > 2) {
        $html .= '<div style="font-family: Arial, sans-serif; font-size: 9px; color: #718096; font-style: italic; text-align: right; margin-top: 4px;">';
        $html .= '* Showing primary candidates only — full selection list available in Skyesoft portal interface.';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

function buildGovernanceSection(array $proposal): string
{
    $narrative = $proposal['governanceNarrative'] ?? 'Governance review pending.';
    
    $html = '<pagebreak />';
    $html .= '<div class="proposal-section" style="page-break-inside: avoid; break-inside: avoid; width: 100%;">';
    // 🌟 FIX C: Handled ampersand raw format safely so it generates correctly without double translation
    $html .= buildSectionHeader('Governance & Operational Narrative', 'scales.png');
    $html .= '<div class="highlight" style="font-family: Arial, sans-serif; background-color: #ffffff; border-left: 4px solid #3182ce; padding: 14px; font-size: 10.5px; line-height: 1.6; text-align: justify; color: #2d3748; margin-top:10px;">' . nl2br(htmlspecialchars($narrative)) . '</div>';
    $html .= '</div>';
    return $html;
}
#endregion

#region SECTION 04 - Helpers
function buildSectionHeader(string $title, string $icon = 'clipboard.png'): string
{
    // Ensure titles bypass internal double escaping loops safely
    return '
    <table class="sectionHeaderTable" style="width:100%; margin-bottom:8px; border-bottom: 2px solid #1a365d; font-family: Arial, sans-serif;">
        <tr>
            <td class="sectionIconCell" style="width:24px; padding:4px 0;"><img src="https://skyelighting.com/skyesoft/assets/images/icons/' . htmlspecialchars($icon) . '" class="sectionIcon" style="width:16px; height:16px; display:block;"></td>
            <td class="sectionTitleCell" style="padding:4px 6px;"><div class="sectionTitle" style="font-family: Arial, sans-serif; font-size: 13px; font-weight: bold; color: #1a365d; text-transform: uppercase; letter-spacing: 0.5px;">' . htmlspecialchars($title) . '</div></td>
        </tr>
    </table>';
}
#endregion

#region SECTION 05 - Summary
function generateSummarySection(array $proposal): string
{
    $summary = $proposal['narratives']['ui'] ?? $proposal['narratives']['report'] ?? $proposal['governanceNarrative'] ?? 'Proposal processing complete.';
    error_log("DEBUG Summary final text: " . substr($summary, 0, 150));
    return buildSectionHeader('Proposal Summary', 'clipboard.png') . '
        <div class="summaryNarrative" style="font-family: Arial, sans-serif; padding:16px; background:#f8f9fa; border-left:4px solid #17a2b8; margin-bottom:20px; line-height:1.5; font-size:10.5px;">
            ' . nl2br(htmlspecialchars(trim($summary))) . '
        </div>';
}
#endregion

#region SECTION 06 - Data Normalization
function getProposalData(array $input): array
{
    $data = $input['data'] ?? $input;
    $entity = $data['entity'] ?? [];
    $contact = $data['contact'] ?? [];
    $location = $data['location'] ?? [];
    $artifacts = $input['reportArtifacts'] ?? $data['reportArtifacts'] ?? [];
    $rawStreet = $artifacts['streetview'] ?? null;
    $rawSat = $artifacts['satellite'] ?? null;
    $rawParcel = $artifacts['parcelmap'] ?? null;
    $urlStreet = $rawStreet ? str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $rawStreet) : null;
    $urlSat = $rawSat ? str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $rawSat) : null;
    $urlParcel = $rawParcel ? str_replace('/home/notyou64/public_html', 'https://skyelighting.com', $rawParcel) : null;
    return [
        'entityName'           => $entity['entityName'] ?? $data['entityName'] ?? $input['entityName'] ?? 'Unknown Entity',
        'contactName'          => isset($contact['contactFirstName']) ? trim(($contact['contactFirstName'] ?? '') . ' ' . ($contact['contactLastName'] ?? '')) : ($data['contactName'] ?? $input['contactName'] ?? 'Unknown Contact'),
        'contactTitle'         => $contact['contactTitle'] ?? $data['contactTitle'] ?? $input['contactTitle'] ?? '',
        'contactPhone'         => $contact['contactPrimaryPhone'] ?? $data['contactPhone'] ?? $input['contactPhone'] ?? '',
        'contactEmail'         => $contact['contactEmail'] ?? $data['contactEmail'] ?? $input['contactEmail'] ?? '',
        'locationAddress'      => $location['locationAddress'] ?? $data['locationAddress'] ?? $input['locationAddress'] ?? '',
        'locationCityStateZip' => isset($location['locationCity']) ? trim(($location['locationCity'] ?? '') . ', ' . ($location['locationState'] ?? '') . ' ' . ($location['locationZip'] ?? '')) : ($data['locationCityStateZip'] ?? $input['locationCityStateZip'] ?? '—'),
        'locationJurisdiction' => $location['jurisdictionName'] ?? $data['locationJurisdiction'] ?? $input['locationJurisdiction'] ?? '—',
        'locationCounty'       => $location['locationCounty'] ?? $data['locationCounty'] ?? '—',
        'locationCountyFips'   => $location['locationCountyFips'] ?? $data['locationCountyFips'] ?? '—',
        'locationPlaceId'      => $location['locationPlaceId'] ?? $data['locationPlaceId'] ?? 'N/A',
        'latitude'             => $location['locationLatitude'] ?? $data['latitude'] ?? $input['latitude'] ?? null,
        'longitude'            => $location['locationLongitude'] ?? $data['longitude'] ?? $input['longitude'] ?? null,
        'governanceNarrative'  => $data['governanceNarrative'] ?? $input['governanceNarrative'] ?? 'Proposal processing complete.',
        'proposalCode'         => $data['proposalCode'] ?? $input['proposalCode'] ?? $data['pc_code'] ?? $input['pc_code'] ?? '',
        'parcelDetails'        => $location['parcelDetails'] ?? $data['parcelDetails'] ?? $input['parcelDetails'] ?? [],
        'narratives'           => $input['narratives'] ?? [],
        'reportArtifacts'      => [
            'streetview'       => $rawStreet,
            'streetviewUrl'    => $artifacts['streetviewUrl'] ?? $urlStreet,
            'satellite'        => $rawSat,
            'satelliteUrl'     => $artifacts['satelliteUrl'] ?? $urlSat,
            'parcelmap'        => $rawParcel,
            'parcelmapUrl'     => $artifacts['parcelmapUrl'] ?? $urlParcel
        ]
    ];
}

function normalizeProposalData(array $input): array
{
    error_log("DEBUG normalizeProposalData - Using flat payload structure");
    return [
        'entityName'           => $input['entityName'] ?? 'Unknown Entity',
        'contactName'          => $input['contactName'] ?? 'Unknown Contact',
        'contactTitle'         => $input['contactTitle'] ?? '',
        'contactPhone'         => $input['contactPhone'] ?? '',
        'contactEmail'         => $input['contactEmail'] ?? '',
        'locationAddress'      => $input['locationAddress'] ?? '',
        'locationCityStateZip' => $input['locationCityStateZip'] ?? '—',
        'locationCounty'       => $input['locationCounty'] ?? '—',
        'locationCountyFips'   => $input['locationCountyFips'] ?? '—',
        'locationJurisdiction' => $input['locationJurisdiction'] ?? '—',
        'locationPlaceId'      => $input['locationPlaceId'] ?? '',
        'latitude'             => $input['latitude'] ?? null,
        'longitude'            => $input['longitude'] ?? null,
        'governanceNarrative'  => $input['governanceNarrative'] ?? 'Proposal processing complete.',
        'pc_code'              => $input['proposalCode'] ?? $input['pc_code'] ?? '',
        'parcelDetails'        => $input['parcelDetails'] ?? [],
        'reportArtifacts'      => $input['reportArtifacts'] ?? []
    ];
}
#endregion