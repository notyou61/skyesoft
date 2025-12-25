<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — auditor.php (AGS v2 compliant — hardened)
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
 *  Explicitly Forbidden:
 *   • NO notification logic
 *   • NO batch assignment
 *   • NO email dispatch
 *   • NO mutation of resolution or notification fields
 * ===================================================================== */

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Runtime Context

$root         = dirname(__DIR__);
$timestamp    = time();
$auditMode    = "governance";
$auditLogPath = $root . '/data/records/auditResults.json';

$isCli        = (PHP_SAPI === 'cli');
$isProduction = !$isCli;

#endregion SECTION 0

#region SECTION I — Path Resolution

$codexPath      = $root . '/codex/codex.json';
$merkleTreePath = $root . '/data/records/merkleTree.json';
$merkleRootPath = $root . '/data/records/merkleRoot.txt';

#endregion SECTION I

#region SECTION II — Helpers

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

function buildMerkle(array $leaves): string {
    $layer = array_values($leaves);

    while (count($layer) > 1) {
        $next = [];
        for ($i = 0; $i < count($layer); $i += 2) {
            $left  = $layer[$i];
            $right = $layer[$i + 1] ?? $layer[$i];
            $next[] = hash('sha256', $left . $right);
        }
        $layer = $next;
    }

    return $layer[0] ?? hash('sha256', '');
}

/**
 * Recursively audit semantic JSON keys for camelCase compliance.
 * Enforcement is strict ONLY within governed semantic scopes for codex.json.
 * Canonical registries receive full strict enforcement.
 */
function auditCamelCaseKeys(
    mixed $node,
    string $file,
    string $currentPath,      // accumulated dot-path, e.g. "violationClasses.enums"
    array &$violations,
    bool $fullEnforcement = false
): void {
    if (!is_array($node)) {
        return;
    }

    // Governed top-level sections in codex.json where camelCase is strictly required
    $governedSemanticRoots = [
        'namingConvention',
        'semanticRoles',
        'violationModel',
        'auditModes',
        'violationClasses',
        'actorModel',
        'reconciliationModel',
        'standards',
        'standardsIndex',
        'tier2Standards',
        'codex',
        // Future semantic doctrine sections added here deliberately
    ];

    // Determine if we are inside a governed scope (only matters when not full enforcement)
    $isInGovernedScope = $fullEnforcement || (
        $currentPath !== '' &&
        in_array(explode('.', $currentPath)[0], $governedSemanticRoots, true)
    );

    foreach ($node as $key => $value) {
        $childPath = $currentPath === '' ? (string)$key : "$currentPath.$key";

        // Key naming enforcement
        if (
            is_string($key) &&
            !ctype_digit($key) &&
            !preg_match('/^[a-z][a-zA-Z0-9]*$/', $key) &&
            ($fullEnforcement || $isInGovernedScope)
        ) {
            $violations[] = "Name conformance violation: key '{$key}' in '{$file}' (path: {$childPath}) is not camelCase.";
        }

        // Semantic 'file' field enforcement — always global
        if ($key === 'file' && is_string($value)) {
            $basename = pathinfo($value, PATHINFO_FILENAME);
            if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $basename)) {
                $violations[] = "Name conformance violation: file '{$value}' in '{$file}' must use camelCase basename.";
            }
        }

        // Recurse — preserve accumulated path
        auditCamelCaseKeys($value, $file, $childPath, $violations, $fullEnforcement);
    }
}

#endregion SECTION II

#region SECTION III — Load Audit Log

if (!file_exists($auditLogPath)) {
    file_put_contents($auditLogPath, json_encode([], JSON_PRETTY_PRINT));
}

$auditLog = json_decode(file_get_contents($auditLogPath), true) ?? [];

function nextViolationId(array $log): string {
    $max = 0;
    foreach ($log as $r) {
        if (isset($r['violationId']) && preg_match('/VIO-(\d+)/', $r['violationId'], $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return sprintf('VIO-%03d', $max + 1);
}

#endregion SECTION III

#region SECTION IV — Violation Collection

$violations = [];

// 1. Required file presence
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

// 2. Name conformance checks
if (empty($violations)) {
    $registryRoot = $root;

    $excludedDirs = [
        '.git',
        'node_modules',
        'vendor',
        'runtimeEphemeral',
        'records',
        'derived'
    ];

    $excludedFiles = [
        'auditResults.json',
        'audit-report.json',
        'repositoryInventory.json',
        'merkleTree.json',
        'merkleRoot.txt'
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($registryRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $file = $fileInfo->getFilename();
        $path = $fileInfo->getPathname();

        foreach ($excludedDirs as $dir) {
            if (str_contains($path, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR)) {
                continue 2;
            }
        }

        if (in_array($file, $excludedFiles, true)) {
            continue;
        }

        $isCanonicalRegistry = preg_match('/(Registry|Map|Index)\.json$/', $file);
        $isCodex             = ($file === 'codex.json');

        if (!$isCanonicalRegistry && !$isCodex) {
            continue;
        }

        // Filename enforcement for canonical registries only
        if ($isCanonicalRegistry) {
            if (!preg_match('/^[a-z][a-zA-Z0-9]*(Registry|Map|Index)\.json$/', $file)) {
                $violations[] = "Name conformance violation: canonical registry filename '{$file}' must use camelCase.";
                continue;
            }
        }

        $json = json_decode(file_get_contents($path), true);
        if (!is_array($json)) {
            continue;
        }

        // Apply appropriate enforcement mode
        if ($isCodex) {
            // Scoped enforcement for codex.json
            auditCamelCaseKeys($json, $file, '', $violations, false);
        } else {
            // Full strict enforcement for all canonical registries
            auditCamelCaseKeys($json, $file, '', $violations, true);
        }
    }
}

// 3. Merkle integrity checks
if (empty($violations)) {
    $codex      = json_decode(file_get_contents($codexPath), true);
    $merkleTree = json_decode(file_get_contents($merkleTreePath), true);
    $storedRoot = trim(file_get_contents($merkleRootPath));

    $treeRoot = $merkleTree['root'] ?? null;

    $observedLeaves = recursiveChunks($codex);
    $observedRoot   = buildMerkle($observedLeaves);

    if ($storedRoot !== $treeRoot) {
        $violations[] = "Stored Merkle root does not match merkleTree.json canonical root.";
    }

    if ($observedRoot !== $storedRoot) {
        $violations[] = "Observed Codex structure diverges from governed Merkle snapshot.";
    }
}

#endregion SECTION IV

#region SECTION V — Process Violations & Update Persistence

$emitted = [];
$updated = false;

$currentObservations = array_flip($violations);

// Pass 1: Handle current observations
foreach ($violations as $obs) {
    $found = false;

    foreach ($auditLog as &$record) {
        if (($record['resolved'] ?? null) !== null) {
            continue;
        }

        if (
            ($record['type'] ?? null) === 'violation' &&
            ($record['observation'] ?? null) === $obs
        ) {
            $record['lastObserved']     = $timestamp;
            $record['observationCount'] = ($record['observationCount'] ?? 0) + 1;
            $found  = true;
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
            'timestamp'         => $timestamp,
            'auditMode'         => $auditMode,
            'observation'       => $obs,
            'notificationSent'  => null,  // Owned by Sentinel
            'violationBatch'    => null,  // Owned by Sentinel
            'resolved'          => null,
            'resolution'        => null,  // Owned by Reconciler
            'lastObserved'      => $timestamp,
            'observationCount'  => 1
        ];

        $auditLog[] = $newRecord;
        $emitted[]  = $newRecord;
        $updated    = true;
    }
}

// Pass 2: Infer resolution by absence — mode-isolated
foreach ($auditLog as &$record) {
    if (
        ($record['type'] ?? null) === 'violation' &&
        ($record['resolved'] ?? null) === null &&
        ($record['auditMode'] ?? null) === $auditMode &&
        !isset($currentObservations[$record['observation'] ?? ''])
    ) {
        $record['resolved'] = $timestamp;
        $updated = true;
    }
}
unset($record);

// Persist changes
if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

// Emit violations observed in this audit execution (for Sentinel consumption)
echo json_encode($emitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion SECTION V