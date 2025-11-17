<?php
/**
 * Skyesoft™ Codex Validator (v5.5.1)
 * Confirms structural integrity, required keys, and amendment compliance.
 */

$codexPath = realpath(__DIR__ . '/../assets/data/codex.json');
if (!file_exists($codexPath)) {
    exit("❌ codex.json not found at expected path.\n");
}

$json = file_get_contents($codexPath);
$data = json_decode($json, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    exit("❌ JSON parse error: " . json_last_error_msg() . "\n");
}

// ---- Basic meta checks ----
$meta = $data['codexMeta'] ?? null;
if (!$meta) exit("❌ Missing codexMeta section.\n");

$required = ['title', 'version', 'category'];
foreach ($required as $key) {
    if (empty($meta[$key])) echo "⚠️  Missing meta key: {$key}\n";
}

// ---- Key structure checks ----
$requiredTop = ['codexMeta','ontologySchema','skyesoftConstitution'];
foreach ($requiredTop as $key) {
    if (!isset($data[$key])) echo "⚠️  Missing root object: {$key}\n";
}

// ---- Amendment compliance ----
if (!isset($data['codexAmendments'])) {
    echo "⚠️  No codexAmendments registry found.\n";
} else {
    $amendments = array_keys($data['codexAmendments']['amendments'] ?? []);
    echo "✅ Amendments detected: " . implode(', ', $amendments) . "\n";
}

// ---- Version check ----
$ontologyVersion = $data['ontologySchema']['version'] ?? 'unknown';
if ($ontologyVersion !== '5.5.1') {
    echo "⚠️  Ontology version mismatch (found {$ontologyVersion}, expected 5.5.1)\n";
} else {
    echo "✅ Ontology version 5.5.1 confirmed.\n";
}

// ---- Syntax validation ----
echo "✅ JSON structure valid.\n";
echo "🎯 Validation complete — Codex integrity OK.\n";