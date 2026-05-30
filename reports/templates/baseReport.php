<?php
declare(strict_types=1);

// =============================================
//  Skyesoft — baseReport.php
//  Universal PDF Renderer
//  Version: 1.3.0
//  Last Updated: 2026-05-30
// =============================================

#region SECTION 00 - Main Report Renderer

require_once __DIR__ . '/../../vendor/autoload.php';

use Mpdf\Mpdf;

/**
 * Renders a standardized report object into a professional PDF.
 * Includes full artifact image embedding and placeholder replacement.
 */
function renderReport(array $report): string
{
    $mpdf = new Mpdf([
        'format'        => 'Letter',
        'margin_left'   => 12,
        'margin_right'  => 12,
        'margin_top'    => 28,
        'margin_bottom' => 24,
        'margin_header' => 8,
        'margin_footer' => 8,
    ]);

    // Set header and footer
    $mpdf->SetHTMLHeader(buildReportHeader($report));
    $mpdf->SetHTMLFooter(buildReportFooter());

    // Apply global stylesheet
    $mpdf->WriteHTML(buildReportStyles(), \Mpdf\HTMLParserMode::HEADER_CSS);

    // Generate Executive Summary (Page 1)
    generateExecutiveSummary($mpdf, $report);

    // Process artifacts (replace placeholders with real images)
    $processedBodyHtml = processReportArtifacts(
        $report['reportBodyHtml'] ?? '', 
        $report['reportArtifacts'] ?? []
    );

    // Render main body content
    generateMainBody($mpdf, $processedBodyHtml);

    return $mpdf->Output('', 'S');
}

#endregion

#region SECTION 01 - Header Builder

/**
 * Build professional report header with branding (matches test_mpdf.php).
 */
function buildReportHeader(array $report): string
{
    return '
    <div style="border-bottom: 3px solid #14377C; padding-bottom: 8px;">
        <table style="width:100%; border:none;">
            <tr>
                <td style="width:78px; padding-right:10px; vertical-align:middle;">
                    <img src="https://skyelighting.com/skyesoft/assets/images/christyLogo.png" 
                         style="width:72px; height:auto;" alt="Christy Signs">
                </td>
                <td>
                    <div style="font-size:14pt; font-weight:700; color:#14377C;">' 
                        . htmlspecialchars($report['reportTitle'] ?? 'Skyesoft Report') . 
                    '</div>
                    <div style="font-size:9pt; color:#555;">Skyesoft Operational Intelligence</div>
                </td>
            </tr>
        </table>
    </div>';
}

#endregion

#region SECTION 02 - Footer Builder

/**
 * Build consistent report footer with page numbering.
 */
function buildReportFooter(): string
{
    return '
    <div style="border-top: 3px solid #14377C; padding-top: 5px; font-size:7.5pt; color:#555; text-align:center;">
        <div style="font-weight:600;">Christy Signs &nbsp;|&nbsp; Phoenix, Arizona &nbsp;|&nbsp; Confidential Internal Document</div>
        <div style="font-size:7pt; color:#666;">© 2026 Christy Signs — Page {PAGENO} of {nbpg}</div>
    </div>';
}

#endregion

#region SECTION 03 - Stylesheet

/**
 * Return comprehensive CSS matching test_mpdf.php styling.
 */
function buildReportStyles(): string
{
    return '
        body { 
            font-family: Helvetica, Arial, sans-serif; 
            font-size: 11pt; 
            color: #222; 
            line-height: 1.35; 
        }
        h1, h2 { color: #14377C; }
        
        .sectionHeaderTable { 
            width:100%; 
            border-collapse:collapse; 
            margin:8px 0 6px 0; 
        }
        .sectionIconCell { width:28px; padding-right:8px; vertical-align:middle; }
        .sectionTitleCell { vertical-align:middle; }
        .sectionIcon { width:20px; height:20px; }
        .sectionTitle { 
            font-size:13pt; 
            font-weight:700; 
            color:#14377C; 
        }
        
        .dataTable { 
            width:100%; 
            border-collapse:collapse; 
            margin:6px 0 12px 0; 
        }
        .dataTable th, .dataTable td { 
            border:1px solid #ccc; 
            padding:6px 8px; 
            text-align:left; 
            vertical-align:top; 
        }
        .dataTable th { 
            background:#e8e8e8; 
            width:28%; 
            font-weight:700; 
            color:#333; 
        }
        
        .highlight {
            background:#f0f7ff; 
            border-left:5px solid #14377C; 
            padding:12px 14px; 
            margin:10px 0; 
            border-radius:4px;
        }
        
        .parcel-block {
            border: 2px solid #14377C;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 20px;
            background: #fafafa;
            page-break-inside: avoid;
        }
        
        .summaryNarrative {
            background: #f8f8f8;
            border: 1px solid #d0d0d0;
            padding: 16px;
            border-radius: 6px;
            font-size: 10.4pt;
            line-height: 1.55;
            margin-bottom: 12px;
        }
        
        .parcelSummaryBlock {
            background: #f8f9fa;
            border: 1px solid #d0d0d0;
            border-left: 4px solid #14377C;
            padding: 12px 14px;
            margin: 10px 0 16px 0;
            border-radius: 4px;
        }
        
        .summaryMetaTable {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
        }
        .summaryMetaTable td {
            padding: 8px 10px;
            border: 1px solid #ddd;
            background: #f0f0f0;
            text-align: center;
            font-weight: 500;
        }

        .image-placeholder {
            border: 2px solid #14377C;
            border-radius: 8px;
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            min-height: 260px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    ';
}

#endregion

#region SECTION 04 - Executive Summary

/**
 * Generate the first page executive summary.
 */
function generateExecutiveSummary(Mpdf $mpdf, array $report): void
{
    $mpdf->WriteHTML('
        <div class="section">
            <table class="sectionHeaderTable">
                <tr>
                    <td class="sectionIconCell"><img src="https://skyelighting.com/skyesoft/assets/images/icons/clipboard.png" class="sectionIcon"></td>
                    <td class="sectionTitleCell"><div class="sectionTitle">Report Summary</div></td>
                </tr>
            </table>
    ');

    $mpdf->WriteHTML($report['reportSummary'] ?? '<p>No summary available.</p>');

    $mpdf->WriteHTML('</div>');

    $mpdf->AddPage();
}

#endregion

#region SECTION 05 - Main Body Generator

/**
 * Render the processed main body content.
 */
function generateMainBody(Mpdf $mpdf, string $bodyHtml): void
{
    $mpdf->WriteHTML($bodyHtml);
}

#endregion

#region SECTION 06 - Artifact Image Processor

/**
 * Process reportBodyHtml and replace image placeholders with real artifacts.
 * Compatible with placeholders generated by contactProposalReport.php.
 */
function processReportArtifacts(string $html, array $artifacts): string
{
    if (empty($artifacts)) {
        return $html;
    }

    // Satellite
    if (!empty($artifacts['satellite'])) {
        $imgHtml = getEmbeddedImageHtml($artifacts['satellite'], 'Satellite View');
        $html = str_replace('[Satellite Image will be inserted here by baseReport.php]', $imgHtml, $html);
    }

    // Street View
    if (!empty($artifacts['streetview'])) {
        $imgHtml = getEmbeddedImageHtml($artifacts['streetview'], 'Street View');
        $html = str_replace('[Street View Image will be inserted here by baseReport.php]', $imgHtml, $html);
    }

    // Parcel images (generic fallback)
    if (!empty($artifacts['parcel_maps']) && is_array($artifacts['parcel_maps'])) {
        foreach ($artifacts['parcel_maps'] as $index => $imagePath) {
            if ($imagePath) {
                $imgHtml = getEmbeddedImageHtml($imagePath, 'Parcel ' . ($index + 1));
                $html = str_replace('[Parcel Aerial Image Placeholder]', $imgHtml, $html);
            }
        }
    }

    return $html;
}

#endregion

#region SECTION 07 - Image Embedding Helper

/**
 * Convert a local image path to base64 embedded HTML for mPDF compatibility.
 */
function getEmbeddedImageHtml(string $imagePath, string $alt = 'Image'): string
{
    if (!file_exists($imagePath)) {
        return '<div class="image-placeholder" style="color:#c00;">'
             . 'Image not found: ' . htmlspecialchars(basename($imagePath))
             . '</div>';
    }

    $mimeType = mime_content_type($imagePath) ?: 'image/png';
    $imageData = base64_encode(file_get_contents($imagePath));
    $base64Src = 'data:' . $mimeType . ';base64,' . $imageData;

    return '
    <div style="text-align:center; margin:12px 0;">
        <img src="' . $base64Src . '" 
             style="max-width:100%; height:auto; border:1px solid #ccc; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.1);"
             alt="' . htmlspecialchars($alt) . '">
    </div>';
}

#endregion

#region SECTION 08 - Future Enhancements

/**
 * Placeholder for advanced artifact handling (reserved for future use).
 */
function handleReportArtifacts(Mpdf $mpdf, array $report): void
{
    // Currently handled in processReportArtifacts()
    // Kept for potential future extensions (e.g. dynamic page insertion)
}

#endregion