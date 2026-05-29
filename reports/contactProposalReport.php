<?php
// =============================================
// contactProposalReport.php
// =============================================

/**
 * Generate the full report data array
 */
function generateReport(array $input): array
{
    $proposal = getProposalData($input['proposalId'] ?? null);

    return [
        'reportType'     => 'contact_proposal',
        'reportTitle'    => 'Proposed Contact Report - ' . ($proposal['entity_name'] ?? 'Unknown'),
        'reportSummary'  => generateSummarySection($proposal),
        'reportData'     => $proposal,
        'reportArtifacts'=> collectArtifacts($proposal),
        'reportMeta'     => [
            'generated_at' => date('Y-m-d H:i:s'),
            'proposal_id'  => $input['proposalId'] ?? null
        ]
    ];
}

/**
 * Render the body of the Contact Proposal Report
 */
function renderContactProposalBody(\Mpdf\Mpdf $mpdf, array $data, array $artifacts): void
{
    $mpdf->WriteHTML('<h2>Entity Information</h2>');
    $mpdf->WriteHTML('<p><strong>Name:</strong> ' . htmlspecialchars($data['entity_name'] ?? 'N/A') . '</p>');

    $mpdf->WriteHTML('<h2>Contact Information</h2>');
    $mpdf->WriteHTML('<p><strong>Contact:</strong> ' . htmlspecialchars($data['contact_name'] ?? 'N/A') . '</p>');

    $mpdf->WriteHTML('<h2>Location Information</h2>');
    $mpdf->WriteHTML('<p><strong>Address:</strong> ' . htmlspecialchars($data['address'] ?? 'N/A') . '</p>');

    // Placeholder for more sections from test_mpdf.php
    $mpdf->AddPage();
    $mpdf->WriteHTML('<h2>Additional Sections Coming Soon</h2>');
}

/**
 * Generate Executive Summary (Page 1)
 */
function generateSummarySection(array $proposal): string
{
    return '
        <p>' . htmlspecialchars($proposal['ai_narrative'] ?? 'No AI narrative available.') . '</p>
        <p><strong>Confidence Level:</strong> ' . htmlspecialchars($proposal['confidence'] ?? 'N/A') . '</p>
    ';
}

/**
 * Collect images and other artifacts
 */
function collectArtifacts(array $proposal): array
{
    return [
        'satellite'  => $proposal['satellite_image'] ?? null,
        'streetview' => $proposal['street_view'] ?? null,
    ];
}

/**
 * Get proposal data (temporary test data)
 */
function getProposalData(?string $id): array
{
    // TODO: Replace with real data from processProposedContact.php or database
    return [
        'entity_name'   => 'Test Entity LLC',
        'contact_name'  => 'John Smith',
        'address'       => '123 Main Street, Tempe, AZ 85281',
        'ai_narrative'  => 'This property shows strong potential for outreach based on current zoning and ownership patterns.',
        'confidence'    => 'High'
    ];
}