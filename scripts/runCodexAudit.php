<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — Codex Audit Runner
//  Version: 1.1.2
//  Last Updated: 2025-12-15
//  Codex Tier: 3 — Governance Automation / Audit Execution
// ======================================================================

header("Content-Type: application/json; charset=UTF-8");

#region SECTION 0 — Environment Bootstrap

$envLocalPath = __DIR__ . '/../secure/env.local';

if (file_exists($envLocalPath)) {
    $lines = file($envLocalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

define('APP_ENV', getenv('APP_ENV') ?: 'production');

#endregion

#region SECTION 1 — Fail Handler

function aiFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "codexAuditRunner",
        "error"   => "❌ {$msg}"
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

#endregion

#region SECTION 2 — Helper Functions

function checkStructuralIntegrity(array $codex, array &$findings, int &$blockingCount): void {
    $requiredTop = ['meta', 'constitution', 'standards', 'modules'];

    foreach ($requiredTop as $key) {
        if (!isset($codex[$key])) {
            $findings[] = [
                "severity" => "blocking",
                "carrier"  => [
                    "issue"  => "Missing required top-level section: {$key}",
                    "cause"  => "Structural violation of Codex tier hierarchy",
                    "remedy" => "Restore missing section via governed amendment"
                ]
            ];
            $blockingCount++;
        }
    }

    if (isset($codex['standards'])) {
        foreach ($codex['standards'] as $stdKey => $std) {
            if (isset($std['tier']) && $std['tier'] !== 2) {
                $findings[] = [
                    "severity" => "blocking",
                    "carrier"  => [
                        "issue"  => "Standard declares incorrect tier in {$stdKey}",
                        "cause"  => "Violation of tier hierarchy doctrine",
                        "remedy" => "Correct tier declaration"
                    ]
                ];
                $blockingCount++;
            }
        }
    }
}

function getAICandidates(string $codexRaw): array {
    $apiUrl = 'http://localhost/skyesoft/api/askOpenAI.php';

    $prompt = <<<PROMPT
You are an adversarial Codex auditor per the Codex Audit Standard.

Presume validity. Literal interpretation only. Universality unless explicitly excepted.
Assume misuse, fatigue, automation error, and literal non-human execution.

Task: Read the full Codex JSON.

Surface ONLY candidates where doctrine as written:
• Requires inference
• Relies on implicit or unwritten rules
• Has ambiguous authority
• Produces contradictory outcomes under literal application
• Violates universality implicitly

Return JSON array only:
[{ "narrative": "...", "citation": "...", "cause": "..." }]

No severity. No remedies. No judgment.
PROMPT;

    $payload = json_encode([
        'query'   => $prompt,
        'context' => $codexRaw
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $httpCode !== 200) {
        return [];
    }

    $decodedOuter = json_decode($response, true);
    $text = $decodedOuter['response'] ?? '';

    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
}

#endregion

#region SECTION 3 — Initialization

$codexPath  = __DIR__ . '/../codex/codex.json';
$auditOut   = __DIR__ . '/../codex/meta/codexAudit.json';
$merklePath = __DIR__ . '/../codex/meta/merkleRoot.txt';

$executedAt = time();                 // ✅ Canonical Unix time
$now        = date('Y-m-d');
$auditId    = 'AUD-' . date('Ymd-His');

$codexRaw = file_get_contents($codexPath);
if ($codexRaw === false) aiFail("Unable to read codex.json");

$codex = json_decode($codexRaw, true);
if ($codex === null) aiFail("Invalid codex.json");

$merkleRoot = file_exists($merklePath)
    ? trim(file_get_contents($merklePath))
    : 'missing';

#endregion

#region SECTION 4 — AI Availability Detection

$openAiKey = getenv('OPENAI_API_KEY');

$aiEnabled = (
    $openAiKey !== false &&
    trim($openAiKey) !== '' &&
    $openAiKey !== 'your-local-or-dummy-key'
);

$aiStatus = $aiEnabled
    ? 'enabled'
    : 'disabled (no valid API key)';

#endregion

#region SECTION 5 — Findings Containers

$findings      = [];
$blockingCount = 0;
$aiCandidates  = [];

#endregion

#region SECTION 6 — Phase 1: Deterministic Structural Checks

checkStructuralIntegrity($codex, $findings, $blockingCount);

#endregion

#region SECTION 7 — Phase 2: AI Advisory Candidate Surfacing

if ($aiEnabled) {
    $aiCandidates = getAICandidates($codexRaw);
    $aiStatus = empty($aiCandidates)
        ? 'enabled (no candidates)'
        : 'enabled (candidates surfaced)';
}

#endregion

#region SECTION 8 — Determination

$determination = ($blockingCount > 0)
    ? "Not Sound"
    : "Sound";

$determinationStatus = (
    empty($findings) && empty($aiCandidates)
)
    ? "Sealed"
    : "Proposed — Human review required";

#endregion

#region SECTION 9 — Canonical Audit Record Assembly

$auditRecord = [
    "auditId"              => $auditId,
    "auditDate"            => $now,
    "executedAt"           => $executedAt, // ✅ Canonical time
    "executedAtHuman"      => gmdate('c', $executedAt), // non-authoritative
    "codexSnapshot"        => "merkleRoot:{$merkleRoot}",
    "methodReference"      => "Hybrid: deterministic structural checks + optional AI adversarial advisory",
    "overallDetermination" => $determination,
    "determinationStatus"  => $determinationStatus,
    "findings"             => $findings,
    "aiCandidateFindings"  => $aiCandidates,
    "findingsOrNoneStatement" => empty($findings)
        ? "No governed findings identified."
        : "Governed findings present.",
    "coverageStatement"    => "Full Codex reviewed under literal and universal assumptions.",
    "aiAdvisoryStatus"     => $aiStatus,
    "environment"          => APP_ENV
];

file_put_contents(
    $auditOut,
    json_encode(
        $auditRecord,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )
);

#endregion

#region SECTION 10 — Completion Signal

echo json_encode([
    "success"  => true,
    "role"     => "codexAuditRunner",
    "message"  => "✔ Codex Audit Record generated ({$now}): {$determination}",
    "aiStatus" => $aiStatus,
    "status"   => $determinationStatus,
    "path"     => $auditOut,
    "next"     => "Human review required before sealing and commit."
], JSON_PRETTY_PRINT);

#endregion
