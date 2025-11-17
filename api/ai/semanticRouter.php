<?php
// 📘 File: api/ai/semanticRouter.php
// Version: v2.9 – Safe recursive normalization + robust intent dispatch (PHP 5.6 compatible)

// ================================================================
// 🔹 SAFETY NORMALIZATION LAYER
// Ensures router never fails when receiving non-string input
// ================================================================
function semanticRoute($input)
{
    // 🩹 Safely flatten arrays (recursively) before string conversion
    if (is_array($input)) {
        $flat = array();
        array_walk_recursive($input, function ($v) use (&$flat) {
            if (is_scalar($v) || is_null($v)) {
                $flat[] = $v;
            }
        });
        $input = implode(' ', $flat);
    }

    // ✅ Guarantee clean string
    $input = trim((string)$input);

    // 🧠 Default response (fallback domain)
    $default = array(
        'domain'     => 'general',
        'target'     => 'skyesoftConstitution',
        'confidence' => 0.5
    );

    // 🧩 Guard against empty input
    if ($input === '' || strlen($input) === 0) {
        return $default;
    }

    // ================================================================
    // 🧭 SEMANTIC ROUTING RULES
    // ================================================================
    $inputLower = strtolower($input);

    // Temporal domain – time, workday, hours, schedule
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

    // Contextual domain – weather, environment, forecast
    if (strpos($inputLower, 'weather') !== false ||
        strpos($inputLower, 'temperature') !== false ||
        strpos($inputLower, 'forecast') !== false) {
        return array(
            'domain'     => 'contextual',
            'target'     => 'weatherData',
            'confidence' => 0.9
        );
    }

    // Governance domain – constitution, codex, policy
    if (strpos($inputLower, 'constitution') !== false ||
        strpos($inputLower, 'codex') !== false ||
        strpos($inputLower, 'policy') !== false) {
        return array(
            'domain'     => 'governance',
            'target'     => 'skyesoftConstitution',
            'confidence' => 0.8
        );
    }

    // Framework domain – MTCO or LGBAS
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

    // Default fallback
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

    // ✅ Build absolute intent path
    $intentFile = __DIR__ . '/intents/' . $domain . '.php';

    if (!file_exists($intentFile)) {
        return "⚠️ No intent file found for domain '{$domain}' (target: {$target}).";
    }

    require_once($intentFile);

    // ✅ Only call handleIntent() if defined
    if (function_exists('handleIntent')) {
        // Each intent handles its own context paths safely
        return handleIntent($prompt, $codexPath, $ssePath);
    }

    return "⚠️ Intent file '{$domain}.php' found, but no handleIntent() defined.";
}