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

#region SECTION 0 — Fail Handler
skyesoftLoadEnv();
// Header
header("Content-Type: application/json; charset=UTF-8");
// AI Fail Function
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
function loadSemanticIntentPrompt(): string
{
    $root = dirname(__DIR__);
    $path = "$root/prompts/semanticIntent.prompt.md";

    if (!file_exists($path)) {
        error_log("[semantic-intent] PROMPT FILE NOT FOUND at $path");
        return "";
    }

    error_log("[semantic-intent] PROMPT FILE LOADED: $path");

    return trim(file_get_contents($path));
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

    $response = callOpenAI($fullPrompt, $apiKey, 'gpt-4.1');

    if (!$response) {
        return null;
    }

    $response = trim($response);

    if (in_array($response, ['Mr.', 'Ms.'], true)) {
        return $response;
    }

    return null;
}function loadSseSnapshot(): ?array
{
    $url = "https://www.skyelighting.com/skyesoft/api/sse.php?mode=snapshot";

    $context = stream_context_create([
        "http" => [
            "timeout" => 2
        ]
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!$raw) {
        return null;
    }

    // Strip "data: " prefix if present
    $raw = preg_replace('/^data:\s*/', '', trim($raw));

    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}function extractPermitContext(array $sse): string
{
    $kpi = $sse["kpi"]["atAGlance"] ?? [];
    $breakdown = $sse["kpi"]["statusBreakdown"] ?? [];

    return <<<TEXT
Operational Permit Snapshot (read-only, current):

- Total active permits: {$kpi["totalActive"]}
- Oldest outstanding: {$kpi["oldestOutstandingDays"]} days
- Average turnaround: {$kpi["averageTurnaroundDays"]} days

Status breakdown:
- Under review: {$breakdown["under_review"]}
- Corrections: {$breakdown["corrections"]}
- Ready to issue: {$breakdown["ready_to_issue"]}
- Issued: {$breakdown["issued"]}

Source: SSE snapshot (not persisted)
TEXT;
}function extractTimeContext(array $sse): string
{
    $time = $sse["timeDateArray"]["currentLocalTime"] ?? null;
    $date = $sse["timeDateArray"]["currentDate"] ?? null;

    if (!$time || !$date) {
        return "";
    }

    return <<<TEXT
Current system time (from SSE snapshot):
- Date: {$date}
- Local Time: {$time}

This information is current as of the snapshot and is read-only.
TEXT;
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
function injectSemanticIntentContext(string $basePrompt): string
{
    $semanticPrompt = loadSemanticIntentPrompt();

    if ($semanticPrompt === "") {
        return injectStandingOrders($basePrompt);
    }

    return injectStandingOrders(
        $semanticPrompt . "\n\n" . $basePrompt
    );
}
#endregion

#region SECTION 3 — OpenAI API Caller
function callOpenAI(
    string $prompt,
    ?string $apiKey,
    string $model = "gpt-4.1",
    ?array $responseFormat = null
): ?string {

    if (!$apiKey) {
        return null;
    }

    $ch = curl_init();

    /* TEMPORARY — confirm SSL is the only blocker */
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // Build payload (allows optional response_format)
    $payload = [
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
    ];

    // Optional: strict JSON schema / structured output enforcement
    if (is_array($responseFormat)) {
        $payload["response_format"] = $responseFormat;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.openai.com/v1/chat/completions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
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

$apiKey = skyesoftGetEnv("OPENAI_API_KEY");
if ($apiKey === null) {
    aiFail("OPENAI_API_KEY not available.");
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

#region SECTION 7 — Skyebot & Semantic Intent Modes

// ------------------------------------------------------
// SKYEBOT — Conversational / Informational Responses
// ------------------------------------------------------
if ($type === "skyebot") {

    $query = $_GET["userQuery"] ?? ($argv[3] ?? null);
    if (!$query) {
        aiFail("userQuery required for skyebot mode.");
    }

    // ======================================================
    // HANDOFF BOUNDARY
    // Semantic Intent (AI advisory) → Context Gating (PHP)
    // ======================================================

    // 1) Obtain semantic intent advisory (non-binding)
    $intentPrompt = <<<PROMPT
Analyze the following user input and return semantic intent metadata only.

User Input:
{$query}
PROMPT;

    $semanticIntentSchema = [
        "type" => "json_schema",
        "json_schema" => [
            "name" => "semantic_intent",
            "schema" => [
                "type" => "object",
                "additionalProperties" => false,
                "required" => ["intent", "confidence", "reasoning"],
                "properties" => [
                    "intent" => ["type" => "string"],
                    "confidence" => [
                        "type" => "number",
                        "minimum" => 0,
                        "maximum" => 1
                    ],
                    "reasoning" => ["type" => "string"]
                ]
            ]
        ]
    ];

    $intentRaw = callOpenAI(
        injectSemanticIntentContext($intentPrompt),
        $apiKey,
        "gpt-4.1",
        $semanticIntentSchema
    );

    $intentMeta = json_decode($intentRaw ?? "", true);
    if (!is_array($intentMeta)) {
        $intentMeta = [
            "intent" => "uncertain",
            "confidence" => 0.0,
            "reasoning" => "Intent could not be reliably inferred."
        ];
    }

    // Audit log (advisory only)
    error_log("[skyebot:intent] " . json_encode([
        "intent" => $intentMeta["intent"],
        "confidence" => $intentMeta["confidence"]
    ], JSON_UNESCAPED_SLASHES));

    // 2) PHP decides which SSE context (if any) is allowed
    $sse = loadSseSnapshot();
    $contextBlocks = [];

    // Minimal, conservative rule:
    // SSE context is NOT assumed — only injected if clearly relevant
    if ($sse && ($intentMeta["confidence"] ?? 0) >= 0.80) {

        switch ($intentMeta["intent"]) {

            case "time_query":
                $contextBlocks[] = extractTimeContext($sse);
                break;

            case "permit_status":
            case "permit_overview":
                $contextBlocks[] = extractPermitContext($sse);
                break;

            // interaction / UI / conversational intents get no SSE
            default:
                // no context injected
                break;
        }
    }

    $contextText = implode("\n\n", array_filter($contextBlocks));

    // ======================================================
    // Final Skyebot Prompt (bounded, authorized inputs only)
    // ======================================================

    $basePrompt = <<<PROMPT
Skyebot response requested.

{$contextText}

User Input:
{$query}

Pre-SIS posture.
- Respond conversationally and clearly.
- Do NOT invent facts.
- If required context is missing, say so explicitly.
- Do NOT imply persistence, execution, or authority.
PROMPT;

    $response = callOpenAI(
        injectStandingOrders($basePrompt),
        $apiKey
    );
}


// ------------------------------------------------------
// SEMANTIC INTENT — Non-Binding Advisory Interpretation
// ------------------------------------------------------
if ($type === "semantic_intent") {

    $query = $_GET["userQuery"] ?? ($argv[3] ?? null);
    if (!$query) {
        aiFail("userQuery required for semantic intent mode.");
    }

    $basePrompt = <<<PROMPT
Analyze the following user input and return semantic intent metadata only.

User Input:
{$query}
PROMPT;

    $semanticIntentSchema = [
        "type" => "json_schema",
        "json_schema" => [
            "name" => "semantic_intent",
            "schema" => [
                "type" => "object",
                "additionalProperties" => false,
                "required" => ["intent", "confidence", "reasoning"],
                "properties" => [
                    "intent" => ["type" => "string"],
                    "confidence" => [
                        "type" => "number",
                        "minimum" => 0,
                        "maximum" => 1
                    ],
                    "reasoning" => ["type" => "string"]
                ]
            ]
        ]
    ];

    $response = callOpenAI(
        injectSemanticIntentContext($basePrompt),
        $apiKey,
        "gpt-4.1",
        $semanticIntentSchema
    );

    $decoded = json_decode($response ?? "", true);
    if (!is_array($decoded)) {
        aiFail("Semantic intent response was not valid JSON.");
    }

    $response = json_encode(
        $decoded,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
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