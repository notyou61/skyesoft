<?php
// PHP 5.6-compatible
require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';

// Load icon map
$iconMapPath = __DIR__ . "/../assets/data/iconMap.json";
$iconMap = json_decode(file_get_contents($iconMapPath), true);
$iconBasePath = realpath(__DIR__ . "/../assets/images/icons/") . DIRECTORY_SEPARATOR;

// Load Codex data
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codex = json_decode(file_get_contents($codexPath), true);

// ChristyPDF class definition
class ChristyPDF extends TCPDF {
    private $reportTitle;
    private $reportIcon;
    public $disclaimer = '';

    public function setReportTitle($title, $emoji = null) {
        $this->reportTitle = preg_replace('/[^\x20-\x7E]/', '', $title);
        $this->reportIcon = $emoji;
    }

    // Custom Header
    public function Header() {
        global $iconMap, $iconBasePath;
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

        // Main Title + dynamic icon from Codex
        if (!empty($this->reportIcon)) {
            $iconFile = $this->getIconFile($this->reportIcon, $iconMap, $iconBasePath);
            if ($iconFile) {
                $this->Image($iconFile, 52, $startY + 1, 6); // draw at left of title
                $this->SetXY(60, $startY); // shift text to right of icon
                if (is_file($iconFile) && strpos($iconFile, sys_get_temp_dir()) === 0) {
                    unlink($iconFile); // Clean up temp file
                }
            }
        }

        // Use Helvetica for the title
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report Title', 0, 1, 'L');

        // Title Tag
        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(55);
        $this->Cell(0, 6, 'Skyesoft™ Information Sheet', 0, 1, 'L');

        // Metadata
        $this->SetFont('helvetica', '', 9);
        $this->SetX(55);
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by Skyesoft™', 0, 1, 'L');

        $text_bottom = $this->GetY();
        $logo_bottom = 10 + $logo_height;
        $divider_y = max($text_bottom, $logo_bottom) + 2;

        // Divider line (black)
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

    public function getIconFile($emoji, $iconMap, $iconBasePath) {
        $normalized = normalizeEmoji($emoji);
        if (!isset($iconMap[$normalized])) return false;

        $data = $iconMap[$normalized];
        if (is_string($data)) {
            $file = rtrim($iconBasePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $data;
            return file_exists($file) ? $file : false;
        } elseif (is_array($data) && isset($data['file'], $data['x'], $data['y'], $data['w'], $data['h'])) {
            return extractIconFromSprite($normalized, $iconMap, $iconBasePath);
        }
        return false;
    }
}

// Normalize emojis (remove variation selectors, invisible chars)
function normalizeEmoji($str) {
    return preg_replace('/\x{FE0F}|\x{200D}/u', '', $str);
}

// Extract icon from sprite
function extractIconFromSprite($emoji, $iconMap, $iconBasePath) {
    if (!isset($iconMap[$emoji])) return false;

    $data = $iconMap[$emoji];
    $spritePath = rtrim($iconBasePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $data['file'];
    if (!file_exists($spritePath)) return false;

    $sprite = imagecreatefrompng($spritePath);
    if (!$sprite) return false;

    $icon = imagecreatetruecolor($data['w'], $data['h']);
    imagesavealpha($icon, true);
    $transColor = imagecolorallocatealpha($icon, 0, 0, 0, 127);
    imagefill($icon, 0, 0, $transColor);

    // Copy from sprite to new cropped image
    imagecopy($icon, $sprite, 0, 0, $data['x'], $data['y'], $data['w'], $data['h']);

    // Save as temp file
    $tempFile = tempnam(sys_get_temp_dir(), "icon_") . ".png";
    imagepng($icon, $tempFile);

    imagedestroy($sprite);
    imagedestroy($icon);

    return $tempFile;
}

// Draw icon helper
function drawIcon($pdf, $emoji, $iconMap, $iconBasePath, $x = null, $y = null, $w = 6) {
    $normalized = normalizeEmoji($emoji);
    if (!isset($iconMap[$normalized])) return false;

    $iconEntry = $iconMap[$normalized];
    if (!is_string($iconEntry)) return false; // skip sprite objects

    $file = rtrim($iconBasePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $iconEntry;
    if (!file_exists($file)) return false;

    if ($x !== null && $y !== null) {
        $pdf->Image($file, $x, $y, $w);
    } else {
        $pdf->Image($file, $pdf->GetX(), $pdf->GetY(), $w);
        $pdf->SetX($pdf->GetX() + $w + 2);
    }
    return true;
}

// Header with mapped icon
function headerWithMappedIcon($pdf, $title, $emoji, $iconMap, $iconBasePath) {
    $pdf->Ln(8);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 12);
    $y = $pdf->GetY();
    $pdf->Cell(0, 8, '', 0, 1, 'L', true);
    $pdf->SetXY(22, $y);
    if ($emoji) {
        drawIcon($pdf, $emoji, $iconMap, $iconBasePath, 22, $y + 1);
        $pdf->SetX($pdf->GetX() + 8);
    }
    $pdf->Cell(0, 8, $title, 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(2);
}

// Generic section renderer
function renderSection($pdf, $label, $section, $iconMap, $iconBasePath) {
    // Draw section header with icon
    if (isset($section['icon'])) {
        headerWithMappedIcon($pdf, $label, $section['icon'], $iconMap, $iconBasePath);
    } else {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, $label, 0, 1, 'L');
        $pdf->Ln(2);
    }

    // If this section has text
    if (isset($section['text'])) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(0, 6, $section['text'], 0, 'L');
        $pdf->Ln(2);
    }

    // If this section has items (list)
    if (isset($section['items']) && is_array($section['items'])) {
        $pdf->SetFont('helvetica', '', 11);
        foreach ($section['items'] as $item) {
            $pdf->Cell(0, 6, '• ' . $item, 0, 1, 'L');
        }
        $pdf->Ln(2);
    }

    // If this section has nested objects
    foreach ($section as $key => $subsection) {
        if (is_array($subsection) && isset($subsection['icon'])) {
            // Capitalize the key for display
            $subLabel = ucfirst($key);
            renderSection($pdf, $subLabel, $subsection, $iconMap, $iconBasePath);
        }
    }
}

// Main Logic
$pdf = new ChristyPDF();
$pdf->SetCreator('Skyesoft PDF Generator');
$pdf->SetAuthor('Skyesoft');

// Set timezone to MST
date_default_timezone_set('America/Phoenix');

// Add Noto Emoji font (if needed; run once then comment the addTTFfont line)
$emojiFontName = 'notoemoji'; // Adjust if different
// TCPDF_FONTS::addTTFfont(__DIR__ . '/fonts/Noto_Emoji/NotoEmoji-Regular.ttf', 'TrueTypeUnicode', '', 32); // Run once

// Consistent margins
$pdf->SetMargins(20, 35, 20);
$pdf->SetAutoPageBreak(true, 25);
$pdf->SetHeaderMargin(0);

// Parse JSON input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// CLI fallback
if (php_sapi_name() === 'cli' && isset($argv[1])) {
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

// Branch: Information Sheet
if ($type === 'information_sheet') {
    if (!$slug || !isset($codex[$slug])) {
        die("❌ Invalid slug or Codex data (slug: $slug)\n");
    }
    $sheet = $codex[$slug];
    $title = $sheet['title'];
    $titleEmoji = normalizeEmoji(mb_substr($title, 0, 1));
    $titleText  = trim(preg_replace('/^\X\s*/u', '', $title));
    $pdf->SetTitle($titleText);
    $pdf->setReportTitle($titleText, $titleEmoji);
    $pdf->AddPage();
    foreach ($sheet as $key => $section) {
        if (is_array($section) && isset($section['icon'])) {
            renderSection($pdf, ucfirst($key), $section, $iconMap, $iconBasePath);
        }
    }
    // Metadata section
    $meta = array(
        "Generated (UTC)" => gmdate("F j, Y, g:i A T"),
        "Generated (Local)" => date("F j, Y, g:i A T"),
        "Author" => $requestor,
        "Version" => "1.0"
    );
    renderSection($pdf, 'Document Metadata', ['icon' => '🧾', 'items' => $meta], $iconMap, $iconBasePath);
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