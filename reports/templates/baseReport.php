<?php
declare(strict_types=1);
// =============================================
//  Skyesoft — baseReport.php
//  Universal PDF Renderer
//  Version: 1.4.6 (Section Padding Fixed)
//  Last Updated: 2026-07-07
// =============================================

#region SECTION 00 - Main Report Renderer
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}
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
    $subtitle = $report['reportSubtitle'] ?? 'Skyesoft Operational Intelligence';
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
                    <div style="font-size:9pt; color:#555;">' . htmlspecialchars($subtitle) . ' | Report Date: ' . date('m/d/y') . '</div>
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
            margin-top:14px; 
            margin-bottom:6px !important; 
            padding-bottom:0px;
            border-bottom:1.5px solid #888;
        }
        .sectionIconCell { 
            width:22px; 
            border:none; 
            padding:0 8px 2px 0; 
            vertical-align:middle; 
        }
        .sectionTitleCell { 
            border:none; 
            padding:0 0 2px 0; 
            vertical-align:middle; 
        }
        .sectionIcon { 
            width:17px; 
            height:17px; 
            object-fit:contain; 
            display:block; 
            margin:0;
        }
        .sectionTitle { 
            font-size:12pt; 
            font-weight:700; 
            color:#14377C; 
            line-height:1.0; 
            margin:0; 
            padding:0;
        }
        .dataTable { 
            width:100%; 
            border-collapse:collapse; 
            margin-top:2px !important; 
            margin-bottom:14px; 
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
            border: 1.5px solid #14377C; 
            border-radius: 8px; 
            padding: 16px 18px; 
            margin-bottom: 22px; 
            background: #ffffff; 
            page-break-inside: avoid;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .parcel-block .dataTable {
            margin-top: 2px !important;
            margin-bottom: 12px;
        }
        .parcel-block .dataTable th {
            background-color: #f3f4f6;
            color: #374151;
            font-weight: 600;
            width: 26%;
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            font-size: 10pt;
        }
        .parcel-block .dataTable td {
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            color: #1f2937;
            font-size: 10pt;
            line-height: 1.45;
        }
        .parcel-block .dataTable tr:nth-child(3) td,
        .parcel-block .dataTable tr:nth-child(4) td,
        .parcel-block .dataTable tr:nth-child(5) td {
            background-color: #f8fafc;
        }
        .parcelSummaryBlock {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 14px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
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
        .satellite-group,
        .parcelSummaryBlock,
        .dataTable,
        .parcel-block {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .section {
            page-break-inside: avoid;
            margin-top: 8px;
            margin-bottom: 12px;
        }
        .streetview-section {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
    ';
}
#endregion

#region SECTION 04 - Executive Summary
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
    $summaryText = $report['report'] ?? $report['reportSummary'] ?? '';
    if (!empty($summaryText)) {
        $mpdf->WriteHTML('<div style="margin-top:4px;">' . $summaryText . '</div>');
    } else {
        $mpdf->WriteHTML('<p style="margin-top:4px;">Proposal ready for review.</p>');
    }
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
    if (!empty($artifacts['staticMapUrl'])) {
        $mapHtml = '<div style="text-align:center; margin:15px 0;">
                <img src="' . htmlspecialchars($artifacts['staticMapUrl']) . '" 
                    style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;" 
                    alt="Satellite View">
            </div>';
        $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 0]', $mapHtml, $html);
        $html = str_replace('[ Satellite Image Placeholder - LC]', $mapHtml, $html);
        $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 1]', $mapHtml, $html);
        $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 2]', $mapHtml, $html);
        $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - 3]', $mapHtml, $html);
    } else {
        $placeholderHtml = '<div class="image-placeholder">📍 Satellite image not available yet</div>';
        $html = str_replace('[SATELLITE IMAGE PLACEHOLDER - Other]', $placeholderHtml, $html);
    }
    if (!empty($artifacts['streetview'])) {
        $html = str_replace(
            '[Street View Image will be inserted here by baseReport.php]', 
            getEmbeddedImageHtml($artifacts['streetview'], 'Street View'), 
            $html
        );
    }
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
    $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'png':
            $mime = 'image/png';
            break;
        case 'gif':
            $mime = 'image/gif';
            break;
        case 'webp':
            $mime = 'image/webp';
            break;
        case 'jpg':
        case 'jpeg':
        default:
            $mime = 'image/jpeg';
            break;
    }
    $data = base64_encode(file_get_contents($imagePath));
    $src = 'data:' . $mime . ';base64,' . $data;
    return '<div style="text-align:center; margin:12px 0;">
                <img src="' . $src . '"
                     style="max-width:100%; height:auto; border:1px solid #bbb; border-radius:6px;"
                     alt="' . htmlspecialchars($alt) . '">
            </div>';
}
#endregion