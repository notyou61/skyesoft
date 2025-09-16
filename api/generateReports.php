<?php
// PHP 5.6-compatible
require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';
// Load icon map
$iconMapPath = __DIR__ . '/../assets/data/iconMap.json';
$iconMap = [];
if (file_exists($iconMapPath)) {
    $iconMap = json_decode(file_get_contents($iconMapPath), true);
    error_log("✅ iconMap loaded with " . count($iconMap) . " entries");
} else {
    error_log("⚠️ iconMap.json not found at $iconMapPath");
}


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

        // Draw company logo
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

        // --- Extract emoji + title text from reportTitle ---
        $fullTitle = $this->reportTitle ?: '📘 Project Report';
        $icon = mb_substr($fullTitle, 0, 1, 'UTF-8');            // First char (emoji)
        $titleText = trim(mb_substr($fullTitle, 1, null, 'UTF-8')); // Rest of the string

        // Cursor position
        $this->SetXY(55, $startY);

        // --- Draw emoji ---
        if ($icon) {
            $this->SetFont('dejavusans', '', 12);   // Use font with emoji support
            $this->Cell(6, 8, $icon, 0, 0, 'L');
            $this->SetXY(62, $startY);              // Shift text to the right
        }

        // --- Main Title ---
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 8, $titleText, 0, 1, 'L');

        // Subtitle / Tagline
        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(55);
        $this->Cell(0, 6, 'Skyesoft™ Information Sheet', 0, 1, 'L');

        // Metadata
        $this->SetFont('helvetica', '', 9);
        $this->SetX(55);
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by Skyesoft™', 0, 1, 'L');

        // Divider line
        $text_bottom = $this->GetY();
        $logo_bottom = 10 + $logo_height;
        $divider_y = max($text_bottom, $logo_bottom) + 2;
        $this->SetDrawColor(0, 0, 0);
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

// --- Normalize emojis (remove variation selectors, invisible chars) ---
function normalizeEmoji($str) {
    return preg_replace('/\x{FE0F}|\x{200D}/u', '', $str);
}

// --- Icon resolver helper (PNG only, no emoji fallback) ---
function resolveIcon($value, $iconMap, $iconBasePath) {
    $result = ['file' => false];
    if (empty($value)) return $result;

    $normalized = normalizeEmoji($value);

    // Normalize iconMap keys once
    static $normalizedMap = null;
    if ($normalizedMap === null) {
        $normalizedMap = [];
        foreach ($iconMap as $k => $v) {
            $normalizedMap[normalizeEmoji($k)] = $v;
        }
    }

    if (isset($normalizedMap[$normalized])) {
        $candidate = rtrim($iconBasePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedMap[$normalized];
        if (file_exists($candidate)) {
            $result['file'] = $candidate;
            return $result;
        } else {
            error_log("❌ File not found on disk for [$normalized]: $candidate");
        }
    } else {
        error_log("⚠️ No mapping for [$normalized] in iconMap");
    }

    return $result;
}

// --- Main function ---
function renderSectionWithIcon($pdf, $key, $title, $content) {
    error_log("🔍 renderSectionWithIcon() called with key: $key");

    // --- Prepend icon from codex if available ---
    if (is_array($content) && isset($content['icon'])) {
        $emojiValue = $content['icon'];  // 🎯, 💡, 📂, 🧾 etc.
        $title = $emojiValue . " " . $title;

        // Simplify body
        if (isset($content['text'])) {
            $content = $content['text'];
        } elseif (isset($content['items'])) {
            $content = $content['items'];
        }
    }

    // --- Transaction preview ---
    $pdf->startTransaction();
    $startPage = $pdf->getPage();

    $drawSection = function($pdf, $key, $title, $content) {
        // Header bar
        $pdf->Ln(8);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);

        $y = $pdf->GetY();
        $pdf->Cell(0, 8, '', 0, 1, 'L', true);

        // Header with inline emoji + title
        $pdf->SetXY(22, $y);
        $pdf->Cell(0, 8, $title, 0, 1, 'L');

        // Reset text for body
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(2);

        // Recursive content renderer
        $renderContent = function($data, $level = 0) use (&$renderContent, $pdf, $key) {
            if (is_string($data) || is_numeric($data)) {
                $pdf->MultiCell(0, 6, str_repeat("   ", $level) . $data, 0, 'L');
            } elseif (is_array($data)) {
                if (array_keys($data) === range(0, count($data) - 1)) {
                    // Sequential list
                    foreach ($data as $item) {
                        $bullet = $level === 0 ? "• " : "– ";
                        $text   = is_array($item) ? "" : $item;
                        $pdf->MultiCell(0, 6, str_repeat("   ", $level) . $bullet . $text, 0, 'L');
                        if (is_array($item)) {
                            $renderContent($item, $level + 1);
                        }
                    }
                } else {
                    // Associative array
                    foreach ($data as $k => $v) {
                        if ($key === 'metadata') {
                            $pdf->SetFont('helvetica', 'B', 10);
                            $pdf->Cell(60, 6, ucfirst($k) . ":", 0, 0, 'L');
                            $pdf->SetFont('helvetica', '', 10);
                            $pdf->Cell(0, 6, (string)$v, 0, 1, 'L');
                        } else {
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

    $drawSection($pdf, $key, $title, $content);

    // --- Handle overflow ---
    if ($pdf->getPage() > $startPage) {
        $pdf->rollbackTransaction(true);
        $pdf->AddPage();
        $pdf->SetY(27);
        $drawSection($pdf, $key, $title, $content);
    } else {
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

// --- Main Logic ---
/** @var ChristyPDF $pdf */
$pdf = new ChristyPDF();
$pdf->SetCreator('Skyesoft PDF Generator');
$pdf->SetAuthor('Skyesoft');

// Set timezone to MST
date_default_timezone_set('America/Phoenix');

// Add Noto Emoji font statically (run once to generate definition if missing, then comment out)
// $emojiFontName = TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/Noto_Emoji/NotoEmoji-Regular.ttf', 'TrueTypeUnicode', '', 32);
// After running once, uncomment the next line and use the returned font name (e.g., 'notoemoji')
$emojiFontName = 'notoemoji'; // Adjust based on addTTFfont() output
$pdf->SetFont('helvetica', '', 12); // Default font until changed

// Consistent margins: left/right 20, top 35 to give space for header
$pdf->SetMargins(20, 35, 20); // Left, Top, Right
$pdf->SetAutoPageBreak(true, 25); // Bottom margin = 25mm
$pdf->SetHeaderMargin(0);

// Icon map (for section icons)
$iconMapFile = __DIR__ . '/../assets/images/icons/iconMap.json';
$iconMap = file_exists($iconMapFile) ? json_decode(file_get_contents($iconMapFile), true) : [];
$iconBasePath = __DIR__ . '/../assets/images/icons/';

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

        // Don’t flatten objects here — pass them as-is
        $content = $value;

        $label = ucwords(str_replace('_', ' ', $key));
        renderSectionWithIcon($pdf, $key, $label, $content, $iconMap, $iconBasePath);
    }

    // Metadata section
    $meta = array(
        "Generated (UTC)"   => gmdate("F j, Y, g:i A T"),
        "Generated (Local)" => date("F j, Y, g:i A T"),
        "Author"            => $requestor,
        "Version"           => "1.0"
    );
    renderSectionWithIcon($pdf, 'metadata', "Document Metadata", $meta, $iconMap, $iconBasePath);
} else {
    // Reports branch (placeholder)
    $title = "Report Placeholder";
    $pdf->SetTitle($title);
    $pdf->setReportTitle($title);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 11); // Changed to Helvetica for consistency
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