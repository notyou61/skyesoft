<?php
// 📘 File: api/ai/semanticRouter.php
// Purpose: Directs user input to appropriate Codex domains and intent handlers
// Version: v2.7 – Restored routeIntent() for live dispatch (2025-10-23)

// ================================================================
// 🔹 SAFETY NORMALIZATION LAYER
// Ensures router never fails when receiving non-string input
// ================================================================
function semanticRoute($input)
{
    if (is_array($input)) {
        $input = implode(' ', $input);
    }
    $input = trim((string)$input);

    $default = array(
        'domain'     => 'general',
        'target'     => 'skyesoftConstitution',
        'confidence' => 0.5
    );

    if ($input === '') {
        return $default;
    }

    $inputLower = strtolower($input);

    // Temporal domain
    if (strpos($inputLower, 'workday') !== false ||
        strpos($inputLower, 'time') !== false ||
        strpos($inputLower, 'hour') !== false ||
        strpos($inputLower, 'schedule') !== false) {
        return array(
            'domain'     => 'temporal',
            'target'     => 'timeIntervalStandards',
            'confidence' => 0.9
        );
    }

    // Contextual domain
    if (strpos($inputLower, 'weather') !== false ||
        strpos($inputLower, 'temperature') !== false ||
        strpos($inputLower, 'forecast') !== false) {
        return array(
            'domain'     => 'contextual',
            'target'     => 'weatherData',
            'confidence' => 0.9
        );
    }

    // Governance domain
    if (strpos($inputLower, 'constitution') !== false ||
        strpos($inputLower, 'codex') !== false ||
        strpos($inputLower, 'policy') !== false) {
        return array(
            'domain'     => 'governance',
            'target'     => 'skyesoftConstitution',
            'confidence' => 0.8
        );
    }

    // Frameworks
    if (strpos($inputLower, 'mtco') !== false ||
        strpos($inputLower, 'measure twice') !== false) {
        return array(
            'domain'     => 'framework',
            'target'     => 'mtcoFramework',
            'confidence' => 0.85
        );
    }

    if (strpos($inputLower, 'lgbas') !== false ||
        strpos($inputLower, 'go back a step') !== false) {
        return array(
            'domain'     => 'framework',
            'target'     => 'lgbasProtocol',
            'confidence' => 0.85
        );
    }

    return $default;
}

// ================================================================
// 🧭 ROUTE INTENT WRAPPER
// Dispatches prompt to correct intent file based on semantic domain
// ================================================================
function routeIntent($prompt, $codexPath, $ssePath)
{
    $semantic = semanticRoute($prompt);
    $domain   = isset($semantic['domain']) ? $semantic['domain'] : 'general';
    $target   = isset($semantic['target']) ? $semantic['target'] : 'skyesoftConstitution';

    // ✅ Ensure proper absolute intent file path
    $intentFile = __DIR__ . '/intents/' . $domain . '.php';

    if (!file_exists($intentFile)) {
        return "⚠️ No intent file found for domain '{$domain}' (target: {$target}).";
    }

    require_once($intentFile);

    if (function_exists('handleIntent')) {
        return handleIntent($prompt, $codexPath, $ssePath);
    }

    return "⚠️ Intent file '{$domain}.php' found, but no handleIntent() defined.";
}