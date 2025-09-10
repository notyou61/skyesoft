<?php
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');

// --- Custom TCPDF class with header and footer ---
class ChristyPDF extends TCPDF {
    public function Header() {
        // Path to your logo
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        if (file_exists($logo)) {
            $this->Image($logo, 10, 8, 50); // x=10mm, y=8mm, width=50mm
        }
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 15, 'Christy Signs – Project Report', 0, false, 'R', 0, '', 0, false, 'M', 'M');
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(
            0,
            10,
            "© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com | Page ".$this->getAliasNumPage()."/".$this->getAliasNbPages(),
            0,
            false,
            'C'
        );
    }
}

// --- Step 1: JSON input ---
$json = '{
  "title":"📑 Project Report",
  "description":"This is a test document with icons.",
  "author":"👷 Steve Skye",
  "date":"📅 2025-09-09",
  "status":"✅ Completed",
  "priority":"🚨 High"
}';
$data = json_decode($json, true);

// --- Step 2: Convert to Markdown ---
$markdown  = "# {$data['title']}\n\n";
$markdown .= "📑 **Description:** {$data['description']}\n\n";
$markdown .= "**Author:** {$data['author']}  \n";
$markdown .= "**Date:** {$data['date']}  \n";
$markdown .= "**Status:** {$data['status']}  \n";
$markdown .= "**Priority:** {$data['priority']}  \n";
$markdown .= "\n---\n";
$markdown .= "### 📑 Summary\n";
$markdown .= "- ✅ Task finished successfully\n";
$markdown .= "- 📍 Saved to reports folder\n";
$markdown .= "- 🎉 Ready for review\n";

// --- Step 2b: Replace emoji with icons (embed as base64) ---
function replaceIcons($markdown) {
    $iconPath = __DIR__ . "/../assets/images/icons/";
    $mapPath  = __DIR__ . "/../assets/data/iconMap.json";

    if (!file_exists($mapPath)) return $markdown;

    $map = json_decode(file_get_contents($mapPath), true);
    if (!$map) return $markdown;

    foreach ($map as $emoji => $filename) {
        $file = $iconPath . $filename;
        if (file_exists($file)) {
            $imgData = base64_encode(file_get_contents($file));
            $imgTag  = '<img src="data:image/png;base64,'.$imgData.'" width="16" style="vertical-align:middle;">';
            $markdown = str_replace($emoji, $imgTag, $markdown);
        }
    }
    return $markdown;
}
$markdown = replaceIcons($markdown);

// --- Step 3: Markdown → HTML ---
require_once(__DIR__ . '/../libs/parsedown/Parsedown.php');
$Parsedown = new Parsedown();
$Parsedown->setMarkupEscaped(false);
$html = $Parsedown->text($markdown);

// --- Step 4: HTML → PDF ---
$pdf = new ChristyPDF();
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);
$pdf->writeHTML($html, true, false, true, false, '');

// --- Step 5: Save report ---
$filename = "report_" . date("Y-m-d_H-i-s") . ".pdf";
$savePath = __DIR__ . "/../docs/reports/" . $filename;
$pdf->Output($savePath, 'F');

echo "✅ PDF created successfully at: $savePath";
