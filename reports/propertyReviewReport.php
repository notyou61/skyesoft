<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — propertyReviewReport.php
//  Property / Parcel Review Report Generator
//  Version: 1.0.0
//  Created: 2026-06-25
// =============================================

#region SECTION 00 - Main Report Generator

function generatePropertyReviewReport(array $input): array
{
    $data = normalizePropertyReviewData($input);

    $bodyHtml = buildPropertyReviewBody($data);

    $address = $data['inputAddress'] ?? 'Unknown Address';
    $reportFilename = "Property Review - " . preg_replace('/[^A-Za-z0-9 ]/', '', $address);

    return [
        'reportType'      => 'property',
        'reportTitle'     => 'Property Review Report',

        'reportFilename'  => $reportFilename,

        'reportSummary'   => '',

        'reportBodyHtml'  => $bodyHtml,

        'reportArtifacts' => $data['reportArtifacts'] ?? [],

        'reportMeta'      => [
            'generated_at' => date('Y-m-d H:i:s'),
            'actionId'     => $input['actionId'] ?? null,
        ]
    ];
}

#endregion

#region SECTION 01 - Data Normalizer

function normalizePropertyReviewData(array $input): array
{
    $payload = $input['actionResponseData'] ?? $input['data'] ?? $input['parcel'] ?? $input;

    return [
        'inputAddress'     => $payload['inputAddress'] ?? 'Unknown Address',
        'summary'          => $payload['summary'] ?? '',
        'primaryParcel'    => $payload['parcel']['primaryParcel'] ?? $payload['primaryParcel'] ?? null,
        'candidateParcels' => $payload['parcel']['candidateParcels'] ?? [],
        'jurisdiction'     => $payload['jurisdiction'] ?? [],
        'governance'       => $payload['governance'] ?? [],
        'google'           => $payload['google'] ?? [],
        'census'           => $payload['census'] ?? [],
        'reportArtifacts'  => $payload['reportArtifacts'] ?? []
    ];
}

#endregion

#region SECTION 02 - Body Builder

function buildPropertyReviewBody(array $data): string
{
    $html = '';

    // 1. Property Review Summary
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildSectionHeader('Property Review Summary', 'pin.png');
    $html .= '<div class="summaryBlock" style="margin-bottom:20px; line-height:1.6;">';
    $summary = $data['summary'] ?? 'Property review completed.';
    $summary = str_replace(['<br>', '<br/>', '<br />'], ' ', $summary);
    $summary = preg_replace('/\s+/', ' ', $summary);
    $html .= htmlspecialchars(trim($summary));
    $html .= '</div>';
    $html .= '</div>';

    // 2. Primary Parcel
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildPrimaryParcelSection($data);
    $html .= '</div>';

    // 3. Google Map (Satellite)
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildGoogleMapSection($data);
    $html .= '</div>';

    // 4. Street View
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildStreetViewSection($data);
    $html .= '</div>';

    // 5. Governance
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildGovernanceSection($data);
    $html .= '</div>';

    return $html;
}

#endregion

#region SECTION 03 - Section Builders (Reused from location review)

function buildPrimaryParcelSection(array $data): string
{
    $p = $data['primaryParcel'];
    if (!$p) return '';

    $html = buildSectionHeader('Primary Parcel', 'compass.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>APN</th><td>' . htmlspecialchars($p['parcelNumber'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Owner</th><td>' . htmlspecialchars($p['ownerName'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Address</th><td>' . htmlspecialchars($p['siteAddress'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Jurisdiction</th><td>' . htmlspecialchars($p['jurisdiction'] ?? '—') . '</td></tr>';
    $html .= '</table>';
    return $html;
}

function buildGovernanceSection(array $data): string
{
    $g = $data['governance'] ?? [];
    $html = buildSectionHeader('Governance', 'scales.png');
    $html .= '<table class="dataTable">';
    $html .= '<tr><th>RS Code</th><td>' . htmlspecialchars($g['rsCode'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Status</th><td>' . htmlspecialchars($g['parcelStatus'] ?? '—') . '</td></tr>';
    $html .= '</table>';
    return $html;
}

// Reuse the map and street view helpers from locationReviewReport.php if available
// (You can copy buildGoogleMapSection and buildStreetViewSection from locationReviewReport.php here if needed)

#endregion