<?php
// =============================================
// contactProposalReport.php
// =============================================

function generateContactProposalReport(array $input): array
{
    // TODO: Later this will receive live data from processProposedContact.php
    $proposal = getTestProposalData();

    $bodyHtml = buildContactProposalBody($proposal);

    return [
        'reportType'      => 'contact_proposal',
        'reportTitle'     => 'Proposed Contact Report - ' . ($proposal['entity_name'] ?? 'Unknown'),
        'reportSummary'   => generateSummarySection($proposal),
        'reportBodyHtml'  => $bodyHtml,
        'reportArtifacts' => collectArtifacts($proposal),
        'reportMeta'      => [
            'generated_at' => date('Y-m-d H:i:s'),
            'proposal_id'  => $input['proposalId'] ?? null
        ]
    ];
}

/**
 * Build full HTML body (all pages after summary)
 */
function buildContactProposalBody(array $proposal): string
{
    $html = '';

    // Entity, Contact, Location
    $html .= '<h2>Entity Information</h2>';
    $html .= '<p><strong>Name:</strong> ' . htmlspecialchars($proposal['entity_name'] ?? 'N/A') . '</p>';

    $html .= '<h2>Contact Information</h2>';
    $html .= '<p><strong>Contact:</strong> ' . htmlspecialchars($proposal['contact_name'] ?? 'N/A') . '</p>';

    $html .= '<h2>Location Information</h2>';
    $html .= '<p><strong>Address:</strong> ' . htmlspecialchars($proposal['address'] ?? 'N/A') . '</p>';

    $html .= '<hr>';

    // Parcel Candidate Summary
    $html .= '<h2>Parcel Candidate Summary</h2>';
    $html .= '<p>Placeholder for parcel analysis, scores, and recommendations.</p>';

    // Parcel Detail Pages (loop ready)
    $html .= '<h2>Parcel Details</h2>';
    $html .= '<p>Individual parcel detail blocks will go here.</p>';

    // Street View + Governance
    $html .= '<h2>Street View &amp; Governance</h2>';
    $html .= '<p>Street view images and governance narrative will be rendered here.</p>';

    return $html;
}

function generateSummarySection(array $proposal): string
{
    return '
        <p>' . htmlspecialchars($proposal['ai_narrative'] ?? 'No narrative available.') . '</p>
        <p><strong>Confidence:</strong> ' . htmlspecialchars($proposal['confidence'] ?? 'N/A') . '</p>
    ';
}

function collectArtifacts(array $proposal): array
{
    return [
        'satellite'   => $proposal['satellite_image'] ?? null,
        'streetview'  => $proposal['street_view'] ?? null,
        'parcel_maps' => $proposal['parcel_maps'] ?? []
    ];
}

function getTestProposalData(): array
{
    return [
        'entity_name'   => 'Test Entity LLC',
        'contact_name'  => 'John Smith',
        'address'       => '123 Main Street, Tempe, AZ 85281',
        'ai_narrative'  => 'Strong candidate for outreach based on zoning and ownership patterns.',
        'confidence'    => 'High'
    ];
}