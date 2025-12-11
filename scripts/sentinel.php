<?php
// ======================================================================
//  Skyesoft — sentinel.php
//  Role: ERR Processor (EGS v4)
//  PHP 8.1+ • Strict Typing
//  Responsibility:
//     • Receive Auditor findings
//     • Assign ERR IDs sequentially
//     • Append to errorRegistry.json (SOT)
//     • Append to repositoryAudit.json (SOT)
//     • Update SSE errorState (optional UI support)
//  Forbidden:
//     • NO modification of Codex or Merkle files
//     • NO regeneration of Merkle Tree or Root
// ======================================================================

declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

// ROOT REQUIRED BEFORE ANY PATH CALL
$root = dirname(__DIR__);

// ----------------------------------------------------------------------
// SECTION I — Fail Handler
// ----------------------------------------------------------------------
function fail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "sentinel",
        "error"   => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ======================================================================
//  SECTION II — Load Inputs (Supports: stdin, CLI file, CLI JSON)
// ======================================================================

$rawInput = file_get_contents("php://input");

// Case 1: stdin (Linux/Mac servers)
if ($rawInput && trim($rawInput) !== "") {
    $data = json_decode($rawInput, true);

// Case 2: CLI with temp file (Windows-safe)
} elseif (!empty($argv[1]) && file_exists($argv[1])) {
    $rawInput = file_get_contents($argv[1]);
    $data = json_decode($rawInput, true);

// Case 3: CLI direct JSON
} elseif (!empty($argv[1])) {
    $data = json_decode($argv[1], true);

// Case 4: no input
} else {
    fail("No input provided via STDIN, temp file, or CLI JSON.");
}

if (!is_array($data) || !isset($data["findings"])) {
    fail("Invalid or missing 'findings' from Auditor.");
}

$findings = $data["findings"];


$findings = $data["findings"];

// ----------------------------------------------------------------------
// SECTION III — Load SOT Files
// ----------------------------------------------------------------------

// Resolve repository root (required for building SOT paths)
$root = dirname(__DIR__);

$registryPath = $root . "/assets/data/errorRegistry.json";
$auditPath    = $root . "/assets/data/repositoryAudit.json";
$sseMetaPath  = $root . "/assets/data/versions.json";

if (!file_exists($registryPath)) fail("errorRegistry.json missing.");
if (!file_exists($auditPath))    fail("repositoryAudit.json missing.");

$registry = json_decode(file_get_contents($registryPath), true);
$audit    = json_decode(file_get_contents($auditPath), true);

if (!is_array($registry)) fail("Invalid errorRegistry.json");
if (!is_array($audit))    fail("Invalid repositoryAudit.json");

// ----------------------------------------------------------------------
// SECTION IV — Next ERR ID Generator
// ----------------------------------------------------------------------
function nextERRid(array $registry): string {
    if (empty($registry)) return "ERR-00001";

    $last = end($registry);
    $id = $last["id"] ?? null;

    if (!$id || !preg_match("/^ERR-([0-9]{5})$/", $id, $m)) {
        return "ERR-00001"; // fallback for malformed
    }

    $num = intval($m[1]) + 1;
    return "ERR-" . str_pad((string)$num, 5, "0", STR_PAD_LEFT);
}

// ----------------------------------------------------------------------
// SECTION V — Process Findings
// ----------------------------------------------------------------------
$newErrors = [];
$timestamp = time();

foreach ($findings as $f) {

    // classification safety
    $type = $f["type"] ?? "policy_violation";
    $name = $f["name"] ?? "Unnamed Error";
    $desc = $f["description"] ?? "No description";

    $errId = nextERRid($registry);

    // Build ERR object
    $err = [
        "id"               => $errId,
        "name"             => $name,
        "timestamp"        => $timestamp,
        "type"             => $type,
        "description"      => $desc,
        "details"          => $f["details"] ?? [],
        "suggestedSolution"=> "Pending review",
        "status"           => "active",
        "lastAction"       => "created"
    ];

    $registry[] = $err;
    $newErrors[] = $err;

    // Append audit log entry
    $audit[] = [
        "timestamp" => $timestamp,
        "errorId"   => $errId,
        "status"    => "active",
        "name"      => $name,
        "type"      => $type,
        "message"   => $desc
    ];
}

// ----------------------------------------------------------------------
// SECTION VI — Persist SOT Updates
// ----------------------------------------------------------------------
file_put_contents(
    $registryPath,
    json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

file_put_contents(
    $auditPath,
    json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// ----------------------------------------------------------------------
// SECTION VII — Optional: Update SSE Error State
// ----------------------------------------------------------------------
if (file_exists($sseMetaPath)) {
    $versions = json_decode(file_get_contents($sseMetaPath), true);

    if (is_array($versions)) {
        $versions["system"]["latestError"]   = $newErrors[count($newErrors) - 1]["id"] ?? null;
        $versions["system"]["activeErrors"]  =
            count(array_filter($registry, fn($e) => ($e["status"] ?? null) === "active"));
        $versions["system"]["errorTimestamp"]= $timestamp;

        file_put_contents(
            $sseMetaPath,
            json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

// ----------------------------------------------------------------------
// SECTION VIII — Output
// ----------------------------------------------------------------------
echo json_encode([
    "success" => true,
    "role"    => "sentinel",
    "processedErrors" => $newErrors,
    "count"            => count($newErrors)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;