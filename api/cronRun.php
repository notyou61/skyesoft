<?php
// ======================================================================
//  Skyesoft — cronRun.php
//  Automation Governor (Codex Article XI + EGS v4)
//  PHP 8.1+ • Strict Typing
//  Role: Execute automation tasks and orchestrate Auditor → Sentinel
//  Forbidden:
//     • No mutation of Codex, Merkle Tree, Merkle Root
//     • No MIS logic executed here — Auditor handles MIS detection
// ======================================================================

declare(strict_types=1);
header("Content-Type: application/json; charset=UTF-8");

// ======================================================================
//  SECTION I — Fail Handler
// ======================================================================
function cronFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "cron",
        "error"   => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ======================================================================
//  SECTION II — Resolve Paths
// ======================================================================
$root          = dirname(__DIR__);
$codexPath     = $root . "/codex/codex.json";
$auditorPath   = $root . "/scripts/auditor.php";
$sentinelPath  = $root . "/scripts/sentinel.php";
$versionsPath  = $root . "/assets/data/versions.json";

// Verify required files exist
foreach ([
    "Codex"          => $codexPath,
    "Auditor"        => $auditorPath,
    "Sentinel"       => $sentinelPath,
    "versions.json"  => $versionsPath
] as $label => $path) {
    if (!file_exists($path)) cronFail("$label missing at $path");
}

// ======================================================================
//  SECTION III — Load Codex & Determine Task
// ======================================================================
$codex = json_decode(file_get_contents($codexPath), true);
$versions = json_decode(file_get_contents($versionsPath), true);

if (!is_array($codex) || !is_array($versions)) {
    cronFail("Invalid JSON in Codex or versions.json.");
}

$automation = $codex["modules"]["items"]["systemAutomation"]["tasks"] ?? null;

if (!$automation) {
    cronFail("systemAutomation.tasks missing from Codex.");
}

// Determine requested task
$task = $_GET["task"] ?? ($argv[1] ?? null);

if (!$task) cronFail("No task specified.");
if (!isset($automation[$task])) cronFail("Unknown task '$task'.");

// ======================================================================
//  SECTION IV — Execute Auditor (Detection Only)
// ======================================================================
$auditorOutput = shell_exec("php " . escapeshellarg($auditorPath));

if (!$auditorOutput) cronFail("Auditor produced no output.");

$auditorJson = json_decode($auditorOutput, true);

if (!is_array($auditorJson) || !isset($auditorJson["findings"])) {
    cronFail("Auditor output invalid.");
}

// ======================================================================
//  SECTION V — Forward Findings to Sentinel (Processing)
// ======================================================================

// Normalize Auditor findings
$inner = $auditorJson["findings"]["findings"]
    ?? $auditorJson["findings"]
    ?? [];

$normalized = array_map(function($f) {
    if (isset($f["description"])) {
        $f["description"] = preg_replace("/\r?\n/", " ", $f["description"]);
    }
    return $f;
}, is_array($inner) ? $inner : []);

// Build payload
$sentinelPayload = json_encode([
    "findings" => $normalized
], JSON_UNESCAPED_SLASHES);

// ----------------------------------------------------------------------
// Windows-safe input transport: TEMP FILE
// ----------------------------------------------------------------------
$tmpFile = tempnam(sys_get_temp_dir(), "sky_");
file_put_contents($tmpFile, $sentinelPayload);

$sentinelCmd = "php " . escapeshellarg($sentinelPath) . " " . escapeshellarg($tmpFile);

$sentinelOutput = shell_exec($sentinelCmd);
unlink($tmpFile); // Cleanup

if (!$sentinelOutput) {
    cronFail("Sentinel produced no output.");
}

$sentinelJson = json_decode($sentinelOutput, true);

if (!is_array($sentinelJson)) {
    cronFail("Sentinel returned invalid JSON.");
}

// ======================================================================
//  SECTION VI — Automation Run Report (Codex Requirement)
// ======================================================================
$report = [
    "timestamp" => time(),
    "task"      => $task,
    "auditor"   => $auditorJson,
    "sentinel"  => $sentinelJson
];

$reportDir = $root . "/reports/automation";
if (!is_dir($reportDir)) mkdir($reportDir, 0777, true);

file_put_contents(
    "$reportDir/{$task}.json",
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// ======================================================================
//  SECTION VII — Output
// ======================================================================
echo json_encode([
    "success"  => true,
    "role"     => "cron",
    "task"     => $task,
    "report"   => $report
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;