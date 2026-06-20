<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — locationReviewReport.php
//  Location / Parcel Review Report Generator
//  Version: 1.0.2
//  Created: 2026-06-20
// =============================================

#region SECTION 00 - Main Report Generator

function generateLocationReviewReport(array $input): array
{
    $data = normalizeLocationReviewData($input);

    $bodyHtml = buildLocationReviewBody($data);

    $address = $data['inputAddress'] ?? 'Unknown Address';

    $reportFilename = "Location Review - " . preg_replace('/[^A-Za-z0-9 ]/', '', $address);

    return [
        'reportType'      => 'location_review',

        'reportTitle'     => 'Location / Parcel Review Report',

        'reportFilename'  => $reportFilename,

        'reportSummary'   => generateLocationReviewSummary($data),   // ← Use proper HTML summary

        'reportBodyHtml'  => $bodyHtml,

        'reportArtifacts' => $data['reportArtifacts'] ?? [],

        'reportMeta'      => [
            'generated_at' => date('Y-m-d H:i:s'),
            'actionId'     => $input['actionId'] ?? null,
        ]
    ];
}

function generateLocationReviewSummary(array $data): string
{
    $summary = $data['summary'] ?? 'Location review completed.';

    // Convert <br> to actual line breaks for the PDF renderer
    $summary = str_replace('<br>', "\n", $summary);
    $summary = str_replace('<br/>', "\n", $summary);

    return nl2br(htmlspecialchars($summary));
}

#endregion

#region SECTION 01 - Data Normalizer

function normalizeLocationReviewData(array $input): array
{
    $payload = $input['actionPayloadData'] ?? $input['data'] ?? $input;

    return [
        'inputAddress'     => $payload['inputAddress'] ?? 'Unknown Address',
        'summary'          => $payload['summary'] ?? '',
        'primaryParcel'    => $payload['parcel']['primaryParcel'] ?? null,
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

function buildLocationReviewBody(array $data): string
{
    $html = '';

    // Location Review Summary (with HTML line breaks)
    $html .= buildSectionHeader('Location Review Summary', 'pin.png');
    $html .= '<div class="summaryBlock" style="margin-bottom:20px; line-height:1.6;">';
    $html .= nl2br(htmlspecialchars($data['summary'] ?? 'Location review completed.'));
    $html .= '</div>';

    $html .= buildPrimaryParcelSection($data);
    $html .= buildCandidateParcelsSection($data);
    $html .= buildGovernanceSection($data);

    return $html;
}

#endregion

#region SECTION 03 - Section Builders

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

function buildCandidateParcelsSection(array $data): string
{
    $candidates = $data['candidateParcels'] ?? [];
    if (empty($candidates)) return '';

    $html = buildSectionHeader('Additional Parcels at this Address', 'compass.png');

    foreach ($candidates as $i => $c) {
        $html .= '<div class="parcel-block">';
        $html .= '<strong>' . ($i + 1) . '. APN: ' . htmlspecialchars($c['parcelNumber'] ?? '—') . '</strong><br>';
        $html .= 'Owner: ' . htmlspecialchars($c['ownerName'] ?? '—') . '<br>';
        $html .= 'Address: ' . htmlspecialchars($c['siteAddress'] ?? '—');
        $html .= '</div>';
    }

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