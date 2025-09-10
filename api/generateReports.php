<?php
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');

class ChristyPDF extends TCPDF {
    private $reportTitle;

    public function setReportTitle($title) {
        $this->reportTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
    }

    // --- Custom Header ---
    public function Header() {
        $logo = __DIR__ . '/../assets/images/christyLogo.jpg';
        if (file_exists($logo)) {
            // Rolled back logo: 35mm wide
            $this->Image($logo, 15, 10, 35);
        }
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(15, 15);
        $this->Cell(0, 10, $this->reportTitle ?: 'Project Report', 0, 0, 'R');
        // Divider line adjusted
        $this->Line(15, 34, 195, 34);
    }

    // --- Custom Footer ---
    public function Footer() {
        $this->SetY(-20);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10,
            "© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com",
            0, 1, 'C');
        $this->Cell(0, 10,
            "Page ".$this->getAliasNumPage()."/".$this->getAliasNbPages(),
            0, 0, 'C');
    }
}

// --- Create PDF ---
$pdf = new ChristyPDF();
$pdf->setReportTitle("Project Report");

// Margins: top=45mm (for header), bottom=25mm (for footer space)
$pdf->SetMargins(15, 45, 15);
$pdf->SetAutoPageBreak(true, 25);

$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);
$pdf->Write(0, "Test body content. Footer should now appear on page 1.\n\nAdding more lines...\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\n\nDone.");

// --- Save file ---
$savePath = __DIR__ . "/../docs/reports/project_report.pdf";
$pdf->Output($savePath, 'F');

echo "✅ PDF created successfully at: $savePath";
