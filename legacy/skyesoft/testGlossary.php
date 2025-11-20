<?php
// Dependencies
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');


// Load codex
$codex = json_decode(file_get_contents(__DIR__ . '/../assets/data/codex.json'), true);
$glossary = $codex['skyesoftConstitution']['glossary'];

// Extend TCPDF
class TestPDF extends TCPDF {
    public function Header() {}
    public function Footer() {}
}

$pdf = new TestPDF();
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);

// Render Glossary
if ($glossary['format'] === 'table') {
    $items = $glossary['items'];
    $headers = array_keys($items[0]);
    $pageWidth = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
    $colWidths = array($pageWidth * 0.3, $pageWidth * 0.7);

    // Header row
    $pdf->SetFillColor(0,0,0);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('helvetica','B',11);
    foreach ($headers as $i => $header) {
        $pdf->Cell($colWidths[$i], 8, ucfirst($header), 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('helvetica','',11);

    // Rows
    foreach ($items as $row) {
        $pdf->Cell($colWidths[0], 8, $row[$headers[0]], 1);
        $pdf->Cell($colWidths[1], 8, $row[$headers[1]], 1);
        $pdf->Ln();
    }
}

$pdf->Output(__DIR__ . '/../docs/sheets/Glossary-Test.pdf', 'F');
echo "✅ Glossary-Test.pdf created\n";
