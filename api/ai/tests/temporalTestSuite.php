<?php
// 📄 File: api/ai/tests/temporalTestSuite.php
// Purpose: Structured stress-test for temporal.php logic
// Version: v1.0 – Codex-aligned validation harness
// Usage: php api/ai/tests/temporalTestSuite.php

require_once __DIR__ . '/../intents/temporal.php';

$codexPath = __DIR__ . '/../../../codex.json';
$ssePath   = 'https://www.skyelighting.com/skyesoft/api/getDynamicData.php';

// 🧠 20 test prompts (coverage: day types, rollovers, delta logic, resilience)
$tests = array(
    'Is today a workday?',
    'Is tomorrow a workday?',
    'When does the next workday start?',
    'How long until the workday begins?',
    'How long until sunset?',
    'When does the shop start work tomorrow?',
    'Is Sunday a holiday or a weekend?',
    'What is the next holiday?',
    'How long ago did the workday start?',
    'When does the workday finish today?',
    'Time until tomorrow’s workday starts',
    'How long till the next sunrise/sunset?',
    'When is the next business day after Sunday?',
    'Is today a holiday?',
    'Time until shop closes',
    'Tell me the phase of the moon',
    'Time since last workday ended',
    'When does the next work period start after Thanksgiving?',
    'How long till after worktime?',
    'When is the next federal holiday?'
);

// 🧾 Output formatting helper
function printResult($prompt, $result)
{
    echo "───────────────────────────────\n";
    echo "🧭 Prompt: {$prompt}\n";
    if (!is_array($result)) {
        echo "⚠️ Invalid result: " . print_r($result, true) . "\n";
        return;
    }

    $runtime = isset($result['data']['runtime']) ? $result['data']['runtime'] : [];
    $flags   = isset($result['flags']) ? $result['flags'] : [];

    echo "• Event: " . ($result['event'] ?? 'unknown') . "\n";
    echo "• Status: " . ($result['status'] ?? 'n/a') . "\n";
    echo "• Workday? " . (isset($result['isWorkdayToday']) ? ($result['isWorkdayToday'] ? '✅ Yes' : '❌ No') : 'Unknown') . "\n";
    echo "• Target: " . ($runtime['targetDate'] ?? 'n/a') . " " . ($runtime['targetTime'] ?? '') . "\n";
    echo "• Delta: " . ($runtime['delta']['hours'] ?? '?') . "h " . ($runtime['delta']['minutes'] ?? '?') . "m " . ($runtime['delta']['direction'] ?? '') . "\n";
    echo "• Rollover: " . ((isset($flags['rollover']) && $flags['rollover']) ? 'Yes' : 'No') . "\n";
    echo "• Post-Start: " . ((isset($flags['postStart']) && $flags['postStart']) ? 'Yes' : 'No') . "\n";
    echo "───────────────────────────────\n\n";
}

// 🚀 Execute test suite
echo "=== SKYEBOT TEMPORAL TEST SUITE (v1.0) ===\n";
echo "Date: " . date('F j, Y g:i A') . " | Timezone: America/Phoenix\n\n";

foreach ($tests as $prompt) {
    try {
        $result = handleIntent($prompt, $codexPath, $ssePath);
        printResult($prompt, $result);
        usleep(200000); // slight delay for readability
    } catch (Exception $e) {
        echo "❌ Exception on '{$prompt}': " . $e->getMessage() . "\n";
    }
}

echo "\n✅ Test suite completed.\n";
