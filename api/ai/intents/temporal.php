<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Generate temporal reasoning context (Codex + SSE integration)
// Version: v3.0 – Non-hardcoded, Codex-referential, PHP 5.6-safe

function handleIntent($prompt, $codexPath, $ssePath)
{
    date_default_timezone_set('America/Phoenix');

    // ✅ Pull live data directly from getDynamicData.php
    $endpoint = dirname(__DIR__, 3) . '/getDynamicData.php';
    $dynamicData = @file_get_contents($endpoint);

    if ($dynamicData === false || trim($dynamicData) === '') {
        return json_encode(array('error' => 'SSE dynamic data unavailable.'));
    }

    $sse = json_decode($dynamicData, true);
    if (!is_array($sse) || json_last_error() !== JSON_ERROR_NONE) {
        return json_encode(array('error' => 'Invalid SSE JSON.'));
    }

    // Extract essentials
    $timeData    = isset($sse['timeDateArray']) ? $sse['timeDateArray'] : array();
    $weatherData = isset($sse['weatherData'])   ? $sse['weatherData']   : array();
    $sunset      = isset($weatherData['sunset']) ? $weatherData['sunset'] : null;

    // 🧠 Codex context
    $codex = file_exists($codexPath) ? json_decode(file_get_contents($codexPath), true) : array();
    $timeModule = isset($codex['timeIntervalStandards']) ? $codex['timeIntervalStandards'] : array();

    // 🕐 Build runtime context
    $now = date("g:i A");
    $tz  = isset($timeData['timeZone']) ? $timeData['timeZone'] : 'America/Phoenix';
    $phase = isset($timeData['timeOfDayDescription']) ? $timeData['timeOfDayDescription'] : 'unknown';

    // 🧩 Compose unified JSON context (non-hardcoded)
    $context = array(
        'domain' => 'temporal',
        'codexNode' => 'timeIntervalStandards',
        'prompt' => $prompt,
        'data' => array(
            'definition' => isset($timeModule['purpose']['text']) ? $timeModule['purpose']['text'] : '',
            'runtime' => array(
                'now' => $now,
                'timezone' => $tz,
                'phase' => $phase,
                'sunset' => $sunset,
                'conditions' => isset($weatherData['description']) ? $weatherData['description'] : 'unknown'
            )
        ),
        'intent' => 'Derive temporal context using Codex + SSE dynamic data (no hardcoding).'
    );

    return json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}