<?php
// =====================================================================
//  Skyesoft™ Codex Report Generator – Core Glue  |  PHP 5.6-Safe
//  Purpose: Loads Codex entry by slug, uses its title & body renderer,
//           and passes data to the Core PDF Renderer (v1.0 Frame)
//  Parliamentarian Compliance: Section 4.2.1 (Document Title Integrity)
// =====================================================================

require_once(__DIR__ . '/renderPDF_core.php');
require_once(__DIR__ . '/renderBodyFromCodex.php');

// --- 1️⃣  Resolve input slug ---
$slug = isset($argv[1]) ? trim($argv[1]) : 'timeIntervalStandards';

// --- 2️⃣  Load Codex JSON ---
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

// --- 3️⃣  Determine readable title ---
$title = '';
if (isset($codex[$slug]['title']) && trim($codex[$slug]['title']) !== '') {
    // Use the Codex-defined title (e.g., "⏱️ Time Interval Standards (TIS)")
    $title = $codex[$slug]['title'];
} else {
    // Fallback: format slug → "Time Interval Standards"
    $title = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $slug));
}

// --- 4️⃣  Render the body content ---
$body = renderBodyFromCodex($slug);

// --- 5️⃣  Determine output path ---
$outputDir = __DIR__ . '/../../docs/sheets/';
if (!is_dir($outputDir)) {
    $old = umask(0);
    mkdir($outputDir, 0777, true);
    umask($old);
}
$outputFile = $outputDir . 'Information_Sheet_' . $slug . '.pdf';

// --- 6️⃣  Generate PDF ---
$meta = array(
    'generatedAt' => date('Y-m-d H:i:s'),
    'author'      => 'Skyebot™ System Layer'
);

renderPDF($title, $body, $meta, $outputFile);

// --- 7️⃣  Final output ---
echo "✅ PDF created: " . realpath($outputFile) . "\n";