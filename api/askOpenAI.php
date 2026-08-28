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

/**
 * Load standing orders from codex.json.
 * Always returns a valid JSON string.
 */
function loadStandingOrders(): string
{
    $root      = dirname(__DIR__);
    $codexPath = $root . '/codex/codex.json';

    // Codex file check
    if (!is_file($codexPath) || !is_readable($codexPath)) {
        return '{}';
    }

    $codexRaw = file_get_contents($codexPath);

    if ($codexRaw === false || $codexRaw === '') {
        return '{}';
    }

    $codex = json_decode($codexRaw, true);

    // Standing-orders structure check
    if (
        !is_array($codex) ||
        !isset($codex['meta']['standingOrders']) ||
        !is_array($codex['meta']['standingOrders'])
    ) {
        return '{}';
    }

    $standingOrders = json_encode(
        $codex['meta']['standingOrders'],
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $standingOrders !== false
        ? $standingOrders
        : '{}';
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

    $apiKey = skyesoftGetEnv('OPENAI_API_KEY');

    if ($apiKey === null) {
        error_log('[SALUTATION] Missing API key');
        return null;
    }

    try {
        $response = callOpenAI(
            $basePrompt,
            $apiKey,
            'gpt-4o'
        );
    } catch (Throwable $e) {
        error_log(
            '[SALUTATION AI ERROR] ' .
            $e->getMessage()
        );

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

    $fullPrompt = $basePrompt;
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
function buildSystemContext(
    ?array $sse,
    ?PDO $db = null,
    ?array $list = null,
    bool $minimal = true
): string {
    // Priority information
    $priority = [
        'time'    => $sse['timeDateArray'] ?? null,
        'holiday' => $sse['holidayState'] ?? null
    ];

    // Clean operational counts (no list)
    $boundedOperational = loadOperationalCounts($db);

    // Optional bounded list
    $operational = $boundedOperational;

    if (is_array($list) && !empty($list)) {
        $operational['list'] = $list;
    }

    // Base context
    $context = [
        'priority'    => $priority,
        'operational' => $operational,
        'meta' => [
            'source' => $sse
                ? 'SSE priority + live ELC counts'
                : 'Live ELC counts; SSE unavailable',
            'sseAvailable' => (bool)$sse,
            'readOnly'     => true,
            'schema'       => 'slim'
        ]
    ];

    // Selective domain context
    if (!$minimal && $sse) {
        $safeDomains = [];
        $allowedKeys = [
            'kpi',
            'timeDateArray',
            'holidayState',
            'weather',
            'systemStatus'
        ];

        foreach ($allowedKeys as $key) {
            if (isset($sse[$key])) {
                $safeDomains[$key] = $sse[$key];
            }
        }

        $context['domains']        = $safeDomains;
        $context['meta']['schema'] = 'selective';
    }

    $json = encodeSystemContext($context);

    // Context-size guard
    $varMaxContextChars = 40000;

    if (strlen($json) > $varMaxContextChars) {
        error_log(
            '[buildSystemContext] Oversized context: ' .
            strlen($json) .
            ' bytes; using absolute fallback'
        );

        // Remove lists and domains
        $json = encodeSystemContext([
            'priority'    => $priority,
            'operational' => $boundedOperational,
            'meta' => [
                'source' => $sse
                    ? 'SSE priority + live ELC counts (absolute fallback)'
                    : 'Live ELC counts only (absolute fallback)',
                'sseAvailable' => (bool)$sse,
                'readOnly'     => true,
                'schema'       => 'absolute'
            ]
        ]);
    }

    // Verify absolute fallback
    if (strlen($json) > $varMaxContextChars) {
        error_log(
            '[buildSystemContext] Absolute fallback remains oversized: ' .
            strlen($json) .
            ' bytes; using emergency fallback'
        );

        // Counts only
        $json = encodeSystemContext([
            'operational' => $boundedOperational,
            'meta' => [
                'source'   => 'Live ELC counts only',
                'readOnly' => true,
                'schema'   => 'emergency'
            ]
        ]);
    }

    return $json;
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
function loadContactPage(?PDO $db, int $page = 1, int $pageSize = 5): array
{
    $page     = max(1, $page);
    $pageSize = 5; // hard limit — do not raise without design review
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
                c.contactEntityId,
                c.contactLocationId,
                c.contactSalutation,
                c.contactFirstName,
                c.contactLastName,
                c.contactTitle,
                c.contactIsBilling,
                c.contactPrimaryPhone,
                c.contactPrimaryPhoneRaw,
                c.contactPrimaryPhoneExtension,
                c.contactSecondaryPhone,
                c.contactSecondaryPhoneRaw,
                c.contactEmail,
                c.contactEmailNormalized,
                c.contactEmailConfirmed,
                c.contactNote,
                c.contactDate,
                c.contactIsNotValid,
                c.isActive,
                c.contactCreatedAt,
                c.contactUpdatedAt,
                c.contactEndedAt,
                c.lastActivityUnix,

                e.entityId,
                e.entityName,

                l.locationId,
                l.locationName,
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

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        // Format dates for the client
        $createdUnix = (int)($row['contactDate'] ?? $row['contactCreatedAt'] ?? 0);
        $updatedUnix = (int)($row['contactUpdatedAt'] ?? $row['lastActivityUnix'] ?? 0);

        $createdDate  = $createdUnix > 0
            ? date('M j, Y', $createdUnix)
            : null;
        $lastActivity = $updatedUnix > 0
            ? date('M j, Y', $updatedUnix)
            : $createdDate;

        return [
            'contactId'                    => (int)$row['contactId'],
            'contactEntityId'              => (int)$row['contactEntityId'],
            'contactLocationId'            => (int)$row['contactLocationId'],
            'contactSalutation'            => $row['contactSalutation'],
            'contactFirstName'             => $row['contactFirstName'],
            'contactLastName'              => $row['contactLastName'],
            'contactTitle'                 => $row['contactTitle'],
            'contactIsBilling'             => (int)$row['contactIsBilling'],
            'contactPrimaryPhone'          => $row['contactPrimaryPhone'],
            'contactPrimaryPhoneRaw'       => $row['contactPrimaryPhoneRaw'],
            'contactPrimaryPhoneExtension' => $row['contactPrimaryPhoneExtension'],
            'contactSecondaryPhone'        => $row['contactSecondaryPhone'],
            'contactSecondaryPhoneRaw'     => $row['contactSecondaryPhoneRaw'],
            'contactEmail'                 => $row['contactEmail'],
            'contactEmailNormalized'       => $row['contactEmailNormalized'],
            'contactEmailConfirmed'        => (int)$row['contactEmailConfirmed'],
            'contactNote'                  => $row['contactNote'],
            'contactDate'                  => $createdUnix,
            'contactIsNotValid'            => (int)$row['contactIsNotValid'],
            'isActive'                     => (int)$row['isActive'],
            'contactCreatedAt'             => (int)($row['contactCreatedAt'] ?? 0),
            'contactUpdatedAt'             => $updatedUnix,
            'contactEndedAt'               => (int)($row['contactEndedAt'] ?? 0),
            'createdDate'                  => $createdDate,
            'lastActivity'                 => $lastActivity,

            'entity' => !empty($row['entityId']) ? [
                'entityId'   => (int)$row['entityId'],
                'entityName' => $row['entityName']
            ] : null,

            'location' => !empty($row['locationId']) ? [
                'locationId'      => (int)$row['locationId'],
                'locationName'    => $row['locationName'],
                'locationAddress' => $row['locationAddress'],
                'locationCity'    => $row['locationCity'],
                'locationState'   => $row['locationState'],
                'locationZip'     => $row['locationZip']
            ] : null,

            // Counts left at 0 for now; can be filled later
            'orderCount'       => 0,
            'applicationCount' => 0,
            'noteCount'        => 0,
            'taskCount'        => 0
        ];

    } catch (Throwable $e) {
        error_log(
            '[skyebot] loadContactDetail failed: ' .
            $e->getMessage()
        );
        return null;
    }
}

/**
 * Paginated entity list (read-only).
 * Returns the same shape the frontend Entity List Card expects.
 *
 * @param PDO|null $db
 * @param int      $page
 * @param int      $pageSize
 * @return array
 */
function loadEntityPage(?PDO $db, int $page = 1, int $pageSize = 5): array
{
    $page     = max(1, $page);
    $pageSize = 5; // hard limit — keep consistent with contacts
    $offset   = ($page - 1) * $pageSize;

    $result = [
        'type'       => 'entities',
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
        // Total count (exclude invalid entities if desired)
        $total = (int)$db->query("
            SELECT COUNT(*)
            FROM tblEntities
            WHERE COALESCE(entityIsNotValid, 0) = 0
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
                e.entityId,
                e.entityName,
                e.entityLegalName,
                e.entityType,
                e.entityStatus,
                e.entityState,
                e.entityIsNotValid
            FROM tblEntities e
            WHERE COALESCE(e.entityIsNotValid, 0) = 0
            ORDER BY e.entityName ASC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'entityId'   => (int)$row['entityId'],
                'entityName' => trim((string)$row['entityName']) ?: 'Unnamed Entity',
                'name'       => trim((string)$row['entityName']) ?: 'Unnamed Entity', // alias for frontend compatibility
                'address'    => null,   // populate later if you join a primary location
                'city'       => null,
                'state'      => $row['entityState'] ?: null,
                'phone'      => null,
                'status'     => $row['entityStatus'] ?: null,
                'entityType' => $row['entityType'] ?: null
            ];
        }

        $result['rows'] = $rows;

    } catch (Throwable $e) {
        error_log('[skyebot] loadEntityPage failed: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Search contacts by first name, last name, or both names.
 */
function searchContactsByName(?PDO $db, string $searchName): array
{
    if (!$db instanceof PDO) {
        return [];
    }

    // Normalize search text
    $searchName = trim(preg_replace('/\s+/', ' ', $searchName));

    if ($searchName === '') {
        return [];
    }

    // Separate first and last search terms
    $nameParts = explode(' ', $searchName, 2);

    $firstTerm = $nameParts[0];
    $lastTerm  = $nameParts[1] ?? null;

    try {
        // Search by both supplied names
        if ($lastTerm !== null && trim($lastTerm) !== '') {
            $stmt = $db->prepare("
                SELECT
                    c.contactId,
                    c.contactFirstName,
                    c.contactLastName,
                    c.contactTitle,
                    c.contactPrimaryPhone,
                    c.contactEmail,
                    c.isActive,
                    e.entityName,
                    l.locationCity,
                    l.locationState
                FROM tblContacts c
                LEFT JOIN tblEntities e
                    ON e.entityId = c.contactEntityId
                LEFT JOIN tblLocations l
                    ON l.locationId = c.contactLocationId
                WHERE (
                    c.contactFirstName LIKE :firstTerm
                    AND c.contactLastName LIKE :lastTerm
                )
                OR (
                    c.contactFirstName LIKE :lastTermReverse
                    AND c.contactLastName LIKE :firstTermReverse
                )
                ORDER BY
                    c.contactLastName,
                    c.contactFirstName
                LIMIT 50
            ");

            $stmt->execute([
                'firstTerm'       => '%' . $firstTerm . '%',
                'lastTerm'        => '%' . $lastTerm . '%',
                'lastTermReverse' => '%' . $lastTerm . '%',
                'firstTermReverse'=> '%' . $firstTerm . '%'
            ]);

        // Search either first or last name
        } else {
            $stmt = $db->prepare("
                SELECT
                    c.contactId,
                    c.contactFirstName,
                    c.contactLastName,
                    c.contactTitle,
                    c.contactPrimaryPhone,
                    c.contactEmail,
                    e.entityName,
                    l.locationCity,
                    l.locationState
                FROM tblContacts c
                LEFT JOIN tblEntities e
                    ON e.entityId = c.contactEntityId
                LEFT JOIN tblLocations l
                    ON l.locationId = c.contactLocationId
                WHERE c.contactFirstName LIKE :firstName
                   OR c.contactLastName LIKE :lastName
                ORDER BY
                    c.contactLastName,
                    c.contactFirstName
                LIMIT 50
            ");

            $stmt->execute([
                'firstName' => '%' . $firstTerm . '%',
                'lastName'  => '%' . $firstTerm . '%'
            ]);
        }

        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($contacts) ? $contacts : [];

    } catch (Throwable $e) {
        error_log(
            '[skyebot] searchContactsByName failed: ' .
            $e->getMessage()
        );

        return [];
    }
}

/**
 * Search entities / businesses by name (deterministic, authority-aware)
 * Includes invalid entities and exposes entityIsNotValid so the UI can note them.
 *
 * @param PDO $db
 * @param string $searchName
 * @return array
 */
function searchEntitiesByName($db, string $searchName): array
{
    $searchName = trim($searchName);
    if ($searchName === '') {
        return [];
    }

    $normalized = preg_replace('/\s+/', ' ', strtolower($searchName));
    $nospace    = str_replace(' ', '', $normalized);

    try {
        $sql = "
            SELECT
                e.entityId,
                e.entityName,
                e.entityLegalName,
                e.entityNormalizedName,
                e.entityStructure,
                e.entityState,
                e.entityStatus,
                e.entityType,
                e.entityIsVerified,
                e.entityAccNumber,
                e.entityIsNotValid
            FROM tblEntities e
            WHERE
                e.entityName LIKE :like1
                OR e.entityLegalName LIKE :like2
                OR e.entityNormalizedName LIKE :like3
                OR LOWER(e.entityName) = :exact1
                OR LOWER(e.entityNormalizedName) = :exact2
                OR LOWER(REPLACE(e.entityName, ' ', '')) = :nospace1
                OR LOWER(REPLACE(e.entityNormalizedName, ' ', '')) = :nospace2
            ORDER BY
                CASE
                    WHEN LOWER(e.entityName) = :exact3 THEN 0
                    WHEN LOWER(e.entityNormalizedName) = :exact4 THEN 1
                    WHEN LOWER(e.entityName) LIKE :starts1 THEN 2
                    ELSE 3
                END,
                e.entityIsNotValid ASC,
                e.entityName ASC
            LIMIT 25
        ";

        $stmt = $db->prepare($sql);

        $like   = '%' . $searchName . '%';
        $starts = $normalized . '%';

        // Bind every placeholder uniquely
        $stmt->bindValue(':like1',   $like,      PDO::PARAM_STR);
        $stmt->bindValue(':like2',   $like,      PDO::PARAM_STR);
        $stmt->bindValue(':like3',   $like,      PDO::PARAM_STR);
        $stmt->bindValue(':exact1',  $normalized, PDO::PARAM_STR);
        $stmt->bindValue(':exact2',  $normalized, PDO::PARAM_STR);
        $stmt->bindValue(':exact3',  $normalized, PDO::PARAM_STR);
        $stmt->bindValue(':exact4',  $normalized, PDO::PARAM_STR);
        $stmt->bindValue(':nospace1',$nospace,    PDO::PARAM_STR);
        $stmt->bindValue(':nospace2',$nospace,    PDO::PARAM_STR);
        $stmt->bindValue(':starts1', $starts,     PDO::PARAM_STR);

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];

    } catch (Throwable $e) {
        error_log('[searchEntitiesByName] ' . $e->getMessage());
        return [];
    }
}

/**
 * Resolve a single best-matching entity by name (exact-first ranking).
 * Returns the top entity row or null when no confident match exists.
 * Uses the same ranking philosophy as searchEntitiesByName but returns
 * only the highest-ranked candidate so callers can treat it as authoritative.
 *
 * @param PDO|null $db
 * @param string   $entityName
 * @return array|null
 */
function searchEntityByName(?PDO $db, string $entityName): ?array
{
    if (!$db instanceof PDO) {
        return null;
    }

    $entityName = trim(preg_replace('/\s+/', ' ', $entityName));
    if ($entityName === '') {
        return null;
    }

    $matches = searchEntitiesByName($db, $entityName);
    if (empty($matches)) {
        return null;
    }

    // Prefer exact (case-insensitive) name or normalized name
    $normalized = strtolower($entityName);
    $nospace    = str_replace(' ', '', $normalized);

    foreach ($matches as $row) {
        $name     = strtolower(trim((string)($row['entityName'] ?? '')));
        $normName = strtolower(trim((string)($row['entityNormalizedName'] ?? '')));
        $legal    = strtolower(trim((string)($row['entityLegalName'] ?? '')));

        if (
            $name === $normalized ||
            $normName === $normalized ||
            $legal === $normalized ||
            str_replace(' ', '', $name) === $nospace ||
            str_replace(' ', '', $normName) === $nospace
        ) {
            return $row;
        }
    }

    // Fall back to the highest-ranked (first) result from the existing ordered query
    return $matches[0] ?? null;
}

/**
 * Return all active contacts belonging to a given entityId.
 * Relationship: tblContacts.contactEntityId = tblEntities.entityId
 *
 * @param PDO|null $db
 * @param int      $entityId
 * @return array
 */
function searchContactsByEntityId(?PDO $db, int $entityId): array
{
    if (!$db instanceof PDO || $entityId <= 0) {
        return [];
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
                c.isActive,
                e.entityId,
                e.entityName,
                l.locationCity,
                l.locationState
            FROM tblContacts c
            LEFT JOIN tblEntities e
                ON e.entityId = c.contactEntityId
            LEFT JOIN tblLocations l
                ON l.locationId = c.contactLocationId
            WHERE c.contactEntityId = :entityId
              AND COALESCE(c.isActive, 1) = 1
            ORDER BY
                c.contactLastName ASC,
                c.contactFirstName ASC
            LIMIT 100
        ");

        $stmt->execute(['entityId' => $entityId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];

    } catch (Throwable $e) {
        error_log('[searchContactsByEntityId] ' . $e->getMessage());
        return [];
    }
}

/**
 * Return active contacts matching a name (first/last/partial) that also
 * belong to the supplied entityId.
 * Combines the name-matching logic of searchContactsByName with the
 * entity filter of searchContactsByEntityId.
 *
 * Exact whole-name matches are preferred by the ORDER BY ranking.
 *
 * @param PDO|null $db
 * @param string   $contactName
 * @param int      $entityId
 * @return array
 */
function searchContactsByNameAndEntityId(?PDO $db, string $contactName, int $entityId): array
{
    if (!$db instanceof PDO || $entityId <= 0) {
        return [];
    }

    $contactName = trim(preg_replace('/\s+/', ' ', $contactName));
    if ($contactName === '') {
        return [];
    }

    $nameParts = explode(' ', $contactName, 2);
    $firstTerm = $nameParts[0];
    $lastTerm  = isset($nameParts[1]) ? trim($nameParts[1]) : null;

    try {
        if ($lastTerm !== null && $lastTerm !== '') {
            // Full first + last supplied
            $stmt = $db->prepare("
                SELECT
                    c.contactId,
                    c.contactSalutation,
                    c.contactFirstName,
                    c.contactLastName,
                    c.contactTitle,
                    c.contactPrimaryPhone,
                    c.contactEmail,
                    c.isActive,
                    e.entityId,
                    e.entityName,
                    l.locationCity,
                    l.locationState,
                    CASE
                        WHEN LOWER(TRIM(CONCAT(COALESCE(c.contactFirstName,''), ' ', COALESCE(c.contactLastName,'')))) = LOWER(:exactFull)
                            THEN 0
                        WHEN LOWER(c.contactFirstName) = LOWER(:exactFirst)
                         AND LOWER(c.contactLastName)  = LOWER(:exactLast)
                            THEN 1
                        WHEN LOWER(c.contactFirstName) LIKE LOWER(:startsFirst)
                         AND LOWER(c.contactLastName)  LIKE LOWER(:startsLast)
                            THEN 2
                        ELSE 3
                    END AS rankScore
                FROM tblContacts c
                LEFT JOIN tblEntities e
                    ON e.entityId = c.contactEntityId
                LEFT JOIN tblLocations l
                    ON l.locationId = c.contactLocationId
                WHERE c.contactEntityId = :entityId
                  AND COALESCE(c.isActive, 1) = 1
                  AND (
                      (c.contactFirstName LIKE :firstTerm AND c.contactLastName LIKE :lastTerm)
                   OR (c.contactFirstName LIKE :lastTermReverse AND c.contactLastName LIKE :firstTermReverse)
                  )
                ORDER BY
                    rankScore ASC,
                    c.contactLastName ASC,
                    c.contactFirstName ASC
                LIMIT 50
            ");

            $exactFull = $firstTerm . ' ' . $lastTerm;

            $stmt->execute([
                'entityId'         => $entityId,
                'firstTerm'        => '%' . $firstTerm . '%',
                'lastTerm'         => '%' . $lastTerm . '%',
                'lastTermReverse'  => '%' . $lastTerm . '%',
                'firstTermReverse' => '%' . $firstTerm . '%',
                'exactFull'        => $exactFull,
                'exactFirst'       => $firstTerm,
                'exactLast'        => $lastTerm,
                'startsFirst'      => $firstTerm . '%',
                'startsLast'       => $lastTerm . '%'
            ]);
        } else {
            // Single token (first or last name)
            $stmt = $db->prepare("
                SELECT
                    c.contactId,
                    c.contactSalutation,
                    c.contactFirstName,
                    c.contactLastName,
                    c.contactTitle,
                    c.contactPrimaryPhone,
                    c.contactEmail,
                    e.entityId,
                    e.entityName,
                    l.locationCity,
                    l.locationState,
                    CASE
                        WHEN LOWER(c.contactFirstName) = LOWER(:exactToken)
                          OR LOWER(c.contactLastName)  = LOWER(:exactToken2)
                            THEN 0
                        WHEN LOWER(c.contactFirstName) LIKE LOWER(:startsToken)
                          OR LOWER(c.contactLastName)  LIKE LOWER(:startsToken2)
                            THEN 1
                        ELSE 2
                    END AS rankScore
                FROM tblContacts c
                LEFT JOIN tblEntities e
                    ON e.entityId = c.contactEntityId
                LEFT JOIN tblLocations l
                    ON l.locationId = c.contactLocationId
                WHERE c.contactEntityId = :entityId
                  AND COALESCE(c.isActive, 1) = 1
                  AND (
                      c.contactFirstName LIKE :token
                   OR c.contactLastName  LIKE :token2
                  )
                ORDER BY
                    rankScore ASC,
                    c.contactLastName ASC,
                    c.contactFirstName ASC
                LIMIT 50
            ");

            $stmt->execute([
                'entityId'     => $entityId,
                'token'        => '%' . $firstTerm . '%',
                'token2'       => '%' . $firstTerm . '%',
                'exactToken'   => $firstTerm,
                'exactToken2'  => $firstTerm,
                'startsToken'  => $firstTerm . '%',
                'startsToken2' => $firstTerm . '%'
            ]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];

    } catch (Throwable $e) {
        error_log('[searchContactsByNameAndEntityId] ' . $e->getMessage());
        return [];
    }
}

/**
 * Lightweight conversational wrapper stripper.
 * Removes only the common command prefixes so the remaining
 * phrase can be handed to the record resolver.
 *
 * Examples:
 *   "Can you show me Steve?"          → "Steve"
 *   "Show Christy Signs"              → "Christy Signs"
 *   "Show Susan at Christy Signs"     → "Susan at Christy Signs"
 *   "Please find contact Susan"       → "contact Susan"
 *   "Search for Christy Signs"        → "Christy Signs"
 *
 * Does NOT decide whether the phrase is a contact or an entity.
 *
 * @param string $raw
 * @return string  Cleaned lookup phrase (trimmed)
 */
function stripConversationalWrapper(string $raw): string
{
    $text = trim($raw);
    if ($text === '') {
        return '';
    }

    // Normalize internal whitespace and trailing punctuation that is
    // almost always conversational noise.
    $text = preg_replace('/\s+/', ' ', $text);
    $text = rtrim($text, " \t\n\r\0\x0B?.!");

    // Ordered from longest / most specific to shortest so we never
    // leave a residual fragment of a longer phrase.
    $wrappers = [
        '/^\s*can\s+you\s+(?:please\s+)?(?:show|find|search\s+for|look\s+up)\s+(?:me\s+)?/i',
        '/^\s*please\s+(?:show|find|search\s+for|look\s+up)\s+(?:me\s+)?/i',
        '/^\s*(?:show|find|search\s+for|look\s+up)\s+(?:me\s+)?/i',
        '/^\s*can\s+you\s+/i',
        '/^\s*please\s+/i',
    ];

    foreach ($wrappers as $pattern) {
        $text = preg_replace($pattern, '', $text);
    }

    // One final clean-up after stripping
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $text = rtrim($text, " \t\n\r\0\x0B?.!");

    return $text;
}

/**
 * Detect and resolve the combined "Name at Entity" form.
 *
 * Examples that should hit this path:
 *   "Susan at Christy Signs"
 *   "Steve Skye at Christy Signs"
 *   "Susan Alderson at Christy"
 *
 * Returns a structured contact_search payload with searchMode = "contact_entity"
 * or null when the phrase does not contain a usable "at" separator /
 * the entity cannot be resolved / no matching contacts are found.
 *
 * @param PDO|null $db
 * @param string   $lookupPhrase   Already cleaned by stripConversationalWrapper()
 * @return array|null
 */
function resolveContactAtEntity(?PDO $db, string $lookupPhrase): ?array
{
    if (!$db instanceof PDO) {
        return null;
    }

    $phrase = trim($lookupPhrase);
    if ($phrase === '' || stripos($phrase, ' at ') === false) {
        return null;
    }

    // Split on the first " at " only (case-insensitive)
    $parts = preg_split('/\s+at\s+/i', $phrase, 2);
    if (count($parts) !== 2) {
        return null;
    }

    $contactPhrase = trim($parts[0]);
    $entityPhrase  = trim($parts[1]);

    if ($contactPhrase === '' || $entityPhrase === '') {
        return null;
    }

    // 1. Resolve the entity (exact-first)
    $entity = searchEntityByName($db, $entityPhrase);
    if ($entity === null || empty($entity['entityId'])) {
        return null;          // entity not found → fall through to other resolvers
    }

    $entityId   = (int)$entity['entityId'];
    $entityName = (string)($entity['entityName'] ?? $entityPhrase);

    // 2. Search for the named contact inside that entity
    $contacts = searchContactsByNameAndEntityId($db, $contactPhrase, $entityId);

    // Even if zero contacts match the name, we still return the structured
    // response so the client can show "no matching contact at this entity".
    // (Change to `if (empty($contacts)) return null;` if you prefer silent fall-through.)

    return [
        'success'           => true,
        'type'              => 'contact_search',
        'searchMode'        => 'contact_entity',
        'searchName'        => $phrase,               // original combined phrase
        'contactPhrase'     => $contactPhrase,
        'entityId'          => $entityId,
        'entityName'        => $entityName,
        'matches'           => $contacts,
        'matchCount'        => count($contacts),
        'activitySessionId' => $_SESSION['activitySessionId'] ?? session_id()
    ];
}

/**
 * Resolve a single lookup phrase with no "at" separator.
 *
 * Priority order (stop at first successful hit):
 *  1. Exact full contact name
 *  2. Exact entity name          → open the Entity Card
 *  3. Exact contact first or last name
 *  4. Ranked partial contact match
 *  5. Partial entity match       → return Entity Search Results
 *  6. null                       → fall through to normal Skyebot
 *
 * Entity matches are resolved as Entity objects. Contacts associated with
 * an entity are accessed through the Contacts relationship on the Entity Card.
 *
 * @param PDO|null $db
 * @param string   $lookupPhrase Already cleaned by stripConversationalWrapper()
 * @return array|null
 */
function resolveSinglePhrase(?PDO $db, string $lookupPhrase): ?array
{
    if (!$db instanceof PDO) {
        return null;
    }

    $phrase = trim($lookupPhrase);

    if ($phrase === '') {
        return null;
    }

    $activitySessionId =
        $_SESSION['activitySessionId']
        ?? session_id();

    $normalizedPhrase = strtolower($phrase);

    // ---------------------------------------------------------------
    // 1. Exact full contact name
    // ---------------------------------------------------------------
    $contacts = searchContactsByName($db, $phrase);

    $exactFull = [];

    foreach ($contacts as $contact) {
        $fullName = strtolower(trim(
            ($contact['contactFirstName'] ?? '')
            . ' '
            . ($contact['contactLastName'] ?? '')
        ));

        if ($fullName === $normalizedPhrase) {
            $exactFull[] = $contact;
        }
    }

    if (!empty($exactFull)) {
        return [
            'success'           => true,
            'type'              => 'contact_search',
            'searchMode'        => 'contact_exact',
            'searchName'        => $phrase,
            'matches'           => $exactFull,
            'matchCount'        => count($exactFull),
            'activitySessionId' => $activitySessionId
        ];
    }

    // ---------------------------------------------------------------
    // Resolve potential entity match once for exact and partial checks
    // ---------------------------------------------------------------
    $entity = searchEntityByName($db, $phrase);

    // ---------------------------------------------------------------
    // 2. Exact entity name → open Entity Card
    // ---------------------------------------------------------------
    if (
        $entity !== null
        && !empty($entity['entityId'])
    ) {
        $entityId   = (int)$entity['entityId'];
        $entityName = trim((string)(
            $entity['entityName']
            ?? $phrase
        ));

        $normalizedEntityName = strtolower($entityName);

        $normalizedStoredName = strtolower(trim((string)(
            $entity['entityNormalizedName']
            ?? ''
        )));

        $normalizedLegalName = strtolower(trim((string)(
            $entity['entityLegalName']
            ?? ''
        )));

        $isExactEntity =
            $normalizedEntityName === $normalizedPhrase
            || (
                $normalizedStoredName !== ''
                && $normalizedStoredName === $normalizedPhrase
            )
            || (
                $normalizedLegalName !== ''
                && $normalizedLegalName === $normalizedPhrase
            );

        if ($isExactEntity) {
            return [
                'success'           => true,
                'type'              => 'entity_detail',
                'searchMode'        => 'entity_exact',
                'searchName'        => $phrase,
                'entityId'          => $entityId,
                'entityName'        => $entityName,
                'activitySessionId' => $activitySessionId
            ];
        }
    }

    // ---------------------------------------------------------------
    // 3. Exact contact first or last name
    // ---------------------------------------------------------------
    $exactFirstOrLast = [];

    foreach ($contacts as $contact) {
        $firstName = strtolower(trim((string)(
            $contact['contactFirstName']
            ?? ''
        )));

        $lastName = strtolower(trim((string)(
            $contact['contactLastName']
            ?? ''
        )));

        if (
            $firstName === $normalizedPhrase
            || $lastName === $normalizedPhrase
        ) {
            $exactFirstOrLast[] = $contact;
        }
    }

    if (!empty($exactFirstOrLast)) {
        return [
            'success'           => true,
            'type'              => 'contact_search',
            'searchMode'        => 'contact_exact_partial',
            'searchName'        => $phrase,
            'matches'           => $exactFirstOrLast,
            'matchCount'        => count($exactFirstOrLast),
            'activitySessionId' => $activitySessionId
        ];
    }

    // ---------------------------------------------------------------
    // 4. Ranked partial contact match
    // ---------------------------------------------------------------
    if (!empty($contacts)) {
        return [
            'success'           => true,
            'type'              => 'contact_search',
            'searchMode'        => 'contact_partial',
            'searchName'        => $phrase,
            'matches'           => $contacts,
            'matchCount'        => count($contacts),
            'activitySessionId' => $activitySessionId
        ];
    }

    // ---------------------------------------------------------------
    // 5. Partial entity match → Entity Search Results
    // ---------------------------------------------------------------
    if (
        $entity !== null
        && !empty($entity['entityId'])
    ) {
        return [
            'success'           => true,
            'type'              => 'entity_search',
            'searchMode'        => 'entity_partial',
            'searchName'        => $phrase,
            'matches'           => [$entity],
            'matchCount'        => 1,
            'activitySessionId' => $activitySessionId
        ];
    }

    // ---------------------------------------------------------------
    // 6. Nothing matched → normal Skyebot processing
    // ---------------------------------------------------------------
    return null;
}

/**
 * Perform a Google Custom Search and return normalized results.
 * Returns an empty array on any failure (never throws).
 */
function googleCustomSearch(string $query, int $num = 5): array
{
$apiKey = skyesoftGetEnv('GOOGLE_CSE_API_KEY')
       ?: skyesoftGetEnv('GOOGLE_SEARCH_KEY')
       ?: getenv('GOOGLE_CSE_API_KEY')
       ?: getenv('GOOGLE_SEARCH_KEY');

$cx     = skyesoftGetEnv('GOOGLE_CSE_CX')
       ?: skyesoftGetEnv('GOOGLE_SEARCH_CX')
       ?: skyesoftGetEnv('GOOGLE_CX')
       ?: getenv('GOOGLE_CSE_CX')
       ?: getenv('GOOGLE_SEARCH_CX')
       ?: getenv('GOOGLE_CX');

    if (!$apiKey || !$cx || trim($query) === '') {
        error_log('[GoogleCSE] Missing API key, CX, or empty query');
        return [];
    }

    $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
        'key' => $apiKey,
        'cx'  => $cx,
        'q'   => $query,
        'num' => max(1, min(10, $num))
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // PHP 8.5+ auto-closes CurlHandle

    if ($raw === false || $httpCode !== 200) {
        error_log("[GoogleCSE] HTTP {$httpCode} or request failed for query: {$query}");
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['items'])) {
        return [];
    }

    $results = [];
    foreach ($data['items'] as $item) {
        $results[] = [
            'title'       => $item['title']       ?? '',
            'link'        => $item['link']        ?? '',
            'snippet'     => $item['snippet']     ?? '',
            'displayLink' => $item['displayLink'] ?? ''
        ];
    }

    error_log('[GoogleCSE] Returned ' . count($results) . ' results for: ' . $query);
    return $results;
}

/**
 * Load a single entity with related collection counts.
 * Read-only. Returns null when the entity does not exist.
 *
 * @param PDO|null $db
 * @param int      $entityId
 * @return array|null
 */
function loadEntityDetail(?PDO $db, int $entityId): ?array
{
    if (!$db instanceof PDO || $entityId <= 0) {
        return null;
    }

    try {
        // --------------------------------------------------
        // Core entity record
        // --------------------------------------------------
        $stmt = $db->prepare("
            SELECT
                e.entityId,
                e.entityName,
                e.entityLegalName,
                e.entityNormalizedName,
                e.entityStructure,
                e.entityState,
                e.entityStatus,
                e.entityType,
                e.entityIsVerified,
                e.entityAccNumber,
                e.entityDate,
                e.entityUpdatedUnix,
                e.entityIsNotValid
            FROM tblEntities e
            WHERE e.entityId = :entityId
            LIMIT 1
        ");

        $stmt->execute([
            'entityId' => $entityId
        ]);

        $entity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($entity)) {
            return null;
        }

        // --------------------------------------------------
        // Related collection counts (safe / defensive)
        // --------------------------------------------------
        $safeCount = static function (PDO $db, string $sql, int $entityId): int {
            try {
                $s = $db->prepare($sql);
                $s->execute(['entityId' => $entityId]);
                return (int)$s->fetchColumn();
            } catch (Throwable $e) {
                error_log('[loadEntityDetail] count failed: ' . $e->getMessage());
                return 0;
            }
        };

        $entity['locationCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblLocations WHERE locationEntityId = :entityId",
            $entityId
        );

        $entity['contactCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblContacts WHERE contactEntityId = :entityId",
            $entityId
        );

        $entity['orderCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblOrders WHERE orderEntityId = :entityId",
            $entityId
        );

        $entity['applicationCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblApplications WHERE applicationEntityId = :entityId",
            $entityId
        );

        $entity['noteCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblNotes WHERE noteEntityId = :entityId",
            $entityId
        );

        $entity['taskCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblTasks WHERE taskEntityId = :entityId",
            $entityId
        );

        // --------------------------------------------------
        // Billing Location (canonical address)
        // --------------------------------------------------
        try {
            $locStmt = $db->prepare("
                SELECT
                    locationId,
                    locationName,
                    locationAddress,
                    locationAddressSuite,
                    locationCity,
                    locationState,
                    locationZip,
                    locationJurisdiction,
                    locationCounty,
                    locationParcelNumber,
                    locationZone,
                    locationLatitude,
                    locationLongitude
                FROM tblLocations
                WHERE locationEntityId = :entityId
                  AND locationIsBilling = 1
                  AND locationIsNotValid = 0
                LIMIT 1
            ");
            $locStmt->execute(['entityId' => $entityId]);
            $billing = $locStmt->fetch(PDO::FETCH_ASSOC);

            $entity['billingLocation'] = is_array($billing) ? $billing : null;
        } catch (Throwable $e) {
            error_log('[loadEntityDetail] billing location failed: ' . $e->getMessage());
            $entity['billingLocation'] = null;
        }

        // --------------------------------------------------
        // Last activity
        // Priority:
        //   1. entityUpdatedUnix (authoritative write stamp)
        //   2. Latest matching action in tblActions
        //   3. Fall back to created date (applied below)
        // --------------------------------------------------
        $entity['lastActivity'] = null;

        if (!empty($entity['entityUpdatedUnix']) && is_numeric($entity['entityUpdatedUnix'])) {
            $entity['lastActivity'] = date('M j, Y', (int)$entity['entityUpdatedUnix']);
        }

        if (empty($entity['lastActivity'])) {
            try {
                $act = $db->prepare("
                    SELECT MAX(actionUnix) AS lastUnix
                    FROM tblActions
                    WHERE actionPayloadData  LIKE :needle1
                       OR actionPayloadData  LIKE :needle2
                       OR actionResponseData LIKE :needle1
                       OR actionResponseData LIKE :needle2
                ");
                $act->execute([
                    'needle1' => '%"entityId":' . $entityId . '%',
                    'needle2' => '%"targetEntityId":' . $entityId . '%'
                ]);
                $lastUnix = $act->fetchColumn();

                if ($lastUnix && is_numeric($lastUnix)) {
                    $entity['lastActivity'] = date('M j, Y', (int)$lastUnix);
                }
            } catch (Throwable $e) {
                // keep null
            }
        }

        // --------------------------------------------------
        // Created date
        // --------------------------------------------------
        $createdRaw = $entity['entityDate'] ?? null;

        if ($createdRaw !== null && $createdRaw !== '') {
            if (is_numeric($createdRaw) && (int)$createdRaw > 1000000000) {
                $entity['createdDate'] = date('M j, Y', (int)$createdRaw);
            } else {
                $ts = strtotime((string)$createdRaw);
                $entity['createdDate'] = $ts ? date('M j, Y', $ts) : null;
            }
        } else {
            $entity['createdDate'] = null;
        }

        // Final fallback: use creation date when last activity is still empty
        if (empty($entity['lastActivity']) && !empty($entity['createdDate'])) {
            $entity['lastActivity'] = $entity['createdDate'];
        }

        return $entity;

    } catch (Throwable $e) {
        error_log(
            '[skyebot] loadEntityDetail failed: ' .
            $e->getMessage()
        );
        return null;
    }
}

/**
 * Load a complete Location Business Object.
 * Identifier can be: locationId, name, address, parcel, placeId, lat/lng, etc.
 * Server is responsible for resolution order.
 */
function loadLocationDetail(?PDO $db, string $identifier): ?array
{
    if (!$db instanceof PDO || trim($identifier) === '') {
        error_log('[loadLocationDetail] empty identifier or no DB');
        return null;
    }

    $identifier = trim($identifier);
    error_log('[loadLocationDetail] identifier received = [' . $identifier . ']');

    try {
        $location = null;

        // 1. Numeric Location ID
        if (ctype_digit($identifier)) {
            error_log('[loadLocationDetail] trying numeric ID');
            $stmt = $db->prepare("SELECT * FROM tblLocations WHERE locationId = :id LIMIT 1");
            $stmt->execute(['id' => (int)$identifier]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($location) error_log('[loadLocationDetail] matched by ID');
        }

        // 2. Exact Parcel Number (normalized)
        if (!$location) {
            $parcel = preg_replace('/[^0-9A-Za-z]/', '', strtoupper($identifier));
            if (strlen($parcel) >= 5) {
                error_log('[loadLocationDetail] trying parcel = ' . $parcel);
                $stmt = $db->prepare("
                    SELECT *
                    FROM tblLocations
                    WHERE locationParcelNumberRaw = :parcel1
                    OR REPLACE(REPLACE(REPLACE(locationParcelNumber, '-', ''), ' ', ''), '.', '') = :parcel2
                    LIMIT 1
                ");
                $stmt->execute([
                    'parcel1' => $parcel,
                    'parcel2' => $parcel
                ]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($location) error_log('[loadLocationDetail] matched by parcel');
            }
        }

        // 3. Exact Address
        if (!$location) {
            error_log('[loadLocationDetail] trying exact address');
            $stmt = $db->prepare("SELECT * FROM tblLocations WHERE locationAddress = :addr LIMIT 1");
            $stmt->execute(['addr' => $identifier]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($location) error_log('[loadLocationDetail] matched by exact address');
        }

        // 4. Google Place ID
        if (!$location && str_starts_with($identifier, 'ChIJ')) {
            error_log('[loadLocationDetail] trying Place ID');
            $stmt = $db->prepare("SELECT * FROM tblLocations WHERE locationPlaceId = :placeId LIMIT 1");
            $stmt->execute(['placeId' => $identifier]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($location) error_log('[loadLocationDetail] matched by Place ID');
        }

        // 5. Exact Location Name
        if (!$location) {
            error_log('[loadLocationDetail] trying exact name');
            $stmt = $db->prepare("SELECT * FROM tblLocations WHERE locationName = :name LIMIT 1");
            $stmt->execute(['name' => $identifier]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($location) error_log('[loadLocationDetail] matched by exact name');
        }

        // 6. Fuzzy Location Name
        if (!$location) {
            error_log('[loadLocationDetail] trying fuzzy name');
            $stmt = $db->prepare("
                SELECT * FROM tblLocations
                WHERE locationName LIKE :name
                ORDER BY locationId ASC
                LIMIT 1
            ");
            $stmt->execute(['name' => '%' . $identifier . '%']);
            $location = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($location) error_log('[loadLocationDetail] matched by fuzzy name');
        }

        if (!is_array($location)) {
            error_log('[loadLocationDetail] NO MATCH for [' . $identifier . ']');
            return null;
        }

        $locationId = (int)$location['locationId'];

        // --------------------------------------------------
        // Related collection counts (safe / defensive)
        // --------------------------------------------------
        $safeCount = static function (PDO $db, string $sql, int $locationId): int {
            try {
                $s = $db->prepare($sql);
                $s->execute(['locationId' => $locationId]);
                return (int)$s->fetchColumn();
            } catch (Throwable $e) {
                error_log('[loadLocationDetail] count failed: ' . $e->getMessage());
                return 0;
            }
        };

        $location['contactCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblContacts WHERE contactLocationId = :locationId",
            $locationId
        );

        $location['orderCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblOrders WHERE orderLocationId = :locationId",
            $locationId
        );

        $location['applicationCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblApplications WHERE applicationLocationId = :locationId",
            $locationId
        );

        $location['noteCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblNotes WHERE noteLocationId = :locationId",
            $locationId
        );

        $location['taskCount'] = $safeCount(
            $db,
            "SELECT COUNT(*) FROM tblTasks WHERE taskLocationId = :locationId",
            $locationId
        );

        // --------------------------------------------------
        // Parent Entity (optional but useful)
        // --------------------------------------------------
        $entityId = (int)($location['locationEntityId'] ?? 0);
        if ($entityId > 0) {
            try {
                $eStmt = $db->prepare("
                    SELECT entityId, entityName, entityType, entityStatus
                    FROM tblEntities
                    WHERE entityId = :entityId
                    LIMIT 1
                ");
                $eStmt->execute(['entityId' => $entityId]);
                $entity = $eStmt->fetch(PDO::FETCH_ASSOC);
                $location['entity'] = is_array($entity) ? $entity : null;
            } catch (Throwable $e) {
                $location['entity'] = null;
            }
        } else {
            $location['entity'] = null;
        }

        return $location;

    } catch (Throwable $e) {
        error_log('[skyebot] loadLocationDetail failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Paginated location list (read-only).
 * Returns the same shape the frontend Location List Card expects.
 *
 * @param PDO|null $db
 * @param int      $page
 * @param int      $pageSize
 * @return array
 */
function loadLocationPage(?PDO $db, int $page = 1, int $pageSize = 5): array
{
    $page     = max(1, $page);
    $pageSize = 5; // hard limit — keep consistent with entities/contacts
    $offset   = ($page - 1) * $pageSize;

    $result = [
        'type'       => 'locations',
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
        // Total count (exclude invalid locations)
        $total = (int)$db->query("
            SELECT COUNT(*)
            FROM tblLocations
            WHERE COALESCE(locationIsNotValid, 0) = 0
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
                l.locationId,
                l.locationName,
                l.locationAddress,
                l.locationCity,
                l.locationState,
                l.locationZip,
                l.locationParcelNumber,
                l.locationParcelNumberRaw,
                l.locationIsBilling,
                l.locationEntityId,
                e.entityName,
                e.entityId
            FROM tblLocations l
            LEFT JOIN tblEntities e ON e.entityId = l.locationEntityId
            WHERE COALESCE(l.locationIsNotValid, 0) = 0
            ORDER BY l.locationName ASC, l.locationId ASC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'locationId'              => (int)$row['locationId'],
                'locationName'            => trim((string)$row['locationName']) ?: 'Unnamed Location',
                'name'                    => trim((string)$row['locationName']) ?: 'Unnamed Location', // alias
                'locationAddress'         => $row['locationAddress'] ?: null,
                'address'                 => $row['locationAddress'] ?: null,
                'locationCity'            => $row['locationCity'] ?: null,
                'city'                    => $row['locationCity'] ?: null,
                'locationState'           => $row['locationState'] ?: null,
                'state'                   => $row['locationState'] ?: null,
                'locationZip'             => $row['locationZip'] ?: null,
                'zip'                     => $row['locationZip'] ?: null,
                'locationParcelNumber'    => $row['locationParcelNumber'] ?: null,
                'locationParcelNumberRaw' => $row['locationParcelNumberRaw'] ?: null,
                'parcel'                  => $row['locationParcelNumber'] ?: $row['locationParcelNumberRaw'] ?: null,
                'locationIsBilling'       => (int)$row['locationIsBilling'],
                'entityId'                => $row['entityId'] ? (int)$row['entityId'] : null,
                'entityName'              => $row['entityName'] ?: null,
                'entity'                  => $row['entityId'] ? [
                    'entityId'   => (int)$row['entityId'],
                    'entityName' => $row['entityName'] ?: null
                ] : null
            ];
        }

        $result['rows'] = $rows;

    } catch (Throwable $e) {
        error_log('[skyebot] loadLocationPage failed: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Search authoritative Locations by Location name for autocomplete.
 *
 * @param PDO|null $db
 * @param string   $query
 * @param int      $limit
 * @return array
 */
function searchLocations(?PDO $db, string $query, int $limit = 10): array
{
    // Normalize search
    $query = trim($query);
    $limit = max(1, min(10, $limit));

    if (!$db instanceof PDO || strlen($query) < 2) {
        return [];
    }

    try {
        // Prepare Location-name search values
        $contains = '%' . $query . '%';
        $prefix   = $query . '%';

        $stmt = $db->prepare("
            SELECT
                l.locationId,
                l.locationName,
                l.locationAddress,
                l.locationAddressSuite,
                l.locationCity,
                l.locationState,
                l.locationZip,
                l.locationEntityId,
                e.entityName
            FROM tblLocations l
            LEFT JOIN tblEntities e
                ON e.entityId = l.locationEntityId
            WHERE COALESCE(l.locationIsNotValid, 0) = 0
              AND l.locationName LIKE :nameContains
            ORDER BY
                CASE
                    WHEN l.locationName = :exactName THEN 1
                    WHEN l.locationName LIKE :namePrefix THEN 2
                    ELSE 3
                END,
                l.locationName ASC,
                l.locationId ASC
            LIMIT :limit
        ");

        $stmt->bindValue(
            ':nameContains',
            $contains,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':exactName',
            $query,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':namePrefix',
            $prefix,
            PDO::PARAM_STR
        );

        $stmt->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $locations = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locations[] = [
                'locationId'           => (int)$row['locationId'],
                'locationName'         => $row['locationName']
                    ?: 'Unnamed Location',
                'locationAddress'      => $row['locationAddress']
                    ?: null,
                'locationAddressSuite' => $row['locationAddressSuite']
                    ?: null,
                'locationCity'         => $row['locationCity']
                    ?: null,
                'locationState'        => $row['locationState']
                    ?: null,
                'locationZip'          => $row['locationZip']
                    ?: null,
                'locationEntityId'     => $row['locationEntityId']
                    ? (int)$row['locationEntityId']
                    : null,
                'entityName'           => $row['entityName']
                    ?: null
            ];
        }

        return $locations;

    } catch (Throwable $e) {
        error_log(
            '[searchLocations] Search failed: ' .
            $e->getMessage()
        );

        return [];
    }
}

/**
 * Resolve a location by natural-language phrase.
 * Returns a structured location_detail payload or null.
 *
 * Scoring:
 *  100  Exact locationName (case-insensitive)
 *   95  Exact full address
 *   92  Exact parcel number
 *   90  Exact Google Place ID
 *   80  Strong prefix / starts-with
 *   70  Contains / fuzzy
 *
 * Only returns when best score ≥ 90 (or explicit "location …" force).
 */
function resolveLocationByPhrase(?PDO $db, string $phrase, bool $force = false): ?array
{
    if (!$db instanceof PDO) {
        return null;
    }

    $phrase = trim(preg_replace('/\s+/', ' ', $phrase));
    if ($phrase === '') {
        return null;
    }

    $normalized = strtolower($phrase);
    $nospace    = str_replace(' ', '', $normalized);

    try {
        // Broad candidate set (name, address, parcel, placeId)
        $stmt = $db->prepare("
            SELECT
                l.locationId,
                l.locationEntityId,
                l.locationName,
                l.locationPlaceId,
                l.locationAddress,
                l.locationAddressSuite,
                l.locationCity,
                l.locationState,
                l.locationZip,
                l.locationParcelNumber,
                l.locationParcelNumberRaw,
                l.locationJurisdiction,
                l.locationCounty,
                l.locationIsBilling,
                l.locationIsNotValid,

                p.ownerName,
                p.subdivision,
                p.lotSize,
                p.yearBuilt,
                p.zoningCode,
                p.zoningDescription,
                p.zoningSource,
                p.zoningVerifiedAt,
                p.source,
                p.confidence,

                e.entityId,
                e.entityName,
                e.entityType,
                e.entityStatus

            FROM tblLocations l
            LEFT JOIN tblEntities e
                ON e.entityId = l.locationEntityId
            LEFT JOIN tblLocationParcelDetails p
                ON p.locationId = l.locationId

            WHERE COALESCE(l.locationIsNotValid, 0) = 0
              AND (
                    LOWER(l.locationName) LIKE :like1
                 OR LOWER(l.locationAddress) LIKE :like2
                 OR l.locationParcelNumber = :exactParcel
                 OR l.locationParcelNumberRaw = :exactParcelRaw
                 OR l.locationPlaceId = :exactPlace
                 OR LOWER(REPLACE(l.locationName, ' ', '')) LIKE :nospace
              )

            ORDER BY l.locationName ASC
            LIMIT 25
        ");

        $like = '%' . $phrase . '%';
        $stmt->execute([
            'like1'          => $like,
            'like2'          => $like,
            'exactParcel'    => $phrase,
            'exactParcelRaw' => $phrase,
            'exactPlace'     => $phrase,
            'nospace'        => '%' . $nospace . '%'
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return null;
        }

        // Score each candidate
        $scored = [];
        foreach ($rows as $row) {
            $score = 0;
            $name  = strtolower(trim((string)($row['locationName'] ?? '')));
            $addr  = strtolower(trim(
                ($row['locationAddress'] ?? '') . ' ' .
                ($row['locationCity'] ?? '') . ' ' .
                ($row['locationState'] ?? '') . ' ' .
                ($row['locationZip'] ?? '')
            ));
            $parcel = trim((string)($row['locationParcelNumber'] ?? $row['locationParcelNumberRaw'] ?? ''));
            $place  = trim((string)($row['locationPlaceId'] ?? ''));

            if ($name === $normalized) {
                $score = 100;
            } elseif ($addr === $normalized || str_contains($addr, $normalized)) {
                $score = 95;
            } elseif ($parcel !== '' && $parcel === $phrase) {
                $score = 92;
            } elseif ($place !== '' && $place === $phrase) {
                $score = 90;
            } elseif (str_starts_with($name, $normalized) || str_starts_with($nospace, str_replace(' ', '', $name))) {
                $score = 80;
            } elseif (str_contains($name, $normalized)) {
                $score = 70;
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }

        if (empty($scored)) {
            return null;
        }

        // Sort highest score first
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $best = $scored[0];

        // Threshold: ≥ 90, or force=true (explicit "location …")
        if ($best['score'] < 90 && !$force) {
            return null;
        }

        $row = $best['row'];

        // Shape the same payload that locationDetail returns
        $location = [
            'locationId'              => (string)$row['locationId'],
            'locationEntityId'        => (string)$row['locationEntityId'],
            'locationName'            => $row['locationName'],
            'locationPlaceId'         => $row['locationPlaceId'],
            'locationAddress'         => $row['locationAddress'],
            'locationAddressSuite'    => $row['locationAddressSuite'],
            'locationCity'            => $row['locationCity'],
            'locationState'           => $row['locationState'],
            'locationZip'             => $row['locationZip'],
            'locationParcelNumber'    => $row['locationParcelNumber'],
            'locationParcelNumberRaw' => $row['locationParcelNumberRaw'],
            'locationJurisdiction'    => $row['locationJurisdiction'],
            'locationCounty'          => $row['locationCounty'],
            'locationIsBilling'       => $row['locationIsBilling'],
            'locationIsNotValid'      => $row['locationIsNotValid'],
            'parcel' => [
                'ownerName'         => $row['ownerName'],
                'subdivision'       => $row['subdivision'],
                'lotSize'           => $row['lotSize'],
                'yearBuilt'         => $row['yearBuilt'],
                'zoningCode'        => $row['zoningCode'],
                'zoningDescription' => $row['zoningDescription'],
                'zoningSource'      => $row['zoningSource'],
                'zoningVerifiedAt'  => $row['zoningVerifiedAt'],
                'source'            => $row['source'],
                'confidence'        => $row['confidence']
            ],
            'entity' => [
                'entityId'     => (string)($row['entityId'] ?? ''),
                'entityName'   => $row['entityName'] ?? null,
                'entityType'   => $row['entityType'] ?? null,
                'entityStatus' => $row['entityStatus'] ?? null
            ]
        ];

        return [
            'success'    => true,
            'type'       => 'location_detail',
            'location'   => $location,
            'searchMode' => 'location.resolve',
            'matchCount' => 1,
            'score'      => $best['score'],
            'error'      => null
        ];

    } catch (Throwable $e) {
        error_log('[resolveLocationByPhrase] ' . $e->getMessage());
        return null;
    }
}

/**
 * Encode system context safely.
 * Never returns false.
 */
function encodeSystemContext(array $context): string
{
    $json = json_encode(
        $context,
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        error_log(
            '[buildSystemContext] JSON encode failed: ' .
            json_last_error_msg()
        );

        return '{"status":"encoding_error"}';
    }

    return $json;
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

    if ($semanticPrompt === '') {
        return $basePrompt;
    }

    return $semanticPrompt . "\n\n" . $basePrompt;
}
#endregion

#region SECTION 3 — OpenAI API Caller (Stream Context)
/**
 * Submit a Chat Completions request to OpenAI.
 */
function callOpenAI(
    string $prompt,
    ?string $apiKey,
    string $model = 'gpt-4o',
    ?array $responseFormat = null
): ?string {
    // API-key check
    if (!$apiKey) {
        error_log('[callOpenAI] Missing API key');
        return null;
    }

    $url = 'https://api.openai.com/v1/chat/completions';

    // Apply standing orders once
    $systemMessage = injectStandingOrders(
        'You are a precise, Codex-aligned assistant.'
    );

    // Calculate request sizes
    $varSystemBytes   = strlen($systemMessage);
    $varPromptBytes   = strlen($prompt);
    $varTotalBytes    = $varSystemBytes + $varPromptBytes;
    $varMaxInputBytes = 160000;
    $varStandingBytes = strlen(loadStandingOrders());

    // Log every attempted input
    error_log(
        '[callOpenAI] Input sizes (bytes) | ' .
        'system=' . $varSystemBytes .
        ' | user=' . $varPromptBytes .
        ' | total=' . $varTotalBytes .
        ' | limit=' . $varMaxInputBytes .
        ' | standingOrdersRaw=' . $varStandingBytes
    );

    // Local input-size guard
    if ($varTotalBytes > $varMaxInputBytes) {
        error_log(
            '[callOpenAI] Input rejected by local size guard'
        );

        return null;
    }

    // Build API payload
    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role'    => 'system',
                'content' => $systemMessage
            ],
            [
                'role'    => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens'  => 600,
        'temperature' => 0.1
    ];

    // Optional structured response
    if (is_array($responseFormat)) {
        $payload['response_format'] = $responseFormat;
    }

    $encodedPayload = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encodedPayload === false) {
        error_log(
            '[callOpenAI] JSON encode failed: ' .
            json_last_error_msg()
        );

        return null;
    }

    // Create HTTP request
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            'content'       => $encodedPayload,
            'timeout'       => 45,
            'ignore_errors' => true
        ]
    ]);

    $rawResponse = @file_get_contents(
        $url,
        false,
        $context
    );

    $statusLine = $http_response_header[0]
        ?? 'No HTTP response';

    // Request visibility
    error_log(
        '[callOpenAI] Model: ' .
        $model .
        ' | HTTP: ' .
        $statusLine
    );

    if ($rawResponse !== false && $rawResponse !== '') {
        error_log(
            '[callOpenAI] Raw response length: ' .
            strlen($rawResponse)
        );
    } else {
        error_log('[callOpenAI] No response body received');

        $streamError = error_get_last();

        if (is_array($streamError)) {
            error_log(
                '[callOpenAI] Stream error: ' .
                ($streamError['message'] ?? 'Unknown stream error')
            );
        }
    }

    $is200 = strpos($statusLine, ' 200 ') !== false;

    // HTTP/API failure
    if (
        $rawResponse === false ||
        $rawResponse === '' ||
        !$is200
    ) {
        $errorBody = json_decode(
            $rawResponse !== false ? $rawResponse : '',
            true
        );

        $errorCode = is_array($errorBody)
            ? ($errorBody['error']['code'] ?? 'unknown')
            : 'unknown';

        $errorMsg = is_array($errorBody)
            ? ($errorBody['error']['message'] ?? $statusLine)
            : $statusLine;

        error_log(
            '[callOpenAI] OpenAI error code=' .
            $errorCode .
            ' | msg=' .
            substr((string)$errorMsg, 0, 400)
        );

        return null;
    }

    // Decode response
    $json = json_decode($rawResponse, true);

    if (!is_array($json)) {
        error_log(
            '[callOpenAI] Invalid JSON response: ' .
            json_last_error_msg()
        );

        return null;
    }

    // API error in successful HTTP response
    if (isset($json['error'])) {
        error_log(
            '[callOpenAI] OpenAI API error: ' .
            json_encode(
                $json['error'],
                JSON_UNESCAPED_SLASHES |
                JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

        return null;
    }

    // Extract response
    $content = trim(
        (string)($json['choices'][0]['message']['content'] ?? '')
    );

    if ($content === '') {
        error_log(
            '[callOpenAI] Empty content returned'
        );

        return null;
    }

    error_log(
        '[callOpenAI] Success (' .
        $model .
        ') - ' .
        strlen($content) .
        ' characters'
    );

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
    // Resolve requested contact
    $targetContactId = (int)($input['contactId'] ?? 0);

    // Resolve action context
    $actorContactId = (int)($_SESSION['SKYESOFT_contactId']
                    ?? $_SESSION['contactId']
                    ?? 0);

    $activitySessionId = $_SESSION['activitySessionId']
                      ?? session_id();

    $latitude  = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;

    // Load requested contact
    $contact = loadContactDetail($db, $targetContactId);

    // Record successful contact READ
    if ($contact !== null && $actorContactId > 0) {
        try {
            insertActionPrompt([
                'contactId'          => $actorContactId,
                'promptText'         => 'Open contact profile',
                'responseText'       => sprintf(
                    'Displayed contact profile #%d.',
                    $targetContactId
                ),
                'intent'             => 'contacts.read',
                'intentConfidence'   => 1.00,
                'activitySessionId'  => $activitySessionId,
                'latitude'           => $latitude,
                'longitude'          => $longitude,
                'actionTypeId'       => 13,
                'origin'             => ACTION_ORIGIN_USER,
                'actionPayloadData'  => [
                    'operation'       => 'contacts.read',
                    'targetContactId' => $targetContactId
                ],
                'actionResponseData' => [
                    'success'         => true,
                    'targetContactId' => $targetContactId
                ]
            ], $db);

        } catch (Throwable $e) {
            // Preserve contact response if action logging fails
            error_log(
                '[askOpenAI] Contact-read action logging failed: ' .
                $e->getMessage()
            );
        }
    }

    // Return contact response
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

// =====================================================
// READ-ONLY ENTITY DETAIL
// =====================================================

if ($type === 'entityDetail') {

    // Resolve requested entity
    $targetEntityId = (int)($input['entityId'] ?? 0);

    // Resolve action context
    $actorContactId = (int)($_SESSION['SKYESOFT_contactId']
                    ?? $_SESSION['contactId']
                    ?? 0);

    $activitySessionId = $_SESSION['activitySessionId']
                      ?? session_id();

    $latitude  = $input['latitude']  ?? null;
    $longitude = $input['longitude'] ?? null;

    // Load requested entity
    $entity = loadEntityDetail($db, $targetEntityId);

    // Record successful entity READ
    if ($entity !== null && $actorContactId > 0) {
        try {
            insertActionPrompt([
                'contactId'          => $actorContactId,
                'promptText'         => 'Open entity profile',
                'responseText'       => sprintf(
                    'Displayed entity profile #%d.',
                    $targetEntityId
                ),
                'intent'             => 'entities.read',
                'intentConfidence'   => 1.00,
                'activitySessionId'  => $activitySessionId,
                'latitude'           => $latitude,
                'longitude'          => $longitude,
                'actionTypeId'       => 13,          // same READ action type used by contacts
                'origin'             => ACTION_ORIGIN_USER,
                'actionPayloadData'  => [
                    'operation'      => 'entities.read',
                    'targetEntityId' => $targetEntityId
                ],
                'actionResponseData' => [
                    'success'        => true,
                    'targetEntityId' => $targetEntityId
                ]
            ], $db);

        } catch (Throwable $e) {
            // Preserve entity response if action logging fails
            error_log(
                '[askOpenAI] Entity-read action logging failed: ' .
                $e->getMessage()
            );
        }
    }

    // Return entity response
    header('Content-Type: application/json');

    echo json_encode([
        'success' => $entity !== null,
        'type'    => 'entity_detail',
        'entity'  => $entity,
        'error'   => $entity === null
            ? 'Entity not found.'
            : null
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

// =====================================================
// ENTITY UPDATE (mutation)
// =====================================================

if ($type === 'entityUpdate') {

    $actorContactId = (int)(
        $_SESSION['SKYESOFT_contactId']
        ?? $_SESSION['contactId']
        ?? 0
    );

    $activitySessionId = $_SESSION['activitySessionId']
                      ?? session_id();

    $latitude  = is_numeric($input['latitude']  ?? null) ? (float)$input['latitude']  : null;
    $longitude = is_numeric($input['longitude'] ?? null) ? (float)$input['longitude'] : null;

    $targetEntityId = (int)($input['entityId'] ?? 0);

    if ($targetEntityId <= 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'entity_update',
            'error'   => 'Valid entityId is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Collect allowed fields
    $entityName           = trim((string)($input['entityName']           ?? ''));
    $entityLegalName      = trim((string)($input['entityLegalName']      ?? ''));
    $entityStructure      = trim((string)($input['entityStructure']      ?? ''));
    $entityNormalizedName = trim((string)($input['entityNormalizedName'] ?? ''));
    $entityAccNumber      = trim((string)($input['entityAccNumber']      ?? ''));
    $entityType           = trim((string)($input['entityType']           ?? ''));
    $entityIsNotValid     = (int)($input['entityIsNotValid'] ?? 0);

    if ($entityName === '') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'entity_update',
            'error'   => 'Entity name is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Validate enum
    $allowedTypes = ['company', 'customer', 'vendor', 'jurisdiction'];
    if ($entityType !== '' && !in_array(strtolower($entityType), $allowedTypes, true)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'entity_update',
            'error'   => 'Invalid entityType. Allowed: company, customer, vendor, jurisdiction.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($entityType !== '') {
        $entityType = strtolower($entityType);
    }

    // Auto-normalize if blank
    if ($entityNormalizedName === '') {
        $entityNormalizedName = strtolower(preg_replace('/\s+/', ' ', $entityName));
    }

    // Clamp invalid flag
    $entityIsNotValid = $entityIsNotValid ? 1 : 0;

    try {
        // Confirm exists
        $check = $db->prepare("
            SELECT entityId
            FROM tblEntities
            WHERE entityId = :entityId
            LIMIT 1
        ");
        $check->execute(['entityId' => $targetEntityId]);

        if (!$check->fetchColumn()) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'type'    => 'entity_update',
                'error'   => 'Entity not found.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $nowUnix = time();

        $sql = "
            UPDATE tblEntities
            SET
                entityName           = :entityName,
                entityLegalName      = :entityLegalName,
                entityStructure      = :entityStructure,
                entityNormalizedName = :entityNormalizedName,
                entityAccNumber      = :entityAccNumber,
                entityType           = :entityType,
                entityIsNotValid     = :entityIsNotValid,
                entityUpdatedUnix    = :entityUpdatedUnix
            WHERE entityId = :entityId
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'entityId'             => $targetEntityId,
            'entityName'           => $entityName,
            'entityLegalName'      => ($entityLegalName !== '') ? $entityLegalName : null,
            'entityStructure'      => ($entityStructure !== '') ? $entityStructure : null,
            'entityNormalizedName' => $entityNormalizedName,
            'entityAccNumber'      => ($entityAccNumber !== '') ? $entityAccNumber : null,
            'entityType'           => ($entityType !== '') ? $entityType : 'company',
            'entityIsNotValid'     => $entityIsNotValid,
            'entityUpdatedUnix'    => $nowUnix
        ]);

        // Reload
        $entity = loadEntityDetail($db, $targetEntityId);

        if ($entity === null) {
            throw new RuntimeException('Entity updated but reload failed.');
        }

        // Audit
        if ($actorContactId > 0) {
            try {
                insertActionPrompt([
                    'contactId'         => $actorContactId,
                    'promptText'        => 'Update entity profile',
                    'responseText'      => sprintf(
                        'Updated entity #%d (%s).',
                        $targetEntityId,
                        $entityName
                    ),
                    'intent'            => 'entities.update',
                    'intentConfidence'  => 1.0,
                    'activitySessionId' => $activitySessionId,
                    'latitude'          => $latitude,
                    'longitude'         => $longitude,
                    // TODO: replace with dedicated UPDATE actionTypeId when added
                    'actionTypeId'      => 13,
                    'origin'            => ACTION_ORIGIN_USER,
                    'actionPayloadData' => [
                        'operation'      => 'entities.update',
                        'targetEntityId' => $targetEntityId,
                        'fields'         => [
                            'entityName'           => $entityName,
                            'entityLegalName'      => $entityLegalName !== '' ? $entityLegalName : null,
                            'entityStructure'      => $entityStructure !== '' ? $entityStructure : null,
                            'entityNormalizedName' => $entityNormalizedName,
                            'entityAccNumber'      => $entityAccNumber !== '' ? $entityAccNumber : null,
                            'entityType'           => $entityType !== '' ? $entityType : null,
                            'entityIsNotValid'     => $entityIsNotValid,
                            'entityUpdatedUnix'    => $nowUnix
                        ]
                    ],
                    'actionResponseData' => [
                        'success'        => true,
                        'targetEntityId' => $targetEntityId
                    ]
                ], $db);
            } catch (Throwable $e) {
                error_log('[askOpenAI] Entity-update action logging failed: ' . $e->getMessage());
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'type'    => 'entity_update',
            'entity'  => $entity
        ], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        error_log('[askOpenAI] entityUpdate failed: ' . $e->getMessage());

        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'entity_update',
            'error'   => 'Update failed: ' . $e->getMessage()
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// =====================================================
// LOCATION UPDATE (mutation) — Location Edit v1.0
// =====================================================

if ($type === 'locationUpdate') {

    $actorContactId = (int)(
        $_SESSION['SKYESOFT_contactId']
        ?? $_SESSION['contactId']
        ?? 0
    );

    $activitySessionId = $_SESSION['activitySessionId']
                      ?? session_id();

    $latitude  = is_numeric($input['latitude']  ?? null) ? (float)$input['latitude']  : null;
    $longitude = is_numeric($input['longitude'] ?? null) ? (float)$input['longitude'] : null;

    $targetLocationId = (int)($input['locationId'] ?? 0);

    if ($targetLocationId <= 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'location_update',
            'error'   => 'Valid locationId is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Collect only user-maintained fields
    $locationName         = trim((string)($input['locationName']         ?? ''));
    $locationAddress      = trim((string)($input['locationAddress']      ?? ''));
    $locationAddressSuite = trim((string)($input['locationAddressSuite'] ?? ''));
    $locationCity         = trim((string)($input['locationCity']         ?? ''));
    $locationState        = strtoupper(trim((string)($input['locationState'] ?? '')));
    $locationZip          = trim((string)($input['locationZip']          ?? ''));
    $locationIsNotValid   = (int)($input['locationIsNotValid'] ?? 0) ? 1 : 0;

    if ($locationName === '') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'location_update',
            'error'   => 'Location name is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Length guards matching column sizes
    $locationName         = mb_substr($locationName, 0, 255);
    $locationAddress      = mb_substr($locationAddress, 0, 255);
    $locationAddressSuite = mb_substr($locationAddressSuite, 0, 100);
    $locationCity         = mb_substr($locationCity, 0, 100);
    $locationState        = mb_substr($locationState, 0, 10);
    $locationZip          = mb_substr($locationZip, 0, 20);

    try {
        // Confirm exists + load current address fields for change detection
        $check = $db->prepare("
            SELECT
                locationId,
                locationEntityId,
                locationAddress,
                locationAddressSuite,
                locationCity,
                locationState,
                locationZip
            FROM tblLocations
            WHERE locationId = :locationId
            LIMIT 1
        ");
        $check->execute(['locationId' => $targetLocationId]);
        $current = $check->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'type'    => 'location_update',
                'error'   => 'Location not found.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $nowUnix = time();

        // ── Write user-maintained fields only ───────────────────────────────
        $sql = "
            UPDATE tblLocations
            SET
                locationName         = :locationName,
                locationAddress      = :locationAddress,
                locationAddressSuite = :locationAddressSuite,
                locationCity         = :locationCity,
                locationState        = :locationState,
                locationZip          = :locationZip,
                locationIsNotValid   = :locationIsNotValid,
                locationUpdatedUnix  = :locationUpdatedUnix
            WHERE locationId = :locationId
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'locationId'            => $targetLocationId,
            'locationName'          => $locationName,
            'locationAddress'       => ($locationAddress !== '') ? $locationAddress : null,
            'locationAddressSuite'  => ($locationAddressSuite !== '') ? $locationAddressSuite : null,
            'locationCity'          => ($locationCity !== '') ? $locationCity : null,
            'locationState'         => ($locationState !== '') ? $locationState : null,
            'locationZip'           => ($locationZip !== '') ? $locationZip : null,
            'locationIsNotValid'    => $locationIsNotValid,
            'locationUpdatedUnix'   => $nowUnix
        ]);

        // ── Detect address change → re-derive parcel / zoning ───────────────
        $addressChanged = (
            $locationAddress      !== (string)($current['locationAddress'] ?? '') ||
            $locationAddressSuite !== (string)($current['locationAddressSuite'] ?? '') ||
            $locationCity         !== (string)($current['locationCity'] ?? '') ||
            $locationState        !== (string)($current['locationState'] ?? '') ||
            $locationZip          !== (string)($current['locationZip'] ?? '')
        );

        if ($addressChanged) {
            // Single authoritative owner: re-use the same Maricopa service
            // that the proposal pipeline already uses.
            refreshLocationParcelDetails($db, $targetLocationId);
        }

        // ── Reload full record (after possible parcel refresh) ──────────────
        $reload = $db->prepare("
            SELECT l.*,
                   e.entityId, e.entityName
            FROM tblLocations l
            LEFT JOIN tblEntities e ON e.entityId = l.locationEntityId
            WHERE l.locationId = :locationId
            LIMIT 1
        ");
        $reload->execute(['locationId' => $targetLocationId]);
        $row = $reload->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Location updated but reload failed.');
        }

        $createdDate  = !empty($row['locationDate'])
            ? date('M j, Y', (int)$row['locationDate'])
            : null;
        $lastActivity = !empty($row['locationUpdatedUnix'])
            ? date('M j, Y', (int)$row['locationUpdatedUnix'])
            : $createdDate;

        $location = [
            'locationId'              => (int)$row['locationId'],
            'locationName'            => $row['locationName'],
            'name'                    => $row['locationName'],
            'locationAddress'         => $row['locationAddress'],
            'locationAddressSuite'    => $row['locationAddressSuite'],
            'locationCity'            => $row['locationCity'],
            'locationState'           => $row['locationState'],
            'locationZip'             => $row['locationZip'],
            'locationParcelNumber'    => $row['locationParcelNumber'],
            'locationParcelNumberRaw' => $row['locationParcelNumberRaw'],
            'locationJurisdiction'    => $row['locationJurisdiction'],
            'locationCounty'          => $row['locationCounty'],
            'locationZone'            => $row['locationZone'],
            'locationLatitude'        => $row['locationLatitude'],
            'locationLongitude'       => $row['locationLongitude'],
            'locationIsBilling'       => (int)$row['locationIsBilling'],
            'locationIsNotValid'      => (int)$row['locationIsNotValid'],
            'locationIsLocationOnly'  => (int)$row['locationIsLocationOnly'],
            'locationEntityId'        => (int)$row['locationEntityId'],
            'locationDate'            => (int)$row['locationDate'],
            'locationUpdatedUnix'     => (int)$row['locationUpdatedUnix'],
            'createdDate'             => $createdDate,
            'lastActivity'            => $lastActivity,
            'entity' => !empty($row['entityId']) ? [
                'entityId'   => (int)$row['entityId'],
                'entityName' => $row['entityName']
            ] : null,
            'contactCount'     => 0,
            'orderCount'       => 0,
            'applicationCount' => 0,
            'noteCount'        => 0,
            'taskCount'        => 0
        ];

        // Audit
        if ($actorContactId > 0) {
            try {
                insertActionPrompt([
                    'contactId'         => $actorContactId,
                    'promptText'        => 'Update location profile',
                    'responseText'      => sprintf(
                        'Updated location #%d (%s).',
                        $targetLocationId,
                        $locationName
                    ),
                    'intent'            => 'locations.update',
                    'intentConfidence'  => 1.0,
                    'activitySessionId' => $activitySessionId,
                    'latitude'          => $latitude,
                    'longitude'         => $longitude,
                    // TODO: replace with dedicated LOCATION_UPDATE actionTypeId when added
                    'actionTypeId'      => 13,
                    'origin'            => ACTION_ORIGIN_USER,
                    'actionPayloadData' => [
                        'operation'        => 'locations.update',
                        'targetLocationId' => $targetLocationId,
                        'addressChanged'   => $addressChanged,
                        'fields'           => [
                            'locationName'         => $locationName,
                            'locationAddress'      => $locationAddress !== '' ? $locationAddress : null,
                            'locationAddressSuite' => $locationAddressSuite !== '' ? $locationAddressSuite : null,
                            'locationCity'         => $locationCity !== '' ? $locationCity : null,
                            'locationState'        => $locationState !== '' ? $locationState : null,
                            'locationZip'          => $locationZip !== '' ? $locationZip : null,
                            'locationIsNotValid'   => $locationIsNotValid,
                            'locationUpdatedUnix'  => $nowUnix
                        ]
                    ],
                    'actionResponseData' => [
                        'success'          => true,
                        'targetLocationId' => $targetLocationId,
                        'addressChanged'   => $addressChanged
                    ]
                ], $db);
            } catch (Throwable $e) {
                error_log('[askOpenAI] Location-update action logging failed: ' . $e->getMessage());
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success'  => true,
            'type'     => 'location_update',
            'location' => $location
        ], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        error_log('[askOpenAI] locationUpdate failed: ' . $e->getMessage());

        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'location_update',
            'error'   => 'Update failed: ' . $e->getMessage()
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// =====================================================
// READ-ONLY LOCATION SEARCH (Order autocomplete)
// =====================================================

if ($type === 'locationSearch') {

    // Resolve search request
    $query = trim((string)($input['query'] ?? ''));
    $limit = max(1, min(10, (int)($input['limit'] ?? 10)));

    // Require enough input for a useful search
    if (strlen($query) < 2) {
        echo json_encode([
            'success'   => true,
            'type'      => 'location_search',
            'query'     => $query,
            'locations' => []
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Search authoritative Locations
    $locations = searchLocations(
        $db,
        $query,
        $limit
    );

    echo json_encode([
        'success'   => true,
        'type'      => 'location_search',
        'query'     => $query,
        'locations' => $locations
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

// =====================================================
// READ-ONLY LOCATION DETAIL
// =====================================================

if ($type === 'locationDetail') {

    // Resolve requested identifier
    $identifier = trim((string)($input['identifier'] ?? ''));

    // Resolve action context
    $actorContactId = (int)($_SESSION['SKYESOFT_contactId']
                    ?? $_SESSION['contactId']
                    ?? 0);

    $activitySessionId = $_SESSION['activitySessionId']
                      ?? session_id();

    $latitude  = $input['latitude']  ?? null;
    $longitude = $input['longitude'] ?? null;

    // Load requested location
    $location = loadLocationDetail($db, $identifier);

    // Record successful location READ
    if ($location !== null && $actorContactId > 0) {
        try {
            insertActionPrompt([
                'contactId'          => $actorContactId,
                'promptText'         => 'Open location profile',
                'responseText'       => sprintf(
                    'Displayed location profile #%d (%s).',
                    (int)($location['locationId'] ?? 0),
                    $location['locationName'] ?? $identifier
                ),
                'intent'             => 'locations.read',
                'intentConfidence'   => 1.00,
                'activitySessionId'  => $activitySessionId,
                'latitude'           => $latitude,
                'longitude'          => $longitude,
                'actionTypeId'       => 13,          // same READ action type used by entities/contacts
                'origin'             => ACTION_ORIGIN_USER,
                'actionPayloadData'  => [
                    'operation'         => 'locations.read',
                    'targetIdentifier'  => $identifier,
                    'targetLocationId'  => (int)($location['locationId'] ?? 0)
                ],
                'actionResponseData' => [
                    'success'          => true,
                    'targetLocationId' => (int)($location['locationId'] ?? 0)
                ]
            ], $db);

        } catch (Throwable $e) {
            error_log(
                '[askOpenAI] Location-read action logging failed: ' .
                $e->getMessage()
            );
        }
    }

    // Return location response
    header('Content-Type: application/json');

    echo json_encode([
        'success'  => $location !== null,
        'type'     => 'location_detail',
        'location' => $location,
        'error'    => $location === null
            ? 'Location not found.'
            : null
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

// =====================================================
// READ-ONLY ORDER CREATE OPTIONS
// =====================================================

if ($type === 'orderCreateOptions') {

    // Resolve selected jobsite
    $locationId = (int)($input['locationID'] ?? 0);

    if ($locationId <= 0) {
        echo json_encode([
            'success' => false,
            'type'    => 'order_create_options',
            'error'   => 'Valid locationID is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        // Confirm authoritative Location and Entity
        $locationStmt = $db->prepare("
            SELECT
                l.locationId,
                l.locationEntityId,
                l.locationName,
                l.locationAddress,
                l.locationAddressSuite,
                l.locationCity,
                l.locationState,
                l.locationZip,
                e.entityName
            FROM tblLocations l
            LEFT JOIN tblEntities e
                ON e.entityId = l.locationEntityId
            WHERE l.locationId = :locationId
              AND COALESCE(l.locationIsNotValid, 0) = 0
            LIMIT 1
        ");

        $locationStmt->execute([
            'locationId' => $locationId
        ]);

        $location = $locationStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($location)) {
            echo json_encode([
                'success' => false,
                'type'    => 'order_create_options',
                'error'   => 'Jobsite Location was not found.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $entityId = (int)(
            $location['locationEntityId'] ?? 0
        );

        if ($entityId <= 0) {
            echo json_encode([
                'success' => false,
                'type'    => 'order_create_options',
                'error'   => 'Jobsite Location has no valid Entity.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Load authoritative Order Types
        $typeStmt = $db->query("
            SELECT
                orderTypeID,
                orderTypeName,
                orderTypeDescription
            FROM tblOrderTypes
            ORDER BY orderTypeID ASC
        ");

        $orderTypes = $typeStmt
            ? $typeStmt->fetchAll(PDO::FETCH_ASSOC)
            : [];

        // Load authoritative Order Statuses
        $statusStmt = $db->query("
            SELECT
                orderStatusID,
                orderStatusName,
                orderStatusDescription
            FROM tblOrderStatuses
            ORDER BY orderStatusID ASC
        ");

        $orderStatuses = $statusStmt
            ? $statusStmt->fetchAll(PDO::FETCH_ASSOC)
            : [];

        // Resolve Active status without assuming its ID
        $activeStatusID = null;

        foreach ($orderStatuses as $status) {
            if (
                strtolower(
                    trim((string)$status['orderStatusName'])
                ) === 'active'
            ) {
                $activeStatusID = (int)$status[
                    'orderStatusID'
                ];
                break;
            }
        }

        // Load active Contacts at selected jobsite
        $jobsiteContactStmt = $db->prepare("
            SELECT
                c.contactId,
                c.contactEntityId,
                c.contactLocationId,
                c.contactSalutation,
                c.contactFirstName,
                c.contactLastName,
                c.contactTitle,
                c.contactPrimaryPhone,
                c.contactEmail,
                c.contactIsBilling,
                e.entityName
            FROM tblContacts c
            LEFT JOIN tblEntities e
                ON e.entityId = c.contactEntityId
            WHERE c.contactLocationId = :locationId
              AND COALESCE(c.contactIsNotValid, 0) = 0
              AND COALESCE(c.isActive, 1) = 1
            ORDER BY
                c.contactLastName ASC,
                c.contactFirstName ASC,
                c.contactId ASC
        ");

        $jobsiteContactStmt->execute([
            'locationId' => $locationId
        ]);

        $jobsiteContacts = $jobsiteContactStmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        // Load active Billing Contacts for selected Entity
        $billingContactStmt = $db->prepare("
            SELECT
                c.contactId,
                c.contactEntityId,
                c.contactLocationId,
                c.contactSalutation,
                c.contactFirstName,
                c.contactLastName,
                c.contactTitle,
                c.contactPrimaryPhone,
                c.contactEmail,
                c.contactIsBilling,
                e.entityName,
                bl.locationName AS billingLocationName,
                bl.locationAddress AS billingLocationAddress,
                bl.locationAddressSuite AS billingLocationAddressSuite,
                bl.locationCity AS billingLocationCity,
                bl.locationState AS billingLocationState,
                bl.locationZip AS billingLocationZip
            FROM tblContacts c
            LEFT JOIN tblEntities e
                ON e.entityId = c.contactEntityId
            LEFT JOIN tblLocations bl
                ON bl.locationId = c.contactLocationId
               AND COALESCE(bl.locationIsNotValid, 0) = 0
            WHERE c.contactEntityId = :entityId
              AND COALESCE(c.contactIsBilling, 0) = 1
              AND COALESCE(c.contactIsNotValid, 0) = 0
              AND COALESCE(c.isActive, 1) = 1
            ORDER BY
                c.contactLastName ASC,
                c.contactFirstName ASC,
                c.contactId ASC
        ");

        $billingContactStmt->execute([
            'entityId' => $entityId
        ]);

        $billingContacts = $billingContactStmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        // Resolve authenticated salesperson
        $salespersonID = (int)(
            $_SESSION['SKYESOFT_contactId']
            ?? $_SESSION['contactId']
            ?? 1
        );

        if ($salespersonID <= 0) {
            $salespersonID = 1;
        }

        // Normalize Location identifiers
        $location['locationId'] = (int)$location[
            'locationId'
        ];

        $location['locationEntityId'] = (int)$location[
            'locationEntityId'
        ];

        // Normalize Order Type identifiers
        foreach ($orderTypes as &$orderType) {
            $orderType['orderTypeID'] = (int)$orderType[
                'orderTypeID'
            ];
        }
        unset($orderType);

        // Normalize Order Status identifiers
        foreach ($orderStatuses as &$orderStatus) {
            $orderStatus['orderStatusID'] = (int)$orderStatus[
                'orderStatusID'
            ];
        }
        unset($orderStatus);

        // Normalize Jobsite Contact identifiers
        foreach ($jobsiteContacts as &$jobsiteContact) {
            $jobsiteContact['contactId'] = (int)$jobsiteContact[
                'contactId'
            ];

            $jobsiteContact['contactEntityId'] =
                (int)$jobsiteContact[
                    'contactEntityId'
                ];

            $jobsiteContact['contactLocationId'] =
                $jobsiteContact['contactLocationId'] !== null
                    ? (int)$jobsiteContact[
                        'contactLocationId'
                    ]
                    : null;

            $jobsiteContact['contactIsBilling'] =
                (int)$jobsiteContact[
                    'contactIsBilling'
                ];
        }
        unset($jobsiteContact);

        // Normalize Billing Contact identifiers
        foreach ($billingContacts as &$billingContact) {
            $billingContact['contactId'] = (int)$billingContact[
                'contactId'
            ];

            $billingContact['contactEntityId'] =
                (int)$billingContact[
                    'contactEntityId'
                ];

            $billingContact['contactLocationId'] =
                $billingContact['contactLocationId'] !== null
                    ? (int)$billingContact[
                        'contactLocationId'
                    ]
                    : null;

            $billingContact['contactIsBilling'] =
                (int)$billingContact[
                    'contactIsBilling'
                ];
        }
        unset($billingContact);

        echo json_encode([
            'success'         => true,
            'type'            => 'order_create_options',
            'location'        => $location,
            'orderTypes'      => $orderTypes,
            'orderStatuses'   => $orderStatuses,
            'jobsiteContacts' => $jobsiteContacts,
            'billingContacts' => $billingContacts,
            'defaults'        => [
                'statusID'      => $activeStatusID,
                'salespersonID' => $salespersonID,
                'orderDateUnix' => time()
            ]
        ], JSON_UNESCAPED_SLASHES);

        exit;

    } catch (Throwable $e) {
        error_log(
            '[askOpenAI] orderCreateOptions failed: ' .
            $e->getMessage()
        );

        echo json_encode([
            'success' => false,
            'type'    => 'order_create_options',
            'error'   => 'Unable to load Order creation options.'
        ], JSON_UNESCAPED_SLASHES);

        exit;
    }
}

// =====================================================
// AUTHORITATIVE ORDER DETAIL LOADER
// Performs no Action logging
// =====================================================

/**
 * Load an authoritative Order without creating an Action.
 *
 * @param PDO         $db
 * @param int|null    $orderId
 * @param string|null $christyNumber
 * @return array|null
 */
function loadAuthoritativeOrderDetail(
    PDO $db,
    ?int $orderId = null,
    ?string $christyNumber = null
): ?array {
    $resolvedOrderId = (int)($orderId ?? 0);

    $resolvedChristyNumber = trim(
        (string)($christyNumber ?? '')
    );

    // Require one authoritative identifier
    if (
        $resolvedOrderId <= 0 &&
        $resolvedChristyNumber === ''
    ) {
        return null;
    }

    // Select authoritative identifier
    if ($resolvedOrderId > 0) {
        $identifierCondition =
            'o.orderID = :identifier';

        $identifierValue =
            $resolvedOrderId;

    } else {
        $identifierCondition =
            'o.orderChristyNumber = :identifier';

        $identifierValue =
            $resolvedChristyNumber;
    }

    // Load authoritative Order
    $orderStmt = $db->prepare("
        SELECT
            o.*,

            ot.orderTypeName,
            ot.orderTypeDescription,

            os.orderStatusName,
            os.orderStatusDescription,

            e.entityName,

            jl.locationName,
            jl.locationAddress,
            jl.locationAddressSuite,
            jl.locationCity,
            jl.locationState,
            jl.locationZip,

            bc.contactFirstName
                AS billToFirstName,
            bc.contactLastName
                AS billToLastName,
            bc.contactTitle
                AS billToTitle,
            bc.contactPrimaryPhone
                AS billToPrimaryPhone,
            bc.contactEmail
                AS billToEmail,
            bc.contactLocationId
                AS billingLocationID,

            bl.locationName
                AS billingLocationName,
            bl.locationAddress
                AS billingLocationAddress,
            bl.locationAddressSuite
                AS billingLocationAddressSuite,
            bl.locationCity
                AS billingLocationCity,
            bl.locationState
                AS billingLocationState,
            bl.locationZip
                AS billingLocationZip,

            jc.contactFirstName
                AS jobsiteFirstName,
            jc.contactLastName
                AS jobsiteLastName,
            jc.contactTitle
                AS jobsiteTitle,
            jc.contactPrimaryPhone
                AS jobsitePrimaryPhone,
            jc.contactEmail
                AS jobsiteEmail

        FROM tblOrders o

        LEFT JOIN tblOrderTypes ot
            ON ot.orderTypeID = o.orderTypeID

        LEFT JOIN tblOrderStatuses os
            ON os.orderStatusID = o.orderStatusID

        LEFT JOIN tblEntities e
            ON e.entityId = o.orderEntityID

        LEFT JOIN tblLocations jl
            ON jl.locationId = o.orderLocationID

        LEFT JOIN tblContacts bc
            ON bc.contactId = o.orderBillToContactID

        LEFT JOIN tblLocations bl
            ON bl.locationId = bc.contactLocationId

        LEFT JOIN tblContacts jc
            ON jc.contactId = o.orderJobsiteContactID

        WHERE {$identifierCondition}
          AND COALESCE(o.orderIsNotValid, 0) = 0

        LIMIT 1
    ");

    $orderStmt->execute([
        'identifier' => $identifierValue
    ]);

    $row = $orderStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (!is_array($row)) {
        return null;
    }

    // Normalize related identifiers
    $billToContactId =
        $row['orderBillToContactID'] !== null
            ? (int)$row['orderBillToContactID']
            : null;

    $jobsiteContactId =
        $row['orderJobsiteContactID'] !== null
            ? (int)$row['orderJobsiteContactID']
            : null;

    $billingLocationId =
        $row['billingLocationID'] !== null
            ? (int)$row['billingLocationID']
            : null;

    // Return complete authoritative Order
    return [
        'orderID' =>
            (int)$row['orderID'],

        'orderChristyNumber' =>
            $row['orderChristyNumber'],

        'orderTypeID' =>
            $row['orderTypeID'] !== null
                ? (int)$row['orderTypeID']
                : null,

        'orderTypeName' =>
            $row['orderTypeName'],

        'orderTypeDescription' =>
            $row['orderTypeDescription'],

        'orderIsProposal' =>
            (int)$row['orderIsProposal'],

        'orderEntityID' =>
            (int)$row['orderEntityID'],

        'entityName' =>
            $row['entityName'],

        'orderLocationID' =>
            (int)$row['orderLocationID'],

        'locationName' =>
            $row['locationName'],

        'locationAddress' =>
            $row['locationAddress'],

        'locationAddressSuite' =>
            $row['locationAddressSuite'],

        'locationCity' =>
            $row['locationCity'],

        'locationState' =>
            $row['locationState'],

        'locationZip' =>
            $row['locationZip'],

        'orderBillToContactID' =>
            $billToContactId,

        'billToContact' =>
            $billToContactId !== null
                ? [
                    'contactId' =>
                        $billToContactId,
                    'contactFirstName' =>
                        $row['billToFirstName'],
                    'contactLastName' =>
                        $row['billToLastName'],
                    'contactTitle' =>
                        $row['billToTitle'],
                    'contactPrimaryPhone' =>
                        $row['billToPrimaryPhone'],
                    'contactEmail' =>
                        $row['billToEmail']
                ]
                : null,

        'billingLocationID' =>
            $billingLocationId,

        'billingLocation' =>
            $billingLocationId !== null
                ? [
                    'locationId' =>
                        $billingLocationId,
                    'locationName' =>
                        $row['billingLocationName'],
                    'locationAddress' =>
                        $row['billingLocationAddress'],
                    'locationAddressSuite' =>
                        $row['billingLocationAddressSuite'],
                    'locationCity' =>
                        $row['billingLocationCity'],
                    'locationState' =>
                        $row['billingLocationState'],
                    'locationZip' =>
                        $row['billingLocationZip']
                ]
                : null,

        'orderJobsiteContactID' =>
            $jobsiteContactId,

        'jobsiteContact' =>
            $jobsiteContactId !== null
                ? [
                    'contactId' =>
                        $jobsiteContactId,
                    'contactFirstName' =>
                        $row['jobsiteFirstName'],
                    'contactLastName' =>
                        $row['jobsiteLastName'],
                    'contactTitle' =>
                        $row['jobsiteTitle'],
                    'contactPrimaryPhone' =>
                        $row['jobsitePrimaryPhone'],
                    'contactEmail' =>
                        $row['jobsiteEmail']
                ]
                : null,

        'orderSalespersonID' =>
            $row['orderSalespersonID'] !== null
                ? (int)$row['orderSalespersonID']
                : null,

        'orderSalespersonName' =>
            $row['orderSalespersonName'],

        'orderDate' =>
            (int)$row['orderDate'],

        'orderDueDate' =>
            $row['orderDueDate'] !== null
                ? (int)$row['orderDueDate']
                : null,

        'orderPurchaseOrder' =>
            $row['orderPurchaseOrder'],

        'orderScope' =>
            $row['orderScope'],

        'orderStatusID' =>
            $row['orderStatusID'] !== null
                ? (int)$row['orderStatusID']
                : null,

        'orderStatusName' =>
            $row['orderStatusName'],

        'orderStatusDescription' =>
            $row['orderStatusDescription'],

        'progress' => [
            'designComplete' =>
                (bool)$row['orderIsDesignComplete'],
            'estimateComplete' =>
                (bool)$row['orderIsEstimateComplete'],
            'sold' =>
                (bool)$row['orderIsSold'],
            'requiresPermit' =>
                (bool)$row['orderRequiresPermit'],
            'permitComplete' =>
                (bool)$row['orderIsPermitComplete'],
            'fulfillmentComplete' =>
                (bool)$row['orderIsFulfillmentComplete'],
            'completed' =>
                (bool)$row['orderIsCompleted'],
            'requiresInspection' =>
                (bool)$row['orderRequiresInspection'],
            'inspectionComplete' =>
                (bool)$row['orderIsInspectionComplete'],
            'collected' =>
                (bool)$row['orderIsCollected'],
            'closedOut' =>
                (bool)$row['orderIsClosedOut']
        ],

        'orderNote' =>
            $row['orderNote'],

        'orderCreatedAt' =>
            (int)$row['orderCreatedAt'],

        'orderUpdatedAt' =>
            $row['orderUpdatedAt'] !== null
                ? (int)$row['orderUpdatedAt']
                : null,

        'orderIsNotValid' =>
            (int)$row['orderIsNotValid']
    ];
}

// =====================================================
// READ-ONLY ORDER DETAIL
// =====================================================

if ($type === 'orderDetail') {

    // Resolve requested Order identifier
    $orderId = (int)(
        $input['orderID'] ?? 0
    );

    $christyNumber = trim((string)(
        $input['christyNumber']
        ?? $input['identifier']
        ?? ''
    ));

    if (
        $orderId <= 0 &&
        $christyNumber === ''
    ) {
        echo json_encode([
            'success' => false,
            'type'    => 'order_detail',
            'order'   => null,
            'error'   =>
                'orderID or Christy Work Order number is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Resolve authoritative Action context
    $actorContactId = (int)(
        $_SESSION['SKYESOFT_contactId']
        ?? $_SESSION['contactId']
        ?? 0
    );

    $activitySessionId = trim((string)(
        $_SESSION['activitySessionId']
        ?? session_id()
    ));

    $activitySessionId =
        $activitySessionId !== ''
            ? $activitySessionId
            : null;

    $latitude = is_numeric(
        $input['latitude'] ?? null
    )
        ? (float)$input['latitude']
        : null;

    $longitude = is_numeric(
        $input['longitude'] ?? null
    )
        ? (float)$input['longitude']
        : null;

    try {
        // Load authoritative Order without logging
        $order = loadAuthoritativeOrderDetail(
            $db,
            $orderId > 0
                ? $orderId
                : null,
            $christyNumber !== ''
                ? $christyNumber
                : null
        );

        if (!is_array($order)) {
            echo json_encode([
                'success' => false,
                'type'    => 'order_detail',
                'order'   => null,
                'error'   => 'Order was not found.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $resolvedOrderId = (int)(
            $order['orderID'] ?? 0
        );

        $resolvedChristyNumber =
            $order['orderChristyNumber']
            ?? null;

        // Resolve order.read Action Type
        $actionTypeStmt = $db->prepare("
            SELECT actionTypeId
            FROM tblActionTypes
            WHERE actionName = 'order.read'
              AND crud_class = 'read'
            LIMIT 1
        ");

        $actionTypeStmt->execute();

        $orderReadActionTypeId = (int)(
            $actionTypeStmt->fetchColumn() ?: 0
        );

        $actionId = null;

        // Record user-generated Order read
        if (
            $actorContactId > 0 &&
            $orderReadActionTypeId > 0
        ) {
            $actionId = insertActionPrompt([
                'actionTypeId' =>
                    $orderReadActionTypeId,

                'contactId' =>
                    $actorContactId,

                'origin' =>
                    ACTION_ORIGIN_USER,

                'activitySessionId' =>
                    $activitySessionId,

                'promptText' =>
                    'Open Order',

                'responseText' =>
                    sprintf(
                        'Opened Order #%d%s.',
                        $resolvedOrderId,
                        !empty($resolvedChristyNumber)
                            ? ' — Christy Work Order ' .
                                $resolvedChristyNumber
                            : ''
                    ),

                'intent' =>
                    'order.read',

                'intentConfidence' =>
                    1.00,

                'latitude' =>
                    $latitude,

                'longitude' =>
                    $longitude,

                'actionPayloadData' => [
                    'operation' =>
                        'order.read',

                    'requestedOrderID' =>
                        $orderId > 0
                            ? $orderId
                            : null,

                    'requestedChristyNumber' =>
                        $christyNumber !== ''
                            ? $christyNumber
                            : null
                ],

                'actionResponseData' => [
                    'success' =>
                        true,

                    'orderID' =>
                        $resolvedOrderId,

                    'christyNumber' =>
                        $resolvedChristyNumber
                ]
            ], $db);

            if ($actionId <= 0) {
                error_log(
                    '[askOpenAI] Order-read Action logging failed.'
                );

                $actionId = null;
            }
        }

        // Return authoritative Order
        echo json_encode([
            'success'  => true,
            'type'     => 'order_detail',
            'order'    => $order,
            'actionID' => $actionId,
            'error'    => null
        ], JSON_UNESCAPED_SLASHES);

        exit;

    } catch (Throwable $e) {
        error_log(
            '[askOpenAI] orderDetail failed: ' .
            $e->getMessage()
        );

        echo json_encode([
            'success' => false,
            'type'    => 'order_detail',
            'order'   => null,
            'error'   => 'Unable to load Order.'
        ], JSON_UNESCAPED_SLASHES);

        exit;
    }
}

// =====================================================
// ORDER UPDATE (mutation) — Order Edit v1.0
// =====================================================

if ($type === 'orderUpdate') {

    // Return governed Order-update error
    $returnOrderUpdateError = static function (
        string $message
    ): never {
        echo json_encode([
            'success' => false,
            'type'    => 'order_update',
            'error'   => $message
        ], JSON_UNESCAPED_SLASHES);
        exit;
    };

    // Resolve authenticated actor
    $actorContactId = (int)(
        $_SESSION['SKYESOFT_contactId']
        ?? $_SESSION['contactId']
        ?? 0
    );

    if ($actorContactId <= 0) {
        $returnOrderUpdateError(
            'An authenticated Contact is required.'
        );
    }

    // Resolve authoritative activity session
    $activitySessionId = trim((string)(
        $_SESSION['activitySessionId']
        ?? session_id()
    ));

    $activitySessionId = $activitySessionId !== ''
        ? $activitySessionId
        : null;

    // Resolve Action audit coordinates
    $latitude = is_numeric(
        $input['latitude'] ?? null
    )
        ? (float)$input['latitude']
        : null;

    $longitude = is_numeric(
        $input['longitude'] ?? null
    )
        ? (float)$input['longitude']
        : null;

    // Resolve payload sections
    $jobsite = is_array(
        $input['jobsite'] ?? null
    )
        ? $input['jobsite']
        : [];

    $billing = is_array(
        $input['billing'] ?? null
    )
        ? $input['billing']
        : [];

    $orderInput = is_array(
        $input['order'] ?? null
    )
        ? $input['order']
        : [];

    // Resolve submitted identifiers
    $orderId = (int)(
        $input['orderID'] ?? 0
    );

    $jobsiteContactId = (int)(
        $jobsite['jobsiteContactID'] ?? 0
    );

    $billToContactId = (int)(
        $billing['billToContactID'] ?? 0
    );

    $orderTypeId = (int)(
        $orderInput['typeID'] ?? 0
    );

    $orderStatusId = (int)(
        $orderInput['statusID'] ?? 0
    );

    // Resolve submitted Order values
    $christyNumber = trim((string)(
        $orderInput['christyNumber'] ?? ''
    ));

    $isProposal = !empty(
        $orderInput['isProposal']
    ) ? 1 : 0;

    $orderDate = (int)(
        $orderInput['date'] ?? 0
    );

    $orderDueDate = (int)(
        $orderInput['dueDate'] ?? 0
    );

    $purchaseOrder = trim((string)(
        $orderInput['purchaseOrder'] ?? ''
    ));

    $orderScope = trim((string)(
        $orderInput['scope'] ?? ''
    ));

    $orderNote = trim((string)(
        $orderInput['note'] ?? ''
    ));

    // Validate required submitted values
    if ($orderId <= 0) {
        $returnOrderUpdateError(
            'A valid Order ID is required.'
        );
    }

    if ($billToContactId <= 0) {
        $returnOrderUpdateError(
            'A valid Bill-To Contact is required.'
        );
    }

    if ($orderTypeId <= 0) {
        $returnOrderUpdateError(
            'A valid Order Type is required.'
        );
    }

    if ($orderStatusId <= 0) {
        $returnOrderUpdateError(
            'A valid Order Status is required.'
        );
    }

    if ($orderDate <= 0) {
        $returnOrderUpdateError(
            'A valid Unix Order Date is required.'
        );
    }

    // Enforce database field lengths
    $christyNumber = mb_substr(
        $christyNumber,
        0,
        20
    );

    $purchaseOrder = mb_substr(
        $purchaseOrder,
        0,
        100
    );

    // Normalize nullable values
    $christyNumber = $christyNumber !== ''
        ? $christyNumber
        : null;

    $jobsiteContactId = $jobsiteContactId > 0
        ? $jobsiteContactId
        : null;

    $orderDueDate = $orderDueDate > 0
        ? $orderDueDate
        : null;

    $purchaseOrder = $purchaseOrder !== ''
        ? $purchaseOrder
        : null;

    $orderScope = $orderScope !== ''
        ? $orderScope
        : null;

    $orderNote = $orderNote !== ''
        ? $orderNote
        : null;

    try {
        // Confirm authenticated actor
        $actorStmt = $db->prepare("
            SELECT contactId
            FROM tblContacts
            WHERE contactId = :contactId
              AND COALESCE(contactIsNotValid, 0) = 0
              AND COALESCE(isActive, 1) = 1
            LIMIT 1
        ");

        $actorStmt->execute([
            'contactId' => $actorContactId
        ]);

        if (!$actorStmt->fetchColumn()) {
            $returnOrderUpdateError(
                'Authenticated Contact was not found.'
            );
        }

        // Load existing authoritative Order
        $existingStmt = $db->prepare("
            SELECT
                orderID,
                orderChristyNumber,
                orderTypeID,
                orderIsProposal,
                orderEntityID,
                orderLocationID,
                orderBillToContactID,
                orderJobsiteContactID,
                orderSalespersonID,
                orderSalespersonName,
                orderDate,
                orderDueDate,
                orderPurchaseOrder,
                orderScope,
                orderStatusID,
                orderNote,
                orderCreatedAt,
                orderUpdatedAt
            FROM tblOrders
            WHERE orderID = :orderId
              AND COALESCE(orderIsNotValid, 0) = 0
            LIMIT 1
        ");

        $existingStmt->execute([
            'orderId' => $orderId
        ]);

        $existingOrder = $existingStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($existingOrder)) {
            $returnOrderUpdateError(
                'Order was not found.'
            );
        }

        // Preserve immutable Order relationships
        $orderEntityId = (int)(
            $existingOrder['orderEntityID'] ?? 0
        );

        $locationId = (int)(
            $existingOrder['orderLocationID'] ?? 0
        );

        if (
            $orderEntityId <= 0 ||
            $locationId <= 0
        ) {
            $returnOrderUpdateError(
                'Order customer or jobsite data is invalid.'
            );
        }

        // Confirm authoritative Order Type
        $typeStmt = $db->prepare("
            SELECT
                orderTypeID,
                orderTypeName
            FROM tblOrderTypes
            WHERE orderTypeID = :orderTypeId
            LIMIT 1
        ");

        $typeStmt->execute([
            'orderTypeId' => $orderTypeId
        ]);

        $orderType = $typeStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($orderType)) {
            $returnOrderUpdateError(
                'Order Type was not found.'
            );
        }

        // Confirm authoritative Order Status
        $statusStmt = $db->prepare("
            SELECT
                orderStatusID,
                orderStatusName
            FROM tblOrderStatuses
            WHERE orderStatusID = :orderStatusId
            LIMIT 1
        ");

        $statusStmt->execute([
            'orderStatusId' => $orderStatusId
        ]);

        $orderStatus = $statusStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($orderStatus)) {
            $returnOrderUpdateError(
                'Order Status was not found.'
            );
        }

        // Confirm authoritative Billing Contact
        $billingStmt = $db->prepare("
            SELECT
                c.contactId,
                c.contactEntityId,
                c.contactLocationId
            FROM tblContacts c
            INNER JOIN tblLocations bl
                ON bl.locationId = c.contactLocationId
               AND COALESCE(
                    bl.locationIsNotValid,
                    0
               ) = 0
            WHERE c.contactId = :contactId
              AND c.contactEntityId = :entityId
              AND COALESCE(c.contactIsBilling, 0) = 1
              AND COALESCE(c.contactIsNotValid, 0) = 0
              AND COALESCE(c.isActive, 1) = 1
            LIMIT 1
        ");

        $billingStmt->execute([
            'contactId' => $billToContactId,
            'entityId'  => $orderEntityId
        ]);

        $billingContact = $billingStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($billingContact)) {
            $returnOrderUpdateError(
                'The selected Bill-To Contact is not valid for this Entity.'
            );
        }

        $billingLocationId = (int)(
            $billingContact['contactLocationId'] ?? 0
        );

        if ($billingLocationId <= 0) {
            $returnOrderUpdateError(
                'Bill-To Contact has no valid Billing Location.'
            );
        }

        // Confirm optional Jobsite Contact
        if ($jobsiteContactId !== null) {
            $jobsiteContactStmt = $db->prepare("
                SELECT contactId
                FROM tblContacts
                WHERE contactId = :contactId
                  AND contactLocationId = :locationId
                  AND contactEntityId = :entityId
                  AND COALESCE(
                        contactIsNotValid,
                        0
                  ) = 0
                  AND COALESCE(isActive, 1) = 1
                LIMIT 1
            ");

            $jobsiteContactStmt->execute([
                'contactId'  => $jobsiteContactId,
                'locationId' => $locationId,
                'entityId'   => $orderEntityId
            ]);

            if (!$jobsiteContactStmt->fetchColumn()) {
                $returnOrderUpdateError(
                    'The selected Jobsite Contact is not valid.'
                );
            }
        }

        // Confirm unique Christy Work Order number
        if ($christyNumber !== null) {
            $numberStmt = $db->prepare("
                SELECT orderID
                FROM tblOrders
                WHERE orderChristyNumber = :christyNumber
                  AND orderID <> :orderId
                LIMIT 1
            ");

            $numberStmt->execute([
                'christyNumber' => $christyNumber,
                'orderId'       => $orderId
            ]);

            if ($numberStmt->fetchColumn()) {
                $returnOrderUpdateError(
                    'That Christy Work Order number already exists.'
                );
            }
        }

        // Resolve authoritative order.update Action Type
        $actionTypeStmt = $db->prepare("
            SELECT actionTypeId
            FROM tblActionTypes
            WHERE actionName = :actionName
              AND crud_class = 'update'
            LIMIT 1
        ");

        $actionTypeStmt->execute([
            'actionName' => 'order.update'
        ]);

        $orderUpdateActionTypeId = (int)(
            $actionTypeStmt->fetchColumn() ?: 0
        );

        if ($orderUpdateActionTypeId <= 0) {
            $returnOrderUpdateError(
                'Order update Action Type is not configured.'
            );
        }

        // Initialize authoritative timestamp
        $updatedUnix = time();

        // Build normalized before state
        $beforeState = [
            'christyNumber' => $existingOrder[
                'orderChristyNumber'
            ],
            'typeID' => (int)$existingOrder[
                'orderTypeID'
            ],
            'isProposal' => (bool)$existingOrder[
                'orderIsProposal'
            ],
            'statusID' => (int)$existingOrder[
                'orderStatusID'
            ],
            'jobsiteContactID' =>
                $existingOrder['orderJobsiteContactID']
                    !== null
                        ? (int)$existingOrder[
                            'orderJobsiteContactID'
                        ]
                        : null,
            'billToContactID' => (int)$existingOrder[
                'orderBillToContactID'
            ],
            'date' => (int)$existingOrder[
                'orderDate'
            ],
            'dueDate' =>
                $existingOrder['orderDueDate'] !== null
                    ? (int)$existingOrder[
                        'orderDueDate'
                    ]
                    : null,
            'purchaseOrder' => $existingOrder[
                'orderPurchaseOrder'
            ],
            'scope' => $existingOrder[
                'orderScope'
            ],
            'note' => $existingOrder[
                'orderNote'
            ]
        ];

        // Build normalized after state
        $afterState = [
            'christyNumber' => $christyNumber,
            'typeID'        => $orderTypeId,
            'isProposal'    => (bool)$isProposal,
            'statusID'      => $orderStatusId,
            'jobsiteContactID' =>
                $jobsiteContactId,
            'billToContactID' =>
                $billToContactId,
            'date'          => $orderDate,
            'dueDate'       => $orderDueDate,
            'purchaseOrder' => $purchaseOrder,
            'scope'         => $orderScope,
            'note'          => $orderNote
        ];

        // Begin atomic Order and Action update
        $db->beginTransaction();

        // Update authoritative Order
        $updateStmt = $db->prepare("
            UPDATE tblOrders
            SET
                orderChristyNumber = :christyNumber,
                orderTypeID = :orderTypeId,
                orderIsProposal = :isProposal,
                orderBillToContactID = :billToContactId,
                orderJobsiteContactID = :jobsiteContactId,
                orderDate = :orderDate,
                orderDueDate = :orderDueDate,
                orderPurchaseOrder = :purchaseOrder,
                orderScope = :orderScope,
                orderStatusID = :orderStatusId,
                orderNote = :orderNote,
                orderUpdatedAt = :updatedUnix
            WHERE orderID = :orderId
              AND COALESCE(orderIsNotValid, 0) = 0
        ");

        $updateStmt->execute([
            'christyNumber'   => $christyNumber,
            'orderTypeId'     => $orderTypeId,
            'isProposal'      => $isProposal,
            'billToContactId' => $billToContactId,
            'jobsiteContactId'=> $jobsiteContactId,
            'orderDate'       => $orderDate,
            'orderDueDate'    => $orderDueDate,
            'purchaseOrder'   => $purchaseOrder,
            'orderScope'      => $orderScope,
            'orderStatusId'   => $orderStatusId,
            'orderNote'       => $orderNote,
            'updatedUnix'     => $updatedUnix,
            'orderId'         => $orderId
        ]);

        // Reload complete authoritative Order inside transaction
        $updatedOrder = loadAuthoritativeOrderDetail(
            $db,
            $orderId,
            null
        );

        if (!is_array($updatedOrder)) {
            throw new RuntimeException(
                'Updated Order could not be reloaded.'
            );
        }

        // Build governed Action payload
        $actionPayloadData = [
            'operation' => 'order.update',
            'orderID'   => $orderId,
            'before'    => $beforeState,
            'after'     => $afterState
        ];

        // Insert required Order update Action
        $actionId = insertActionPrompt([
            'actionTypeId'      =>
                $orderUpdateActionTypeId,
            'contactId'         =>
                $actorContactId,
            'origin'            =>
                ACTION_ORIGIN_USER,
            'createdUnixTime'   =>
                $updatedUnix,
            'activitySessionId' =>
                $activitySessionId,
            'promptText'        =>
                'Update Order',
            'responseText'      => sprintf(
                'Updated Order #%d%s.',
                $orderId,
                $christyNumber !== null
                    ? ' — Christy Work Order ' .
                        $christyNumber
                    : ''
            ),
            'intent'            =>
                'order.update',
            'intentConfidence'  =>
                1.00,
            'latitude'          =>
                $latitude,
            'longitude'         =>
                $longitude,
            'actionPayloadData' =>
                $actionPayloadData,
            'actionResponseData'=> [
                'success'       => true,
                'orderID'       => $orderId,
                'christyNumber' => $christyNumber
            ]
        ], $db);

        if ($actionId <= 0) {
            throw new RuntimeException(
                'Order update Action history could not be created.'
            );
        }

        // Commit Order and Action together
        $db->commit();

        // Return complete updated authoritative Order
        echo json_encode([
            'success'  => true,
            'type'     => 'order_update',
            'order'    => $updatedOrder,
            'actionID' => $actionId,
            'error'    => null
        ], JSON_UNESCAPED_SLASHES);

        exit;

            } catch (Throwable $e) {
                // Roll back partial Order update
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                error_log(
                    '[askOpenAI] orderUpdate failed: ' .
                    $e->getMessage()
                );

                // Return duplicate-number conflict
                if (
                    $e instanceof PDOException &&
                    (string)$e->getCode() === '23000'
                ) {
                    $returnOrderUpdateError(
                        'That Christy Work Order number already exists.'
                    );
                }

                $returnOrderUpdateError(
                    'Unable to update the Order.'
                );
            }
        }

// =====================================================
// ORDER CREATE (mutation) — Order Creation v1.0
// =====================================================

if ($type === 'orderCreate') {

    // Return governed Order-creation error
    $returnOrderCreateError = static function (
        string $message
    ): never {
        echo json_encode([
            'success' => false,
            'type'    => 'order_create',
            'error'   => $message
        ], JSON_UNESCAPED_SLASHES);
        exit;
    };

    // Resolve authenticated actor
    $actorContactId = (int)(
        $_SESSION['SKYESOFT_contactId']
        ?? $_SESSION['contactId']
        ?? 0
    );

    if ($actorContactId <= 0) {
        $returnOrderCreateError(
            'An authenticated Contact is required.'
        );
    }

    // Resolve authoritative activity session
    $activitySessionId = trim((string)(
        $_SESSION['activitySessionId']
        ?? session_id()
    ));

    $activitySessionId = $activitySessionId !== ''
        ? $activitySessionId
        : null;

    // Resolve audit coordinates
    $latitude = is_numeric(
        $input['latitude'] ?? null
    )
        ? (float)$input['latitude']
        : null;

    $longitude = is_numeric(
        $input['longitude'] ?? null
    )
        ? (float)$input['longitude']
        : null;

    // Resolve payload sections
    $jobsite = is_array($input['jobsite'] ?? null)
        ? $input['jobsite']
        : [];

    $billing = is_array($input['billing'] ?? null)
        ? $input['billing']
        : [];

    $orderInput = is_array($input['order'] ?? null)
        ? $input['order']
        : [];

    // Resolve submitted identifiers
    $locationId = (int)(
        $jobsite['locationID'] ?? 0
    );

    $jobsiteContactId = (int)(
        $jobsite['jobsiteContactID'] ?? 0
    );

    $billToContactId = (int)(
        $billing['billToContactID'] ?? 0
    );

    $orderTypeId = (int)(
        $orderInput['typeID'] ?? 0
    );

    $orderStatusId = (int)(
        $orderInput['statusID'] ?? 0
    );

    // Resolve Order values
    $christyNumber = trim((string)(
        $orderInput['christyNumber'] ?? ''
    ));

    $isProposal = !empty(
        $orderInput['isProposal']
    ) ? 1 : 0;

    $orderDate = (int)(
        $orderInput['date'] ?? 0
    );

    $orderDueDate = (int)(
        $orderInput['dueDate'] ?? 0
    );

    $purchaseOrder = trim((string)(
        $orderInput['purchaseOrder'] ?? ''
    ));

    $orderScope = trim((string)(
        $orderInput['scope'] ?? ''
    ));

    $orderNote = trim((string)(
        $orderInput['note'] ?? ''
    ));

    // Validate required submitted values
    if ($locationId <= 0) {
        $returnOrderCreateError(
            'A valid jobsite Location is required.'
        );
    }

    if ($billToContactId <= 0) {
        $returnOrderCreateError(
            'A valid Bill-To Contact is required.'
        );
    }

    if ($orderTypeId <= 0) {
        $returnOrderCreateError(
            'A valid Order Type is required.'
        );
    }

    if ($orderStatusId <= 0) {
        $returnOrderCreateError(
            'A valid Order Status is required.'
        );
    }

    if ($orderDate <= 0) {
        $returnOrderCreateError(
            'A valid Unix Order Date is required.'
        );
    }

    // Enforce database field lengths
    $christyNumber = mb_substr(
        $christyNumber,
        0,
        20
    );

    $purchaseOrder = mb_substr(
        $purchaseOrder,
        0,
        100
    );

    // Normalize nullable values
    $christyNumber = $christyNumber !== ''
        ? $christyNumber
        : null;

    $jobsiteContactId = $jobsiteContactId > 0
        ? $jobsiteContactId
        : null;

    $orderDueDate = $orderDueDate > 0
        ? $orderDueDate
        : null;

    $purchaseOrder = $purchaseOrder !== ''
        ? $purchaseOrder
        : null;

    $orderScope = $orderScope !== ''
        ? $orderScope
        : null;

    $orderNote = $orderNote !== ''
        ? $orderNote
        : null;

    try {
        // Confirm authenticated actor and salesperson
        $actorStmt = $db->prepare("
            SELECT
                contactId,
                contactFirstName,
                contactLastName
            FROM tblContacts
            WHERE contactId = :contactId
              AND COALESCE(contactIsNotValid, 0) = 0
              AND COALESCE(isActive, 1) = 1
            LIMIT 1
        ");

        $actorStmt->execute([
            'contactId' => $actorContactId
        ]);

        $actor = $actorStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($actor)) {
            $returnOrderCreateError(
                'Authenticated salesperson was not found.'
            );
        }

        // Build authoritative salesperson name
        $salespersonName = trim(
            implode(' ', array_filter([
                $actor['contactFirstName'] ?? null,
                $actor['contactLastName'] ?? null
            ]))
        );

        $salespersonName = $salespersonName !== ''
            ? mb_substr($salespersonName, 0, 200)
            : null;

        // Confirm authoritative jobsite Location
        $locationStmt = $db->prepare("
            SELECT
                l.locationId,
                l.locationEntityId,
                l.locationName,
                e.entityName
            FROM tblLocations l
            INNER JOIN tblEntities e
                ON e.entityId = l.locationEntityId
            WHERE l.locationId = :locationId
              AND COALESCE(l.locationIsNotValid, 0) = 0
            LIMIT 1
        ");

        $locationStmt->execute([
            'locationId' => $locationId
        ]);

        $location = $locationStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($location)) {
            $returnOrderCreateError(
                'Jobsite Location was not found.'
            );
        }

        $jobsiteEntityId = (int)(
            $location['locationEntityId'] ?? 0
        );

        if ($jobsiteEntityId <= 0) {
            $returnOrderCreateError(
                'Jobsite Location has no valid Entity.'
            );
        }

        // Confirm authoritative Billing Contact
        $billingStmt = $db->prepare("
            SELECT
                c.contactId,
                c.contactEntityId,
                c.contactLocationId,
                c.contactFirstName,
                c.contactLastName,
                bl.locationName AS billingLocationName
            FROM tblContacts c
            INNER JOIN tblLocations bl
                ON bl.locationId = c.contactLocationId
               AND COALESCE(bl.locationIsNotValid, 0) = 0
            WHERE c.contactId = :contactId
              AND c.contactEntityId = :entityId
              AND COALESCE(c.contactIsBilling, 0) = 1
              AND COALESCE(c.contactIsNotValid, 0) = 0
              AND COALESCE(c.isActive, 1) = 1
            LIMIT 1
        ");

        $billingStmt->execute([
            'contactId' => $billToContactId,
            'entityId'  => $jobsiteEntityId
        ]);

        $billingContact = $billingStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($billingContact)) {
            $returnOrderCreateError(
                'The selected Bill-To Contact is not valid for this Entity.'
            );
        }

        // Billing Contact establishes customer Entity
        $orderEntityId = (int)(
            $billingContact['contactEntityId'] ?? 0
        );

        $billingLocationId = (int)(
            $billingContact['contactLocationId'] ?? 0
        );

        if (
            $orderEntityId <= 0 ||
            $billingLocationId <= 0
        ) {
            $returnOrderCreateError(
                'Bill-To Contact has no valid Billing Location.'
            );
        }

        // Confirm optional Jobsite Contact
        if ($jobsiteContactId !== null) {
            $jobsiteContactStmt = $db->prepare("
                SELECT contactId
                FROM tblContacts
                WHERE contactId = :contactId
                  AND contactLocationId = :locationId
                  AND contactEntityId = :entityId
                  AND COALESCE(contactIsNotValid, 0) = 0
                  AND COALESCE(isActive, 1) = 1
                LIMIT 1
            ");

            $jobsiteContactStmt->execute([
                'contactId' => $jobsiteContactId,
                'locationId' => $locationId,
                'entityId' => $jobsiteEntityId
            ]);

            if (!$jobsiteContactStmt->fetchColumn()) {
                $returnOrderCreateError(
                    'The selected Jobsite Contact is not valid.'
                );
            }
        }

        // Confirm authoritative Order Type
        $orderTypeStmt = $db->prepare("
            SELECT
                orderTypeID,
                orderTypeName
            FROM tblOrderTypes
            WHERE orderTypeID = :orderTypeId
            LIMIT 1
        ");

        $orderTypeStmt->execute([
            'orderTypeId' => $orderTypeId
        ]);

        $orderType = $orderTypeStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($orderType)) {
            $returnOrderCreateError(
                'Order Type was not found.'
            );
        }

        // Confirm authoritative Order Status
        $orderStatusStmt = $db->prepare("
            SELECT
                orderStatusID,
                orderStatusName
            FROM tblOrderStatuses
            WHERE orderStatusID = :orderStatusId
            LIMIT 1
        ");

        $orderStatusStmt->execute([
            'orderStatusId' => $orderStatusId
        ]);

        $orderStatus = $orderStatusStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($orderStatus)) {
            $returnOrderCreateError(
                'Order Status was not found.'
            );
        }

        // Confirm unique Christy Work Order number
        if ($christyNumber !== null) {
            $numberStmt = $db->prepare("
                SELECT orderID
                FROM tblOrders
                WHERE orderChristyNumber = :christyNumber
                LIMIT 1
            ");

            $numberStmt->execute([
                'christyNumber' => $christyNumber
            ]);

            if ($numberStmt->fetchColumn()) {
                $returnOrderCreateError(
                    'That Christy Work Order number already exists.'
                );
            }
        }

        // Resolve authoritative order.create Action Type
        $actionTypeStmt = $db->prepare("
            SELECT actionTypeId
            FROM tblActionTypes
            WHERE actionName = :actionName
              AND crud_class = 'create'
            LIMIT 1
        ");

        $actionTypeStmt->execute([
            'actionName' => 'order.create'
        ]);

        $orderCreateActionTypeId = (int)(
            $actionTypeStmt->fetchColumn() ?: 0
        );

        if ($orderCreateActionTypeId <= 0) {
            $returnOrderCreateError(
                'Order creation Action Type is not configured.'
            );
        }

        // Initialize authoritative timestamps
        $createdUnix = time();

        // Begin atomic Order and Action creation
        $db->beginTransaction();

        // Insert authoritative Order
        $insertOrderStmt = $db->prepare("
            INSERT INTO tblOrders (
                orderChristyNumber,
                orderTypeID,
                orderIsProposal,
                orderEntityID,
                orderLocationID,
                orderBillToContactID,
                orderJobsiteContactID,
                orderSalespersonID,
                orderSalespersonName,
                orderDate,
                orderDueDate,
                orderPurchaseOrder,
                orderScope,
                orderStatusID,
                orderNote,
                orderCreatedAt,
                orderUpdatedAt,
                orderIsNotValid
            ) VALUES (
                :orderChristyNumber,
                :orderTypeID,
                :orderIsProposal,
                :orderEntityID,
                :orderLocationID,
                :orderBillToContactID,
                :orderJobsiteContactID,
                :orderSalespersonID,
                :orderSalespersonName,
                :orderDate,
                :orderDueDate,
                :orderPurchaseOrder,
                :orderScope,
                :orderStatusID,
                :orderNote,
                :orderCreatedAt,
                NULL,
                0
            )
        ");

        $insertOrderStmt->execute([
            'orderChristyNumber'   => $christyNumber,
            'orderTypeID'          => $orderTypeId,
            'orderIsProposal'      => $isProposal,
            'orderEntityID'        => $orderEntityId,
            'orderLocationID'      => $locationId,
            'orderBillToContactID' => $billToContactId,
            'orderJobsiteContactID'=> $jobsiteContactId,
            'orderSalespersonID'   => $actorContactId,
            'orderSalespersonName' => $salespersonName,
            'orderDate'            => $orderDate,
            'orderDueDate'         => $orderDueDate,
            'orderPurchaseOrder'   => $purchaseOrder,
            'orderScope'           => $orderScope,
            'orderStatusID'        => $orderStatusId,
            'orderNote'            => $orderNote,
            'orderCreatedAt'       => $createdUnix
        ]);

        $orderId = (int)$db->lastInsertId();

        if ($orderId <= 0) {
            throw new RuntimeException(
                'Order record could not be created.'
            );
        }

        // Build authoritative Action payload
        $actionPayloadData = [
            'operation' => 'order.create',
            'orderID'   => $orderId,
            'jobsite'   => [
                'locationID'       => $locationId,
                'jobsiteContactID' => $jobsiteContactId
            ],
            'billing' => [
                'entityID'         => $orderEntityId,
                'billToContactID'  => $billToContactId,
                'billingLocationID'=> $billingLocationId
            ],
            'order' => [
                'christyNumber' => $christyNumber,
                'typeID'        => $orderTypeId,
                'isProposal'    => (bool)$isProposal,
                'statusID'      => $orderStatusId,
                'salespersonID' => $actorContactId,
                'date'          => $orderDate,
                'dueDate'       => $orderDueDate,
                'purchaseOrder' => $purchaseOrder,
                'scope'         => $orderScope,
                'note'          => $orderNote
            ]
        ];

        // Insert required Order Action
        $actionId = insertActionPrompt([
            'actionTypeId'      => $orderCreateActionTypeId,
            'contactId'         => $actorContactId,
            'origin'            => ACTION_ORIGIN_USER,
            'createdUnixTime'   => $createdUnix,
            'activitySessionId' => $activitySessionId,
            'promptText'        => 'Create Order',
            'responseText'      => sprintf(
                'Created Order #%d%s.',
                $orderId,
                $christyNumber !== null
                    ? ' — Christy Work Order ' .
                        $christyNumber
                    : ''
            ),
            'intent'            => 'order.create',
            'intentConfidence'  => 1.00,
            'latitude'          => $latitude,
            'longitude'         => $longitude,
            'actionPayloadData' => $actionPayloadData,
            'actionResponseData'=> [
                'success'       => true,
                'orderID'       => $orderId,
                'christyNumber' => $christyNumber
            ]
        ], $db);

        if ($actionId <= 0) {
            throw new RuntimeException(
                'Order Action history could not be created.'
            );
        }

        // Commit Order and Action together
        $db->commit();

        // Return created authoritative Order
        echo json_encode([
            'success' => true,
            'type'    => 'order_create',
            'order'   => [
                'orderID'              => $orderId,
                'orderChristyNumber'   => $christyNumber,
                'orderTypeID'          => $orderTypeId,
                'orderTypeName'        => $orderType[
                    'orderTypeName'
                ],
                'orderIsProposal'      => $isProposal,
                'orderEntityID'        => $orderEntityId,
                'entityName'           => $location[
                    'entityName'
                ],
                'orderLocationID'      => $locationId,
                'locationName'         => $location[
                    'locationName'
                ],
                'orderBillToContactID' => $billToContactId,
                'billingLocationID'    => $billingLocationId,
                'orderJobsiteContactID'=> $jobsiteContactId,
                'orderSalespersonID'   => $actorContactId,
                'orderSalespersonName' => $salespersonName,
                'orderDate'            => $orderDate,
                'orderDueDate'         => $orderDueDate,
                'orderPurchaseOrder'   => $purchaseOrder,
                'orderScope'           => $orderScope,
                'orderStatusID'        => $orderStatusId,
                'orderStatusName'      => $orderStatus[
                    'orderStatusName'
                ],
                'orderNote'            => $orderNote,
                'orderCreatedAt'       => $createdUnix,
                'orderUpdatedAt'       => null,
                'orderIsNotValid'      => 0
            ],
            'actionID' => $actionId,
            'error'    => null
        ], JSON_UNESCAPED_SLASHES);

        exit;

    } catch (Throwable $e) {
        // Roll back partial Order creation
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            '[askOpenAI] orderCreate failed: ' .
            $e->getMessage()
        );

        // Return duplicate-number conflict
        if (
            $e instanceof PDOException &&
            (string)$e->getCode() === '23000'
        ) {
            $returnOrderCreateError(
                'That Christy Work Order number already exists.'
            );
        }

        $returnOrderCreateError(
            'Unable to create the Order.'
        );
    }
}

// =====================================================
// CONTACT UPDATE (mutation) — Contact Edit v1.0
// =====================================================

if ($type === 'contactUpdate') {

    $actorContactId = (int)(
        $_SESSION['SKYESOFT_contactId']
        ?? $_SESSION['contactId']
        ?? 0
    );

    $activitySessionId = $_SESSION['activitySessionId']
                      ?? session_id();

    $latitude  = is_numeric($input['latitude']  ?? null) ? (float)$input['latitude']  : null;
    $longitude = is_numeric($input['longitude'] ?? null) ? (float)$input['longitude'] : null;

    $targetContactId = (int)($input['contactId'] ?? 0);

    if ($targetContactId <= 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'contact_update',
            'error'   => 'Valid contactId is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Collect only user-maintained fields
    $contactSalutation            = trim((string)($input['contactSalutation'] ?? 'Mr'));
    $contactFirstName             = trim((string)($input['contactFirstName'] ?? ''));
    $contactLastName              = trim((string)($input['contactLastName'] ?? ''));
    $contactTitle                 = trim((string)($input['contactTitle'] ?? ''));
    $contactPrimaryPhone          = trim((string)($input['contactPrimaryPhone'] ?? ''));
    $contactPrimaryPhoneExtension = trim((string)($input['contactPrimaryPhoneExtension'] ?? ''));
    $contactSecondaryPhone        = trim((string)($input['contactSecondaryPhone'] ?? ''));
    $contactEmail                 = trim((string)($input['contactEmail'] ?? ''));
    $contactIsNotValid            = (int)($input['contactIsNotValid'] ?? 0) ? 1 : 0;

    if ($contactFirstName === '' || $contactLastName === '') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'contact_update',
            'error'   => 'First name and last name are required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($contactEmail === '') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'contact_update',
            'error'   => 'Email is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Normalize derived fields (server-owned)
    $contactEmailNormalized = strtolower($contactEmail);
    $contactPrimaryPhoneRaw = preg_replace('/\D/', '', $contactPrimaryPhone);
    $contactSecondaryPhoneRaw = preg_replace('/\D/', '', $contactSecondaryPhone);

    // Validate salutation
    if (!in_array($contactSalutation, ['Mr', 'Ms'], true)) {
        $contactSalutation = 'Mr';
    }

    // Length guards
    $contactFirstName             = mb_substr($contactFirstName, 0, 100);
    $contactLastName              = mb_substr($contactLastName, 0, 100);
    $contactTitle                 = mb_substr($contactTitle, 0, 150);
    $contactPrimaryPhone          = mb_substr($contactPrimaryPhone, 0, 25);
    $contactPrimaryPhoneExtension = mb_substr($contactPrimaryPhoneExtension, 0, 10);
    $contactSecondaryPhone        = mb_substr($contactSecondaryPhone, 0, 25);
    $contactEmail                 = mb_substr($contactEmail, 0, 255);

    try {
        // Confirm exists
        $check = $db->prepare("
            SELECT contactId, contactEntityId, contactLocationId
            FROM tblContacts
            WHERE contactId = :contactId
            LIMIT 1
        ");
        $check->execute(['contactId' => $targetContactId]);
        $current = $check->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'type'    => 'contact_update',
                'error'   => 'Contact not found.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $nowUnix = time();

        $sql = "
            UPDATE tblContacts
            SET
                contactSalutation            = :contactSalutation,
                contactFirstName             = :contactFirstName,
                contactLastName              = :contactLastName,
                contactTitle                 = :contactTitle,
                contactPrimaryPhone          = :contactPrimaryPhone,
                contactPrimaryPhoneRaw       = :contactPrimaryPhoneRaw,
                contactPrimaryPhoneExtension = :contactPrimaryPhoneExtension,
                contactSecondaryPhone        = :contactSecondaryPhone,
                contactSecondaryPhoneRaw     = :contactSecondaryPhoneRaw,
                contactEmail                 = :contactEmail,
                contactEmailNormalized       = :contactEmailNormalized,
                contactIsNotValid            = :contactIsNotValid,
                contactUpdatedAt             = :contactUpdatedAt
            WHERE contactId = :contactId
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'contactId'                    => $targetContactId,
            'contactSalutation'            => $contactSalutation,
            'contactFirstName'             => $contactFirstName,
            'contactLastName'              => $contactLastName,
            'contactTitle'                 => ($contactTitle !== '') ? $contactTitle : null,
            'contactPrimaryPhone'          => ($contactPrimaryPhone !== '') ? $contactPrimaryPhone : null,
            'contactPrimaryPhoneRaw'       => ($contactPrimaryPhoneRaw !== '') ? $contactPrimaryPhoneRaw : null,
            'contactPrimaryPhoneExtension' => ($contactPrimaryPhoneExtension !== '') ? $contactPrimaryPhoneExtension : null,
            'contactSecondaryPhone'        => ($contactSecondaryPhone !== '') ? $contactSecondaryPhone : null,
            'contactSecondaryPhoneRaw'     => ($contactSecondaryPhoneRaw !== '') ? $contactSecondaryPhoneRaw : null,
            'contactEmail'                 => $contactEmail,
            'contactEmailNormalized'       => $contactEmailNormalized,
            'contactIsNotValid'            => $contactIsNotValid,
            'contactUpdatedAt'             => $nowUnix
        ]);

        // Reload
        $reload = $db->prepare("
            SELECT c.*,
                   e.entityId, e.entityName,
                   l.locationId, l.locationName
            FROM tblContacts c
            LEFT JOIN tblEntities e ON e.entityId = c.contactEntityId
            LEFT JOIN tblLocations l ON l.locationId = c.contactLocationId
            WHERE c.contactId = :contactId
            LIMIT 1
        ");
        $reload->execute(['contactId' => $targetContactId]);
        $row = $reload->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Contact updated but reload failed.');
        }

        $createdDate  = !empty($row['contactDate'] ?? $row['contactCreatedAt'])
            ? date('M j, Y', (int)($row['contactDate'] ?? $row['contactCreatedAt']))
            : null;
        $lastActivity = !empty($row['contactUpdatedAt'])
            ? date('M j, Y', (int)$row['contactUpdatedAt'])
            : $createdDate;

        $contact = [
            'contactId'                    => (int)$row['contactId'],
            'contactSalutation'            => $row['contactSalutation'],
            'contactFirstName'             => $row['contactFirstName'],
            'contactLastName'              => $row['contactLastName'],
            'contactTitle'                 => $row['contactTitle'],
            'contactPrimaryPhone'          => $row['contactPrimaryPhone'],
            'contactPrimaryPhoneExtension' => $row['contactPrimaryPhoneExtension'],
            'contactSecondaryPhone'        => $row['contactSecondaryPhone'],
            'contactEmail'                 => $row['contactEmail'],
            'contactIsNotValid'            => (int)$row['contactIsNotValid'],
            'contactEntityId'              => (int)$row['contactEntityId'],
            'contactLocationId'            => (int)$row['contactLocationId'],
            'contactDate'                  => (int)($row['contactDate'] ?? $row['contactCreatedAt'] ?? 0),
            'contactUpdatedAt'             => (int)$row['contactUpdatedAt'],
            'createdDate'                  => $createdDate,
            'lastActivity'                 => $lastActivity,
            'entity' => !empty($row['entityId']) ? [
                'entityId'   => (int)$row['entityId'],
                'entityName' => $row['entityName']
            ] : null,
            'location' => !empty($row['locationId']) ? [
                'locationId'   => (int)$row['locationId'],
                'locationName' => $row['locationName']
            ] : null,
            'orderCount'       => 0,
            'applicationCount' => 0,
            'noteCount'        => 0,
            'taskCount'        => 0
        ];

        // Audit
        if ($actorContactId > 0) {
            try {
                insertActionPrompt([
                    'contactId'         => $actorContactId,
                    'promptText'        => 'Update contact profile',
                    'responseText'      => sprintf(
                        'Updated contact #%d (%s %s).',
                        $targetContactId,
                        $contactFirstName,
                        $contactLastName
                    ),
                    'intent'            => 'contacts.update',
                    'intentConfidence'  => 1.0,
                    'activitySessionId' => $activitySessionId,
                    'latitude'          => $latitude,
                    'longitude'         => $longitude,
                    'actionTypeId'      => 13, // TODO: dedicated CONTACT_UPDATE type when available
                    'origin'            => ACTION_ORIGIN_USER,
                    'actionPayloadData' => [
                        'operation'       => 'contacts.update',
                        'targetContactId' => $targetContactId,
                        'fields'          => [
                            'contactSalutation'            => $contactSalutation,
                            'contactFirstName'             => $contactFirstName,
                            'contactLastName'              => $contactLastName,
                            'contactTitle'                 => $contactTitle !== '' ? $contactTitle : null,
                            'contactPrimaryPhone'          => $contactPrimaryPhone !== '' ? $contactPrimaryPhone : null,
                            'contactPrimaryPhoneExtension' => $contactPrimaryPhoneExtension !== '' ? $contactPrimaryPhoneExtension : null,
                            'contactSecondaryPhone'        => $contactSecondaryPhone !== '' ? $contactSecondaryPhone : null,
                            'contactEmail'                 => $contactEmail,
                            'contactIsNotValid'            => $contactIsNotValid,
                            'contactUpdatedAt'             => $nowUnix
                        ]
                    ],
                    'actionResponseData' => [
                        'success'         => true,
                        'targetContactId' => $targetContactId
                    ]
                ], $db);
            } catch (Throwable $e) {
                error_log('[askOpenAI] Contact-update action logging failed: ' . $e->getMessage());
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'type'    => 'contact_update',
            'contact' => $contact
        ], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        error_log('[askOpenAI] contactUpdate failed: ' . $e->getMessage());

        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'contact_update',
            'error'   => 'Update failed: ' . $e->getMessage()
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// =====================================================
// LOCATIONS BY ENTITY (paginated list for Workspace)
// =====================================================
if ($type === 'locationsByEntity') {

    $entityId = (int)($input['entityId'] ?? 0);
    $page     = max(1, (int)($input['page'] ?? 1));
    $pageSize = max(1, min(50, (int)($input['pageSize'] ?? 5)));

    if ($entityId <= 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'location_list',
            'error'   => 'Valid entityId is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        // Total
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM tblLocations
            WHERE locationEntityId = :entityId
              AND locationIsNotValid = 0
        ");
        $countStmt->execute(['entityId' => $entityId]);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $pageSize));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $pageSize;

        $stmt = $db->prepare("
            SELECT
                l.locationId,
                l.locationName,
                l.locationAddress,
                l.locationAddressSuite,
                l.locationCity,
                l.locationState,
                l.locationZip,
                l.locationParcelNumber,
                l.locationIsBilling,
                l.locationEntityId,
                e.entityName
            FROM tblLocations l
            LEFT JOIN tblEntities e ON e.entityId = l.locationEntityId
            WHERE l.locationEntityId = :entityId
              AND l.locationIsNotValid = 0
            ORDER BY l.locationIsBilling DESC, l.locationName ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('entityId', $entityId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'type'    => 'location_list',
            'list'    => [
                'page'       => $page,
                'pageSize'   => $pageSize,
                'total'      => $total,
                'totalPages' => $totalPages,
                'rows'       => $rows
            ]
        ], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        error_log('[askOpenAI] locationsByEntity failed: ' . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'location_list',
            'error'   => 'Unable to load locations.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// =====================================================
// CONTACTS BY ENTITY (paginated list for Workspace)
// =====================================================
if ($type === 'contactsByEntity') {

    $entityId = (int)($input['entityId'] ?? 0);
    $page     = max(1, (int)($input['page'] ?? 1));
    $pageSize = max(1, min(500, (int)($input['pageSize'] ?? 200)));

    if ($entityId <= 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'contact_list',
            'error'   => 'Valid entityId is required.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM tblContacts
            WHERE contactEntityId = :entityId
              AND COALESCE(isActive, 1) = 1
        ");
        $countStmt->execute(['entityId' => $entityId]);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $pageSize));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $pageSize;

        $stmt = $db->prepare("
            SELECT
                c.contactId,
                c.contactSalutation,
                c.contactFirstName,
                c.contactLastName,
                c.contactTitle,
                c.contactPrimaryPhone,
                c.contactEmail,
                c.isActive,
                c.contactEntityId,
                e.entityName
            FROM tblContacts c
            LEFT JOIN tblEntities e ON e.entityId = c.contactEntityId
            WHERE c.contactEntityId = :entityId
              AND COALESCE(c.isActive, 1) = 1
            ORDER BY c.contactLastName ASC, c.contactFirstName ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('entityId', $entityId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'type'    => 'contact_list',
            'list'    => [
                'page'       => $page,
                'pageSize'   => $pageSize,
                'total'      => $total,
                'totalPages' => $totalPages,
                'rows'       => $rows
            ]
        ], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        error_log('[askOpenAI] contactsByEntity failed: ' . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'type'    => 'contact_list',
            'error'   => 'Unable to load contacts.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// =====================================================
// PROPOSAL INTENT CLASSIFICATION
// =====================================================

if ($type === 'classifyProposalIntent') {

    $userQuery = trim((string)($input['userQuery'] ?? ''));

    if ($userQuery === '') {
        echo json_encode([
            'type'       => 'none',
            'displayName'=> null,
            'confidence' => 0.0,
            'reason'     => 'Empty input'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

$systemPrompt = <<<PROMPT
You are a precise classifier for Skyesoft. Your only job is to decide whether the user's multi-line input is a Contact Proposal, a Location Proposal, or neither.

Return ONLY valid JSON with this exact shape:
{
  "type": "contact_proposal" | "location_proposal" | "none",
  "displayName": "string or null",
  "confidence": number between 0 and 1,
  "reason": "short explanation"
}

Classification rules:

1. contact_proposal (most common)
   - Contains a person's name (First Last or FIRST LAST)
   - Usually includes a job title, phone number, and/or email
   - May also include a company name and street address
   - If a clear person + contact method (phone or email) is present, prefer contact_proposal even if an address is also present

2. location_proposal
   - Focused primarily on a physical place or business location
   - Does NOT contain a clear personal name + phone/email as the main subject
   - Typically just entity name + address block

3. none
   - Single line
   - Pure conversational questions
   - Clearly incomplete or ambiguous input with no person and no usable address

Important:
- Prefer contact_proposal when a person name + phone or email is present.
- Prefer location_proposal only when there is no clear person + contact method.
- displayName should be the person's full name for contact_proposal, or the entity/location name for location_proposal.
- Never invent data.
PROMPT;

    $fullPrompt = $systemPrompt . "\n\nUser Input:\n" . $userQuery;

    try {
        $raw = callOpenAI($fullPrompt, $apiKey, 'gpt-4o-mini');

        // Try to extract JSON even if the model wraps it
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $result = json_decode($raw, true);

        if (!is_array($result) || !isset($result['type'])) {
            throw new Exception('Invalid classifier response');
        }

        // Normalize
        $allowed = ['contact_proposal', 'location_proposal', 'none'];
        if (!in_array($result['type'], $allowed, true)) {
            $result['type'] = 'none';
        }

        echo json_encode([
            'type'        => $result['type'],
            'displayName' => $result['displayName'] ?? null,
            'confidence'  => isset($result['confidence']) ? (float)$result['confidence'] : 0.0,
            'reason'      => $result['reason'] ?? null
        ], JSON_UNESCAPED_SLASHES);

    } catch (Throwable $e) {
        error_log('[classifyProposalIntent] Error: ' . $e->getMessage());
        echo json_encode([
            'type'        => 'none',
            'displayName' => null,
            'confidence'  => 0.0,
            'reason'      => 'Classifier failed'
        ], JSON_UNESCAPED_SLASHES);
    }

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
        $parsePrompt,
        $apiKey,
        'gpt-4o-mini',
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
    
    $response = callOpenAI(
        $basePrompt,
        $apiKey
    );

    if ($response !== null && trim($response) !== '') {
        $report['narrative'] = trim($response);

        file_put_contents(
            $reportPath,
            json_encode(
                $report,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_SLASHES |
                JSON_INVALID_UTF8_SUBSTITUTE
            )
        );

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
    
    $response = callOpenAI(
        $finalPrompt,
        $apiKey
    );

    if ($response === null || trim($response) === '') {
        aiFail(
            'AI narrative generation failed — empty response from OpenAI'
        );
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

    // =====================================================================
    // 📇 GOVERNED NATURAL-LANGUAGE BUSINESS-OBJECT RESOLUTION
    // Order: Contact → Entity → Location
    // Runs before legacy regex searches and before semantic intent.
    // =====================================================================

    $lookupPhrase = stripConversationalWrapper($query);

    if ($lookupPhrase !== '') {

        $resolved = null;
        $activitySessionId = $_SESSION['activitySessionId'] ?? session_id();

        // Detect explicit force keywords
        $forceLocation = (bool)preg_match(
            '/^\s*(?:show|open|find|search(?:\s+for)?|location)\s+(?:location|loc)\b/i',
            $query
        ) || (bool)preg_match('/\blocation\s+(.+)$/i', $lookupPhrase);

        $forceEntity = (bool)preg_match(
            '/^\s*(?:show|open|find|search(?:\s+for)?|entity|business|company)\s+(?:entity|business|company)?\b/i',
            $query
        ) || (bool)preg_match(
            '/^(?:entity|business|company)\s+(.+)$/i',
            $lookupPhrase
        );

        // 1. Combined form: "Susan at Christy Signs"
        $resolved = resolveContactAtEntity($db, $lookupPhrase);

        // 2. Single phrase – Contact or Entity (existing helper)
        if ($resolved === null) {
            $resolved = resolveSinglePhrase($db, $lookupPhrase);
        }

        // 2b. Forced Entity
        if ($resolved === null) {
            $entityPhrase = $lookupPhrase;
            $forceEntity  = false;

            if (preg_match('/^(?:entity|business|company)\s+(.+)$/i', $lookupPhrase, $m)) {
                $entityPhrase = trim($m[1]);
                $forceEntity  = true;
            }

            // Also catch "Entity Christy Signs", "find entity …", etc.
            if (!$forceEntity) {
                $forceEntity = (bool)preg_match(
                    '/^\s*(?:show|open|find|search(?:\s+for)?)\s+(?:a\s+)?(?:entity|business|company)\b/i',
                    $query
                );
            }

            if ($forceEntity && $entityPhrase !== '') {
                // Use the broader multi-result search (same one that powers the search card)
                $matches = searchEntitiesByName($db, $entityPhrase);

                if (!empty($matches) && is_array($matches[0])) {
                    $top = $matches[0];

                    $resolved = [
                        'success'    => true,
                        'type'       => 'entity_detail',
                        'entityId'   => (int)($top['entityId'] ?? 0),
                        'entity'     => $top,
                        'searchMode' => 'entities.resolve',
                        'matchCount' => 1
                    ];
                }
            }
        }

        // 3. Location resolution (new)
        if ($resolved === null) {
            // Strip leading "location " if present so the pure name is used
            $locationPhrase = $lookupPhrase;
            if (preg_match('/^(?:location|loc)\s+(.+)$/i', $lookupPhrase, $m)) {
                $locationPhrase = trim($m[1]);
                $forceLocation  = true;
            }

            $resolved = resolveLocationByPhrase($db, $locationPhrase, $forceLocation);
        }

        // ─────────────────────────────────────────────
        // High-confidence match found → return immediately
        // ─────────────────────────────────────────────
        if ($resolved !== null) {

            $actorContactId = (int)(
                $_SESSION['SKYESOFT_contactId']
                ?? $_SESSION['contactId']
                ?? 0
            );

            // Determine intent / operation for logging
            $intent = match ($resolved['type'] ?? '') {
                'location_detail' => 'locations.resolve',
                'entity_detail', 'entity_search' => 'entities.resolve',
                default           => 'contacts.resolve'
            };

            if ($actorContactId > 0) {
                try {
                    insertActionPrompt([
                        'contactId'         => $actorContactId,
                        'promptText'        => $query,
                        'responseText'      => sprintf(
                            'Natural-language resolution (%s) returned %d match%s.',
                            $resolved['searchMode'] ?? $intent,
                            $resolved['matchCount'] ?? 1,
                            ($resolved['matchCount'] ?? 1) === 1 ? '' : 'es'
                        ),
                        'intent'            => $intent,
                        'intentConfidence'  => 1.0,
                        'activitySessionId' => $activitySessionId,
                        'latitude'          => $latitude,
                        'longitude'         => $longitude,
                        'actionTypeId'      => 3,
                        'origin'            => ACTION_ORIGIN_USER,
                        'actionPayloadData' => [
                            'operation'  => $intent,
                            'searchMode' => $resolved['searchMode'] ?? null,
                            'searchName' => $lookupPhrase,
                            'score'      => $resolved['score'] ?? null
                        ],
                        'actionResponseData' => [
                            'success'    => true,
                            'matchCount' => $resolved['matchCount'] ?? 1,
                            'type'       => $resolved['type'] ?? null
                        ]
                    ], $db);
                } catch (Throwable $e) {
                    error_log('[askOpenAI] Natural resolve action logging failed: ' . $e->getMessage());
                }
            }

            $resolved['activitySessionId'] = $activitySessionId;

            header('Content-Type: application/json');
            echo json_encode($resolved, JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // If we reach here, no high-confidence Contact / Entity / Location match
    // → continue to legacy path (explicit searches, lists, semantic intent, Google)

    // If we reach here, no contact/entity resolution matched → continue to legacy path

    // ─────────────────────────────────────────────
    // 2. Deterministic Contact Operations (legacy)
    // ─────────────────────────────────────────────
    $lowerQuery = strtolower(trim($query));

    // Individual contact-search commands
    $explicitContactSearch = preg_match(
        '/^\s*(?:' .
            'contacts?' .
            '|(?:find|search(?:\s+for)?)\s+(?:a\s+)?contacts?(?:\s+named)?' .
        ')\s+(.+?)\s*$/i',
        $query,
        $explicitContactMatch
    );

    // Short name search (example: "find Steve")
    $implicitContactSearch = preg_match(
        '/^\s*(?:find|search(?:\s+for)?)\s+([a-z][a-z\'\-.]*(?:\s+[a-z][a-z\'\-.]*){0,2})\s*$/i',
        $query,
        $implicitContactMatch
    );

    // Detect incomplete explicit requests
    $incompleteContactSearch = preg_match(
        '/^\s*(?:' .
            'contacts?' .
            '|(?:find|search(?:\s+for)?)\s+(?:a\s+)?contacts?(?:\s+named)?' .
        ')\s*$/i',
        $query
    );

    if ($incompleteContactSearch) {
        header('Content-Type: application/json');

        echo json_encode([
            'success'    => false,
            'type'       => 'contact_search',
            'searchName' => '',
            'matches'    => [],
            'matchCount' => 0,
            'error'      => 'Enter a first name, last name, or full name.'
        ], JSON_UNESCAPED_SLASHES);

        exit;
    }

    $searchName = '';

    if ($explicitContactSearch) {
        $searchName = trim($explicitContactMatch[1] ?? '');
    } elseif ($implicitContactSearch) {
        $searchName = trim($implicitContactMatch[1] ?? '');
    }

    // Exclude obvious non-contact searches
    $isNonContactSearch = $searchName !== '' && (bool)preg_match(
        '/\b(zoning|permit|permits|ordinance|requirements?|code|parcel|address|location|project|report|document|file)\b/i',
        $searchName
    );

    if ($searchName !== '' && !$isNonContactSearch) {
        // Search authoritative contact records
        $contacts   = searchContactsByName($db, $searchName);
        $matchCount = count($contacts);

        /*
         * Explicit contact commands return zero matches.
         * Implicit commands only become contact searches when a record exists.
         */
        $isConfirmedContactSearch =
            (bool)$explicitContactSearch ||
            $matchCount > 0;

        if ($isConfirmedContactSearch) {
            // Resolve action context
            $actorContactId = (int)(
                $_SESSION['SKYESOFT_contactId']
                ?? $_SESSION['contactId']
                ?? 0
            );

            $activitySessionId = $_SESSION['activitySessionId']
                              ?? session_id();

            // Build structured response
            $searchResponse = [
                'success'           => true,
                'type'              => 'contact_search',
                'searchName'        => $searchName,
                'matches'           => $contacts,
                'matchCount'        => $matchCount,
                'activitySessionId' => $activitySessionId
            ];

            // Record contact-search prompt action (Type 3)
            if ($actorContactId > 0) {
                try {
                    insertActionPrompt([
                        'contactId'         => $actorContactId,
                        'promptText'        => $query,
                        'responseText'      => sprintf(
                            'Contact search for "%s" returned %d match%s.',
                            $searchName,
                            $matchCount,
                            $matchCount === 1 ? '' : 'es'
                        ),
                        'intent'            => 'contacts.search',
                        'intentConfidence'  => 1.0,
                        'activitySessionId' => $activitySessionId,
                        'latitude'          => $latitude,
                        'longitude'         => $longitude,
                        'actionTypeId'      => 3,
                        'origin'            => ACTION_ORIGIN_USER,
                        'actionPayloadData' => [
                            'operation'  => 'contacts.search',
                            'searchName' => $searchName
                        ],
                        'actionResponseData' => [
                            'success'    => true,
                            'matchCount' => $matchCount
                        ]
                    ], $db);
                } catch (Throwable $e) {
                    // Preserve results if audit logging fails
                    error_log(
                        '[askOpenAI] Contact-search action logging failed: ' .
                        $e->getMessage()
                    );
                }
            }

            header('Content-Type: application/json');

            echo json_encode(
                $searchResponse,
                JSON_UNESCAPED_SLASHES
            );

            exit;
        }
    }
    // ─────────────────────────────────────────────
    // 2c. Explicit Entity / Business Search
    //     (after explicit contact search, before implicit resolution)
    // ─────────────────────────────────────────────

    $explicitEntitySearch = preg_match(
        '/^\s*(?:find|search(?:\s+for)?)\s+(?:a\s+)?(?:business|company|entity|entities)(?:\s+named)?\s+(.+?)\s*$/i',
        $query,
        $explicitEntityMatch
    );

    $incompleteEntitySearch = preg_match(
        '/^\s*(?:find|search(?:\s+for)?)\s+(?:a\s+)?(?:business|company|entity|entities)(?:\s+named)?\s*$/i',
        $query
    );

    if ($incompleteEntitySearch) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'    => false,
            'type'       => 'entity_search',
            'searchName' => '',
            'matches'    => [],
            'matchCount' => 0,
            'error'      => 'Enter a business or company name.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($explicitEntitySearch) {
        $entitySearchName = trim($explicitEntityMatch[1] ?? '');

        if ($entitySearchName === '') {
            header('Content-Type: application/json');
            echo json_encode([
                'success'    => false,
                'type'       => 'entity_search',
                'searchName' => '',
                'matches'    => [],
                'matchCount' => 0,
                'error'      => 'Enter a business or company name.'
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $entities   = searchEntitiesByName($db, $entitySearchName);
        $matchCount = count($entities);

        $actorContactId = (int)(
            $_SESSION['SKYESOFT_contactId']
            ?? $_SESSION['contactId']
            ?? 0
        );
        $activitySessionId = $_SESSION['activitySessionId'] ?? session_id();

        $searchResponse = [
            'success'           => true,
            'type'              => 'entity_search',
            'searchName'        => $entitySearchName,
            'matches'           => $entities,
            'matchCount'        => $matchCount,
            'activitySessionId' => $activitySessionId
        ];

        // Record action (Type 3)
        if ($actorContactId > 0) {
            try {
                insertActionPrompt([
                    'contactId'         => $actorContactId,
                    'promptText'        => $query,
                    'responseText'      => sprintf(
                        'Entity search for "%s" returned %d match%s.',
                        $entitySearchName,
                        $matchCount,
                        $matchCount === 1 ? '' : 'es'
                    ),
                    'intent'            => 'entities.search',
                    'intentConfidence'  => 1.0,
                    'activitySessionId' => $activitySessionId,
                    'latitude'          => $latitude,
                    'longitude'         => $longitude,
                    'actionTypeId'      => 3,
                    'origin'            => ACTION_ORIGIN_USER,
                    'actionPayloadData' => [
                        'operation'  => 'entities.search',
                        'searchName' => $entitySearchName
                    ],
                    'actionResponseData' => [
                        'success'    => true,
                        'matchCount' => $matchCount
                    ]
                ], $db);
            } catch (Throwable $e) {
                error_log(
                    '[askOpenAI] Entity-search action logging failed: ' .
                    $e->getMessage()
                );
            }
        }

        header('Content-Type: application/json');
        echo json_encode($searchResponse, JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Operational contact list
    $isContactList =
        preg_match(
            '/\b(list|show|display)\b.*\bcontacts?\b/',
            $lowerQuery
        ) ||
        preg_match(
            '/\bcontacts?\b.*\b(list|page)\b/',
            $lowerQuery
        );

    // Contact-list navigation only
    $isListNavigation =
        ($_SESSION['lastList']['type'] ?? null) === 'contacts' &&
        (bool)preg_match(
            '/\b(next|previous|prev)\s+page\b/',
            $lowerQuery
        );

    if ($isContactList || $isListNavigation) {
        // Set requested page
        $page = 1;

        if (preg_match('/\bpage\s+(\d+)\b/', $lowerQuery, $m)) {
            $page = max(1, (int)$m[1]);
        } elseif (preg_match('/\bnext\s+page\b/', $lowerQuery)) {
            $page = (int)($_SESSION['lastList']['page'] ?? 1) + 1;
        } elseif (
            preg_match(
                '/\b(prev|previous)\s+page\b/',
                $lowerQuery
            )
        ) {
            $page = max(
                1,
                (int)($_SESSION['lastList']['page'] ?? 2) - 1
            );
        }

        // Load requested contacts
        $operationalList = loadContactPage($db, $page, 5);

        // Preserve navigation context
        $_SESSION['lastList'] = [
            'type' => 'contacts',
            'page' => $operationalList['page'] ?? $page
        ];

        error_log(
            '[skyebot] contact list page=' .
            ($operationalList['page'] ?? $page) .
            ' rows=' .
            count($operationalList['rows'] ?? [])
        );

        if (
            is_array($operationalList) &&
            isset($operationalList['rows'])
        ) {
            // Resolve action context
            $actorContactId = (int)(
                $_SESSION['SKYESOFT_contactId']
                ?? $_SESSION['contactId']
                ?? 0
            );

            $activitySessionId = $_SESSION['activitySessionId']
                              ?? session_id();

            // Resolve response details
            $page       = (int)($operationalList['page'] ?? 1);
            $pageSize   = (int)($operationalList['pageSize'] ?? 5);
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

            // Record contact-list prompt action (Type 3)
            if ($actorContactId > 0) {
                try {
                    insertActionPrompt([
                        'contactId'         => $actorContactId,
                        'promptText'        => $query,
                        'responseText'      => sprintf(
                            'Displayed contacts page %d of %d (%d contacts shown; %d total).',
                            $page,
                            $totalPages,
                            $rowCount,
                            $total
                        ),
                        'intent'            => 'contacts.list',
                        'intentConfidence'  => 1.0,
                        'activitySessionId' => $activitySessionId,
                        'latitude'          => $latitude,
                        'longitude'         => $longitude,
                        'actionTypeId'      => 3,
                        'origin'            => ACTION_ORIGIN_USER,
                        'actionPayloadData' => [
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
                    // Preserve results if audit logging fails
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

    // =====================================================================
    // Operational Entity List
    // =====================================================================

    $isEntityList =
        preg_match(
            '/\b(list|show|display)\b.*\b(entities|entity|businesses|companies)\b/',
            $lowerQuery
        ) ||
        preg_match(
            '/\b(entities|entity|businesses|companies)\b.*\b(list|page)\b/',
            $lowerQuery
        );

    // Entity-list navigation only
    $isEntityListNavigation =
        ($_SESSION['lastList']['type'] ?? null) === 'entities' &&
        (bool)preg_match(
            '/\b(next|previous|prev)\s+page\b/',
            $lowerQuery
        );

    if ($isEntityList || $isEntityListNavigation) {

        // Set requested page
        $page = 1;

        if (preg_match('/\bpage\s+(\d+)\b/', $lowerQuery, $m)) {
            $page = max(1, (int)$m[1]);
        } elseif (preg_match('/\bnext\s+page\b/', $lowerQuery)) {
            $page = (int)($_SESSION['lastList']['page'] ?? 1) + 1;
        } elseif (
            preg_match(
                '/\b(prev|previous)\s+page\b/',
                $lowerQuery
            )
        ) {
            $page = max(
                1,
                (int)($_SESSION['lastList']['page'] ?? 2) - 1
            );
        }

        // Load requested entities
        $operationalList = loadEntityPage($db, $page, 5);

        // Preserve navigation context
        $_SESSION['lastList'] = [
            'type' => 'entities',
            'page' => $operationalList['page'] ?? $page
        ];

        error_log(
            '[skyebot] entity list page=' .
            ($operationalList['page'] ?? $page) .
            ' rows=' .
            count($operationalList['rows'] ?? [])
        );

        if (
            is_array($operationalList) &&
            isset($operationalList['rows'])
        ) {
            // Resolve action context
            $actorContactId = (int)(
                $_SESSION['SKYESOFT_contactId']
                ?? $_SESSION['contactId']
                ?? 0
            );

            $activitySessionId = $_SESSION['activitySessionId']
                              ?? session_id();

            // Resolve response details
            $page       = (int)($operationalList['page'] ?? 1);
            $pageSize   = (int)($operationalList['pageSize'] ?? 5);
            $total      = (int)($operationalList['total'] ?? 0);
            $totalPages = (int)($operationalList['totalPages'] ?? 1);
            $rowCount   = count($operationalList['rows']);

            // Build structured response
            $listResponse = [
                'success'           => true,
                'type'              => 'entity_list',
                'list'              => $operationalList,
                'activitySessionId' => $activitySessionId
            ];

            // Record entity-list prompt action (Type 3)
            if ($actorContactId > 0) {
                try {
                    insertActionPrompt([
                        'contactId'         => $actorContactId,
                        'promptText'        => $query,
                        'responseText'      => sprintf(
                            'Displayed entities page %d of %d (%d entities shown; %d total).',
                            $page,
                            $totalPages,
                            $rowCount,
                            $total
                        ),
                        'intent'            => 'entities.list',
                        'intentConfidence'  => 1.0,
                        'activitySessionId' => $activitySessionId,
                        'latitude'          => $latitude,
                        'longitude'         => $longitude,
                        'actionTypeId'      => 3,
                        'origin'            => ACTION_ORIGIN_USER,
                        'actionPayloadData' => [
                            'operation' => 'entities.list',
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
                    // Preserve results if audit logging fails
                    error_log(
                        '[askOpenAI] Entity-list action logging failed: ' .
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

    // =====================================================================
    // Operational Location List
    // =====================================================================

    $isLocationList =
        preg_match(
            '/\b(list|show|display)\b.*\blocations?\b/',
            $lowerQuery
        ) ||
        preg_match(
            '/\blocations?\b.*\b(list|page)\b/',
            $lowerQuery
        );

    // Location-list navigation only
    $isLocationListNavigation =
        ($_SESSION['lastList']['type'] ?? null) === 'locations' &&
        (bool)preg_match(
            '/\b(next|previous|prev)\s+page\b/',
            $lowerQuery
        );

    if ($isLocationList || $isLocationListNavigation) {

        // Set requested page
        $page = 1;

        if (preg_match('/\bpage\s+(\d+)\b/', $lowerQuery, $m)) {
            $page = max(1, (int)$m[1]);
        } elseif (preg_match('/\bnext\s+page\b/', $lowerQuery)) {
            $page = (int)($_SESSION['lastList']['page'] ?? 1) + 1;
        } elseif (
            preg_match(
                '/\b(prev|previous)\s+page\b/',
                $lowerQuery
            )
        ) {
            $page = max(
                1,
                (int)($_SESSION['lastList']['page'] ?? 2) - 1
            );
        }

        // Load requested locations
        $operationalList = loadLocationPage($db, $page, 5);

        // Preserve navigation context
        $_SESSION['lastList'] = [
            'type' => 'locations',
            'page' => $operationalList['page'] ?? $page
        ];

        error_log(
            '[skyebot] location list page=' .
            ($operationalList['page'] ?? $page) .
            ' rows=' .
            count($operationalList['rows'] ?? [])
        );

        if (
            is_array($operationalList) &&
            isset($operationalList['rows'])
        ) {
            // Resolve action context
            $actorContactId = (int)(
                $_SESSION['SKYESOFT_contactId']
                ?? $_SESSION['contactId']
                ?? 0
            );

            $activitySessionId = $_SESSION['activitySessionId']
                              ?? session_id();

            // Resolve response details
            $page       = (int)($operationalList['page'] ?? 1);
            $pageSize   = (int)($operationalList['pageSize'] ?? 5);
            $total      = (int)($operationalList['total'] ?? 0);
            $totalPages = (int)($operationalList['totalPages'] ?? 1);
            $rowCount   = count($operationalList['rows']);

            // Build structured response
            $listResponse = [
                'success'           => true,
                'type'              => 'location_list',
                'list'              => $operationalList,
                'activitySessionId' => $activitySessionId
            ];

            // Record location-list prompt action (Type 3)
            if ($actorContactId > 0) {
                try {
                    insertActionPrompt([
                        'contactId'         => $actorContactId,
                        'promptText'        => $query,
                        'responseText'      => sprintf(
                            'Displayed locations page %d of %d (%d locations shown; %d total).',
                            $page,
                            $totalPages,
                            $rowCount,
                            $total
                        ),
                        'intent'            => 'locations.list',
                        'intentConfidence'  => 1.0,
                        'activitySessionId' => $activitySessionId,
                        'latitude'          => $latitude,
                        'longitude'         => $longitude,
                        'actionTypeId'      => 3,
                        'origin'            => ACTION_ORIGIN_USER,
                        'actionPayloadData' => [
                            'operation' => 'locations.list',
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
                    // Preserve results if audit logging fails
                    error_log(
                        '[askOpenAI] Location-list action logging failed: ' .
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
    // Deterministic Time / Date Commands
    // ─────────────────────────────────────────────
    $isDateQuery = (bool)preg_match(
        "/^\s*(?:" .
            "what\s+is\s+the\s+(?:current\s+)?date" .
            "|what(?:'s|\s+is)\s+today(?:'s\s+date)?" .
            "|current\s+date" .
        ")\s*[?.!]*\s*$/i",
        $query
    );

    $isTimeQuery = (bool)preg_match(
        '/^\s*(?:' .
            'what\s+time\s+is\s+it' .
            '|what\s+is\s+the\s+(?:current\s+)?time' .
            '|current\s+time' .
            '|time\s+now' .
        ')\s*[?.!]*\s*$/i',
        $query
    );

    // Local time/date response
    if ($isTimeQuery || $isDateQuery) {
        $varNow = new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Phoenix')
        );

        if ($isDateQuery) {
            $response = 'Today is ' .
                $varNow->format('l, F j, Y') .
                '.';
        } else {
            $response = 'The current time in Phoenix is ' .
                $varNow->format('g:i A') .
                '.';
        }

        $type = 'skyebot';
        $role = 'askOpenAI';

        goto SKY_OUTPUT;
    }

    // ─────────────────────────────────────────────
    // 3. Semantic Intent Classification
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
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'semantic_intent',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'intent',
                    'confidence',
                    'reasoning'
                ],
                'properties' => [
                    'intent' => [
                        'type' => 'string'
                    ],
                    'confidence' => [
                        'type' => 'number'
                    ],
                    'reasoning' => [
                        'type' => 'string'
                    ]
                ]
            ]
        ]
    ];

    $intentRaw = callOpenAI(
        injectSemanticIntentContext($intentPrompt),
        $apiKey,
        'gpt-4o-mini',
        $semanticIntentSchema
    );

    error_log(
        '[semantic-intent] Raw response: ' .
        ($intentRaw ? substr($intentRaw, 0, 600) : 'NULL')
    );

    $intentMeta = json_decode($intentRaw ?? '', true);

    if (
        !is_array($intentMeta) ||
        !isset($intentMeta['intent']) ||
        !isset($intentMeta['confidence'])
    ) {
        error_log(
            '[semantic-intent] Failed to parse JSON or missing keys'
        );

        $intentMeta = [
            'intent'     => 'uncertain',
            'confidence' => 0.0,
            'reasoning'  => 'JSON parse / schema failure'
        ];
    } else {
        error_log(
            '[semantic-intent] Intent: ' .
            $intentMeta['intent'] .
            ' | Confidence: ' .
            $intentMeta['confidence']
        );
    }

    $intent     = $intentMeta['intent'] ?? 'unknown';
    $confidence = (float)($intentMeta['confidence'] ?? 0.0);

    // ─────────────────────────────────────────────
    // 4. UI Actions and Short-Circuits
    // ─────────────────────────────────────────────
    $execution = executeIntent($intent, $confidence);

    if ($execution) {
        $type     = $execution['type'];
        $response = $execution['response'];

        goto SKY_OUTPUT;
    }

    // Resolve streamed domain intents
    if (
        $confidence >= 0.70 &&
        preg_match(
            '/^([a-z]+)_(inquiry|repair_request|execute|amendment_request)$/',
            $intent,
            $m
        )
    ) {
        $domainKey = $m[1];
        $mode      = $m[2];

        if (in_array($domainKey, $streamedDomains, true)) {
            $type = 'domain_intent';

            $response = json_encode([
                'domain'     => $domainKey,
                'mode'       => $mode,
                'confidence' => $confidence
            ], JSON_UNESCAPED_SLASHES);

            goto SKY_OUTPUT;
        }
    }

    // Resolve structural governance requests
    if (
        str_contains($lowerQuery, 'deviation') ||
        str_contains($lowerQuery, 'violation') ||
        str_contains($lowerQuery, 'structural')
    ) {
        $role     = 'governance';
        $type     = 'structural_state';
        $response = buildGovernanceResponse();

        goto SKY_OUTPUT;
    }

    // ─────────────────────────────────────────────
    // 6. Conversational Fallback
    // ─────────────────────────────────────────────

    error_log(
        '[skyebot] Entering conversational fallback. Query: ' .
        substr($query, 0, 150)
    );

    $sseSnapshot    = loadSseSnapshot();
    $responsePrompt = loadResponseGenerationPrompt();

    // Build slim conversational context
    $systemContext = buildSystemContext(
        $sseSnapshot,
        $db,
        $operationalList ?? null,
        true
    );

    // Determine whether current information is required
    $decisionPrompt = <<<PROMPT
    You are deciding whether the following SYSTEM DATA contains enough information to answer the user question.

    Reply with ONLY one word:
    - LOCAL   (if the system data is sufficient)
    - SEARCH  (if external search is required)

    SYSTEM DATA:
    {$systemContext}

    USER QUESTION:
    {$query}
    PROMPT;

    // Log decision request size
    error_log(
        '[skyebot] Decision sizes (bytes) | ' .
        'systemContext=' . strlen($systemContext) .
        ' | decisionPrompt=' . strlen($decisionPrompt)
    );

    $decision = strtoupper(trim(
        callOpenAI(
            $decisionPrompt,
            $apiKey,
            'gpt-4o-mini'
        ) ?? ''
    ));

    $useGoogle = ($decision === 'SEARCH');

    $googleResults = [];
    $googleContext = '';

    // Retrieve current external information
    if ($useGoogle) {
        $googleResults = googleCustomSearch($query, 5);

        if (!empty($googleResults)) {
            $googleContext =
                "GOOGLE SEARCH RESULTS (use these as factual grounding):\n\n";

            foreach ($googleResults as $index => $result) {
                $googleContext .=
                    ($index + 1) .
                    '. ' .
                    ($result['title'] ?? '') .
                    "\n";

                $googleContext .=
                    '   ' .
                    ($result['link'] ?? '') .
                    "\n";

                $googleContext .=
                    '   ' .
                    ($result['snippet'] ?? '') .
                    "\n\n";
            }
        }
    }

    // Build answer prompt
    if ($responsePrompt === '') {
        $basePrompt =
            "You are a helpful assistant.\n\n" .
            $googleContext .
            'User question: ' .
            $query;
    } else {
        $basePrompt =
            $responsePrompt .
            "\n\nSYSTEM DATA (JSON):\n" .
            $systemContext .
            "\n\n" .
            $googleContext .
            "\nUser Input:\n" .
            $query;
    }

    // Log answer request size
    error_log(
        '[skyebot] Answer sizes (bytes) | ' .
        'responsePrompt=' . strlen($responsePrompt) .
        ' | systemContext=' . strlen($systemContext) .
        ' | googleContext=' . strlen($googleContext) .
        ' | basePrompt=' . strlen($basePrompt)
    );

    // Generate answer
    $response = callOpenAI(
        $basePrompt,
        $apiKey,
        'gpt-4o-mini'
    );

    if (
        $response === null ||
        trim((string)$response) === ''
    ) {
        $response =
            "I couldn't complete the OpenAI request because " .
            'the request was too large or the API returned an error.';
    }

    // Return sourced AI answer
    if (!empty($googleResults)) {
        echo json_encode([
            'success' => true,
            'role'    => 'askOpenAI',
            'type'    => 'ai_query',
            'response' => trim((string)$response),
            'actionResponseData' => [
                'answer' => trim((string)$response),
                'sources' => [
                    'google' => [
                        'searched' => true,
                        'results'  => $googleResults
                    ]
                ]
            ],
            'activitySessionId' =>
                $_SESSION['activitySessionId'] ??
                session_id()
        ], JSON_UNESCAPED_SLASHES);

        exit;
    }

    $type = 'skyebot';

    SKY_OUTPUT:

    error_log(
        '[skyebot] Final response type: ' .
        $type .
        ' | Length: ' .
        strlen($response ?? '')
    );
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