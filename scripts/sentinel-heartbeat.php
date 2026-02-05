<?php
// scripts/sentinel-heartbeat.php

$statePath = __DIR__ . '/../data/runtimeEphemeral/sentinelState.json';

$state = [
    'lastRunUnix' => time(),
    'runCount'    => 0
];

if (file_exists($statePath)) {
    $existing = json_decode(file_get_contents($statePath), true);
    if (is_array($existing)) {
        $state['runCount'] = (int)($existing['runCount'] ?? 0);
    }
}

$state['runCount']++;

file_put_contents(
    $statePath,
    json_encode($state, JSON_PRETTY_PRINT)
);

// Optional tiny response for manual testing
echo json_encode(['status' => 'ok']);
