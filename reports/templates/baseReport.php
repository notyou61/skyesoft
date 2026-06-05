<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — baseReport.php
//  Universal PDF Renderer
//  Version: 1.4.5
//  Last Updated: 2026-05-31
// =============================================

#region SECTION 00 - Main Report Renderer

require_once __DIR__ . '/../../vendor/autoload.php';

use Mpdf\Mpdf;

function renderReport(array $report): string
{
    try {
        $mpdf = new Mpdf([
            'format'        => 'Letter',
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 28,
            'margin_bottom' => 25,
            'margin_header' => 8,
            'margin_footer' => 8,
        ]);

        $mpdf->SetHTMLHeader(buildReportHeader($report));
        $mpdf->SetHTMLFooter(buildReportFooter());

        $mpdf->WriteHTML(buildReportStyles(), \Mpdf\HTMLParserMode::HEADER_CSS);

        generateExecutiveSummary($mpdf, $report);

        $processedBodyHtml = processReportArtifacts(
            $report['reportBodyHtml'] ?? '', 
            $report['reportArtifacts'] ?? []
        );

        generateMainBody($mpdf, $processedBodyHtml);

        return $mpdf->Output('', 'S');

    } catch (Throwable $e) {
        http_response_code(500);
        throw new Exception("PDF Generation Error: " . $e->getMessage());
    }
}

#endregion

#region SECTION 01 - Header Builder

function buildReportHeader(array $report): string
{
    $title = $report['reportTitle'] ?? 'Proposed Contact Report (PC-3)';

    return '
    <div style="border-bottom: 3px solid #14377C; padding-bottom: 6px;">
        <table style="width:100%; border:none;">
            <tr>
                <td style="width:78px; padding-right:10px; vertical-align:middle;">
                    <img src="https://skyelighting.com/skyesoft/assets/images/christyLogo.png" 
                         style="width:72px; height:auto;" alt="Christy Signs">
                </td>
                <td>
                    <div style="font-size:14pt; font-weight:700; color:#14377C;">' . htmlspecialchars($title) . '</div>
                    <div style="font-size:9pt; color:#555;">Skyesoft Operational Intelligence | Report Date: ' . date('m/d/y') . '</div>
                </td>
            </tr>
        </table>
    </div>';
}

#endregion

#region SECTION 02 - Footer Builder

function buildReportFooter(): string
{
    return '
    <div style="border-top: 3px solid #14377C; padding-top: 5px; font-size:7.5pt; color:#555; text-align:center;">
        <div style="font-weight:600;">Christy Signs &nbsp;|&nbsp; 3145 N 33rd Ave, Phoenix, AZ 85017 &nbsp;|&nbsp; (602) 242-4488</div>
        <div style="font-size:7pt; color:#666;">© 2026 Christy Signs — Confidential Internal Operational Document &nbsp;•&nbsp; Page {PAGENO} of {nbpg}</div>
    </div>';
}

#endregion

#region SECTION 03 - Stylesheet (Matched to test_mpdf.php)

function buildReportStyles(): string
{
    return '
        body { 
            font-family: Helvetica, Arial, sans-serif; 
            font-size: 11pt; 
            color: #222; 
            line-height: 1.4; 
        }
        
        .sectionHeaderTable { 
            width:100%; 
            border-collapse:collapse; 
            border:none; 
            margin-top:4px; 
            margin-bottom:6px; 
            border-bottom:1.5px solid #888;
        }
        .sectionIconCell { 
            width:22px; 
            border:none; 
            padding:0 8px 5px 0; 
            vertical-align:middle; 
        }
        .sectionTitleCell { 
            border:none; 
            padding:0 0 5px 0; 
            vertical-align:middle; 
        }
        .sectionIcon { 
            width:17px; 
            height:17px; 
            object-fit:contain; 
            display:block; 
        }
        .sectionTitle { 
            font-size:12pt; 
            font-weight:700; 
            color:#14377C; 
            line-height:1.1; 
            margin:0; 
        }
        
        .dataTable { 
            width:100%; 
            border-collapse:collapse; 
            margin:8px 0 14px 0; 
            page-break-inside: avoid; 
        }
        .dataTable th, .dataTable td { 
            border:1px solid #ccc; 
            padding:7px 9px; 
            text-align:left; 
            vertical-align:top; 
        }
        .dataTable th { 
            background:#e8e8e8; 
            width:28%; 
            font-weight:700; 
            color:#333; 
        }
        
        .summaryNarrative, 
        .highlight, 
        .parcelSummaryBlock,
        .satellite-container { 
            background:#f8f9fa; 
            border:1px solid #d0d0d0; 
            padding:16px; 
            border-radius:6px; 
            margin-bottom:14px;
            page-break-inside: avoid;
        }
        
        .parcel-block { 
            border: 2px solid #14377C; 
            border-radius: 8px; 
            padding: 14px; 
            margin-bottom: 20px; 
            background: #fafafa; 
            page-break-inside: avoid;
        }
        
        .image-placeholder { 
            border: 2px dashed #14377C; 
            background: #f8f9fa; 
            padding: 40px 20px; 
            text-align: center; 
            min-height: 260px; 
            border-radius: 8px; 
            page-break-inside: avoid;
        }

        /* Force the entire Satellite + Parcel group to stay together */
        .satellite-group,
        .parcelSummaryBlock,
        .dataTable {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        .streetview-section {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        /* Make sure placeholder matches Satellite section exactly */
        .streetview-section .image-placeholder {
            min-height: 260px;
            font-size: 11pt;
        }

    ';
}

#endregion

#region SECTION 04 - Executive Summary (Now matches test_mpdf.php exactly)

function generateExecutiveSummary(Mpdf $mpdf, array $report): void
{
    $summaryHtml = '
        <div class="section">
            <table class="sectionHeaderTable">
                <tr>
                    <td class="sectionIconCell">
                        <img src="https://skyelighting.com/skyesoft/assets/images/icons/clipboard.png" class="sectionIcon">
                    </td>
                    <td class="sectionTitleCell">
                        <div class="sectionTitle">Report Summary</div>
                    </td>
                </tr>
            </table>
    ';

    $mpdf->WriteHTML($summaryHtml);
    $mpdf->WriteHTML($report['reportSummary'] ?? '<p>Proposal ready for review.</p>');
    $mpdf->WriteHTML('</div>');
}

#endregion

#region SECTION 05 - Main Body & Artifacts

function generateMainBody(Mpdf $mpdf, string $bodyHtml): void
{
    $mpdf->WriteHTML($bodyHtml);
}

function getEmbeddedImageHtmlFromUrl(string $url, string $alt = 'Image'): string
{
    if (empty($url)) {
        return '<div class="image-placeholder">📍 Satellite image not available yet</div>';
    }

    return '<div style="text-align:center; margin:12px 0;">
                <img src="' . htmlspecialchars($url) . '" 
                     style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" 
                     alt="' . htmlspecialchars($alt) . '">
            </div>';
}

function processReportArtifacts(string $html, array $artifacts): string
{
    if (empty($artifacts)) return $html;

        // === SATELLITE MAP - Multiple possible placeholders ===
        if (!empty($artifacts['staticMapUrl'])) {
            $mapHtml = '<div style="text-align:center; margin:15px 0;">
                    <img src="' . htmlspecialchars($artifacts['staticMapUrl']) . '" 
                        style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" 
                        alt="Satellite View">
                </div>';

            // Try multiple common placeholder variations
            $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 0]', $mapHtml, $html);
            $html = str_replace('[ Satellite Image Placeholder - LC]', $mapHtml, $html);
            $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 1]', $mapHtml, $html);
            $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 2]', $mapHtml, $html);
            $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 3]', $mapHtml, $html);
        } else {
            $placeholderHtml = '<div class="image-placeholder">📍 Satellite image not available yet</div>';
            $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - Other]', $placeholderHtml, $html);
        }

    // === STREET VIEW ===
    if (!empty($artifacts['streetview'])) {
        $html = str_replace(
            '[Street View Image will be inserted here by baseReport.php]', 
            getEmbeddedImageHtml($artifacts['streetview'], 'Street View'), 
            $html
        );
    }

    // === PARCEL MAPS ===
    if (!empty($artifacts['parcel_maps']) && is_array($artifacts['parcel_maps'])) {
        foreach ($artifacts['parcel_maps'] as $path) {
            if ($path) {
                $html = str_replace(
                    '[Parcel Aerial Image Placeholder]', 
                    getEmbeddedImageHtml($path, 'Parcel Aerial'), 
                    $html
                );
            }
        }
    }

    return $html;
}

function getEmbeddedImageHtml(string $imagePath, string $alt = 'Image'): string
{
    if (empty($imagePath) || !file_exists($imagePath)) {
        return '<div class="image-placeholder">📍 ' . htmlspecialchars($alt) . ' image not available yet</div>';
    }

    $mime = mime_content_type($imagePath) ?: 'image/jpeg';
    $data = base64_encode(file_get_contents($imagePath));
    $src = 'data:' . $mime . ';base64,' . $data;

    return '<div style="text-align:center; margin:12px 0;">
                <img src="' . $src . '" 
                     style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" 
                     alt="' . htmlspecialchars($alt) . '">
            </div>';
}

#endregion