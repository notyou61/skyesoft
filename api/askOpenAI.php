<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — askOpenAI.php
//  Version: 1.3.3
//  Last Updated: 2026-04-30
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

// Ensure logs directory exists
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

$logFile = $logDir . '/php-error.log';
ini_set('error_log', $logFile);

error_log("=== askOpenAI.php LOADED SUCCESSFULLY ===");
error_log("Current time: " . date('Y-m-d H:i:s'));

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
if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/utils/envLoader.php';
}
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
// 🗄️ DB Connection (Single Source of Truth)
// ─────────────────────────────────────────
require_once __DIR__ . '/dbConnect.php';

if (!function_exists('getPDO')) {
    error_log('[bootstrap-error] getPDO not available after dbConnect load');
    aiFail("Database initialization error.");
}

$db = getPDO();

if (skyesoftGetEnv("APP_ENV") === "local") {
    error_log('[db] connection established via getPDO()');
}

// ─────────────────────────────────────────
// ⚙️ Actions Layer (Execution + Logging)
// ─────────────────────────────────────────
require_once __DIR__ . '/utils/actions.php';

#endregion

#region SECTION 1 — Codex Loaders (Standing Orders + Version)

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

// Infer Salutation (Mr/Ms) based on first and last name using AI — includes robust normalization and error handling to ensure clean output.
function inferSalutation(string $firstName, string $lastName): ?string {

    // 🔒 Guard — do not call AI with empty names
    $firstName = trim($firstName);
    $lastName  = trim($lastName);

    if ($firstName === '' && $lastName === '') {
        return null;
    }

    $basePrompt = <<<PROMPT
Given the name "{$firstName} {$lastName}", infer the most likely professional salutation.

Respond with ONLY one of these values:
Mr
Ms

Do not include punctuation or any other words.
PROMPT;

    $fullPrompt = injectStandingOrders($basePrompt);

    $apiKey = skyesoftGetEnv("OPENAI_API_KEY");

    if ($apiKey === null) {
        error_log('[SALUTATION] Missing API key');
        return null;
    }

    try {
        $response = callOpenAI($fullPrompt, $apiKey, 'gpt-4o');
    } catch (Throwable $e) {
        error_log('[SALUTATION AI ERROR] ' . $e->getMessage());
        return null;
    }

    if (!$response) {
        return null;
    }

    // 🔧 HARD NORMALIZATION
    $response = strtolower(trim($response));
    $response = str_replace(['.', '"', "'"], '', $response);

    if ($response === 'mr') return 'Mr';
    if ($response === 'ms') return 'Ms';

    return null;
}

// Infer Title
function inferTitle(string $input): ?string {

    $basePrompt = <<<PROMPT
Extract the professional job title from the following contact information.

Input:
{$input}

Respond with ONLY the job title (e.g., "Project Manager", "Account Manager").

If no clear title is present, respond with "Unknown".
PROMPT;

    $fullPrompt = injectStandingOrders($basePrompt);
    $apiKey = skyesoftGetEnv("OPENAI_API_KEY");

    if ($apiKey === null) {
        error_log('[TITLE] Missing API key');
        return null;
    }

    try {
        $response = callOpenAI($fullPrompt, $apiKey, 'gpt-4o');
    } catch (Throwable $e) {
        error_log('[TITLE AI ERROR] ' . $e->getMessage());
        return null;
    }

    if (!$response) {
        return null;
    }

    return trim($response);
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
    $exclude = [
        "auth", 
        "idle", 
        "streamId", 
        "activitySessionId",   // ← updated
        "forceLogout"
    ];

    return array_values(array_filter(
        array_keys($payload),
        fn($key) => !in_array($key, $exclude, true)
    ));
}

// Load recent user/system actions (context + behavioral insight)
function loadRecentActions(int $limit = 30, bool $todayOnly = false): array {

    try {
        $pdo = getPDO();

        // ─────────────────────────────────────────
        // ⏱ Optional time filter (today)
        // ─────────────────────────────────────────
        $whereTime = "";
        $params    = [':limit' => $limit];

        if ($todayOnly) {
            $todayStart = strtotime("today midnight");
            $whereTime  = "AND actionUnix >= :todayStart";
            $params[':todayStart'] = $todayStart;
        }

        // ─────────────────────────────────────────
        // 📊 Query
        // ─────────────────────────────────────────
        $sql = "
            SELECT 
                actionId,
                promptText,
                intent,
                intentConfidence,
                actionUnix
            FROM tblActions
            WHERE actionTypeId = 3
            {$whereTime}
            ORDER BY actionUnix DESC
            LIMIT :limit
        ";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            "rows" => $results,
            "meta" => [
                "count"    => count($results),
                "latest"   => $results[0]["actionUnix"] ?? null,
                "earliest" => $results ? $results[count($results)-1]["actionUnix"] ?? null : null,
                "filtered" => $todayOnly ? "today" : "recent"
            ]
        ];

    } catch (Throwable $e) {
        error_log("[DB Actions Error] " . $e->getMessage());
        return [
            "rows" => [],
            "meta" => [
                "count" => 0,
                "error" => true
            ]
        ];
    }
}

// Build Authoritative System Context from SSE snapshot + activity
function buildSystemContext(?array $sse, ?PDO $db = null, ?array $list = null): string
{
    if (!$sse) {
        return json_encode([
            "status"  => "no_data",
            "message" => "No SSE snapshot available"
        ]);
    }

    $exclude = [
        "auth",
        "idle",
        "streamId",
        "activitySessionId",
        "forceLogout"
    ];

    $domains = array_values(array_filter(
        array_keys($sse),
        fn($key) => !in_array($key, $exclude, true)
    ));

    $priority = [
        "time"    => $sse["timeDateArray"] ?? null,
        "holiday" => $sse["holidayState"] ?? null
    ];

    $activityData  = loadRecentActions(30);
    $recentActions = $activityData["rows"] ?? [];
    $activityMeta  = $activityData["meta"] ?? [];

    $operational = loadOperationalCounts($db);

    // Optional paginated list (contacts, etc.)
    if (is_array($list) && !empty($list)) {
        $operational['list'] = $list;
    }

    $context = [
        "priority" => $priority,
        "domains"  => $sse,
        "activity" => [
            "recentActions" => $recentActions,
            "meta"          => $activityMeta
        ],
        "operational" => $operational,
        "meta" => [
            "source"           => "SSE snapshot + live ELC counts",
            "readOnly"         => true,
            "schema"           => "dynamic",
            "availableDomains" => $domains
        ]
    ];

    return json_encode(
        $context,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}
/**
 * Live ELC counts for authoritative operational answers.
 * Read-only. Never mutates data.
 */
function loadOperationalCounts(?PDO $db): array
{
    $counts = [
        'contactsActive' => null,
        'contactsTotal'  => null,
        'entitiesTotal'  => null,
        'locationsTotal' => null,
        'actionsTotal'   => null,
        'source'         => 'database',
        'asOf'           => date('c')
    ];

    if (!$db instanceof PDO) {
        return $counts;
    }

    $safeCount = static function (PDO $db, string $sql) {
        try {
            return (int)$db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            error_log('[skyebot] count query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return null;
        }
    };

    $counts['contactsActive'] = $safeCount($db, "
        SELECT COUNT(*) FROM tblContacts
        WHERE COALESCE(isActive, 1) = 1
    ");

    $counts['contactsTotal'] = $safeCount($db, "
        SELECT COUNT(*) FROM tblContacts
    ");

    $counts['entitiesTotal'] = $safeCount($db, "
        SELECT COUNT(*) FROM tblEntities
    ");

    $counts['locationsTotal'] = $safeCount($db, "
        SELECT COUNT(*) FROM tblLocations
    ");

    $counts['actionsTotal'] = $safeCount($db, "
        SELECT COUNT(*) FROM tblActions
    ");

    error_log('[skyebot] operational counts: ' . json_encode($counts));

    return $counts;
}

/**
 * Bounded contact list for conversational pagination.
 * Page size is fixed at 10.
 */
function loadContactPage(?PDO $db, int $page = 1, int $pageSize = 10): array
{
    $page     = max(1, $page);
    $pageSize = 10; // hard limit — do not raise without design review
    $offset   = ($page - 1) * $pageSize;

    $result = [
        'type'       => 'contacts',
        'page'       => $page,
        'pageSize'   => $pageSize,
        'total'      => 0,
        'totalPages' => 0,
        'rows'       => [],
        'source'     => 'database',
        'asOf'       => date('c')
    ];

    if (!$db instanceof PDO) {
        return $result;
    }

    try {
        $total = (int)$db->query("
            SELECT COUNT(*) FROM tblContacts
            WHERE COALESCE(isActive, 1) = 1
        ")->fetchColumn();

        $result['total']      = $total;
        $result['totalPages'] = $total > 0 ? (int)ceil($total / $pageSize) : 0;

        if ($total === 0) {
            return $result;
        }

        // Clamp page to last page
        if ($page > $result['totalPages']) {
            $page   = $result['totalPages'];
            $offset = ($page - 1) * $pageSize;
            $result['page'] = $page;
        }

        $stmt = $db->prepare("
            SELECT
                c.contactId,
                TRIM(CONCAT(
                    COALESCE(c.contactFirstName, ''),
                    ' ',
                    COALESCE(c.contactLastName, '')
                )) AS name,
                c.contactTitle AS title,
                c.contactPrimaryPhone AS phone,
                c.contactEmail AS email,
                e.entityName AS entity,
                l.locationCity AS city
            FROM tblContacts c
            LEFT JOIN tblEntities e ON e.entityId = c.contactEntityId
            LEFT JOIN tblLocations l ON l.locationId = c.contactLocationId
            WHERE COALESCE(c.isActive, 1) = 1
            ORDER BY c.contactLastName ASC, c.contactFirstName ASC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'contactId' => (int)$row['contactId'],
                'name'      => trim((string)$row['name']) ?: 'Unnamed',
                'title'     => $row['title'] ?: null,
                'phone'     => $row['phone'] ?: null,
                'email'     => $row['email'] ?: null,
                'entity'    => $row['entity'] ?: null,
                'city'      => $row['city'] ?: null
            ];
        }

        $result['rows'] = $rows;

    } catch (Throwable $e) {
        error_log('[skyebot] loadContactPage failed: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Complete read-only contact record for the contact detail modal.
 */
function loadContactDetail(?PDO $db, int $contactId): ?array
{
    if (!$db instanceof PDO || $contactId <= 0) {
        return null;
    }

    try {
        $stmt = $db->prepare("
            SELECT
                c.contactId,
                c.contactSalutation,
                c.contactFirstName,
                c.contactLastName,
                c.contactTitle,
                c.contactPrimaryPhone,
                c.contactEmail,
                e.entityName,
                l.locationAddress,
                l.locationCity,
                l.locationState,
                l.locationZip
            FROM tblContacts c
            LEFT JOIN tblEntities e
                ON e.entityId = c.contactEntityId
            LEFT JOIN tblLocations l
                ON l.locationId = c.contactLocationId
            WHERE c.contactId = :contactId
            LIMIT 1
        ");

        $stmt->execute([
            'contactId' => $contactId
        ]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($contact) ? $contact : null;

    } catch (Throwable $e) {
        error_log(
            '[skyebot] loadContactDetail failed: ' .
            $e->getMessage()
        );

        return null;
    }
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
    string $model = "gpt-4o",           // ← Changed default
    ?array $responseFormat = null
): ?string {

    if (!$apiKey) {
        error_log('[callOpenAI] ❌ Missing API key');
        return null;
    }

    $url = "https://api.openai.com/v1/chat/completions";

    $payload = [
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => injectStandingOrders("You are a precise, Codex-aligned assistant.")],
            ["role" => "user",   "content" => $prompt]
        ],
        "max_tokens"  => 600,
        "temperature" => 0.1
    ];

    if (is_array($responseFormat)) {
        $payload["response_format"] = $responseFormat;
    }

    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($encodedPayload === false) {
        error_log('[callOpenAI] ❌ JSON encode failed');
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
            "timeout"       => 45,
            "ignore_errors" => true
        ]
    ]);

    $rawResponse = @file_get_contents($url, false, $context);
    $statusLine  = $http_response_header[0] ?? 'No HTTP response';

    // === FULL VISIBILITY LOGGING ===
    error_log("[callOpenAI] Model: {$model} | HTTP: {$statusLine}");
    if ($rawResponse) {
        error_log("[callOpenAI] Raw response length: " . strlen($rawResponse));
    } else {
        error_log("[callOpenAI] ❌ No body received");
    }

    $is200 = strpos($statusLine, " 200 ") !== false;

    if (!$rawResponse || !$is200) {
        error_log("[callOpenAI] ❌ Request failed - Status: {$statusLine}");
        if ($rawResponse) {
            error_log("[callOpenAI] Error body: " . $rawResponse);
        }
        return null;
    }

    $json = json_decode($rawResponse, true);

    if (!is_array($json)) {
        error_log("[callOpenAI] ❌ Invalid JSON response");
        return null;
    }

    if (isset($json['error'])) {
        error_log("[callOpenAI] ❌ OpenAI API Error: " . json_encode($json['error'], JSON_UNESCAPED_SLASHES));
        return null;
    }

    $content = $json["choices"][0]["message"]["content"] ?? '';
    $content = trim($content);

    if ($content === '') {
        error_log("[callOpenAI] ⚠️ Empty content returned. Full JSON: " . json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return null;
    }

    error_log("[callOpenAI] ✅ Success ({$model}) - " . strlen($content) . " chars");
    return $content;
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

#region SECTION 5 — Execution Guard (Auto)

if (realpath(__FILE__) !== realpath($_SERVER['SCRIPT_FILENAME'])) {
    return; // File is being included → STOP execution
}

#endregion

#region SECTION 6 — Input Resolution

// Init
$intent     = null;
$confidence = null;
$query      = null;
$systemPrompt = null;

$root = dirname(__DIR__);

// 🔧 Parse incoming JSON (CRITICAL)
$rawInput = file_get_contents("php://input");
$input    = json_decode($rawInput, true) ?? [];

// 🔐 API Key
$apiKey = skyesoftGetEnv("OPENAI_API_KEY");
if ($apiKey === null) {
    aiFail("OPENAI_API_KEY not available.");
}

// 🔎 Resolve Mode / Type (POST body has highest priority)
$type = $input['type']
     ?? $_POST['type']
     ?? $_GET['type']
     ?? ($argv[1] ?? "skyebot");

$isStructured = ($type === 'structured');

// =====================================================
// READ-ONLY CONTACT DETAIL
// =====================================================

if ($type === 'contactDetail') {
    $contactId = (int)($input['contactId'] ?? 0);
    $contact   = loadContactDetail($db, $contactId);

    header('Content-Type: application/json');

    echo json_encode([
        'success' => $contact !== null,
        'type'    => 'contact_detail',
        'contact' => $contact,
        'error'   => $contact === null
            ? 'Contact not found.'
            : null
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

// Resolve systemPrompt (for structured mode)
$systemPrompt = $input['systemPrompt']
             ?? $_POST['systemPrompt']
             ?? null;

// 🧠 Resolve userQuery
$query = $input['userQuery']
      ?? $_POST['userQuery']
      ?? $_GET['userQuery']
      ?? ($argv[3] ?? null);

// ❌ Validate
if (!$query || !is_string($query)) {
    aiFail("❌ userQuery is required.");
}

// ✂️ Normalize
$query = trim($query);

// 📍 Optional Context
$latitude  = $input["latitude"]  ?? null;
$longitude = $input["longitude"] ?? null;

// Debug logging
error_log("[askOpenAI] Mode: {$type} | Structured: " . ($isStructured ? 'YES' : 'NO'));
error_log("[askOpenAI] Query length: " . strlen($query));

#endregion

#region SECTION 7 — Structured Mode (EOP / Machine-Readable JSON)

if ($isStructured) {

    $finalSystemPrompt = $systemPrompt 
        ? $systemPrompt 
        : "You are a precise, JSON-only assistant.";

    $response = callOpenAI(
        $query,                    // user content
        $apiKey,
        "gpt-4o-mini",             // fast + reliable for structured output
        null
    );

    // Return RAW AI response (expected to be JSON)
    header('Content-Type: application/json');
    echo trim((string)$response);
    exit;   // ← Critical: Do NOT go to skyebot wrapper
}

// =====================================================
// STRUCTURED INTENT + ADDRESS PARSER (NEW)
// =====================================================

if ($type === "parseIntent") {

    $rawQuery = trim($input['userQuery'] ?? $query ?? '');

    if (empty($rawQuery)) {
        echo json_encode([
            'success' => false,
            'error'   => 'userQuery required'
        ]);
        exit;
    }

    $parsePrompt = <<<PROMPT
Extract clean intent and address from the following user input.

Return ONLY valid JSON in this exact schema:

{
  "workflow": "street_view" | "property_review" | "contact" | "unknown",
  "cleanAddress": "normalized address string or null",
  "confidence": 0.0-1.0,
  "reasoning": "short explanation"
}

User Input:
{$rawQuery}
PROMPT;

    $response = callOpenAI(
        injectStandingOrders($parsePrompt),
        $apiKey,
        "gpt-4o-mini",
        [
            "type" => "json_schema",
            "json_schema" => [
                "name" => "intent_parser",
                "schema" => [
                    "type" => "object",
                    "additionalProperties" => false,
                    "required" => ["workflow", "cleanAddress", "confidence", "reasoning"],
                    "properties" => [
                        "workflow" => ["type" => "string", "enum" => ["street_view", "property_review", "contact", "unknown"]],
                        "cleanAddress" => ["type" => ["string", "null"]],
                        "confidence" => ["type" => "number"],
                        "reasoning" => ["type" => "string"]
                    ]
                ]
            ]
        ]
    );

    if (!$response) {
        echo json_encode([
            'success' => false,
            'error'   => 'AI parser failed'
        ]);
        exit;
    }

    echo $response;   // return clean JSON
    exit;
}

#endregion

#region SECTION 7A — Temporary Artifact Cleanup

/**
 * Removes deprecated TMP artifacts belonging to the authenticated contact.
 * Leaves other users' TMP files and all permanent artifacts untouched.
 */
function cleanupTemporaryArtifacts(): void
{
    // Resolve shared artifacts directory (/skyesoft/artifacts)
    $artifactDir = dirname(__DIR__) . '/artifacts';

    // Resolve authenticated Contact ID
    $contactId = (int)($_SESSION['contactId'] ?? 0);

    // Stop safely when no authenticated contact is available
    if ($contactId <= 0) {
        error_log('[ARTIFACT CLEANUP] Skipped — authenticated contactId unavailable.');
        return;
    }

    // Confirm artifact workspace exists
    if (!is_dir($artifactDir)) {
        error_log("[ARTIFACT CLEANUP] Directory not found: {$artifactDir}");
        return;
    }

    // Format Contact ID to match filename segment (001, 017, 248)
    $contactSegment = str_pad((string)$contactId, 3, '0', STR_PAD_LEFT);

    // Match only this contact's temporary artifacts
    $pattern = $artifactDir . "/TMP-*-*-*-{$contactSegment}-*.*";
    $files = glob($pattern) ?: [];
    $deleted = 0;
    $failed = 0;

    foreach ($files as $file) {
        // File check
        if (!is_file($file)) {
            continue;
        }

        // Delete artifact
        if (unlink($file)) {
            $deleted++;
        } else {
            $failed++;
            error_log("[ARTIFACT CLEANUP] Failed to delete: {$file}");
        }
    }

    error_log(
        "[ARTIFACT CLEANUP] Contact {$contactId} — Deleted={$deleted} | Failed={$failed}"
    );
}

#endregion

#region SECTION 8 — Runtime Workflow Engine

$response = null;
$narrativeGenerated = false;
$reportPath = null;
$role = "askOpenAI";

// =====================================================================
// PHASE 1 — Normalize Request
// =====================================================================
$query = $query 
    ?? $input["userQuery"] 
    ?? $input["query"] 
    ?? $_POST["userQuery"] 
    ?? $_GET["userQuery"] 
    ?? ($argv[3] ?? null);

// =====================================================================
// PHASE 2 — Detect Intent
// =====================================================================
$detectedIntent = "skyebot"; // Fallback default intent

// Heuristic configurations for Contact Signature checks
$lowerQuery = strtolower(trim($query ?? ''));
$hasEmail   = preg_match('/@\S+\.\S{2,}/', $query ?? '');
$hasPhone   = preg_match('/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/', $query ?? '');
$lineCount  = substr_count($query ?? '', "\n") + 1;
$isContactSignature = $hasEmail && $hasPhone && $lineCount >= 3;

// Heuristic configurations for Property/Parcel checks
$isContactStructure = ($hasEmail || $hasPhone) && ($lineCount >= 1);

if (($type === "contact_proposal") || $isContactSignature) {
    $detectedIntent = "contact_proposal";
} elseif ($type === "narrative") {
    $detectedIntent = "narrative";
} elseif ($type === "proposalNarrative") {
    $detectedIntent = "proposalNarrative";
} elseif (!$isContactStructure && 
    ($type === "property_review" || 
     (isset($input['intent']) && $input['intent'] === 'property_review') || 
     str_contains(strtolower($query ?? ''), "property review") || 
     str_contains(strtolower($query ?? ''), "parcel review") || 
     preg_match('/\b\d{1,5}\s+[A-Za-z]/', $query ?? ''))) {
    
    $detectedIntent = "property_review";
}

error_log("[Workflow Engine] Evaluated Intent: " . strtoupper($detectedIntent));

// =====================================================================
// PHASE 3 — Initialize Runtime Workspace
// =====================================================================
$createsArtifacts = in_array($detectedIntent, [
    "contact_proposal",
    "property_review",
    "location_proposal",
    "parcel_review",
    "street_view",
    "sign_survey"
], true);

// If an artifact-producing workflow is starting, it explicitly retires previous temporary workspaces
if ($createsArtifacts) {
    error_log("[Workflow Engine] Ephemeral workspace initialization requested via new action track.");
    cleanupTemporaryArtifacts();
}

// =====================================================================
// PHASE 4 — Dispatch Workflow
// =====================================================================

// 📇 Workflow Branch: Contact Proposal
if ($detectedIntent === "contact_proposal") {
    error_log("[askOpenAI] Dispatching CONTACT_PROPOSAL track");

    $sessionContactId = $_SESSION["contactId"] ?? null;
    $activitySessionId = $activitySessionId ?? ($_SESSION['activitySessionId'] ?? session_id());

    $payload = [
        'input'              => $query,
        'activitySessionId'  => $activitySessionId,
        'mode'               => 'propose',
        'source'             => 'askOpenAI_bridge'
    ];

    try {
        insertActionPrompt([
            'contactId'         => $sessionContactId,
            'promptText'        => $query,
            'responseText'      => 'contact_propose_execute',   // placeholder
            'intent'            => 'contact_proposal',
            'intentConfidence'  => 0.95,
            'latitude'          => $latitude ?? null,
            'longitude'         => $longitude ?? null,
            'activitySessionId' => $activitySessionId,
            'actionTypeId'      => 3,
            'origin'            => ACTION_ORIGIN_USER,
            'actionPayloadData' => $payload,
            'actionResponseData'=> null
        ], $db);
    } catch (Throwable $e) {
        error_log('[actions] contact logging failed: ' . $e->getMessage());
    }

    session_write_close();

    $ch = curl_init('https://skyelighting.com/skyesoft/api/processProposedContact.php');
    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 45,
        CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS      => json_encode($payload, JSON_UNESCAPED_SLASHES)
    ]);

    $proposalResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($proposalResponse === false || $httpCode !== 200) {
        error_log("[askOpenAI] Proposal processor failed. HTTP " . $httpCode);
        echo json_encode(['status' => 'error', 'message' => 'Proposal processing failed']);
        exit;
    }

    echo $proposalResponse;
    exit;
}

// 🧾 Workflow Branch: Narrative Generation (Audit / Report Summaries)
if ($detectedIntent === "narrative") {
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
    
    $auditFacts = $report["auditFacts"] ?? buildAuditFacts($report);
    $auditFactsJson = json_encode($auditFacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
{$auditFactsJson}
PROMPT;
    
    $response = callOpenAI(injectStandingOrders($basePrompt), $apiKey);
    if ($response) {
        $report["narrative"] = trim($response);
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $narrativeGenerated = true;
    }
}

// 📦 Workflow Branch: Proposed Contact Report Summary Narrative
if ($detectedIntent === "proposalNarrative") {
    error_log("[proposalNarrative] Starting processing");
    $proposalData = $input["proposalData"] ?? null;
    if (!$proposalData || !is_array($proposalData)) {
        aiFail("proposalData required for proposalNarrative.");
    }
    
    $promptFile = $input["promptFile"] ?? "proposedContactReportSummary.prompt";
    $promptPath = "$root/codex/prompts/{$promptFile}";
    if (!file_exists($promptPath)) {
        aiFail("Prompt file not found: {$promptPath}");
    }
    
    $basePrompt = file_get_contents($promptPath);
    if (!$basePrompt) {
        aiFail("Failed to load prompt file.");
    }
    
    $proposalJson = json_encode($proposalData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $finalPrompt = <<<PROMPT
{$basePrompt}

=====================================================

Proposal JSON:

{$proposalJson}

=====================================================

Generate ONLY the operational report summary narrative.

Do NOT:
- explain the JSON
- mention AI generation
- produce markdown
- produce headings
- produce bullet lists
- produce recommendations outside governance framing

Professional operational tone only.
PROMPT;
    
    $response = callOpenAI(injectStandingOrders($finalPrompt), $apiKey);
    if (!$response || trim($response) === '') {
        aiFail("AI narrative generation failed - empty response from OpenAI");
    }
    $cleanNarrative = trim($response);
    
    echo json_encode(["success" => true, "summaryNarrative" => $cleanNarrative], JSON_UNESCAPED_SLASHES);
    exit;
}

// 🌐 Workflow Branch: Property Review (Compatibility Bridge)
if ($detectedIntent === "property_review") {
    error_log("[property_review] Dispatching PROPERTY_REVIEW track");

    $addressToReview = trim($query ?? '');
    if (empty($addressToReview)) {
        echo json_encode(['success' => false, 'error' => 'No address provided for review']);
        exit;
    }

    require_once __DIR__ . '/resolveParcelReview.php';
    $resolutionData = resolveParcelReview($addressToReview);

    if (!$resolutionData['success']) {
        echo json_encode($resolutionData);
        exit;
    }
    
    if (empty($resolutionData['summary'])) {
        $resolutionData['summary'] = "Skyesoft completed property review for " . htmlspecialchars($addressToReview) . ".";
    }
    
    if (isset($db) && $db instanceof PDO) {
        require_once __DIR__ . '/utils/actions.php';
        $actionId = insertActionPrompt([
            'actionTypeId'      => 12,
            'contactId'         => $_SESSION['contactId'] ?? 0,
            'promptText'        => $addressToReview,
            'responseText'      => $resolutionData['summary'] ?? null,
            'actionPayloadData' => $input,
            'actionResponseData'=> $resolutionData,
            'intent'            => 'property.review',
            'intentConfidence'  => 0.90,
            'latitude'          => $resolutionData['google']['latitude'] ?? null,
            'longitude' => $resolutionData['google']['longitude'] ?? null,
            'origin'            => 1,
            'createdUnixTime'   => time(),
        ], $db);
        $resolutionData['actionId'] = $actionId;
    }
    
    echo json_encode($resolutionData, JSON_UNESCAPED_SLASHES);
    exit;
}

// =====================================================================
// PHASE 5 — Return Response (Fallback Core Path)
// =====================================================================
// Any action running down this core conversational pathway represents a 
// non-acceptance/diversion path. Per Codex workspace governance guidelines, 
// this counts as an implicit rejection of any active proposal workspace state.
// We execute a tenant-safe purge of this contact's active TMP workspace files.
if ($detectedIntent === "skyebot") {
    error_log("[Workflow Engine] Non-acceptance conversation path hit. Invoking contact workspace cleanup.");
    cleanupTemporaryArtifacts();
}

#endregion

#region SECTION 9 — Skyebot (Authority-Aware, Deterministic)

if ($type === "skyebot") {

    // Query
    $query = $input["userQuery"]
          ?? $_GET["userQuery"]
          ?? ($argv[3] ?? null);
    
    if (!$query) {
        aiFail("userQuery required for skyebot mode.");
    }

    // Defaults
    $role = "askOpenAI";
    $narrativeGenerated = false;
    $reportPath = null;
    $operationalList = null;

    error_log("[skyebot] Processing query: " . substr($query, 0, 250));

    // ─────────────────────────────────────────────
    // 1. Load Runtime Domain Registry
    // ─────────────────────────────────────────────
    $streamedDomains = loadRuntimeDomainRegistryKeys();
    $allowedDomainsList = !empty($streamedDomains)
        ? implode(", ", $streamedDomains)
        : "none";

    // ─────────────────────────────────────────────
    // 2. Semantic Intent Classification
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
                    "intent"      => ["type" => "string"],
                    "confidence"  => ["type" => "number"],
                    "reasoning"   => ["type" => "string"]
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

    error_log("[semantic-intent] Raw response: " . ($intentRaw ? substr($intentRaw, 0, 600) : "NULL"));

    $intentMeta = json_decode($intentRaw ?? "", true);

    if (!is_array($intentMeta) || !isset($intentMeta['intent']) || !isset($intentMeta['confidence'])) {
        error_log("[semantic-intent] ❌ Failed to parse JSON or missing keys");
        $intentMeta = [
            "intent"     => "uncertain",
            "confidence" => 0.0,
            "reasoning"  => "JSON parse / schema failure"
        ];
    } else {
        error_log("[semantic-intent] ✅ Intent: {$intentMeta['intent']} | Confidence: {$intentMeta['confidence']}");
    }

    $intent     = $intentMeta["intent"] ?? "unknown";
    $confidence = (float)($intentMeta["confidence"] ?? 0.0);

    // ─────────────────────────────────────────────
    // 3. UI ACTIONS + SHORT-CIRCUITS
    // ─────────────────────────────────────────────
    $execution = executeIntent($intent, $confidence);
    if ($execution) {
        $type     = $execution['type'];
        $response = $execution['response'];
        goto SKY_OUTPUT;
    }

    if (
        $confidence >= 0.70 &&
        preg_match('/^([a-z]+)_(inquiry|repair_request|execute|amendment_request)$/', $intent, $m)
    ) {
        $domainKey = $m[1];
        $mode      = $m[2];
        if (in_array($domainKey, $streamedDomains, true)) {
            $type = "domain_intent";
            $response = json_encode(["domain" => $domainKey, "mode" => $mode, "confidence" => $confidence], JSON_UNESCAPED_SLASHES);
            goto SKY_OUTPUT;
        }
    }

    $lowerQuery = strtolower(trim($query));
    if (str_contains($lowerQuery, "deviation") || str_contains($lowerQuery, "violation") || str_contains($lowerQuery, "structural")) {
        $role = "governance";
        $type = "structural_state";
        $response = buildGovernanceResponse();
        goto SKY_OUTPUT;
    }

    // ─────────────────────────────────────────────
    // 3b. Operational list resolution (contacts, page size 10)
    // ─────────────────────────────────────────────
    $isContactList =
        preg_match('/\b(list|show|display)\b.*\bcontacts?\b/', $lowerQuery) ||
        preg_match('/\bcontacts?\b.*\b(list|page)\b/', $lowerQuery);

    $isListNavigation = (bool)preg_match('/\b(next|previous|prev)\s+page\b/', $lowerQuery);

    if ($isContactList || $isListNavigation) {
        $page = 1;

        if (preg_match('/\bpage\s+(\d+)\b/', $lowerQuery, $m)) {
            $page = max(1, (int)$m[1]);
        } elseif (preg_match('/\bnext\s+page\b/', $lowerQuery)) {
            $page = (int)($_SESSION['lastList']['page'] ?? 1) + 1;
        } elseif (preg_match('/\b(prev|previous)\s+page\b/', $lowerQuery)) {
            $page = max(1, (int)($_SESSION['lastList']['page'] ?? 2) - 1);
        }

        $operationalList = loadContactPage($db, $page, 10);

        // Session handoff for next/previous
        $_SESSION['lastList'] = [
            'type' => 'contacts',
            'page' => $operationalList['page'] ?? $page
        ];

        error_log('[skyebot] contact list page=' . ($operationalList['page'] ?? $page) .
                  ' rows=' . count($operationalList['rows'] ?? []));

        // Structured list response — client renders the card (skip model layout)
        if (is_array($operationalList) && isset($operationalList['rows'])) {
            // Resolve action context
            $actorContactId   = (int)($_SESSION['SKYESOFT_contactId']
                            ?? $_SESSION['contactId']
                            ?? 0);

            $activitySessionId = $_SESSION['activitySessionId']
                            ?? session_id();

            $page       = (int)($operationalList['page'] ?? 1);
            $pageSize   = (int)($operationalList['pageSize'] ?? 10);
            $total      = (int)($operationalList['total'] ?? 0);
            $totalPages = (int)($operationalList['totalPages'] ?? 1);
            $rowCount   = count($operationalList['rows']);

            // Build structured response
            $listResponse = [
                'success'           => true,
                'type'              => 'contact_list',
                'list'              => $operationalList,
                'activitySessionId' => $activitySessionId
            ];

            // Record prompt action (Type 3)
            if ($actorContactId > 0) {
                try {
                    insertActionPrompt([
                        'contactId'          => $actorContactId,
                        'promptText'         => $query,
                        'responseText'       => sprintf(
                            'Displayed contacts page %d of %d (%d contacts shown; %d total).',
                            $page,
                            $totalPages,
                            $rowCount,
                            $total
                        ),
                        'intent'             => 'contacts.list',
                        'intentConfidence'   => $confidence,
                        'activitySessionId'  => $activitySessionId,
                        'latitude'           => $latitude,
                        'longitude'          => $longitude,
                        'actionTypeId'       => 3,
                        'origin'             => ACTION_ORIGIN_USER,
                        'actionPayloadData'  => [
                            'operation' => 'contacts.list',
                            'page'      => $page,
                            'pageSize'  => $pageSize
                        ],
                        'actionResponseData' => [
                            'success'    => true,
                            'page'       => $page,
                            'totalPages' => $totalPages,
                            'rowCount'   => $rowCount,
                            'total'      => $total
                        ]
                    ], $db);
                } catch (Throwable $e) {
                    // Preserve list response if audit logging fails
                    error_log(
                        '[askOpenAI] Contact-list action logging failed: ' .
                        $e->getMessage()
                    );
                }
            }

            header('Content-Type: application/json');

            echo json_encode(
                $listResponse,
                JSON_UNESCAPED_SLASHES
            );

            exit;
        }
    }

    // ─────────────────────────────────────────────
    // 6. Conversational Fallback
    // ─────────────────────────────────────────────
    error_log("[DEBUG] Entering conversational fallback. Query: " . substr($query, 0, 150));

    $sseSnapshot = loadSseSnapshot();
    $responsePrompt = loadResponseGenerationPrompt();

    if ($responsePrompt === "") {
        error_log("[DEBUG] ❌ Response generation prompt file is missing or empty!");
        $basePrompt = "You are a helpful assistant. User said: " . $query;
    } else {
        $systemContext = buildSystemContext($sseSnapshot, $db, $operationalList);
        $basePrompt = $responsePrompt . "\n\nSYSTEM DATA (JSON):\n" . $systemContext . "\n\nUser Input:\n" . $query;
    }

    $response = callOpenAI(
        injectStandingOrders($basePrompt),
        $apiKey,
        "gpt-4o-mini"
    );

    // Graceful Quota Handling
    if (!$response) {
        $response = "I'm here, but OpenAI is currently out of credits on this account. Try again in a few minutes or let Steve know to top up the balance.";
    }

    $type = "skyebot";

    SKY_OUTPUT:
    error_log("[skyebot] Final response type: {$type} | Length: " . strlen($response ?? ''));
}
#endregion

#region SECTION 10 — Output (EOP)

if (!isset($response) || trim((string)$response) === '') {
    error_log('[askOpenAI] EMPTY AI RESPONSE — forcing fallback');

    $debugInfo = [
        "reason"     => "callOpenAI returned null or empty",
        "intent"     => $intent ?? 'null',
        "confidence" => $confidence ?? 'null',
        "query"      => substr($query ?? '', 0, 100),
        "type"       => $type ?? 'unknown'
    ];

    error_log('[askOpenAI] DEBUG INFO: ' . json_encode($debugInfo, JSON_UNESCAPED_SLASHES));

    $response = "I'm here and ready — try asking that again. (Check php-error.log for details)";
}

// ───────────────────────────────────────────────
// Normal Output Path (Everything Else)
// ───────────────────────────────────────────────

$preview = function_exists('mb_substr')
    ? mb_substr((string)$response, 0, 300)
    : substr((string)$response, 0, 300);

error_log('ASK_OPENAI RESPONSE RAW: ' . json_encode(['preview' => $preview]));

// Session Context
$sessionContactId = $_SESSION["contactId"] ?? null;
if (!empty($_SESSION['authenticated'])) {
    $_SESSION['lastActivity'] = time();
}

$activitySessionId = $_SESSION['activitySessionId'] ?? session_id();

// Location
$latitude  = is_numeric($input['latitude'] ?? null) ? (float)$input['latitude'] : null;
$longitude = is_numeric($input['longitude'] ?? null) ? (float)$input['longitude'] : null;

// Action Logging — general query / skyebot path
if ($sessionContactId && isset($response)) {
    try {
        $actionPayloadData = [
            'query'             => $query ?? $input['input'] ?? '[unknown]',
            'source'            => 'skyebot',
            'requestType'       => $type ?? 'skyebot',
            'activitySessionId' => $activitySessionId,
            'detectedIntent'    => $intent ?? 'unknown',
            'intentConfidence'  => $confidence ?? null
        ];

        $actionResponseData = [
            'success'            => true,
            'answer'             => trim((string)$response),
            'role'               => $role ?? 'askOpenAI',
            'type'               => $type ?? 'skyebot',
            'intent'             => $intent ?? 'unknown',
            'intentConfidence'   => $confidence ?? null,
            'narrativeGenerated' => $narrativeGenerated ?? false
        ];

        insertActionPrompt([
            'contactId'          => $sessionContactId,
            'promptText'         => $query ?? $input['input'] ?? '[unknown]',
            'responseText'       => trim((string)$response),
            'intent'             => $intent ?? 'unknown',
            'intentConfidence'   => $confidence ?? null,
            'latitude'           => $latitude,
            'longitude'          => $longitude,
            'activitySessionId'  => $activitySessionId,
            'actionTypeId'       => 3,
            'origin'             => ACTION_ORIGIN_USER,
            'actionPayloadData'  => $actionPayloadData,
            'actionResponseData' => $actionResponseData
        ], $db);

    } catch (Throwable $e) {
        error_log("[actions] insert failed in askOpenAI.php: " . $e->getMessage());
    }
}

session_write_close();

// Final Output
echo json_encode([
    "success"            => true,
    "role"               => $role ?? "askOpenAI",
    "type"               => $type ?? "skyebot",
    "narrativeGenerated" => $narrativeGenerated ?? false,
    "response"           => trim((string)$response),
    "reportUpdated"      => $reportPath ?? null,
    "activitySessionId"  => $activitySessionId
], JSON_UNESCAPED_SLASHES);

exit;

#endregion