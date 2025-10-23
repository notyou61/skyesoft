<?php
// Skyesoft Policy Link Diagnostic (v2)
header('Content-Type: text/plain; charset=utf-8');

$path = __DIR__ . '/../../assets/data/codex.json';
echo "🔍 Loading Codex from: $path\n\n";

if (!file_exists($path)) {
    exit("❌ File not found.\n");
}

$json = file_get_contents($path);
$codex = json_decode($json, true);

if (!$codex) {
    exit("❌ JSON failed to decode. Last JSON error: " . json_last_error_msg() . "\n");
}

echo "✅ Codex loaded successfully.\n\n";

if (!isset($codex['sseStream'])) {
    exit("❌ Missing 'sseStream' module in Codex.\n");
}

echo "🌐 SSE Stream found.\n";

print_r($codex['sseStream']);

$tiers = isset($codex['sseStream']['tiers']) ? $codex['sseStream']['tiers'] : null;
if (!is_array($tiers)) {
    exit("❌ Invalid or missing 'tiers' array.\n");
}

echo "📊 Found " . count($tiers) . " tier(s).\n\n";

foreach ($tiers as $tierName => $tierData) {
    echo "▶️ Tier: $tierName\n";
    echo "   Interval: " . $tierData['interval'] . "s\n";
    if (!empty($tierData['members'])) {
        echo "   Members:\n";
        foreach ($tierData['members'] as $m) {
            echo "     - $m\n";
        }
    } else {
        echo "   ⚠️ No members listed.\n";
    }
    echo "\n";
}

echo "✅ Policy linkage test complete.\n";