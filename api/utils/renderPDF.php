<?php
#region File Header
// =====================================================================
//  Skyesoft™ Render v7.3.6 – PHP 5.6 Safe | True Center Footer Fix
//  Purpose: Unified Christy/Skyesoft Layout Engine (Legacy TCPDF)
// =====================================================================
#endregion

#region Render PDF Function
function renderPDF($title, $html, $meta = array(), $outputFile = null) {

    // ✅ Append audit provenance
    $meta['renderSessionId'] = uniqid('session_', true);
    $meta['generatorVersion'] = 'v7.3.1';
    $meta['timestamp'] = date('c');

    require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');

    // --- Custom subclass to suppress default TCPDF footer ---
    class SkyesoftPDF extends TCPDF {
        public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false) {
            parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache);
        }

        public function Footer() { /* Skyesoft: disable TCPDF auto-footer */ }
    }
#endregion

#region Meta and Paths
    // --- Meta and Paths ---
    $logoPath   = __DIR__ . '/../../assets/images/christyLogo.png';
    $hasLogo    = file_exists($logoPath);
    $generatedAt = isset($meta['generatedAt']) ? $meta['generatedAt'] : date('Y-m-d H:i:s');
    $author      = isset($meta['author']) ? $meta['author'] : 'Skyebot™ System Layer';
#endregion

#region Initialize PDF
    // --- Initialize PDF (narrow, symmetric margins - TIS style) ---
    $pdf = new SkyesoftPDF('P', 'mm', 'Letter', true, 'UTF-8', false);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetCreator('Skyesoft™ Report Generator');
    $pdf->SetAuthor($author);
    $pdf->SetTitle($title);

    // ✅ Use Codex-defined margins and layout (v7.3.2 unified)
    global $codex;

    if (!isset($codex) || !is_array($codex)) {
        // Fallback if renderPDF is called standalone
        $codex = file_exists(__DIR__ . '/../assets/data/codex.json')
            ? json_decode(file_get_contents(__DIR__ . '/../assets/data/codex.json'), true)
            : [];
    }

    $defaultMargins = ['header' => 80, 'body' => 50, 'footer' => 25];
    $margins = isset($codex['kpiData']['pdfMargins'])
        ? array_merge($defaultMargins, $codex['kpiData']['pdfMargins'])
        : $defaultMargins;

    // Log for debug trace
    if (function_exists('logReport')) {
        logReport("PDF Margins applied: header={$margins['header']}, body={$margins['body']}, footer={$margins['footer']}");
    }

    $pdf->SetMargins(15, $margins['header'] / 3, 15);
    $pdf->SetAutoPageBreak(true, $margins['footer'] / 3);
    $pdf->AddPage();
#endregion

#region Header Rendering
    // --- Header (raised to match footer symmetry) ----------------------
    $topMargin = 10;           // keep base margin
    $raise = -10;               // raise header upward by 5 mm for symmetry
    $headerY = $pdf->GetY() + $raise;

    if ($hasLogo) $pdf->Image($logoPath, 12, $headerY, 42, 0, 'PNG');

    // --- Icon Extraction from Title (robust version) ---
    $iconMapPath   = __DIR__ . '/../../assets/data/iconMap.json';
    $iconPathBase  = __DIR__ . '/../../assets/images/icons/';
    $iconFile      = null;
    $cleanTitle    = $title;
    $emoji         = null;

    // 1️⃣ Detect any emoji or symbol at start of title
    if (preg_match('/^([\p{So}\p{Sk}\x{FE0F}\x{1F300}-\x{1FAFF}]+)\s*(.*)$/u', $title, $m)) {
        $emoji      = trim($m[1]);
        $cleanTitle = trim($m[2]);
    }

    // 2️⃣ Attempt icon lookup
    if ($emoji && file_exists($iconMapPath)) {
        $map = json_decode(file_get_contents($iconMapPath), true);
        foreach ($map as $key => $icon) {
            if (isset($icon['icon']) && $icon['icon'] === $emoji) {
                $fileCandidate = $iconPathBase . $icon['file'];
                if (file_exists($fileCandidate)) {
                    $iconFile = $fileCandidate;
                    break;
                }
            }
        }
    }

    // 3️⃣ Draw icon (aligned tighter to title)
    if ($iconFile) {
        // Move icon slightly lower (y + 1) and closer (x + 1) for optical balance
        $pdf->Image($iconFile, 60, $headerY + 1, 8, 8, '', '', '', false, 300);
        $pdf->SetXY(70, $headerY + 0.5);
    } else {
        // Print emoji directly if no image found
        $pdf->SetXY(60, $headerY + 0.5);
        $pdf->SetFont('dejavusans', 'B', 15); // Unicode-safe font
        $pdf->Cell(7, 8, $emoji ? $emoji : '', 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetXY(68, $headerY + 0.5);
    }

    // Render the cleaned title
    $pdf->Cell(0, 8, strip_tags($cleanTitle), 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 10.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->SetX(60);

    // Determine type name for display (Information Sheet, Report, etc.)
    $docLabel = 'Skyesoft™ Report';
    if (isset($meta['path'])) {
        if (stripos($meta['path'], '/sheets/') !== false) {
            $docLabel = 'Skyesoft™ Information Sheet';
        } elseif (stripos($meta['path'], '/reports/') !== false) {
            $docLabel = 'Skyesoft™ Report';
        } elseif (stripos($meta['path'], '/doctrines/') !== false) {
            $docLabel = 'Skyesoft™ Doctrine Sheet';
        }
    }

    $pdf->Cell(0, 6, $docLabel, 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetX(60);
    $pdf->Cell(0, 5, 'Generated ' . date('F j, Y • g:i A', strtotime($generatedAt)), 0, 1, 'L');

    // Separator line just below header text block
    $currentY = $pdf->GetY() + 2;
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $currentY, 205, $currentY);
    $pdf->Ln(4);
    $pdf->SetTextColor(0, 0, 0);
#endregion

#region Body Rendering
    // --- Body ---------------------------------------------------------
    $bodyCSS = '
        <style>
            body { font-family: helvetica, Arial, sans-serif; font-size: 10.5pt; color: #111; line-height: 1.4; }
            h1, h2, h3 { color: #000; margin-top: 10pt; margin-bottom: 4pt; }
            h2 { font-size: 12.5pt; border-bottom: 0.5pt solid #999; padding-bottom: 3pt; margin-top: 14pt; }
            table { border-collapse: collapse; width: 100%; margin-top: 8pt; }
            th, td { border: 0.3pt solid #aaa; padding: 5pt; font-size: 9.5pt; }
            th { background-color: #f2f2f2; }
            ul { margin: 0 0 6pt 14pt; }
            li { margin-bottom: 3pt; }
            hr { margin: 10pt 0; color: #ccc; }
        </style>';

    $pdf->writeHTML($bodyCSS, false, false, true, false, '');

    // --- Smart Section Writer: Keeps section header + body together ---
    function writeSmartSection($pdf, $headerHTML, $bodyHTML) {
        // Begin a transaction so we can test layout
        $pdf->startTransaction();
        $yStart = $pdf->GetY();

        // Write header and body together temporarily
        $pdf->writeHTML($headerHTML, false, false, true, false, '');
        $pdf->writeHTML($bodyHTML, false, false, true, false, '');

        // Check if the section overflowed beyond the printable area
        $overflow = $pdf->GetY() > ($pdf->getPageHeight() - $pdf->getBreakMargin());

        if ($overflow) {
            // Undo the partial write
            $pdf = $pdf->rollbackTransaction(true);
            // Add a new page and rewrite together
            $pdf->AddPage();
            $pdf->writeHTML($headerHTML, false, false, true, false, '');
            $pdf->writeHTML($bodyHTML, false, false, true, false, '');
        } else {
            // Commit normally
            $pdf->commitTransaction();
        }
    }

    // Split sections by <h2> headers
    $sections = preg_split('/(<h2[^>]*>.*?<\/h2>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    for ($i = 0; $i < count($sections); $i += 2) {
        $headerHTML = isset($sections[$i]) && preg_match('/<h2/i', $sections[$i]) ? $sections[$i] : '';
        $bodyHTML   = isset($sections[$i + 1]) ? $sections[$i + 1] : '';

        if ($headerHTML === '' && $bodyHTML === '') continue;
        writeSmartSection($pdf, $headerHTML, $bodyHTML);
    }
#endregion

#region Footer Rendering
    // --- Footer (true visual symmetry) -----------------------------
    $footerText = '© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix AZ 85017 | (602) 242-4488 | christysigns.com | Page 1/1';

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);

    $footerY = $pdf->getPageHeight() - 8;    // lowers footer 2 mm for perfect symmetry
    $pdf->Line(10, $footerY - 2, 205, $footerY - 2);

    $pdf->SetXY(10, $footerY);
    $pdf->Cell(195, 6, $footerText, 0, 1, 'C', false);
#endregion

#region Output Handling
    // --- Output -------------------------------------------------------
    if (!$outputFile) {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower(strip_tags($title)));
        $outputFile = __DIR__ . '/../../docs/sheets/Information Sheet - ' . preg_replace('/_+/', ' ', ucwords($safeName)) . '.pdf';
    }

    $dir = dirname($outputFile);
    if (!is_dir($dir)) {
        $oldUmask = umask(0);
        mkdir($dir, 0777, true);
        umask($oldUmask);
    }

    $pdf->Output($outputFile, 'F');
    return $outputFile;
}
#endregion