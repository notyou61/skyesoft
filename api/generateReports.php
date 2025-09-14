<?php
// PHP 5.6-compatible
require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';

// ChristyPDF class definition
class ChristyPDF extends TCPDF {
    private $reportTitle;
    public $disclaimer = '';

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

        // Blue divider line
        $this->SetDrawColor(0, 0, 0); // Pantone-like Christy Signs blue
        $this->SetLineWidth(0.5);
        $this->Line(15, $divider_y, 195, $divider_y);
    }

    // Override AddPage to ensure consistent body start on all pages
    public function AddPage($orientation='', $format='', $keepmargins=false, $tocpage=false) {
        parent::AddPage($orientation, $format, $keepmargins, $tocpage);
        $this->SetY(27); // lock body start across all pages
    }

    // Custom Footer
    public function Footer() {
        $this->SetY(-20);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), 195, $this->GetY());

        $this->SetFont('helvetica', '', 8);
        $footerText = "© Christy Signs / Skyesoft, All Rights Reserved | " .
                      "3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com" .
                      " | Page " . $this->getAliasNumPage() . "/" . $this->getAliasNbPages();
        $this->Cell(0, 6, $footerText, 0, 1, 'C');

        if (!empty($this->disclaimer)) {
            $this->MultiCell(0, 6, "Disclaimer: " . $this->disclaimer, 0, 'C');
        }
    }
}

// Render a section with styled header, icon, and dynamic content
function renderSectionWithIcon($pdf, $key, $title, $content, $iconMap = []) {
    // --- Transaction preview ---
    $pdf->startTransaction();
    $startPage = $pdf->getPage();
    $startY    = $pdf->GetY();

    // --- Draw section once (for measurement) ---
    $drawSection = function($pdf, $key, $title, $content, $iconMap) {
        // Section header
        $pdf->Ln(8);
        $pdf->SetFillColor(0, 0, 0); // Christy Signs Blue
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);

        $icon = isset($iconMap[$key]) ? $iconMap[$key] : '';
        $pdf->Cell(0, 8, " $icon $title", 0, 1, 'L', true);

        // Body reset
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(2);

        // Recursive render
        $renderContent = function($data, $level = 0) use (&$renderContent, $pdf, $key) {
            if (is_string($data) || is_numeric($data)) {
                $pdf->MultiCell(0, 6, str_repeat("   ", $level) . $data, 0, 'L');
            } elseif (is_array($data)) {
                if (array_keys($data) === range(0, count($data) - 1)) {
                    // Bullet list
                    foreach ($data as $item) {
                        $bullet = $level === 0 ? "• " : "– ";
                        $text = is_array($item) ? "" : $item;
                        $pdf->MultiCell(0, 6, str_repeat("   ", $level) . $bullet . $text, 0, 'L');
                        if (is_array($item)) {
                            $renderContent($item, $level + 1);
                        }
                    }
                } else {
                    // Assoc array
                    if ($key === 'metadata') {
                        foreach ($data as $k => $v) {
                            $pdf->SetFont('helvetica', 'B', 10);
                            $pdf->Cell(60, 6, ucfirst($k) . ":", 0, 0, 'L');
                            $pdf->SetFont('helvetica', '', 10);
                            $pdf->Cell(0, 6, (string)$v, 0, 1, 'L');
                        }
                    } else {
                        foreach ($data as $k => $v) {
                            $label = ucfirst($k) . ": ";
                            if (is_array($v)) {
                                $pdf->SetFont('helvetica', 'B', 10);
                                $pdf->MultiCell(0, 6, str_repeat("   ", $level) . $label, 0, 'L');
                                $pdf->SetFont('helvetica', '', 10);
                                $renderContent($v, $level + 1);
                            } else {
                                $pdf->MultiCell(0, 6, str_repeat("   ", $level) . $label . $v, 0, 'L');
                            }
                        }
                    }
                }
            }
        };

        $renderContent($content);
        $pdf->Ln(4);
    };

    // Render invisibly first
    $drawSection($pdf, $key, $title, $content, $iconMap);

    // Check overflow
    if ($pdf->getPage() > $startPage) {
        // Overflow → rollback and add page
        $pdf->rollbackTransaction(true); // discard preview
        $pdf->AddPage();
        $pdf->SetY(27); // ensure same start position as page 1
        $drawSection($pdf, $key, $title, $content, $iconMap);
    } else {
        // No overflow → just accept
        $pdf->commitTransaction();
    }
}

/**
 * Recursive search in Codex for a slug.
 */
function findSectionRecursive($array, $slug) {
    foreach ($array as $key => $value) {
        if (strcasecmp($key, $slug) === 0) {
            return $value;
        }
        if (is_array($value)) {
            $found = findSectionRecursive($value, $slug);
            if ($found) return $found;
        }
    }
    return null;
}

// --- Parse JSON input ---
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

$requestor = 'Skyesoft';
if (isset($input['requestor']) && $input['requestor'] !== '0' && $input['requestor'] !== 0) {
    $requestor = (string)$input['requestor'];
}
// Sanitize slug to allow only alphanumerics, dashes, underscores
$slug = isset($input['slug']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$input['slug']) : '';
// Default slug if missing
$data = isset($input['data']) && is_array($input['data']) ? $input['data'] : array();

// Initialize PDF
/** @var ChristyPDF $pdf */
$pdf = new ChristyPDF();
$pdf->SetCreator('Skyesoft PDF Generator');
$pdf->SetAuthor($requestor);

// Consistent margins: left/right 20, top 35 to give space for header
$pdf->SetMargins(20, 35, 20);     // Left, Top, Right
$pdf->SetAutoPageBreak(true, 25); // Bottom margin = 25mm
$pdf->SetHeaderMargin(0);

// Icon map (placeholder, as icons not implemented in TCPDF)
$iconMap = [];
$sectionIcons = [];

// Branch: Information Sheet
if ($type === 'information_sheet') {
    $codex_file = __DIR__ . '/../docs/codex/codex.json';
    if (!file_exists($codex_file)) {
        die("❌ Codex JSON not found at: $codex_file\n");
    }

    $codex = json_decode(file_get_contents($codex_file), true);

    $foundSection = findSectionRecursive($codex, $slug);

    if (!$foundSection || !is_array($foundSection)) {
        die("❌ Invalid slug or Codex data (slug: $slug)\n");
    }

    $section = $foundSection;
    $title   = !empty($section['title']) ? $section['title'] : 'Information Sheet';

    $pdf->SetTitle($title);
    $pdf->setReportTitle($title);
    $pdf->AddPage();

    // Dynamically render all fields except title
    foreach ($section as $key => $value) {
        if ($key === 'title') continue;

        // Normalize value
        $content = $value;
        if (is_array($value) && isset($value['text'])) {
            $content = $value['text']; // handle objects with 'text'
        }

        $label = ucwords(str_replace('_', ' ', $key));
        renderSectionWithIcon($pdf, $key, $label, $content, $iconMap, $sectionIcons);
    }

    // Metadata section
    $meta = array(
        "Generated (UTC)"   => gmdate("Y-m-d H:i:s"),
        "Generated (Local)" => date("Y-m-d H:i:s"),
        "Author"            => $requestor,
        "Version"           => "1.0"
    );
    renderSectionWithIcon($pdf, 'metadata', "Document Metadata", $meta, $iconMap, $sectionIcons);
} else {
    // Reports branch (placeholder)
    $title = "Report Placeholder";
    $pdf->SetTitle($title);
    $pdf->setReportTitle($title);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Write(0, "Report generation coming soon.\n\nThis is a placeholder for the report type: " . $slug . ".");
}

// Save output
$saveDir = realpath(__DIR__ . "/../docs/reports") ?: __DIR__ . "/../docs/reports";
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}
//
// Convert slug to Title Case with spaces
$prettySlug = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $slug));
// Fallback if slug is empty
$savePath = $saveDir . DIRECTORY_SEPARATOR . "Information Sheet - " . $prettySlug . ".pdf";
try {
    $pdf->Output($savePath, 'F');
    echo "✅ PDF created successfully at: $savePath\n";
} catch (Exception $e) {
    die("❌ Failed to create PDF: " . $e->getMessage() . "\n");
}