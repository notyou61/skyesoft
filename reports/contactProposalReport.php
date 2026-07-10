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
            'pc_code'      => $proposal['proposalCode'] ?? '', // 🌟 FIXED: Tracks against the unified 'proposalCode' key
        ]
    ];
}
#endregion

#region SECTION 01 - HTML Body Builder
function buildContactProposalBody(array $proposal): string
{
    // Inject calibrated mPDF container-wrapped styles
    $html = '
    <style>
        /* 🌟 Wrap container gives mPDF breathing room so outer borders never clip */
        .tableWrapper {
            width: 100%;
            padding: 2px; 
            margin-bottom: 14px;
        }
        .dataTable { 
            width: 100%; 
            border-collapse: collapse; /* 🌟 Merges headers and descriptions smoothly with NO gaps */
            font-family: Arial, sans-serif; 
            font-size: 11px;
            border: 1.5px solid #14377C; /* Updated to unified corporate brand framing wrap */
        }
        .dataTable th { 
            background-color: #14377C; 
            color: #ffffff; 
            text-align: left; 
            padding: 7px 10px; 
            font-weight: bold; 
            width: 32%; 
            border-bottom: 1px solid #ffffff; /* Clean line between header rows */
        }
        .dataTable td { 
            padding: 7px 10px; 
            color: #2d3748; 
            border-bottom: 1.5px solid #14377C; /* Solid color bars between description rows */
            border-left: 1.5px solid #14377C;   /* 🌟 Seals the inner vertical line where blue meets grey/white */
        }
        /* Clean off the bottom edge line of the final rows */
        .dataTable tr:last-child th {
            border-bottom: none;
        }
        .dataTable tr:last-child td {
            border-bottom: none;
        }
        /* Alternating description block backgrounds */
        .dataTable tr:nth-child(even) td { 
            background-color: #f1f5f9; 
        }
        .dataTable tr:nth-child(odd) td { 
            background-color: #ffffff; 
        }
    </style>';
    
    // Card 1: Entity Information Block (Using the new wrapper pattern)
    $html .= '<div class="proposal-section" style="page-break-inside: avoid; margin-bottom: 10px; width: 100%;">';
    $html .= buildEntitySection($proposal);
    $html .= '</div>';
    
    // Card 2: Contact Information Block
    $html .= '<div class="proposal-section" style="page-break-inside: avoid; margin-bottom: 10px; width: 100%;">';
    $html .= buildContactSection($proposal);
    $html .= '</div>';
    
    // Card 3: Location Information Block
    $html .= '<div class="proposal-section" style="page-break-inside: avoid; margin-bottom: 10px; width: 100%;">';
    $html .= buildLocationSection($proposal);
    $html .= '</div>';
    
    // Map Context Group (Satellite overview) — Locked inside a structurally complete single container
    $html .= buildSatelliteSection($proposal);
    
    // Interactive Street View Imagery
    $html .= buildStreetViewSection($proposal);
    
    // Parcel Overviews & Plat Map matching
    //$html .= buildParcelSummarySection($proposal);
    
    // 🌟 FIX: Isolate the Plat Map inside its own clean structural section container block
    $html .= '<div class="parcel-map-block-wrapper" style="width: 100%; margin-bottom: 12px; page-break-inside: avoid; break-inside: avoid;">';
    $html .= buildParcelMapSection($proposal);
    $html .= '</div>';
    
    // 🌟 FIX: Isolate the Detail Table completely into its own distinct, un-nested block container
    $html .= '<div class="parcel-detail-block-wrapper" style="width: 100%; margin-top: 4px; page-break-inside: avoid; break-inside: avoid;">';
    $html .= buildParcelDetailSection($proposal);
    $html .= '</div>';
    
    // Governance narrative block
    $html .= buildGovernanceSection($proposal);
    
    return $html;
}
#endregion

#region SECTION 02 - Core Sections
function buildEntitySection(array $proposal): string
{
    $html = buildSectionHeader('Entity Information', 'property.png');
    $html .= '<div class="tableWrapper">'; // 🌟 Added wrap
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Entity Name</th><td>' . htmlspecialchars($proposal['entityName'] ?? 'N/A') . '</td></tr>';
    $html .= '</table>';
    $html .= '</div>';
    return $html;
}

function buildContactSection(array $proposal): string
{
    $html = buildSectionHeader('Contact Information', 'users.png');
    $html .= '<div class="tableWrapper">'; // 🌟 Added wrap
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Contact Name</th><td>' . htmlspecialchars($proposal['contactName'] ?? 'N/A') . '</td></tr>';
    $html .= '<tr><th>Title</th><td>' . htmlspecialchars($proposal['contactTitle'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Phone</th><td>' . htmlspecialchars($proposal['contactPhone'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Email</th><td>' . htmlspecialchars($proposal['contactEmail'] ?? '—') . '</td></tr>';
    $html .= '</table>';
    $html .= '</div>';
    return $html;
}

function buildLocationSection(array $proposal): string
{
    // Extract Verification details for inline integration
    $placeName    = htmlspecialchars($proposal['reportArtifacts']['place_details']['name'] ?? $proposal['entityName'] ?? 'N/A');
    $placeRating  = htmlspecialchars($proposal['reportArtifacts']['place_details']['rating'] ?? '—');
    $placeReviews = htmlspecialchars((string)($proposal['reportArtifacts']['place_details']['user_ratings_total'] ?? ''));
    $ratingString = !empty($placeReviews) ? "{$placeRating} ★ (Based on {$placeReviews} user reviews)" : "{$placeRating} ★";
    
    $latValue = $proposal['latitude'] ?? '—';
    $lngValue = $proposal['longitude'] ?? '—';
    $placeId  = $proposal['locationPlaceId'] ?? '';
    
    $mapsUrl = !empty($placeId) 
        ? "https://maps.google.com/?q=place_id:" . htmlspecialchars($placeId)
        : "#";

    $html = buildSectionHeader('Location Information', 'pin.png');
    $html .= '<div class="tableWrapper">'; 
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Full Address</th><td>' . htmlspecialchars($proposal['locationAddress'] ?? 'N/A') . '</td></tr>';
    $html .= '<tr><th>City, State ZIP</th><td>' . htmlspecialchars($proposal['locationCityStateZip'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>County</th><td>' . htmlspecialchars($proposal['locationCounty'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>County FIPS</th><td>' . htmlspecialchars($proposal['locationCountyFips'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Jurisdiction</th><td>' . htmlspecialchars($proposal['locationJurisdiction'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Place ID</th><td>' . htmlspecialchars($placeId ?: 'N/A') . '</td></tr>';
    
    // 🌟 INTEGRATED: Google Verification Fields appended cleanly to the matching Location set
    $html .= '<tr><th>Verification Name</th><td>' . $placeName . '</td></tr>';
    $html .= '<tr><th>Google Rating</th><td>' . $ratingString . '</td></tr>';
    $html .= '<tr><th>Coordinates</th><td>Lat: ' . $latValue . ' | Lng: ' . $lngValue . '</td></tr>';
    $html .= '<tr><th>Google Maps Link</th><td><a href="' . $mapsUrl . '" style="color: #1a365d; text-decoration: underline;" target="_blank">View Live Listing</a></td></tr>';
    
    $html .= '</table>';
    $html .= '</div>';
    return $html;
}
#endregion

#region SECTION 03 - Visual & Governance Sections

function buildSatelliteSection(array $proposal): string
{
    // 🌟 Using a structural table block to enforce page-break locks across all mPDF engine variants
    $html = '<table class="section-lock-table" style="width: 100%; border-collapse: collapse; margin: 0; padding: 0; page-break-inside: avoid; break-inside: avoid;">';
    $html .= '<tr><td style="padding: 0; margin: 0; border: none;">';
    
    // Inject Section Header inside the locked cell block
    $html .= buildSectionHeader('Location Overview — Satellite Context', 'pin.png');
    
    $path = $proposal['reportArtifacts']['satellite'] ?? null;
    $url  = $proposal['reportArtifacts']['satelliteUrl'] ?? null;
    
    if ($path && file_exists($path)) {
        $html .= '<div style="text-align: center; margin: 12px 0 4px 0; width: 100%;">';
        $html .= '<img src="' . htmlspecialchars($url ?: $path) . '" width="100%" style="border: 1.5px solid #1a365d; border-radius: 4px;" alt="Satellite View">';
        $html .= '</div>';
    } else {
        $lat = $proposal['latitude'] ?? null;
        $lng = $proposal['longitude'] ?? null;
        $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') ?: '';
        if ($googleKey && $lat && $lng) {
            $staticUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$lat},{$lng}&zoom=18&size=1024x576&maptype=satellite&markers=color:red%7C{$lat},{$lng}&key={$googleKey}";
            $html .= '<div style="text-align: center; margin: 12px 0 4px 0; width: 100%;">';
            $html .= '<img src="' . htmlspecialchars($staticUrl) . '" width="100%" style="border: 1.5px solid #1a365d; border-radius: 4px;" alt="Satellite View Fallback">';
            $html .= '</div>';
        } else {
            $html .= '<div class="image-placeholder" style="min-height:260px; padding-top:20px; font-family: Arial, sans-serif; font-size:11px; text-align:center; color:#718096; background:#f8fafc; border:1px dashed #cbd5e1;">📍 Satellite imagery unavailable</div>';
        }
    }
    
    $html .= '</td></tr>';
    $html .= '</table>';
    
    // Add margin buffer spacing after the locked table element to clean up layout flow
    $html .= '<div style="margin-bottom: 24px; font-size: 1px; line-height: 1px;">&nbsp;</div>';
    
    return $html;
}
function buildStreetViewSection(array $proposal): string
{
    // 🌟 RESET: Dropped structural tables completely. Using modern block containers to match your UX design.
    $html = '<div class="proposal-section" style="width: 100%; margin-bottom: 14px; page-break-inside: avoid; break-inside: avoid; display: block; clear: both;">';
    
    // Inject Section Header cleanly into the standard document flow
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
    
    // RENDER IMAGE WITH SAME REAL-ESTATE AS THE SATELLITE CARD
    if ($base64Data !== null) {
        $html .= '<div style="text-align: center; margin: 10px 0 4px 0; width: 100%; clear: both; display: block;">';
        $html .= '<img src="data:' . $mimeType . ';base64,' . $base64Data . '" width="100%" style="border: 1.5px solid #14377C; border-radius: 4px; display: block;" alt="Street View">';
        $html .= '</div>';
    } else {
        $html .= '<div class="image-placeholder" style="min-height:260px; padding-top:20px; font-family: Arial, sans-serif; font-size:11px; text-align:center; color:#718096; background:#f8fafc; border:1px dashed #cbd5e1; margin-top: 10px; clear: both;">📍 Street View unavailable</div>';
    }
    
    $html .= '</div>'; // Safely closes the standalone structural container
    
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
    // 🌟 Using a structural table block to enforce page-break locks and match the Page 2 layout perfectly
    $html = '<table class="section-lock-table" style="width: 100%; border-collapse: collapse; margin: 0; padding: 0; page-break-inside: avoid; break-inside: avoid;">';
    $html .= '<tr><td style="padding: 0; margin: 0; border: none;">';

    $html .= buildSectionHeader('Parcel Plat Map Artifact', 'compass.png');
    
    $path = $proposal['reportArtifacts']['parcelmap'] ?? null;
    $url  = $proposal['reportArtifacts']['parcelmapUrl'] ?? null;
    
    if ($path && file_exists($path)) {
        // 🌟 MATCHING LAYOUT REAL-ESTATE: Force 100% width and brand-matching border
        $html .= '<div style="text-align: center; margin: 12px 0 4px 0; width: 100%;">';
        $html .= '<img src="' . htmlspecialchars($url ?: $path) . '" width="100%" style="border: 1.5px solid #1a365d; border-radius: 4px;" alt="Parcel Plat Map">';
        $html .= '</div>';
    } else {
        $html .= '<div class="image-placeholder" style="min-height:260px; padding-top:20px; font-family: Arial, sans-serif; font-size:11px; text-align:center; color:#718096; background:#f8fafc; border:1px dashed #cbd5e1;">📍 Parcel plat map artifact unavailable</div>';
    }
    
    $html .= '</td></tr>';
    $html .= '</table>';
    
    // Smooth trail margin out to the next block baseline bounds
    $html .= '<div style="margin-bottom: 24px; font-size: 1px; line-height: 1px;">&nbsp;</div>';
    
    return $html;
}

function buildParcelDetailSection(array $proposal): string
{
    $parcels = $proposal['parcelDetails'] ?? [];
    if (empty($parcels)) {
        return '';
    }

    $html = '<div class="detail-section-container" style="width: 100%; display: block; clear: both;">';
    
    // 🌟 FIXED: Reverted to local resource string filename to resolve the missing icon
    $html .= buildSectionHeader('Parcel Candidates – Detail', 'folder.png');
    
    foreach ($parcels as $index => $p) {
        $num       = $index + 1;
        $apn       = htmlspecialchars($p['parcelNumber'] ?? $p['apnRaw'] ?? '—');
        $owner     = htmlspecialchars($p['ownerName'] ?? $p['owner'] ?? '—');
        $addr      = htmlspecialchars(trim(($p['siteAddress'] ?? $p['address'] ?? '') . ', ' . ($p['city'] ?? '')));
        $source    = htmlspecialchars($p['source'] ?? 'Inferred');
        $jurisdict = htmlspecialchars($p['jurisdiction'] ?? 'Phoenix');
        
        $addr = preg_replace('/\s+/', ' ', $addr);
        
        $cleanApnForUrl = str_replace('-', '', $apn);
        $assessorUrl    = "https://mcassessor.maricopa.gov/mcs.php?q=" . urlencode($cleanApnForUrl);

        $html .= '<table class="dataTable" style="width: 100%; margin-top: 6px; margin-bottom: 14px; border-collapse: collapse; page-break-inside: avoid; break-inside: avoid;">';
        $html .= '<thead>
                    <tr>
                        <th colspan="2" style="background-color: #14377C; color: #ffffff; text-align: left; padding: 7px 10px; font-weight: bold;">
                            Candidate #' . $num . ' — Assessor Parcel Record
                        </th>
                    </tr>
                 </thead>';
        $html .= '<tbody>
                    <tr>
                        <th style="background-color: #e8e8e8; color: #333333; text-align: left; padding: 7px 10px; font-weight: bold; width: 32%;">Assessor Parcel Number (APN)</th>
                        <td style="padding: 7px 10px; color: #2d3748; font-weight: bold;">' . $apn . '</td>
                    </tr>
                    <tr>
                        <th style="background-color: #e8e8e8; color: #333333; text-align: left; padding: 7px 10px; font-weight: bold;">Registered Owner</th>
                        <td style="padding: 7px 10px; color: #2d3748;">' . $owner . '</td>
                    </tr>
                    <tr>
                        <th style="background-color: #e8e8e8; color: #333333; text-align: left; padding: 7px 10px; font-weight: bold;">Site Boundary Address</th>
                        <td style="padding: 7px 10px; color: #2d3748;">' . $addr . '</td>
                    </tr>
                    <tr>
                        <th style="background-color: #e8e8e8; color: #333333; text-align: left; padding: 7px 10px; font-weight: bold;">Tax/Permit Jurisdiction</th>
                        <td style="padding: 7px 10px; color: #2d3748;">' . $jurisdict . '</td>
                    </tr>
                    <tr>
                        <th style="background-color: #e8e8e8; color: #333333; text-align: left; padding: 7px 10px; font-weight: bold;">GIS Mapping Source</th>
                        <td style="padding: 7px 10px; color: #718096; font-style: italic;">' . $source . '</td>
                    </tr>
                    <tr>
                        <th style="background-color: #e8e8e8; color: #333333; text-align: left; padding: 7px 10px; font-weight: bold;">Assessor Portal Link</th>
                        <td style="padding: 7px 10px; color: #2d3748;">
                            <a href="' . $assessorUrl . '" style="color: #14377C; text-decoration: underline; font-weight: bold;" target="_blank">View Live Parcel Map</a>
                        </td>
                    </tr>
                 </tbody>';
        $html .= '</table>';
    }
    
    $html .= '</div>';
    return $html;
}

function buildGovernanceSection(array $proposal): string
{
    $narrative = $proposal['governanceNarrative'] ?? 'Governance review pending.';
    
    $html = '<div class="proposal-section" style="page-break-inside: avoid; break-inside: avoid; width: 100%; margin-top: 14px; display: block; clear: both;">';
    
    // 🌟 FIXED: Passed clear literal ampersand title to prevent double-escaping glitches
    $html .= buildSectionHeader('Governance & Operational Narrative', 'scales.png');
    
    $html .= '<div class="highlight" style="font-family: Arial, sans-serif; background-color: #ffffff; border-left: 4px solid #14377C; padding: 14px; font-size: 10.5px; line-height: 1.6; text-align: justify; color: #2d3748; margin-top: 10px;">' . nl2br(htmlspecialchars($narrative)) . '</div>';
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
    // 1. 🛡️ Target the precise theme array context source cleanly
    $themeSource = $proposal['theme'] 
    ?? $proposal['narratives']['theme'] 
    ?? $proposal['ui']['theme'] 
    ?? $proposal;
    
    // Normalize keys to lowercase to completely eliminate case-sensitivity bugs dynamically
    $theme = [];
    if (is_array($themeSource)) {
        foreach ($themeSource as $key => $value) {
            $theme[strtolower($key)] = $value;
        }
    }

    // 2. Extract the Summary Narrative text block
    $summary = 'Proposal evaluation sequence completed.';
    if (!empty($proposal['narratives'])) {
        $narratives = $proposal['narratives'];
        $summary = is_array($narratives) 
            ? ($narratives['ui'] ?? $narratives['report'] ?? ($narratives[0] ?? $summary))
            : $narratives;
    } else {
        $summary = $proposal['governanceNarrative'] ?? $proposal['summary'] ?? $summary;
    }
    
    // 3. Render Component using dynamic, pass-through lookups with strict fallback protections
    $html = '<div class="page-content-wrapper" style="padding-top: 10px; position: relative; clear: both;">';
    $html .= buildSectionHeader('Proposal Summary', 'clipboard.png');
    
    $html .= '
    <div class="summaryNarrative" style="font-family: Arial, sans-serif; padding: 18px; background: ' . ($theme['bglight'] ?? '#f8f9fa') . '; border-left: 5px solid ' . ($theme['bgcolor'] ?? '#6c757d') . '; border-top: 1px solid ' . ($theme['bordercolor'] ?? '#dee2e6') . '; border-right: 1px solid ' . ($theme['bordercolor'] ?? '#dee2e6') . '; border-bottom: 1px solid ' . ($theme['bordercolor'] ?? '#dee2e6') . '; border-radius: 6px; margin-bottom: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); position: relative;">
        <table style="width: 100%; border-collapse: collapse; margin: 0; padding: 0;">
            <tr>
                <td style="vertical-align: middle; padding: 0; text-align: left; color: #212529; font-size: 11px; line-height: 1.6; font-weight: 500;">
                    ' . nl2br(htmlspecialchars(trim($summary))) . '
                </td>
                <td style="vertical-align: middle; padding: 0 0 0 20px; text-align: right; width: 160px;">
                    <div style="display: inline-block; background-color: ' . ($theme['bglight'] ?? '#f8f9fa') . '; color: ' . ($theme['textcolor'] ?? '#495057') . '; border: 1px solid ' . ($theme['bordercolor'] ?? '#dee2e6') . '; font-family: Arial, sans-serif; font-size: 9.5px; font-weight: 700; padding: 6px 14px; border-radius: 4px; text-align: center; white-space: nowrap; letter-spacing: 0.7px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                        ● ' . htmlspecialchars($theme['badgetext'] ?? 'REVIEW STATE') . '
                    </div>
                </td>
            </tr>
        </table>
    </div>';
    $html .= '</div>';
    
    return $html;
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
    
    // ... Keep any existing local variable asset resolution logic here unchanged ($rawStreet, $urlStreet, etc.) ...

    // 🌟 ENHANCED THEME EXTRACTION - covers all injection paths from processProposedContact
    $theme = $input['theme'] 
        ?? $data['theme'] 
        ?? $input['narratives']['theme'] 
        ?? $data['narratives']['theme'] 
        ?? $input['ui']['theme'] 
        ?? [];

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
        'narratives'           => $input['narratives'] ?? $data['narratives'] ?? [],
        'theme'                => $theme,  // ← CRITICAL: Explicit top-level theme payload anchor
        
        // System Status
        'status'               => $input['status'] ?? $data['status'] ?? 'proposed',
        'ui'                   => $input['ui'] ?? $data['ui'] ?? [],
        'proposalStatus'       => $input['ui']['proposalStatus'] ?? $data['ui']['proposalStatus'] ?? 'existing',
        
        'reportArtifacts'      => [
            'streetview'    => $rawStreet ?? '',
            'streetviewUrl' => $artifacts['streetviewUrl'] ?? $urlStreet ?? '',
            'satellite'     => $rawSat ?? '',
            'satelliteUrl'  => $artifacts['satelliteUrl'] ?? $urlSat ?? '',
            'parcelmap'     => $rawParcel ?? '',
            'parcelmapUrl'  => $artifacts['parcelmapUrl'] ?? $urlParcel ?? ''
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
        
        // 🌟 Pass-through fields kept intact here as well
        'status'               => $input['status'] ?? 'proposed',
        'ui'                   => $input['ui'] ?? [],
        'proposalStatus'       => $input['ui']['proposalStatus'] ?? $input['proposalStatus'] ?? 'existing',
        
        'reportArtifacts'      => $input['reportArtifacts'] ?? []
    ];
}
#endregion