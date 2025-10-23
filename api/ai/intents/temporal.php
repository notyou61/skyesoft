<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Generate temporal reasoning context (Codex + SSE integration)
// Version: v3.0 – Non-hardcoded, Codex-referential, PHP 5.6-safe

function handleIntent($prompt, $codexPath, $ssePath)
{
    // ------------------------------------------------------------
    // 1️⃣  Load Codex + SSE
    // ------------------------------------------------------------
    $codex = json_decode(@file_get_contents($codexPath), true);
    $sse   = json_decode(@file_get_contents($ssePath), true);

    if (!is_array($codex) || !is_array($sse)) {
        return json_encode(array(
            'error' => 'Codex or SSE data unavailable.'
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------
    // 2️⃣  Locate the temporal-standard node dynamically
    // ------------------------------------------------------------
    $tis = null;
    if (isset($codex['modules'])) {
        foreach ($codex['modules'] as $key => $module) {
            if (isset($module['type']) && $module['type'] === 'temporal-standard') {
                $tis = $module;
                $tis['key'] = $key;
                break;
            }
        }
    }

    if (!$tis) {
        return json_encode(array(
            'error' => 'Temporal-standard node not found in Codex.'
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------
    // 3️⃣  Build runtime snapshot from SSE
    // ------------------------------------------------------------
    $runtime = array(
        'now'        => isset($sse['timeDateArray']['now']) ? $sse['timeDateArray']['now'] : null,
        'timezone'   => isset($sse['timeDateArray']['timezone']) ? $sse['timeDateArray']['timezone'] : 'America/Phoenix',
        'dayType'    => isset($sse['timeDateArray']['dayType']) ? $sse['timeDateArray']['dayType'] : null,
        'phase'      => isset($sse['timeDateArray']['phase']) ? $sse['timeDateArray']['phase'] : null,
        'sunrise'    => isset($sse['weatherData']['sunrise']) ? $sse['weatherData']['sunrise'] : null,
        'sunset'     => isset($sse['weatherData']['sunset']) ? $sse['weatherData']['sunset'] : null,
        'conditions' => isset($sse['weatherData']['conditions']) ? $sse['weatherData']['conditions'] : null
    );

    // ------------------------------------------------------------
    // 4️⃣  Compose AI reasoning payload
    // ------------------------------------------------------------
    $context = array(
        'domain'    => 'temporal',
        'codexNode' => isset($tis['key']) ? $tis['key'] : 'timeIntervalStandards',
        'prompt'    => $prompt,
        'data'      => array(
            'definition' => $tis['purpose']['text'],
            'runtime'    => $runtime
        ),
        'intent'    => 'Derive temporal or celestial awareness using Codex + SSE context, not hardcoded logic.'
    );

    // ------------------------------------------------------------
    // 5️⃣  Return pure JSON for AI reasoning
    // ------------------------------------------------------------
    return json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}