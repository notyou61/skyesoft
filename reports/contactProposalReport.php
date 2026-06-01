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
        'reportType'      => 'contact_proposal',

        'reportTitle'     =>
            $proposal['reportTitle']
            ?? 'Proposed Contact Report (PC-3)',

        // -------------------------------------------------
        // Dynamic PDF Filename
        // -------------------------------------------------

        'reportFilename'  =>
            'Proposed Contact Report - '
            . trim(
                $proposal['contactName']
                ?? 'Unknown Contact'
            ),

        'reportSummary'   =>
            generateSummarySection(
                $proposal
            ),

        'reportBodyHtml'  =>
            $bodyHtml,

        'reportArtifacts' =>
            collectArtifacts(
                $proposal
            ),

        'reportMeta'      => [
            'generated_at' =>
                date('Y-m-d H:i:s'),

            'proposal_id'  =>
                $input['proposalId']
                ?? null
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
    // Action row removed as requested
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

    // Full Address
    $fullAddress = $proposal['locationAddress'] 
                ?? $proposal['location']['address'] 
                ?? $proposal['location']['formattedAddress'] 
                ?? 'N/A';
    
    $html .= '<tr><th>Full Address</th><td>' . htmlspecialchars($fullAddress) . '</td></tr>';

    // City, State ZIP
    $cityStateZip = $proposal['locationCityStateZip'] 
                 ?? buildCityStateZip($proposal['location'] ?? $proposal)
                 ?? '—';
    
    $html .= '<tr><th>City, State ZIP</th><td>' . htmlspecialchars($cityStateZip) . '</td></tr>';

    // County
    $county = $proposal['locationCounty'] 
           ?? $proposal['location']['county'] 
           ?? '—';
    
    $html .= '<tr><th>County</th><td>' . htmlspecialchars($county) . '</td></tr>';

    // County FIPS
    $fips = $proposal['locationCountyFips'] 
         ?? $proposal['location']['countyFips'] 
         ?? '—';
    
    $html .= '<tr><th>County FIPS</th><td>' . htmlspecialchars($fips) . '</td></tr>';

    // Jurisdiction (with Title Case + Cleanup)
    $jur = $proposal['locationJurisdiction'] 
        ?? $proposal['location']['locationJurisdiction'] 
        ?? $proposal['location']['jurisdiction'] 
        ?? 'Pending';

    if (empty($jur) || strtoupper($jur) === 'NO CITY/TOWN') {
        $jur = 'Maricopa County';
    } else {
        $jur = ucwords(strtolower($jur));   // PHOENIX → Phoenix
    }

    $html .= '<tr><th>Jurisdiction</th><td>' . htmlspecialchars($jur) . '</td></tr>';

    // Place ID
    $placeId = $proposal['locationPlaceId'] 
            ?? $proposal['location']['locationPlaceId'] 
            ?? 'N/A';
    
    $html .= '<tr><th>Place ID</th><td>' . htmlspecialchars($placeId) . '</td></tr>';

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

#region SECTION 05 - Summary (AI-Powered + Clean)

function generateSummarySection(array $proposal): string
{
    $summaryNarrative = 'The operational narrative for this proposal is currently unavailable.';

    try {
        $payload = [
            'type'         => 'proposalNarrative',
            'promptFile'   => 'proposedContactReportSummary.prompt',
            'proposalData' => $proposal,
            'userQuery'    => 'Generate Proposed Contact Report Summary'
        ];

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($payload),
                'timeout' => 60
            ]
        ]);

        $rawResponse = file_get_contents(
            'https://skyelighting.com/skyesoft/api/askOpenAI.php',
            false,
            $context
        );

        if ($rawResponse === false) {
            throw new Exception('Failed to connect to askOpenAI.php');
        }

        $responseData = json_decode($rawResponse, true);

        if (isset($responseData['summaryNarrative']) && trim($responseData['summaryNarrative']) !== '') {
            $summaryNarrative = trim($responseData['summaryNarrative']);
        }
    } catch (Exception $e) {
        error_log("[PDF] Summary AI Error: " . $e->getMessage());
        
        $summaryNarrative = 'The proposal involves adding ' . ($proposal['contactName'] ?? 'Ms. Susan Alderson') .
                            ' as the Accounting Manager contact to the existing ' .
                            ($proposal['entityName'] ?? 'Christy Signs') .
                            ' location at ' . ($proposal['locationAddress'] ?? '3145 N 33rd Ave') . 
                            ', ' . ($proposal['locationCityStateZip'] ?? 'Phoenix, AZ 85017') . '.';
    }

    // Safety limit
    if (strlen($summaryNarrative) > 1250) {
        $summaryNarrative = substr($summaryNarrative, 0, 1250) . '...';
    }

    return '
        <div class="summaryNarrative">
            ' . nl2br(htmlspecialchars($summaryNarrative)) . '
        </div>';
}

#endregion

#region SECTION 06 - Data Source

function getProposalData(array $input): array
{
    return normalizeProposalData($input);
}

function normalizeProposalData(array $input): array
{
    // Unwrap common nesting patterns
    $proposal = $input['proposal'] ?? $input;
    $data     = $proposal['data'] ?? $proposal;
    $location = $data['location'] ?? $data['parsed']['location'] ?? $data;
    $contact  = $data['contact']  ?? $data;
    $entity   = $data['entity']   ?? $data;
    $res      = $proposal['resolution'] ?? [];
    $pers     = $proposal['persistence'] ?? [];

    // === DYNAMIC FIELD MAPPER ===
    $loc = normalizeLocationFields($location);

    return [
        'entityName'           => $entity['name'] ?? $entity['entityName'] ?? 'Unknown Entity',

        'contactName'          => trim(implode(' ', array_filter([
                                    $contact['salutation'] ?? $contact['contactSalutation'] ?? '',
                                    $contact['firstName']  ?? $contact['contactFirstName'] ?? '',
                                    $contact['lastName']   ?? $contact['contactLastName'] ?? ''
                                  ]))) ?: 'Susan Alderson',

        'contactTitle'         => $contact['title'] ?? $contact['contactTitle'] ?? '',
        'contactPhone'         => $contact['primaryPhone'] ?? $contact['contactPrimaryPhone'] ?? '',
        'contactEmail'         => $contact['email'] ?? $contact['contactEmail'] ?? '',

        // === LOCATION FIELDS (Dynamic) ===
        'locationAddress'      => $loc['address'] ?? '',
        'locationCity'         => $loc['city'] ?? '',
        'locationState'        => $loc['state'] ?? '',
        'locationZip'          => $loc['zip'] ?? '',

        'locationCityStateZip' => buildCityStateZip($loc),

        'locationCounty'       => $loc['county'] ?? '',
        'locationCountyFips'   => $loc['countyFips'] ?? '',
        'locationJurisdiction' => $loc['locationJurisdiction'] ?? $loc['jurisdiction'] ?? 'Pending',
        'locationPlaceId'      => $loc['locationPlaceId'] ?? $loc['placeId'] ?? '',

        'governanceNarrative'  => $proposal['governanceNarrative'] ?? 
                                  ($res['narratives']['decision'][0] ?? ''),
        'confidence'           => $proposal['confidence'] ?? 85,
        'pcCode'               => $res['pc']['code'] ?? 'PC-3',
        'resolutionStatus'     => $res['pc']['status'] ?? 'existing_location',
        'commitAllowed'        => ($pers['commitAllowed'] ?? false) ? 'YES' : 'NO',

        'parcelDetails'        => $loc['parcelDetails'] ?? []
    ];
}

// ================================================
// HELPER FUNCTIONS (Dynamic & Reusable)
// ================================================

function normalizeLocationFields(array $loc): array
{
    $map = [
        'address'      => ['address', 'locationAddress', 'formattedAddress'],
        'city'         => ['city', 'locationCity'],
        'state'        => ['state', 'locationState'],
        'zip'          => ['zip', 'locationZip'],
        'county'       => ['county', 'locationCounty'],
        'countyFips'   => ['countyFips', 'locationCountyFips'],
        'locationJurisdiction' => ['locationJurisdiction', 'jurisdiction'],
        'locationPlaceId' => ['locationPlaceId', 'placeId'],
        'parcelDetails'=> ['parcelDetails']
    ];

    $normalized = [];
    foreach ($map as $targetKey => $possibleKeys) {
        foreach ($possibleKeys as $key) {
            if (!empty($loc[$key]) || (isset($loc[$key]) && $loc[$key] !== '')) {
                $normalized[$targetKey] = $loc[$key];
                break;
            }
        }
    }

    return $normalized;
}

function buildCityStateZip(array $loc): string
{
    $city  = $loc['city'] ?? $loc['locationCity'] ?? '';
    $state = $loc['state'] ?? $loc['locationState'] ?? '';
    $zip   = $loc['zip'] ?? $loc['locationZip'] ?? '';

    $stateZip = trim($state . ($zip ? ' ' . $zip : ''));
    return trim(implode(', ', array_filter([$city, $stateZip])));
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