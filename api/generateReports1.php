<?php
// Enable full error reporting for debugging in PHP 5.6
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Polyfill for PHP 8.1+ array_is_list() function (PHP 5.6 compatible)
if (!function_exists('array_is_list')) {
    function array_is_list($array) {
        if (!is_array($array)) return false;
        $keys = array_keys($array);
        return $keys === array_keys($keys);
    }
}

// =====================================================================
// Skyesoft Dynamic Report Generator v2
// =====================================================================

// Dependencies
require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');

// Configuration
$config = array(
    'report_subtitle' => 'Skyesoft™ Information Sheet',
    'company_info' => '© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com'
);

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
$modules = array();
foreach ($codex as $key => $value) {
    if ($key === 'codexModules' && is_array($value)) {
        $modules = array_merge($modules, $value);
    } elseif (is_array($value) && isset($value['title'])) {
        $hasValidSection = false;
        foreach ($value as $sectionKey => $section) {
            if ($sectionKey !== 'title' && is_array($section) && isset($section['format']) && in_array($section['format'], array('text', 'list', 'table'))) {
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
        if ($key === 'title') continue;
        if (!is_array($section)) continue;
        if (!isset($section['format'])) continue;
        if (!in_array($section['format'], array('text', 'list', 'table'))) {
            logError("❌ ERROR: Invalid format for section '$key' in slug '$slug'. Must be 'text', 'list', or 'table'.");
            die();
        }
    }
}

// --------------------------
// Load iconMap.json dynamically
// --------------------------
$iconMapPath = __DIR__ . '/../assets/data/iconMap.json';
$iconMap = array();
if (file_exists($iconMapPath)) {
    $iconMap = json_decode(file_get_contents($iconMapPath), true);
    if (!is_array($iconMap)) {
        $iconMap = array();
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

if (!in_array($outputMode, array('I', 'D', 'F'))) {
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
    
    if (file_exists($cacheFile)) {
        $cachedContent = json_decode(file_get_contents($cacheFile), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (($format === 'text' && is_string($cachedContent) && !empty($cachedContent)) ||
                ($format === 'list' && is_array($cachedContent) && array_is_list($cachedContent) && !empty($cachedContent)) ||
                ($format === 'table' && is_array($cachedContent) && !empty($cachedContent) && is_array($cachedContent[0]))) {
                logMessage("✅ Loaded valid cached content for $slug/$key");
                return $cachedContent;
            } else {
                logMessage("⚠️ Invalid or empty cached content for $slug/$key, regenerating");
                unlink($cacheFile);
            }
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

// =====================================================================
// PDF Class Extension (SkyesoftPDF)
// =====================================================================
class SkyesoftPDF extends TCPDF {
    // Class Properties (top of SkyesoftPDF)
    public $headerHeight = 80;      // fixed header band height (pick what matches page 1)
    public $bodyMargin   = 0;      // gap under header
    public $bodyStartY   = 50;      // 90 will be set from headerHeight + bodyMargin
    public $footerHeight = 25;      // fixed footer block height
    public $bodyEndOffset;          // bottom margin used for page break (see #3)

    // Report-level state
    public $reportTitle;
    public $reportIcon;
    private $reportIconKey;

    // Section-level state
    public $currentSectionTitle;
    public $currentSectionKey;
    public $currentSectionIcon;

    // Other state
    private $config = array();
    private $isTableSection = false;

    public function __construct($config) {
        parent::__construct('P', 'pt', 'A4', true, 'UTF-8', false);
        $this->config = $config;

        // Fixed starting Y for body: header + margin
        $this->bodyStartY = $this->headerHeight + $this->bodyMargin;

        // Symmetric body end offset (same gap above footer as below header)
        $this->bodyEndOffset = $this->bodyStartY;
    }
    public function setReportTitle($title, $icon = null) {
        $this->reportTitle = $title;
        $this->reportIcon  = $icon;
        $this->reportIconKey = $icon;
    }

    public function Header() {
        global $iconMap, $requestor;

        // Legacy logo (left)
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        if (file_exists($logo)) {
            // 93.75 wide at (15,12) as before
            $this->Image($logo, 15, 12, 93.75, 0);
        }

        // Title block (right), legacy XY and sizes
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(120, 15);

        $iconFile = resolveHeaderIcon($this->reportIconKey, $iconMap);
        if ($iconFile) {
            $this->Image($iconFile, $this->GetX(), $this->GetY() - 2, 20);
            $this->SetX($this->GetX() + 24);
        }

        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report', 0, 1, 'L');

        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(120);
        $this->Cell(0, 6, $this->config['report_subtitle'], 0, 1, 'L');

        date_default_timezone_set('America/Phoenix');
        $this->SetFont('helvetica', '', 9);
        $this->SetX(120);
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by ' . $requestor, 0, 1, 'L');

        // Divider
        $this->Ln(2);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());

        // >>> Do NOT recompute headerHeight here. Just move to the fixed anchor:
        $this->SetY($this->bodyStartY);
    }

    public function Footer() {
        $this->SetY(-$this->footerHeight); // Fixed footer position
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());

        $this->SetY(-($this->footerHeight - 5)); // Adjust text position
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, $this->config['company_info'] . ' | Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }

    public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false) {
        parent::AddPage($orientation, $format, $keepmargins, $tocpage);

        // Force the cursor to start just below the header, same as page 1
        $this->SetY($this->headerHeight);
    }

    // ----------------------
    // Dynamic Table Rendering
    // ----------------------
    public function addTable(array $data) {
        $this->isTableSection = true;
        $this->SetCellPadding(2);

        if (empty($data) || !is_array($data[0])) {
            logError("❌ Invalid table data: Expected non-empty array of objects.");
            $this->isTableSection = false;
            return;
        }

        $headers = array_keys($data[0]);
        $numColumns = count($headers);
        $pageWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;

        if ($numColumns === 2) {
            $colWidths = array($pageWidth * 0.3, $pageWidth * 0.7);
        } else {
            $colWidth = $pageWidth / $numColumns;
            $colWidths = array_fill(0, $numColumns, $colWidth);
        }

        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('', 'B');
        $x = $this->GetX();
        foreach ($headers as $i => $header) {
            $this->Cell($colWidths[$i], 10, ucwords(str_replace('_', ' ', $header)), 1, 0, 'C', true);
            $this->SetXY($x += $colWidths[$i], $this->GetY());
        }
        $this->Ln();
        $this->SetFont('');

        foreach ($data as $row) {
            $x = $this->GetX();
            $y = $this->GetY();
            $maxHeight = 0;
            $cellHeights = array();

            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
                if ($cellHeight < 10) {
                    $cellHeight = 10;
                }
                $cellHeights[$i] = $cellHeight;
                if ($cellHeight > $maxHeight) {
                    $maxHeight = $cellHeight;
                }
            }

            if ($this->GetY() + $maxHeight > $this->PageBreakTrigger - $this->footerHeight) {
                $this->AddPage();
                $x = $this->GetX();
                $y = $this->GetY();
                $this->SetFillColor(230, 230, 230);
                $this->SetFont('', 'B');
                foreach ($headers as $i => $header) {
                    $this->Cell($colWidths[$i], 10, ucwords(str_replace('_', ' ', $header)), 1, 0, 'C', true);
                    $this->SetXY($x += $colWidths[$i], $this->GetY());
                }
                $this->Ln();
                $this->SetFont('');
                $x = $this->GetX();
                $y = $this->GetY();
            }

            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $this->MultiCell($colWidths[$i], $maxHeight, $value, 1, 'L', false, 0, $x, $y, true, 0, false, true, $maxHeight, 'M');
                $x += $colWidths[$i];
            }

            $this->SetXY($this->lMargin, $y + $maxHeight);
        }

        $this->SetCellPadding(0);
        $this->isTableSection = false;
    }

    public function renderSection($key, $section, $iconMap, &$sections) {
        logMessage("DEBUG Entering renderSection for key: $key");

        if (!isset($section['format'])) {
            logMessage("⚠️ Skipping section '$key' due to missing format.");
            return;
        }

        logMessage("ℹ️ Rendering section '$key' with format '{$section['format']}'.");

        if ($section['format'] === 'table'
            && (!is_array($section['items']) || empty($section['items']) || !is_array($section['items'][0]))) {
            logError("❌ Invalid table data for section '$key'.");
            return;
        }

        global $codex;
        $styling = isset($codex['documentStandards']['styling']['items'])
            ? $codex['documentStandards']['styling']['items']
            : array();

        $this->currentSectionTitle = formatHeaderTitle($key);
        $this->currentSectionKey   = $key;
        $this->currentSectionIcon  = isset($section['icon']) ? $section['icon'] : null;

        // --- START TRANSACTION ---
        $this->startTransaction();
        $startPage = $this->getPage();
        $startY    = $this->GetY();

        // Add spacing before section (except at top of page body)
        if ($this->GetY() > $this->bodyStartY) {
            $this->Ln(4);
        }


        // ---- Section Header ----
        $iconFile = resolveHeaderIcon($this->currentSectionIcon, $iconMap);
        $startX   = 20;

        if ($iconFile) {
            $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
            $startX += 24;
        }

        $this->SetXY($startX, $this->GetY());
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $this->currentSectionTitle, 0, 1, 'L', false);

        $this->SetDrawColor(200, 200, 200);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());

        $this->Ln(4);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 11);

        // ---- Render Body ----
        if ($section['format'] === 'text' && isset($section['text'])) {
            $this->MultiCell(0, 6, $section['text'], 0, 'L', false);
        } elseif ($section['format'] === 'list' && isset($section['items']) && is_array($section['items'])) {
            foreach ($section['items'] as $item) {
                $this->Cell(10);
                $this->Cell(0, 6, '• ' . $item, 0, 1);
            }
        } elseif ($section['format'] === 'table'
            && isset($section['items'])
            && is_array($section['items'])
            && count($section['items']) > 0
            && is_array($section['items'][0])) {
            $this->addTable($section['items']);
        }

        $this->Ln(4);
        $this->currentSectionIcon = null;

        // --- END TRANSACTION ---
        if ($this->getPage() !== $startPage || $this->GetY() > $this->PageBreakTrigger) {
            // rollback and move section to new page
            $this->rollbackTransaction(true);
            $this->AddPage();
            $this->SetY($this->headerHeight + $this->bodyMargin); // consistent start
            // re-render the section cleanly on the new page
            $this->renderSection($key, $section, $iconMap, $sections);
        } else {
            $this->commitTransaction();
        }
    }

    private function drawTableHeader($headers, $colWidths, $styling) {
        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('', 'B');
        $x = $this->GetX();
        foreach ($headers as $i => $header) {
            $this->Cell($colWidths[$i], 10, $header, 1, 0, 'C', true);
            $this->SetXY($x += $colWidths[$i], $this->GetY());
        }
        $this->Ln();
        $this->SetFont('');
    }

    private function drawTableRow($row, $headers, $colWidths) {
        $x = $this->GetX();
        $y = $this->GetY();
        $maxHeight = 0;
        $cellHeights = array();

        foreach ($headers as $i => $header) {
            $value = isset($row[$header]) ? $row[$header] : '';
            $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
            if ($cellHeight < 10) {
                $cellHeight = 10;
            }
            $cellHeights[$i] = $cellHeight;
            if ($cellHeight > $maxHeight) {
                $maxHeight = $cellHeight;
            }
        }

        if ($this->GetY() + $maxHeight > $this->PageBreakTrigger - $this->footerHeight) {
            $this->AddPage();
            $x = $this->GetX();
            $y = $this->GetY();
        }

        foreach ($headers as $i => $header) {
            $value = isset($row[$header]) ? $row[$header] : '';
            $this->MultiCell($colWidths[$i], $maxHeight, $value, 1, 'L', false, 0, $x, $y, true, 0, false, true, $maxHeight, 'M');
            $x += $colWidths[$i];
        }

        $this->SetXY($this->lMargin, $y + $maxHeight);
    }

    public function resetSectionIcon() {
        $this->currentSectionIcon = null;
    }
}

// =====================================================================
// Build PDF
// =====================================================================
logMessage("DEBUG Script started at " . date('Y-m-d H:i:s'));
$pdf = new SkyesoftPDF($config);
$pdf->SetCreator('Skyesoft Report Generator');
$pdf->SetAuthor('Skyesoft');
// Build section (replace the two lines you have now)
$pdf->SetMargins(20, $pdf->bodyStartY, 20); // keep top margin neutral; we control start with SetY
$pdf->SetAutoPageBreak(true, $pdf->bodyEndOffset); // use symmetric bottom gap
$pdf->SetHeaderMargin(0); // No additional header margin
$pdf->SetFooterMargin(0); // No additional footer margin

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

$cleanTitle = ucwords(trim(str_replace(array('_', '-'), ' ', $titleText)));

$pdf->setReportTitle($cleanTitle, $iconKey);

$pdf->AddPage();

$sections = $module;
unset($sections['title']);
uasort($sections, function($a, $b) {
    return (isset($a['priority']) ? $a['priority'] : 999) - (isset($b['priority']) ? $b['priority'] : 999);
});

foreach ($sections as $key => &$section) {
    if (!isset($section['format'])) {
        continue;
    }

    $format = strtolower(trim($section['format']));
    if (!in_array($format, array('text', 'list', 'table'))) {
        logError("❌ Invalid format for '$key', defaulting to text.");
        $format = 'text';
    }
    $section['format'] = $format;

    if (isset($section['items']) && is_array($section['items'])) {
        if (is_array(reset($section['items']))) {
            $format = 'table';
        } elseif (is_string(reset($section['items']))) {
            $format = 'list';
        }
        $section['format'] = $format;
    }

    if ($format === 'text' && isset($section['text']) && trim($section['text']) !== '') {
        logMessage("ℹ️ Skipping enrichment for '$key' (codex already has text).");
        continue;
    }

    if ($format === 'list' && isset($section['items']) && is_array($section['items']) && count($section['items']) > 0 && is_string(current($section['items']))) {
        logMessage("ℹ️ Skipping enrichment for '$key' (codex already has list items).");
        continue;
    }

    if ($format === 'table' && isset($section['items']) && is_array($section['items']) && count($section['items']) > 0) {
        $firstRow = current($section['items']);
        if (is_array($firstRow)) {
            logMessage("ℹ️ Skipping enrichment for '$key' (codex already has table items).");
            continue;
        }
    }

    logMessage("ℹ️ Enriching section '$key' with format '{$format}'.");

    switch ($format) {
        case 'text':
            $section['text'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'text');
            break;
        case 'list':
            $section['items'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'list');
            break;
        case 'table':
            $section['items'] = getAIEnrichedBody($slug, $key, $moduleForAI, $OPENAI_API_KEY, 'table');
            break;
    }

    if ($format === 'text' && empty($section['text'])) {
        logError("❌ Invalid text data for '$key'. Expected non-empty string.");
        $section['text'] = '';
    } elseif ($format === 'list' && (!is_array($section['items']) || empty($section['items']))) {
        logError("❌ Invalid list data for '$key'. Expected array of strings.");
        $section['items'] = array();
    } elseif ($format === 'table' && (!is_array($section['items']) || empty($section['items']) || !is_array(current($section['items'])))) {
        logError("❌ Invalid table data for '$key'. Expected array of objects.");
        $section['items'] = array();
    }
}
unset($section);

if (isset($sections['glossary'])) {
    $gloss = $sections['glossary'];
    $fmt   = isset($gloss['format']) ? $gloss['format'] : 'MISSING';
    $cnt   = (isset($gloss['items']) && is_array($gloss['items'])) ? count($gloss['items']) : 0;
    logMessage("DEBUG sanity Glossary → format={$fmt}, items={$cnt}");
    if ($cnt > 0) {
        logMessage("DEBUG sanity Glossary first row: " . print_r($gloss['items'][0], true));
    }
}

$sectionKeys = array_keys($sections);
$totalSections = count($sectionKeys);

foreach ($sectionKeys as $i => $key) {
    $section = $sections[$key];
    $pdf->resetSectionIcon();
    $pdf->renderSection($key, $section, $iconMap, $sections);

    if ($i < $totalSections - 1) {
        $pdf->Ln($consistent_spacing);
    }
}

while ($pdf->getNumPages() > 3) {
    $pdf->setPage($pdf->getNumPages());
    $margins = $pdf->getMargins();
    $pageHeight = $pdf->getPageHeight() - $margins['top'] - $margins['bottom'] - $pdf->getFooterMargin();
    $contentHeight = $pdf->GetY() - $margins['top'];
    if ($contentHeight < 5 && $pdf->getNumPages() > 3) {
        $pdf->deletePage($pdf->getNumPages());
    } else {
        break;
    }
}

$baseDir = ($type === 'information_sheet')
    ? __DIR__ . '/../docs/sheets/'
    : __DIR__ . '/../docs/reports/';

if (!is_dir($baseDir)) {
    $oldUmask = umask(0);
    $result = mkdir($baseDir, 0777, true);
    umask($oldUmask);
    if (!$result) {
        logError("❌ ERROR: Failed to create directory $baseDir");
        die();
    }
}

if ($type === 'information_sheet') {
    $outputFile = $baseDir . "Information Sheet - " . $cleanTitle . ".pdf";
} else {
    $outputFile = $baseDir . ucfirst($type) . " - " . $cleanTitle . ".pdf";
}

$pdf->Output($outputFile, $outputMode);

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

function formatHeaderTitle($key) {
    global $codex;

    // 1. Prefer explicit title in codex.json if it exists
    if (isset($codex['skyesoftConstitution'][$key]['title'])) {
        return $codex['skyesoftConstitution'][$key]['title'];
    }

    // 2. Otherwise, split camelCase, snake_case, and kebab-case
    $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key); // split camelCase
    $key = str_replace(['_', '-'], ' ', $key);              // handle snake/kebab

    // 3. Normalize spaces and capitalize
    return ucwords(trim($key));
}

function resolveHeaderIcon($iconKey, $iconMap) {
    if (!$iconKey || !isset($iconMap[$iconKey]) || !isset($iconMap[$iconKey]['file'])) {
        return null;
    }
    $iconFile = __DIR__ . '/../assets/images/icons/' . $iconMap[$iconKey]['file'];
    return file_exists($iconFile) ? $iconFile : null;
}