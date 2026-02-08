<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — sentinel-heartbeat.php (SGS v1 compliant — lifecycle-aware)
 *  Role: Sentinel Heartbeat
 *  Authority: Sentinel Governance Standard v1
 *  PHP: 8.3+
 *
 *  Responsibility:
 *   • Maintain ephemeral runtime heartbeat state
 *   • Enforce time-boxed deploy visibility policy
 *   • Act as server-authoritative arbiter for updateOccurred
 *
 *  Governance notes:
 *   • lastUpdateUnix is immutable historical truth
 *   • lastUpdateAgeSeconds is derived, never persisted
 *   • updateOccurred is policy-driven, not client-driven
 *
 *  Policy enforced:
 *   • updateOccurred remains true for 300 seconds after deploy
 *   • Signal decays deterministically based on server clock
 *
 *  Fixed in this version (Feb 2026):
 *   • Replaced edge-triggered clearing with time-window enforcement
 *   • Eliminated client-side or timer-based decay paths
 *   • Prevented unnecessary file writes via transition-only persistence
 *
 *  Still planned:
 *   • Optional exposure of update window remaining seconds
 *   • Sentinel policy registry for additional lifecycle rules
 *   • Test!
 * ===================================================================== */

$now = time();

#region 🫀 Runtime Sentinel State (ephemeral, lifecycle tracking only)
$statePath = __DIR__ . '/../data/runtimeEphemeral/sentinelState.json';

$state = file_exists($statePath)
    ? json_decode(file_get_contents($statePath), true)
    : [];

// Establish baseline on first run
if (!isset($state['initialRunUnix']) || (int)$state['initialRunUnix'] === 0) {
    $state['initialRunUnix'] = $now;
    $state['runCount'] = 0;
}

// Heartbeat update
$state['lastRunUnix'] = $now;
$state['runCount']    = (int)($state['runCount'] ?? 0) + 1;

// Derived runtime values (non-authoritative)
$state['uptimeSeconds'] = $now - $state['initialRunUnix'];
$state['ageSeconds']    = 0; // always 0 at write-time

// Persist runtime state
file_put_contents(
    $statePath,
    json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
#endregion

#region 🛡️ Update Occurred Enforcement (authoritative policy)

$versionsPath = __DIR__ . '/../data/authoritative/versions.json';

if (file_exists($versionsPath)) {

    $versions = json_decode(file_get_contents($versionsPath), true);

    // Canonical deploy timestamp (immutable)
    $lastUpdateUnix = (int)(
        $versions['system']['lastUpdateUnix'] ?? 0
    );

    if ($lastUpdateUnix > 0) {

        // Derived age (server clock only)
        $lastUpdateAgeSeconds = $now - $lastUpdateUnix;

        // Update visibility window (seconds)
        $updateWindowSeconds = 300;

        $shouldBeTrue = ($lastUpdateAgeSeconds <= $updateWindowSeconds);
        $isCurrently  = (bool)($versions['system']['updateOccurred'] ?? false);

        // Persist only on state transition
        if ($shouldBeTrue !== $isCurrently) {
            $versions['system']['updateOccurred'] = $shouldBeTrue;

            file_put_contents(
                $versionsPath,
                json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
    }
}
#endregion

#region 📤 Minimal Response (cron-safe / manual-safe)

echo json_encode([
    'status'      => 'ok',
    'runCount'    => $state['runCount'],
    'lastRunUnix' => $state['lastRunUnix']
]);
#endregion