<?php
// =============================================================
// 🧪 Codex Temporal Validation – v1.3 (Fixed Path Edition)
// =============================================================

$codexPath = realpath(__DIR__ . '/../assets/data/codex.json');

if (!$codexPath || !file_exists($codexPath)) {
    echo "❌ Codex not found at expected path:\n";
    echo "   " . __DIR__ . "/../assets/data/codex.json\n";
    exit(1);
}

$raw = file_get_contents($codexPath);
$codex = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    exit("❌ Invalid JSON: " . json_last_error_msg() . "\n");
}

echo "✅ Loaded Codex from: {$codexPath}\n";
echo "=== Codex Temporal Validation ===\n";

// ✅ Validate aiIntegration.temporalReasoning
if (isset($codex['aiIntegration']['temporalReasoning'])) {
    $block = $codex['aiIntegration']['temporalReasoning'];
    echo "✅ temporalReasoning found under aiIntegration.\n";
    echo "• Items: " . count($block['items']) . "\n";
    echo "• Notes: " . substr($block['notes'], 0, 80) . "...\n";
} else {
    echo "⚠️ temporalReasoning missing under aiIntegration.\n";
}

// ✅ Scan ontologySchema for temporal/time references
$ontology = isset($codex['ontologySchema']) ? json_encode($codex['ontologySchema']) : '';
if (is_string($ontology) && strlen($ontology) > 0) {
    $hasTemporalRef = (bool)preg_match('/temporal|time/i', $ontology);
    echo $hasTemporalRef
        ? "✅ Ontology schema contains temporal references.\n"
        : "⚠️ Ontology schema lacks temporal reference.\n";
} else {
    echo "⚠️ ontologySchema not found or empty.\n";
}

echo "=== Validation complete ===\n";
