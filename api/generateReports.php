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
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // skip comments
        list($name, $value) = array_map('trim', explode('=', $line, 2));
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

$OPENAI_API_KEY = getenv('OPENAI_API_KEY');
if (!$OPENAI_API_KEY) {
    echo "❌ ERROR: Missing OpenAI API Key. Set in .env file.\n";
    exit(1);
}

// --------------------------
// Load codex.json dynamically
// --------------------------
$codexPath = __DIR__ . '/../docs/codex/codex.json';
if (!file_exists($codexPath)) {
    logError("❌ ERROR: Codex file not found at $codexPath");
    die();
}

$codex = json_decode(file_get_contents($codexPath), true);
if ($codex === null) {
    logError("❌ ERROR: Failed to decode codex.json\nJSON Error: " . json_last_error_msg());
    die();
}

// Initialize modules array to include valid modules only
$modules = [];
foreach ($codex as $key => $value) {
    if ($key === 'codexModules' && is_array($value)) {
        $modules = array_merge($modules, $value);
    } elseif (is_array($value) && isset($value['title'])) {
        // Check if the entry has at least one valid section with format
        $hasValidSection = false;
        foreach ($value as $sectionKey => $section) {
            if ($sectionKey !== 'title' && is_array($section) && isset($section['format']) && in_array($section['format'], ['text', 'list', 'table'])) {
                $hasValidSection = true;
                break;
            }
        }
        if ($hasValidSection) {
            $modules[$key] = $value;
        }
    }
}
logMessage("ℹ️ Loaded " . count($modules) . " valid modules for slug lookup.");

// Validate codex structure
foreach ($modules as $slug => $module) {
    if (!is_array($module) || !isset($module['title'])) {
        logError("❌ ERROR: Invalid codex structure for slug '$slug'. Missing 'title' or invalid module.");
        die();
    }

    foreach ($module as $key => $section) {
        if ($key === 'title') continue; // Always allowed

        // Skip scalar metadata (strings, numbers, bools)
        if (!is_array($section)) continue;

        // Some objects (like codexMeta.schema) are metadata, not renderable sections → skip if they lack 'format'
        if (!isset($section['format'])) continue;

        // Enforce format only for renderable sections
        if (!in_array($section['format'], ['text', 'list', 'table'])) {
            logError("❌ ERROR: Invalid format for section '$key' in slug '$slug'. Must be 'text', 'list', or 'table'.");
            die();
        }
    }
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
        logMessage("⚠️ WARNING: Failed to decode iconMap.json. Using empty icon map.");
    }
}

// --------------------------
// Handle CLI or HTTP Input
// --------------------------
$input = null;
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $rawInput = $argv[1];
    logMessage("ℹ️ CLI raw input: $rawInput");
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("❌ ERROR: Failed to parse CLI JSON input: " . json_last_error_msg() . "\nRaw input: $rawInput");
        die();
    }
} else {
    $rawInput = file_get_contents('php://input');
    logMessage("ℹ️ HTTP raw input: $rawInput");
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logError("❌ ERROR: Failed to parse HTTP JSON input: " . json_last_error_msg() . "\nRaw input: $rawInput");
        die();
    }
}

if (!is_array($input) || !isset($input['slug'])) {
    logError("❌ ERROR: Invalid input JSON. Must include 'slug'.\nParsed input: " . print_r($input, true));
    die();
}

// Sanitize input
$slug = preg_replace('/[^A-Za-z0-9_-]/', '', $input['slug']);
$type = isset($input['type']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $input['type']) : 'information_sheet';
$requestor = isset($input['requestor']) ? preg_replace('/[^A-Za-z0-9\s]/', '', $input['requestor']) : 'Skyesoft';
$outputMode = isset($input['outputMode']) ? strtoupper($input['outputMode']) : 'F';

if (!in_array($outputMode, ['I', 'D', 'F'])) {
    logError("❌ ERROR: Invalid outputMode '$outputMode'. Must be 'I', 'D', or 'F'.");
    die();
}

// --------------------------
// Validate slug exists
// --------------------------
if (!isset($modules[$slug])) {
    logError("❌ ERROR: Slug '$slug' not found in Codex.");
    die();
}

$module = $modules[$slug];
$moduleForAI = $module;
unset($moduleForAI['title']); // Clean for AI enrichment

// =====================================================================
// Logging Helper
// =====================================================================
function logMessage($message) {
    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) {
        $oldUmask = umask(0);
        mkdir($logDir, 0777, true);
        umask($oldUmask);
    }
    $logFile = $logDir . 'report_generator.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function logError($message) {
    logMessage($message);
    echo $message . "\n";
}

// =====================================================================
// AI Helper: Generate narrative sections dynamically
// =====================================================================
function getAIEnrichedBody($slug, $key, $moduleData, $apiKey, $format = 'text') {
    $cacheDir = __DIR__ . '/../cache/';
    $cacheFile = $cacheDir . "{$slug}_{$key}.json";
    
    // Check cache
    if (file_exists($cacheFile)) {
        $cachedContent = json_decode(file_get_contents($cacheFile), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            logMessage("✅ Loaded cached content for $slug/$key");
            return $cachedContent;
        }
    }

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
        logError("⚠️ Unsupported format for AI enrichment: $format");
        return "⚠️ Unsupported format for AI enrichment.";
    }

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ));

    $payload = json_encode(array(
        "model" => "gpt-4o-mini",
        "messages" => array(
            array("role" => "system", "content" => "You are an assistant that writes clear, structured PDF content."),
            array("role" => "user", "content" => $prompt)
        ),
        "max_tokens" => 1500
    ));

    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = "⚠️ OpenAI API request failed: " . curl_error($ch);
        logError($error);
        return $error;
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (isset($decoded['choices'][0]['message']['content'])) {
        $content = trim($decoded['choices'][0]['message']['content']);
        if ($format !== 'text') {
            $jsonContent = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Cache the content
                if (!is_dir($cacheDir)) {
                    $oldUmask = umask(0);
                    mkdir($cacheDir, 0777, true);
                    umask($oldUmask);
                }
                file_put_contents($cacheFile, json_encode($jsonContent));
                logMessage("✅ Cached AI content for $slug/$key");
                return $jsonContent;
            } else {
                $error = "⚠️ Failed to parse AI JSON response: " . json_last_error_msg();
                logError($error);
                return $error;
            }
        }
        // Cache text content
        if (!is_dir($cacheDir)) {
            $oldUmask = umask(0);
            mkdir($cacheDir, 0777, true);
            umask($oldUmask);
        }
        file_put_contents($cacheFile, json_encode($content));
        logMessage("✅ Cached AI content for $slug/$key");
        return $content;
    } else {
        $error = "⚠️ AI enrichment failed. Response: " . $response;
        logError($error);
        return $error;
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
            logMessage("⚠️ Icon file not found: $iconPath");
            return null;
        }
    }

    $fallback = $iconBasePath . $iconKey . '.png';
    if (file_exists($fallback)) {
        return $fallback;
    } else {
        logMessage("⚠️ Fallback icon not found: $fallback");
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
    public $currentSectionKey = '';
    protected $currentSectionIcon = null; // store active icon
    protected $lastIcons = [];
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
        global $iconMap, $requestor;
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
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by ' . $requestor, 0, 1, 'L');

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
            $startY = $this->GetY();
            $startX = 20;

            // Draw the section icon on continued pages
            if ($this->currentSectionIcon) {
                $iconFile = resolveHeaderIcon($this->currentSectionIcon, $GLOBALS['iconMap']);
                if ($iconFile) {
                    $this->Image($iconFile, $startX, $startY, 8);
                    $startX += 10;
                }
            }

            // Inline: bold title + " – " + italic "Continued" (no extra spacing)
            $this->SetXY($startX, $startY);
            $this->SetFont('helvetica', 'B', 14);
            $this->Write(8, $this->currentSectionTitle . ' – ');
            $this->SetFont('helvetica', 'I', 14);
            $this->Write(8, 'Continued');
            $this->Ln(8);

            // Divider line and reset for body
            $this->SetDrawColor(200, 200, 200);
            $this->Line(20, $this->GetY(), 190, $this->GetY());
            $this->Ln(4);
            $this->SetFont('helvetica', '', 11);
        }

        return false; // prevent default break
    }

    // =====================================================================
    // Draw a table header (codex-driven styling)
    // =====================================================================
    protected function drawTableHeader($headers, $colWidths, $styling = []) {
        // Always enforce black background + white text for table headers
        $this->SetFillColor(0, 0, 0);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 11);

        foreach ($headers as $i => $header) {
            $this->Cell($colWidths[$i], 8, ucfirst($header), 1, 0, 'C', true);
        }
        $this->Ln();

        // Reset back to body styling
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 11);
    }


    protected function drawTableRow($row, $headers, $colWidths) {
        $maxh = 0;

        // Calculate maximum row height
        foreach ($headers as $i => $header) {
            $cellText = isset($row[$header]) ? $row[$header] : '';
            // FIX: use null instead of false for $cell param
            $h = $this->getStringHeight($colWidths[$i], $cellText, true, true, null, 'L');
            if ($h > $maxh) {
                $maxh = $h;
            }
        }

        if ($maxh < 8) {
            $maxh = 8; // minimum row height
        }

        // Handle page break if row would overflow
        if ($this->GetY() + $maxh > $this->PageBreakTrigger) {
            $this->AcceptPageBreak();
            $this->drawTableHeader($headers, $colWidths);
        }

        // Render each cell in the row
        foreach ($headers as $i => $header) {
            $cellText = isset($row[$header]) ? $row[$header] : '';
            $this->MultiCell(
                $colWidths[$i],   // width
                $maxh,            // height
                $cellText,        // text
                1,                // border
                'L',              // align
                false,            // fill
                0,                // ln (continue on same line)
                null,             // x
                null,             // y
                true,             // reseth
                0,                // stretch
                false,            // ishtml
                true,             // autopadding
                0,                // maxh
                'M'               // valign
            );
        }

        $this->Ln($maxh);
    }
    // =====================================================================
    // Render a section based on its format (codex-driven styling)
    // =====================================================================
    protected $isFirstSection = true;

    // =====================================================================
    // Render a section based on its format (codex-driven styling)
    // =====================================================================
    public function renderSection($key, $section, $iconMap, &$sections) {
        if (!isset($section['format'])) return;

        global $codex;
        $styling = isset($codex['documentStandards']['styling']['items']) ? $codex['documentStandards']['styling']['items'] : [];

        $this->currentSectionTitle = formatHeaderTitle($key);
        $this->currentSectionKey   = $key;
        $this->currentSectionIcon  = isset($section['icon']) ? $section['icon'] : null;

        // Unified spacing value (used everywhere)
        $sectionSpacing = 4;

        // Add spacing only if not the first section and more sections remain
        $sectionKeys = array_keys($sections);
        $currentIndex = array_search($key, $sectionKeys);
        if ($currentIndex !== false && $currentIndex > 0) { // Not first section
            $sectionsRemaining = array_slice($sectionKeys, $currentIndex + 1);
            if (!empty($sectionsRemaining)) {
                $this->Ln($sectionSpacing);
            }
        } else {
            $this->isFirstSection = false; // Reset if first section
        }

        // If the header wouldn’t fit at page bottom, move to next page
        if ($this->GetY() + 20 > $this->PageBreakTrigger) {
            $this->AddPage();
        }

        // Section icon + title (plain, no background)
        $iconFile = resolveHeaderIcon($this->currentSectionIcon, $iconMap);
        $startY   = $this->GetY();
        $startX   = 20;

        if ($iconFile) {
            $this->Image($iconFile, $startX, $startY, 8);
            $startX += 10;
        }

        $this->SetXY($startX, $startY);
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $this->currentSectionTitle, 0, 1, 'L', false);

        // Divider line under section header
        $this->SetDrawColor(200, 200, 200);
        $this->Line(20, $this->GetY(), 190, $this->GetY());

        // Reset to body styling
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 11);

        // Render body by format
        if ($section['format'] === 'text' && isset($section['text'])) {
            $this->MultiCell(0, 6, $section['text']);
        } elseif ($section['format'] === 'list' && isset($section['items'])) {
            foreach ($section['items'] as $item) {
                $this->Cell(0, 6, '• ' . $item, 0, 1);
            }
        } elseif ($section['format'] === 'table' && !empty($section['items']) && isset($section['items'][0])) {
            $this->isTableSection = true;
            $this->SetCellPadding(1);

            $headers    = array_keys($section['items'][0]);
            $numColumns = count($headers);
            $pageWidth  = $this->getPageWidth() - $this->lMargin - $this->rMargin;

            // Prefer codex colWidths (fractions); else smart defaults
            if (isset($section['colWidths']) && is_array($section['colWidths'])) {
                $colWidths = [];
                foreach ($section['colWidths'] as $w) {
                    $colWidths[] = $pageWidth * $w;
                }
            } elseif ($numColumns === 2) {
                $colWidths = [$pageWidth * 0.3, $pageWidth * 0.7];
            } else {
                $colWidth  = $pageWidth / $numColumns;
                $colWidths = array_fill(0, $numColumns, $colWidth);
            }

            $this->drawTableHeader($headers, $colWidths, $styling);
            foreach ($section['items'] as $row) {
                $this->drawTableRow($row, $headers, $colWidths);
            }
            $this->SetCellPadding(0);
            $this->isTableSection = false;
        }

        // No extra spacing at end to prevent double gaps or extra pages
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
// Use auto page break with a tighter threshold
$pdf->SetAutoPageBreak(true, 10);

$iconKey = null;
$titleText = $slug;

if (isset($module['title'])) {
    $parts = explode(' ', $module['title'], 2);
    if (count($parts) === 2) {
        $iconKey = $parts[0];
        $titleText = $parts[1];
    } else {
        $titleText = $module['title'];
    }
}

// Normalize header/title text → “Skyesoft Constitution”
$cleanTitle = ucwords(trim(str_replace(array('_', '-'), ' ', $titleText)));

$pdf->setReportTitle($cleanTitle, $iconKey);

$pdf->AddPage();

// Prepare sections for rendering
$sections = $module;
unset($sections['title']);
uasort($sections, function($a, $b) {
    return (isset($a['priority']) ? $a['priority'] : 999) - (isset($b['priority']) ? $b['priority'] : 999);
});

// Enrich blank sections with AI (dynamic, no hardcoding)
foreach ($sections as $key => &$section) {
    if (!isset($section['format'])) continue;

    $format = $section['format'];

    switch ($format) {
        case 'text':
            if (empty($section['text'])) {
                $section['text'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'text');
            }
            break;

        case 'list':
            if (empty($section['items'])) {
                $section['items'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'list');
            }
            break;

        case 'table':
            if (empty($section['items'])) {
                $section['items'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'table');
            }
            break;

        default:
            logMessage("⚠️ Skipping unsupported format '$format' in section '$key'.");
            break;
    }
}

// Render all sections
foreach ($sections as $key => $section) {
    $pdf->renderSection($key, $section, $iconMap, $sections);
}

// Trim trailing blank pages with stricter check
while ($pdf->getNumPages() > 3) {
    $pdf->setPage($pdf->getNumPages());
    $margins = $pdf->getMargins();
    $pageHeight = $pdf->getPageHeight() - $margins['top'] - $margins['bottom'] - $pdf->getFooterMargin();
    $contentHeight = $pdf->GetY() - $margins['top'];
    if ($contentHeight < 5 && $pdf->getNumPages() > 3) { // Tighter threshold
        $pdf->deletePage($pdf->getNumPages());
    } else {
        break;
    }
}

// =====================================================================
// Save and Output
// =====================================================================
$baseDir = ($type === 'information_sheet')
    ? __DIR__ . '/../docs/sheets/'
    : __DIR__ . '/../docs/reports/';

// Ensure directory exists
if (!is_dir($baseDir)) {
    $oldUmask = umask(0);
    $result = mkdir($baseDir, 0777, true);
    umask($oldUmask);
    if (!$result) {
        logError("❌ ERROR: Failed to create directory $baseDir");
        die();
    }
}

// Set proper file name
if ($type === 'information_sheet') {
    $outputFile = $baseDir . "Information Sheet - " . $cleanTitle . ".pdf";
} else {
    $outputFile = $baseDir . ucfirst($type) . " - " . $cleanTitle . ".pdf";
}

// Generate PDF
$pdf->Output($outputFile, $outputMode);

// Confirmation remark
if ($outputMode === 'F' && file_exists($outputFile)) {
    if ($type === 'information_sheet') {
        logMessage("✅ Information Sheet created for slug '$slug': " . $outputFile);
        echo "✅ Information Sheet created for slug '$slug': " . $outputFile . "\n";
    } else {
        logMessage("✅ Report created for slug '$slug': " . $outputFile);
        echo "✅ Report created for slug '$slug': " . $outputFile . "\n";
    }
} elseif ($outputMode === 'F') {
    logError("❌ ERROR: PDF generation failed. File not found after output.");
    echo "❌ ERROR: PDF generation failed. File not found after output.\n";
}