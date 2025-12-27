<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — mutator.php
 *  Role: Mutator (Auto-Fix Bounded Violations)
 *  Authority: Reconciliation Governance Standard (RGS)
 *  PHP: 8.3+
 *
 *  Purpose:
 *   • Auto-fix low-risk violations (naming conformance only)
 *   • Write resolution narrative to canonical registry
 *   • Trigger Sentinel re-audit for verification
 *
 *  Governance Notes:
 *   • Trigger: Human-only (manual or approved cron)
 *   • Scope: ONLY NAMING_CONFORMANCE
 *   • No persistence — updates resolution field only
 *   • No notification — Sentinel handles
 * ===================================================================== */

#region SECTION 0 — Environment Setup

$rootDir    = dirname(__DIR__);
$dataDir    = $rootDir . '/data/records';
$scriptsDir = $rootDir . '/scripts';

$auditLogPath = $dataDir . '/auditResults.json';
$sentinelPath = $scriptsDir . '/sentinel.php';

#endregion SECTION 0 — Environment Setup

#region SECTION I — Helpers

/*
 * Helper: Convert key to camelCase
 * Examples:
 *   "MERKLE_EXCLUDED"          → "merkleExcluded"
 *   "step2_integrityCommit"    → "step2IntegrityCommit"
 *   "non-deterministic"        → "nonDeterministic"
 */
if (!function_exists('toCamelCase')) {
    // To camelCase FunctionS
    function toCamelCase(string $key): string {

        // Already camelCase
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $key)) {
            return lcfirst($key);
        }

        // Normalize separators
        $norm  = preg_replace('/[^a-zA-Z0-9]+/', ' ', $key);
        $parts = preg_split('/\s+/', trim($norm)) ?: [];

        if (!$parts) {
            return $key;
        }

        $first = strtolower(array_shift($parts));
        $rest  = array_map(
            fn($p) => ucfirst(strtolower($p)),
            $parts
        );

        return $first . implode('', $rest);
    }
}

/*
 * Helper: Rename key in JSON at dotted path
 */
function renameJsonKeyAtPath(string $filePath, string $fullPath, string $oldKey, string $newKey): bool {
    $json = json_decode(file_get_contents($filePath), true);
    if (!is_array($json)) {
        return false;
    }

    $segments = explode('.', $fullPath);
    if (count($segments) < 2) {
        return false;
    }

    array_pop($segments);
    $ref = &$json;

    foreach ($segments as $seg) {
        if (!is_array($ref) || !isset($ref[$seg])) {
            return false;
        }
        $ref = &$ref[$seg];
    }

    if (!is_array($ref) || !isset($ref[$oldKey])) {
        return false;
    }
    if ($oldKey === $newKey) {
        return false;
    }

    $ref[$newKey] = $ref[$oldKey];
    unset($ref[$oldKey]);

    file_put_contents(
        $filePath,
        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    return true;
}

/*
 * Helper: Write resolution narrative to violation by ID
 */
function writeResolutionByViolationId(string $auditLogPath, string $violationId, string $narrative): bool {
    $log = json_decode(file_get_contents($auditLogPath), true);
    if (!is_array($log)) {
        return false;
    }

    $ts      = time();
    $updated = false;

    foreach ($log as &$rec) {
        if (
            ($rec['type'] ?? null) !== 'violation' ||
            ($rec['violationId'] ?? null) !== $violationId
        ) {
            continue;
        }

        if (!empty($rec['resolution'])) {
            continue; // idempotent
        }

        $rec['resolution'] = [
            'timestamp' => $ts,
            'actor'     => 'mutator',
            'method'    => 'mutator.namingConformance',
            'note'      => $narrative
        ];

        $updated = true;
        break;
    }
    unset($rec);

    if ($updated) {
        file_put_contents(
            $auditLogPath,
            json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    return $updated;
}

#endregion SECTION I — Helpers

#region SECTION II — Load Canonical Registry

$log = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($log)) {
    exit(1);
}

#endregion SECTION II — Load Canonical Registry

#region SECTION III — Mutator Execution (NAMING_CONFORMANCE Only)

$mutatableRules = ['NAMING_CONFORMANCE']; // Explicit allowlist — prevents scope creep
$fixed          = 0;

foreach ($log as $v) {
    if (
        ($v['type'] ?? null) !== 'violation' ||
        ($v['resolved'] ?? null) !== null ||
        !in_array($v['ruleId'] ?? null, $mutatableRules, true)
    ) {
        continue;
    }

    $obs = (string)($v['observation'] ?? '');
    $vid = (string)($v['violationId'] ?? '');

    // NOTE: Observation string format is governed by AGS.
    // Mutator is explicitly coupled to NAMING_CONFORMANCE phrasing.
    if (!preg_match("/key '([^']+)' in '([^']+)' \\(path: ([^)]+)\\)/", $obs, $m)) {
        continue;
    }

    $badKey  = $m[1];
    $file    = $m[2];
    $path    = $m[3];

    // Scope: only codex.json
    if ($file !== 'codex.json') {
        continue;
    }

    $filePath = $rootDir . '/codex/' . $file;
    if (!file_exists($filePath)) {
        continue;
    }

    $fixedKey = toCamelCase($badKey);

    if (renameJsonKeyAtPath($filePath, $path, $badKey, $fixedKey)) {
        $narrative = "Auto-fixed naming: '{$badKey}' → '{$fixedKey}' in {$file} at path {$path}";
        if (writeResolutionByViolationId($auditLogPath, $vid, $narrative)) {
            $fixed++;
        }
    }
}

#endregion SECTION III — Mutator Execution

#region SECTION IV — Trigger Verification (If Fixes Applied)

if ($fixed > 0) {
    require $sentinelPath;
}

#endregion SECTION IV — Trigger Verification

#region SECTION V — Final Exit

exit(0);

#endregion SECTION V — Final Exit