<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — reportHelpers.php
//  Shared Report Helper Functions
//  Version: 1.0.0
//  Created: 2026-06-25
// =============================================

/**
 * Build a consistent section header for all reports
 */
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

/**
 * Build Google Satellite Map Section (reusable)
 */
function buildGoogleMapSection(array $data): string
{
    $google = $data['google'] ?? [];
    $lat = $google['latitude'] ?? null;
    $lng = $google['longitude'] ?? null;

    if (!$lat || !$lng) {
        return '<p style="color:#888;">Map unavailable</p>';
    }

    $html = buildSectionHeader('Location Map', 'pin.png');

    $staticMapUrl = 'https://maps.googleapis.com/maps/api/staticmap?' .
        'center=' . $lat . ',' . $lng .
        '&zoom=17&size=900x500&maptype=satellite' .
        '&markers=color:red%7C' . $lat . ',' . $lng .
        '&key=' . (skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') ?: '');

    $html .= '<div style="text-align:center; margin:12px 0; page-break-inside:avoid;">';
    $html .= '<img src="' . htmlspecialchars($staticMapUrl) . '" style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" alt="Location Map">';
    $html .= '</div>';

    return $html;
}

/**
 * Build Street View Section (reusable)
 */
function buildStreetViewSection(array $data): string
{
    $html = '<div class="section">';

    $html .= buildSectionHeader('Street View Verification', 'camera.png');

    $html .= '<div style="page-break-inside:avoid !important; break-inside:avoid !important;">';

    $streetViewPath = $data['reportArtifacts']['streetview'] ?? null;

    $lat = $data['google']['latitude'] ?? null;
    $lng = $data['google']['longitude'] ?? null;

    if ($streetViewPath && file_exists($streetViewPath)) {

        $html .= '<div style="text-align:center; margin:4px 0 8px 0;">';
        $html .= '<img src="' . htmlspecialchars($streetViewPath) . '" ';
        $html .= 'style="max-width:100%; width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" ';
        $html .= 'alt="Street View of Location">';
        $html .= '</div>';

        $html .= '<p style="text-align:center; font-size:9.5pt; color:#444; margin:0 0 8px 0;">';
        $html .= 'Google Street View • ' . htmlspecialchars($data['inputAddress'] ?? 'Unknown Address');
        $html .= '</p>';

    } else if ($lat && $lng) {

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
        $html .= '<div class="image-placeholder" style="min-height:260px; display:flex; align-items:center; justify-content:center;">';
        $html .= '<span style="font-size:11pt; color:#555;">📍 Street View imagery unavailable at this time</span>';
        $html .= '</div>';
    }

    $html .= '</div></div>'; // close wrappers
    return $html;
}