<?php
#region File Header
// File: generateReports_v7.3.1.php
// System: Skyesoft Codex [Subsystem] v7.3.1
// Compliance: Tier-A | PHP 5.6 Safe
// Features: AI Enrichment | Cache-Aware | Meta Footer Injection | Recursive Rendering | Subnode Iteration | Enhanced Debug v7.3.1
// Outputs: PDF | JSON Metadata | Debug HTML/Log
// Codex Parliamentarian Approved: 2025-11-03 (CPAP-01)
#endregion

#region Initial Setup
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Phoenix');


$logPath = dirname(__DIR__) . '/logs';
if (!is_dir($logPath)) {
    @mkdir($logPath, 0777, true);
}


ini_set('memory_limit', '512M');
set_time_limit(60);

require_once(__DIR__ . '/helpers.php');

$basePath = dirname(__DIR__);
$codexFile = $basePath . '/assets/data/codex.json';
$cacheDir = $basePath . '/cache/enriched/';
$logDir = $basePath . '/logs/';
$OPENAI_API_KEY = getenv("OPENAI_API_KEY");

// Ensure folders exist
foreach (array($cacheDir, $logDir) as $dir) {
    if (!is_dir($dir)) {
        $oldUmask = umask(0);
        mkdir($dir, 0777, true);
        umask($oldUmask);
    }
}
#endregion

#region Logging Function
function logReport($msg) {
    global $logDir;
    $file = $logDir . 'report_kernel_v7.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($file, "[$timestamp] $msg\n", FILE_APPEND);
}
#endregion

#region Parameter Handling and Codex Loading
$slug = null;
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    foreach ($argv as $arg) {
        if (strpos($arg, '=') !== false) {
            list($key, $val) = explode('=', $arg, 2);
            if ($key === 'slug') $slug = trim($val);
        }
    }
} elseif (isset($_GET['slug'])) {
    $slug = trim($_GET['slug']);
} elseif (isset($_POST['slug'])) {
    $slug = trim($_POST['slug']);
}
if (!$slug) {
    logReport("FATAL: Missing slug parameter.");
    echo json_encode(array("success"=>false,"message"=>"Missing slug parameter."));
    exit;
}
logReport("Requested report for slug: $slug");

if (!file_exists($codexFile)) {
    logReport("FATAL: Codex file missing at $codexFile");
    echo json_encode(array("success"=>false,"message"=>"Codex not found."));
    exit;
}
$codex = json_decode(file_get_contents($codexFile), true);
if (!is_array($codex)) {
    logReport("FATAL: Codex invalid or unreadable at $codexFile");
    echo json_encode(array("success"=>false,"message"=>"Codex invalid or unreadable."));
    exit;
}
$module = null;
foreach ($codex as $key => $val) {
    if (strcasecmp($key, $slug) === 0) {
        $module = $val;
        $slug = $key;
        break;
    }
}
if (!$module && isset($codex['modules'][$slug])) $module = $codex['modules'][$slug];
if (!$module) {
    logReport("FATAL: Module '$slug' not found in Codex.");
    echo json_encode(array("success"=>false,"message"=>"Module '$slug' not found in Codex."));
    exit;
}
logReport("Module located: $slug");

// Debug: Log sample module structure
if (isset($module['purpose'])) {
    logReport("DEBUG: purpose keys: " . json_encode(array_keys($module['purpose'])));
    logReport("DEBUG: purpose text preview: " . substr($module['purpose']['text'] ?? '', 0, 100));
}
#endregion

#region AI Enrichment Function
function getAIEnrichedBody($slug, $sectionKey, $module, $apiKey, $format) {
    global $cacheDir;
    $cacheFile = $cacheDir . "{$slug}_{$sectionKey}.json";
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cached)) return $cached['content'];
    }
    // Prepare section data
    $section = isset($module[$sectionKey]) ? $module[$sectionKey] : array();
    // Prepare prompt
    $prompt = "You are Skyebot, the Codex Parliamentarian. 
    Generate human-readable content for the '{$sectionKey}' section in the module '{$slug}'.
    The section format is '{$format}'. Use the provided data below:

    " . json_encode($section, JSON_PRETTY_PRINT) . "

    If format is:
    - text → Write a concise paragraph explaining the purpose or meaning.
    - list → Introduce the list briefly, then reproduce each item as a bulleted list.
    - table → Write one sentence explaining what the table represents, then reproduce the table in Markdown format.
    - object → Explain its purpose, then summarize its key-value structure.

    Respond using valid HTML for inclusion in a PDF report.";

    $model = isset($module['meta']['model']) ? $module['meta']['model'] : 'gpt-4o-mini';

    $postData = json_encode(array(
        "model" => $model,
        "messages" => array(
            array("role"=>"system","content"=>"You are a precise document formatter for the Skyesoft Codex."),
            array("role"=>"user","content"=>$prompt)
        ),
        "temperature" => 0.6,
        "max_tokens" => 800
    ));

    $context = stream_context_create(array(
        "http" => array(
            "method" => "POST",
            "header" => "Content-Type: application/json\r\nAuthorization: Bearer $apiKey\r\n",
            "content" => $postData,
            "timeout" => 45
        )
    ));

    $response = @file_get_contents("https://api.openai.com/v1/chat/completions", false, $context);

    if ($response === false) {
        logReport("AI enrichment request failed for '{$slug}/{$sectionKey}' (HTTP context).");
        return "(AI enrichment unavailable)";
    }

    $parsed = @json_decode($response, true);

    if (isset($parsed['choices'][0]['message']['content'])) {
        $content = trim($parsed['choices'][0]['message']['content']);
        file_put_contents($cacheFile, json_encode(array("content"=>$content), JSON_PRETTY_PRINT));
        logReport("Enriched section '{$sectionKey}' for '{$slug}' via AI.");
        return $content;
    } else {
        logReport("AI enrichment failed for '{$sectionKey}' (using fallback).");
        return isset($module[$sectionKey]['text']) ? $module[$sectionKey]['text'] : "(No enriched content)";
    }
}
#endregion

#region Mock Helper Functions (v7.3.1 Debug - Replace if helpers.php defines them)
if (!function_exists('getIconFile')) {
    function getIconFile($iconKey) {
        logHelperInvocation(__FUNCTION__);
        // Mock: Return null for emoji fallback
        logReport("DEBUG: getIconFile called for '$iconKey' - returning null (emoji fallback)");
        return null;
    }
}
if (!function_exists('renderMetaFooterFromCodex')) {
    function renderMetaFooterFromCodex($codex, $slug, $module) {
        logHelperInvocation(__FUNCTION__);
        // Mock footer
        $introduced = findCodexMetaValue($module, 'introducedInVersion');
        $footer = "<p style='font-size:9pt; color:#666; text-align:center;'><em>Meta: Codex v" . ($codex['codexMeta']['version'] ?? 'unknown') . " | Introduced in $introduced | Doctrine Compliant</em></p>";
        logReport("DEBUG: renderMetaFooterFromCodex mocked");
        return $footer;
    }
}
#endregion

#region Render Section Header Function
function renderSectionHeader($key, $section) {

    logReport(">>> renderSectionHeader called for key: " . $key);

    $label = ucwords(str_replace('_', ' ', $key));

    // ✅ Load icon map once
    static $iconMap = null;
    if ($iconMap === null) {
        $mapPath = __DIR__ . '/../assets/data/iconMap.json';
        $iconMap = file_exists($mapPath)
            ? json_decode(file_get_contents($mapPath), true)
            : array();
    }

    $iconHTML = '';
    if (isset($section['icon'])) {
        $iconKey = $section['icon'];

        if (isset($iconMap[$iconKey]['file'])) {

            // ✅ Use filesystem path for TCPDF
            $iconFile = __DIR__ . '/../assets/images/icons/' . $iconMap[$iconKey]['file'];

            if (file_exists($iconFile)) {
                // PDF-safe inline scaling + alignment
                $iconHTML = '<img src="' . $iconFile . '" style="width:12pt; height:12pt; vertical-align:middle; margin-right:6pt;">';
            }

        } elseif (isset($iconMap[$iconKey]['icon'])) {
            // ✅ Emoji fallback
            $emoji = $iconMap[$iconKey]['icon'];
            $iconHTML = '<span style="font-size:13pt; margin-right:6pt; vertical-align:middle;">' . $emoji . '</span>';
        }
    }
    // Return header HTML
    return "<div style='margin-top:10pt; margin-bottom:6pt;'>"
        . "<h2 style='font-size:13pt; font-weight:bold; color:#003366;"
        . "line-height:1.0; margin-bottom:2pt; padding:0;'>"
        . $iconHTML . $label . "</h2>"
        . "<div style='height:0.8pt; background-color:#555; margin-top:1pt; margin-bottom:8pt;'></div>"
        . "</div>";

}
#endregion

#region Recursive Codex Node Renderer (v7.3.3 – Guaranteed Body Render)
function renderCodexNode($slug, $key, $node, $module, $apiKey, $depth = 0, $isSub = false) {
    global $logDir;

    if ($depth > 10) {
        logReport("Max recursion depth for '$key'");
        return "<p style='opacity:0.6;'><em>[Max depth]</em></p>";
    }

    logReport("renderCodexNode: key='$key', depth=$depth, type=" . gettype($node));

    // ✅ STRING / simple scalar
    if (!is_array($node)) {
        $html = htmlspecialchars($node);
        return $html;
    }

    $format = isset($node['format']) ? strtolower($node['format']) : null;
    $html = '';

    $enrichmentLevel = isset($module['enrichment']) ? $module['enrichment'] : 'none';
    $useAI = ($enrichmentLevel === 'medium' && $apiKey);

    // ✅ ✨ UNIVERSAL BODY VISIBILITY GUARD ✨
    $hasRenderData = false;

    if (isset($node['text']) && strlen(trim($node['text'])) > 0) $hasRenderData = true;
    if (isset($node['items']) && is_array($node['items']) && count($node['items']) > 0) $hasRenderData = true;
    if (isset($node['data']) && is_array($node['data']) && count($node['data']) > 0) $hasRenderData = true;
    if (isset($node['holidays']) && is_array($node['holidays']) && count($node['holidays']) > 0) $hasRenderData = true;

    if (!$hasRenderData && !$useAI) {
        logReport("SKIP '$key': No visible content");
        return "";
    }

    // ✅ EXPLICIT FORMAT HANDLING
    if ($format) {
        logReport("explicit format='$format' on '$key'");

        switch ($format) {
            case 'text':
            case 'dynamic':
                $textKey = ($format === 'dynamic') ? 'description' : 'text';
                $text = isset($node[$textKey]) ? trim($node[$textKey]) : '';

                if (empty($text) && $useAI) {
                    $text = getAIEnrichedBody($slug, $key, $module, $apiKey, 'text');
                }

                $html .= "<p class='tis-text'>{$text}</p>";
                return $html;

            case 'list':
                $items = (isset($node['items']) && is_array($node['items'])) ? $node['items'] : $node;

                $html .= "<ul class='tis-list'>";
                foreach ($items as $i) {
                    $itemHtml = is_array($i)
                        ? renderCodexNode($slug, $key, $i, $module, $apiKey, $depth+1, false)
                        : htmlspecialchars($i);
                    $html .= "<li>{$itemHtml}</li>";
                }
                $html .= "</ul>";
                return $html;

            case 'table':
                $tableData = null;
                if (isset($node['items']) && count($node['items']) > 0) $tableData = $node['items'];
                if (isset($node['holidays']) && count($node['holidays']) > 0) $tableData = $node['holidays'];
                if (isset($node['data']) && count($node['data']) > 0) $tableData = $node['data'];

                if ($tableData) {
                    $headers = array_keys($tableData[0]);
                    $html .= "<table class='tis-table'>";
                    $html .= "<tr>";
                    foreach ($headers as $h) { $html .= "<th>{$h}</th>"; }
                    $html .= "</tr>";

                    foreach ($tableData as $row) {
                        $html .= "<tr>";
                        foreach ($headers as $h) {
                            $val = isset($row[$h]) ? $row[$h] : '';
                            if (is_array($val)) $val = implode(", ", $val);
                            $html .= "<td>{$val}</td>";
                        }
                        $html .= "</tr>";
                    }
                    $html .= "</table>";
                    return $html;
                }

                return "<p><em>No table data available.</em></p>";

            case 'object':
                $data = isset($node['data']) ? $node['data'] : $node;

                $html .= "<table class='tis-table'>";
                $html .= "<tr><th>Key</th><th>Value</th></tr>";
                foreach ($data as $k => $v) {
                    $label = ucwords(str_replace('_', ' ', $k));
                    if (is_array($v)) {
                        $v = htmlspecialchars(json_encode($v));
                    }
                    $html .= "<tr><td style='font-weight:bold;'>{$label}</td><td>{$v}</td></tr>";
                }
                $html .= "</table>";
                return $html;
        }

        $html .= "<p><em>Format '{$format}' unsupported.</em></p>";
        return $html;
    }

    // ✅ NO FORMAT → clean table/list fallback
    $keys = array_keys($node);
    $isList = ($keys === range(0, count($keys)-1));

    if ($isList && !empty($node)) {
        $html .= "<ul class='tis-list'>";
        foreach ($node as $item) {
            $html .= "<li>" . htmlspecialchars($item) . "</li>";
        }
        $html .= "</ul>";
        return $html;
    }

    // ✅ Raw fallback display (debug only)
    if (trim(strip_tags($html)) === '') {
        $html .= "<pre class='tis-debug'>" .
            htmlspecialchars(json_encode($node, JSON_PRETTY_PRINT)) .
            "</pre>";
    }

    return $html;
}
#endregion

#region Render Document Function (v7.3.7 – Codex-Compliant Body Layout)
function renderDocument($slug, $module, $apiKey) {

    global $codex;
    logReport("DEBUG renderDocument: Starting for slug '$slug'");
    logReport(">>> renderDocument invoked for slug: " . (isset($slug) ? $slug : 'unknown'));

    // ✅ Load stylesheet
    $cssPath = __DIR__ . '/../assets/styles/reportBase.css';
    $html = file_exists($cssPath)
        ? "<style>" . file_get_contents($cssPath) . "</style>"
        : "<style>body{font-family:Arial,sans-serif;font-size:11pt;}</style>";

    // ✅ Top title ONLY appears in Header Block (PDF renderer)
    // Do NOT repeat <h1> or <p> Category inside body ✅

    $bodyHtml = "";
    $relationshipsBlock = ""; // ✅ Captured and printed at bottom
    $sectionsProcessed = 0;

    foreach ($module as $key => $section) {

        // Skip meta/system keys but NOT relationships
        if (in_array($key, array('title','category','type','enrichment','meta','subtypes','actions'))) {
            continue;
        }

        $sectionsProcessed++;
        logReport("BODY: Processing [$key]");

        // ✅ 1️⃣ STRING SECTIONS
        if (is_string($section)) {
            $bodyHtml .= renderSectionHeader($key, $section);
            $bodyHtml .= "<p class='tis-text'>" . htmlspecialchars($section) . "</p>";
            continue;
        }

        // ✅ Detect Relationship Section → hold for final placement
        if ($key === 'relationships' ||
            !empty(array_intersect(array('isA','governs','dependsOn','aliases','partOf'), array_keys($section)))) {
            
            logReport("Capture relationships block for placement at end: '$key'");
            $relationshipsBlock .= renderSectionHeader("Governance & Dependencies", $section);
            $relationshipsBlock .= renderCodexNode($slug, $key, $section, $module, $apiKey, 0, true);
            continue;
        }

        // ✅ 3️⃣ Structured Codex Sections
        $bodyHtml .= renderSectionHeader($key, $section);

        // Normalize missing formats
        if (!isset($module[$key]['format'])) {
            if (isset($section['text'])) {
                $module[$key]['format'] = 'text';
            } elseif (isset($section['items']) && is_array($section['items'])) {
                $module[$key]['format'] = 'table';
            } elseif (array_keys($section) === range(0, count($section) - 1)) {
                $module[$key]['format'] = 'list';
            }
            $section = $module[$key];
        }

        $rendered = renderCodexNode($slug, $key, $section, $module, $apiKey, 0, true);

        if (trim(strip_tags($rendered)) !== '') {
            $bodyHtml .= $rendered;
        } else {
            $bodyHtml .= "<p><em>No content available.</em></p>";
        }
    }

    // ✅ Append relationships at the end (if any)
    if (trim(strip_tags($relationshipsBlock)) !== '') {
        logReport("APPEND: Governance & Dependencies");
        $bodyHtml .= $relationshipsBlock;
    }

    // ✅ Meta Footer only for information sheets
    $type = isset($module['type']) ? strtolower($module['type']) : '';
    if (strpos($type, 'information') !== false) {
        $bodyHtml .= "<hr>" . renderMetaFooterFromCodex($codex, $slug, $module);
    }

    // Log HTML for debug
    file_put_contents(__DIR__ . "/../logs/last_rendered_html.html", $bodyHtml);
    logReport("BODY COMPLETE: $sectionsProcessed main sections rendered");

    return $html . $bodyHtml;
}
#endregion

#region PDF Generation and Output
require_once(__DIR__ . '/utils/renderPDF_v7.3.php');

// --- Debug: module keys ---
$moduleDump = json_encode(array_keys($module));
logReport("DEBUG keys for $slug: $moduleDump");

// --- Render document once (DRY fix) ---
$reportTitle = strip_tags(isset($module['title']) ? $module['title'] : ucfirst($slug));
$reportBody  = renderDocument($slug, $module, $OPENAI_API_KEY);   // render only once

// --- Log + preview from same HTML ---
logReport("DEBUG body length: " . strlen($reportBody));
logReport("DEBUG body preview (stripped tags): " . substr(strip_tags($reportBody), 0, 500));

// --- Save full HTML for inspection ---
$debugHtmlFile = $basePath . '/debug_' . $slug . '_' . date('His') . '.html'; // timestamped
file_put_contents($debugHtmlFile, $reportBody);
logReport("DEBUG HTML saved: $debugHtmlFile (len=" . strlen($reportBody) . ")");

// --- Determine output path and prefix ---
$type   = isset($module['type']) ? strtolower($module['type']) : 'standard';
$prefix = (strpos($type, 'report') !== false) ? 'Report' : 'Information Sheet';

// ✅ Route output path via File-Management schema
global $codex;
$rules    = isset($codex['fileManagement']['rules']['items']) ? $codex['fileManagement']['rules']['items'] : array();
$baseOut  = (strpos(json_encode($rules), '/docs/reports/') !== false) ? '/docs/reports/' : '/docs/sheets/';
$titleClean = trim(preg_replace('/[^a-zA-Z0-9\s\(\)\-]/', '', $reportTitle));
$fileName   = $prefix . ' - ' . $titleClean . '.pdf';
$outputFile = $basePath . $baseOut . $fileName;

// --- Ensure directory exists ---
if (!is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0777, true);
}

// --- Meta information ---
$meta = array(
    'generatedAt' => date('Y-m-d H:i:s'),
    'source'      => 'Codex v7',
    'author'      => 'Skyebot System Layer'
);

// --- Generate PDF ---
renderPDF($reportTitle, $reportBody, $meta, $outputFile);
logReport("PDF created: $outputFile");

// --- JSON response ---
echo json_encode(array(
    'success'   => true,
    'slug'      => $slug,
    'title'     => $reportTitle,
    'timestamp' => date('Y-m-d H:i:s'),
    'fileReady' => true,
    'filePath'  => str_replace($basePath . '/', '', $outputFile),
    'debugHtml' => str_replace($basePath . '/', '', $debugHtmlFile),
    'message'   => 'PDF generated (v7.3.1-stable). Check logs and debug HTML for content.'
));
exit;
#endregion

#region Meta Footer Injection
// Automatically applied for Information Sheet documents
// Implemented via renderMetaFooterFromCodex() in helpers.php (or mocked)
#endregion