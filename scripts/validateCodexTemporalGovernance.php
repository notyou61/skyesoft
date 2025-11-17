<?php
// 🧩 Codex Temporal Governance Validator (v5.3.1)
// Purpose: Ensure all temporal modules inherit reasoning logic dynamically via Codex governance mapping.

$baseDir = __DIR__ . '/../assets/data/codex.json';
if (!file_exists($baseDir)) {
    echo "❌ Codex not found at $baseDir\n";
    exit(1);
}

$json = file_get_contents($baseDir);
$codex = json_decode($json, true);
echo "✅ Loaded Codex from: $baseDir\n";
echo "=== Temporal Governance Validation ===\n";

if (isset($codex['aiIntegration']['temporalGovernance'])) {
    $gov = $codex['aiIntegration']['temporalGovernance'];
    echo "✅ temporalGovernance found under aiIntegration.\n";

    $requiredFields = ['definition', 'appliesTo', 'reasoningRole'];
    $missing = array_diff($requiredFields, array_keys($gov));

    if (empty($missing)) {
        echo "✅ All required fields present.\n";
        echo "📘 Applies to: " . implode(', ', $gov['appliesTo']) . "\n";
        echo "⚖️  " . $gov['reasoningRole'] . "\n";
    } else {
        echo "⚠️ Missing fields: " . implode(', ', $missing) . "\n";
    }
} else {
    echo "❌ temporalGovernance missing from aiIntegration.\n";
}

if (isset($codex['aiIntegration']['temporalReasoning'])) {
    echo "✅ temporalReasoning logic linkage available.\n";
} else {
    echo "⚠️ temporalReasoning not found — linkage incomplete.\n";
}

echo "=== Validation complete ===\n";