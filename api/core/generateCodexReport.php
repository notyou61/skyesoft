<?php
// =====================================================================
//  Skyesoft™ Codex Report Generator – Core Glue  |  PHP 5.6-Safe
// ---------------------------------------------------------------------
//  Parliamentarian Compliance:
//     • §4.1.2  Dynamic Slug Resolution
//     • §4.2.1  Document Title Integrity
//     • §4.3.4  Non-Hardcoded Document Classification
//     • §5.1.1  Regional Code Structuring
// =====================================================================

#region Dependencies
require_once(__DIR__ . '/renderPDF_core.php');
require_once(__DIR__ . '/renderBodyFromCodex.php');
#endregion

#region Slug Resolution
$slug = null;
if (isset($argv[1])) {
    $slug = trim($argv[1]);
} elseif (isset($_GET['slug'])) {
    $slug = trim($_GET['slug']);
}
#endregion

#region Load Codex
$codexPath = __DIR__ . '/../../assets/data/codex.json';
if (!file_exists($codexPath)) {
    echo "❌ Codex file missing at $codexPath\n";
    exit(1);
}
$codex = json_decode(file_get_contents($codexPath), true);
if (!is_array($codex)) {
    echo "❌ Codex could not be decoded.\n";
    exit(1);
}
#endregion

#region Default Fallback
if (!$slug) {
    $slug = isset($codex['defaults']['primaryModule'])
        ? $codex['defaults']['primaryModule']
        : 'index';
}
#endregion

#region Extract Node and Title
$node = isset($codex[$slug]) ? $codex[$slug] : array();
if (isset($node['title']) && trim($node['title']) !== '') {
    $title = trim($node['title']);
} else {
    $title = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $slug));
}
#endregion

#region Document Class Determination
function codex_getDocClass($n) {
    $type   = isset($n['type']) ? strtolower($n['type']) : '';
    $family = isset($n['family']) ? strtolower($n['family']) : '';
    if ($type === 'specification') {
        if ($family === 'report') return 'Report';
        if ($family === 'survey') return 'Survey';
        return 'Specification';
    }
    if (strpos($type, 'standard') !== false) return 'Information Sheet';
    if ($type === 'registry') return 'Registry';
    if ($type === 'taxonomy') return 'Taxonomy';
    return 'Document';
}
$docClass = codex_getDocClass($node);
#endregion

#region Body Rendering
$body = renderBodyFromCodex($slug);
#endregion

#region Output Path and File
$baseDir = realpath(__DIR__ . '/../../docs/');
$subDir  = 'sheets';
if ($docClass === 'Report') $subDir = 'reports';
elseif ($docClass === 'Survey') $subDir = 'surveys';
$outputDir = $baseDir . DIRECTORY_SEPARATOR . $subDir . DIRECTORY_SEPARATOR;
if (!is_dir($outputDir)) {
    $old = umask(0); mkdir($outputDir, 0777, true); umask($old);
}
$titleClean = preg_replace('/[^a-zA-Z0-9\s\(\)\-\x{1F300}-\x{1FAFF}]/u', '', $title);
$outputFile = $outputDir . $docClass . ' - ' . trim($titleClean) . '.pdf';
#endregion

#region Metadata + PDF Generation  // Parliamentarian §3.4.2 – DRY Temporal Source

// --- Default timestamp (fallback) ---
$localTime = date('Y-m-d H:i:s');

// --- Prefer SSE Stream time if available ---
$dynDataPath = __DIR__ . '/../../api/getDynamicData.php';
if (file_exists($dynDataPath)) {
    // Execute PHP file and decode JSON (PHP 5.6-safe)
    $json = shell_exec('php ' . escapeshellarg($dynDataPath));
    $data = json_decode($json, true);

    if (is_array($data)
        && isset($data['timeDateArray']['currentDate'])
        && isset($data['timeDateArray']['currentLocalTime'])
    ) {
        // Combine date + time from SSE (America/Phoenix clock)
        $localTime = trim($data['timeDateArray']['currentDate'] . ' ' . $data['timeDateArray']['currentLocalTime']);
    }
}

// --- Metadata passed to renderer ---
$meta = array(
    'generatedAt' => $localTime,
    'author'      => 'Skyebot™ System Layer',
    'docClass'    => $docClass
);

// --- Render PDF ---
renderPDF($title, $body, $meta, $outputFile);

#endregion

#region Final Output
echo "✅ PDF created: " . realpath($outputFile) . "\n";
#endregion