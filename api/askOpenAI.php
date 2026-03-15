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

#region SECTION 0 — Environment Bootstrap

ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");

// ─────────────────────────────────────────
// SESSION COOKIE POLICY
// Must match auth.php / sse.php exactly
// ─────────────────────────────────────────

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '.skyelighting.com',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Attach existing browser session if present
$cookieName = session_name();

if (isset($_COOKIE[$cookieName])) {
    session_id($_COOKIE[$cookieName]);
}

// Load environment
skyesoftLoadEnv();

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

// Loads .env from secure location (3 levels up) → putenv, $_ENV, $_SERVER
function skyesoftLoadEnv(): void {

    $envPath = dirname(__DIR__, 3) . '/secure/.env';

    // Validate .env existence
    if (!file_exists($envPath) || !is_readable($envPath)) {
        error_log("[env-loader] FAILED to load .env at $envPath");
        return;
    }

    // Parse file line-by-line
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {

        $line = trim($line);

        // Skip comments / invalid lines
        if (
            $line === '' ||
            str_starts_with($line, '#') ||
            !str_contains($line, '=')
        ) {
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

// Safe env var reader — checks getenv → $_ENV → $_SERVER
function skyesoftGetEnv(string $key): ?string {
    $val = getenv($key);
    if ($val !== false && trim($val) !== '') return trim($val);
    if (!empty($_ENV[$key])) return trim($_ENV[$key]);
    if (!empty($_SERVER[$key])) return trim($_SERVER[$key]);
    return null;
}

// Load Standing Orders from codex.json (injected into all prompts) — fallback to empty JSON object
function loadStandingOrders(): string {

    $root      = dirname(__DIR__);
    $codexPath = "$root/codex/codex.json";

    // Validate codex file existence
    if (!file_exists($codexPath)) {
        return "{}";
    }

    $codex = json_decode(file_get_contents($codexPath), true);

    // Validate codex structure
    if (
        !is_array($codex) ||
        !isset($codex["meta"]["standingOrders"])
    ) {
        return "{}";
    }

    return json_encode(
        $codex["meta"]["standingOrders"],
        JSON_UNESCAPED_SLASHES
    );
}

// Loads semantic intent classification prompt markdown
function loadSemanticIntentPrompt(): string {
    $root = dirname(__DIR__);
    $path = "$root/codex/prompts/semanticIntent.prompt.md";

    if (!file_exists($path)) {
        error_log("[semantic-intent] PROMPT FILE NOT FOUND at $path");
        return "";
    }

    error_log("[semantic-intent] PROMPT FILE LOADED: $path");
    return trim(file_get_contents($path));
}

// Loads final response generation prompt markdown
function loadResponseGenerationPrompt(): string {
    $root = dirname(__DIR__);
    $path = "$root/codex/prompts/responseGeneration.prompt.md";

    if (!file_exists($path)) {
        error_log("[response-generation] PROMPT FILE NOT FOUND at $path");
        return "";
    }

    error_log("[response-generation] PROMPT FILE LOADED: $path");
    return trim(file_get_contents($path));
}

// Get Codex Version
function getCodexVersion(): string {

    $root      = dirname(__DIR__);
    $codexPath = "$root/codex/codex.json";

    // Validate codex file existence
    if (!file_exists($codexPath)) {
        return "pending";
    }

    $codex = json_decode(file_get_contents($codexPath), true);

    // Validate structure before reading version
    if (!is_array($codex)) {
        return "pending";
    }

    return (string)(
        $codex["meta"]["version"]
        ?? $codex["version"]
        ?? "pending"
    );
}

// Load Unresolved Structural Violations from latest audit (Merkle + inventory)
function loadUnresolvedStructuralViolations(): ?array {

    $auditFile = __DIR__ . '/../data/records/auditResults.json';

    if (!file_exists($auditFile)) {
        return null;
    }

    $json = json_decode((string)file_get_contents($auditFile), true);

    if (!is_array($json) || !isset($json['violations']) || !is_array($json['violations'])) {
        return null;
    }

    $summary = [
        "merkleIntegrity"   => false,
        "declaredMissing"   => [],
        "unexpectedPresent" => []
    ];

    foreach ($json['violations'] as $violation) {

        // Skip resolved violations
        if (!empty($violation['resolved'])) {
            continue;
        }

        $observation = $violation['observation'] ?? '';

        if (!is_string($observation) || $observation === '') {
            continue;
        }

        // ---- Merkle ----
        if (stripos($observation, 'Merkle') !== false) {
            $summary['merkleIntegrity'] = true;
            continue;
        }

        // ---- Inventory ----
        if (stripos($observation, 'Repository inventory') !== false) {

            // Declared but missing
            if (preg_match("/declared (file|dir) '([^']+)' is missing/i", $observation, $m)) {
                $summary['declaredMissing'][] = $m[2];
                continue;
            }

            // Unexpected but present
            if (preg_match("/unexpected (file|dir) '([^']+)' exists/i", $observation, $m)) {
                $summary['unexpectedPresent'][] = $m[2];
                continue;
            }
        }
    }

    // Normalize duplicates (defensive)
    $summary['declaredMissing']   = array_values(array_unique($summary['declaredMissing']));
    $summary['unexpectedPresent'] = array_values(array_unique($summary['unexpectedPresent']));

    return $summary;
}

// Infer Salutation
function inferSalutation(string $firstName, string $lastName): ?string {

    $basePrompt = <<<PROMPT
Given the name "{$firstName} {$lastName}", infer the most likely professional salutation for business correspondence.

Respond with ONLY "Mr." or "Ms." — nothing else.
PROMPT;

    $fullPrompt = injectStandingOrders($basePrompt);

    $apiKey = skyesoftGetEnv("OPENAI_API_KEY");

    // Validate API key
    if ($apiKey === null) {
        aiFail("OPENAI_API_KEY not available in PHP environment.");
    }

    $response = callOpenAI($fullPrompt, $apiKey, 'gpt-4.1');

    if (!$response) {
        return null;
    }

    $response = trim($response);

    // Strict response validation
    if (in_array($response, ['Mr.', 'Ms.'], true)) {
        return $response;
    }

    return null;
}

// Load SSE Snapshot
function loadSseSnapshot(): ?array {

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

    // Strip optional SSE "data: " prefix
    $raw = preg_replace('/^data:\s*/', '', trim($raw));

    $json = json_decode($raw, true);

    return is_array($json) ? $json : null;
}

// Extract Permit Context
function extractPermitContext(array $sse): string {

    $kpi       = $sse["kpi"]["atAGlance"] ?? [];
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
}

// Extracts current date/time from SSE snapshot
function extractTimeContext(array $sse): string {

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

// Append Prompt Ledger Entry (non-blocking, best-effort) — creates ledger if missing, appends entry with sequential ID, updates meta timestamp
function appendPromptLedgerEntry(array $entry): void {

    $root       = dirname(__DIR__);
    $ledgerPath = "$root/reports/promptLedger.json";

    // Create ledger if missing
    if (!file_exists($ledgerPath)) {

        $initial = [
            "meta" => [
                "objectType"    => "promptLedger",
                "schemaVersion" => "1.0.0",
                "codexTier"     => 2,
                "createdAt"     => time(),
                "lastUpdatedAt" => time()
            ],
            "entries" => []
        ];

        file_put_contents(
            $ledgerPath,
            json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    $ledger = json_decode(file_get_contents($ledgerPath), true);

    if (!is_array($ledger) || !isset($ledger["entries"])) {
        error_log("[prompt-ledger] Invalid ledger structure");
        return;
    }

    $count = count($ledger["entries"]) + 1;

    $entry["promptId"]        = sprintf("PRL-%06d", $count);
    $entry["createdUnixTime"] = $entry["createdUnixTime"] ?? time();

    $ledger["entries"][] = $entry;
    $ledger["meta"]["lastUpdatedAt"] = time();

    file_put_contents(
        $ledgerPath,
        json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

// Load Runtome Domain Registry Keys (Authoritative list of valid domains for intent classification)
function loadRuntimeDomainRegistryKeys(): array {

    $root = dirname(__DIR__);
    $path = $root . "/data/authoritative/runtimeDomainRegistry.json";

    if (!file_exists($path)) {
        error_log("[runtime-domain-registry] NOT FOUND: $path");
        return [];
    }

    $json = json_decode((string)file_get_contents($path), true);

    if (!is_array($json)) {
        error_log("[runtime-domain-registry] INVALID JSON");
        return [];
    }

    $domains = $json["domains"] ?? null;

    if (!is_array($domains)) {
        return [];
    }

    return array_values(
        array_filter(
            array_keys($domains),
            fn ($k) => is_string($k) && $k !== ""
        )
    );
}

// Returns formatted governance/remediation action URL (or empty string)
function formatGovernanceActionLink(string $action): string{
    $map = [
        "run_inventory_builder"    => "https://skyelighting.com/skyesoft/api/repositoryInventoryBuilder.php?mode=reconcile",
        "run_merkle_builder"       => "https://skyelighting.com/skyesoft/api/merkleBuilder.php?mode=accept",
        "review_unexpected_files"  => "https://skyelighting.com/skyesoft/api/violationActionResolver.php"
    ];

    return $map[$action] ?? '';
}

// Build Governance Surface Summary (for AI injection and developer visibility) based on unresolved structural violations — includes Merkle integrity status, inventory deviation details, and actionable next steps for developers.
function buildGovernanceSurface(?array $summary): string {

    if ($summary === null) {
        return "🧭 Structural State\n\nNo audit data available.";
    }

    $hasMerkle      = $summary['merkleIntegrity'] ?? false;
    $declaredMissing = $summary['declaredMissing'] ?? [];
    $unexpected      = $summary['unexpectedPresent'] ?? [];

    $intentional = [];
    $runtime     = [];

    foreach ($unexpected as $path) {
        if (
            str_starts_with($path, '/data/runtimeEphemeral') ||
            str_starts_with($path, '/scripts/') ||
            str_starts_with($path, '/tools/')
        ) {
            $runtime[] = $path;
        } else {
            $intentional[] = $path;
        }
    }

    // If everything is clean
    if (!$hasMerkle && empty($declaredMissing) && empty($intentional) && empty($runtime)) {
        return "🧭 Structural State\n\nNo structural deviations detected.\n\nAll integrity domains are verified.";
    }

    $output  = "🧭 Current Structural State\n\n";

    // --------------------------------------------------
    // Merkle Section (only if violated)
    // --------------------------------------------------
    if ($hasMerkle) {
        $output .= "1️⃣ Merkle Deviation\n\n";
        $output .= "Status: Baseline Mismatch\n\n";
        $output .= "The current Codex state does not match the last accepted Merkle snapshot.\n\n";
        $output .= "The governed structural baseline has changed and requires developer confirmation.\n\n";
    }

    // --------------------------------------------------
    // Inventory Section (only if anything exists)
    // --------------------------------------------------
    if (!empty($declaredMissing) || !empty($intentional) || !empty($runtime)) {

        $output .= "2️⃣ Repository Inventory Deviations\n\n";

        // A) Declared but Missing
        if (!empty($declaredMissing)) {
            $output .= "A) Declared but Missing\n\n";
            foreach ($declaredMissing as $path) {
                $output .= "{$path}\n";
            }
            $output .= "\nThese items are defined as canonical but are not currently present.\n\n";
        }

        // B) Intentional
        if (!empty($intentional)) {
            $output .= "B) Unexpected but Present (Intentional Structure)\n\n";
            foreach ($intentional as $path) {
                $output .= "{$path}\n";
            }
            $output .= "\nThese appear to be intentional structural additions and likely require inventory reconciliation.\n\n";
        }

        // C) Runtime
        if (!empty($runtime)) {
            $output .= "C) Unexpected but Present (Runtime / Development Artifacts)\n\n";
            foreach ($runtime as $path) {
                $output .= "{$path}\n";
            }
            $output .= "\nThese may require exclusion rules rather than reconciliation.\n\n";
        }
    }

    return trim($output);
}
// Build Governance Response HTML (for AI injection and developer visibility) based on unresolved structural violations — includes Merkle integrity status, inventory deviation details, and actionable next steps for developers with direct links to remediation actions.
function buildGovernanceResponse(): string {

    $summary = loadUnresolvedStructuralViolations();
    $surface = buildGovernanceSurface($summary);

    if ($summary === null) {
        return "<div class='gov-box'>{$surface}</div>";
    }

    $hasMerkle    = $summary['merkleIntegrity'] ?? false;
    $hasInventory = !empty($summary['declaredMissing']) || !empty($summary['unexpectedPresent']);

    $actions = [];

    if ($hasMerkle) {
        $actions[] = [
            "label"  => "Accept New Merkle Snapshot",
            "action" => "accept_merkle"
        ];
    }

    if ($hasInventory) {
        $actions[] = [
            "label"  => "Reconcile Repository Inventory",
            "action" => "reconcile_inventory"
        ];
    }

    if (!empty($summary['unexpectedPresent'])) {
        $actions[] = [
            "label"  => "Review Unexpected Files",
            "action" => "review_unexpected"
        ];
    }

    $html  = "<div class='gov-box'>";
    $html .= "<pre>" . htmlspecialchars($surface) . "</pre>";

    if (!empty($actions)) {
        $html .= "<div class='gov-actions'>";
        $html .= "<h3>Remediation Options</h3>";

        foreach ($actions as $action) {
            $html .= "<button type='button' class='gov-btn' data-action='"
                  . htmlspecialchars($action['action'])
                  . "'>";
            $html .= htmlspecialchars($action['label']);
            $html .= "</button>";
        }

        $html .= "</div>";
    }

    $html .= "</div>";

    return $html;
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

#region SECTION 3 — OpenAI API Caller (Stream Context)
function callOpenAI(
    string $prompt,
    ?string $apiKey,
    string $model = "gpt-4.1",
    ?array $responseFormat = null
): ?string {

    if (!$apiKey) {
        return null;
    }

    $url = "https://api.openai.com/v1/chat/completions";

    $payload = [
        "model" => $model,
        "messages" => [
            [
                "role"    => "system",
                "content" => injectStandingOrders("You are a precise, Codex-aligned assistant.")
            ],
            [
                "role"    => "user",
                "content" => $prompt
            ]
        ],
        "max_tokens"  => 600,
        "temperature" => 0.1
    ];

    if (is_array($responseFormat)) {
        $payload["response_format"] = $responseFormat;
    }

    $context = stream_context_create([
        "http" => [
            "method"        => "POST",
            "header"        => implode("\r\n", [
                "Content-Type: application/json",
                "Authorization: Bearer {$apiKey}"
            ]),
            "content"       => json_encode($payload),
            "timeout"       => 30,
            "ignore_errors" => true // lets us read non-200 bodies
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    $statusLine = $http_response_header[0] ?? "no-status";
    $is200 = (strpos($statusLine, " 200 ") !== false);

    if (!$response || !$is200) {

        error_log("[askOpenAI:callOpenAI] OpenAI request failed " . json_encode([
            "httpStatus" => $statusLine,
            "hasBody"    => (bool)$response
        ], JSON_UNESCAPED_SLASHES));

        return null;
    }

    $json = json_decode($response, true);

    if (!is_array($json)) {
        error_log("[askOpenAI:callOpenAI] invalid JSON response");
        return null;
    }

    return trim((string)($json["choices"][0]["message"]["content"] ?? ""));
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

#region SECTION 7 — Skyebot (Authority-Aware, Deterministic)

if ($type === "skyebot") {

    $query = $_GET["userQuery"] ?? ($argv[3] ?? null);
    if (!$query) {
        aiFail("userQuery required for skyebot mode.");
    }

    // Defaults (centralized output model)
    $role = "askOpenAI";
    $narrativeGenerated = false;
    $reportPath = null;

    // ─────────────────────────────────────────────
    // 1. Load Runtime Domain Registry
    // ─────────────────────────────────────────────

    $streamedDomains = loadRuntimeDomainRegistryKeys();
    $allowedDomainsList = !empty($streamedDomains)
        ? implode(", ", $streamedDomains)
        : "none";

    // ─────────────────────────────────────────────
    // 2. Semantic Intent
    // ─────────────────────────────────────────────

    $intentPrompt = <<<PROMPT
Analyze the following user input and return semantic intent metadata only.

Canonical domain intent grammar is allowed ONLY if the domain is in this allowed list:
{$allowedDomainsList}

If the user request maps to a domain NOT in the allowed list, return a non-domain intent.

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
                    "confidence" => ["type" => "number"],
                    "reasoning" => ["type" => "string"]
                ]
            ]
        ]
    ];

    $intentRaw = callOpenAI(
        injectSemanticIntentContext($intentPrompt),
        $apiKey,
        "gpt-4o-mini",
        $semanticIntentSchema
    );

    $intentMeta = json_decode($intentRaw ?? "", true);

    if (!is_array($intentMeta)) {
        $intentMeta = [
            "intent" => "uncertain",
            "confidence" => 0.0
        ];
    }

    $intent     = $intentMeta["intent"] ?? "unknown";
    $confidence = (float)($intentMeta["confidence"] ?? 0.0);

    // ─────────────────────────────────────────────
    // 3. UI ACTIONS
    // ─────────────────────────────────────────────

    if ($intent === "ui_clear" && $confidence >= 0.80) {
        $type = "ui_action";
        $response = "clear_screen";
        goto SKY_OUTPUT;
    }

    if ($intent === "ui_logout" && $confidence >= 0.90) {
        $type = "ui_action";
        $response = "logout";
        goto SKY_OUTPUT;
    }

    // ─────────────────────────────────────────────
    // 4. DOMAIN SHORT-CIRCUIT
    // ─────────────────────────────────────────────

    if (
        $confidence >= 0.70 &&
        preg_match('/^([a-z]+)_(inquiry|repair_request|execute|amendment_request)$/', $intent, $m)
    ) {

        $domainKey = $m[1];
        $mode      = $m[2];

        if (in_array($domainKey, $streamedDomains, true)) {

            $type = "domain_intent";
            $response = json_encode([
                "domain"     => $domainKey,
                "mode"       => $mode,
                "confidence" => $confidence
            ], JSON_UNESCAPED_SLASHES);

            goto SKY_OUTPUT;
        }
    }

    // ─────────────────────────────────────────────
    // 5. GOVERNANCE SHORT-CIRCUIT
    // ─────────────────────────────────────────────

    $lowerQuery = strtolower($query);

    if (
        str_contains($lowerQuery, "deviation") ||
        str_contains($lowerQuery, "violation") ||
        str_contains($lowerQuery, "structural")
    ) {

        $role = "governance";
        $type = "structural_state";
        $response = buildGovernanceResponse();

        goto SKY_OUTPUT;
    }

    // ─────────────────────────────────────────────
    // 6. Conversational Fallback
    // ─────────────────────────────────────────────

    $sseSnapshot = loadSseSnapshot();

    $authoritativeContext = $sseSnapshot
        ? json_encode($sseSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : "No authoritative context available.";

    $responsePrompt = loadResponseGenerationPrompt();
    if ($responsePrompt === "") {
        aiFail("Response generation prompt not available.");
    }

    $basePrompt = <<<PROMPT
{$responsePrompt}

Authoritative Context (read-only):
{$authoritativeContext}

User Input:
{$query}
PROMPT;

    $response = callOpenAI(
        injectStandingOrders($basePrompt),
        $apiKey
    );

    $type = "skyebot";

    SKY_OUTPUT:
}
#endregion

#region SECTION 8 — Output

// Ensure response exists
if (!isset($response) || trim((string)$response) === '') {
    $response = "⚠ AI returned no usable response.";
}

// Safe logging only
error_log('ASK_OPENAI RESPONSE RAW: ' . substr((string)$response, 0, 500));

// ------------------------------------------------
// Session Activity Heartbeat
// ------------------------------------------------

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionUserId = $_SESSION["userId"] ?? null;

if (!empty($_SESSION['authenticated'])) {
    $_SESSION['lastActivity'] = time();
}

session_write_close();

// ------------------------------------------------
// Prompt Ledger (Non-blocking)
// ------------------------------------------------

if (isset($query) && isset($response)) {

    try {

        $root = dirname(__DIR__);
        $ledgerPath = $root . "/data/authoritative/promptLedger.json";

        if (file_exists($ledgerPath)) {

            $ledgerData = json_decode(file_get_contents($ledgerPath), true);

            if (is_array($ledgerData) && isset($ledgerData["entries"])) {

                $nextNumber = count($ledgerData["entries"]) + 1;

                $ledgerData["entries"][] = [
                    "promptId"         => sprintf("PRL-%06d", $nextNumber),
                    "userId"           => $sessionUserId,
                    "promptText"       => $query,
                    "responseText"     => trim($response),
                    "intent"           => $intent ?? "unknown",
                    "intentConfidence" => $confidence ?? null,
                    "createdUnixTime"  => time()
                ];

                $ledgerData["meta"]["lastUpdatedUnixTime"] = time();

                file_put_contents(
                    $ledgerPath,
                    json_encode($ledgerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                );
            }
        }

    } catch (Throwable $e) {
        error_log("[prompt-ledger] append failed: " . $e->getMessage());
    }
}

// ------------------------------------------------
// Final JSON Output (Single Authority)
// ------------------------------------------------

echo json_encode([
    "success"            => true,
    "role"               => $role ?? "askOpenAI",
    "type"               => $type ?? "skyebot",
    "narrativeGenerated" => $narrativeGenerated ?? false,
    "response"           => trim((string)$response),
    "reportUpdated"      => $reportPath ?? null
], JSON_UNESCAPED_SLASHES);

exit;

#endregion