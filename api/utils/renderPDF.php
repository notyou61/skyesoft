<?php
function renderTemporalIntegrityReport($html, $outputFile, $title) {
    if (!class_exists('TCPDF')) {
        require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');
    }

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Skyesoft Report Generator');
    $pdf->SetAuthor('Skyebot™');
    $pdf->SetTitle($title);
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Header
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, '🕰️ ' . $title, 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell(0, 8, date('l, F j, Y • g:i A'), 0, 'C');
    $pdf->Ln(8);

    // Body
    $pdf->SetFont('helvetica', '', 11);
    $pdf->writeHTML($html, true, false, true, false, '');

    // Footer
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(
        0,
        10,
        '© Christy Signs / Skyesoft • Codex v5.5.1 • Validated ' . date('Y-m-d H:i:s'),
        0,
        0,
        'C'
    );

    $pdf->Output($outputFile, 'F');
}