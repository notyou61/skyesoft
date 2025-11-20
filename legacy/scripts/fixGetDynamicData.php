<?php
// =============================================================
// 🧭 Skyesoft Codex Repair Utility – getDynamicData.php
// Version: 1.4.5
// Purpose: Auto-fix Codex Compliance violations in getDynamicData.php
// Compatible: PHP 5.6+
// =============================================================

$target = __DIR__ . '/../api/getDynamicData.php';
$output = __DIR__ . '/../api/getDynamicData_repaired.php';

if (!file_exists($target)) {
    echo "❌ Target file not found: $target\n";
    exit(1);
}

$src = file_get_contents($target);

// =============================================================
// 1️⃣ Ensure region headers include version tags
// =============================================================
$src = preg_replace_callback('/#region\s+([^\n]+)/', function($m) {
    $regionName = trim($m[1]);
    return "#region {$regionName}\n// Version: Codex v1.4";
}, $src);

// =============================================================
// 2️⃣ Append #endregion markers if missing
// =============================================================
if (!preg_match('/#endregion\s*$/m', $src)) {
    $src .= "\n#endregion\n";
}

// =============================================================
// 3️⃣ Replace hardcoded constants with Codex references
// =============================================================
$replacements = array(
    "define('DEFAULT_SUNSET', '19:42:00');" =>
        "define('DEFAULT_SUNSET', getConst('kpiData','defaultSunset'));",
    "define('DEFAULT_SUNRISE', '05:27:00');" =>
        "define('DEFAULT_SUNRISE', getConst('kpiData','defaultSunrise'));"
);
$src = strtr($src, $replacements);

// =============================================================
// 4️⃣ Normalize error_log() to Codex logger
// =============================================================
$src = preg_replace(
    '/error_log\(([^\)]+)\);/',
    "logCacheEvent('getDynamicData', 'warning: ' . trim($1));",
    $src
);

// =============================================================
// 5️⃣ Inject Phase 4 cache region if missing
// =============================================================
if (strpos($src, '⚡ Phase 4') === false) {
    $cacheRegion = <<<PHP

#region ⚡ Phase 4 – Codex-Aware Caching & TTL Enforcement
// Version: Codex v1.4
\$weatherData = resolveCache('weather', getConst('kpiData','cacheTtlSeconds'), function() {
    return fetchWeatherData(); // your existing weather fetch routine
});
#endregion

PHP;
    $src .= "\n" . $cacheRegion;
}

// =============================================================
// 6️⃣ Fix indentation and trailing whitespace
// =============================================================
$src = preg_replace('/[ \t]+$/m', '', $src);

// =============================================================
// 7️⃣ Write output file
// =============================================================
if (file_put_contents($output, $src)) {
    echo "✅ Codex Repair complete: $output\n";
    echo "→ Run: php -l $output  (to verify syntax)\n";
    echo "→ Replace original after confirming SSE still works.\n";
} else {
    echo "❌ Failed to write repaired file.\n";
}