<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — askOpenAI.php
//  Version: 1.3.1
//  Last Updated: 2025-12-12
//  Codex Tier: 3 — AI Augmentation / Prompt Orchestration
//
//  Role:
//  Codex-aligned OpenAI prompt executor.
//  Generates:
//   • Audit narratives (from automation reports)
//   • Skyebot responses (general semantic queries)
//
//  Forbidden:
//   • No data mutation except report narrative injection
//   • No Codex mutation
//   • Standing Orders must be injected from Codex SOT
// ======================================================================

//sheader("Content-Type: application/json; charset=UTF-8");

skyesoftLoadEnv();

$key = skyesoftGetEnv("OPENAI_API_KEY");

header('Content-Type: text/plain');

if ($key === null) {
    echo "❌ OPENAI_API_KEY NOT FOUND\n";
} else {
    echo "✅ OPENAI_API_KEY FOUND (length: " . strlen($key) . ")\n";
}
exit;

#region SECTION 0 — Fail Handler
function aiFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "askOpenAI",
        "error"   => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
#endregion

#region SECTION 1 — Codex Loaders (Standing Orders + Version)

function skyesoftLoadEnv(): void
{
    $envPath = dirname(__DIR__, 3) . '/secure/.env';

    if (!file_exists($envPath) || !is_readable($envPath)) {
        error_log("[env-loader] FAILED to load .env at $envPath");
        return;
    }

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        putenv("$key=$value");
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }

    error_log("[env-loader] Loaded .env from $envPath");
}

function skyesoftGetEnv(string $key): ?string
{
    $val = getenv($key);
    if ($val !== false && trim($val) !== '') return trim($val);
    if (!empty($_ENV[$key])) return trim($_ENV[$key]);
    if (!empty($_SERVER[$key])) return trim($_SERVER[$key]);
    return null;
}

function loadStandingOrders(): string {
    $root = dirname(__DIR__);
    $codexPath = "$root/codex/codex.json";

    if (!file_exists($codexPath)) {
        return "{}";
    }

    $codex = json_decode(file_get_contents($codexPath), true);

    if (!is_array($codex) || !isset($codex["meta"]["standingOrders"])) {
        return "{}";
    }

    return json_encode(
        $codex["meta"]["standingOrders"],
        JSON_UNESCAPED_SLASHES
    );
}

function getCodexVersion(): string {
    $root = dirname(__DIR__);
    $codexPath = "$root/codex/codex.json";

    if (!file_exists($codexPath)) {
        return "pending";
    }

    $codex = json_decode(file_get_contents($codexPath), true);

    return (string)(
        $codex["meta"]["version"]
        ?? $codex["version"]
        ?? "pending"
    );
}

function inferSalutation(string $firstName, string $lastName): ?string
{

$basePrompt = <<<PROMPT
Given the name "{$firstName} {$lastName}", infer the most likely professional salutation for business correspondence.

Respond with ONLY "Mr." or "Ms." — nothing else.
PROMPT;

    $fullPrompt = injectStandingOrders($basePrompt);

    $apiKey = skyesoftGetEnv("OPENAI_API_KEY");

    if ($apiKey === null) {
        aiFail("OPENAI_API_KEY not available in PHP environment.");
    }

    //$apiKey = getenv("OPENAI_API_KEY") ?: null;

    $response = callOpenAI($fullPrompt, $apiKey, 'gpt-4o-mini');

    if (!$response) {
        return null;
    }

    $response = trim($response);

    if (in_array($response, ['Mr.', 'Ms.'], true)) {
        return $response;
    }

    return null;
}
#endregion

#region SECTION 2 — Standing Orders Injection
function injectStandingOrders(string $basePrompt): string {
    $ordersJson = loadStandingOrders();
    $codexVer   = getCodexVersion();

    return <<<PROMPT
Adhere strictly to these Standing Orders (Codex Meta, v{$codexVer}).
They supersede all task instructions.

Standing Orders (JSON):
{$ordersJson}

{$basePrompt}
PROMPT;
}
#endregion

#region SECTION 3 — OpenAI API Caller
function callOpenAI(
    string $prompt,
    ?string $apiKey,
    string $model = "gpt-4o-mini"
): ?string {

    if (!$apiKey) {
        return null;
    }

    $ch = curl_init();

    /* TEMPORARY — confirm SSL is the only blocker */
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.openai.com/v1/chat/completions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            "model" => $model,
            "messages" => [
                [
                    "role"    => "system",
                    "content" => "You are a precise, Codex-aligned assistant."
                ],
                [
                    "role"    => "user",
                    "content" => $prompt
                ]
            ],
            "max_tokens"  => 600,
            "temperature" => 0.1
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}"
        ]
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (!$response || $status !== 200) {
        echo "cURL error: " . curl_error($ch) . "\n";
        echo "HTTP status: " . $status . "\n";
        return null;
    }

    $json = json_decode($response, true);

    return trim(
        (string)($json["choices"][0]["message"]["content"] ?? "")
    );
}
#endregion

#region SECTION 4 — Audit Facts Builder (Narrative Input)
function buildAuditFacts(array $report): array {

    $auditor  = $report["auditor"]  ?? [];
    $sentinel = $report["sentinel"] ?? [];

    $rawFindings =
        $auditor["findings"]["findings"]
        ?? $auditor["findings"]
        ?? [];

    $findings = array_map(
        fn($f) => array_merge($f, [
            "description" => isset($f["description"])
                ? preg_replace("/\r?\n/", " ", $f["description"])
                : null
        ]),
        is_array($rawFindings) ? $rawFindings : []
    );

    $overallStatus = "clean";
    $merkleMatch  = null;
    $changedCount = 0;

    foreach ($findings as $f) {
        if (
            ($f["type"] ?? "") === "policy_violation"
            && ($f["name"] ?? "") === "Codex Drift Detected"
        ) {
            $overallStatus = "drift_detected";
            $details = $f["details"] ?? [];
            $merkleMatch =
                ($details["storedRoot"] ?? null)
                === ($details["liveRoot"] ?? null);

            $changedCount = is_array($details["changedKeys"] ?? null)
                ? count($details["changedKeys"])
                : 0;
            break;
        }
    }

    return [
        "meta" => [
            "schemaVersion" => "1.0.0",
            "generatedAt"   => $report["timestamp"] ?? time(),
            "preSIS"        => true,
            "source"        => "askOpenAI.php"
        ],
        "auditStatus" => [
            "overall"  => $overallStatus,
            "severity" => "informational"
        ],
        "merkleVerification" => [
            "performed"    => $overallStatus === "drift_detected",
            "match"        => $merkleMatch,
            "changedCount" => $changedCount
        ],
        "findingsSummary" => [
            "totalFindings" => count($findings)
        ],
        "sentinelOutcome" => [
            "action" => $overallStatus === "drift_detected"
                ? "notify"
                : "none"
        ],
        "disclaimers" => [
            "Pre-SIS: Informational only.",
            "Audit results are not persisted or indexed."
        ]
    ];
}
#endregion

#region SECTION 4.5 — Library Mode Guard
// Stop controller execution when used as a library (e.g., review-elc-staging.php)
if (defined('SKYESOFT_LIB_MODE') && SKYESOFT_LIB_MODE) {
    return;
}
#endregion

#region SECTION 5 — Input Resolution
$root   = dirname(__DIR__);

$apiKey = getenv("OPENAI_API_KEY");
var_dump($apiKey);     // ← add this
exit;                  // ← add this

$apiKey = getenv("OPENAI_API_KEY");

if ($apiKey === false || $apiKey === '') {
    $apiKey = null;
}

if ($apiKey === null) {
    aiFail("OPENAI_API_KEY environment variable is not set or is empty. Cannot call OpenAI.");
}

$type   = $_GET["type"] ?? ($argv[1] ?? "narrative");
$aiFlag =
    ($_GET["ai"] ?? "false") === "true"
    || (($argv[2] ?? "") === "ai=true");

if (!$aiFlag) {
    aiFail("ai=true required to invoke AI.");
}
#endregion

#region SECTION 6 — Narrative Generation
$response           = null;
$narrativeGenerated = false;
$reportPath         = null;

if ($type === "narrative") {

    $task = $_GET["task"] ?? ($argv[3] ?? null);
    if (!$task) {
        aiFail("task required for narrative generation.");
    }

    $reportPath = "$root/reports/automation/{$task}.json";

    if (!file_exists($reportPath)) {
        aiFail("Report not found: {$reportPath}");
    }

    $report = json_decode(file_get_contents($reportPath), true);
    if (!is_array($report)) {
        aiFail("Invalid report JSON.");
    }

    $auditFacts = $report["auditFacts"]
        ?? buildAuditFacts($report);

    $date   = date("Y-m-d", $report["timestamp"] ?? time());
    $codexV = getCodexVersion();

    $basePrompt = <<<PROMPT
This is a pre-System Initialization Standard (SIS) audit narrative.
All findings are informational and non-binding.

Do NOT:
- Recommend actions
- Propose fixes
- Imply enforcement or persistence

Generate a concise narrative with:
1. Executive Summary
2. Key Facts (bulleted)
3. Findings Overview
4. Explicit Pre-SIS Caveat

Max 400 words. Professional tone.
Date: {$date}. Codex v{$codexV}.

Audit Facts (JSON):
{json_encode($auditFacts, JSON_PRETTY_PRINT)}
PROMPT;

    $response = callOpenAI(
        injectStandingOrders($basePrompt),
        $apiKey
    );

    if ($response) {
        $report["narrative"] = $response;
        file_put_contents(
            $reportPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        $narrativeGenerated = true;
    }
}
#endregion

#region SECTION 7 — Skyebot Mode
if ($type === "skyebot") {

    $query = $_GET["userQuery"] ?? ($argv[3] ?? null);
    if (!$query) {
        aiFail("userQuery required for skyebot mode.");
    }

    $prompt = "Skyebot response requested:\n{$query}\n\nPre-SIS posture.";
    $response = callOpenAI(
        injectStandingOrders($prompt),
        $apiKey
    );
}
#endregion

#region SECTION 8 — Output
// Log raw response for debugging
error_log('ASK_OPENAI RESPONSE RAW: ' . var_export($response, true));

// Fallback for empty responses
if (!isset($response) || trim($response) === '') {
    $response = "⚠ AI returned no usable response.";
}

// Final output
echo json_encode([
    "success"             => true,
    "role"                => "askOpenAI",
    "type"                => $type,
    "narrativeGenerated"  => $narrativeGenerated,
    "response"            => trim($response),
    "reportUpdated"       => $narrativeGenerated ? $reportPath : null
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Exit
exit;
#endregion