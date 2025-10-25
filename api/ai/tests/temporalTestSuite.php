<?php
// === SKYEBOT TEMPORAL TEST SUITE (v1.3 – GoDaddy Diagnostic) ===
// PHP 5.6-compatible version with timezone validation

// Force Phoenix timezone first
date_default_timezone_set('America/Phoenix');

// 🧭 Server Time Diagnostics
echo "Server Time Debug:\n";
echo "• PHP version: " . PHP_VERSION . "\n";
echo "• Default timezone: " . date_default_timezone_get() . "\n";
echo "• Current time (system): " . date('Y-m-d H:i:s T') . "\n";
echo "• UTC time (baseline): " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

// Header
echo "=== SKYEBOT TEMPORAL TEST SUITE (v1.3) ===\n";
echo "Date: " . date('F j, Y g:i A') . " | Timezone: " . date_default_timezone_get() . "\n\n";

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

    $runtime = isset($result['data']['runtime']) ? $result['data']['runtime'] : array();
    $flags   = isset($result['flags']) ? $result['flags'] : array();

    echo "• Event: " . (isset($result['event']) ? $result['event'] : 'unknown') . "\n";
    echo "• Status: " . (isset($result['status']) ? $result['status'] : 'n/a') . "\n";

    // 🩹 Fixed: Read from dayInfo instead of missing top-level isWorkdayToday
    $dayTypeLabel = 'Unknown';
    if (isset($result['dayInfo']['dayType'])) {
        $dayTypeLabel = $result['dayInfo']['dayType'];
    } elseif (isset($result['dayInfo']['isWorkday'])) {
        $dayTypeLabel = $result['dayInfo']['isWorkday'] ? 'Workday' : 'Weekend';
    }
    echo "• Workday? " . ($dayTypeLabel === 'Workday' ? '✅ Yes (' . $dayTypeLabel . ')' : '❌ No (' . $dayTypeLabel . ')') . "\n";

    $targetDate = isset($runtime['targetDate']) ? $runtime['targetDate'] : 'n/a';
    $targetTime = isset($runtime['targetTime']) ? $runtime['targetTime'] : '';
    echo "• Target: {$targetDate} {$targetTime}\n";

    $delta = isset($runtime['delta']) ? $runtime['delta'] : array();
    $deltaHours = isset($delta['hours']) ? $delta['hours'] : '?';
    $deltaMins  = isset($delta['minutes']) ? $delta['minutes'] : '?';
    $deltaDir   = isset($delta['direction']) ? $delta['direction'] : '';
    echo "• Delta: {$deltaHours}h {$deltaMins}m {$deltaDir}\n";

    $rollover = (isset($flags['rollover']) && $flags['rollover']) ? 'Yes' : 'No';
    $postStart = (isset($flags['postStart']) && $flags['postStart']) ? 'Yes' : 'No';
    echo "• Rollover: {$rollover}\n";
    echo "• Post-Start: {$postStart}\n";
    echo "───────────────────────────────\n\n";
}

// 🚀 Execute test suite
echo "=== SKYEBOT TEMPORAL TEST SUITE (v1.3) ===\n";
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