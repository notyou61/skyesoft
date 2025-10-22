<?php
// 📄 File: api/ai/semanticRouter.php
// Version: v1.0 (PHP 5.6 compatible)
// Purpose: Route Skyebot prompts to correct intent handler using Codex & SSE context

#region 🔧 Basic Setup
error_reporting(E_ALL);
ini_set('display_errors', 1);

function routeIntent($prompt, $codexPath, $ssePath) {

    // Load Codex & SSE snapshots
    $codex = (is_readable($codexPath)) ? json_decode(file_get_contents($codexPath), true) : array();
    $sse   = (is_readable($ssePath))   ? json_decode(file_get_contents($ssePath), true)   : array();

    $lower = strtolower(trim($prompt));
    $intent = "conversation"; // default intent

    // --- Simple semantic detection ---
    if (strpos($lower, "time") !== false || strpos($lower, "workday") !== false) {
        $intent = "temporal";
    } elseif (strpos($lower, "permit") !== false || strpos($lower, "report") !== false) {
        $intent = "dataRequest";
    }

    // --- Route to intent module ---
    $intentFile = __DIR__ . "/intents/{$intent}.php";
    if (file_exists($intentFile)) {
        require_once($intentFile);
        $handler = "handle_" . $intent;
        if (function_exists($handler)) {
            return $handler($prompt, $codex, $sse);
        }
    }

    // --- Default fallback ---
    return "I'm here and listening — tell me more about what you need.";
}
#endregion