<?php
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');

class ChristyPDF extends TCPDF {
    private $reportTitle;

    public function setReportTitle($title) {
        $this->reportTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
    }

    // --- Custom Header ---
    public function Header() {
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        $logo_height = 0;
        if (file_exists($logo)) {
            list($pix_w, $pix_h) = getimagesize($logo);
            $logo_width = 35;
            if ($pix_w > 0) {
                $logo_height = $logo_width * ($pix_h / $pix_w);
            }
            $this->Image($logo, 15, 10, $logo_width); // logo at x=15, y=10, width=35mm
        }

        // Calculate startY so text block is centered vertically with logo
        $text_height = 8 + 6 + 6; // Total height of the three cells
        $logo_center_y = 10 + ($logo_height / 2);
        $startY = $logo_center_y - ($text_height / 2);

        // Main Title
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(55, $startY);
        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report Title', 0, 1, 'L');

        // Title Tag
        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(55);
        $this->Cell(0, 6, 'Skyesoft Information Sheet', 0, 1, 'L');

        // Metadata
        $this->SetFont('helvetica', '', 9);
        $this->SetX(55);
        $this->Cell(0, 6, date('F j, Y') . ' – Created by Skyesoft', 0, 1, 'L');

        // Calculate divider position dynamically
        $text_bottom = $this->GetY();
        $logo_bottom = 10 + $logo_height;
        $divider_y = max($text_bottom, $logo_bottom) + 2; // 2mm padding below the higher element

        // Divider line under header (logo+text)
        $this->Line(15, $divider_y, 195, $divider_y);
    }

    // --- Custom Footer ---
    public function Footer() {
        $this->SetY(-20);
        $this->Line(15, $this->GetY(), 195, $this->GetY()); // divider line

        $this->SetFont('helvetica', '', 8);

        $footerText = "© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com"
                    . " | Page " . $this->getAliasNumPage() . "/" . $this->getAliasNbPages();

        $this->Cell(0, 10, $footerText, 0, 0, 'C');
    }
}

// --- Create PDF ---
$pdf = new ChristyPDF();
$pdf->setReportTitle("Project Report Title");

// Calculate header height dynamically for body start
$logo = __DIR__ . '/../assets/images/christyLogo.png';
$logo_height = 0;
if (file_exists($logo)) {
    list($pix_w, $pix_h) = getimagesize($logo);
    $logo_width = 35;
    if ($pix_w > 0) {
        $logo_height = $logo_width * ($pix_h / $pix_w);
    }
}
$text_height = 8 + 6 + 6;
$logo_center_y = 10 + ($logo_height / 2);
$startY = $logo_center_y - ($text_height / 2);
$text_bottom = $startY + $text_height;
$logo_bottom = 10 + $logo_height;
$max_bottom = max($text_bottom, $logo_bottom);
$divider_y = $max_bottom + 2; // matches Header calculation
$body_start_y = $divider_y + 3; // 3mm gap below divider for content start; adjust this parameter as needed

// Footer parameters (fixed in this case)
$footer_start = 20; // Footer starts 20mm from bottom
$body_end_margin = $footer_start + 5; // 5mm gap above footer divider; adjust this parameter as needed

// Set margins: top = body_start_y (for content start), bottom via AutoPageBreak (for content end)
$pdf->SetMargins(15, $body_start_y, 15);
$pdf->SetAutoPageBreak(true, $body_end_margin);

$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);

// Test content with enough lines to force page wrapping (adjust num_lines for more/less)
$num_lines = 60; // This should create ~2-3 pages; increase to 100+ for more
$test_content = "Test body content. Footer should now appear on page 1.\n\nAdding more lines...\n";
for ($i = 0; $i < $num_lines; $i++) {
    $test_content .= "This is line " . ($i + 1) . " of filler content to test page wrapping. Each line adds to the body until it overflows to a new page with header/footer.\n";
}
$test_content .= "\n\nDone.";

$pdf->Write(0, $test_content);

// --- Save file ---
$savePath = __DIR__ . "/../docs/reports/project_report.pdf";
$pdf->Output($savePath, 'F');

echo "✅ PDF created successfully at: $savePath\n";
echo "Run this and check the output PDF—it should now have multiple pages with wrapping content!";
?>