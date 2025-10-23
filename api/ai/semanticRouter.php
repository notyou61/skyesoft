<?php
// 📘 File: api/ai/semanticRouter.php
// Purpose: Directs user input to appropriate Codex domains and targets
// Version: v2.3 (Array-safe normalization, 2025-10-23)

// ================================================================
// 🔹 SAFETY NORMALIZATION LAYER
// Ensures that the router never fails when receiving non-string input
// ================================================================
function semanticRoute($input)
{
    // 🩹 Normalize input to string
    if (is_array($input)) {
        $input = implode(' ', $input);
    }
    $input = trim((string)$input);

    // 🧠 Default response (fallback domain)
    $default = array(
        'domain'     => 'general',
        'target'     => 'skyesoftConstitution',
        'confidence' => 0.5
    );

    // 🧩 Guard against empty input
    if ($input === '') {
        return $default;
    }

    // ================================================================
    // 🧭 SEMANTIC ROUTING RULES
    // ================================================================
    $inputLower = strtolower($input);

    // Temporal domain – time, workday, hours
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

    // Contextual domain – weather, environment, atmosphere
    if (strpos($inputLower, 'weather') !== false ||
        strpos($inputLower, 'temperature') !== false ||
        strpos($inputLower, 'forecast') !== false) {
        return array(
            'domain'     => 'contextual',
            'target'     => 'weatherData',
            'confidence' => 0.9
        );
    }

    // Organizational domain – constitution, codex, governance
    if (strpos($inputLower, 'constitution') !== false ||
        strpos($inputLower, 'codex') !== false ||
        strpos($inputLower, 'policy') !== false) {
        return array(
            'domain'     => 'governance',
            'target'     => 'skyesoftConstitution',
            'confidence' => 0.8
        );
    }

    // Mission / MTCO references
    if (strpos($inputLower, 'mtco') !== false ||
        strpos($inputLower, 'measure twice') !== false) {
        return array(
            'domain'     => 'framework',
            'target'     => 'mtcoFramework',
            'confidence' => 0.85
        );
    }

    // LGBAS references
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