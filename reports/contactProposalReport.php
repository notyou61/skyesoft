<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — contactProposalReport.php
//  Version: 1.4.0
//  Last Updated: 2026-05-30
// =============================================

#region SECTION 00 - Main Report Generator

/**
 * Main entry point for generating a Contact Proposal Report.
 * Called by generateReports.php via the report registry.
 */
function generateContactProposalReport(array $input): array
{
    $proposal = getProposalData($input);
    
    $bodyHtml = buildContactProposalBody($proposal);
    
    return [
        'reportType'     => 'contact_proposal',
        'reportTitle'    => 'Proposed Contact Report - ' . ($proposal['entityName'] ?? $proposal['entity_name'] ?? 'Unknown'),
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

/**
 * Build full HTML body by assembling modular sections.
 * Structured to mirror the page flow from test_mpdf.php.
 */
function buildContactProposalBody(array $proposal): string
{
    $html = '';

    // Page 2: Core Entity, Contact, Location
    $html .= buildEntitySection($proposal);
    $html .= buildContactSection($proposal);
    $html .= buildLocationSection($proposal);
    $html .= insertPageBreak();

    // Page 3: Visual Context
    $html .= buildSatelliteSection($proposal);
    $html .= buildParcelSummarySection($proposal);
    $html .= insertPageBreak();

    // Page 4+: Detailed Parcels
    $html .= buildParcelDetailSection($proposal);
    $html .= insertPageBreak();

    // Final Page: Street View + Governance
    $html .= buildStreetViewSection($proposal);
    $html .= buildGovernanceSection($proposal);

    return $html;
}

#endregion

#region SECTION 01A - Entity Information

function buildEntitySection(array $proposal): string
{
    $entityName = $proposal['entityName'] ?? $proposal['entity_name'] ?? 'N/A';
    $entityAction = $proposal['entityAction'] ?? 'reuse';

    $html = buildSectionHeader('Entity Information', 'property.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Entity Name</th><td>' . htmlspecialchars($entityName) . '</td></tr>';
    $html .= '<tr><th>Action</th><td>' . htmlspecialchars(ucfirst($entityAction)) . '</td></tr>';
    $html .= '</table>';
    return $html;
}

#endregion

#region SECTION 01B - Contact Information

function buildContactSection(array $proposal): string
{
    $html = buildSectionHeader('Contact Information', 'users.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Contact Name</th><td>' . htmlspecialchars($proposal['contactName'] ?? $proposal['contact_name'] ?? 'N/A') . '</td></tr>';
    $html .= '<tr><th>Title</th><td>' . htmlspecialchars($proposal['contactTitle'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Phone</th><td>' . htmlspecialchars($proposal['contactPhone'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Email</th><td>' . htmlspecialchars($proposal['contactEmail'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Action</th><td>' . htmlspecialchars(ucfirst($proposal['contactAction'] ?? 'create')) . '</td></tr>';
    $html .= '</table>';
    return $html;
}

#endregion

#region SECTION 01C - Location Information

function buildLocationSection(array $proposal): string
{
    $html = buildSectionHeader('Location Information', 'pin.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Full Address</th><td>' . htmlspecialchars($proposal['locationAddress'] ?? $proposal['address'] ?? 'N/A') . '</td></tr>';
    $html .= '<tr><th>City, State ZIP</th><td>' . htmlspecialchars($proposal['locationCityStateZip'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>County</th><td>' . htmlspecialchars($proposal['locationCounty'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>County FIPS</th><td>' . htmlspecialchars($proposal['locationCountyFips'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Jurisdiction</th><td>' . htmlspecialchars($proposal['locationJurisdiction'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Place ID</th><td>' . htmlspecialchars($proposal['locationPlaceId'] ?? '—') . '</td></tr>';
    $html .= '</table>';
    return $html;
}

#endregion

#region SECTION 01D - Satellite Context

function buildSatelliteSection(array $proposal): string
{
    $html = buildSectionHeader('Location Overview — Satellite Context', 'pin.png');
    $html .= renderImagePlaceholder('satellite', $proposal);
    $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin-top:8px;">';
    $html .= htmlspecialchars($proposal['locationAddress'] ?? $proposal['address'] ?? '') . ', ';
    $html .= htmlspecialchars($proposal['locationCityStateZip'] ?? '') . ' • Google Satellite View';
    $html .= '</p>';
    return $html;
}

#endregion

#region SECTION 01E - Parcel Candidate Summary

function buildParcelSummarySection(array $proposal): string
{
    $parcelSummary = $proposal['parcelSummaryNarrative'] ?? 
                    'Multiple parcel candidates were identified at this address. Review and selection is required before proceeding.';
    
    $html = buildSectionHeader('Parcel Candidates – Summary', 'compass.png');
    $html .= '<div class="parcelSummaryBlock">';
    $html .= nl2br(htmlspecialchars($parcelSummary));
    $html .= '</div>';
    return $html;
}

#endregion

#region SECTION 01F - Parcel Detail Pages

function buildParcelDetailSection(array $proposal): string
{
    $parcelDetails = $proposal['parcelDetails'] ?? $proposal['location']['parcelDetails'] ?? [];
    if (empty($parcelDetails)) {
        return buildSectionHeader('Parcel Details', 'compass.png') . '<p>No parcel details available.</p>';
    }

    $html = buildSectionHeader('Parcel Candidates – Visual Review', 'compass.png');

    foreach ($parcelDetails as $index => $parcel) {
        $parcelNum = $index + 1;
        $html .= '<div class="parcel-block">';
        
        $html .= '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">';
        $html .= '<div>';
        $html .= '<span style="font-size:14pt; font-weight:700; color:#14377C;">Parcel ' . $parcelNum . '</span>';
        $html .= '<span style="font-size:12.5pt; font-weight:600; margin-left:12px;">APN: ' . htmlspecialchars($parcel['apnDisplay'] ?? $parcel['apnRaw'] ?? '—') . '</span>';
        $html .= '</div>';
        $html .= '<div style="background:#e6f0ff; color:#14377C; padding:4px 14px; border-radius:5px; font-size:10pt; font-weight:600;">';
        $html .= 'Confidence: ' . ($parcel['confidence'] ?? 98) . '%';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<table class="dataTable" style="margin-bottom:10px;">';
        $html .= '<tr><th style="width:20%;">Owner</th><td>' . htmlspecialchars($parcel['owner'] ?? '—') . '</td></tr>';
        $html .= '<tr><th>Address</th><td>' . htmlspecialchars($parcel['address'] ?? '') . ', ' . htmlspecialchars($parcel['city'] ?? '') . '</td></tr>';
        $html .= '</table>';

        $html .= renderImagePlaceholder('parcel', $proposal, $parcelNum);

        $html .= '</div>';
    }

    return $html;
}

#endregion

#region SECTION 01G - Street View Verification

function buildStreetViewSection(array $proposal): string
{
    $html = buildSectionHeader('Street View Verification', 'property.png');
    $html .= renderImagePlaceholder('streetview', $proposal);
    $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin-top:8px;">';
    $html .= 'Google Street View • ' . htmlspecialchars($proposal['locationAddress'] ?? $proposal['address'] ?? '');
    $html .= '</p>';
    return $html;
}

#endregion

#region SECTION 01H - Governance Narrative

function buildGovernanceSection(array $proposal): string
{
    $narrative = $proposal['governanceNarrative'] ?? 
                'This proposal references an existing operational location. Review and selection of the correct parcel is required before committing.';

    $html = buildSectionHeader('Governance &amp; Operational Narrative', 'scales.png');
    $html .= '<div class="highlight">';
    $html .= nl2br(htmlspecialchars($narrative));
    $html .= '</div>';
    return $html;
}

#endregion

#region SECTION 02 - Section Header Helper

/**
 * Build consistent section header with icon (matches test_mpdf.php).
 */
function buildSectionHeader(string $title, string $icon = 'clipboard.png'): string
{
    return '
    <table class="sectionHeaderTable">
        <tr>
            <td class="sectionIconCell">
                <img src="https://skyelighting.com/skyesoft/assets/images/icons/' . htmlspecialchars($icon) . '" 
                     class="sectionIcon" alt="' . htmlspecialchars($title) . '">
            </td>
            <td class="sectionTitleCell">
                <div class="sectionTitle">' . htmlspecialchars($title) . '</div>
            </td>
        </tr>
    </table>';
}

#endregion

#region SECTION 03 - Helpers

function insertPageBreak(): string
{
    return '<div style="page-break-before: always;"></div>';
}

/**
 * Render image placeholders compatible with baseReport.php processor.
 */
function renderImagePlaceholder(string $type, array $proposal, int $parcelNum = 0): string
{
    $label = strtoupper($type);
    if ($type === 'parcel') {
        $label .= ' ' . $parcelNum;
    }
    
    return '
    <div style="text-align:center; padding:15px; border:2px solid #14377C; border-radius:8px; background:#f8f9fa; margin:12px 0; min-height:260px; display:flex; align-items:center; justify-content:center;">
        [ ' . $label . ' IMAGE PLACEHOLDER ]
    </div>';
}

#endregion

#region SECTION 04 - Summary Section

/**
 * Generate rich summary section matching test_mpdf.php structure.
 */
function generateSummarySection(array $proposal): string
{
    $narrative = $proposal['reportSummaryNarrative'] ?? $proposal['ai_narrative'] ?? 'No narrative available.';
    $confidence = $proposal['confidence'] ?? 85;
    $pcCode = $proposal['pcCode'] ?? 'PC-3';
    $resolutionStatus = $proposal['resolutionStatus'] ?? 'multiple_parcels';
    $commitAllowed = $proposal['commitAllowed'] ?? 'NO';

    return '
        <div class="summaryNarrative">
            ' . nl2br(htmlspecialchars($narrative)) . '
        </div>
        <table class="summaryMetaTable">
            <tr>
                <td><strong>Proposal Code:</strong> ' . htmlspecialchars($pcCode) . '</td>
                <td><strong>Confidence:</strong> ' . $confidence . '%</td>
                <td><strong>Status:</strong> ' . htmlspecialchars($resolutionStatus) . '</td>
                <td><strong>Commit Ready:</strong> <span style="color:#c00;">' . htmlspecialchars($commitAllowed) . '</span></td>
            </tr>
        </table>
    ';
}

#endregion

#region SECTION 05 - Artifacts Collection

/**
 * Collect report artifacts for the base renderer.
 */
function collectArtifacts(array $proposal): array
{
    return [
        'satellite'     => $proposal['satelliteImage'] ?? $proposal['satellite_image'] ?? null,
        'streetview'    => $proposal['streetViewImage'] ?? $proposal['street_view'] ?? null,
        'parcel_maps'   => $proposal['parcelImages'] ?? $proposal['parcel_maps'] ?? [],
        'proposal_json' => $proposal['sourceJsonPath'] ?? null
    ];
}

#endregion

#region SECTION 06 - Proposal Data Source

/**
 * Returns proposal data for report generation.
 * Handles both test data and real incoming JSON payload.
 */
function getProposalData(array $input): array
{
    // If real proposal data is passed from frontend, use it
    if (!empty($input['proposal']) && is_array($input['proposal'])) {
        return $input['proposal'];
    }

    // Direct JSON payload support (what you're sending now)
    if (!empty($input['entityName']) || !empty($input['reportType'])) {
        return normalizeProposalData($input);
    }

    // Fallback to test data
    return getTestProposalData();
}

/**
 * Normalizes incoming JSON payload to match internal structure.
 */
function normalizeProposalData(array $data): array
{
    return [
        'entityName'           => $data['entityName'] ?? 'Unknown Entity',
        'contactName'          => $data['contactName'] ?? '',
        'contactTitle'         => $data['contactTitle'] ?? '',
        'contactPhone'         => $data['contactPhone'] ?? '',
        'contactEmail'         => $data['contactEmail'] ?? '',
        'locationAddress'      => $data['locationAddress'] ?? '',
        'locationCityStateZip' => $data['locationCityStateZip'] ?? '',
        'locationPlaceId'      => $data['locationPlaceId'] ?? '',
        'locationCounty'       => $data['locationCounty'] ?? '',
        'governanceNarrative'  => $data['governanceNarrative'] ?? '',
        'confidence'           => $data['confidence'] ?? 75,
        'pcCode'               => $data['pc_code'] ?? $data['pcCode'] ?? 'PC-3',
        'resolutionStatus'     => $data['resolutionStatus'] ?? 'existing_location',
        'commitAllowed'        => $data['commitAllowed'] ?? 'NO',
        'entityAction'         => $data['entityAction'] ?? 'reuse',
        'contactAction'        => $data['contactAction'] ?? 'create',
        'reportSummaryNarrative' => $data['reportSummaryNarrative'] ?? 'Proposal ready for review.',
        'parcelSummaryNarrative' => $data['parcelSummaryNarrative'] ?? 'Parcel information reviewed.',
        'parcelDetails'        => $data['parcelDetails'] ?? []
    ];
}

/**
 * Rich mock data for testing when no real data is provided.
 */
function getTestProposalData(): array
{
    return normalizeProposalData([
        'entityName' => 'Christy Signs',
        'contactName' => 'Ms. Susan Alderson',
        'contactTitle' => 'Accounting Manager',
        'contactPhone' => '(602) 242-4488',
        'contactEmail' => 'susan@christysigns.com',
        'locationAddress' => '3145 N 33rd Ave',
        'locationCityStateZip' => 'Phoenix, AZ 85017',
        'pc_code' => 'PC-3'
    ]);
}

#endregion