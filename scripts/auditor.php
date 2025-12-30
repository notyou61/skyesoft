<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — auditor.php (AGS v2 compliant — hardened & Reentrant)
 *  Role: Auditor
 *  Authority: Audit Governance Standard v2
 *  PHP: 8.3+
 *
 *  Purpose:
 *   • Observe Codex integrity via Merkle verification
 *   • Perform name conformance checks on canonical registries
 *   • Emit governed violation observations only
 *   • Track persistence of unresolved violations
 *   • Infer resolution by absence of observation in current run
 *
 *  Reentrancy:
 *   • All helper functions guarded with function_exists()
 *   • Safe to require multiple times (supports Sentinel 2-pass verification)
 *
 *  Explicitly Forbidden:
 *   • NO notification logic
 *   • NO batch assignment
 *   • NO email dispatch
 *   • NO mutation of resolution or notification fields
 * ===================================================================== */

#region SECTION 0 — Runtime Context

$root         = dirname(__DIR__);
$timestamp    = time();
$auditMode    = "governance";
$auditLogPath = $root . '/data/records/auditResults.json';

$isCli        = (PHP_SAPI === 'cli');
$isProduction = !$isCli;

// --- Auditor contract defaults (may be overridden by Sentinel) ---
if (!defined('SKYESOFT_LIB_MODE')) {
    define('SKYESOFT_LIB_MODE', false);
}

if (!defined('SKYESOFT_VERIFICATION_PASS')) {
    define('SKYESOFT_VERIFICATION_PASS', false);
}

// Send header only in standalone (non-library) mode
if (!defined('SKYESOFT_LIB_MODE') || !SKYESOFT_LIB_MODE) {
    header("Content-Type: application/json; charset=UTF-8");
}

// Verification pass detection (set by Sentinel on second audit)
$isVerificationPass = defined('SKYESOFT_VERIFICATION_PASS') && SKYESOFT_VERIFICATION_PASS;

if (!isset($auditBatch) || !is_string($auditBatch)) {
    throw new RuntimeException(
        'AUDITOR CONTRACT VIOLATION: auditBatch must be injected by Sentinel'
    );
}

#endregion SECTION 0

#region SECTION I — Path Resolution

$codexPath      = $root . '/codex/codex.json';
$merkleTreePath = $root . '/data/records/merkleTree.json';
$merkleRootPath = $root . '/data/records/merkleRoot.txt';

#endregion SECTION I

#region SECTION II — Helpers (Reentrant)

if (!function_exists('recursiveChunks')) {
    function recursiveChunks(mixed $node, string $path = ''): array {
        $chunks = [];

        if (!is_array($node)) {
            $chunks[$path] = hash('sha256', json_encode($node, JSON_UNESCAPED_SLASHES));
            return $chunks;
        }

        ksort($node);
        foreach ($node as $key => $value) {
            $fullPath = $path === '' ? (string)$key : "$path.$key";
            $chunks += recursiveChunks($value, $fullPath);
        }

        return $chunks;
    }
}

if (!function_exists('buildMerkle')) {
    function buildMerkle(array $leaves): string
    {
        $layer = array_values($leaves);

        if (empty($layer)) {
            return hash('sha256', '');
        }

        while (count($layer) > 1) {
            $nextLayer = [];

            for ($i = 0, $n = count($layer); $i < $n; $i += 2) {
                $left  = $layer[$i];
                $right = $layer[$i + 1] ?? $left;
                $nextLayer[] = hash('sha256', $left . $right);
            }

            $layer = $nextLayer;
        }

        return $layer[0];
    }
}

if (!function_exists('inferRuleId')) {
    function inferRuleId(string $observation): string {
        if (str_starts_with($observation, 'Merkle integrity violation:')) {
            return 'MERKLE_INTEGRITY';
        }
        if (str_starts_with($observation, 'Required governed file missing:')) {
            return 'REQUIRED_FILES';
        }
        if (str_starts_with($observation, 'Repository inventory violation:')) {
            return 'REPOSITORY_INVENTORY_CONFORMANCE';
        }
        return 'UNKNOWN';
    }
}

if (!function_exists('canonicalViolationHash')) {
    function canonicalViolationHash(
        string $ruleId,
        string $file,
        string $path,
        string $observation
    ): string {
        $normalizedObservation = strtolower(trim($observation));

        $identity = implode('|', [$ruleId, $file, $path, $normalizedObservation]);

        return hash('sha256', $identity);
    }
}

if (!function_exists('extractViolationLocation')) {
    function extractViolationLocation(string $observation): array {
        if (preg_match("/in '([^']+)' \\(path: ([^)]+)\\)/", $observation, $m)) {
            return [$m[1], $m[2]];
        }
        return ['unknown', 'unknown'];
    }
}

#endregion SECTION II

#region SECTION III — Load Audit Log

if (!file_exists($auditLogPath)) {
    file_put_contents($auditLogPath, json_encode([], JSON_PRETTY_PRINT));
}

$auditLog = json_decode(file_get_contents($auditLogPath), true) ?? [];

if (!function_exists('nextViolationId')) {
    function nextViolationId(array $log): string {
        $max = 0;
        foreach ($log as $r) {
            if (isset($r['violationId']) && preg_match('/VIO-(\d+)/', $r['violationId'], $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return sprintf('VIO-%03d', $max + 1);
    }
}

#endregion SECTION III

#region SECTION IV — Violation Collection
/*
 * SECTION IV PURPOSE
 * ------------------
 * This section is the ONLY place where the Auditor:
 *
 *   • Decides WHICH rules are evaluated in this run
 *   • Decides WHICH files are in audit scope
 *   • Collects raw, governed OBSERVATIONS (not resolutions)
 *
 * IMPORTANT:
 *   • No mutation occurs here
 *   • No notification occurs here
 *   • No reconciliation occurs here
 *   • Scope is intentionally conservative and pattern-based
 *
 * All observations emitted here are treated as factual snapshots
 * of the repository state at audit time.
 */

$violations = [];

#region SECTION IV.A — Rule Execution Ledger
/*
 * Tracks which rule families are evaluated during this run.
 * Used later to determine safe auto-resolution by non-observation.
 *
 * A rule NOT marked as evaluated MUST NOT resolve prior violations.
 */
$rulesEvaluated = [
    'REQUIRED_FILES'                  => false,
    'MERKLE_INTEGRITY'                => false,
    'REPOSITORY_INVENTORY_CONFORMANCE'=> false,
];
#endregion SECTION IV.A

#region SECTION IV.B — Required File Presence (Explicit Scope)
/*
 * Enforces the existence of core governed artifacts.
 *
 * Characteristics:
 *   • Explicit file list (no traversal)
 *   • Absolute governance requirement
 *   • Indicates repository corruption if violated
 */
$rulesEvaluated['REQUIRED_FILES'] = true;

$required = [
    'codex.json'      => $codexPath,
    'merkleTree.json' => $merkleTreePath,
    'merkleRoot.txt'  => $merkleRootPath,
];

foreach ($required as $label => $path) {
    if (!file_exists($path)) {
        $violations[] = "Required governed file missing: $label at $path";
    }
}
#endregion SECTION IV.B

#region SECTION IV.C — Merkle Integrity (Codex Only)
/*
 * Cryptographic integrity verification.
 *
 * Scope:
 *   • codex.json ONLY
 *   • No directory traversal
 *   • No registry participation
 */
$rulesEvaluated['MERKLE_INTEGRITY'] = true;

$codex      = json_decode(file_get_contents($codexPath), true);
$merkleTree = json_decode(file_get_contents($merkleTreePath), true);
$storedRoot = trim(file_get_contents($merkleRootPath));

$treeRoot = $merkleTree['root'] ?? null;

$observedLeaves = recursiveChunks($codex);
$observedRoot   = buildMerkle($observedLeaves);

if ($storedRoot !== $treeRoot || $observedRoot !== $storedRoot) {
    $violations[] =
        "Merkle integrity violation: observed Codex state does not match governed Merkle snapshot.";
}
#endregion SECTION IV.C

#region SECTION IV.D — Repository Inventory Conformance
/*
 * Verifies that the observed repository filesystem
 * exactly matches repositoryInventory.json.
 *
 * Bidirectional enforcement:
 *   • Declared → Observed (missing items)
 *   • Observed → Declared (unexpected items)
 *
 * Characteristics:
 *   • Structural only
 *   • Deterministic
 *   • No hashing
 *   • No mutation
 */

$rulesEvaluated['REPOSITORY_INVENTORY_CONFORMANCE'] = true;

$inventoryPath = $root . '/data/records/repositoryInventory.json';

if (!file_exists($inventoryPath)) {
    $violations[] =
        "Required governed file missing: repositoryInventory.json at {$inventoryPath}";
} else {

    $inventory = json_decode(file_get_contents($inventoryPath), true);

    if (!is_array($inventory) || !isset($inventory['paths'])) {
        $violations[] =
            "Repository inventory violation: repositoryInventory.json is malformed or missing paths map.";
    } else {

        $declaredPaths = $inventory['paths'];
        $observedPaths = [];

        // ---- Scan filesystem ----
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            $fullPath = $fileInfo->getPathname();

            // Normalize to repo-relative path
            $relativePath = ltrim(str_replace($root, '', $fullPath), DIRECTORY_SEPARATOR);

            // Excluded directories (governed non-repository state)
            foreach (['.git','node_modules','vendor','runtimeEphemeral','records','derived'] as $dir) {
                if (str_contains($relativePath, $dir . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }

            $observedPaths[$relativePath] = $fileInfo->isDir() ? 'dir' : 'file';
        }

        // ---- Declared → Observed (missing) ----
        foreach ($declaredPaths as $path => $meta) {
            $expectedType = $meta['type'] ?? null;

            if (!isset($observedPaths[$path])) {
                $violations[] =
                    "Repository inventory violation: declared {$expectedType} '{$path}' is missing from repository.";
                continue;
            }

            if ($expectedType && $observedPaths[$path] !== $expectedType) {
                $violations[] =
                    "Repository inventory violation: '{$path}' expected type '{$expectedType}' but found '{$observedPaths[$path]}'.";
            }
        }

        // ---- Observed → Declared (unexpected) ----
        foreach ($observedPaths as $path => $actualType) {
            if (!isset($declaredPaths[$path])) {
                $violations[] =
                    "Repository inventory violation: unexpected {$actualType} '{$path}' exists but is not declared.";
            }
        }
    }
}
#endregion SECTION IV.D

#endregion SECTION IV

#region SECTION V — Process Violations & Update Persistence (Rule-Aware)

$violations = array_values(array_unique($violations));
$emitted = [];
$updated = false;

$currentIdentities = [];

foreach ($violations as $obs) {
    $ruleId = inferRuleId($obs);
    [$file, $path] = extractViolationLocation($obs);
    $hash = canonicalViolationHash($ruleId, $file, $path, $obs);
    $currentIdentities[$hash] = true;
}

foreach ($violations as $obs) {
    $ruleId = inferRuleId($obs);
    [$file, $path] = extractViolationLocation($obs);
    $hash = canonicalViolationHash($ruleId, $file, $path, $obs);

    $found = false;
    foreach ($auditLog as &$record) {
        if (($record['resolved'] ?? null) !== null) {
            continue;
        }

        if (
            ($record['type'] ?? null) === 'violation' &&
            ($record['identityHash'] ?? null) === $hash
        ) {
            $record['lastObserved'] = $timestamp;

            // Do NOT increment observationCount during verification pass
            if (!$isVerificationPass) {
                $record['observationCount'] = ($record['observationCount'] ?? 0) + 1;
            }

            $found = true;
            $updated = true;
            $emitted[] = $record;
            break;
        }
    }
    unset($record);

    if (!$found) {
        $newRecord = [
            'type'              => 'violation',
            'violationId'       => nextViolationId($auditLog),
            'identityHash'      => $hash,
            'ruleId'            => $ruleId,
            'timestamp'         => $timestamp,
            'auditMode'         => $auditMode,
            'observation'       => $obs,
            'notificationSent'  => null,
            'auditBatch'        => $auditBatch,
            'resolved'          => null,
            'resolution'        => null,
            'lastObserved'      => $timestamp,
            'observationCount'  => 1
        ];

        $auditLog[] = $newRecord;
        $emitted[] = $newRecord;
        $updated = true;
    }
}

foreach ($auditLog as &$record) {
    if (
        ($record['type'] ?? null) !== 'violation' ||
        ($record['resolved'] ?? null) !== null ||
        ($record['auditMode'] ?? null) !== $auditMode
    ) {
        continue;
    }

    $ruleId = $record['ruleId'] ?? null;

    if (!$ruleId || !($rulesEvaluated[$ruleId] ?? false)) {
        continue;
    }

    if (!isset($currentIdentities[$record['identityHash'] ?? ''])) {
        $record['resolved'] = $timestamp;
        $updated = true;
    }
}
unset($record);

if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

$mutatableCount = 0;

// Explicit completion summary for Sentinel
$summary = [
    'runComplete'    => true,
    'timestamp'      => $timestamp,
    'auditMode'      => $auditMode,
    'emittedCount'   => count($emitted),
    'mutatableCount' => 0, // Auditor is non-mutating by design
];

// Library mode: output emitted violations and return contract
if (defined('SKYESOFT_LIB_MODE') && SKYESOFT_LIB_MODE) {
    echo json_encode($emitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $summary;
}

// Standalone/CLI mode
echo json_encode($emitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit(0);

#endregion SECTION V