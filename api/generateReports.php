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

// ✅ Load .env manually for GoDaddy PHP 5.6
$envPath = '/home/notyou64/.env';
if (file_exists($envPath)) {
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // skip comments
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name  = trim($parts[0]);
            $value = trim($parts[1]);
            if (!getenv($name)) {
                putenv($name . '=' . $value);
            }
        }
    }
}

// 🔐 Retrieve API key after loading .env
$OPENAI_API_KEY = getenv('OPENAI_API_KEY');
if (!$OPENAI_API_KEY) {
    echo "❌ ERROR: Missing OpenAI API Key. Check /home/notyou64/public_html/skyesoft/.env\n";
    exit(1);
}

// --------------------------
// Load codex.json dynamically
// --------------------------
$codexPath = __DIR__ . '/../assets/data/codex.json';
// Check if the codex file exists
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

if (!is_array($input)) {
    logError("❌ ERROR: Invalid input JSON.\nParsed input: " . print_r($input, true));
    die();
}

// Sanitize input
$slug = isset($input['slug']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $input['slug']) : '';
$type = isset($input['type']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $input['type']) : 'information_sheet';
$requestor = isset($input['requestor']) ? preg_replace('/[^A-Za-z0-9\s]/', '', $input['requestor']) : 'Skyesoft';
$outputMode = isset($input['outputMode']) ? strtoupper($input['outputMode']) : 'F';
$mode = isset($input['mode']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $input['mode']) : 'single';

if (!in_array($outputMode, array('I', 'D', 'F'))) {
    logError("❌ ERROR: Invalid outputMode '$outputMode'. Must be 'I', 'D', or 'F'.");
    die();
}

$isFull = ($mode === 'full');

if (!$isFull) {
    if (empty($slug) || !isset($modules[$slug])) {
        logError("❌ ERROR: Slug '$slug' not found in Codex.");
        die();
    }
    $currentModules = array($slug => $modules[$slug]);
} else {
    $currentModules = $modules;
}

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
// Enrichment Control Helper
// =====================================================================
function getEnrichmentPrompt($slug, $key, $enrichment, $moduleData) {
    switch (strtolower($enrichment)) {
        case 'none':
            $desc = "No AI enrichment is permitted. Use codex content exactly as written.";
            break;
        case 'light':
            $desc = "Use light enrichment. You may correct tone, grammar, and minor phrasing only.";
            break;
        case 'medium':
            $desc = "Use medium enrichment. You may expand lightly, add short examples or clarifying context, but avoid major rewrites.";
            break;
        case 'heavy':
            $desc = "Use heavy enrichment. You may elaborate with detailed explanations, analogies, or workflow expansions.";
            break;
        default:
            $desc = "Default light enrichment applies. Perform minimal improvement for clarity and readability.";
    }

    logMessage("💡 Enrichment mode for $slug/$key: " . strtoupper($enrichment));
    return "Enrichment Policy: " . strtoupper($enrichment) . " — " . $desc .
        "\n\nModule Context:\n" . json_encode($moduleData, JSON_PRETTY_PRINT);
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

    $enrichmentPrompt = getEnrichmentPrompt($slug, $key, isset($moduleData['enrichment']) ? $moduleData['enrichment'] : 'none', $moduleData);
    $prompt = $enrichmentPrompt . "\n\n" . $basePrompt;

    if ($format === 'text') {
        $prompt .= "\n\nOnly generate narrative text for this section.";
    } elseif ($format === 'list') {
        $prompt .= "\n\nGenerate the list items for this section as a JSON array of strings.";
    } elseif ($format === 'table') {
        $prompt .= "\n\nGenerate the table data for this section as a JSON array of objects, where each object has keys corresponding to the table columns.";
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
    // Helper: Estimate number of lines (renamed to avoid conflict with TCPDF::getNumLines)
    // ----------------------
    private function estimateNumLines($text, $width) {
        if (empty($text)) return 0;
        $height = $this->getStringHeight($width, $text);
        return ceil($height / 6);
    }

    // ----------------------
    // Estimate section body height
    // ----------------------
    private function estimateSectionBodyHeight($section) {
        $format = $section['format'];
        $fullWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $lineHeight = 6;
        $totalHeight = 0;

        if ($format === 'text' && isset($section['text'])) {
            $totalHeight = $this->getStringHeight($fullWidth, $section['text']);
        } elseif ($format === 'list' && isset($section['items']) && is_array($section['items'])) {
            $indent = 10;
            $bulletWidth = 5;
            $spaceAfterBullet = 5;
            $textWidth = $fullWidth - $indent - $bulletWidth - $spaceAfterBullet;
            foreach ($section['items'] as $item) {
                $totalHeight += $this->getStringHeight($textWidth, $item);
            }
        } elseif ($format === 'table' && isset($section['items']) && is_array($section['items'])) {
            if (empty($section['items'])) return 0;
            $headers = array_keys($section['items'][0]);
            $numColumns = count($headers);
            $pageWidth = $fullWidth;
            if ($numColumns === 2) {
                $colWidths = array($pageWidth * 0.3, $pageWidth * 0.7);
            } else {
                $colWidth = $pageWidth / $numColumns;
                $colWidths = array_fill(0, $numColumns, $colWidth);
            }
            $headerHeight = 10;
            $totalHeight += $headerHeight;
            foreach ($section['items'] as $row) {
                $maxHeight = 0;
                foreach ($headers as $i => $header) {
                    $value = isset($row[$header]) ? $row[$header] : '';
                    $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
                    if ($cellHeight < 10) {
                        $cellHeight = 10;
                    }
                    if ($cellHeight > $maxHeight) {
                        $maxHeight = $cellHeight;
                    }
                }
                $totalHeight += $maxHeight;
            }
        }

        return $totalHeight;
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

        // Pre-check for entire table fit
        $estimatedTableHeight = 10; // header
        foreach ($data as $row) {
            $maxHeight = 0;
            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
                if ($cellHeight < 10) {
                    $cellHeight = 10;
                }
                if ($cellHeight > $maxHeight) {
                    $maxHeight = $cellHeight;
                }
            }
            $estimatedTableHeight += $maxHeight;
        }
        $availableSpace = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
        if ($estimatedTableHeight > $availableSpace) {
            $sectionTitle = isset($this->currentSectionTitle) ? $this->currentSectionTitle : 'Unknown Section';
            logMessage("⚙️ Entire table moved to next page for better fit (section: " . $sectionTitle . ")");
            $this->AddPage();
        }

        // Draw header row
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

        // Draw data rows (improved to prevent final-row loss)
        foreach (array_values($data) as $rowIndex => $row) {
            $x = $this->GetX();
            $y = $this->GetY();
            $maxHeight = 0;
            $cellHeights = array();

            // Calculate cell heights for this row
            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
                if ($cellHeight < 10) $cellHeight = 10;
                $cellHeights[$i] = $cellHeight;
                if ($cellHeight > $maxHeight) $maxHeight = $cellHeight;
            }

            // Check for available space before drawing
            $available = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
            if ($maxHeight > $available) {
                $this->AddPage();
                $this->SetY($this->bodyStartY);

                // Render continued section header
                $contTitle = $this->currentSectionTitle . ' – Continued';
                $startX = 20;
                // ✅ Safe procedural version
                if (!isset($iconMap)) {
                    $iconMap = array();
                }
                $iconFile = resolveHeaderIcon(
                    isset($currentSectionIcon) ? $currentSectionIcon : '',
                    $iconMap
                );

                //  Draw icon if available
                if ($iconFile) {
                    $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
                    $startX += 24;
                }
                $this->SetXY($startX, $this->GetY());
                $this->SetFont('helvetica', 'B', 14);
                $this->SetTextColor(0, 0, 0);
                $this->Cell(0, 8, $contTitle, 0, 1, 'L', false);
                $this->SetDrawColor(200, 200, 200);
                $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
                $this->Ln(4);
                $this->SetFont('helvetica', '', 11);

                // Reprint table header on new page
                $x = $this->GetX();
                $this->SetFillColor(230, 230, 230);
                $this->SetFont('', 'B');
                foreach ($headers as $i => $header) {
                    $this->Cell($colWidths[$i], 10, ucwords(str_replace('_', ' ', $header)), 1, 0, 'C', true);
                    $x += $colWidths[$i];
                }
                $this->Ln();
                $this->SetFont('');
            }

            // Draw the row
            $x = $this->GetX();
            $y = $this->GetY();
            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $this->MultiCell($colWidths[$i], $maxHeight, $value, 1, 'L', false, 0, $x, $y, true, 0, false, true, $maxHeight, 'M');
                $x += $colWidths[$i];
            }
            $this->Ln($maxHeight);

            // ✅ After rendering the final row, ensure cursor advancement to trigger new page if needed
            if ($rowIndex === count($data) - 1 && $this->GetY() > ($this->PageBreakTrigger - $this->footerHeight)) {
                $this->AddPage();
                $this->SetY($this->bodyStartY);
            }
        }
        $this->isTableSection = false;
    }

    public function renderTextWithContinuation($text, $iconMap) {
        if (empty($text)) return;

        $fullWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $lineHeight = 6;
        $minHeight = $lineHeight * 2; // Minimum chunk height to avoid tiny fragments

        $sectionTitle = isset($this->currentSectionTitle) ? $this->currentSectionTitle : 'Unknown Section';

        // Pre-check if entire text fits; if not, move to next page
        $estimatedHeight = $this->getStringHeight($fullWidth, $text);
        $availableSpace = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
        if ($estimatedHeight > $availableSpace) {
            logMessage("⚙️ Entire text section moved to next page for better fit (section: " . $sectionTitle . ")");
            $this->AddPage();
            $this->SetY($this->bodyStartY);
        }

        while (trim($text) !== '') {
            $currentY = $this->GetY();
            $availableHeight = $this->PageBreakTrigger - $currentY - $this->footerHeight;

            // If not enough space for min chunk, add page with continued header
            if ($availableHeight < $minHeight) {
                $this->AddPage();
                $this->SetY($this->bodyStartY);

                // Render continued header
                $contTitle = $this->currentSectionTitle . ' – Continued';
                $startX = 20;
                $iconFile = resolveHeaderIcon($this->currentSectionIcon, $iconMap);
                if ($iconFile) {
                    $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
                    $startX += 24;
                }
                $this->SetXY($startX, $this->GetY());
                $this->SetFont('helvetica', 'B', 14);
                $this->SetTextColor(0, 0, 0);
                $this->Cell(0, 8, $contTitle, 0, 1, 'L', false);
                $this->SetDrawColor(200, 200, 200);
                $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
                $this->Ln(4);
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('helvetica', '', 11);

                // Recalculate available after header
                $currentY = $this->GetY();
                $availableHeight = $this->PageBreakTrigger - $currentY - $this->footerHeight;
            }

            // Calculate max lines that fit
            $maxLines = floor(($availableHeight - $lineHeight) / $lineHeight);
            if ($maxLines <= 0) $maxLines = 1;

            // Binary search for the maximum character position that fits in maxLines
            $low = 0;
            $high = strlen($text);
            while ($low < $high) {
                $mid = (int)(($low + $high + 1) / 2);
                $chunk = substr($text, 0, $mid);
                $numLinesChunk = $this->estimateNumLines($chunk, $fullWidth);
                if ($numLinesChunk <= $maxLines) {
                    $low = $mid;
                } else {
                    $high = $mid - 1;
                }
            }
            $splitPos = $low;

            if ($splitPos == 0) {
                // Fallback if nothing fits
                $splitPos = min(200, strlen($text)); // Arbitrary small chunk
            }

            // Prefer splitting at sentence end, comma, or space (prioritized)
            $chunkPrefix = substr($text, 0, $splitPos);
            $splitAt = $splitPos;

            $dotPos = strrpos($chunkPrefix, '.');
            if ($dotPos !== false) {
                $candidate = $dotPos + 2; // ". "
                $testChunk = substr($text, 0, $candidate);
                if ($this->estimateNumLines($testChunk, $fullWidth) <= $maxLines) {
                    $splitAt = $candidate;
                }
            } elseif (($commaPos = strrpos($chunkPrefix, ',')) !== false) {
                $candidate = $commaPos + 2; // ", "
                $testChunk = substr($text, 0, $candidate);
                if ($this->estimateNumLines($testChunk, $fullWidth) <= $maxLines) {
                    $splitAt = $candidate;
                } else {
                    $spacePos = strrpos($chunkPrefix, ' ');
                    if ($spacePos !== false) {
                        $splitAt = $spacePos + 1;
                    }
                }
            } else {
                $spacePos = strrpos($chunkPrefix, ' ');
                if ($spacePos !== false) {
                    $splitAt = $spacePos + 1;
                }
            }

            if ($splitAt < $splitPos * 0.5) {
                $splitAt = $splitPos; // Avoid too early splits
            }

            $chunk = substr($text, 0, $splitAt);
            $text = ltrim(substr($text, $splitAt));

            // Render the chunk
            $this->MultiCell($fullWidth, $lineHeight, $chunk, 0, 'L', false);
        }
    }

    public function renderSection($key, $section, $iconMap, $sections) {
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

        // Add spacing before section (except at top of page body)
        if ($this->GetY() > $this->bodyStartY) {
            $this->Ln(4);
        }

        // Estimate total section height and pre-check if it fits
        $approxHeaderHeight = 30; // icon + title + line + ln
        $bodyHeight = $this->estimateSectionBodyHeight($section);
        $totalSectionHeight = $approxHeaderHeight + $bodyHeight + 4; // + bottom spacing
        $remainingSpace = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
        if ($remainingSpace < $totalSectionHeight) {
            $sectionTitle = $this->currentSectionTitle;
            logMessage("⚙️ Entire section moved to next page for better fit (section: " . $sectionTitle . ")");
            $this->AddPage();
            $this->SetY($this->bodyStartY);
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
            $this->renderTextWithContinuation($section['text'], $iconMap);
        } elseif ($section['format'] === 'list' && isset($section['items']) && is_array($section['items'])) {
            $itemsRenderedOnPage = 0;
            $indent = 10;
            $bulletWidth = 5;
            $spaceAfterBullet = 5;
            $textIndent = $indent + $bulletWidth + $spaceAfterBullet;
            $fullWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
            $textWidth = $fullWidth - $textIndent;
            $lineHeight = 6;
            foreach ($section['items'] as $item) {
                $approxHeight = $this->getStringHeight($textWidth, $item);
                if ($approxHeight < $lineHeight) $approxHeight = $lineHeight;

                if ($this->GetY() + $approxHeight > $this->PageBreakTrigger - $this->footerHeight - 10) {
                    if ($itemsRenderedOnPage > 0) {
                        $this->AddPage();
                        $this->SetY($this->bodyStartY);
                        // Render continued header
                        $contTitle = $this->currentSectionTitle . ' – Continued';
                        $startX = 20;
                        if ($this->currentSectionIcon) {
                            $iconFile = resolveHeaderIcon($this->currentSectionIcon, $iconMap);
                            if ($iconFile) {
                                $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
                                $startX += 24;
                            }
                        }
                        $this->SetXY($startX, $this->GetY());
                        $this->SetFont('helvetica', 'B', 14);
                        $this->Cell(0, 8, $contTitle, 0, 1, 'L', false);
                        $this->SetDrawColor(200, 200, 200);
                        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
                        $this->Ln(4);
                        $this->SetFont('helvetica', '', 11);
                        $itemsRenderedOnPage = 0;
                    }
                }

                // Render item with wrapping
                $this->SetX($this->lMargin + $indent);
                $this->Cell($bulletWidth, $lineHeight, '•', 0, 0, 'L');
                $this->SetX($this->GetX() + $spaceAfterBullet);
                $this->MultiCell($textWidth, $lineHeight, $item, 0, 'L', false);
                $itemsRenderedOnPage++;
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
    }

    public function resetSectionIcon() {
        $this->currentSectionIcon = null;
    }
}

// ===============================================================
// TIS: Inject live schedules + holidays (Office/Shop + Holidays Table)
// ===============================================================
if (isset($currentModules['timeIntervalStandards'])) {
    $tis =& $currentModules['timeIntervalStandards'];

    // (A) Pull live segments from SSE (Office/Shop)
    $dynUrl = 'https://skyelighting.com/skyesoft/api/getDynamicData.php';
    $dynRaw = @file_get_contents($dynUrl);
    if ($dynRaw !== false) {
        $dyn = json_decode($dynRaw, true);

        // Schedules from SSE payload (timeIntervalStandards.segments)
        if (isset($dyn['timeIntervalStandards']['segments'])) {
            $segments = $dyn['timeIntervalStandards']['segments'];

            // Office
            if (isset($segments['Office'])) {
                $tis['officeSchedule']['items'] = array(
                    array('Interval' => 'Before Worktime', 'Hours' => $segments['Office']['before']),
                    array('Interval' => 'Worktime',        'Hours' => $segments['Office']['worktime']),
                    array('Interval' => 'After Worktime',  'Hours' => $segments['Office']['after'])
                );
            }

            // Shop
            if (isset($segments['Shop'])) {
                $tis['shopSchedule']['items'] = array(
                    array('Interval' => 'Before Worktime', 'Hours' => $segments['Shop']['before']),
                    array('Interval' => 'Worktime',        'Hours' => $segments['Shop']['worktime']),
                    array('Interval' => 'After Worktime',  'Hours' => $segments['Shop']['after'])
                );
            }

            logMessage('✅ TIS schedules injected from SSE segments.');
        }

        // (B) Holidays from SSE if present (supports array of {name,date} or strings)
        $holidayRows = array();
        if (isset($dyn['holidays']) && is_array($dyn['holidays'])) {
            foreach ($dyn['holidays'] as $h) {
                if (is_array($h) && isset($h['name']) && isset($h['date'])) {
                    $holidayRows[] = array('Holiday' => $h['name'], 'Date' => $h['date']);
                } elseif (is_string($h)) {
                    $holidayRows[] = array('Holiday' => $h, 'Date' => '');
                }
            }
        }

        // (C) Fallback: local federalHolidays.php (if SSE does not provide)
        if (empty($holidayRows)) {
            $localPath = __DIR__ . '/../api/federalHolidays.php';
            if (!file_exists($localPath)) {
                $localPath = __DIR__ . '/../federalHolidays.php'; // alt location
            }

            if (file_exists($localPath)) {
                // Safely include without echo leak
                define('SKYESOFT_INTERNAL_CALL', true);
                $fhData = include($localPath);

                if (is_array($fhData) && count($fhData) > 0) {
                    foreach ($fhData as $date => $name) {
                        if (preg_match('/\d{4}-\d{2}-\d{2}/', $date)) {
                            $holidayRows[] = array(
                                'Date' => date('m/d/Y', strtotime($date)),
                                'Holiday' => $name
                            );
                        }
                    }
                    logMessage('✅ Holidays loaded internally (' . count($holidayRows) . ' entries).');
                } else {
                    logError('⚠️ No valid holiday data returned by federalHolidays.php include.');
                }
            } else {
                logError('⚠️ Fallback file not found: ' . $localPath);
            }
        }

        // Inject holidays into TIS module
        if (!empty($holidayRows)) {
            $tis['holidays']['items'] = $holidayRows;
            logMessage('✅ Holidays table populated with ' . count($holidayRows) . ' rows.');
        }
    } else {
        logError('⚠️ Could not reach SSE dynamic data for TIS: ' . $dynUrl);
    }
}

// =====================================================================
// Enrich Content
// =====================================================================
foreach ($currentModules as $modSlug => &$mod) {
    $modForAI = $mod;
    unset($modForAI['title']);

    $enrichment = isset($mod['enrichment']) ? strtolower($mod['enrichment']) : 'none';
    if ($enrichment === 'none') {
        logMessage("🚫 Skipping AI enrichment for module '$modSlug' due to enrichment=none");
        continue;
    }

    $sectionKeys = array();
    foreach ($mod as $skey => $sval) {
        if ($skey !== 'title' && isset($sval['format'])) {
            $sectionKeys[$skey] = isset($sval['priority']) ? intval($sval['priority']) : 999;
        }
    }
    asort($sectionKeys);

    foreach ($sectionKeys as $key => $pri) {
        $section =& $mod[$key];
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
                $section['text'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'text');
                break;
            case 'list':
                $section['items'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'list');
                break;
            case 'table':
                $section['items'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'table');
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
}
unset($mod);

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

$modulesToRender = $currentModules;
if ($isFull) {
    $pdf->setReportTitle('Skyesoft Codex', null);
} else {
    $slug = key($modulesToRender);
    $module = $modulesToRender[$slug];
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
}

foreach ($modulesToRender as $modSlug => $module) {
    $pdf->AddPage();
    $pdf->SetY($pdf->bodyStartY);

    if ($isFull) {
        // Render module title header
        $parts = explode(' ', $module['title'], 2);
        $modIconKey = (count($parts) === 2) ? $parts[0] : null;
        $modTitleText = (count($parts) === 2) ? $parts[1] : $module['title'];
        $modCleanTitle = ucwords(trim(str_replace(array('_', '-'), ' ', $modTitleText)));

        $iconFile = resolveHeaderIcon($modIconKey, $iconMap);
        $startX = 20;
        $currentY = $pdf->GetY();
        if ($iconFile) {
            $pdf->Image($iconFile, $startX, $currentY - 2, 20);
            $startX += 24;
        }
        $pdf->SetXY($startX, $currentY);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 10, $modCleanTitle, 0, 1, 'L', false);
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', '', 11);
    }

    // Get sorted section keys
    $modSectionKeys = array();
    foreach ($module as $skey => $sval) {
        if ($skey !== 'title' && isset($sval['format'])) {
            $modSectionKeys[$skey] = isset($sval['priority']) ? intval($sval['priority']) : 999;
        }
    }
    asort($modSectionKeys);
    $sortedSectionKeys = array_keys($modSectionKeys);
    $totalModSections = count($sortedSectionKeys);

    foreach ($sortedSectionKeys as $i => $key) {
        $section = $module[$key];
        $pdf->resetSectionIcon();
        $pdf->renderSection($key, $section, $iconMap, array());

        if ($i < $totalModSections - 1) {
            $pdf->Ln($consistent_spacing);
        }
    }

    if ($isFull) {
        $pdf->Ln(10);
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

if ($isFull) {
    $outputFile = $baseDir . "Information Sheet - Skyesoft Codex.pdf";
} elseif ($type === 'information_sheet') {
    $outputFile = $baseDir . "Information Sheet - " . $cleanTitle . ".pdf";
} else {
    $outputFile = $baseDir . ucfirst($type) . " - " . $cleanTitle . ".pdf";
}

$pdf->Output($outputFile, $outputMode);

if ($outputMode === 'F' && file_exists($outputFile)) {
    if ($isFull) {
        logMessage("✅ Full Codex Information Sheet created: " . $outputFile);
        echo "✅ Full Codex Information Sheet created: " . $outputFile . "\n";
    } elseif ($type === 'information_sheet') {
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