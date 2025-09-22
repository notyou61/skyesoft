<?php
// Enable full error reporting for debugging in PHP 5.6
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =====================================================================
// Skyesoft Dynamic Report Generator v2
// =====================================================================

// Dependencies
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');

// Configuration
$config = [
    'report_subtitle' => 'Skyesoft™ Information Sheet',
    'company_info' => '© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com'
];

// Consistent spacing value (in points)
$consistent_spacing = 10;

// --------------------------
// Load API key securely
// --------------------------
// Place Code Here !!!

// --------------------------
// Load codex.json dynamically
// --------------------------
$codexPath = __DIR__ . '/../docs/codex/codex.json';
if (!file_exists($codexPath)) {
    die("❌ ERROR: Codex file not found at $codexPath\n");
}
$codex = json_decode(file_get_contents($codexPath), true);
if ($codex === null) {
    die("❌ ERROR: Failed to decode codex.json\nJSON Error: " . json_last_error_msg() . "\n");
}

// --------------------------
// Load iconMap.json dynamically
// --------------------------
$iconMapPath = __DIR__ . '/../assets/data/iconMap.json';
$iconMap = [];
if (file_exists($iconMapPath)) {
    $iconMap = json_decode(file_get_contents($iconMapPath), true);
    if (!is_array($iconMap)) {
        $iconMap = [];
        echo "⚠️ WARNING: Failed to decode iconMap.json. Using empty icon map.\n";
    }
}

// --------------------------
// Handle CLI or HTTP Input
// --------------------------
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $input = json_decode($argv[1], true);
}

if (!is_array($input) || !isset($input['slug'])) {
    die("❌ ERROR: Invalid input JSON. Must include 'slug'.\n");
}

$slug      = $input['slug'];
$type      = isset($input['type']) ? $input['type'] : 'information_sheet';
$requestor = isset($input['requestor']) ? $input['requestor'] : 'Skyesoft';

// --------------------------
// Validate slug exists
// --------------------------
if (!isset($codex[$slug])) {
    die("❌ ERROR: Slug '$slug' not found in Codex.\n");
}

$module = $codex[$slug];
$moduleForAI = $module;
unset($moduleForAI['title']); // Clean for AI enrichment

// =====================================================================
// AI Helper: Generate narrative sections dynamically
// =====================================================================
function getAIEnrichedBody($slug, $key, $moduleData, $apiKey, $format = 'text') {
    $basePrompt = "You are generating content for the '{$key}' section of an information sheet for the '{$slug}' module. 
DO NOT create section headers or icons. 
The display formatting (headers, icons, tables, lists) will be applied dynamically by the system.

Module Data:
" . json_encode($moduleData, JSON_PRETTY_PRINT);

    if ($format === 'text') {
        $prompt = $basePrompt . "\n\nOnly generate narrative text for this section.";
    } elseif ($format === 'list') {
        $prompt = $basePrompt . "\n\nGenerate the list items for this section as a JSON array of strings.";
    } elseif ($format === 'table') {
        $prompt = $basePrompt . "\n\nGenerate the table data for this section as a JSON array of objects, where each object has keys corresponding to the table columns.";
    } else {
        return "⚠️ Unsupported format for AI enrichment.";
    }

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Dev only
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Dev only

    $payload = json_encode(array(
        "model" => "gpt-4o-mini",
        "messages" => array(
            array("role" => "system", "content" => "You are an assistant that writes clear, structured PDF content."),
            array("role" => "user", "content" => $prompt)
        ),
        "max_tokens" => 1500
    ));

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    if ($response === false) {
        return "⚠️ OpenAI API request failed: " . curl_error($ch);
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (isset($decoded['choices'][0]['message']['content'])) {
        $content = trim($decoded['choices'][0]['message']['content']);
        if ($format !== 'text') {
            $jsonContent = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $jsonContent;
            } else {
                return "⚠️ Failed to parse AI JSON response: " . json_last_error_msg();
            }
        }
        return $content;
    } else {
        return "⚠️ AI enrichment failed. Response: " . $response;
    }
}

// --------------------------
// Helper functions
// --------------------------
function resolveHeaderIcon($iconKey, $iconMap) {
    $iconBasePath = __DIR__ . '/../assets/images/icons/';
    if (!$iconKey) return null;

    if (isset($iconMap[$iconKey])) {
        $iconName = isset($iconMap[$iconKey]['file']) ? $iconMap[$iconKey]['file'] : $iconMap[$iconKey];
        $iconPath = $iconBasePath . $iconName;
        if (file_exists($iconPath)) {
            return $iconPath;
        } else {
            echo "⚠️ Icon file not found: $iconPath\n";
            return null;
        }
    }

    $fallback = $iconBasePath . $iconKey . '.png';
    if (file_exists($fallback)) {
        return $fallback;
    } else {
        echo "⚠️ Fallback icon not found: $fallback\n";
        return null;
    }
}

function formatHeaderTitle($key) {
    return ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $key));
}

// =====================================================================
// PDF Class
// =====================================================================
class ChristyPDF extends TCPDF {
    public $reportTitle;
    public $reportIcon;
    public $currentSectionTitle = '';
    private $config;
    public $isTableSection = false;
    public $headerHeight = 35; // Approximate height of header

    public function __construct($config) {
        parent::__construct();
        $this->config = $config;
    }

    public function setReportTitle($title, $icon = null) {
        $this->reportTitle = preg_replace('/[^\x20-\x7E]/', '', (string)$title);
        $this->reportIcon  = $icon;
    }

    public function Header() {
        global $iconMap;
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        if (file_exists($logo)) {
            $this->Image($logo, 15, 13, 38);
        }

        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(55, 12);
        $iconFile = resolveHeaderIcon($this->reportIcon, $iconMap);
        if ($iconFile) {
            $this->Image($iconFile, 55, 12, 7);
            $this->SetX(65);
        }
        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report', 0, 1, 'L');

        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(55);
        $this->Cell(0, 6, $this->config['report_subtitle'], 0, 1, 'L');

        date_default_timezone_set('America/Phoenix');
        $this->SetFont('helvetica', '', 9);
        $this->SetX(55);
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by Skyesoft™', 0, 1, 'L');

        $this->Ln(2);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetY($this->headerHeight); // Set Y to start body consistently
    }

    public function Footer() {
        $this->SetY(-20);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), 195, $this->GetY());

        $this->SetFont('helvetica', '', 8);
        $footerText = $this->config['company_info'] . " | Page " .
                      $this->getAliasNumPage() . "/" . $this->getAliasNbPages();
        $this->Cell(0, 6, $footerText, 0, 1, 'C');
    }

    public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false) {
        parent::AddPage($orientation, $format, $keepmargins, $tocpage);
        $this->SetY($this->headerHeight); // Ensure body starts at fixed position
    }

    public function AcceptPageBreak() {
        $this->AddPage();
        if (!empty($this->currentSectionTitle) && $this->isTableSection) {
            $continuedTitle = $this->currentSectionTitle . " – Continued";
            $this->SetFont('helvetica', 'B', 14);
            $this->Cell(0, 8, $continuedTitle, 0, 1, 'L');
            $this->SetDrawColor(200, 200, 200);
            $this->Line(20, $this->GetY(), 190, $this->GetY());
            $this->Ln(4);
            $this->SetFont('helvetica', '', 11);
        }
        return false; // Prevent default page break behavior
    }

    protected function drawTableHeader($headers, $colWidths) {
        $this->SetFillColor(99, 124, 192); // Pantone 637C blue
        foreach ($headers as $i => $header) {
            $this->Cell($colWidths[$i], 8, ucfirst($header), 1, 0, 'C', true);
        }
        $this->Ln();
    }

    protected function drawTableRow($row, $headers, $colWidths) {
        $maxh = 0;
        foreach ($headers as $i => $header) {
            $h = $this->getStringHeight($colWidths[$i], $row[$header], true, true, '', 1);
            if ($h > $maxh) $maxh = $h;
        }
        if ($maxh < 8) $maxh = 8;

        if ($this->GetY() + $maxh > $this->PageBreakTrigger) {
            $this->AcceptPageBreak();
            $this->drawTableHeader($headers, $colWidths);
        }

        foreach ($headers as $i => $header) {
            $this->MultiCell($colWidths[$i], $maxh, $row[$header], 1, 'L', false, 0, '', '', true, 0, false, true, 0, 'M');
        }
        $this->Ln($maxh);
    }

    public function renderSection($key, $section, $iconMap) {
        if (!isset($section['format'])) return;

        $this->currentSectionTitle = formatHeaderTitle($key);

        if ($this->GetY() + 20 > $this->PageBreakTrigger) { // Check if section header would fit
            $this->AddPage();
        }

        $iconKey = isset($section['icon']) ? $section['icon'] : null;
        $iconFile = resolveHeaderIcon($iconKey, $iconMap);

        $this->Ln($GLOBALS['consistent_spacing']);
        $startY = $this->GetY();
        $startX = 20;

        if ($iconFile) {
            $this->Image($iconFile, $startX, $startY, 8);
            $startX += 10;
        }

        $this->SetXY($startX, $startY);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 8, $this->currentSectionTitle, 0, 1, 'L');

        $this->SetDrawColor(200, 200, 200);
        $this->Line(20, $this->GetY(), 190, $this->GetY());
        $this->Ln(4);

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 11);

        if ($section['format'] === 'text' && isset($section['text'])) {
            $this->MultiCell(0, 6, $section['text']);
        } elseif ($section['format'] === 'list' && isset($section['items'])) {
            foreach ($section['items'] as $item) {
                $this->Cell(0, 6, '• ' . $item, 0, 1);
            }
        } elseif ($section['format'] === 'table' && !empty($section['items']) && isset($section['items'][0])) {
            $this->isTableSection = true;
            $this->SetCellPadding(1);
            $headers = array_keys($section['items'][0]);
            $numColumns = count($headers);
            $pageWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
            if ($numColumns == 2 && $key === 'glossary') {
                $colWidths = [$pageWidth * 0.25, $pageWidth * 0.75]; // Adjusted for glossary
            } else {
                $colWidth = $pageWidth / $numColumns;
                $colWidths = array_fill(0, $numColumns, $colWidth);
            }

            $this->drawTableHeader($headers, $colWidths);

            foreach ($section['items'] as $row) {
                $this->drawTableRow($row, $headers, $colWidths);
            }
            $this->SetCellPadding(0);
            $this->isTableSection = false;
        }

        $this->Ln($GLOBALS['consistent_spacing']);
    }
}

// =====================================================================
// Build PDF
// =====================================================================
$pdf = new ChristyPDF($config);
$pdf->SetCreator('Skyesoft Report Generator');
$pdf->SetAuthor('Skyesoft');
$pdf->SetMargins(20, 45, 20);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(20);
$pdf->SetAutoPageBreak(true, 25);

$iconKey = null;
$titleText = $slug;
if (isset($module['title']) && strpos($module['title'], ' ') !== false) {
    $parts = explode(' ', $module['title'], 2);
    $iconKey = $parts[0];
    $titleText = $parts[1];
} elseif (isset($module['title'])) {
    $titleText = $module['title'];
}
$pdf->setReportTitle($titleText, $iconKey);

$pdf->AddPage();

$sections = $module;
unset($sections['title']);
uasort($sections, function($a, $b) {
    return (isset($a['priority']) ? $a['priority'] : 999) - (isset($b['priority']) ? $b['priority'] : 999);
});

// Enrich blank sections with AI
foreach ($sections as $key => &$section) {
    $format = $section['format'];
    if ($key === 'glossary') {
        $section['format'] = 'table'; // Force table for glossary
    }

    if ($format === 'text' && !isset($section['text'])) {
        $section['text'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'text');
    } elseif ($format === 'list' && empty($section['items'])) {
        $section['items'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'list');
    } elseif ($format === 'table' && empty($section['items'])) {
        $promptType = ($key === 'glossary') ? 'table' : 'table'; // Same, but for glossary specific prompt if needed
        $section['items'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'table');
    }
}

// Render all sections
foreach ($sections as $key => $section) {
    $pdf->renderSection($key, $section, $iconMap);
}

// Remove last page if blank
$pdf->setPage($pdf->getNumPages());
if ($pdf->GetY() < 45) {
    $pdf->deletePage($pdf->getNumPages());
}

// =====================================================================
// Save and Output
// =====================================================================
$outputDir = __DIR__ . '/../docs/reports/';
if (!is_dir($outputDir)) {
    $oldUmask = umask(0);
    $result = mkdir($outputDir, 0777, true);
    umask($oldUmask);
    if (!$result) {
        die("❌ ERROR: Failed to create directory $outputDir\n");
    }
}

$titleSanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $titleText);
$outputFile = $outputDir . "Information_Sheet_" . $titleSanitized . ".pdf";

$pdf->Output($outputFile, "F");

if (file_exists($outputFile)) {
    echo "✅ PDF created: " . $outputFile . "\n";
} else {
    echo "❌ ERROR: PDF generation failed. File not found after output.\n";
}