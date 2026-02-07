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
    $state['runCount'] = 0; // intentional reset
}

// Heartbeat update
$state['lastRunUnix'] = $now;
$state['runCount']    = (int)($state['runCount'] ?? 0) + 1;

// Derived (runtime-only, NOT persisted elsewhere)
$state['uptimeSeconds'] = $now - $state['initialRunUnix'];
$state['ageSeconds']    = 0; // always 0 at write-time

// Persist canonical runtime state
file_put_contents(
    $statePath,
    json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// Minimal response (cron / manual safe)
echo json_encode([
    'status'      => 'ok',
    'runCount'    => $state['runCount'],
    'lastRunUnix' => $state['lastRunUnix']
]);