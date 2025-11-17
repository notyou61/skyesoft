<?php
// =====================================================================
//  Skyesoft™ Icon Tester – Verifies Codex ↔ iconMap consistency
// =====================================================================

$root = dirname(__DIR__, 2); // adjust if saved elsewhere
$iconMapPath = $root . '/assets/data/iconMap.json';
$codexPath   = $root . '/assets/data/codex.json';
$iconDir     = $root . '/assets/images/icons/';

if (!file_exists($iconMapPath) || !file_exists($codexPath)) {
    die("❌ Missing iconMap.json or codex.json\n");
}

// Load JSON safely
$iconMap = json_decode(file_get_contents($iconMapPath), true);
$codex   = json_decode(file_get_contents($codexPath), true);
if (!$iconMap || !$codex) die("❌ Failed to decode one of the JSON files.\n");

// --- Helper: resolve icon (same logic as in render engine)
function resolveHeaderIcon($iconKey, $iconMap, $iconDir) {
    if (isset($iconMap[$iconKey]['file'])) {
        $path = $iconDir . $iconMap[$iconKey]['file'];
        return file_exists($path) ? $path : null;
    }
    foreach ($iconMap as $data) {
        if (isset($data['icon']) && trim($data['icon']) === trim($iconKey)) {
            $path = $iconDir . $data['file'];
            return file_exists($path) ? $path : null;
        }
    }
    return null;
}

echo "🧩 Skyesoft™ Icon Resolution Test\n";
echo "─────────────────────────────────────\n";

$missing = 0;
foreach ($codex as $key => $module) {
    if (!is_array($module) || !isset($module['title'])) continue;
    // Extract possible emoji prefix (first UTF-8 char)
    $emoji = trim(mb_substr($module['title'], 0, 2, 'UTF-8'));
    $iconFile = resolveHeaderIcon($emoji, $iconMap, $iconDir);
    $status = $iconFile ? "✅" : "⚠️";
    if (!$iconFile) $missing++;
    printf("%-3s %-25s → %-20s %s\n",
        $status,
        $emoji,
        basename($iconFile ?: 'not found'),
        $module['title']
    );
}

echo "─────────────────────────────────────\n";
echo ($missing === 0)
    ? "🎉 All icons resolved successfully!\n"
    : "⚠️  $missing icon(s) missing or unmatched.\n";
