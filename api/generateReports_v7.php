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
    logHelperInvocation(__FUNCTION__);
    $html = '';
    $iconKey = isset($section['icon']) ? $section['icon'] : null;
    $iconFile = $iconKey ? getIconFile($iconKey) : null;  // Assume this returns full path
    $label = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace('_', ' ', $key)));

    logReport("DEBUG: Rendering header for '$key' - icon: $iconKey, label: $label");

    // --- Simplified Header (TCPDF-safe) ---
    $html .= "<h2 style='font-size:13pt; font-weight:bold; color:#000; margin-top:14pt; margin-bottom:6pt;'>";
    if ($iconFile && file_exists($iconFile)) {
        $html .= "<img src='{$iconFile}' alt='{$iconKey}' style='width:11pt; height:11pt; vertical-align:middle; margin-right:6pt;'>";
    } elseif (!empty($iconKey)) {
        $html .= " {$iconKey} ";  // Emoji fallback
    }
    $html .= "{$label}</h2>";
    $html .= "<hr style='border:1pt solid #555; margin-bottom:6pt;'>";

    return $html;
}
#endregion

#region Recursive Codex Node Renderer (Phase 2 Implementation with Debug)
function renderCodexNode($slug, $key, $node, $module, $apiKey, $depth = 0, $isSub = false) {
    global $logDir; // For debug

    if ($depth > 10) {
        logReport("Max recursion depth reached for node '$key'");
        return "<p style='color:#999; font-style:italic;'>[Max depth reached]</p>";
    }

    $nodeType = gettype($node);
    logReport("DEBUG renderCodexNode: key='$key', depth=$depth, isSub=" . ($isSub ? 'true' : 'false') . ", type=$nodeType");

    if (!is_array($node)) {
        $html = htmlspecialchars($node);
        logReport("DEBUG renderCodexNode '$key': Non-array, HTML len=" . strlen($html));
        return $html;
    }

    $format = isset($node['format']) ? strtolower($node['format']) : null;
    $html = '';
    logReport("DEBUG renderCodexNode '$key': Format='$format', keys=" . implode(',', array_keys($node)));

    $enrichmentLevel = isset($module['enrichment']) ? $module['enrichment'] : 'none';
    $useAI = ($enrichmentLevel === 'medium' && $apiKey);

    if ($format) {
        logReport("DEBUG renderCodexNode '$key': Processing explicit format '$format'");
        // Render based on explicit format
        switch ($format) {
            case 'text':
            case 'dynamic':  // Fallback to text
                $textKey = ($format === 'dynamic') ? 'description' : 'text';
                $text = isset($node[$textKey]) ? trim($node[$textKey]) : '';
                logReport("DEBUG renderCodexNode '$key': textKey='$textKey', raw text len=" . strlen($text) . ", preview='" . substr($text, 0, 50) . "'");
                if (empty($text) && $useAI) {
                    $text = getAIEnrichedBody($slug, $key, $module, $apiKey, 'text');
                    logReport("DEBUG renderCodexNode '$key': AI text len=" . strlen($text));
                }
                $html .= "<p style='margin:4pt 0 10pt 0;'>{$text}</p>";
                logReport("DEBUG renderCodexNode '$key': Generated text HTML len=" . strlen($html));
                break;

            case 'list':
                $items = isset($node['items']) && is_array($node['items']) ? $node['items'] : $node;  // Fallback to self if no 'items'
                $itemsCount = count($items);
                logReport("DEBUG renderCodexNode '$key': List items count=$itemsCount");
                if (empty($items) && $useAI) {
                    $aiText = getAIEnrichedBody($slug, $key, $module, $apiKey, 'list');
                    $html .= "<p>{$aiText}</p>";
                    logReport("DEBUG renderCodexNode '$key': Used AI for list");
                } else {
                    $html .= "<ul style='margin:6pt 0 10pt 18pt;'>";
                    foreach ($items as $idx => $item) {
                        $itemHtml = is_array($item) ? renderCodexNode($slug, "item_$idx", $item, $module, $apiKey, $depth + 1, false) : htmlspecialchars($item);
                        $html .= "<li style='margin-bottom:4pt;'>{$itemHtml}</li>";
                    }
                    $html .= "</ul>";
                    logReport("DEBUG renderCodexNode '$key': Generated UL with $itemsCount LIs, total len=" . strlen($html));
                }
                break;

            case 'table':
                $tableData = null;
                if (isset($node['items']) && is_array($node['items']) && count($node['items']) > 0) {
                    $tableData = $node['items'];
                } elseif (isset($node['holidays']) && is_array($node['holidays']) && count($node['holidays']) > 0) {
                    $tableData = $node['holidays'];
                } elseif (isset($node['data']) && is_array($node['data']) && count($node['data']) > 0) {
                    $tableData = $node['data'];
                }
                $dataCount = $tableData ? count($tableData) : 0;
                logReport("DEBUG renderCodexNode '$key': Table data source='$tableData source', rows=$dataCount");
                if ($tableData && $dataCount > 0) {
                    $headers = array_keys($tableData[0]);
                    $html .= "<table style='border-collapse:collapse; width:100%; margin:6pt 0;'>";
                    $html .= "<tr>";
                    foreach ($headers as $h) {
                        $html .= "<th style='border:0.5pt solid #888; padding:4pt; background:#f2f2f2;'>{$h}</th>";
                    }
                    $html .= "</tr>";
                    foreach ($tableData as $row) {
                        $html .= "<tr>";
                        foreach ($headers as $h) {
                            $val = isset($row[$h]) ? $row[$h] : '';
                            if (is_array($val)) $val = implode(', ', $val);
                            $html .= "<td style='border:0.5pt solid #888; padding:4pt;'>{$val}</td>";
                        }
                        $html .= "</tr>";
                    }
                    $html .= "</table>";
                    logReport("DEBUG renderCodexNode '$key': Generated table with " . count($headers) . " cols, $dataCount rows, len=" . strlen($html));
                } elseif ($useAI) {
                    $html .= getAIEnrichedBody($slug, $key, $module, $apiKey, 'table');
                    logReport("DEBUG renderCodexNode '$key': Used AI for table");
                } else {
                    $html .= "<p><em>No table data available.</em></p>";
                    logReport("DEBUG renderCodexNode '$key': Table fallback message");
                }
                break;

            case 'object':
                $data = isset($node['data']) ? $node['data'] : $node;  // Fallback to self
                $dataCount = count($data);
                logReport("DEBUG renderCodexNode '$key': Object data count=$dataCount");
                if (!empty($data)) {
                    $html .= "<table style='border-collapse:collapse; width:100%; margin:6pt 0;'>";
                    $html .= "<tr><th style='border:0.5pt solid #888; padding:4pt; background:#f2f2f2;'>Key</th><th style='border:0.5pt solid #888; padding:4pt; background:#f2f2f2;'>Value</th></tr>";
                    foreach ($data as $k => $v) {
                        $details = is_array($v) ? json_encode($v, JSON_PRETTY_PRINT) : htmlspecialchars($v);
                        $html .= "<tr><td style='border:0.5pt solid #888; padding:4pt; font-weight:bold;'>{$k}</td><td style='border:0.5pt solid #888; padding:4pt;'>{$details}</td></tr>";
                    }
                    $html .= "</table>";
                    logReport("DEBUG renderCodexNode '$key': Generated object table with $dataCount rows, len=" . strlen($html));
                } elseif ($useAI) {
                    $html .= getAIEnrichedBody($slug, $key, $module, $apiKey, 'object');
                    logReport("DEBUG renderCodexNode '$key': Used AI for object");
                } else {
                    $html .= "<p><em>No object data available.</em></p>";
                    logReport("DEBUG renderCodexNode '$key': Object fallback message");
                }
                break;

            default:
                $html .= "<p><em>Format '{$format}' not yet supported.</em></p>";
                logReport("DEBUG renderCodexNode '$key': Unsupported format '$format'");
        }
        logReport("DEBUG renderCodexNode '$key': Final explicit HTML len=" . strlen($html));
        return $html;
    } else {
        // No explicit format: infer and recurse
        logReport("DEBUG renderCodexNode '$key': No format, inferring structure");
        if ($isSub) {
            $label = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace('_', ' ', $key)));
            $html .= "<h3 style='font-size:12pt; font-weight:bold; color:#000; margin-top:10pt; margin-bottom:4pt;'>{$label}</h3>";
            $html .= "<hr style='border:0.5pt solid #888; margin-bottom:4pt;'>";
            logReport("DEBUG renderCodexNode '$key': Added sub-header");
        }

        // Single-text pattern
        if (count($node) == 1 && isset($node['text'])) {
            $text = trim($node['text']);
            logReport("DEBUG renderCodexNode '$key': Single-text match, len=" . strlen($text));
            if (empty($text) && $useAI) {
                $text = getAIEnrichedBody($slug, $key, $module, $apiKey, 'text');
            }
            if (!empty($text)) {
                $html .= "<p style='margin:4pt 0 10pt 0;'>{$text}</p>";
                logReport("DEBUG renderCodexNode '$key': Generated single-text P, len=" . strlen($html));
                return $html;
            }
        }

        // List-like (indexed array)
        $keys = array_keys($node);
        $isList = $keys === range(0, count($keys) - 1);
        logReport("DEBUG renderCodexNode '$key': isList=" . ($isList ? 'true' : 'false'));
        if ($isList && !empty($node)) {
            // Infer table for assoc items
            $allAssoc = true;
            $commonHeaders = null;
            $itemCount = count($node);
            for ($i = 0; $i < min(3, $itemCount); $i++) {
                $item = $node[$i];
                if (!is_array($item) || array_keys($item) === range(0, count($item)-1)) {
                    $allAssoc = false;
                    break;
                }
                $itemKeys = array_keys($item);
                if ($commonHeaders === null) {
                    $commonHeaders = $itemKeys;
                } elseif ($commonHeaders !== $itemKeys) {
                    $allAssoc = false;
                    break;
                }
            }
            logReport("DEBUG renderCodexNode '$key': allAssoc=" . ($allAssoc ? 'true' : 'false') . ", headers=" . ($commonHeaders ? implode(',', $commonHeaders) : 'none'));
            if ($allAssoc && $commonHeaders !== null && count($commonHeaders) > 0) {
                $html .= "<table style='border-collapse:collapse; width:100%; margin:6pt 0;'>";
                $html .= "<tr>";
                foreach ($commonHeaders as $h) {
                    $html .= "<th style='border:0.5pt solid #888; padding:4pt; background:#f2f2f2;'>{$h}</th>";
                }
                $html .= "</tr>";
                foreach ($node as $row) {
                    $html .= "<tr>";
                    foreach ($commonHeaders as $h) {
                        $val = isset($row[$h]) ? $row[$h] : '';
                        if (is_array($val)) $val = implode(', ', $val);
                        $html .= "<td style='border:0.5pt solid #888; padding:4pt;'>{$val}</td>";
                    }
                    $html .= "</tr>";
                }
                $html .= "</table>";
                logReport("DEBUG renderCodexNode '$key': Inferred table len=" . strlen($html));
            } else {
                // Bulleted list
                if (empty($node) && $useAI) {
                    $html .= getAIEnrichedBody($slug, $key, $module, $apiKey, 'list');
                } else {
                    $html .= "<ul style='margin:6pt 0 10pt 18pt;'>";
                    foreach ($node as $item) {
                        $itemHtml = is_array($item) ? renderCodexNode($slug, 'item', $item, $module, $apiKey, $depth + 1, false) : htmlspecialchars($item);
                        $html .= "<li style='margin-bottom:4pt;'>{$itemHtml}</li>";
                    }
                    $html .= "</ul>";
                    logReport("DEBUG renderCodexNode '$key': Inferred list len=" . strlen($html));
                }
            }
        } else {
            // Object/inferred
            $allSimple = true;
            $simpleCount = 0;
            foreach ($node as $subNode) {
                if (is_array($subNode)) {
                    $allSimple = false;
                    break;
                }
                $simpleCount++;
            }
            logReport("DEBUG renderCodexNode '$key': allSimple=" . ($allSimple ? 'true' : 'false') . ", simpleCount=$simpleCount");
            if ($allSimple && $simpleCount > 0) {
                $html .= "<table style='border-collapse:collapse; width:100%; margin:6pt 0;'>";
                $html .= "<tr><th style='border:0.5pt solid #888; padding:4pt; background:#f2f2f2;'>Key</th><th style='border:0.5pt solid #888; padding:4pt; background:#f2f2f2;'>Value</th></tr>";
                foreach ($node as $k => $v) {
                    if (in_array($k, ['format', 'icon'])) continue;
                    $labelK = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace('_', ' ', $k)));
                    $details = htmlspecialchars($v);
                    $html .= "<tr><td style='border:0.5pt solid #888; padding:4pt; font-weight:bold;'>{$labelK}</td><td style='border:0.5pt solid #888; padding:4pt;'>{$details}</td></tr>";
                }
                $html .= "</table>";
                logReport("DEBUG renderCodexNode '$key': Inferred simple object len=" . strlen($html));
            } else {
                // Recurse subs
                if (empty($node) && $useAI) {
                    $html .= getAIEnrichedBody($slug, $key, $module, $apiKey, 'object');
                } else {
                    $subProcessed = 0;
                    foreach ($node as $subKey => $subNode) {
                        if (in_array($subKey, ['format', 'icon'])) continue;
                        $subProcessed++;
                        logReport("DEBUG renderCodexNode '$key': Recursing sub '$subKey'");
                        if (!is_array($subNode)) {
                            $labelSub = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace('_', ' ', $subKey)));
                            $html .= "<p style='margin:4pt 0;'><b>{$labelSub}:</b> " . htmlspecialchars($subNode) . "</p>";
                        } else {
                            $subHtml = renderCodexNode($slug, $subKey, $subNode, $module, $apiKey, $depth + 1, true);
                            if (!empty($subHtml)) {
                                $html .= $subHtml;
                            }
                        }
                    }
                    logReport("DEBUG renderCodexNode '$key': Processed $subProcessed subs, total len=" . strlen($html));
                }
            }
        }

        logReport("DEBUG renderCodexNode '$key': Final inferred HTML len=" . strlen($html));
        return $html;
    }
}
#endregion

#region Render Document Function
function renderDocument($slug, $module, $apiKey) {
    $cssPath = __DIR__ . '/../assets/styles/reportBase.css';
    $styleBlock = file_exists($cssPath)
        ? "<style>" . file_get_contents($cssPath) . "</style>"
        : "<style>body{font-family:Arial,sans-serif;font-size:11pt;color:#111;}</style>";
    $html = $styleBlock;

    logReport("DEBUG renderDocument: Starting for slug '$slug', module keys=" . implode(',', array_keys($module)));

    // --- Header ---
    $title = isset($module['title']) ? $module['title'] : 'Untitled Report';
    $category = isset($module['category']) ? $module['category'] : 'General';
    $html .= "<h1>$title</h1>";
    $html .= "<p><strong>Category:</strong> $category</p>";
    logReport("DEBUG renderDocument: Header added, len=" . strlen($html));

    // --- Section Loop ---
    $sectionsProcessed = 0;
    foreach ($module as $key => $section) {
        // Skip meta or system keys (but NOT relationships)
        if (in_array($key, ['title', 'category', 'type', 'enrichment', 'meta', 'subtypes', 'actions'])) continue;

        $sectionsProcessed++;
        $hasFormat = isset($section['format']) ? 'yes' : 'no';
        logReport("DEBUG Section Loop: Processing [$key] (section $sectionsProcessed). Type=" . gettype($section) . "; Has format=$hasFormat; Section keys=" . (is_array($section) ? implode(',', array_keys($section)) : 'N/A'));

        // --- Handle string-only sections ---
        if (is_string($section)) {
            $label = ucfirst(str_replace('_', ' ', $key));
            $html .= "<div style='margin-top:14pt; margin-bottom:6pt;'>";
            $html .= "<span style='font-size:13pt; font-weight:bold; color:#000;'>{$label}</span>";
            $html .= "<div style='border-bottom:1pt solid #555; margin-top:3pt;'></div>";
            $html .= "<p style='margin-top:4pt; margin-bottom:10pt;'>" . htmlspecialchars($section) . "</p>";
            $html .= "</div>";
            logReport("DEBUG Section '$key': String section rendered, content len=" . strlen($section));
            continue;
        }

        // --- Generic Relationship Structure Detection ---
        if (isset($section['isA']) || isset($section['governs']) || isset($section['dependsOn'])) {
            logReport("DEBUG Section '$key': Relationship-type structure detected");
            $html .= renderSectionHeader($key, $section);
            $html .= renderCodexNode($slug, $key, $section, $module, $apiKey, 0, false);
            continue;
        }

        // --- Handle structured sections (array) with recursive rendering ---
        if (is_array($section)) {
            $headerLen = strlen($html);
            $html .= renderSectionHeader($key, $section);
            logReport("DEBUG Section '$key': Header added, delta len=" . (strlen($html) - $headerLen));

            $bodyStartLen = strlen($html);

            // Stage-3: Conditional rendering
            if (isset($section['format'])) {
                logReport("DEBUG Section '$key': Has section format, calling renderCodexNode on whole section");
                $html .= renderCodexNode($slug, $key, $section, $module, $apiKey, 0, false);
            } else {
                logReport("DEBUG Section '$key': No section format, iterating subnodes");
                foreach ($section as $subKey => $subNode) {
                    if (in_array($subKey, ['icon', 'format'])) continue;
                    logReport("DEBUG Section '$key': Rendering subnode '$subKey' (type=" . gettype($subNode) . ")");
                    if (!is_array($subNode)) {
                        // Simple value
                        if (in_array($subKey, ['text', 'description'])) {
                            $subHtml = "<p style='margin:4pt 0 10pt 0;'>" . htmlspecialchars($subNode) . "</p>";
                        } else {
                            $labelSub = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', str_replace('_', ' ', $subKey)));
                            $subHtml = "<p style='margin:4pt 0;'><b>{$labelSub}:</b> " . htmlspecialchars($subNode) . "</p>";
                        }
                        $html .= $subHtml;
                        logReport("DEBUG Section '$key' sub '$subKey': Simple P added, len=" . strlen($subHtml));
                    } else {
                        // Array subnode
                        $useSubHeader = (
                            is_array($subNode)
                            && !isset($subNode['format'])
                            && array_values($subNode) !== $subNode // associative array
                        );
                        $subHtml = renderCodexNode($slug, $subKey, $subNode, $module, $apiKey, 0, $useSubHeader);
                        $html .= $subHtml;
                        logReport("DEBUG Section '$key' sub '$subKey': Recursive call, added len=" . strlen($subHtml));
                    }
                }
            }

            $bodyLen = strlen($html) - $bodyStartLen;
            logReport("DEBUG Section '$key': Body complete, added len=$bodyLen");
        }
    }
    logReport("DEBUG renderDocument: Processed $sectionsProcessed sections, total HTML len=" . strlen($html));

    // --- Meta Footer ---
    $type = isset($module['type']) ? strtolower($module['type']) : '';
    if (strpos($type, 'information') !== false) {
        global $codex;
        $html .= "<hr>" . renderMetaFooterFromCodex($codex, $slug, $module);
        logReport("DEBUG renderDocument: Meta footer added");
    }

    // --- Technical Footer ---
    $html .= "<hr><p style='font-size:9pt; color:#666;'><em>Generated from Codex v7 – AI Hybrid Renderer (Recursive v7.3.1-stable)</em></p>";
    
    // Return final HTML
    return $html;
}
#endregion

#region PDF Generation and Output
require_once(__DIR__ . '/utils/renderPDF_v7.3.php');

$moduleDump = json_encode(array_keys($module));
logReport("DEBUG keys for $slug: $moduleDump");
$bodyPreview = renderDocument($slug, $module, $OPENAI_API_KEY);
logReport("DEBUG body length: " . strlen($bodyPreview));
logReport("DEBUG body preview (stripped tags): " . substr(strip_tags($bodyPreview), 0, 500));  // Longer preview

$reportTitle = strip_tags(isset($module['title']) ? $module['title'] : ucfirst($slug));
$reportBody = renderDocument($slug, $module, $OPENAI_API_KEY);

// NEW v7.3.1: Save full HTML for inspection
$debugHtmlFile = $basePath . '/debug_' . $slug . '_' . date('His') . '.html';  // Timestamped
file_put_contents($debugHtmlFile, $reportBody);
logReport("DEBUG HTML saved: $debugHtmlFile (len=" . strlen($reportBody) . ")");

$type = isset($module['type']) ? strtolower($module['type']) : 'standard';
$prefix = (strpos($type, 'report') !== false) ? 'Report' : 'Information Sheet';

// ✅ Route output path via File Management schema
global $codex;
$rules = $codex['fileManagement']['rules']['items'] ?? [];
$baseOut = strpos(json_encode($rules), '/docs/reports/') !== false ? '/docs/reports/' : '/docs/sheets/';
$titleClean = trim(preg_replace('/[^a-zA-Z0-9\s\(\)\-]/', '', $reportTitle));
$fileName   = $prefix . ' - ' . $titleClean . '.pdf';
$outputFile = $basePath . $baseOut . $fileName;

if (!is_dir(dirname($outputFile))) { mkdir(dirname($outputFile), 0777, true); }

$meta = array(
    'generatedAt' => date('Y-m-d H:i:s'),
    'source'      => 'Codex v7',
    'author'      => 'Skyebot System Layer'
);

renderPDF($reportTitle, $reportBody, $meta, $outputFile);
logReport("PDF created: $outputFile");

echo json_encode(array(
    "success"   => true,
    "slug"      => $slug,
    "title"     => $reportTitle,
    "timestamp" => date('Y-m-d H:i:s'),
    "fileReady" => true,
    "filePath"  => str_replace($basePath . '/', '', $outputFile),
    "debugHtml" => str_replace($basePath . '/', '', $debugHtmlFile),
    "message"   => "PDF generated (v7.3.1-stable). Check logs and debug HTML for content."
));
exit;
#endregion

#region Meta Footer Injection
// Automatically applied for Information Sheet documents
// Implemented via renderMetaFooterFromCodex() in helpers.php (or mocked)
#endregion