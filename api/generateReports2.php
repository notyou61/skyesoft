<?php
require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';

// Load icon map
$iconMapPath = __DIR__ . "/../assets/data/iconMap.json";
$iconMap = json_decode(file_get_contents($iconMapPath), true);
$iconBasePath = realpath(__DIR__ . "/../assets/images/icons/") . DIRECTORY_SEPARATOR;

// Load Codex
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codex = json_decode(file_get_contents($codexPath), true);

class ChristyPDF extends TCPDF {
    private $reportTitle;
    private $reportIcon;

    public function setReportTitle($title, $emoji = null) {
        $this->reportTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
        $this->reportIcon = $emoji;
    }

    public function Header() {
        global $iconMap, $iconBasePath;

        // Logo
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        if (file_exists($logo)) {
            $this->Image($logo, 15, 10, 35);
        }

        // Title with icon
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(55, 12);

        // Resolve icon file
        $iconFile = resolveIconFile($this->reportIcon, $iconMap, $iconBasePath);
        if ($iconFile) {
            $this->Image($iconFile, 55, 12, 7); // draw icon
            $this->SetX(65); // shift title text
        }

        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report', 0, 1, 'L');

        // Subtitle + meta
        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(55);
        $this->Cell(0, 6, 'Skyesoft™ Information Sheet', 0, 1, 'L');

        $this->SetFont('helvetica', '', 9);
        $this->SetX(55);
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by Skyesoft™', 0, 1, 'L');

        // Divider
        $this->Ln(2);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
    }

    public function Footer() {
        $this->SetY(-20);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetFont('helvetica', '', 8);
        $footerText = "© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com | Page " .
            $this->getAliasNumPage() . "/" . $this->getAliasNbPages();
        $this->Cell(0, 6, $footerText, 0, 1, 'C');
    }
}

// --- Helpers ---
function normalizeEmoji($str) {
    return preg_replace('/\x{FE0F}|\x{200D}/u', '', $str);
}

function resolveIconFile($emoji, $iconMap, $iconBasePath) {
    if (!$emoji) return null;
    $key = normalizeEmoji($emoji);
    if (!isset($iconMap[$key])) return null;
    $mapVal = $iconMap[$key];

    // try as-is
    if (file_exists($mapVal)) return $mapVal;
    // try base path
    $candidate = $iconBasePath . ltrim($mapVal, DIRECTORY_SEPARATOR);
    if (file_exists($candidate)) return $candidate;
    // fallback basename
    $candidate2 = $iconBasePath . basename($mapVal);
    if (file_exists($candidate2)) return $candidate2;

    return null;
}

function drawIcon($pdf, $emoji, $iconMap, $iconBasePath, $x, $y, $w = 6) {
    $normalized = normalizeEmoji($emoji);
    if (!isset($iconMap[$normalized])) return false;
    $file = $iconMap[$normalized];
    if (!file_exists($file)) {
        $file = $iconBasePath . $iconMap[$normalized];
    }
    if (!file_exists($file)) return false;
    $pdf->Image($file, $x, $y, $w);
    return true;
}

function headerWithMappedIcon($pdf, $title, $emoji, $iconMap, $iconBasePath, $level = 1) {
    $y = $pdf->GetY();

    if ($level === 1) {
        // === Top-level header: black bar with white text ===
        $pdf->Ln(4);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetFillColor(0, 0, 0);      // black background
        $pdf->SetTextColor(255, 255, 255); // white text

        // Draw icon if available
        $startX = 18;
        if ($emoji) {
            $iconFile = resolveIconFile($emoji, $iconMap, $iconBasePath);
            if ($iconFile) {
                $pdf->Image($iconFile, $startX, $y + 1, 8);
                $startX += 10;
            }
        }

        // Draw header cell with fill
        $pdf->SetXY($startX, $y);
        $pdf->Cell(0, 10, "  " . $title, 0, 1, 'L', true);
        $pdf->Ln(2);
    } else {
        // === Sub-header: bold text with gray underline ===
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(0, 0, 0); // black text

        // Draw icon if available
        $startX = 18;
        if ($emoji) {
            $iconFile = resolveIconFile($emoji, $iconMap, $iconBasePath);
            if ($iconFile) {
                $pdf->Image($iconFile, $startX, $y + 1, 7);
                $startX += 10;
            }
        }

        $pdf->SetXY($startX, $y);
        $pdf->Cell(0, 8, $title, 0, 1, 'L');

        // Gray underline
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(2);
    }

    // Reset font/colors for body text
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);
}

function renderTable($pdf, $data) {
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(240,240,240);
    $pdf->SetDrawColor(200,200,200);

    if (isset($data[0]) && is_array($data[0])) {
        $headers = array_keys($data[0]);
        foreach ($headers as $header) {
            $pdf->Cell(180 / count($headers), 8, ucfirst($header), 1, 0, 'C', true);
        }
        $pdf->Ln();
        foreach ($data as $row) {
            foreach ($headers as $header) {
                $pdf->Cell(180 / count($headers), 8, $row[$header], 1);
            }
            $pdf->Ln();
        }
    } else {
        foreach ($data as $key=>$val) {
            $pdf->Cell(60, 8, ucfirst($key), 1, 0, 'L', true);
            $pdf->Cell(120, 8, $val, 1, 1);
        }
    }
    $pdf->Ln(4);
}

function renderSection($pdf, $label, $section, $iconMap, $iconBasePath, $level = 1) {
    $emoji = isset($section['icon']) ? $section['icon'] : null;
    headerWithMappedIcon($pdf, $label, $emoji, $iconMap, $iconBasePath, $level);

    if (isset($section['format'])) {
        switch ($section['format']) {
            case 'text':
                if (isset($section['text'])) {
                    $pdf->SetFont('helvetica', '', 11);
                    $pdf->MultiCell(0, 6, $section['text'], 0, 'L');
                }
                break;
            case 'list':
                if (isset($section['items'])) {
                    $pdf->SetFont('helvetica', '', 11);
                    foreach ($section['items'] as $item) {
                        $pdf->Cell(0, 6, '• ' . $item, 0, 1, 'L');
                    }
                }
                break;
            case 'table':
                if (isset($section['items'])) {
                    renderTable($pdf, $section['items']);
                } else {
                    renderTable($pdf, $section);
                }
                break;
        }
    }

    // Recurse only into meaningful subsections
    foreach ($section as $key => $subsection) {
        if (in_array($key, ['icon','text','items','format'])) continue;
        if (is_array($subsection)) {
            $subLabel = ucfirst($key);
            renderSection($pdf, $subLabel, $subsection, $iconMap, $iconBasePath, $level + 1);
        }
    }
}

// --- Main ---
$pdf = new ChristyPDF();
$pdf->SetCreator('Skyesoft PDF Generator');
$pdf->SetAuthor('Skyesoft');
date_default_timezone_set('America/Phoenix');
$pdf->SetMargins(20, 35, 20);
$pdf->SetAutoPageBreak(true, 25);

// Parse input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $input = json_decode($argv[1], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("❌ Invalid CLI JSON: " . json_last_error_msg() . "\n");
    }
}
if (!is_array($input)) die("❌ Invalid JSON input\n");

$type = isset($input['type']) ? (string)$input['type'] : 'information_sheet';
$slug = isset($input['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$input['slug']) : '';
$requestor = isset($input['requestor']) ? (string)$input['requestor'] : 'Skyesoft';

// Info Sheet branch
if ($type === 'information_sheet') {
    if (!$slug || !isset($codex[$slug])) {
        die("❌ Invalid slug or missing Codex data (slug: $slug)\n");
    }
    $sheet = $codex[$slug];
    $title = $sheet['title'];
    $titleEmoji = normalizeEmoji(mb_substr($title, 0, 1));
    $titleText = trim(mb_substr($title, 2));

    $pdf->SetTitle($titleText);
    $pdf->setReportTitle($titleText, $titleEmoji);
    $pdf->AddPage();

    // Add main title section with purpose/description if exists
    if (isset($sheet['purpose'])) {
        $mainSection = $sheet['purpose'];
        $mainSection['icon'] = $titleEmoji;
        renderSection($pdf, $titleText, $mainSection, $iconMap, $iconBasePath, 1);
    }

    foreach ($sheet as $key => $section) {
        if ($key === 'title' || $key === 'purpose') continue;
        if (is_array($section)) {
            renderSection($pdf, ucfirst($key), $section, $iconMap, $iconBasePath);
        }
    }

    // Metadata
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
if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
$prettySlug = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $slug));
$savePath = $saveDir . DIRECTORY_SEPARATOR . "Information Sheet - " . $prettySlug . ".pdf";
$pdf->Output($savePath, 'F');
echo "✅ PDF created: $savePath\n";