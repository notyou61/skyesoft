<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — auditor.php
 *  Role: Auditor (AGS v1)
 *  Authority: Audit Governance Standard
 *  PHP: 8.3+
 *
 *  Purpose:
 *   • Observe Codex integrity via Merkle verification
 *   • Emit governed violation observations only
 *   • Track persistence of unresolved violations via observation match
 *
 *  Explicitly Forbidden:
 *   • NO lifecycle handling
 *   • NO mutation of existing records except persistence updates
 * ===================================================================== */

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Runtime Context

$root      = dirname(__DIR__);
$timestamp = time();
$auditMode = "governance";

$auditLogPath = $root . '/data/records/auditResults.json';

// Environment detection
$isCli        = (PHP_SAPI === 'cli');
$isProduction = !$isCli; // CLI = dev/test, Web SAPI = production (GoDaddy)

#endregion SECTION 0 — Runtime Context

#region SECTION I — Path Resolution

$codexPath      = $root . '/codex/codex.json';
$merkleTreePath = $root . '/data/records/merkleTree.json';
$merkleRootPath = $root . '/data/records/merkleRoot.txt';

#endregion SECTION I — Path Resolution

#region SECTION II — Merkle Helpers (MUST MATCH BUILDER)

function recursiveChunks(mixed $node, string $path = ''): array {
    $chunks = [];

    if (!is_array($node)) {
        $chunks[$path] = hash(
            'sha256',
            json_encode($node, JSON_UNESCAPED_SLASHES)
        );
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

#endregion SECTION II — Merkle Helpers

#region SECTION III — Load Audit Log

if (!file_exists($auditLogPath)) {
    file_put_contents($auditLogPath, json_encode([], JSON_PRETTY_PRINT));
}

$auditLog = json_decode(file_get_contents($auditLogPath), true);
if (!is_array($auditLog)) {
    $auditLog = [];
}

function nextViolationId(array $log): string {
    $max = 0;
    foreach ($log as $r) {
        if (isset($r['violationId']) && preg_match('/VIO-(\d+)/', $r['violationId'], $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return sprintf('VIO-%03d', $max + 1);
}

$developerEmail = 'steve.skye@skyelighting.com'; // set to your preferred address

function sendViolationNotice(string $to, array $record, bool $isProduction): bool {
    if (!$isProduction) {
        // Non-production environment: skip email
        return false;
    }

    $subject = "[Skyesoft AGS] New Audit Violation {$record['violationId']}";

    $body =
        "A new audit violation instance was created.\n\n" .
        "Violation ID : {$record['violationId']}\n" .
        "Audit Mode   : {$record['auditMode']}\n" .
        "Timestamp    : {$record['timestamp']}\n" .
        "Observation  : {$record['observation']}\n\n" .
        "This notice is sent once per violation instance.\n";

    $headers =
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "From: skyesoft@localhost\r\n";

    return @mail($to, $subject, $body, $headers);
}

#endregion SECTION III — Load Audit Log

#region SECTION IV — Required File Presence

$violations = [];  // Array of observation strings

$required = [
    'codex.json'      => $codexPath,
    'merkleTree.json' => $merkleTreePath,
    'merkleRoot.txt'  => $merkleRootPath
];

foreach ($required as $label => $path) {
    if (!file_exists($path)) {
        $violations[] = "Required governed file missing: $label at $path";
    }
}

#endregion SECTION IV — Required File Presence

#region SECTION V — Name Conformance Checks (Canonical Registries Only)

/**
 * Recursively audit semantic JSON keys for camelCase compliance.
 *
 * Doctrine:
 * • All semantic keys are governed, including meta keys
 * • Numeric index keys ("1", "2", etc.) are exempt
 * • Values are not audited EXCEPT where explicitly semantic (e.g. filenames)
 */
function auditCamelCaseKeys(
    mixed $node,
    string $file,
    array &$violations
): void {

    if (!is_array($node)) {
        return;
    }

    foreach ($node as $key => $value) {

        /* -----------------------------
         * Key naming enforcement
         * ----------------------------- */
        if (
            is_string($key) &&
            !ctype_digit($key) &&
            !preg_match('/^[a-z][a-zA-Z0-9]*$/', $key)
        ) {
            $violations[] =
                "Name conformance violation: key '{$key}' in '{$file}' is not camelCase.";
        }

        /* -----------------------------
         * Semantic VALUE enforcement
         * (explicitly governed fields only)
         * ----------------------------- */
        if (
            $key === 'file' &&
            is_string($value)
        ) {
            $basename = pathinfo($value, PATHINFO_FILENAME);

            if (!preg_match('/^[a-z][a-zA-Z0-9]*$/', $basename)) {
                $violations[] =
                    "Name conformance violation: file '{$value}' in '{$file}' must use camelCase basename.";
            }
        }

        // Recurse into nested structures
        auditCamelCaseKeys($value, $file, $violations);
    }
}

    if (empty($violations)) {

        $registryRoot = $root; // ENTIRE REPO SCOPE

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
            new RecursiveDirectoryIterator(
                $registryRoot,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $fileInfo) {

        if (!$fileInfo->isFile()) {
            continue;
        }

        $file = $fileInfo->getFilename();
        $path = $fileInfo->getPathname();

        // Skip excluded directories
        foreach ($excludedDirs as $dir) {
            if (str_contains($path, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR)) {
                continue 2;
            }
        }

        // Skip excluded files
        if (in_array($file, $excludedFiles, true)) {
            continue;
        }

        // Canonical targets
        $isCanonicalRegistry = preg_match('/(Registry|Map|Index)\.json$/', $file);
        $isCodex             = ($file === 'codex.json');

        if (!$isCanonicalRegistry && !$isCodex) {
            continue;
        }

        // Filename camelCase enforcement — registries ONLY
        if ($isCanonicalRegistry) {
            if (!preg_match('/^[a-z][a-zA-Z0-9]*(Registry|Map|Index)\.json$/', $file)) {
                $violations[] =
                    "Name conformance violation: canonical registry filename '{$file}' must use camelCase.";
                continue;
            }
        }

        $json = json_decode(file_get_contents($path), true);

        if (!is_array($json)) {
            continue;
        }

        // Recursive semantic audit
        auditCamelCaseKeys($json, $file, $violations);
    }

}

#endregion SECTION V — Name Conformance Checks

#region SECTION VI — Merkle Integrity Checks

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

#endregion SECTION VI — Merkle Integrity Checks

#region SECTION VII — Emit & Persist Violations

$emitted = [];
$updated = false;

/*
 * Pass 1 — Emit current observations
 */
foreach ($violations as $obs) {
    $found = false;

    foreach ($auditLog as &$record) {
        if (
            ($record['type'] ?? null) === 'violation' &&
            ($record['resolved'] ?? null) === null &&
            ($record['observation'] ?? null) === $obs
        ) {
            // Persistent unresolved violation — update tracking
            $record['lastObserved'] = $timestamp;
            $record['observationCount'] = ($record['observationCount'] ?? 0) + 1;

            $found = true;
            $updated = true;
            $emitted[] = $record;
            break;
        }
    }
    unset($record); // break reference

    if (!$found) {
        // New violation instance
        $record = [
            "type"             => "violation",
            "violationId"      => nextViolationId($auditLog),
            "timestamp"        => $timestamp,
            "auditMode"        => $auditMode,
            "observation"      => $obs,
            "notificationSent" => null,
            "resolved"         => null,
            "actions"          => null,
            "lastObserved"     => $timestamp,
            "observationCount" => 1
        ];

        // Notify only on first instance creation
        if (sendViolationNotice($developerEmail, $record, $isProduction)) {
            $record["notificationSent"] = $timestamp;
        }

        $auditLog[] = $record;
        $emitted[]  = $record;
        $updated = true;
    }
}

/*
 * Pass 2 — Resolution inference
 * Any unresolved violation NOT observed in this run is now resolved
 */
$currentObservations = array_flip($violations);

foreach ($auditLog as &$record) {
    if (
        ($record['type'] ?? null) === 'violation' &&
        ($record['resolved'] ?? null) === null &&
        !isset($currentObservations[$record['observation']])
    ) {
        $record['resolved'] = $timestamp;
        $updated = true;
    }
}
unset($record);

/*
 * Persist updates if anything changed
 */
if ($updated) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

echo json_encode($emitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion SECTION VII — Emit & Persist Violations