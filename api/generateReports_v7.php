<?php
// Skyesoft Codex-Oriented Report Kernel v7 Compact
// Tier-A Compliant | AI Enrichment | PHP 5.6 Safe
// Generates: PDF | Returns: JSON metadata

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Phoenix');

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

function logReport($msg) {
    global $logDir;
    $file = $logDir . 'report_kernel_v7.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($file, "[$timestamp] $msg\n", FILE_APPEND);
}

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
    echo json_encode(array("success"=>false,"message"=>"Missing slug parameter."));
    exit;
}
logReport("Requested report for slug: $slug");

if (!file_exists($codexFile)) {
    echo json_encode(array("success"=>false,"message"=>"Codex not found."));
    exit;
}
$codex = json_decode(file_get_contents($codexFile), true);
if (!is_array($codex)) {
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
    echo json_encode(array("success"=>false,"message"=>"Module '$slug' not found in Codex."));
    exit;
}
logReport("Module located: $slug");

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

    $postData = json_encode(array(
        "model" => "gpt-4o-mini",
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

function renderCodexSection($slug, $key, $section, $module, $apiKey) {
    $html = "";
    $icon = isset($section['icon']) ? $section['icon'] . ' ' : '';
    $format = isset($section['format']) ? strtolower($section['format']) : 'text';
    $label = ucfirst(str_replace('_', ' ', $key));

    $html .= "<h2>{$icon}{$label}</h2>";

    // --- Enrichment Layer ---
    $enriched = getAIEnrichedBody($slug, $key, $module, $apiKey, $format);
    if (!empty($enriched)) {
        $html .= "<p>{$enriched}</p>";
    }

    // --- Structural Rendering ---
    switch ($format) {
        case 'list':
            if (isset($section['items']) && is_array($section['items'])) {
                $html .= "<ul>";
                foreach ($section['items'] as $item) {
                    $html .= "<li>" . htmlspecialchars($item) . "</li>";
                }
                $html .= "</ul>";
            }
            break;

        case 'table':
            if (isset($section['items']) && is_array($section['items'])) {
                $html .= "<table><tr>";
                $headers = array_keys($section['items'][0]);
                foreach ($headers as $h) $html .= "<th>" . htmlspecialchars($h) . "</th>";
                $html .= "</tr>";
                foreach ($section['items'] as $row) {
                    $html .= "<tr>";
                    foreach ($headers as $h) {
                        $html .= "<td>" . htmlspecialchars($row[$h]) . "</td>";
                    }
                    $html .= "</tr>";
                }
                $html .= "</table>";
            }
            break;

        case 'object':
            if (isset($section['data']) && is_array($section['data'])) {
                $html .= "<table><tr><th>Key</th><th>Details</th></tr>";
                foreach ($section['data'] as $objKey => $objVal) {
                    $details = "";
                    if (is_array($objVal)) {
                        foreach ($objVal as $subKey => $subVal) {
                            $details .= "<b>" . htmlspecialchars($subKey) . ":</b> " . htmlspecialchars($subVal) . "<br>";
                        }
                    } else {
                        $details = htmlspecialchars($objVal);
                    }
                    $html .= "<tr><td><b>" . htmlspecialchars($objKey) . "</b></td><td>$details</td></tr>";
                }
                $html .= "</table>";
            }
            break;
    }

    return $html;
}

function renderDocument($slug, $module, $apiKey) {
    $html = '<style>
        body { font-family: Arial, sans-serif; color: #111; font-size: 11pt; }
        h1 { font-size: 16pt; margin-bottom: 4pt; }
        h2 { font-size: 13pt; margin-top: 10pt; color: #333; }
        .enriched-section { margin-bottom: 10pt; }
    </style>';

    $title = isset($module['title']) ? $module['title'] : 'Untitled Report';
    $category = isset($module['category']) ? $module['category'] : 'General';
    $html .= "<h1>$title</h1><p><strong>Category:</strong> $category</p>";

    foreach ($module as $key => $section) {
        if (!is_array($section) || !isset($section['format'])) continue;
        $html .= renderCodexSection($slug, $key, $section, $module, $apiKey);
    }
    return $html;
}

require_once(__DIR__ . '/utils/renderPDF_v7.3.php');

$reportTitle = strip_tags(isset($module['title']) ? $module['title'] : ucfirst($slug));
$reportBody = renderDocument($slug, $module, $OPENAI_API_KEY);

$type = isset($module['type']) ? strtolower($module['type']) : 'standard';
$prefix = (strpos($type, 'report') !== false) ? 'Report' : 'Information Sheet';
$targetDir = (strpos($type, 'report') !== false)
    ? $basePath . '/docs/reports/'
    : $basePath . '/docs/sheets/';

if (!is_dir($targetDir)) { mkdir($targetDir, 0777, true); }

$titleClean = trim(preg_replace('/[^a-zA-Z0-9\s\(\)\-]/', '', $reportTitle));
$fileName   = $prefix . ' - ' . $titleClean . '.pdf';
$outputFile = $targetDir . $fileName;   // ✅ define it before use

$meta = array(
    'generatedAt' => date('Y-m-d H:i:s'),
    'source'      => 'Codex v7',
    'author'      => 'Skyebot System Layer'
);

renderPDF($reportTitle, $reportBody, $meta, $outputFile);
logReport("PDF created successfully: $outputFile");

echo json_encode(array(
    "success"   => true,
    "slug"      => $slug,
    "title"     => $reportTitle,
    "timestamp" => date('Y-m-d H:i:s'),
    "fileReady" => true,
    "filePath"  => str_replace($basePath . '/', '', $outputFile),
    "message"   => "PDF generated successfully with AI enrichment."
));
exit;