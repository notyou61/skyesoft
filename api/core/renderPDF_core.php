<?php
// =====================================================================
//  Skyesoft™ Core PDF Renderer v1.0  |  PHP 5.6-Safe
//  (Implements Unified Header/Footer Frame – Parliamentarian Approved)
// =====================================================================

require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');

class SkyesoftPDF extends TCPDF {
    public $docTitle = '';
    public $docType  = 'Skyesoft™ Information Sheet';
    public $generatedAt = '';
    // Header
    public function Header() {
        $logo = __DIR__ . '/../../assets/images/christyLogo.png';
        $iconMapPath = __DIR__ . '/../../assets/data/iconMap.json';
        $iconBase = __DIR__ . '/../../assets/images/icons/';

        $this->SetY(10);

        // --- Left logo ---
        if (file_exists($logo)) {
            $this->Image($logo, 12, 10, 40, 0, 'PNG');
        }

        // --- Extract emoji and readable title ---
        $emoji = null;
        $cleanTitle = $this->docTitle;

        if (preg_match('/^([\p{So}\p{Sk}\x{FE0F}\x{1F300}-\x{1FAFF}]+)\s*(.*)$/u', $this->docTitle, $m)) {
            $emoji = trim($m[1]);
            $cleanTitle = trim($m[2]);
        }

        // --- Fallback: convert slug style to spaced title ---
        if (preg_match('/^[a-z]+([A-Z][a-z]+)/', $cleanTitle)) {
            $cleanTitle = trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $cleanTitle));
        }

        // --- Match emoji to iconMap entry ---
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

        // --- Title and icon ---
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(0, 0, 0);

        if ($iconFile) {
            $this->Image($iconFile, 60, 11.5, 8, 8, '', '', '', false, 300);
            $this->SetXY(70, 12);
        } else {
            $this->SetXY(60, 12);
        }

        $this->Cell(0, 7, $cleanTitle, 0, 1, 'L');

        // --- Sub-label ---
        $this->SetFont('helvetica', '', 9.5);
        $this->SetTextColor(90, 90, 90);
        $this->SetXY(60, 18);
        $this->Cell(0, 6, $this->docType, 0, 1, 'L');

        // --- Timestamp ---
        $this->SetFont('helvetica', '', 8.5);
        $this->SetTextColor(110, 110, 110);
        $this->SetXY(60, 23);
        $this->Cell(0, 5, 'Generated ' . date('F j, Y • g:i A', strtotime($this->generatedAt)), 0, 1, 'L');

        // --- Divider ---
        $this->SetLineWidth(0.4);
        $this->Line(10, 30, 205, 30);
        $this->Ln(5);
    }
    // Footer
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(80, 80, 80);
        $this->Line(10, $this->GetY(), 205, $this->GetY());
        $this->Ln(2);
        $this->Cell(0, 6,
            '© Christy Signs / Skyesoft • All Rights Reserved | (602) 242-4488 | christysigns.com',
            0, 1, 'C');
        $this->Cell(0, 6,
            'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(),
            0, 0, 'R');
    }
}
// Main render function
function renderPDF($title, $html, $meta = array(), $outputFile = null) {

    $pdf = new SkyesoftPDF('P', 'mm', 'Letter', true, 'UTF-8', false);
    $pdf->docTitle     = $title;
    $pdf->generatedAt  = isset($meta['generatedAt']) ? $meta['generatedAt'] : date('Y-m-d H:i:s');
    $pdf->docType      = 'Skyesoft™ Information Sheet';

    // Standardized margins for Core v1.0
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetHeaderMargin(8);
    $pdf->SetFooterMargin(12);
    $pdf->SetAutoPageBreak(true, 20);

    $pdf->SetCreator('Skyesoft™ Codex Engine');
    $pdf->SetAuthor(isset($meta['author']) ? $meta['author'] : 'Skyebot™ System Layer');
    $pdf->SetTitle($title);

    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10.5);
    $pdf->writeHTML($html, true, false, true, false, '');

    if (!$outputFile) {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(strip_tags($title)));
        $outputFile = __DIR__ . '/../../docs/sheets/Information_Sheet_' . $safeName . '.pdf';
    }
    if (!is_dir(dirname($outputFile))) {
        $old = umask(0); mkdir(dirname($outputFile), 0777, true); umask($old);
    }

    $pdf->Output($outputFile, 'F');
    return $outputFile;
}