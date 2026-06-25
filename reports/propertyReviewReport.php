<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — propertyReviewReport.php
//  Property / Parcel Review Report Generator
//  Version: 1.0.2
//  Updated: 2026-06-25
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
    // Source payload.
    $payload = $input['actionResponseData']
        ?? $input['data']
        ?? $input;

    // Decode tblActions JSON if needed.
    if (is_string($payload)) {
        $decodedPayload = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPayload)) {
            $payload = $decodedPayload;
        }
    }

    // Unwrap nested response if present.
    if (!empty($payload['actionResponseData'])) {
        $nestedPayload = $payload['actionResponseData'];

        if (is_string($nestedPayload)) {
            $nestedPayload = json_decode($nestedPayload, true);
        }

        if (is_array($nestedPayload)) {
            $payload = $nestedPayload;
        }
    }

    // Clean address.
    $inputAddress = $payload['inputAddress']
        ?? $payload['address']
        ?? $input['inputAddress']
        ?? 'Unknown Address';

    $inputAddress = preg_replace('/^\s*(parcel review|property review)\s+/i', '', $inputAddress);

    // Resolve coordinates.
    $google = $payload['google'] ?? [];

    if (empty($google['latitude']) && !empty($payload['latitude'])) {
        $google['latitude'] = $payload['latitude'];
    }

    if (empty($google['longitude']) && !empty($payload['longitude'])) {
        $google['longitude'] = $payload['longitude'];
    }

    if (empty($google['latitude']) && !empty($payload['census']['normalized']['lat'])) {
        $google['latitude'] = $payload['census']['normalized']['lat'];
    }

    if (empty($google['longitude']) && !empty($payload['census']['normalized']['lng'])) {
        $google['longitude'] = $payload['census']['normalized']['lng'];
    }

    // Resolve parcel.
    $primaryParcel = $payload['parcel']['primaryParcel']
        ?? $payload['primaryParcel']
        ?? null;

    return [
        'inputAddress'     => trim((string)$inputAddress),
        'summary'          => $payload['summary'] ?? '',
        'primaryParcel'    => $primaryParcel,
        'candidateParcels' => $payload['parcel']['candidateParcels'] ?? [],
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

    // 1. Property Review Summary (Narrative)
    $html .= '<div style="page-break-inside:avoid; break-inside:avoid;">';
    $html .= buildSectionHeader('Property Review Summary', 'pin.png');
    $html .= '<div class="summaryBlock" style="margin-bottom:20px; line-height:1.6; font-size:11pt;">';
    $summary = $data['summary'] ?? 'Property review completed.';
    $summary = str_replace(['<br>', '<br/>', '<br />'], ' ', $summary);
    $summary = preg_replace('/\s+/', ' ', $summary);
    $html .= htmlspecialchars(trim($summary));
    $html .= '</div>';
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

function buildPrimaryParcelSection(array $data): string
{
    $p = $data['primaryParcel'] ?? [];

    $html = '<div class="section">';
    $html .= buildSectionHeader('Primary Parcel', 'compass.png');

    $html .= '<table class="dataTable">';
    $html .= '<tr>';
    $html .= '<th>APN</th>';
    $html .= '<td>' . htmlspecialchars($p['parcelNumber'] ?? '—') . '</td>';
    $html .= '</tr>';

    $html .= '<tr>';
    $html .= '<th>Owner</th>';
    $html .= '<td>' . htmlspecialchars($p['ownerName'] ?? '—') . '</td>';
    $html .= '</tr>';

    $html .= '<tr>';
    $html .= '<th>Property Address</th>';
    $html .= '<td>' . htmlspecialchars($p['siteAddress'] ?? '—') . '</td>';
    $html .= '</tr>';

    $html .= '<tr>';
    $html .= '<th>Jurisdiction</th>';
    $html .= '<td>' . htmlspecialchars($p['jurisdiction'] ?? '—') . '</td>';
    $html .= '</tr>';

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