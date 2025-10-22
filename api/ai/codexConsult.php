<?php
// 📄 File: api/ai/codexConsult.php
// Minimal placeholder for Codex lookup utilities (Phase 4–5 transitional)

function getCodexModule($moduleName, $codexPath = null) {
    $path = $codexPath ? $codexPath : __DIR__ . '/../../docs/codex/codex.json';
    if (!file_exists($path)) {
        error_log("⚠️ Codex file not found at $path");
        return array('error' => 'Codex missing');
    }

    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return array('error' => 'Invalid Codex JSON');

    if (isset($data[$moduleName])) {
        return $data[$moduleName];
    }

    return array('error' => "Module '$moduleName' not found in Codex");
}