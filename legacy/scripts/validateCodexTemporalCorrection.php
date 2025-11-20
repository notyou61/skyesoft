<?php
// =============================================================
// 🧭 Skyesoft Codex Validator – Temporal Correction Handling
// Version: 1.0 – Codex v5.3
// Purpose: Ensure Codex defines temporal correction logic under aiIntegration
// Compatible: PHP 5.6+
// =============================================================

$codexPath = __DIR__ . '/../assets/data/codex.json';
if (!file_exists($codexPath)) {
    echo "❌ Codex not found at: $codexPath\n";
    exit(1);
}

$codex = json_decode(file_get_contents($codexPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Invalid JSON in codex.json: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "✅ Loaded Codex from: {$codexPath}\n";
echo "=== Temporal Correction Validation ===\n";

// Ensure aiIntegration exists
if (!isset($codex['aiIntegration'])) {
    echo "❌ aiIntegration section missing.\n";
    exit(1);
}

// Validate temporalCorrectionHandling block
$aih = $codex['aiIntegration'];
if (isset($aih['temporalCorrectionHandling'])) {
    $tch = $aih['temporalCorrectionHandling'];
    echo "✅ Found temporalCorrectionHandling.\n";

    $expectedKeys = array('definition', 'example', 'reasoningRole');
    $missing = array_filter($expectedKeys, function($k) use ($tch) {
        return !isset($tch[$k]);
    });

    if (empty($missing)) {
        echo "✅ All core fields present.\n";
    } else {
        echo "⚠️ Missing fields: " . implode(', ', $missing) . "\n";
    }

    if (isset($tch['feedbackBehavior'])) {
        echo "🗣️ feedbackBehavior enabled: {$tch['feedbackBehavior']}\n";
    } else {
        echo "ℹ️ feedbackBehavior not defined (optional).\n";
    }
} else {
    echo "❌ temporalCorrectionHandling not found under aiIntegration.\n";
    exit(1);
}

echo "=== Validation complete. ===\n";
?>
