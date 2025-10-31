<?php
// =====================================================================
//  Skyesoft™ Render v7.3.1 – Unified Christy/Skyesoft PDF Layout Engine
//  PHP 5.6 Compatible | Works with GoDaddy & Docker | GD Ready
//  Layout fidelity: optimized for Information Sheets (TIS-style)
// =====================================================================

function renderPDF($title, $html, $meta = array(), $outputFile = null) {

    // ---------------------------------------------------------
    // ⚙️ Dependencies
    // ---------------------------------------------------------
    if (!class_exists('TCPDF')) {
        require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');
    }

    // ---------------------------------------------------------
    // 🧩 Meta + Paths
    // ---------------------------------------------------------
    $logoPath = __DIR__ . '/../../assets/images/christyLogo.png';
    $hasLogo  = file_exists($logoPath);

    $generatedAt = isset($meta['generatedAt']) ? $meta['generatedAt'] : date('Y-m-d H:i:s');
    $author      = isset($meta['author']) ? $meta['author'] : 'Skyebot™ System Layer';
    $source      = isset($meta['source']) ? $meta['source'] : 'Codex v7.3.1';

    // Dynamic subtitle selection
    $subtitle = 'Skyesoft™ Document';
    $tLower = strtolower($title);
    if (strpos($tLower, 'standard') !== false || strpos($tLower, 'spec') !== false)
        $subtitle = 'Skyesoft™ Information Sheet';
    elseif (strpos($tLower, 'report') !== false)
        $subtitle = 'Skyesoft™ Report';
    elseif (strpos($tLower, 'survey') !== false || strpos($tLower, 'inspection') !== false)
        $subtitle = 'Skyesoft™ Survey Report';
    elseif (strpos($tLower, 'constitution') !== false || strpos($tLower, 'doctrine') !== false)
        $subtitle = 'Skyesoft™ Doctrine Sheet';

    // ---------------------------------------------------------
    // 🧭 Initialize TCPDF
    // ---------------------------------------------------------
    $pdf = new TCPDF('P', 'mm', 'Letter', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('Skyesoft™ Report Generator');
    $pdf->SetAuthor($author);
    $pdf->SetTitle($title);
    $pdf->SetMargins(20, 30, 20);

    // ❌ Disable automatic page breaking
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddPage();

    // ---------------------------------------------------------
    // 🏗️ Header
    // ---------------------------------------------------------
    if ($hasLogo) {
        $pdf->Image($logoPath, 17, 15, 34, 0, 'PNG');
    }

    // Title block
    $pdf->SetXY(60, 15);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 9, strip_tags($title), 0, 1, 'L', false);

    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetX(60);
    $pdf->Cell(0, 6, $subtitle, 0, 1, 'L', false);

    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetFont('helvetica', '', 9.5);
    $pdf->SetX(60);
    $pdf->Cell(0, 5, 'Generated ' . date('F j, Y • g:i A', strtotime($generatedAt)), 0, 1, 'L');

    // Header divider line (bold)
    $pdf->SetLineWidth(0.6);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Line(17, 39, 200, 39);
    $pdf->Ln(6);
    $pdf->SetTextColor(0, 0, 0);

    // ---------------------------------------------------------
    // 📄 Body
    // ---------------------------------------------------------
    $bodyCSS = '
        <style>
            body { font-family: helvetica, Arial, sans-serif; font-size: 10.5pt; color: #111; line-height: 1.4; }
            h1, h2, h3 { color: #000; margin-top: 10pt; margin-bottom: 4pt; }
            h2 { font-size: 12.5pt; border-top: 0.5pt solid #999; padding-top: 5pt; }
            h3 { font-size: 11pt; color: #333; }
            table { border-collapse: collapse; width: 100%; margin-top: 8pt; }
            th, td { border: 0.3pt solid #aaa; padding: 5pt; font-size: 9.5pt; }
            th { background-color: #f2f2f2; }
            ul { margin: 0 0 6pt 14pt; }
            li { margin-bottom: 3pt; }
            hr { margin: 10pt 0; color: #ccc; }
        </style>
    ';

    // Render content — no auto pagination
    $pdf->writeHTML($bodyCSS . $html, false, false, true, false, '');
    $pdf->Ln(2);

    // Prevent content from touching footer
    if ($pdf->GetY() > 240) {
        $pdf->SetY(240);
    }

    // ---------------------------------------------------------
    // 🦶 Footer (precisely centered, TIS-style)
    // ---------------------------------------------------------
    if ($pdf->GetY() > 245) {
        $pdf->SetY(245);
    } else {
        $pdf->SetY(-18);
    }

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);

    // Draw footer separator line
    $pdf->Line(17, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);

    // Footer text (measure & absolute center)
    $footerText = '© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com | Page ' .
        $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages();

    $pageWidth  = $pdf->getPageWidth();
    $textWidth  = $pdf->GetStringWidth($footerText, 'helvetica', '', 8);
    $xPosition  = ($pageWidth - $textWidth) / 2;

    $pdf->SetXY($xPosition, $pdf->GetY());
    $pdf->Cell($textWidth, 6, $footerText, 0, 1, 'C', false);

    // Fully suppress TCPDF footer branding
    $pdf->setPrintFooter(false);
    $pdf->SetFooterMargin(0);
    $pdf->setFooterData(array(255,255,255), array(255,255,255));

    // ---------------------------------------------------------
    // 💾 Output
    // ---------------------------------------------------------
    if (!$outputFile) {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($title));
        $outputFile = __DIR__ . '/../../docs/reports/' . $safeName . '_' . date('Ymd_His') . '.pdf';
    }

    $pdf->Output($outputFile, 'F');
    return $outputFile;
}