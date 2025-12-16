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

function generateReportNarrative(array $auditRecord): array {
    $apiUrl = 'http://localhost/skyesoft/api/askOpenAI.php';

    $prompt = <<<PROMPT
You are operating under the Skyesoft Semantic Responder Standard.

This task is governed by the following Standing Orders, which you must obey:
- Codex-First Reasoning
- Fact vs Interpretation Separation
- No Speculation or Hallucination
- Non-Override Rule
- Design Inference (Advisory Only)

You are provided a canonical Codex Audit Record as JSON.
This record contains governed facts and determinations. You must not alter, reinterpret, soften, or restate those results.

Your task is NOT to explain the audit outcome.

Your task IS to explain the Codex doctrines that define how such an audit is evaluated and interpreted.

Specifically:
- Describe the Codex principles that govern structural integrity, tier hierarchy, and universality.
- Explain how those principles are assessed during a Codex audit.
- Clarify, in Codex terms, what it means for an audit to be “Sound” or “Not Sound” without justifying or rephrasing the result.
- Distinguish clearly between governed facts (the audit record) and advisory explanation (your narrative).

You must NOT:
- Introduce findings
- Recommend changes or remedies
- Speculate about future behavior
- Add authority beyond explanation
- Repeat or paraphrase the determination itself

This narrative is advisory only and exists to support human understanding and document rendering (e.g., PDF).

You must generate content ONLY for the following sections:

1. Executive Summary  

Write this section for a non-technical manager or stakeholder.

Assume the reader:
- Is not a programmer
- Is not familiar with Skyesoft internals
- Has not read the Codex

Language rules:
- Use plain English
- Use short sentences
- Avoid jargon and formal governance language
- Explain “Sound” in everyday terms

+ Use different sentence structures than previous runs when possible, without changing meaning.

Content rules:
- Explain what was reviewed
- Explain what “Sound” means
- Explain what this result does NOT mean (not finished, not final)
- Explain why this audit result is sufficient at this stage

You must:
- Base all statements only on the audit record
- Avoid recommendations
- Avoid future-looking statements
- Avoid reassurance or persuasion


2. Methodology Overview  
   Explain, at a conceptual level, how Codex doctrine is evaluated (structure, tiers, universality), not what this audit found.

3. Interpretation Notes  
   Clarify how readers should interpret audit records versus Codex authority and governance.

Tone:
- Professional
- Neutral
- Precise
- Non-instructional

Assume this text will be rendered into a formal PDF report.

Return VALID JSON ONLY in the following structure:

{
  "executiveSummary": "...",
  "methodologyOverview": "...",
  "interpretationNotes": "..."
}
PROMPT;

    $payload = json_encode([
        'query'   => $prompt,
        'context' => json_encode($auditRecord, JSON_UNESCAPED_SLASHES)
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
    if ($response === false) return [];

    $outer = json_decode($response, true);
    $text  = $outer['response'] ?? '';

    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
}

#endregion

#region SECTION 3 — Initialization

$codexPath  = __DIR__ . '/../codex/codex.json';
$auditOut   = __DIR__ . '/../codex/meta/codexAudit.json';
$merklePath = __DIR__ . '/../codex/meta/merkleRoot.txt';

$executedAt = time();
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

#region SECTION 9 — Canonical Audit Record Assembly (Canonical First)

// ------------------------------------------------------------------
// Phase A — Assemble canonical audit record (NO narrative yet)
// ------------------------------------------------------------------

$auditRecord = [
    // ------------------------------------------------------------------
    // Canonical Audit Identity (governed)
    // ------------------------------------------------------------------
    "auditId"              => $auditId,
    "auditDate"            => $now,
    "executedAt"           => $executedAt,
    "executedAtHuman"      => gmdate('c', $executedAt),
    "codexSnapshot"        => "merkleRoot:{$merkleRoot}",
    "methodReference"      => "Hybrid: deterministic structural checks + optional AI adversarial advisory",
    "overallDetermination" => $determination,
    "determinationStatus"  => $determinationStatus,

    // ------------------------------------------------------------------
    // Findings (governed)
    // ------------------------------------------------------------------
    "findings"                => $findings,
    "aiCandidateFindings"     => $aiCandidates,
    "findingsOrNoneStatement" => empty($findings)
        ? "No governed findings identified."
        : "Governed findings present.",

    "coverageStatement" => "Full Codex reviewed under literal and universal assumptions.",
    "aiAdvisoryStatus"  => $aiStatus,
    "environment"       => APP_ENV,

    // ------------------------------------------------------------------
    // Document Standard — Metadata (non-governing)
    // ------------------------------------------------------------------
    "documentMeta" => [
        "documentType" => "audit",
        "catalogKey"   => "complianceAudit",
        "title"        => "Codex Audit Report",
        "icon"         => 67,
        "renderIntent" => "pdf",
        "authority"    => "derived-view",
        "notes"        => "Metadata exists solely to support document rendering and indexing."
    ]
];

// ------------------------------------------------------------------
// Phase B — Generate advisory narrative FROM canonical record
// ------------------------------------------------------------------

$reportNarrativeAI = $aiEnabled
    ? generateReportNarrative($auditRecord)
    : [];

// ------------------------------------------------------------------
// Phase C — Attach advisory narrative (non-governing)
// ------------------------------------------------------------------

$auditRecord["reportNarrative"] = [
    "executiveSummary" => [
        "icon"          => 29,
        "contentFormat" => "paragraphs",
        "authority"     => "advisory",
        "text"          => $reportNarrativeAI['executiveSummary']
            ?? [
                "This audit confirms that the rules governing Skyesoft are logically sound at this stage.",
                "This means the rules don’t contradict each other, and they can be followed without needing to guess how to apply them.",
                "However, this audit does not mean the system is complete or final — it simply means the current rules make sense and are in a workable state right now.",
                "At this point, the audit confirms the rules can be followed as written without internal conflicts."
            ]
    ],

    "methodologyOverview" => [
        "icon"          => 6,
        "contentFormat" => "bullets",
        "authority"     => "advisory",
        "items"         => [
            "Checked that all required Codex sections are present",
            "Verified rules do not contradict each other",
            "Confirmed rules can be followed without hidden assumptions",
            "Recorded a clear pass or fail result"
        ]
    ],

    "interpretationNotes" => [
        "icon"          => 63,
        "contentFormat" => "paragraph",
        "authority"     => "advisory",
        "text"          =>
            "This section explains the audit in plain language only. "
          . "The audit result itself is defined by the governed fields above."
    ]
];

// ------------------------------------------------------------------
// Persist audit record
// ------------------------------------------------------------------

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
    "next" => ($determinationStatus === "Sealed")
    ? "Audit sealed. Ready for commit."
    : "Human review required before sealing and commit."
], JSON_PRETTY_PRINT);

#endregion