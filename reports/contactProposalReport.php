<?php
declare(strict_types=1);

// =============================================
// Skyesoft — contactProposalReport.php
// Dynamic Foldable Proposal Report
// Version: 2.0.0
// =============================================

#region SECTION 00 - Main Report Generator

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

error_log("=== contactProposalReport.php FULLY RELOADED (Dynamic v2) at " . date('H:i:s') . " ===");

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
    $html = '';

    // Core Identity Sections
    $html .= buildEntitySection($proposal);
    $html .= buildContactSection($proposal);
    $html .= buildLocationSection($proposal);

    // Visual Sections
    $html .= buildSatelliteSection($proposal);
    $html .= buildStreetViewSection($proposal);

    // Parcel & Governance
    $html .= buildParcelSummarySection($proposal);
    $html .= buildParcelDetailSection($proposal);
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
    $html .= '<tr><th>Jurisdiction</th><td>' . htmlspecialchars($proposal['locationJurisdiction'] ?? 'Maricopa County') . '</td></tr>';
    $html .= '<tr><th>Place ID</th><td>' . htmlspecialchars($proposal['locationPlaceId'] ?? 'N/A') . '</td></tr>';
    $html .= '</table>';
    return $html;
}

#endregion

#region SECTION 03 - Visual & Governance Sections

function buildSatelliteSection(array $proposal): string
{
    $html = '<div class="satellite-group" style="page-break-inside:avoid;">';
    $html .= buildSectionHeader('Location Overview — Satellite Context', 'pin.png');

    $lat = $proposal['latitude'] ?? $proposal['locationLatitude'] ?? null;
    $lng = $proposal['longitude'] ?? $proposal['locationLongitude'] ?? null;

    $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') ?: '';

    if ($googleKey && $lat && $lng) {
        $staticUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$lat},{$lng}&zoom=18&size=950x450&maptype=satellite&markers=color:red%7C{$lat},{$lng}&key={$googleKey}";
        $html .= '<div style="text-align:center; margin:12px 0;">';
        $html .= '<img src="' . htmlspecialchars($staticUrl) . '" style="max-width:100%; border:1px solid #bbb; border-radius:6px;" alt="Satellite View">';
        $html .= '</div>';
    } else {
        $html .= '<div class="image-placeholder" style="min-height:260px;">📍 Satellite imagery unavailable</div>';
    }

    $html .= '</div>';
    return $html;
}

function buildStreetViewSection(array $proposal): string
{
    $html = '<div class="section">';
    $html .= buildSectionHeader('Street View Verification', 'property.png');

    $path = $proposal['reportArtifacts']['streetview'] ?? null;
    if ($path && file_exists($path)) {
        $html .= '<div style="text-align:center; margin:8px 0;">';
        $html .= '<img src="' . htmlspecialchars($path) . '" style="max-width:100%; border:1px solid #bbb; border-radius:6px;" alt="Street View">';
        $html .= '</div>';
    } else {
        $html .= '<div class="image-placeholder" style="min-height:260px;">📍 Street View unavailable</div>';
    }

    $html .= '</div>';
    return $html;
}

function buildParcelSummarySection(array $proposal): string
{
    $html = '<div style="page-break-inside:avoid; margin-top:10px;">';
    $html .= buildSectionHeader('Parcel Candidates – Summary', 'compass.png');
    $html .= '<div class="parcelSummaryBlock">';
    $html .= 'Multiple parcel candidates exist at this address.<br><br>';
    $html .= 'Review and selection is required before proceeding.';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function buildParcelDetailSection(array $proposal): string
{
    $parcels = $proposal['parcelDetails'] ?? [];
    if (empty($parcels)) return '';

    $html = '<div class="section">';
    $html .= buildSectionHeader('Parcel Candidates – Detail', 'compass.png');

    foreach ($parcels as $i => $p) {
        $num = $i + 1;
        $apn = htmlspecialchars($p['apnRaw'] ?? $p['parcelNumber'] ?? '—');
        $owner = htmlspecialchars($p['owner'] ?? '—');
        $addr = htmlspecialchars(trim(($p['address'] ?? '') . ', ' . ($p['city'] ?? '')));

        $html .= '<div class="parcel-block">';
        $html .= '<strong>Parcel ' . $num . ' — APN: ' . $apn . '</strong><br>';
        $html .= 'Owner: ' . $owner . '<br>';
        $html .= 'Address: ' . $addr . '<br><br>';
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

function buildGovernanceSection(array $proposal): string
{
    $narrative = $proposal['governanceNarrative'] ?? 'Governance review pending.';
    $html = buildSectionHeader('Governance &amp; Operational Narrative', 'scales.png');
    $html .= '<div class="highlight">' . nl2br(htmlspecialchars($narrative)) . '</div>';
    return $html;
}

#endregion

#region SECTION 04 - Helpers

function buildSectionHeader(string $title, string $icon = 'clipboard.png'): string
{
    return '
    <table class="sectionHeaderTable">
        <tr>
            <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/' . htmlspecialchars($icon) . '" class="sectionIcon"></td>
            <td class="sectionTitleCell"><div class="sectionTitle">' . htmlspecialchars($title) . '</div></td>
        </tr>
    </table>';
}

#endregion

#region SECTION 05 - Summary

function generateSummarySection(array $proposal): string
{
    $summary = $proposal['governanceNarrative'] 
            ?? $proposal['narratives']['ui'] 
            ?? 'Proposal processing complete.';

    return '<div class="summaryNarrative">' . nl2br(htmlspecialchars($summary)) . '</div>';
}

#endregion

#region SECTION 06 - Data Normalization

function getProposalData(array $input): array
{
    return normalizeProposalData($input);
}

function normalizeProposalData(array $input): array
{

error_log("DEBUG normalizeProposalData - Keys in input: " . json_encode(array_keys($input)));
    // Deep dive into common proposal structures
    $root = $input['data'] ?? $input['proposal'] ?? $input;
    $data = $root['data'] ?? $root;

    $entity   = $data['entity']   ?? $root['entity']   ?? [];
    $contact  = $data['contact']  ?? $root['contact']  ?? [];
    $location = $data['location'] ?? $root['location'] ?? [];
    $pcm      = $input['pcm']     ?? $root['pcm']      ?? [];
    $narr     = $input['narratives'] ?? $root['narratives'] ?? [];

    // Full contact name
    $fullName = trim(implode(' ', array_filter([
        $contact['contactSalutation'] ?? '',
        $contact['contactFirstName']  ?? $contact['firstName'] ?? '',
        $contact['contactLastName']   ?? $contact['lastName'] ?? ''
    ])));

    // City, State ZIP
    $cityStateZip = trim(implode(', ', array_filter([
        $location['locationCity'] ?? $location['city'] ?? '',
        trim(($location['locationState'] ?? $location['state'] ?? '') . ' ' . ($location['locationZip'] ?? $location['zip'] ?? ''))
    ])));

    return [
        'entityName'           => $entity['entityName'] ?? $entity['name'] ?? 'Unknown Entity',

        'contactName'          => $fullName ?: 'Unknown Contact',
        'contactTitle'         => $contact['contactTitle'] ?? $contact['title'] ?? '',
        'contactPhone'         => $contact['contactPrimaryPhone'] ?? $contact['primaryPhone'] ?? '',
        'contactEmail'         => $contact['contactEmail'] ?? $contact['email'] ?? '',

        'locationAddress'      => $location['locationAddress'] ?? $location['address'] ?? '',
        'locationCityStateZip' => $cityStateZip ?: '—',
        'locationCounty'       => $location['locationCounty'] ?? '',
        'locationCountyFips'   => $location['locationCountyFips'] ?? '',
        'locationJurisdiction' => $location['locationJurisdiction'] ?? 'Maricopa County',
        'locationPlaceId'      => $location['locationPlaceId'] ?? '',

        'latitude'             => $location['latitude'] ?? $location['locationLatitude'] ?? null,
        'longitude'            => $location['longitude'] ?? $location['locationLongitude'] ?? null,

        'governanceNarrative'  => $narr['ui'] ?? $narr['report'] ?? 'Proposal processing complete.',
        'pc_code'              => $pcm['pc'] ?? '',
        'parcelDetails'        => $location['parcelDetails'] ?? [],
        'reportArtifacts'      => $input['reportArtifacts'] ?? []
    ];
}

#endregion