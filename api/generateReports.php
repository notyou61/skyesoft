<?php
// PHP 5.6-compatible
require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';

// ChristyPDF class definition
class ChristyPDF extends TCPDF {
    private $reportTitle;

    public function setReportTitle($title) {
        $this->reportTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
    }

    // Custom Header
    public function Header() {
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        $logo_height = 0;
        if (file_exists($logo)) {
            list($pix_w, $pix_h) = getimagesize($logo);
            $logo_width = 35;
            if ($pix_w > 0) {
                $logo_height = $logo_width * ($pix_h / $pix_w);
            }
            $this->Image($logo, 15, 10, $logo_width);
        }

        $text_height = 8 + 6 + 6;
        $logo_center_y = 10 + ($logo_height / 2);
        $startY = $logo_center_y - ($text_height / 2);

        // Main Title
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(55, $startY);
        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report Title', 0, 1, 'L');

        // Title Tag
        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(55);
        $this->Cell(0, 6, 'Skyesoft™ Information Sheet', 0, 1, 'L');

        // Metadata
        $this->SetFont('helvetica', '', 9);
        $this->SetX(55);
        $this->Cell(0, 6, date('F j, Y') . ' – Created by Skyesoft™', 0, 1, 'L');

        $text_bottom = $this->GetY();
        $logo_bottom = 10 + $logo_height;
        $divider_y = max($text_bottom, $logo_bottom) + 2;
        $this->Line(15, $divider_y, 195, $divider_y);
    }

    // Custom Footer
    public function Footer() {
        $this->SetY(-20);
        $this->Line(15, $this->GetY(), 195, $this->GetY());

        $this->SetFont('helvetica', '', 8);
        $footerText = "© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com"
                    . " | Page " . $this->getAliasNumPage() . "/" . $this->getAliasNbPages();
        $this->Cell(0, 10, $footerText, 0, 0, 'C');
    }
}

// Central section-to-icon mapping (fallback to emojis; override via iconMap.json)
$sectionIcons = array(
    'purpose' => '🎯',
    'useCases' => '💡',
    'features' => '⚙️',
    'workflow' => '📋',
    'integrations' => '🔌',
    'types' => '📂',
    'status' => '✅',
    'lastUpdated' => '📅'
);

// Load icon map
$iconMapFile = __DIR__ . '/../assets/data/iconMap.json';
$iconMap = file_exists($iconMapFile) ? json_decode(file_get_contents($iconMapFile), true) : array();

/**
 * Get icon for a section (prioritize iconMap, fallback to sectionIcons)
 */
function getSectionIcon($sectionKey, $iconMap, $sectionIcons) {
    return isset($iconMap[$sectionKey]) ? $iconMap[$sectionKey] : (isset($sectionIcons[$sectionKey]) ? $sectionIcons[$sectionKey] : '');
}

/**
 * Render a section with an icon + title + body, keeping the section together.
 */
function renderSectionWithIcon($pdf, $sectionKey, $title, $body, $iconMap, $sectionIcons) {
    $pdf->startTransaction();
    $startY = $pdf->GetY();

    // 1. Find emoji for this section
    $emoji = isset($sectionIcons[$sectionKey]) ? $sectionIcons[$sectionKey] : '';
    // 2. See if there’s a PNG mapping for that emoji
    $iconFile = ($emoji && isset($iconMap[$emoji])) ? $iconMap[$emoji] : null;

    // --- Section header row ---
    $pdf->SetFont('dejavusans', 'B', 12);
    $iconUsed = false;

    if ($iconFile) {
        $iconPath = __DIR__ . '/../assets/images/icons/' . $iconFile;
        if (file_exists($iconPath)) {
            $pdf->Image($iconPath, $pdf->GetX(), $pdf->GetY(), 6);
            $pdf->SetX($pdf->GetX() + 8);
            $pdf->Cell(0, 8, $title, 0, 1, 'L');
            $pdf->Ln(1);
            $iconUsed = true;
        }
    }

    if (!$iconUsed) {
        // fallback: emoji text (if font supports) or plain title
        $pdf->Cell(0, 8, ($emoji ? $emoji . " " : "") . $title, 0, 1, 'L');
    }
    $pdf->Ln(2);

    // --- Section body ---
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->MultiCell(0, 6, $body, 0, 'L', false, 1);
    $pdf->Ln(4);

    // --- Rollback if section spills onto next page ---
    $pageHeight = $pdf->getPageHeight();
    $bottomMargin = $pdf->getBreakMargin();
    $pageEnd = $pageHeight - $bottomMargin;
    if ($pdf->GetY() > $pageEnd) {
        $pdf->rollbackTransaction(true);
        $pdf->AddPage();
        renderSectionWithIcon($pdf, $sectionKey, $title, $body, $iconMap, $sectionIcons);
    } else {
        $pdf->commitTransaction();
    }
}

// Parse JSON input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// CLI fallback
if (php_sapi_name() === 'cli' && isset($argv[1]) && !$input) {
    $input = json_decode($argv[1], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("❌ Invalid CLI JSON input: " . json_last_error_msg() . "\n");
    }
}

if (!is_array($input)) {
    die("❌ Invalid JSON input\n");
}

// Normalize input
$type = isset($input['type']) ? (string)$input['type'] : 'information_sheet';
if ($type === '0') $type = 'information_sheet';
if ($type === '1') $type = 'report';
if (!in_array($type, array('information_sheet', 'report'))) {
    die("❌ Invalid type: $type\n");
}

$requestor = 'Skyesoft';
if (isset($input['requestor']) && $input['requestor'] !== '0' && $input['requestor'] !== 0) {
    $requestor = (string)$input['requestor'];
}

$slug = isset($input['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$input['slug']) : '';
$data = isset($input['data']) && is_array($input['data']) ? $input['data'] : array();

// Initialize PDF
/** @var ChristyPDF $pdf */
$pdf = new ChristyPDF();
$pdf->SetCreator('Skyesoft PDF Generator');
$pdf->SetAuthor($requestor);

// Layout calculations for margins
$logo = __DIR__ . '/../assets/images/christyLogo.png';
$logo_height = 0;
$logo_width = 35;
if (file_exists($logo)) {
    list($pix_w, $pix_h) = getimagesize($logo);
    if ($pix_w > 0) {
        $logo_height = $logo_width * ($pix_h / $pix_w);
    }
}
$text_height   = 20;
$logo_center_y = 10 + ($logo_height / 2);
$startY        = $logo_center_y - ($text_height / 2);
$text_bottom   = $startY + $text_height;
$logo_bottom   = 10 + $logo_height;
$max_bottom    = max($text_bottom, $logo_bottom);
$divider_y     = $max_bottom + 2;
$body_start_y  = $divider_y + 3;
$footer_start  = 20;
$body_end_margin = $footer_start + 5;

$pdf->SetMargins(15, $body_start_y, 15);
$pdf->SetAutoPageBreak(true, $body_end_margin);

// Branch: Information Sheet
if ($type === 'information_sheet') {
    $codex_file = __DIR__ . '/../docs/codex/codex.json';
    if (!file_exists($codex_file)) {
        die("❌ Codex JSON not found at: $codex_file\n");
    }

    $codex = json_decode(file_get_contents($codex_file), true);

    $foundSection = null;
    foreach ($codex as $key => $value) {
        if (strcasecmp($key, $slug) === 0) { // case-insensitive compare
            $foundSection = $value;
            $slug = $key; // preserve original casing for later
            break;
        }
    }

    if (!$foundSection || !is_array($foundSection)) {
        die("❌ Invalid slug or Codex data (slug: $slug)\n");
    }

    $section = $foundSection;
    $title   = !empty($section['title']) ? $section['title'] : 'Information Sheet';

    $pdf->SetTitle($title);
    $pdf->setReportTitle($title);
    $pdf->AddPage();

    // Purpose
    if (!empty($section['purpose'])) {
        $purposeText = is_array($section['purpose']) && isset($section['purpose']['text'])
            ? $section['purpose']['text'] : (string)$section['purpose'];
        renderSectionWithIcon($pdf, 'purpose', "Purpose", $purposeText, $iconMap, $sectionIcons);
    }

    // Use Cases
    if (!empty($section['useCases']) && is_array($section['useCases'])) {
        $body = implode("\n", array_map(function($uc){ return "• " . $uc; }, $section['useCases']));
        renderSectionWithIcon($pdf, 'useCases', "Use Cases", $body, $iconMap, $sectionIcons);
    }

    // Features
    if (!empty($section['features'])) {
        $items = is_array($section['features']) ? $section['features'] : array((string)$section['features']);
        $body = implode("\n", array_map(function($f){ return "• " . $f; }, $items));
        renderSectionWithIcon($pdf, 'features', "Features", $body, $iconMap, $sectionIcons);
    }

    // Workflow
    if (!empty($section['workflow'])) {
        $items = isset($section['workflow']['items']) ? $section['workflow']['items'] : (array)$section['workflow'];
        $body = implode("\n", array_map(function($w){ return "• " . $w; }, $items));
        renderSectionWithIcon($pdf, 'workflow', "Workflow Steps", $body, $iconMap, $sectionIcons);
    }

    // Integrations
    if (!empty($section['integrations'])) {
        $items = isset($section['integrations']['items']) ? $section['integrations']['items'] : (array)$section['integrations'];
        $body = implode("\n", array_map(function($i){ return "• " . $i; }, $items));
        renderSectionWithIcon($pdf, 'integrations', "System Integrations", $body, $iconMap, $sectionIcons);
    }

    // Types (special for informationSheetSuite)
    if (!empty($section['types']) && is_array($section['types'])) {
        foreach ($section['types'] as $typeName => $typeDef) {
            $body = "Purpose: " . $typeDef['purpose'] . "\n\n";
            if (!empty($typeDef['requiredFields'])) {
                $body .= "Required Fields:\n";
                foreach ($typeDef['requiredFields'] as $k => $fields) {
                    $body .= "- " . ucfirst($k) . ": " . implode(", ", $fields) . "\n";
                }
                $body .= "\n";
            }
            if (!empty($typeDef['pipeline'])) {
                $body .= "Pipeline:\n";
                foreach ($typeDef['pipeline'] as $step) {
                    $body .= "- " . $step . "\n";
                }
                $body .= "\n";
            }
            if (!empty($typeDef['outputs'])) {
                $body .= "Outputs:\n";
                foreach ($typeDef['outputs'] as $o) {
                    $body .= "- " . $o . "\n";
                }
                $body .= "\n";
            }
            if (!empty($typeDef['status'])) {
                $body .= "Status: " . $typeDef['status'] . "\n";
            }
            renderSectionWithIcon($pdf, 'types', "Type: " . $typeName, $body, $iconMap, $sectionIcons);
        }
    }

    // Status
    if (!empty($section['status'])) {
        renderSectionWithIcon($pdf, 'status', "Status", (string)$section['status'], $iconMap, $sectionIcons);
    }

    // Last Updated
    if (!empty($section['lastUpdated'])) {
        renderSectionWithIcon($pdf, 'lastUpdated', "Last Updated", (string)$section['lastUpdated'], $iconMap, $sectionIcons);
    }

    // Footer line
    $pdf->Ln(10);
    $pdf->SetFont('dejavusans', 'I', 9);
    $pdf->Cell(0, 6, "Skyesoft™ Internal Documentation – Info Sheet | Updated: " . date('Y-m-d'), 0, 1, 'C');

} else {
    // Reports branch (placeholder)
    $title = "Report Placeholder";
    $pdf->SetTitle($title);
    $pdf->setReportTitle($title);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->Write(0, "Report generation coming soon.\n\nThis is a placeholder for the report type: " . $slug . ".");
}

// Pick a filename-friendly title
$fileTitle = !empty($section['title']) 
    ? preg_replace('/[^A-Za-z0-9 _-]/', '', $section['title']) 
    : $slug;
$fileTitle = trim($fileTitle);

// Save output
$saveDir = realpath(__DIR__ . "/../docs/reports") ?: __DIR__ . "/../docs/reports";
// Directory traversal protection
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}
// Path format: "Information Sheet - [Title].pdf"
$savePath = $saveDir . DIRECTORY_SEPARATOR . "Information Sheet - " . $fileTitle . ".pdf";
try {
    $pdf->Output($savePath, 'F');
    echo "✅ PDF created successfully at: $savePath\n";
} catch (Exception $e) {
    die("❌ Failed to create PDF: " . $e->getMessage() . "\n");
}