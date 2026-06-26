<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — propertyReviewReport.php
//  Property / Parcel Review Report Generator
//  Version: 1.3.0 (Summary as Bullets)
//  Updated: 2026-06-26
// =============================================

#region SECTION 00 - Main Report Generator

require_once __DIR__ . '/reportHelpers.php';

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

        'reportSummary'   => '',   // No longer duplicated in header

        'reportBodyHtml'  => $bodyHtml,

        'reportArtifacts' => $data['reportArtifacts'] ?? [],

        'reportMeta'      => [
            'generated_at' => date('Y-m-d H:i:s'),
            'actionId'     => $input['actionId'] ?? null,
        ]
    ];
}

#endregion

#region SECTION 01 - Data Normalizer (Simplified)

function normalizePropertyReviewData(array $input): array
{
    // $input is already prepared by generateReports.php (action data merged + artifacts ready)
    $payload = $input;

    // Light address cleanup
    $inputAddress = $payload['inputAddress']
        ?? $payload['address']
        ?? $payload['property']['address']
        ?? $input['inputAddress']
        ?? 'Unknown Address';

    $inputAddress = preg_replace('/^\s*(parcel review|property review)\s+/i', '', $inputAddress);

    // Resolve coordinates (broad fallbacks)
    $google = $payload['google'] ?? [];
    if (empty($google['latitude']) && !empty($payload['latitude'])) {
        $google['latitude'] = $payload['latitude'];
    }
    if (empty($google['longitude']) && !empty($payload['longitude'])) {
        $google['longitude'] = $payload['longitude'];
    }
    if (empty($google['latitude']) && !empty($payload['census']['normalized']['lat'] ?? null)) {
        $google['latitude'] = $payload['census']['normalized']['lat'];
    }
    if (empty($google['longitude']) && !empty($payload['census']['normalized']['lng'] ?? null)) {
        $google['longitude'] = $payload['census']['normalized']['lng'];
    }

    return [
        'inputAddress'     => trim((string)$inputAddress),
        'summary'          => $payload['summary'] ?? $payload['property']['summary'] ?? '',
        'primaryParcel'    => $payload['primaryParcel']
                           ?? $payload['parcel']['primaryParcel']
                           ?? [],
        'candidateParcels' => $payload['candidateParcels']
                           ?? $payload['parcel']['candidateParcels']
                           ?? [],
        'jurisdiction'     => $payload['jurisdiction'] ?? [],
        'governance'       => $payload['governance'] ?? [],
        'google'           => $google,
        'census'           => $payload['census'] ?? [],
        'reportArtifacts'  => $payload['reportArtifacts'] ?? [],
        'artifactManifest' => $payload['artifactManifest'] ?? []
    ];
}

#endregion

#region SECTION 02 - Body Builder

function buildPropertyReviewBody(array $data): string
{
    $html = '';

    // 1. Property Review Summary (Bullet Items)
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildPropertySummarySection($data);
    $html .= '</div>';

    // 2. Location Map (Satellite)
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildGoogleMapSection($data);
    $html .= '</div>';

    // 3. Street View
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildStreetViewSection($data);
    $html .= '</div>';

    // 4. Parcel Details
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildPrimaryParcelSection($data);
    $html .= '</div>';

    // 5. Governance
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildGovernanceSection($data);
    $html .= '</div>';

    return $html;
}

#endregion

#region SECTION 03 - Section Builders

function buildPropertySummarySection(array $data): string
{
    $html = buildSectionHeader('Property Review Summary', 'clipboard.png');

    $html .= '<div class="summaryBlock" style="margin-bottom:20px; line-height:1.6; font-size:11pt;">';

    $summary = $data['summary'] ?? '';

    if (!empty($summary)) {
        // Split on <br> tags and turn into bullets
        $items = preg_split('/<br\s*\/?>/i', $summary);
        $cleanItems = [];

        foreach ($items as $item) {
            $item = trim(strip_tags($item));
            if ($item !== '' && !str_contains(strtolower($item), 'skyesoft resolved')) {
                $cleanItems[] = $item;
            }
        }

        if (!empty($cleanItems)) {
            $html .= '<ul style="margin: 0; padding-left: 20px;">';
            foreach ($cleanItems as $item) {
                $html .= '<li style="margin-bottom: 8px;">' . htmlspecialchars($item) . '</li>';
            }
            $html .= '</ul>';
        } else {
            // Fallback
            $html .= '<p>' . htmlspecialchars(trim($summary)) . '</p>';
        }
    } else {
        $html .= '<p>Property review completed.</p>';
    }

    $html .= '</div>';

    return $html;
}

function buildPrimaryParcelSection(array $data): string
{
    $p = $data['primaryParcel'] ?? [];

    $html = '<div class="section">';
    $html .= buildSectionHeader('Primary Parcel', 'compass.png');

    $html .= '<table class="dataTable">';
    $html .= '<tr><th>APN</th><td>' . htmlspecialchars($p['parcelNumber'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Owner</th><td>' . htmlspecialchars($p['ownerName'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Property Address</th><td>' . htmlspecialchars($p['siteAddress'] ?? '—') . '</td></tr>';
    $html .= '<tr><th>Jurisdiction</th><td>' . htmlspecialchars($p['jurisdiction'] ?? '—') . '</td></tr>';
    $html .= '</table>';
    $html .= '</div>';

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