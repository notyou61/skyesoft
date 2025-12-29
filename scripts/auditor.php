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

if (!function_exists('auditCamelCaseKeys')) {
    function auditCamelCaseKeys(
        mixed $node,
        string $file,
        string $currentPath,
        array &$violations,
        bool $fullEnforcement = false
    ): void {
        if (!is_array($node)) {
            return;
        }

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
        ];

        $isInGovernedScope =
            $fullEnforcement ||
            (
                $currentPath !== '' &&
                in_array(explode('.', $currentPath)[0], $governedSemanticRoots, true)
            );

        foreach ($node as $key => $value) {
            $childPath = $currentPath === '' ? (string)$key : "{$currentPath}.{$key}";

            if (
                is_string($key) &&
                !ctype_digit($key) &&
                ($fullEnforcement || $isInGovernedScope)
            ) {
                // PURE syntactic camelCase check — nothing else
                $isCamelCase = preg_match('/^[a-z][a-zA-Z0-9]*$/', $key) === 1;

                if (!$isCamelCase) {
                    $violations[] =
                        "Name conformance violation: key '{$key}' in '{$file}' (path: {$childPath}) is not proper camelCase.";
                }
            }

            // Filename check remains valid and separate
            if ($key === 'file' && is_string($value)) {
                $basename = pathinfo($value, PATHINFO_FILENAME);
                if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $basename)) {
                    $violations[] =
                        "Name conformance violation: file '{$value}' in '{$file}' must use camelCase basename.";
                }
            }

            auditCamelCaseKeys($value, $file, $childPath, $violations, $fullEnforcement);
        }
    }
}

if (!function_exists('inferRuleId')) {
    function inferRuleId(string $observation): string {
        if (str_starts_with($observation, 'Merkle integrity violation:')) {
            return 'MERKLE_INTEGRITY';
        }
        if (str_starts_with($observation, 'Name conformance violation:')) {
            return 'NAMING_CONFORMANCE';
        }
        if (str_starts_with($observation, 'Required governed file missing:')) {
            return 'REQUIRED_FILES';
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

$violations = [];

// Initialize rule execution tracking
$rulesEvaluated = [
    'REQUIRED_FILES'     => false,
    'NAMING_CONFORMANCE' => false,
    'MERKLE_INTEGRITY'   => false,
];

// 1. Required file presence
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

// 2. Name conformance checks
$rulesEvaluated['NAMING_CONFORMANCE'] = true;

if (empty($violations)) {
    $registryRoot = $root;

    $excludedDirs = ['.git', 'node_modules', 'vendor', 'runtimeEphemeral', 'records', 'derived'];
    $excludedFiles = ['auditResults.json', 'audit-report.json', 'repositoryInventory.json', 'merkleTree.json', 'merkleRoot.txt'];

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
        $isCodex = ($file === 'codex.json');

        if (!$isCanonicalRegistry && !$isCodex) {
            continue;
        }

        if ($isCanonicalRegistry && !preg_match('/^[a-z][a-zA-Z0-9]*(Registry|Map|Index)\.json$/', $file)) {
            $violations[] = "Name conformance violation: canonical registry filename '{$file}' must use camelCase.";
            continue;
        }

        $json = json_decode(file_get_contents($path), true);
        if (!is_array($json)) {
            continue;
        }

        if ($isCodex) {
            auditCamelCaseKeys($json, $file, '', $violations, false);
        } else {
            auditCamelCaseKeys($json, $file, '', $violations, true);
        }
    }
}

// 3. Merkle integrity checks
$rulesEvaluated['MERKLE_INTEGRITY'] = true;

$codex      = json_decode(file_get_contents($codexPath), true);
$merkleTree = json_decode(file_get_contents($merkleTreePath), true);
$storedRoot = trim(file_get_contents($merkleRootPath));

$treeRoot = $merkleTree['root'] ?? null;

$observedLeaves = recursiveChunks($codex);
$observedRoot   = buildMerkle($observedLeaves);

if ($storedRoot !== $treeRoot || $observedRoot !== $storedRoot) {
    $violations[] = "Merkle integrity violation: observed Codex state does not match governed Merkle snapshot.";
}

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

// Compute mutatableCount — only NAMING_CONFORMANCE violations in codex.json
$mutatableCount = 0;
foreach ($emitted as $e) {
    if (
        ($e['ruleId'] ?? '') === 'NAMING_CONFORMANCE' &&
        str_contains($e['observation'] ?? '', "in 'codex.json'")
    ) {
        $mutatableCount++;
    }
}

// Explicit completion summary for Sentinel
$summary = [
    'runComplete'    => true,
    'timestamp'      => $timestamp,
    'auditMode'      => $auditMode,
    'emittedCount'   => count($emitted),
    'mutatableCount' => $mutatableCount,
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