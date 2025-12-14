<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — sentinel.php
//  Role: Integrity Event Registrar (EGS v4.1)
//  PHP 8.3+ • Strict Typing
//
//  Responsibility:
//   • Consume repositoryAuditor output
//   • Assign ERR IDs deterministically
//   • Append to errorRegistry.json (SOT)
//   • Append to repositoryAudit.json (SOT)
//   • Update SSE system error state (optional)
//
//  Forbidden:
//   • NO filesystem scans
//   • NO Codex mutation
//   • NO Merkle generation or verification
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

$root = dirname(__DIR__);

#region SECTION 0 — Fail Handler
function sentinelFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "sentinel",
        "error"   => "❌ $msg"
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion SECTION 0 — Fail Handler

#region SECTION I — Load Input (Auditor Output)

// Read all STDIN
$raw = stream_get_contents(STDIN);

// CLI temp file
if (!$raw && !empty($argv[1]) && file_exists($argv[1])) {
    $raw = file_get_contents($argv[1]);
}

// CLI inline JSON
if (!$raw && !empty($argv[1])) {
    $raw = $argv[1];
}

if (!$raw) {
    sentinelFail("No auditor payload received.");
}

// -----------------------------
// Normalize and extract JSON
// -----------------------------

// Remove BOM + trim
$clean = preg_replace('/^\xEF\xBB\xBF/', '', trim($raw));

// Extract ALL JSON objects
preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $clean, $matches);

if (empty($matches[0])) {
    sentinelFail("No JSON object found in auditor payload.");
}

// Use the LAST JSON object (canonical auditor output)
$json = end($matches[0]);

$data = json_decode($json, true);

if (!is_array($data) || !isset($data["status"], $data["errors"])) {
    sentinelFail("Invalid auditor payload. Expected { status, errors }.");
}

$errors = $data["errors"];

// PASS with no errors
if (!is_array($errors) || empty($errors)) {
    echo json_encode([
        "success" => true,
        "role"    => "sentinel",
        "message" => "No errors to process."
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

#endregion SECTION I — Load Input

#region SECTION II — Load SOT Files

$registryPath = "$root/assets/data/errorRegistry.json";
$auditPath    = "$root/assets/data/repositoryAudit.json";
$versionsPath = "$root/assets/data/versions.json";
$merklePath   = "$root/codex/meta/merkleRoot.txt";

foreach ([$registryPath, $auditPath] as $path) {
    if (!file_exists($path)) {
        sentinelFail(basename($path) . " missing.");
    }
}

$registry = json_decode(file_get_contents($registryPath), true);
$audit    = json_decode(file_get_contents($auditPath), true);

if (!is_array($registry) || !is_array($audit)) {
    sentinelFail("Corrupt SOT file detected.");
}

#endregion SECTION II — Load SOT Files

#region SECTION III — ERR ID Generator

function nextERRid(array $registry): string {
    if (empty($registry)) {
        return "ERR-00001";
    }

    $lastId = end($registry)["id"] ?? "ERR-00000";

    if (!preg_match("/ERR-(\d{5})/", $lastId, $m)) {
        return "ERR-00001";
    }

    $next = (int)$m[1] + 1;
    return "ERR-" . str_pad((string)$next, 5, "0", STR_PAD_LEFT);
}

#endregion SECTION III — ERR ID Generator

#region SECTION IV — Capture Merkle Context (Optional)

$merkleRoot = file_exists($merklePath)
    ? trim((string)file_get_contents($merklePath))
    : null;

#endregion SECTION IV — Merkle Context

#region SECTION V — Process Auditor Errors

$timestamp = time();
$newErrors = [];

foreach ($errors as $e) {

    $errId = nextERRid($registry);

    $err = [
        "id"         => $errId,
        "timestamp"  => $timestamp,
        "type"       => "repository_integrity",
        "code"       => $e["code"] ?? "UNKNOWN",
        "path"       => $e["path"] ?? null,
        "message"    => $e["message"] ?? "Unspecified integrity error",
        "merkleRoot" => $merkleRoot,
        "status"     => "active",
        "lastAction" => "created"
    ];

    $registry[]  = $err;
    $newErrors[] = $err;

    $audit[] = [
        "timestamp" => $timestamp,
        "errorId"   => $errId,
        "code"      => $err["code"],
        "path"      => $err["path"],
        "message"   => $err["message"]
    ];
}

#endregion SECTION V — Process Auditor Errors

#region SECTION VI — Persist SOT

file_put_contents(
    $registryPath,
    json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents(
    $auditPath,
    json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

#endregion SECTION VI — Persist SOT

#region SECTION VII — Optional SSE State Update

if (file_exists($versionsPath)) {

    $versions = json_decode(file_get_contents($versionsPath), true);

    if (is_array($versions)) {
        $versions["system"]["activeErrors"] = count(
            array_filter($registry, fn($e) => ($e["status"] ?? "") === "active")
        );
        $versions["system"]["latestError"]    = end($newErrors)["id"] ?? null;
        $versions["system"]["errorTimestamp"] = $timestamp;

        file_put_contents(
            $versionsPath,
            json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

#endregion SECTION VII — Optional SSE State Update

#region SECTION VIII — Output

echo json_encode([
    "success" => true,
    "role"    => "sentinel",
    "count"   => count($newErrors),
    "errors"  => $newErrors
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion SECTION VIII — Output