<?php
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');
require_once(__DIR__ . '/../libs/parsedown/Parsedown.php');

// --- Custom TCPDF class with header/footer ---
class ChristyPDF extends TCPDF {
    public function Header() {
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        if (file_exists($logo)) {
            $this->Image($logo, 10, 8, 40); // smaller logo for cleaner header
        }
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 15, 'Christy Signs – Project Report', 0, false, 'R');
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(
            0,
            10,
            "© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com | Page "
            . $this->getAliasNumPage() . "/" . $this->getAliasNbPages(),
            0,
            false,
            'C'
        );
    }
}

// --- Step 1: JSON input with icons + text ---
$json = '{
  "title":   { "icon": "📑", "text": "Project Report" },
  "description": { "icon": "📄", "text": "This is a test document with icons." },
  "author":  { "icon": "👷", "text": "Steve Skye" },
  "date":    { "icon": "📅", "text": "2025-09-09" },
  "status":  { "icon": "✅", "text": "Completed" },
  "priority":{ "icon": "🚨", "text": "High" },
  "summary": [
    { "icon": "✅", "text": "Task finished successfully" },
    { "icon": "📍", "text": "Saved to reports folder" },
    { "icon": "🎉", "text": "Ready for review" }
  ]
}';
$data = json_decode($json, true);

// --- Step 2: Load iconMap.json or fallback ---
function loadIconMap() {
    $iconMapPath = __DIR__ . "/../assets/data/iconMap.json";
    if (file_exists($iconMapPath)) {
        $map = json_decode(file_get_contents($iconMapPath), true);
        if ($map && is_array($map)) return $map;
    }
    return [ "📑"=>"notes.png","📄"=>"open_folder.png","👷"=>"workman.png","📅"=>"calendar.png",
             "✅"=>"integration.png","🚨"=>"flashing_light.png","📍"=>"pin.png","🎉"=>"holiday.png" ];
}
$iconMap = loadIconMap();
$iconBasePath = __DIR__ . "/../assets/images/icons/";

// --- Step 3: Render icons as PNG <img> tags ---
function renderIcon($emoji, $iconMap, $iconBasePath) {
    if (!$emoji || !isset($iconMap[$emoji])) return '';
    $file = $iconBasePath . $iconMap[$emoji];
    if (!file_exists($file)) return '';
    $imgData = base64_encode(file_get_contents($file));
    return '<img src="data:image/png;base64,' . $imgData . '" width="16" style="vertical-align:middle;"> ';
}

// --- Step 4: Build Markdown with icons ---
$markdown  = "# " . renderIcon($data['title']['icon'], $iconMap, $iconBasePath) . $data['title']['text'] . "\n\n";

$markdown .= renderIcon($data['description']['icon'], $iconMap, $iconBasePath) . "**Description:** {$data['description']['text']}\n\n";

$markdown .= "## Details\n";
$markdown .= "- **Author:** "   . renderIcon($data['author']['icon'], $iconMap, $iconBasePath) . $data['author']['text'] . "\n";
$markdown .= "- **Date:** "     . renderIcon($data['date']['icon'], $iconMap, $iconBasePath) . $data['date']['text'] . "\n";
$markdown .= "- **Status:** "   . renderIcon($data['status']['icon'], $iconMap, $iconBasePath) . $data['status']['text'] . "\n";
$markdown .= "- **Priority:** " . renderIcon($data['priority']['icon'], $iconMap, $iconBasePath) . $data['priority']['text'] . "\n\n";

$markdown .= "## Summary\n";
foreach ($data['summary'] as $item) {
    $markdown .= "- " . renderIcon($item['icon'], $iconMap, $iconBasePath) . $item['text'] . "\n";
}

// --- Step 5: Convert Markdown → HTML ---
$Parsedown = new Parsedown();
$html = $Parsedown->text($markdown);

// --- Step 6: Render PDF ---
$pdf = new ChristyPDF();
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 11);
$pdf->writeHTML($html, true, false, true, false, '');

// --- Step 7: Save as report.pdf ---
$savePath = __DIR__ . "/../docs/reports/report.pdf";
$pdf->Output($savePath, 'F');

echo "✅ PDF created successfully at: $savePath\n";
