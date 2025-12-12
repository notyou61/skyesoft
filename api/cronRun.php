<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — cronRun.php
//  Version: 1.0.0
//  Last Updated: 2025-12-12
//  Codex Tier: 3 — Automation / Orchestration
//
//  Role:
//  Execute scheduled automation tasks and orchestrate
//  Auditor → Sentinel workflows.
//
//  Forbidden:
//   • No mutation of Codex, Merkle Tree, or Merkle Root
//   • No MIS logic executed here — Auditor handles MIS detection
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Fail Handler
function cronFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "cron",
        "error"   => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion

#region SECTION 1 — Resolve Paths
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
#endregion

#region SECTION 2 — Load Codex & Determine Task
$codex    = json_decode(file_get_contents($codexPath), true);
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
#endregion

#region SECTION 3 — Execute Auditor (Detection Only)
$auditorOutput = shell_exec("php " . escapeshellarg($auditorPath));

if (!$auditorOutput) cronFail("Auditor produced no output.");

$auditorJson = json_decode($auditorOutput, true);

if (!is_array($auditorJson) || !isset($auditorJson["findings"])) {
    cronFail("Auditor output invalid.");
}
#endregion

#region SECTION 4 — Forward Findings to Sentinel
// Normalize Auditor findings
$inner = $auditorJson["findings"]["findings"]
    ?? $auditorJson["findings"]
    ?? [];

$normalized = array_map(function ($f) {
    if (isset($f["description"])) {
        $f["description"] = preg_replace("/\r?\n/", " ", $f["description"]);
    }
    return $f;
}, is_array($inner) ? $inner : []);

// Build payload
$sentinelPayload = json_encode([
    "findings" => $normalized
], JSON_UNESCAPED_SLASHES);

// Windows-safe input transport: TEMP FILE
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
#endregion

#region SECTION 4A — Extract Audit Facts (Internal Only)

// Initialize auditFacts (internal, non-persistent)
$auditFacts = [
    "meta" => [
        "schemaVersion"   => "1.0.0",
        "generatedAt"     => time(),
        "preSIS"          => true,
        "source"          => "cronRun.php",
        "auditCommitHint" => null
    ],

    "auditStatus" => [
        "overall"  => "clean",
        "severity" => "informational"
    ],

    "merkleVerification" => [
        "performed"    => false,
        "storedRoot"   => null,
        "computedRoot" => null,
        "match"        => null,
        "changedKeys"  => [],
        "changedCount" => 0
    ],

    "findingsSummary" => [
        "totalFindings" => 0,
        "byType" => [
            "policy_violation"      => 0,
            "structural_validation" => 0,
            "syntax_error"          => 0,
            "runtime_error"         => 0
        ]
    ],

    "findingsDigest" => [],

    "sentinelOutcome" => [
        "processed" => true,
        "action"    => "none", // descriptive, non-binding pre-SIS
        "notes"     => null
    ],

    "disclaimers" => [
        "This audit was executed in pre-System Initialization Standard (SIS) mode.",
        "Findings are informational and non-binding.",
        "Audit results are not persisted or indexed unless explicitly stated."
    ]
];

// ----------------------------------------
// Extract Auditor Findings
// ----------------------------------------
$findings = is_array($inner) ? $inner : [];
$auditFacts["findingsSummary"]["totalFindings"] = count($findings);

foreach ($findings as $f) {

    $type = $f["type"] ?? "unknown";

    if (isset($auditFacts["findingsSummary"]["byType"][$type])) {
        $auditFacts["findingsSummary"]["byType"][$type]++;
    }

    // Detect Codex drift / Merkle mismatch
    if ($type === "policy_violation" && ($f["name"] ?? "") === "Codex Drift Detected") {

        $details = $f["details"] ?? [];

        $auditFacts["auditStatus"]["overall"]  = "drift_detected";
        $auditFacts["auditStatus"]["severity"] = "informational";

        $auditFacts["merkleVerification"]["performed"]    = true;
        $auditFacts["merkleVerification"]["storedRoot"]   = $details["storedRoot"] ?? null;
        $auditFacts["merkleVerification"]["computedRoot"] = $details["liveRoot"] ?? null;
        $auditFacts["merkleVerification"]["match"]        = false;
        $auditFacts["merkleVerification"]["changedKeys"]  = $details["changedKeys"] ?? [];
        $auditFacts["merkleVerification"]["changedCount"] =
            is_array($details["changedKeys"] ?? null)
                ? count($details["changedKeys"])
                : 0;
    }

    // Build findings digest (fact capsule)
    $auditFacts["findingsDigest"][] = [
        "type"   => $type,
        "name"   => $f["name"] ?? "Unnamed Finding",
        "scope"  => "codex",
        "impact" => "non-binding",
        "source" => "auditor"
    ];
}

// ----------------------------------------
// Infer Sentinel Outcome (Descriptive Only)
// ----------------------------------------
if ($auditFacts["auditStatus"]["overall"] === "drift_detected") {
    $auditFacts["sentinelOutcome"]["action"] = "notify";
}

//endregion

#region SECTION 5 — Automation Run Report (Codex Requirement)
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
#endregion

#region SECTION 6 — Output
echo json_encode([
    "success" => true,
    "role"    => "cron",
    "task"    => $task,
    "report"  => $report
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;
#endregion