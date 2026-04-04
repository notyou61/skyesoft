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

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php-error.log');

header("Content-Type: application/json; charset=UTF-8");

// ─────────────────────────────────────────
// 🔐 SESSION BOOTSTRAP (CANONICAL)
// SINGLE SOURCE OF TRUTH
// ─────────────────────────────────────────
require_once __DIR__ . '/sessionBootstrap.php';

// ─────────────────────────────────────────
// 🌍 Load environment
// ─────────────────────────────────────────
skyesoftLoadEnv();

// ─────────────────────────────────────────
// 🤖 AI Fail Function
// ─────────────────────────────────────────
function aiFail(string $msg): never {
    echo json_encode([
        "success" => false,
        "role"    => "askOpenAI",
        "error"   => "❌ $msg"
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// ─────────────────────────────────────────
// 📌 Action Origins
// ─────────────────────────────────────────
const ACTION_ORIGIN_USER       = 1;
const ACTION_ORIGIN_SYSTEM     = 2;
const ACTION_ORIGIN_AUTOMATION = 3;

// ─────────────────────────────────────────
// 🗄️ DB Connection
// ─────────────────────────────────────────
$dbUser = skyesoftGetEnv("DB_USER");
$dbPass = skyesoftGetEnv("DB_PASS");
$dbHost = skyesoftGetEnv("DB_HOST") ?? "localhost";
$dbName = skyesoftGetEnv("DB_NAME") ?? "skyesoft";

if (!$dbUser || !$dbPass) {
    error_log('[env] DB credentials missing');
    aiFail("Database configuration error.");
}

try {
    $db = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => true
        ]
    );

    if (skyesoftGetEnv("APP_ENV") === "local") {
        error_log('[db] connection established');
    }

} catch (Throwable $e) {
    error_log("[db] connection failed: " . $e->getMessage());
    aiFail("Database connection failed.");
}

// ─────────────────────────────────────────
// ⚙️ Actions Layer (Execution + Logging)
// ─────────────────────────────────────────
require_once __DIR__ . '/utils/actions.php';

#endregion

#region SECTION 1 — Codex Loaders (Standing Orders + Version)

// Loads .env from secure location (3 levels up) → putenv, $_ENV, $_SERVER
function skyesoftLoadEnv(): void {

    // #region Resolve paths

    $basePath = dirname(__DIR__, 3) . '/secure';

    $envFiles = [
        $basePath . '/.env',    // general / OpenAI
        $basePath . '/db.env'   // database
    ];

    // #endregion


    // #region Load each env file

    foreach ($envFiles as $envPath) {

        if (!file_exists($envPath) || !is_readable($envPath)) {
            error_log("[env-loader] Skipped missing: $envPath");
            continue;
        }

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

            // Set into environment
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        error_log("[env-loader] Loaded: $envPath");
    }

    // #endregion
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

    // #region Extract + Safe Defaults

    $kpi       = $sse["kpi"]["atAGlance"] ?? [];
    $breakdown = $sse["kpi"]["statusBreakdown"] ?? [];

    $totalActive       = $kpi["totalActive"] ?? 0;
    $oldestOutstanding = $kpi["oldestOutstandingDays"] ?? 0;
    $avgTurnaround     = $kpi["averageTurnaroundDays"] ?? 0;

    $underReview = $breakdown["under_review"] ?? 0;
    $corrections = $breakdown["corrections"] ?? 0;
    $ready       = $breakdown["ready_to_issue"] ?? 0;
    $issued      = $breakdown["issued"] ?? 0;

    // #endregion

    // #region Render Output

    return <<<TEXT
Operational Permit Snapshot (read-only, current):

- Total active permits: {$totalActive}
- Oldest outstanding: {$oldestOutstanding} days
- Average turnaround: {$avgTurnaround} days

Status breakdown:
- Under review: {$underReview}
- Corrections: {$corrections}
- Ready to issue: {$ready}
- Issued: {$issued}

Source: SSE snapshot (not persisted)
TEXT;

    // #endregion
}

// Extracts current date/time from SSE snapshot
function extractTimeContext(array $sse): string {

    $time = $sse["timeDateArray"]["currentLocalTime"] ?? null;
    $date = $sse["timeDateArray"]["currentDate"] ?? null;

    if (empty($time) || empty($date)) {
        return "";
    }

    return <<<TEXT
Current system time (from SSE snapshot):
- Date: {$date}
- Local Time: {$time}

This information is current as of the snapshot and is read-only.
TEXT;
}

// Load Runtime Domain Registry Keys (Authoritative list of valid domains for intent classification)
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
// Discover Domains from payload (for dynamic intent classification) — excludes known system/meta fields and returns a clean list of candidate domains for AI processing.
function discoverDomains(array $payload): array {

    // exclude system/meta fields
    $exclude = ["auth", "idle", "streamId", "sessionId", "forceLogout"];

    return array_values(array_filter(
        array_keys($payload),
        fn($key) => !in_array($key, $exclude, true)
    ));
}
// Load recent user/system actions (for context and potential audit) — retrieves the latest 25 actions of type 'user' or 'system' from the database, ordered by most recent, and returns them as an associative array.
function loadRecentActions(int $limit = 25): array {

    try {
        $pdo = getPDO();

        $stmt = $pdo->prepare("
            SELECT promptText, intent, actionUnix
            FROM tblActions
            WHERE actionTypeId = 3
            ORDER BY actionUnix DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log("[DB Actions Error] " . $e->getMessage());
        return [];
    }
}
// Build Authoritative System Context from SSE snapshot + activity
function buildSystemContext(?array $sse): string {

    if (!$sse) {
        return json_encode([
            "status" => "no_data",
            "message" => "No SSE snapshot available"
        ]);
    }

    // ─────────────────────────────────────────
    // 🔍 Discover Domains (dynamic, no hardcoding)
    // ─────────────────────────────────────────
    $exclude = ["auth", "idle", "streamId", "sessionId", "forceLogout"];

    $domains = array_values(array_filter(
        array_keys($sse),
        fn($key) => !in_array($key, $exclude, true)
    ));

    // ─────────────────────────────────────────
    // 🎯 Priority Context (light anchors only)
    // ─────────────────────────────────────────
    $priority = [
        "time"    => $sse["timeDateArray"] ?? null,
        "holiday" => $sse["holidayState"] ?? null
    ];

    // ─────────────────────────────────────────
    // 📊 Load Recent Actions (behavior layer)
    // NOTE: no transformation — raw exposure
    // ─────────────────────────────────────────
    $actions = [];

    try {
        $pdo = getPDO();

        $stmt = $pdo->prepare("
            SELECT promptText, intent, actionUnix
            FROM tblActions
            WHERE actionTypeId = 3
            ORDER BY actionUnix DESC
            LIMIT 200
        ");

        $stmt->execute();
        $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        error_log("[buildSystemContext actions error] " . $e->getMessage());
    }

    // ─────────────────────────────────────────
    // 📦 Unified Context (state + behavior)
    // ─────────────────────────────────────────
    $context = [
        "priority" => $priority,
        "domains"  => $sse,
        "activity" => [
            "recentActions" => $actions
        ],
        "meta" => [
            "source" => "SSE snapshot",
            "readOnly" => true,
            "schema" => "dynamic",
            "availableDomains" => $domains
        ]
    ];

    return json_encode(
        $context,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
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

    // ✅ Safe JSON encoding
    $encodedPayload = json_encode($payload);
    if ($encodedPayload === false) {
        error_log("[askOpenAI] JSON encode failed");
        return null;
    }

    $context = stream_context_create([
        "http" => [
            "method"        => "POST",
            "header"        => [
                "Content-Type: application/json",
                "Authorization: Bearer {$apiKey}"
            ],
            "content"       => $encodedPayload,
            "timeout"       => 30,
            "ignore_errors" => true
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    $statusLine = isset($http_response_header[0])
        ? $http_response_header[0]
        : "no-status";

    // ✅ FIXED
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

// Prevent controller execution when included internally (test harness, CLI, etc.)
if (
    (defined('SKYESOFT_INTERNAL') && SKYESOFT_INTERNAL) ||
    (defined('SKYESOFT_LIB_MODE') && SKYESOFT_LIB_MODE)
) {
    return;
}

// 🌍 JSON Input Intake (POST body support)

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = [];
}

#endregion

#region SECTION 5 — Input Resolution
$intent     = null;
$confidence = null;
$query      = null;

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
    error_log('[askOpenAI] AI flag missing — defaulting to enabled');
    $aiFlag = true;
}
#endregion

#region SECTION 6 — Narrative Generation

$response           = null;
$narrativeGenerated = false;
$reportPath         = null;
$role               = "askOpenAI";

if ($type === "narrative") {

    // 🧾 Resolve task input
    $task = $_GET["task"] ?? ($argv[3] ?? null);
    if (!$task) {
        aiFail("task required for narrative generation.");
    }

    $reportPath = "$root/reports/automation/{$task}.json";

    if (!file_exists($reportPath)) {
        aiFail("Report not found: {$reportPath}");
    }

    // 📥 Load report
    $report = json_decode(file_get_contents($reportPath), true);
    if (!is_array($report)) {
        aiFail("Invalid report JSON.");
    }

    // 🧠 Build audit facts
    $auditFacts = $report["auditFacts"]
        ?? buildAuditFacts($report);

    $auditFactsJson = json_encode(
        $auditFacts,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    // 🗓 Metadata
    $date   = date("Y-m-d", $report["timestamp"] ?? time());
    $codexV = getCodexVersion();

    // 🤖 Prompt Construction
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
{$auditFactsJson}
PROMPT;

    // 🚀 AI Execution
    $response = callOpenAI(
        injectStandingOrders($basePrompt),
        $apiKey
    );

    // 💾 Persist Narrative

    if ($response) {

        $report["narrative"] = trim($response);

        file_put_contents(
            $reportPath,
            json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );

        $narrativeGenerated = true;
    }

}

#endregion

#region SECTION 7 — Skyebot (Authority-Aware, Deterministic)

if ($type === "skyebot") {

    // Query
    $query =
    $input["userQuery"]
    ?? $_GET["userQuery"]
    ?? ($argv[3] ?? null);
    
    // Query Conditional
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

    $execution = executeIntent($intent, $confidence);

    if ($execution) {
        $type     = $execution['type'];
        $response = $execution['response'];
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

    $responsePrompt = loadResponseGenerationPrompt();
    if ($responsePrompt === "") {
        aiFail("Response generation prompt not available.");
    }

$systemContext = buildSystemContext($sseSnapshot);

$basePrompt = <<<PROMPT
{$responsePrompt}

You are operating with real-time system data.

Guidelines:
- Prefer "priority" data when available
- Use "extended" only if needed
- If required data is missing, explicitly say so
- Do NOT infer values not present in SYSTEM DATA

SYSTEM DATA (JSON):
{$systemContext}

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
    
    error_log('[askOpenAI] EMPTY AI RESPONSE — forcing fallback');

    $response = "I'm here and ready — try asking that again.";
}

// Safe logging only (mbstring-safe)
$preview = function_exists('mb_substr')
    ? mb_substr((string)$response, 0, 300)
    : substr((string)$response, 0, 300);

error_log('ASK_OPENAI RESPONSE RAW: ' . json_encode([
    'preview' => $preview
]));

// ------------------------------------------------
// Session Activity Heartbeat
// ------------------------------------------------

$sessionContactId = $_SESSION["contactId"] ?? null;

if (!empty($_SESSION['authenticated'])) {
    $_SESSION['lastActivity'] = time();
}

session_write_close();

// ------------------------------------------------
// Action Logging (Authoritative - tblActions)
// ------------------------------------------------

// 🌍 Geo Context
$latitude  = is_numeric($input['latitude'] ?? null) ? (float)$input['latitude'] : null;
$longitude = is_numeric($input['longitude'] ?? null) ? (float)$input['longitude'] : null;
// Session Contact ID
$sessionContactId = $_SESSION["contactId"] ?? null;
//
error_log('[SESSION DEBUG] ' . json_encode($_SESSION));
// Session Contact ID Conditional
if ($sessionContactId && isset($response)) {

    try {
        // Insert Action Prompt
        insertActionPrompt([
            "contactId" => $sessionContactId,
            "promptText" => $query ?? '[system:narrative]',
            "responseText" => trim($response),
            "intent" => $intent ?? "unknown",
            "intentConfidence" => $confidence ?? null,
            "createdUnixTime" => time(),
            "latitude" => $latitude,
            "longitude" => $longitude

        ], $db);

    } catch (Throwable $e) {
        error_log("[actions] insert failed: " . $e->getMessage());
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