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
 *   • Write resolution metadata to canonical audit ledger
 *
 *  Governance Notes:
 *   • Trigger: Sentinel-orchestrated (automatic when mutatable violations exist)
 *   • Scope: ONLY NAMING_CONFORMANCE violations in codex.json
 *   • Deterministic, idempotent, purely structural corrections only
 *   • No semantic inference, no dictionary, no language dependency
 *   • No detection logic
 *   • No notification logic
 *   • Authority delegated by Sentinel via contractual audit completion
 * ===================================================================== */

#region SECTION 0 — Environment Setup

$rootDir      = dirname(__DIR__);
$dataDir      = $rootDir . '/data/records';
$codexDir     = $rootDir . '/codex';

$auditLogPath = $dataDir . '/auditResults.json';

#endregion SECTION 0

#region SECTION I — Helpers

/**
 * Convert an arbitrary key to proper camelCase using pure structural tokenization
 *
 * Rules:
 *   • Remove all non-alphanumeric characters
 *   • Split on runs of letters vs digits
 *   • Preserve digit sequences exactly
 *   • First token lowercase, subsequent tokens capitalized
 *   • Fully deterministic, idempotent, language-independent
 */
if (!function_exists('isCamelCase')) {
    function isCamelCase(string $key): bool
    {
        return preg_match('/^[a-z][a-zA-Z0-9]*$/', $key) === 1;
    }
}

if (!function_exists('toCamelCase')) {
    function toCamelCase(string $key): string
    {
        // If already valid camelCase, preserve exactly
        if (isCamelCase($key)) {
            return $key;
        }

        // Normalize separators to spaces
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $key);
        $normalized = trim($normalized);

        if ($normalized === '') {
            return $key;
        }

        // Tokenize
        $parts = preg_split('/\s+/', $normalized);
        $result = '';

        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }

            if ($i === 0) {
                $result .= strtolower($part);
            } else {
                $result .= ucfirst(strtolower($part));
            }
        }

        return $result;
    }
}

/**
 * Rename a JSON object key at a dotted path
 * Returns true on successful mutation
 */
function renameJsonKeyAtPath(
    string $filePath,
    string $fullPath,
    string $oldKey,
    string $newKey,
    ?int &$codeLine = null
): bool {
    $json = json_decode(file_get_contents($filePath), true);
    if (!is_array($json)) {
        return false;
    }

    $segments = explode('.', $fullPath);
    if (count($segments) < 2) {
        return false;
    }

    // Navigate to parent
    array_pop($segments);
    $ref = &$json;

    foreach ($segments as $seg) {
        if (!is_array($ref) || !isset($ref[$seg])) {
            return false;
        }
        $ref = &$ref[$seg];
    }

    if (!is_array($ref) || !isset($ref[$oldKey]) || $oldKey === $newKey) {
        return false;
    }

    /* GOVERNED MUTATION LINE */
    $codeLine = __LINE__ + 1;
    $ref[$newKey] = $ref[$oldKey];
    unset($ref[$oldKey]);

    $success = file_put_contents(
        $filePath,
        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;

    return $success;
}

/**
 * Write resolution metadata by violationId
 */
function writeResolutionByViolationId(
    string $auditLogPath,
    string $violationId,
    array $resolution
): bool {
    $log = json_decode(file_get_contents($auditLogPath), true);
    if (!is_array($log)) {
        return false;
    }

    $updated = false;
    foreach ($log as &$rec) {
        if (
            ($rec['type'] ?? null) !== 'violation' ||
            ($rec['violationId'] ?? null) !== $violationId ||
            ($rec['resolved'] ?? null) !== null
        ) {
            continue;
        }

        $rec['resolved']   = time();
        $rec['resolution'] = $resolution;
        $updated = true;
        break;
    }
    unset($rec);

    if (!$updated) {
        return false;
    }

    return file_put_contents(
        $auditLogPath,
        json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

#endregion SECTION I

#region SECTION II — Load Canonical Registry

if (!file_exists($auditLogPath)) {
    exit(1);
}

$log = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($log)) {
    exit(1);
}

#endregion SECTION II

#region SECTION III — Mutator Execution (NAMING_CONFORMANCE | iconMap.json ONLY)

$mutatedCount = 0;

/*
 * BETA SAFETY CONTRACT
 * --------------------
 * • Target file: iconMap.json only
 * • Scope: snake_case → camelCase key corrections
 * • Never mutate numeric keys
 * • Deterministic + idempotent
 * • No Codex / Standards mutation
 */

foreach ($log as $v) {

    // --------------------------------------------------
    // Basic eligibility filters
    // --------------------------------------------------
    if (
        ($v['type'] ?? null) !== 'violation' ||
        ($v['resolved'] ?? null) !== null ||
        ($v['ruleId'] ?? null) !== 'NAMING_CONFORMANCE'
    ) {
        continue;
    }

    // --------------------------------------------------
    // Parse observation
    // --------------------------------------------------
    if (
        !preg_match(
            "/key '([^']+)' in '([^']+)' \\(path: ([^)]+)\\)/",
            (string) ($v['observation'] ?? ''),
            $m
        )
    ) {
        continue;
    }

    [$badKey, $file, $path] = [$m[1], $m[2], $m[3]];

    // --------------------------------------------------
    // Strict scope enforcement
    // --------------------------------------------------
    if ($file !== 'iconMap.json') {
        continue;
    }

    // Never mutate numeric keys
    if (ctype_digit($badKey)) {
        continue;
    }

    $filePath = $rootDir . '/data/authoritative/' . $file;
    if (!file_exists($filePath)) {
        continue;
    }

    // --------------------------------------------------
    // Compute deterministic fix
    // --------------------------------------------------
    $fixedKey = toCamelCase($badKey);

    // No-op guard
    if ($badKey === $fixedKey) {
        continue;
    }

    // Target must be valid camelCase
    if (!isCamelCase($fixedKey)) {
        continue;
    }

    // --------------------------------------------------
    // Apply governed mutation
    // --------------------------------------------------
    $codeLine = null;

    if (renameJsonKeyAtPath($filePath, $path, $badKey, $fixedKey, $codeLine)) {
        $mutatedCount++;

        writeResolutionByViolationId(
            $auditLogPath,
            (string) $v['violationId'],
            [
                'actor'    => 'mutator',
                'ruleId'   => 'NAMING_CONFORMANCE',
                'action'   => 'renameKey',
                'before'   => $badKey,
                'after'    => $fixedKey,
                'file'     => $file,
                'path'     => $path,
                'codeLine' => $codeLine,
                'beta'     => 'iconMap'
            ]
        );
    }
}

#endregion SECTION III