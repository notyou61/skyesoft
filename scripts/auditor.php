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
 *
 *  Explicitly Forbidden:
 *   • NO lifecycle handling
 *   • NO counters or increments
 *   • NO mutation of existing records
 * ===================================================================== */

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Runtime Context

$root      = dirname(__DIR__);
$timestamp = time();
$auditMode = "governance";

$auditLogPath = $root . '/data/records/auditResults.json';
$violations   = [];

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

function unresolvedDuplicate(array $log, string $observation): bool {
    foreach ($log as $r) {
        if (
            ($r['type'] ?? null) === 'violation' &&
            ($r['resolved'] ?? null) === null &&
            ($r['observation'] ?? null) === $observation
        ) {
            return true;
        }
    }
    return false;
}

#endregion SECTION III — Load Audit Log

#region SECTION IV — Required File Presence

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

#region SECTION V — Merkle Integrity Checks

if (!$violations) {
    $codex      = json_decode(file_get_contents($codexPath), true);
    $merkleTree = json_decode(file_get_contents($merkleTreePath), true);
    $storedRoot = trim(file_get_contents($merkleRootPath));

    $treeRoot = $merkleTree['root'] ?? null;

    $observedLeaves = recursiveChunks($codex);
    $observedRoot   = buildMerkle($observedLeaves);

    if ($storedRoot !== $treeRoot) {
        $violations[] =
            "Stored Merkle root does not match merkleTree.json canonical root.";
    }

    if ($observedRoot !== $storedRoot) {
        $violations[] =
            "Observed Codex structure diverges from governed Merkle snapshot.";
    }
}

#endregion SECTION V — Merkle Integrity Checks

#region SECTION VI — Emit & Persist Violations

$emitted = [];

foreach ($violations as $obs) {
    if (unresolvedDuplicate($auditLog, $obs)) {
        continue;
    }

    $record = [
        "type"             => "violation",
        "violationId"      => nextViolationId($auditLog),
        "timestamp"        => $timestamp,
        "auditMode"        => $auditMode,
        "observation"      => $obs,
        "notificationSent" => null,
        "resolved"         => null,
        "actions"          => null,
        "lastObserved"     => null,
        "observationCount" => null
    ];

    $auditLog[] = $record;
    $emitted[]  = $record;
}

if ($emitted) {
    file_put_contents(
        $auditLogPath,
        json_encode($auditLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

echo json_encode($emitted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion SECTION VI — Emit & Persist