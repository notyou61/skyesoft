<?php
// =====================================================================
//  Skyesoft™ Codex-Oriented Report Kernel v6
//  Compatible with PHP 5.6+  |  GoDaddy Shared Hosting Safe
//  Generates: PDF Only  |  Returns: JSON Link for Skyebot™
// =====================================================================

// ---------------------------------------------------------
// ⚙️ 1. Environment Boot
// ---------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Phoenix');

$basePath = dirname(__DIR__);
$codexFile = $basePath . '/assets/data/codex.json';
$reportsDir = $basePath . '/docs/reports/';
$logDir = $basePath . '/logs/';

// Ensure folders exist
foreach (array($reportsDir, $logDir) as $dir) {
    if (!is_dir($dir)) {
        $oldUmask = umask(0);
        mkdir($dir, 0777, true);
        umask($oldUmask);
    }
}

// ---------------------------------------------------------
// 🧾 Logging Helpers
// ---------------------------------------------------------
function logReport($msg) {
    global $logDir;
    $file = $logDir . 'report_kernel.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($file, "[$timestamp] $msg\n", FILE_APPEND);
}

// ---------------------------------------------------------
// 🔍 2. Slug Resolver (CLI or HTTP)
// ---------------------------------------------------------
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
    echo json_encode(array("success" => false, "message" => "Missing slug parameter."));
    exit;
}

logReport("Requested report for slug: $slug");

// ---------------------------------------------------------
// 📘 3. Load Codex
// ---------------------------------------------------------
if (!file_exists($codexFile)) {
    echo json_encode(array("success" => false, "message" => "Codex not found at $codexFile"));
    exit;
}

$codex = json_decode(file_get_contents($codexFile), true);
if (!is_array($codex)) {
    echo json_encode(array("success" => false, "message" => "Codex invalid or unreadable."));
    exit;
}

// Case-insensitive lookup
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
    echo json_encode(array("success" => false, "message" => "Module '$slug' not found in Codex."));
    exit;
}

logReport("✅ Module located: $slug");

// ---------------------------------------------------------
// 🔄 4. Optional SSE Merge (getDynamicData.php)
// ---------------------------------------------------------
$sseData = array();
$ssePath = __DIR__ . '/getDynamicData.php';
// Isolate SSE execution to avoid side-effects
if (file_exists($ssePath)) {
    // Run as isolated subprocess to capture output without include side-effects (e.g., exit)
    $escapedPath = escapeshellarg($ssePath);
    $buffer = shell_exec("php " . $escapedPath . " 2>&1");  // Captures stdout + stderr

    // Quick debug log for buffer (remove in prod if verbose)
    $bufferLen = strlen(isset($buffer) ? $buffer : '');
    logReport("ℹ️ SSE subprocess captured: {$bufferLen} bytes");

    // Detect and parse JSON safely
    $trimmedBuffer = trim(isset($buffer) ? $buffer : '');
    $decoded = @json_decode($trimmedBuffer, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        // Basic validation: ensure core SSE keys for robustness
        if (isset($decoded['timeDateArray']) && isset($decoded['intervalsArray'])) {
            $sseData = $decoded;
            logReport("✅ SSE stream merged successfully (subprocess isolation).");
        } else {
            logReport("⚠️ SSE decoded but missing core keys (timeDateArray/intervalsArray). Using partial data.");
            $sseData = !empty($decoded) ? $decoded : array();
        }
    } else {
        $errorMsg = function_exists('json_last_error_msg') ? json_last_error_msg() : 'Unknown decode error';
        logReport("⚠️ SSE JSON decode failed: " . $errorMsg);
        $sseData = array("sseStream" => "Subprocess loaded silently, but invalid JSON: " . $errorMsg);
    }
} else {
    logReport("ℹ️ SSE path not found: " . $ssePath . " (skipping merge).");
    $sseData = array("sseStream" => "File not found; using empty data.");
}

// ---------------------------------------------------------
// 🧩 5. HTML Builder from Codex
// ---------------------------------------------------------
function renderDocument($module, $sseData = array()) {
    $html = '<style>
        body { font-family: Arial, sans-serif; color: #111; font-size: 11pt; }
        h1 { font-size: 16pt; margin-bottom: 4pt; }
        h2 { font-size: 13pt; margin-top: 10pt; color: #333; }
        table { border-collapse: collapse; width: 100%; margin-top: 6pt; }
        th, td { border: 1px solid #888; padding: 4pt; font-size: 10pt; }
        ul { margin: 0 0 6pt 16pt; }
        hr { margin: 10pt 0; }
    </style>';

    $title = isset($module['title']) ? $module['title'] : 'Untitled Report';
    $category = isset($module['category']) ? $module['category'] : 'General';

    $html .= "<h1>$title</h1>";
    $html .= "<p><strong>Category:</strong> $category</p>";

    // Loop through fields
    foreach ($module as $key => $section) {
        if (!is_array($section) || !isset($section['format'])) continue;

        $format = $section['format'];
        $icon = isset($section['icon']) ? $section['icon'] . ' ' : '';
        $html .= "<h2>{$icon}" . ucfirst($key) . "</h2>";
        // Render based on format
        switch ($format) {
            case 'text':
                $text = isset($section['text']) ? $section['text'] : '(No content)';
                $html .= "<p>$text</p>";
                break;

            case 'list':
                $html .= "<ul>";
                if (isset($section['items']) && is_array($section['items'])) {
                    foreach ($section['items'] as $item) {
                        $html .= "<li>$item</li>";
                    }
                }
                $html .= "</ul>";
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

            // 🧩 New support for object-format data (e.g., holidayFallbackRules)
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
                } else {
                    $html .= "<p><em>No structured data found.</em></p>";
                }
                break;
        }

    }

    // SSE snapshot footer (if present)
    if (isset($sseData['timeDateArray']['currentLocalTime'])) {
        $t = $sseData['timeDateArray'];
        $html .= "<hr><p><em>Generated at {$t['currentLocalTime']} on {$t['currentDate']} (Phoenix)</em></p>";
    }

    return $html;
}

// ---------------------------------------------------------
// 🧾 6. Render Final PDF Using Skyesoft Layout Engine
// ---------------------------------------------------------

// Define both potential renderer paths
$renderPathPrimary   = __DIR__ . '/utils/renderPDF_v7.3.php';
$renderPathFallback  = __DIR__ . '/utils/renderPDF.php';      // legacy fallback
$renderPathSecondary = dirname(__DIR__) . '/api/utils/renderPDF.php'; // cross-context safety

// Resolve renderer file in order of preference
if (file_exists($renderPathPrimary)) {
    $rendererPath = $renderPathPrimary;
    logReport("✅ Renderer found: renderPDF_v7.3.php (primary)");
} elseif (file_exists($renderPathFallback)) {
    $rendererPath = $renderPathFallback;
    logReport("⚠️ Using legacy renderer (renderPDF.php).");
} elseif (file_exists($renderPathSecondary)) {
    $rendererPath = $renderPathSecondary;
    logReport("⚙️ Loaded renderer from secondary path.");
} else {
    $msg = "❌ No renderer found in expected paths.";
    logReport($msg);
    echo json_encode(array("success" => false, "message" => $msg));
    exit;
}

// Load renderer once
require_once($rendererPath);

// ---------------------------------------------------------
// 🧠 Prepare report data
// ---------------------------------------------------------
$reportTitle = isset($module['title'])
    ? strip_tags($module['title'])
    : ucfirst(str_replace('_', ' ', $slug));

$reportBody = renderDocument($module, $sseData);

// -------------------------
// 📄 Intelligent File Naming + Folder Routing
// -------------------------

// 1) Base map + helpers
$prefixMap = array(
    'specification' => 'Information Sheet',
    'standard'      => 'Information Sheet',
    'report'        => 'Report',
    'doctrine'      => 'Doctrine Sheet',
    'survey'        => 'Survey Report'
);

function pickPrefix($module) {
    // Prefer explicit type
    if (!empty($module['type'])) {
        $t = strtolower($module['type']);

        // Exact map first
        if (isset($GLOBALS['prefixMap'][$t])) return $GLOBALS['prefixMap'][$t];

        // Heuristics: handle custom types like "temporal-standard"
        if (strpos($t, 'standard') !== false) return 'Information Sheet';
        if (strpos($t, 'report')   !== false) return 'Report';
        if (strpos($t, 'survey')   !== false) return 'Survey Report';
    }

    // Fallback to family hints
    if (!empty($module['family'])) {
        $f = strtolower($module['family']);
        if ($f === 'report') return 'Report';
        if ($f === 'survey') return 'Survey Report';
    }

    // Default
    return 'Information Sheet';
}

$prefix = pickPrefix($module);

// 2) Clean title text
$titleClean = preg_replace('/[^a-zA-Z0-9\s\(\)\-]/', '', $reportTitle);
$titleClean = trim(preg_replace('/\s+/', ' ', $titleClean));

// 3) Folder by prefix
$folderByPrefix = array(
    'Information Sheet' => $basePath . '/docs/sheets/',
    'Doctrine Sheet'    => $basePath . '/docs/sheets/',
    'Report'            => $basePath . '/docs/reports/',
    'Survey Report'     => $basePath . '/docs/reports/'
);

// Default to sheets if somehow unknown
$targetDir = isset($folderByPrefix[$prefix]) ? $folderByPrefix[$prefix] : ($basePath . '/docs/sheets/');

// 4) Ensure folder exists
if (!is_dir($targetDir)) {
    $oldUmask = umask(0);
    mkdir($targetDir, 0777, true);
    umask($oldUmask);
}

// 5) Final filename + path
$fileName   = $prefix . ' - ' . $titleClean . '.pdf';
$outputFile = $targetDir . $fileName;

// 7️⃣ Metadata
$meta = array(
    'generatedAt' => date('Y-m-d H:i:s'),
    'source'      => 'Codex v6',
    'author'      => 'Skyebot System Layer',
    'hostname'    => gethostname(),
    'path'        => $outputFile
);

// ---------------------------------------------------------
// 🖨️  Execute standardized renderer
// ---------------------------------------------------------
try {
    renderPDF($reportTitle, $reportBody, $meta, $outputFile);
    logReport("✅ PDF created successfully: $outputFile");
} catch (Exception $e) {
    logReport("❌ renderPDF() failed: " . $e->getMessage());
    echo json_encode(array(
        "success" => false,
        "message" => "renderPDF() error: " . $e->getMessage()
    ));
    exit;
}

// ---------------------------------------------------------
// 📤 7. JSON Output for Skyebot™
// ---------------------------------------------------------
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(array(
    'success'   => true,
    'slug'      => $slug,
    'title'     => $reportTitle,
    'timestamp' => date('Y-m-d H:i:s'),
    'fileReady' => true,
    'filePath'  => str_replace($basePath . '/', '', $outputFile),
    'message'   => 'PDF generated successfully via Skyesoft render engine.'
));
exit;