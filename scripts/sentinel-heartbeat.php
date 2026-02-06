<?php
// scripts/sentinel-heartbeat.php
// Canonical Sentinel Writer (Lifecycle-Aware)

$statePath = __DIR__ . '/../data/runtimeEphemeral/sentinelState.json';
$now = time();

// Load existing state if present
$state = file_exists($statePath)
    ? json_decode(file_get_contents($statePath), true)
    : [];

// Establish prime run (baseline)
if (!isset($state['initialRunUnix']) || (int)$state['initialRunUnix'] === 0) {
    $state['initialRunUnix'] = $now;
    $state['runCount'] = 0; // reset baseline intentionally
}

// Heartbeat update
$state['lastRunUnix'] = $now;
$state['runCount']    = (int)($state['runCount'] ?? 0) + 1;

// Persist canonical state
file_put_contents(
    $statePath,
    json_encode($state, JSON_PRETTY_PRINT)
);

// Minimal response (manual / cron-safe)
echo json_encode([
    'status' => 'ok',
    'runCount' => $state['runCount']
]);