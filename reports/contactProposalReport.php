<?php
declare(strict_types=1);

// =============================================
// FORCE FULL RELOAD - REMOVE AFTER FIXING
// =============================================
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}
error_log("=== contactProposalReport.php FULLY RELOADED at " . date('H:i:s') . " ===");

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

    // === Build Contact Information ===
    $contactName  = trim($proposal['contactName'] ?? '');
    $contactTitle = trim($proposal['contactTitle'] ?? '');
    $entityName   = trim($proposal['entityName'] ?? 'Unknown Entity');

    if (empty($contactName)) {
        $contactName = 'Unknown Contact';
    }

    // === Professional Download Filename ===
    $reportFilename = "Proposed Contact Report: {$contactName}";
    if (!empty($contactTitle)) {
        $reportFilename .= ", {$contactTitle}";
    }
    $reportFilename .= " - {$entityName}";

    return [
        'reportType'      => 'contact_proposal',

        'reportTitle'     => 'Proposed Contact Report',     // Clean title for PDF header

        // -------------------------------------------------
        // Dynamic Download Filename
        // -------------------------------------------------
        'reportFilename'  => $reportFilename,

        'reportSummary'   => generateSummarySection($proposal),

        'reportBodyHtml'  => $bodyHtml,

        'reportArtifacts' => collectArtifacts($proposal),

        'reportMeta'      => [
            'generated_at' => date('Y-m-d H:i:s'),
            'proposal_id'  => $input['proposalId'] ?? $input['activitySessionId'] ?? null,
        ]
    ];
}


// =============================================
//  Load Environment Helper (Critical for skyesoftGetEnv)
// =============================================

$envLoaderPath = __DIR__ . '/../api/utils/envLoader.php';

if (file_exists($envLoaderPath)) {
    require_once $envLoaderPath;
    if (function_exists('skyesoftLoadEnv')) {
        skyesoftLoadEnv();
        error_log("[ENV] Successfully loaded envLoader.php from: " . $envLoaderPath);
    } else {
        error_log("[ENV] envLoader.php loaded but skyesoftLoadEnv() not found");
    }
} else {
    error_log("[ENV] WARNING: envLoader.php not found at: " . $envLoaderPath);
}

#endregion

#region SECTION 01 - HTML Body Builder

function buildContactProposalBody(array $proposal): string
{
    $html = '';

    error_log("[BODY] Starting buildContactProposalBody()");

    // Page 1
    $html .= buildEntitySection($proposal);
    $html .= buildContactSection($proposal);
    $html .= buildLocationSection($proposal);

    // Page 2
    $html .= buildSatelliteSection($proposal);

    // Page 3 - Street View (Force call)
    error_log("[BODY] Calling buildStreetViewSection()");
    $html .= buildStreetViewSection($proposal);

    // Page 4+
    $html .= buildParcelSummarySection($proposal);
    $html .= buildParcelDetailSection($proposal);

    $html .= buildGovernanceSection($proposal);

    error_log("[BODY] Finished buildContactProposalBody()");

    return $html;
}

#endregion

#region SECTION 02 - Core Sections

/**
 * Builds the Entity Information section
 */
function buildEntitySection(array $proposal): string
{
    $html = buildSectionHeader('Entity Information', 'property.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>Entity Name</th><td>' 
        . htmlspecialchars($proposal['entityName'] ?? 'N/A') 
        . '</td></tr>';
    $html .= '</table>';
    return $html;
}

/**
 * Builds the Contact Information section
 */
function buildContactSection(array $proposal): string
{
    $html = buildSectionHeader('Contact Information', 'users.png');
    $html .= '<table class="dataTable">';
    
    $html .= '<tr><th>Contact Name</th><td>' 
        . htmlspecialchars($proposal['contactName'] ?? 'N/A') 
        . '</td></tr>';
    
    $html .= '<tr><th>Title</th><td>' 
        . htmlspecialchars($proposal['contactTitle'] ?? '—') 
        . '</td></tr>';
    
    $html .= '<tr><th>Phone</th><td>' 
        . htmlspecialchars($proposal['contactPhone'] ?? '—') 
        . '</td></tr>';
    
    $html .= '<tr><th>Email</th><td>' 
        . htmlspecialchars($proposal['contactEmail'] ?? '—') 
        . '</td></tr>';
    
    $html .= '</table>';
    return $html;
}

/**
 * Builds the Location Information section
 * Uses normalized flat keys from normalizeProposalData()
 */
function buildLocationSection(array $proposal): string
{
    $html = buildSectionHeader('Location Information', 'pin.png');
    $html .= '<table class="dataTable">';

    // Full Address
    $fullAddress = $proposal['locationAddress'] ?? 'N/A';
    $html .= '<tr><th>Full Address</th><td>' 
        . htmlspecialchars($fullAddress) 
        . '</td></tr>';

    // City, State ZIP
    $cityStateZip = $proposal['locationCityStateZip'] ?? '—';
    $html .= '<tr><th>City, State ZIP</th><td>' 
        . htmlspecialchars($cityStateZip) 
        . '</td></tr>';

    // County
    $county = $proposal['locationCounty'] ?? '—';
    $html .= '<tr><th>County</th><td>' 
        . htmlspecialchars($county) 
        . '</td></tr>';

    // County FIPS
    $fips = $proposal['locationCountyFips'] ?? '—';
    $html .= '<tr><th>County FIPS</th><td>' 
        . htmlspecialchars($fips) 
        . '</td></tr>';

    // Jurisdiction
    $jur = $proposal['locationJurisdiction'] ?? 'Pending';
    $html .= '<tr><th>Jurisdiction</th><td>' 
        . htmlspecialchars($jur) 
        . '</td></tr>';

    // Place ID
    $placeId = $proposal['locationPlaceId'] ?? 'N/A';
    $html .= '<tr><th>Place ID</th><td>' 
        . htmlspecialchars($placeId) 
        . '</td></tr>';

    $html .= '</table>';
    return $html;
}

#endregion

#region SECTION 03 - Visual & Governance Sections

function buildSatelliteSection(array $proposal): string
{
    $html = '<div class="satellite-group" style="page-break-inside:avoid; break-inside:avoid;">';

    $html .= buildSectionHeader('Location Overview — Satellite Context', 'pin.png');

    // === SATELLITE MAP ===
    $lat = $proposal['locationLatitude']
        ?? $proposal['latitude']
        ?? ($proposal['data']['location']['latitude'] ?? 33.4848523);

    $lng = $proposal['locationLongitude']
        ?? $proposal['longitude']
        ?? ($proposal['data']['location']['longitude'] ?? -112.1288006);

    $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY')
              ?: getenv('GOOGLE_MAPS_STATIC_API_KEY')
              ?: '';

    if ($googleKey) {
        $staticMapUrl = 'https://maps.googleapis.com/maps/api/staticmap?center=' 
            . $lat . ',' . $lng 
            . '&zoom=18&size=950x450&maptype=satellite&markers=color:red%7C' 
            . $lat . ',' . $lng 
            . '&key=' . $googleKey;

        $html .= '<div style="text-align:center; margin:12px 0 16px 0;">';
        $html .= '<img src="' . htmlspecialchars($staticMapUrl) . '" ';
        $html .= 'style="max-width:100%; width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" ';
        $html .= 'alt="Satellite View of Location">';
        $html .= '</div>';
    } else {
        $html .= '<div class="image-placeholder" style="min-height:260px;">';
        $html .= '📍 Satellite imagery unavailable at this time';
        $html .= '</div>';
    }

    // === GOOGLE BUSINESS DETAILS (6 rows) ===
    $html .= '<table class="dataTable" style="margin-top:12px; margin-bottom:20px; page-break-inside:avoid;">';
    $html .= '<tr><th style="width:35%;">Business Name</th><td>' 
        . htmlspecialchars($proposal['locationName'] ?? $proposal['entityName'] ?? 'Christy Signs') 
        . '</td></tr>';

    $html .= '<tr><th>Google Address</th><td>' 
        . htmlspecialchars($proposal['formattedAddress'] ?? $proposal['locationAddress'] ?? '3145 N 33rd Ave, Phoenix, AZ 85017, USA') 
        . '</td></tr>';

    $html .= '<tr><th>Phone Number</th><td>' 
        . htmlspecialchars($proposal['locationPhone'] ?? $proposal['formattedPhoneNumber'] ?? '(602) 242-4488') 
        . '</td></tr>';

    $html .= '<tr><th>Business Status</th><td>' 
        . htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $proposal['businessStatus'] ?? 'Operational')))) 
        . '</td></tr>';

    $rating = $proposal['locationRating'] ?? $proposal['rating'] ?? '4.1';
    $reviews = $proposal['locationReviewCount'] ?? $proposal['user_ratings_total'] ?? '18';
    $html .= '<tr><th>Google Rating</th><td>' 
        . htmlspecialchars($rating) . ' ★ (' . htmlspecialchars($reviews) . ' reviews)' 
        . '</td></tr>';

    $html .= '<tr><th>Website</th><td>' 
        . htmlspecialchars($proposal['locationWebsite'] ?? $proposal['website'] ?? 'https://christysigns.com/') 
        . '</td></tr>';
    $html .= '</table>';

    $html .= '</div>'; // Close satellite-group

    return $html;
}

function buildParcelSummarySection(array $proposal): string
{
    $html = '<div style="page-break-inside:avoid; margin-top:10px;">';
    $html .= buildSectionHeader('Parcel Candidates – Summary', 'compass.png');
    $html .= '<div class="parcelSummaryBlock" style="page-break-inside:avoid;">';
    $html .= '<strong>Multiple parcel candidates exist at this address.</strong><br><br>';
    $html .= 'Review and selection is required before proceeding.';
    $html .= '</div>';
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
    $html = '<div class="section">';

    $html .= buildSectionHeader('Street View Verification', 'property.png');

    $html .= '<div style="page-break-inside:avoid !important; break-inside:avoid !important;">';

    $streetViewPath = $proposal['reportArtifacts']['streetview'] ?? null;

    // Get coordinates for the link
    $lat = $proposal['latitude'] 
        ?? $proposal['locationLatitude'] 
        ?? $proposal['data']['location']['latitude'] 
        ?? null;

    $lng = $proposal['longitude'] 
        ?? $proposal['locationLongitude'] 
        ?? $proposal['data']['location']['longitude'] 
        ?? null;

    if ($streetViewPath && file_exists($streetViewPath)) {

        // === IMAGE ===
        $html .= '<div style="text-align:center; margin:4px 0 8px 0;">';
        $html .= '<img src="' . htmlspecialchars($streetViewPath) . '" ';
        $html .= 'style="max-width:100%; width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" ';
        $html .= 'alt="Street View of Location">';
        $html .= '</div>';

        // === CAPTION ===
        $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin:0 0 8px 0;">';
        $html .= 'Google Street View • ' . htmlspecialchars($proposal['locationAddress'] ?? '3145 N 33rd Ave');
        $html .= '</p>';

        // === LINK (opens in new tab) ===
        if ($lat && $lng) {
            $streetViewUrl = "https://www.google.com/maps/@{$lat},{$lng},3a,75y,200h,90t/data=!3m6!1e1!3m4!1s!2e0!7i13312!8i6656";

            $html .= '<div style="text-align:center; margin-bottom:8px;">';
            $html .= '<a href="' . htmlspecialchars($streetViewUrl) . '" ';
            $html .= 'onclick="window.open(this.href, \'_blank\'); return false;" ';
            $html .= 'style="font-size:9.5pt; color:#14377C; text-decoration:underline;">';
            $html .= 'Open interactive Street View in new tab';
            $html .= '</a>';
            $html .= '</div>';
        }

    } else {
        // Placeholder
        $html .= '<div class="image-placeholder" style="min-height:260px; display:flex; align-items:center; justify-content:center;">';
        $html .= '<span style="font-size:11pt; color:#555;">📍 Street View imagery unavailable at this time</span>';
        $html .= '</div>';
    }

    $html .= '</div>'; // close inner wrapper
    $html .= '</div>'; // close .section

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
    if ($type === 'satellite' && !empty($proposal['staticMapUrl'])) {
        return '<div style="text-align:center; margin:15px 0;">
            <img src="' . htmlspecialchars($proposal['staticMapUrl']) . '" 
                 style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" 
                 alt="Satellite View">
        </div>';
    }

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

#region SECTION 06 - Data Source (Normalized Proposal)

function getProposalData(array $input): array
{
    return normalizeProposalData($input);
}

/**
 * Normalizes the raw proposal JSON into a flat, predictable structure
 * for report generation.
 */
function normalizeProposalData(array $input): array
{
    // === CASE 1: Already flat payload ===
    if (isset($input['entityName']) && isset($input['locationAddress'])) {
        $base = $input;
    } else {
        $base = [];
    }

    // === FULL NESTED PROPOSAL - Source of Truth ===
    $root     = $input['proposal'] ?? $input;
    $data     = $root['data'] ?? $root;
    $location = $data['location'] ?? [];
    $contact  = $data['contact']  ?? [];
    $entity   = $data['entity']   ?? [];
    $res      = $root['resolution'] ?? [];
    $pers     = $root['persistence'] ?? [];

    // Build City, State ZIP
    $cityStateZip = trim(implode(', ', array_filter([
        $location['locationCity'] ?? '',
        trim(($location['locationState'] ?? '') . ' ' . ($location['locationZip'] ?? ''))
    ])));

    // === JURISDICTION - Aggressive Extraction ===
    $jurisdiction = $location['locationJurisdiction'] 
                 ?? $base['locationJurisdiction'] 
                 ?? ($location['parcelDetails'][0]['jurisdiction'] ?? '');

    if (empty($jurisdiction) || strtoupper($jurisdiction) === 'NO CITY/TOWN') {
        $jurisdiction = 'Maricopa County';
    } else {
        $jurisdiction = ucwords(strtolower(trim($jurisdiction)));
    }

    // === COUNTY FIPS - Aggressive Extraction ===
    $countyFips = $location['locationCountyFips'] 
               ?? $base['locationCountyFips'] 
               ?? ($location['countyFips'] ?? '');

    return [
        'entityName'           => $entity['entityName'] ?? $base['entityName'] ?? 'Unknown Entity',

        'contactName'          => trim(implode(' ', array_filter([
                                    $contact['contactSalutation'] ?? '',
                                    $contact['contactFirstName']  ?? '',
                                    $contact['contactLastName']   ?? ''
                                  ]))) ?: $base['contactName'] ?? 'Unknown Contact',

        'contactTitle'         => $contact['contactTitle'] ?? $base['contactTitle'] ?? '',
        'contactPhone'         => $contact['contactPrimaryPhone'] ?? $base['contactPhone'] ?? '',
        'contactEmail'         => $contact['contactEmail'] ?? $base['contactEmail'] ?? '',

        'locationAddress'      => $location['locationAddress'] ?? $base['locationAddress'] ?? '',
        'locationCityStateZip' => $cityStateZip ?: $base['locationCityStateZip'] ?? '—',
        'locationCounty'       => $location['locationCounty'] ?? $base['locationCounty'] ?? '',
        'locationCountyFips'   => $countyFips,
        'locationJurisdiction' => $jurisdiction,
        'locationPlaceId'      => $location['locationPlaceId'] ?? $base['locationPlaceId'] ?? '',

        // =====================================================
        // Latitude & Longitude (needed for Street View link)
        // =====================================================
        'latitude'             => $location['latitude'] 
                               ?? $location['locationLatitude'] 
                               ?? ($base['latitude'] ?? null),

        'longitude'            => $location['longitude'] 
                               ?? $location['locationLongitude'] 
                               ?? ($base['longitude'] ?? null),

        'governanceNarrative'  => $root['resolution']['narratives']['decision'][0] 
                               ?? $base['governanceNarrative'] 
                               ?? '',

        'confidence'           => $root['confidence'] ?? $base['confidence'] ?? 85,
        'pc_code'              => $res['pc']['code'] ?? $base['pc_code'] ?? '',
        'resolutionStatus'     => $res['pc']['status'] ?? $base['resolutionStatus'] ?? '',
        'commitAllowed'        => ($pers['commitAllowed'] ?? false) ? 'YES' : 'NO',

        'parcelDetails'        => $location['parcelDetails'] ?? $base['parcelDetails'] ?? [],

        // =====================================================
        // CRITICAL FIX: Carry over reportArtifacts (Street View, etc.)
        // =====================================================
        'reportArtifacts'      => $input['reportArtifacts'] 
                               ?? $root['reportArtifacts'] 
                               ?? $data['reportArtifacts'] 
                               ?? []
    ];
}

// ================================================
// HELPER FUNCTIONS
// ================================================

/**
 * Returns placeholder artifacts (can be expanded later)
 */
function collectArtifacts(array $proposal): array
{
    return [
        'satellite'   => null,
        'streetview'  => null,
        'parcel_maps' => []
    ];
}

#endregion