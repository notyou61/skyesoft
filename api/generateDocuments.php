<?php
// ======================================================================
//  FILE: generateDocuments.php
//  PURPOSE: Skyesoft™ Codex-Driven Document Generator (with AI Enrichment)
//  VERSION: v2.0.0  (PHP 5.6 Compatible)
//  AUTHOR: CPAP-01 Parliamentarian Integration
// ======================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ----------------------------------------------------------------------
//  STEP 0 – Load Environment (.env) for GoDaddy PHP 5.6 Compatibility
// ----------------------------------------------------------------------
$envPaths = array(
    __DIR__ . '/../.env',                        // project-level (local dev)
    '/home/notyou64/.env',                       // account-level (preferred)
    '/home/notyou64/public_html/skyesoft/.env'   // fallback if copied to webroot
);

foreach ($envPaths as $envFile) {
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || strpos(trim($line), '=') === false) continue;

            list($name, $value) = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // Populate all standard superglobals
            putenv($name . '=' . $value);
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
        break; // stop after first valid .env
    }
}

// ----------------------------------------------------------------------
//  STEP 1 – INPUT & METHOD VALIDATION (Codex-Compliant, slug-only)
// ----------------------------------------------------------------------
$isCli = (php_sapi_name() === 'cli');

// ---- CLI usage ----
// php api/generateDocuments.php timeIntervalStandards
if ($isCli) {
    $input = array();
    if (isset($argv[1])) {
        $input['slug'] = trim($argv[1]);
    }
} else {
    // Accept both POST bodies and cURL-encoded data
    $raw = file_get_contents('php://input');
    parse_str($raw, $parsed);
    $input = array_merge($_POST, $parsed, $_GET);
}

// ---- Validation ----
if (!isset($input['slug']) || $input['slug'] === '') {
    http_response_code(400);
    echo json_encode(array('error' => 'Missing slug parameter.'));
    exit;
}

// Normalize slug (safety)
$slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['slug']);


// ----------------------------------------------------------------------
//  STEP 2 – LOCATE ROOT, LOAD TCPDF & CODEX
// ----------------------------------------------------------------------
$root = realpath(dirname(__DIR__)); // /skyesoft
if ($root === false) {
    http_response_code(500);
    echo json_encode(array('error' => 'Unable to resolve project root.'));
    exit;
}

$codexPath = $root . '/assets/data/codex.json';
$tcpdfPath = $root . '/libs/tcpdf/tcpdf.php';

if (!file_exists($codexPath)) {
    http_response_code(500);
    echo json_encode(array('error' => 'Codex not found at ' . $codexPath));
    exit;
}
if (!file_exists($tcpdfPath)) {
    http_response_code(500);
    echo json_encode(array('error' => 'TCPDF library missing.'));
    exit;
}

require_once($tcpdfPath);

$rawCodex = file_get_contents($codexPath);
$codex    = json_decode($rawCodex, true);

if (!is_array($codex) || !isset($codex[$slug])) {
    http_response_code(404);
    echo json_encode(array('error' => "Module '$slug' not found in Codex."));
    exit;
}

$module = $codex[$slug];

// ----------------------------------------------------------------------
//  STEP 3 – DETERMINE ENRICHMENT LEVEL (CODEX-FIRST, NON-BRITTLE)
// ----------------------------------------------------------------------
$codexEnrichment = '';
if (isset($module['enrichment']) && is_string($module['enrichment'])) {
    $codexEnrichment = strtolower(trim($module['enrichment']));
}

$userEnrichment = '';
if (isset($input['enrichment']) && is_string($input['enrichment'])) {
    $userEnrichment = strtolower(trim($input['enrichment']));
}

// hierarchy for optional override
$hierarchy = array('low' => 1, 'medium' => 2, 'high' => 3);
$codexRank = isset($hierarchy[$codexEnrichment]) ? $hierarchy[$codexEnrichment] : 0;
$userRank  = isset($hierarchy[$userEnrichment]) ? $userEnrichment ? $hierarchy[$userEnrichment] : 0 : 0;

// Doctrine:
// - If Codex defines enrichment and user does NOT explicitly lower it → use Codex.
// - If user provides valid lower level → respect user (for safety).
// - If nothing defined → default to medium.
if ($codexRank > 0 && $userRank > 0 && $userRank < $codexRank) {
    $enrichment = $userEnrichment;
} elseif ($codexRank > 0) {
    $enrichment = $codexEnrichment;
} elseif ($userRank > 0) {
    $enrichment = $userEnrichment;
} else {
    $enrichment = 'medium';
}

$module['enrichment'] = $enrichment;

// ----------------------------------------------------------------------
//  STEP 4 – AI ENRICHMENT HELPER
// ----------------------------------------------------------------------
function getAIEnrichment($prompt, $apiKey) {
    if (!$apiKey || $apiKey === 'YOUR_OPENAI_API_KEY') {
        return "[AI enrichment unavailable – API key not set]";
    }

    $url  = 'https://api.openai.com/v1/chat/completions';
    $data = array(
        'model' => 'gpt-3.5-turbo',
        'messages' => array(
            array('role' => 'user', 'content' => $prompt)
        ),
        'max_tokens'  => 250,
        'temperature' => 0.7
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: ' . 'Bearer ' . $apiKey
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $code !== 200) {
        return "[AI enrichment failed: " . ($err ? $err : $code) . "]";
    }

    $decoded = json_decode($resp, true);
    if (isset($decoded['choices'][0]['message']['content'])) {
        return trim($decoded['choices'][0]['message']['content']);
    }
    return "[AI response empty]";
}

// ----------------------------------------------------------------------
//  STEP 5 – PREPARE AI PROMPT (IF ENRICHMENT ACTIVE)
// ----------------------------------------------------------------------
$aiText = '';
if (in_array($enrichment, array('medium', 'high'))) {
    $purpose  = (isset($module['purpose']['text']) && is_string($module['purpose']['text']))
        ? $module['purpose']['text'] : '';
    $category = isset($module['category']) ? $module['category'] : 'Unspecified Layer';
    $title    = isset($module['title']) ? $module['title'] : ucfirst($slug);
    $type     = isset($module['type']) ? ucfirst($module['type']) : 'Document';

    $prompt = "You are the Skyesoft Codex Parliamentarian AI. "
            . "Write a doctrinal commentary for the {$type} titled '{$title}'. "
            . "Category: {$category}. Purpose: {$purpose}. ";

    if ($enrichment === 'high') {
        $prompt .= "Include relationships, dependencies, and governance impact in formal Codex tone.";
    } else {
        $prompt .= "Limit to a concise, professional paragraph.";
    }

    $aiText = getAIEnrichment($prompt, getenv('OPENAI_API_KEY'));
}

// ----------------------------------------------------------------------
//  STEP 6 – SKYESOFT PDF CLASS (MATCHES pdf_framework HEADER/FOOTER)
// ----------------------------------------------------------------------
class SkyesoftPDF extends TCPDF {
    public $docTitle    = 'Untitled Document';
    public $docType     = 'Skyesoft™ Document';
    public $generatedAt = '';
    private $root       = '';

    public function __construct($root) {
        parent::__construct('P', 'mm', 'Letter', true, 'UTF-8', false);
        $this->root = $root;
        parent::setPrintFooter(false);
    }

    public function Header() {
        $logoPath = $this->root . '/assets/images/christyLogo.png';

        $yBase   = 8;
        $logoW   = 36;
        $gap     = 3;
        $xOffset = $this->lMargin;

        if (file_exists($logoPath)) {
            $this->Image($logoPath, $xOffset, $yBase, $logoW, 0, 'PNG');
        }

        $logoH = $logoW / 3.5;
        $textY = $yBase + ($logoH / 2) - 4.0;
        $textX = $xOffset + $logoW + $gap;

        $rawTitle   = $this->docTitle;
        $cleanTitle = preg_replace('/^[^\p{L}\p{N}\(]+/u', '', $rawTitle);
        $cleanTitle = str_replace("\xEF\xBF\xBD", '', $cleanTitle);
        $cleanTitle = trim($cleanTitle);

        $this->SetFont('helvetica', 'B', 12.5);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($textX, $textY);
        $this->Cell(0, 5.5, $cleanTitle, 0, 1, 'L');

        $this->SetFont('helvetica', '', 9.2);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY($textX, $textY + 5.0);
        $this->Cell(0, 4.5, $this->docType, 0, 1, 'L');

        $this->SetFont('helvetica', '', 7.8);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY($textX, $textY + 10.0);
        $this->Cell(0, 4, 'Generated ' . $this->generatedAt, 0, 1, 'L');

        $this->SetLineWidth(0.4);
        $dividerY = $yBase + $logoH + 8;
        $this->Line($this->lMargin, $dividerY, $this->getPageWidth() - $this->rMargin, $dividerY);
    }

    public function Footer() {
        $this->SetY(-15.5);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(80, 80, 80);

        $left  = $this->lMargin;
        $right = $this->getPageWidth() - $this->rMargin;
        $this->Line($left, $this->GetY(), $right, $this->GetY());

        $this->Ln(2.0);

        $footerText =
            '© Christy Signs / Skyesoft, All Rights Reserved | ' .
            '3145 N 33rd Ave, Phoenix AZ 85017 | (602) 242-4488 | christysigns.com' .
            ' | Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();

        $printableWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $this->SetX($this->lMargin + 6);
        $this->Cell($printableWidth, 6, $footerText, 0, 0, 'C');
    }
}

// ----------------------------------------------------------------------
//  STEP 7 – DOC TYPE + TITLE FROM CODEX REGISTRY
// ----------------------------------------------------------------------
$typeKey = isset($module['type']) ? strtolower(trim($module['type'])) : 'document';

$displayLabel = 'Document';
if (isset($codex['documentStandards']['documentTypesRegistry']['items'])
    && is_array($codex['documentStandards']['documentTypesRegistry']['items'])) {

    foreach ($codex['documentStandards']['documentTypesRegistry']['items'] as $entry) {
        if (isset($entry['key']) && strtolower($entry['key']) === $typeKey) {
            if (isset($entry['type'])) {
                $displayLabel = $entry['type'];
            }
            break;
        }
    }
}

$titleSource = isset($module['title']) ? $module['title'] : $slug;
if (!is_string($titleSource)) $titleSource = (string)$titleSource;

// Strip leading emoji/symbols
$cleanTitle = preg_replace('/^[^\p{L}\p{N}\(]+/u', '', $titleSource);
$cleanTitle = trim($cleanTitle);

// Build label: e.g. Skyesoft™ Directive, Skyesoft™ Report, etc.
$docTypeLabel = 'Skyesoft™ ' . ucfirst($displayLabel);

// ----------------------------------------------------------------------
//  STEP 8 – BUILD BODY FROM CODEX (SECTION-AWARE, KEEP-TOGETHER)
// ----------------------------------------------------------------------
function buildBodyFromCodex($module, $aiText, $pdf) {
    if (!$pdf) {
        return;
    }

    // Ensure we can resolve paths
    global $root;

    // --- load icon map once (Codex-driven icon registry) ---
    $iconMap = array();
    $iconMapPath = $root . '/assets/images/icons/iconMap.json';
    if (file_exists($iconMapPath)) {
        $decoded = json_decode(file_get_contents($iconMapPath), true);
        if (is_array($decoded)) {
            $iconMap = $decoded;
        }
    }

    // --- helper: normalize label from codex key ---
    $makeLabel = function($key) {
        $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        $key = str_replace(array('_','-'), ' ', $key);
        return ucwords($key);
    };

    // --- helper: resolve iconKey from a section node (Codex-first) ---
    $resolveIconKey = function($section) {
        if (is_array($section) && isset($section['icon']) && is_string($section['icon'])) {
            return trim($section['icon']) !== '' ? trim($section['icon']) : null;
        }
        return null;
    };

    // --- helper: render a whole section as one block, kept on a single page ---
    $renderSection = function($pdf, $title, $contentHtml, $iconKey = null) use ($root, $iconMap) {
        $contentHtml = trim($contentHtml);
        if ($contentHtml === '') return;

        // Resolve icon HTML if Codex declares an icon and asset exists
        $iconHtml = '';
        if ($iconKey && isset($iconMap[$iconKey]['file'])) {
            $iconFile = $iconMap[$iconKey]['file'];
            $iconPath = $root . '/assets/images/icons/' . $iconFile;
            if (file_exists($iconPath)) {
                $iconHtml =
                    '<img src="' . $iconPath . '" width="12" ' .
                    'style="vertical-align:middle; margin-right:5px;" />';
            }
        }

        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        // Header + divider + content; spacing matches doctrine (no drift)
        $blockHtml =
            '<h2 style="font-size:11pt; font-weight:bold; margin-top:6mm; margin-bottom:2mm;">' .
                $iconHtml . $safeTitle .
            '</h2>' .
            '<hr style="height:0.35mm; border:0; border-top:0.35mm solid #000000; ' .
                'margin-top:0; margin-bottom:3mm;" />' .
            $contentHtml;

        // Keep section together: if it spills, move entire block to next page
        $pdf->startTransaction();
        $startPage = $pdf->getPage();

        $pdf->writeHTML($blockHtml, true, false, true, false, '');

        $endPage = $pdf->getPage();
        if ($endPage !== $startPage) {
            $pdf->rollbackTransaction(true);
            $pdf->AddPage();
            $pdf->writeHTML($blockHtml, true, false, true, false, '');
        } else {
            $pdf->commitTransaction();
        }
    };

    // --- SECTION: Purpose (always first if defined in Codex) ---
    if (isset($module['purpose']) && is_array($module['purpose']) &&
        isset($module['purpose']['text']) && is_string($module['purpose']['text'])) {

        $purpose = $module['purpose'];

        // Title is Codex-first: allow explicit label override, else derive from key
        $purposeTitle = isset($purpose['label']) && is_string($purpose['label'])
            ? $purpose['label']
            : $makeLabel('purpose');

        $iconKey = $resolveIconKey($purpose);

        $html = '<p>' . htmlspecialchars($purpose['text'], ENT_QUOTES, 'UTF-8') . '</p>';
        $renderSection($pdf, $purposeTitle, $html, $iconKey);
    }

    // --- SECTION: AI Doctrinal Commentary (if active) ---
    if ($aiText && strpos($aiText, '[AI enrichment unavailable') === false) {
        // Optional: allow Codex to specify an icon for commentary (aiCommentary.icon)
        $iconKey = null;
        if (isset($module['aiCommentary']) && is_array($module['aiCommentary'])) {
            $iconKey = $resolveIconKey($module['aiCommentary']);
        }

        $html = '<p>' . htmlspecialchars($aiText, ENT_QUOTES, 'UTF-8') . '</p>';
        $renderSection($pdf, 'Doctrinal Commentary', $html, $iconKey);
    }

    // --- helper: generic format-based content builder for a section node ---
    $buildContent = function($key, $section) use ($makeLabel) {
        if (!is_array($section)) return '';

        $format = isset($section['format']) ? strtolower($section['format']) : '';
        $html   = '';

        // TEXT
        if ($format === 'text' && isset($section['text'])) {
            $html .= '<p>' . htmlspecialchars($section['text'], ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // LIST
        elseif ($format === 'list' && isset($section['items']) && is_array($section['items'])) {
            $html .= '<ul>';
            foreach ($section['items'] as $item) {
                if (is_array($item)) {
                    $html .= '<li>' . htmlspecialchars(implode(' – ', $item), ENT_QUOTES, 'UTF-8') . '</li>';
                } else {
                    $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
                }
            }
            $html .= '</ul>';
        }

        // TABLE
        elseif ($format === 'table' && isset($section['items']) && is_array($section['items'])) {
            $html .= '<table border="1" cellpadding="5" cellspacing="0" width="100%">';
            $headersPrinted = false;
            foreach ($section['items'] as $row) {
                if (!is_array($row)) continue;
                if (!$headersPrinted) {
                    $html .= '<tr style="background-color:#f0f0f0;font-weight:bold">';
                    foreach (array_keys($row) as $col) {
                        $html .= '<td>' . htmlspecialchars($col, ENT_QUOTES, 'UTF-8') . '</td>';
                    }
                    $html .= '</tr>';
                    $headersPrinted = true;
                }
                $html .= '<tr>';
                foreach ($row as $val) {
                    $html .= '<td>' . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        // DYNAMIC / OBJECT (descriptive)
        elseif (in_array($format, array('dynamic','object')) && isset($section['description'])) {
            $html .= '<p>' . htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return $html;
    };

    // --- SPECIAL HANDLING: holidayRegistry & holidayFallbackRules & meta (for TIS) ---
    $renderHolidayRegistry = function($section) {
        $html = '';

        if (isset($section['description'])) {
            $html .= '<p>' . htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8') . '</p>';
        }

        if (isset($section['categories']) && is_array($section['categories'])) {
            $html .= '<h3>Categories</h3>';
            $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
            $html .= '<tr style="background-color:#f0f0f0;font-weight:bold">'
                   . '<td>Name</td><td>Description</td><td>Workday Impact</td><td>Exclude From Scheduling</td>'
                   . '</tr>';
            foreach ($section['categories'] as $name => $cfg) {
                if ($name === 'notes' || !is_array($cfg)) continue;
                $html .= '<tr>'
                       . '<td>' . htmlspecialchars(ucfirst($name), ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars(isset($cfg['description']) ? $cfg['description'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars(isset($cfg['workdayImpact']) ? $cfg['workdayImpact'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars(
                               (isset($cfg['excludeFromScheduling']) && $cfg['excludeFromScheduling']) ? 'Yes' : 'No',
                               ENT_QUOTES,
                               'UTF-8'
                           ) . '</td>'
                       . '</tr>';
            }
            $html .= '</table>';
        }

        if (isset($section['holidays']) && is_array($section['holidays'])) {
            $html .= '<h3>Holidays</h3>';
            $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
            $html .= '<tr style="background-color:#f0f0f0;font-weight:bold">'
                   . '<td>Name</td><td>Rule</td><td>Categories</td>'
                   . '</tr>';
            foreach ($section['holidays'] as $h) {
                if (!is_array($h)) continue;
                $cats = isset($h['categories']) && is_array($h['categories'])
                    ? implode(', ', $h['categories'])
                    : '';
                $html .= '<tr>'
                       . '<td>' . htmlspecialchars(isset($h['name']) ? $h['name'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars(isset($h['rule']) ? $h['rule'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars($cats, ENT_QUOTES, 'UTF-8') . '</td>'
                       . '</tr>';
            }
            $html .= '</table>';
        }

        return $html;
    };

    $renderHolidayFallback = function($section) {
        $html = '';
        if (isset($section['description'])) {
            $html .= '<p>' . htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if (isset($section['data']) && is_array($section['data'])) {
            $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
            $html .= '<tr style="background-color:#f0f0f0;font-weight:bold">'
                   . '<td>Key</td><td>Strategy</td><td>Method</td><td>Reasoning</td>'
                   . '</tr>';
            foreach ($section['data'] as $key => $cfg) {
                if (!is_array($cfg)) continue;
                $html .= '<tr>'
                       . '<td>' . htmlspecialchars(str_replace('_',' ', $key), ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars(isset($cfg['strategy']) ? $cfg['strategy'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars(isset($cfg['fallback_method']) ? $cfg['fallback_method'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td>' . htmlspecialchars(isset($cfg['reasoning']) ? $cfg['reasoning'] : '', ENT_QUOTES, 'UTF-8') . '</td>'
                       . '</tr>';
            }
            $html .= '</table>';
        }
        return $html;
    };

    $renderMeta = function($section) {
        if (!is_array($section)) return '';
        $html = '<table border="0" cellpadding="2" cellspacing="0" width="100%">';
        foreach ($section as $k => $v) {
            $label = ucwords(str_replace(array('_','-'), ' ', $k));
            $html .= '<tr>'
                   . '<td width="35%"><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong></td>'
                   . '<td>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td>'
                   . '</tr>';
        }
        $html .= '</table>';
        return $html;
    };

    // --- Top-level iteration (exclude keys already handled above) ---
    $skipKeys = array('title','category','type','subtypes','actions','relationships','purpose','enrichment','aiCommentary');

    foreach ($module as $key => $section) {
        if (in_array($key, $skipKeys, true)) continue;
        if (!is_array($section)) continue;

        $label = $makeLabel($key);
        $html  = '';

        if ($key === 'holidayRegistry') {
            $html = $renderHolidayRegistry($section);
        } elseif ($key === 'holidayFallbackRules') {
            $html = $renderHolidayFallback($section);
        } elseif ($key === 'meta') {
            $html = $renderMeta($section);
        } else {
            $html = $buildContent($key, $section);
        }

        if ($html !== '') {
            $iconKey = $resolveIconKey($section);
            $renderSection($pdf, $label, $html, $iconKey);
        }
    }
}

// ----------------------------------------------------------------------
//  STEP 9 – REPOSITORY PATH RESOLUTION (CODEX-DRIVEN)
// ----------------------------------------------------------------------
function resolveRepositoryPath($codex) {
    // Prefer referentialDocumentRecord.repository.path if present
    if (isset($codex['documentStandards']['containedModules']['referentialDocumentRecord']['framework']['repository']['path'])) {
        $path = trim($codex['documentStandards']['containedModules']['referentialDocumentRecord']['framework']['repository']['path']);
        if ($path !== '') return '/' . ltrim($path, '/');
    }

    // Then documentStandards.repository.path
    if (isset($codex['documentStandards']['repository']['path'])) {
        $path = trim($codex['documentStandards']['repository']['path']);
        if ($path !== '') return '/' . ltrim($path, '/');
    }

    // Fallback default
    return '/documents/';
}

// ----------------------------------------------------------------------
//  STEP 10 – FILE-NAMING (NON-BRITTLE, TYPE-BASED)
// ----------------------------------------------------------------------
function buildFileName($module, $cleanTitle, $displayLabel) {
    $type = isset($module['type']) ? strtolower($module['type']) : 'document';

    // Map type -> suffix; avoid double "(TIS)" type bugs; title already includes aliases
    $suffix = '';
    if ($type === 'directive') {
        $suffix = 'Directive';
    } elseif ($type === 'audit') {
        $suffix = 'Audit';
    } elseif ($type === 'report') {
        $suffix = 'Report';
    } elseif ($type === 'sheet') {
        $suffix = 'Information Sheet';
    } else {
        $suffix = ucfirst($displayLabel);
    }

    return 'Skyesoft – ' . $cleanTitle . ' ' . $suffix . '.pdf';
}

// ----------------------------------------------------------------------
//  STEP 11 – BUILD & SAVE PDF
// ----------------------------------------------------------------------
$pdf = new SkyesoftPDF($root);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(15, 30, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(12);
$pdf->SetAutoPageBreak(true, 20);

$pdf->docTitle    = $cleanTitle;
$pdf->docType     = $docTypeLabel;
$pdf->generatedAt = date('F j, Y • g:i A');

$pdf->AddPage();
$pdf->SetFont('helvetica','',10.5);

// Build body directly into the PDF (section-aware)
buildBodyFromCodex($module, $aiText, $pdf);

// Resolve directory
$repoPath = resolveRepositoryPath($codex);
$saveDir  = rtrim($root . $repoPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!is_dir($saveDir)) {
    $old = umask(0);
    mkdir($saveDir, 0777, true);
    umask($old);
}

// Filename
$saveFile = buildFileName($module, $cleanTitle, $displayLabel);
$savePath = $saveDir . $saveFile;

$pdf->Output($savePath, 'F');

echo json_encode(array(
    'status'       => 'success',
    'slug'         => $slug,
    'fileName'     => basename($saveFile),
    'path'         => $savePath,
    'aiCommentary' => $aiText,
    'timestamp'    => date('c')
));

// ----------------------------------------------------------------------
//  STEP 12 – JSON RESPONSE
// ----------------------------------------------------------------------
echo json_encode(array(
    'status'       => 'success',
    'slug'         => $slug,
    'fileName'     => basename($saveFile),
    'path'         => $savePath,
    'aiCommentary' => $aiText,
    'timestamp'    => date('c')
));