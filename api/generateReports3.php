<?php
require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';

// Load icon map
$iconMapPath = __DIR__ . "/../assets/data/iconMap.json";
$iconMap = json_decode(@file_get_contents($iconMapPath), true) ?: [];
$iconBasePath = rtrim(realpath(__DIR__ . "/../assets/images/icons/"), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

// Load Codex
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codex = json_decode(@file_get_contents($codexPath), true) ?: [];

class ChristyPDF extends TCPDF {
    private $reportTitle;
    private $reportIcon;
    private $currentSectionHeader = null;
    private $currentSectionIcon = null;

    public function setReportTitle($title, $emoji = null) {
        $this->reportTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
        $this->reportIcon = $emoji;
    }
    public function setCurrentSection($label, $icon = null) {
        $this->currentSectionHeader = $label;
        $this->currentSectionIcon   = $icon;
    }

    public function Header() {
        global $iconMap, $iconBasePath;
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        if (is_string($logo) && file_exists($logo)) { $this->Image($logo, 15, 10, 35); }

        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(55, 12);
        $iconFile = resolveIconFile($this->reportIcon, $iconMap, $iconBasePath);
        if ($iconFile) { $this->Image($iconFile, 55, 12, 7); $this->SetX(65); }
        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report', 0, 1, 'L');

        $this->SetFont('helvetica', 'I', 10); $this->SetX(55);
        $this->Cell(0, 6, 'Skyesoft™ Information Sheet', 0, 1, 'L');

        $this->SetFont('helvetica', '', 9); $this->SetX(55);
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by Skyesoft™', 0, 1, 'L');

        $this->Ln(2); $this->SetDrawColor(0,0,0); $this->Line(15, $this->GetY(), 195, $this->GetY());
    }

    public function Footer() {
        $this->SetY(-20); $this->SetDrawColor(0,0,0);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetFont('helvetica','',8);
        $footerText = "© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com | Page ".
                      $this->getAliasNumPage()."/".$this->getAliasNbPages();
        $this->Cell(0,6,$footerText,0,1,'C');
    }

    // Continuation headers on page breaks
    public function AcceptPageBreak() {
        $this->AddPage();
        if ($this->currentSectionHeader) {
            global $iconMap, $iconBasePath;
            $continued = $this->currentSectionHeader . " – Continued";
            headerWithMappedIcon($this, $continued, $this->currentSectionIcon, $iconMap, $iconBasePath, 1);
        }
        return false;
    }
}

// --- Helpers ---
function normalizeEmoji($str) { return preg_replace('/\x{FE0F}|\x{200D}/u', '', (string)$str); }

function iconMapValueToPath($val, $iconBasePath) {
    if (is_string($val)) {
        $candidates = [$val, $iconBasePath . ltrim($val, DIRECTORY_SEPARATOR), $iconBasePath . basename($val)];
    } elseif (is_array($val) && isset($val['file'])) {
        $candidates = [$val['file'], $iconBasePath . ltrim($val['file'], DIRECTORY_SEPARATOR), $iconBasePath . basename($val['file'])];
    } else return null;
    foreach ($candidates as $c) { if (is_string($c) && file_exists($c)) return $c; }
    return null;
}
function resolveIconFile($emoji, $iconMap, $iconBasePath) {
    if (!$emoji) return null;
    $key = normalizeEmoji($emoji);
    if (!isset($iconMap[$key])) return null;
    return iconMapValueToPath($iconMap[$key], $iconBasePath);
}
function headerWithMappedIcon($pdf, $title, $emoji, $iconMap, $iconBasePath, $level = 1) {
    $y = $pdf->GetY(); $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 12); $pdf->SetTextColor(0,0,0);

    $startX = 18;
    if ($emoji) {
        $iconFile = resolveIconFile($emoji, $iconMap, $iconBasePath);
        if ($iconFile) { $pdf->Image($iconFile, $startX, $y + 1, 7); $startX += 10; }
    }
    $pdf->SetXY($startX, $y); $pdf->Cell(0, 8, $title, 0, 1, 'L');
    $pdf->SetDrawColor(200,200,200); $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY()); $pdf->Ln(2);
    $pdf->SetFont('helvetica','',11); $pdf->SetTextColor(0,0,0);
}
function renderTable($pdf, $data) {
    $pdf->SetFont('helvetica','',10); $pdf->SetFillColor(240,240,240); $pdf->SetDrawColor(200,200,200);
    if (isset($data[0]) && is_array($data[0])) {
        $headers = array_keys($data[0]); $w = 180 / max(1,count($headers));
        foreach ($headers as $h) { $pdf->Cell($w, 8, ucfirst($h), 1, 0, 'C', true); } $pdf->Ln();
        foreach ($data as $row) { foreach ($headers as $h) { $pdf->Cell($w, 8, isset($row[$h]) ? $row[$h] : '', 1); } $pdf->Ln(); }
    } else {
        foreach ($data as $k=>$v) { $pdf->Cell(60,8, prettifyLabel($k),1,0,'L',true); $pdf->Cell(120,8,(string)$v,1,1); }
    }
    $pdf->Ln(4);
}
function prettifyLabel($key) {
    $s = preg_replace('/[_\-]+/',' ', $key);
    $s = preg_replace('/([a-z])([A-Z])/','\\1 \\2',$s);
    $s = ucwords($s);
    $s = preg_replace('/\bAi\b/','AI',$s);
    $s = preg_replace('/\bId\b/','ID',$s);
    $s = preg_replace('/\bSse\b/','SSE',$s);
    return $s;
}
function collapseWrapper($label, &$section) {
    while (is_array($section) && !isset($section['text']) && !isset($section['items'])) {
        $children = [];
        foreach ($section as $k => $v) {
            if (in_array($k, ['icon','format','text','items','title'], true)) continue;
            if (is_array($v)) $children[$k] = $v;
        }
        if (count($children) !== 1) break;
        $childKey = key($children); $child = $children[$childKey];
        if (!isset($section['icon'])   && isset($child['icon']))   $section['icon']   = $child['icon'];
        if (!isset($section['format']) && isset($child['format'])) $section['format'] = $child['format'];
        if (!isset($section['text'])   && isset($child['text']))   $section['text']   = $child['text'];
        if (!isset($section['items'])  && isset($child['items']))  $section['items']  = $child['items'];
        foreach ($child as $k => $v) {
            if (in_array($k, ['icon','format','text','items'], true)) continue;
            $section[$k] = $v;
        }
        unset($section[$childKey]);
    }
    return $label;
}
function renderSection($pdf, $label, $section, $iconMap, $iconBasePath, $level = 1) {
    $label = prettifyLabel($label);
    $label = collapseWrapper($label, $section);
    $emoji = isset($section['icon']) ? $section['icon'] : null;
    $pdf->setCurrentSection($label, $emoji);
    headerWithMappedIcon($pdf, $label, $emoji, $iconMap, $iconBasePath, $level);

    $fmt = $section['format'] ?? (isset($section['items']) ? 'list' : (isset($section['text']) ? 'text' : null));
    if ($fmt === 'text' && isset($section['text'])) {
        $pdf->MultiCell(0, 6, $section['text'], 0, 'L');
    } elseif ($fmt === 'list' && isset($section['items']) && is_array($section['items'])) {
        foreach ($section['items'] as $item) { $pdf->Cell(0, 6, '• ' . (string)$item, 0, 1, 'L'); }
    } elseif ($fmt === 'table') {
        if (isset($section['items'])) { renderTable($pdf, $section['items']); }
        else { renderTable($pdf, $section); }
    }

    foreach ($section as $key => $subsection) {
        if (in_array($key, ['icon','format','text','items','title'], true)) continue;
        if (is_array($subsection)) renderSection($pdf, $key, $subsection, $iconMap, $iconBasePath, $level + 1);
    }
}

// --- Main ---
$pdf = new ChristyPDF();
$pdf->SetCreator('Skyesoft PDF Generator');
$pdf->SetAuthor('Skyesoft');
date_default_timezone_set('America/Phoenix');
$pdf->SetMargins(20, 35, 20);
$pdf->SetAutoPageBreak(true, 25);

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $input = json_decode($argv[1], true);
    if (json_last_error() !== JSON_ERROR_NONE) { die("❌ Invalid CLI JSON: " . json_last_error_msg() . "\n"); }
}
if (!is_array($input)) die("❌ Invalid JSON input\n");

$type = $input['type'] ?? 'information_sheet';
$slug = isset($input['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$input['slug']) : '';
$requestor = $input['requestor'] ?? 'Skyesoft';

if ($type === 'information_sheet') {
    if (!$slug || !isset($codex[$slug])) { die("❌ Invalid slug or missing Codex data (slug: $slug)\n"); }
    $sheet = $codex[$slug];
    $title = $sheet['title'] ?? 'Information Sheet';
    $titleEmoji = normalizeEmoji(mb_substr($title, 0, 1));
    $titleText  = trim(mb_substr($title, 2));

    $pdf->SetTitle($titleText);
    $pdf->setReportTitle($titleText, $titleEmoji);
    $pdf->AddPage();

    // Purpose now always shown as its own section header
    if (isset($sheet['purpose']) && is_array($sheet['purpose'])) {
        renderSection($pdf, 'Purpose', $sheet['purpose'], $iconMap, $iconBasePath, 1);
    }

    foreach ($sheet as $key => $section) {
        if (in_array($key, ['title','purpose'], true)) continue;
        if (is_array($section)) renderSection($pdf, $key, $section, $iconMap, $iconBasePath);
    }

    $meta = [
        gmdate("F j, Y, g:i A") . ' GMT',
        date("F j, Y, g:i A T"),
        $requestor,
        "1.0"
    ];
    renderSection($pdf, 'Document Metadata', ['icon'=>'🧾','items'=>$meta,'format'=>'list'], $iconMap, $iconBasePath);
}

// Save
$saveDir = realpath(__DIR__ . "/../docs/reports") ?: __DIR__ . "/../docs/reports";
if (!is_dir($saveDir)) { @mkdir($saveDir, 0755, true); }
$prettySlug = ucwords(preg_replace('/([a-z])([A-Z])/','$1 $2', $slug));
$savePath = $saveDir . DIRECTORY_SEPARATOR . "Information Sheet - " . $prettySlug . ".pdf";
$pdf->Output($savePath, 'F');
echo "✅ PDF created: $savePath\n";
