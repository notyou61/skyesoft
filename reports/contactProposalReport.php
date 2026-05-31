<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — contactProposalReport.php
//  Version: 1.6.0
//  Last Updated: 2026-05-31
// =============================================

#region SECTION 00 - Main Report Generator

function generateContactProposalReport(array $input): array
{
    $proposal = getProposalData($input);
    
    $bodyHtml = buildContactProposalBody($proposal);
    
    return [
        'reportType'     => 'contact_proposal',
        'reportTitle'    => $proposal['reportTitle'] ?? 'Proposed Contact Report (PC-3)',
        'reportSummary'  => generateSummarySection($proposal),
        'reportBodyHtml' => $bodyHtml,
        'reportArtifacts'=> collectArtifacts($proposal),
        'reportMeta'     => [
            'generated_at' => date('Y-m-d H:i:s'),
            'proposal_id'  => $input['proposalId'] ?? null
        ]
    ];
}

#endregion

#region SECTION 01 - HTML Body Builder

function buildContactProposalBody(array $proposal): string
{
    $html = '';

    $html .= buildEntitySection($proposal);
    $html .= buildContactSection($proposal);
    $html .= buildLocationSection($proposal);
    $html .= insertPageBreak();

    $html .= buildSatelliteSection($proposal);
    $html .= buildParcelSummarySection($proposal);
    $html .= insertPageBreak();

    $html .= buildParcelDetailSection($proposal);
    $html .= insertPageBreak();

    $html .= buildStreetViewSection($proposal);
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
    $html .= '<tr><th>Action</th><td>' . htmlspecialchars(ucfirst($proposal['entityAction'] ?? 'reuse')) . '</td></tr>';
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
    $html .= '<tr><th>Place ID</th><td>' . htmlspecialchars($proposal['locationPlaceId'] ?? '—') . '</td></tr>';
    $html .= '</table>';
    return $html;
}

#endregion

#region SECTION 03 - Visual & Governance Sections

function buildSatelliteSection(array $proposal): string
{
    $html = buildSectionHeader('Location Overview — Satellite Context', 'pin.png');
    $html .= renderImagePlaceholder('satellite', $proposal);
    $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin-top:8px;">';
    $html .= htmlspecialchars($proposal['locationAddress'] ?? '') . ', ';
    $html .= htmlspecialchars($proposal['locationCityStateZip'] ?? '') . ' • Google Satellite View';
    $html .= '</p>';
    return $html;
}

function buildParcelSummarySection(array $proposal): string
{
    $html = buildSectionHeader('Parcel Candidates – Summary', 'compass.png');
    $html .= '<div class="parcelSummaryBlock">';
    $html .= '<strong>Multiple parcel candidates exist at this address.</strong><br><br>';
    $html .= 'Review and selection is required before proceeding.';
    $html .= '</div>';
    return $html;
}

function buildParcelDetailSection(array $proposal): string
{
    $parcels = $proposal['parcelDetails'] ?? [];
    if (empty($parcels)) {
        return buildSectionHeader('Parcel Candidates – Visual Review', 'compass.png') . '<p>No parcel details available.</p>';
    }

    $html = buildSectionHeader('Parcel Candidates – Visual Review', 'compass.png');

    foreach ($parcels as $index => $p) {
        $n = $index + 1;
        $html .= '<div class="parcel-block">';
        $html .= '<div style="display:flex; justify-content:space-between; margin-bottom:8px;">';
        $html .= '<strong>Parcel ' . $n . ' • APN: ' . htmlspecialchars($p['apnDisplay'] ?? $p['apnRaw'] ?? '') . '</strong>';
        $html .= '<span style="background:#e6f0ff; padding:4px 12px; border-radius:5px;">Confidence: ' . ($p['confidence'] ?? 98) . '%</span>';
        $html .= '</div>';
        $html .= '<table class="dataTable">';
        $html .= '<tr><th>Owner</th><td>' . htmlspecialchars($p['owner'] ?? '—') . '</td></tr>';
        $html .= '<tr><th>Address</th><td>' . htmlspecialchars($p['address'] ?? '') . '</td></tr>';
        $html .= '</table>';
        $html .= renderImagePlaceholder('parcel', $proposal, $n);
        $html .= '</div>';
    }
    return $html;
}

function buildStreetViewSection(array $proposal): string
{
    $html = buildSectionHeader('Street View Verification', 'property.png');
    $html .= renderImagePlaceholder('streetview', $proposal);
    $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin-top:8px;">';
    $html .= 'Google Street View • ' . htmlspecialchars($proposal['locationAddress'] ?? '');
    $html .= '</p>';
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

function insertPageBreak(): string
{
    return '<div style="page-break-before: always;"></div>';
}

function renderImagePlaceholder(string $type, array $proposal, int $parcelNum = 0): string
{
    $label = strtoupper($type);
    if ($type === 'parcel') $label .= ' ' . $parcelNum;
    return '<div class="image-placeholder">[ ' . $label . ' IMAGE PLACEHOLDER ]</div>';
}

#endregion

#region SECTION 05 - Summary
function generateSummarySection(array $proposal): string
{
    $narrative = $proposal['governanceNarrative'] ?? 'Proposal ready for review.';
    
    return '
        <div class="summaryNarrative">
            <strong>Operational Overview</strong><br><br>
            ' . nl2br(htmlspecialchars($narrative)) . '
        </div>
        <table class="summaryMetaTable">
            <tr>
                <td><strong>Proposal Code:</strong> ' . htmlspecialchars($proposal['pcCode'] ?? $proposal['pc_code'] ?? 'PC-3') . '</td>
                <td><strong>Confidence:</strong> ' . ($proposal['confidence'] ?? 85) . '%</td>
                <td><strong>Status:</strong> ' . htmlspecialchars($proposal['resolutionStatus'] ?? 'existing_location') . '</td>
                <td><strong>Commit Ready:</strong> <span style="color:#c00;">' . htmlspecialchars($proposal['commitAllowed'] ?? 'NO') . '</span></td>
            </tr>
        </table>';
}
#endregion

#region SECTION 06 - Data Source

function getProposalData(array $input): array
{
    return normalizeProposalData($input);
}

function normalizeProposalData(array $data): array
{
    return [
        'entityName'           => $data['entityName'] ?? 'Unknown Entity',
        'contactName'          => $data['contactName'] ?? 'Susan Alderson',
        'contactTitle'         => $data['contactTitle'] ?? '',
        'contactPhone'         => $data['contactPhone'] ?? '',
        'contactEmail'         => $data['contactEmail'] ?? '',
        'locationAddress'      => $data['locationAddress'] ?? '',
        'locationCityStateZip' => $data['locationCityStateZip'] ?? '',
        'locationPlaceId'      => $data['locationPlaceId'] ?? '',
        'locationCounty'       => $data['locationCounty'] ?? '',
        'governanceNarrative'  => $data['governanceNarrative'] ?? '',
        'confidence'           => $data['confidence'] ?? 85,
        'pcCode'               => $data['pc_code'] ?? $data['pcCode'] ?? 'PC-3',
        'resolutionStatus'     => $data['resolutionStatus'] ?? 'existing_location',
        'commitAllowed'        => $data['commitAllowed'] ?? 'NO',
        'entityAction'         => $data['entityAction'] ?? 'reuse',
        'contactAction'        => $data['contactAction'] ?? 'create',
        'parcelDetails'        => $data['parcelDetails'] ?? []
    ];
}

function collectArtifacts(array $proposal): array
{
    return [
        'satellite'   => null,
        'streetview'  => null,
        'parcel_maps' => []
    ];
}

#endregion