<?php
// =====================================================================
//  Skyesoft™ Render v7.3.7 – Codex-Compliant | Shared Stylesheet Enabled
// =====================================================================

function renderPDF($title, $html, $meta = array(), $outputFile = null) {

    require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');

    // --- Custom subclass to suppress default TCPDF footer ---
    class SkyesoftPDF extends TCPDF {
        public function Footer() { /* Skyesoft: disable TCPDF auto-footer */ }
    }

    // --- Meta and Paths ---
    $logoPath    = __DIR__ . '/../../assets/images/christyLogo.png';
    $iconMapPath = __DIR__ . '/../../assets/data/iconMap.json';
    $iconBase    = __DIR__ . '/../../assets/images/icons/';
    $generatedAt = isset($meta['generatedAt']) ? $meta['generatedAt'] : date('Y-m-d H:i:s');
    $author      = isset($meta['author']) ? $meta['author'] : 'Skyebot™ System Layer';

    // --- Initialize PDF (narrow symmetric margins) ---
    $pdf = new SkyesoftPDF('P', 'mm', 'Letter', true, 'UTF-8', false);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetCreator('Skyesoft™ Report Generator');
    $pdf->SetAuthor($author);
    $pdf->SetTitle($title);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false, 10);
    $pdf->AddPage();

    // --- Header Rendering ---
    $headerY = $pdf->GetY() - 10;
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 12, $headerY, 42, 0, 'PNG');
    }

    // Extract icon (+ strip emoji from title)
    $emoji = null;
    $cleanTitle = $title;
    if (preg_match('/^([\p{So}\p{Sk}\x{FE0F}\x{1F300}-\x{1FAFF}]+)\s*(.*)$/u', $title, $m)) {
        $emoji = trim($m[1]);
        $cleanTitle = trim($m[2]);
    }

    $iconFile = null;
    if ($emoji && file_exists($iconMapPath)) {
        $map = json_decode(file_get_contents($iconMapPath), true);
        foreach ($map as $node) {
            if (isset($node['icon']) && $node['icon'] === $emoji) {
                $candidate = $iconBase . $node['file'];
                if (file_exists($candidate)) {
                    $iconFile = $candidate;
                    break;
                }
            }
        }
    }

    if ($iconFile) {
        $pdf->Image($iconFile, 60, $headerY + 1, 8, 8, '', '', '', false, 300);
        $pdf->SetXY(70, $headerY + 0.5);
    } else {
        $pdf->SetXY(60, $headerY + 0.5);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(7, 8, $emoji ? $emoji : '', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetXY(68, $headerY + 0.5);
    }

    $pdf->Cell(0, 8, strip_tags($cleanTitle), 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetX(60);

    $docLabel = 'Skyesoft™ Information Sheet';
    if (isset($meta['path'])) {
        if (stripos($meta['path'], '/reports/') !== false) $docLabel = 'Skyesoft™ Report';
        if (stripos($meta['path'], '/doctrines/') !== false) $docLabel = 'Skyesoft™ Doctrine Sheet';
    }

    $pdf->Cell(0, 6, $docLabel, 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetX(60);
    $pdf->Cell(0, 5, 'Generated ' . date('F j, Y • g:i A', strtotime($generatedAt)), 0, 1, 'L');

    $currentY = $pdf->GetY() + 2;
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $currentY, 205, $currentY);
    $pdf->Ln(4);
    $pdf->SetTextColor(0, 0, 0);

    // ✅ Apply Shared Stylesheet (Unified HTML + PDF)
    $cssPath = __DIR__ . '/../../assets/styles/reportBase.css';
    if (file_exists($cssPath)) {
        $pdf->writeHTML('<style>' . file_get_contents($cssPath) . '</style>', false, false, true, false, '');
    }

    // ✅ Render Body HTML (force full re-parse)
    $pdf->writeHTMLCell(
        0, 0, '', '', 
        $html,
        0, 1, 0, 
        true, '', true
    );

    // --- Footer ---
    $footerText = '© Christy Signs / Skyesoft • All Rights Reserved | (602) 242-4488 | christysigns.com';
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(80, 80, 80);
    $footerY = $pdf->getPageHeight() - 8;
    $pdf->Line(10, $footerY - 2, 205, $footerY - 2);
    $pdf->SetXY(10, $footerY);
    $pdf->Cell(195, 6, $footerText, 0, 1, 'C', false);

    // --- Save Output ---
    if (!$outputFile) {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(strip_tags($title)));
        $outputFile = __DIR__ . '/../../docs/sheets/Information Sheet - ' . preg_replace('/_+/', ' ', ucwords($safeName)) . '.pdf';
    }

    if (!is_dir(dirname($outputFile))) {
        $oldUmask = umask(0);
        mkdir(dirname($outputFile), 0777, true);
        umask($oldUmask);
    }

    $pdf->Output($outputFile, 'F');
    return $outputFile;
}
