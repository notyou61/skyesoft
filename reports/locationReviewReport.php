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

        'reportSummary'   => '',   // ← Keep empty to avoid duplicate

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

    // 1. Location Review Summary
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildSectionHeader('Location Review Summary', 'pin.png');
    $html .= '<div class="summaryBlock" style="margin-bottom:20px; line-height:1.6;">';
    $summary = $data['summary'] ?? 'Location review completed.';
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

    // 4. Street View (NEW)
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildStreetViewSection($data);
    $html .= '</div>';

    // 5. Additional Parcels
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildCandidateParcelsSection($data);
    $html .= '</div>';

    // 6. Governance
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildGovernanceSection($data);
    $html .= '</div>';

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

function buildGoogleMapSection(array $data): string
{
    $google = $data['google'] ?? [];
    $lat = $google['latitude'] ?? null;
    $lng = $google['longitude'] ?? null;

    if (!$lat || !$lng) {
        return '';
    }

    $html = buildSectionHeader('Location Map', 'pin.png');   // ← Use pin icon

    $staticMapUrl = 'https://maps.googleapis.com/maps/api/staticmap?' .
        'center=' . $lat . ',' . $lng .
        '&zoom=17&size=900x500&maptype=satellite' .   // ← Zoomed out a bit
        '&markers=color:red%7C' . $lat . ',' . $lng .
        '&key=' . (skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') ?: '');

    $html .= '<div style="text-align:center; margin:12px 0; page-break-inside:avoid;">';
    $html .= '<img src="' . htmlspecialchars($staticMapUrl) . '" style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" alt="Location Map">';
    $html .= '</div>';

    return $html;
}

function buildStreetViewSection(array $data): string
{
    $html = '<div class="section">';

    $html .= buildSectionHeader('Street View Verification', 'camera.png');

    $html .= '<div style="page-break-inside:avoid !important; break-inside:avoid !important;">';

    $streetViewPath = $data['reportArtifacts']['streetview'] ?? null;

    $lat = $data['google']['latitude'] ?? null;
    $lng = $data['google']['longitude'] ?? null;

    if ($streetViewPath && file_exists($streetViewPath)) {

        // === Use Pre-Generated Image ===
        $html .= '<div style="text-align:center; margin:4px 0 8px 0;">';
        $html .= '<img src="' . htmlspecialchars($streetViewPath) . '" ';
        $html .= 'style="max-width:100%; width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" ';
        $html .= 'alt="Street View of Location">';
        $html .= '</div>';

        $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin:0 0 8px 0;">';
        $html .= 'Google Street View • ' . htmlspecialchars($data['inputAddress'] ?? 'Unknown Address');
        $html .= '</p>';

    } else if ($lat && $lng) {

        // === Dynamic Fallback ===
        $googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY')
            ?: getenv('GOOGLE_MAPS_STATIC_API_KEY');

        if ($googleKey) {

            $address = trim((string)($data['inputAddress'] ?? ''));

            preg_match('/^\s*(\d+)/', $address, $matches);
            $streetNumber = isset($matches[1]) ? (int)$matches[1] : 0;

            $heading = ($streetNumber % 2 === 0) ? 90 : 270;

            $streetViewUrl = 'https://maps.googleapis.com/maps/api/streetview'
                . '?size=900x500'
                . '&location=' . $lat . ',' . $lng
                . '&heading=' . $heading
                . '&fov=90'
                . '&pitch=0'
                . '&key=' . urlencode($googleKey);

            $html .= '<div style="text-align:center; margin:4px 0 8px 0;">';
            $html .= '<img src="' . htmlspecialchars($streetViewUrl) . '" ';
            $html .= 'style="max-width:100%; width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" ';
            $html .= 'alt="Street View of Location">';
            $html .= '</div>';

            $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin:0 0 8px 0;">';
            $html .= 'Google Street View • ' . htmlspecialchars($data['inputAddress'] ?? 'Unknown Address');
            $html .= '</p>';

        } else {
            goto placeholder;
        }

    } else {
        placeholder:
        // === Placeholder ===
        $html .= '<div class="image-placeholder" style="min-height:260px; display:flex; align-items:center; justify-content:center;">';
        $html .= '<span style="font-size:11pt; color:#555;">📍 Street View imagery unavailable at this time</span>';
        $html .= '</div>';
    }

    $html .= '</div>'; // close inner wrapper
    $html .= '</div>'; // close .section

    return $html;
}

#endregion