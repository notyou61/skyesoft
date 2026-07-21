<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — authFunctions.php
// Shared Authentication Utilities (SSE SAFE)
// Version: 1.5.2
// ======================================================================

// 🔗 Dependencies (explicit + safe)
require_once __DIR__ . '/../sessionBootstrap.php';   // This should call session_start()
require_once __DIR__ . '/../dbConnect.php';
require_once __DIR__ . '/actionLogger.php';  // ✅ REQUIRED

// ─────────────────────────────────────────
// 🌐 REQUEST HELPERS
// ─────────────────────────────────────────

function safeIp(): string
{
    return (string)($_SERVER["REMOTE_ADDR"] ?? "");
}

function safeUserAgent(): string
{
    return (string)($_SERVER["HTTP_USER_AGENT"] ?? "");
}

// ─────────────────────────────────────────
// ⏱ SESSION ACTIVITY HELPER
// ─────────────────────────────────────────

function updateLastActivity(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_SESSION["authenticated"])) {
        $_SESSION["lastActivity"] = time();
    }

    session_write_close();
}

// ─────────────────────────────────────────
// 🧹 WORKSPACE GOVERNANCE HELPER
// ─────────────────────────────────────────

/**
 * Fulfills Codex Workspace Governance rules during explicit auth lifecycle transitions.
 * Locates and destroys lingering TMP workspace artifacts belonging specifically
 * to the authenticated contact without altering permanent records (REC/PER).
 */
function clearUserWorkspaceArtifacts(?int $contactId = null): void
{
    // --- CRITICAL BACKUP: If parameter context is missing or invalid, fallback to live session check
    if ($contactId === null || $contactId <= 0) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $contactId = isset($_SESSION['contactId']) ? (int)$_SESSION['contactId'] : 0;
    }

    // Stop safely when no authenticated context can be derived via parameter or session state
    if ($contactId <= 0) {
        error_log('[AUTH WORKSPACE CLEANUP] Skipped — contact identity context completely unavailable.');
        return;
    }

    // 🔍 FIX: Robust multi-depth lookup path to accurately target the active artifacts directory.
    // If authFunctions.php sits inside /utils/, going up 2 levels reaches the core root folder.
    $artifactDir = dirname(__DIR__, 2) . '/artifacts';

    // Backup check: If the directory is not found, fallback to 1-level directory mapping
    if (!is_dir($artifactDir)) {
        $artifactDir = dirname(__DIR__) . '/artifacts';
    }

    if (!is_dir($artifactDir)) {
        error_log("[AUTH WORKSPACE CLEANUP] Aborted — directory not found: {$artifactDir}");
        return;
    }

    // Format contact segment constraint string (e.g., 017)
    $contactSegment = str_pad((string)$contactId, 3, '0', STR_PAD_LEFT);

    // Filter directory targeting specifically this user's ephemeral TMP records
    $pattern = $artifactDir . "/TMP-*-*-*-{$contactSegment}-*.*";
    $files = glob($pattern) ?: [];
    $deleted = 0;
    $failed = 0;

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        if (unlink($file)) {
            $deleted++;
        } else {
            $failed++;
            error_log("[AUTH WORKSPACE CLEANUP] Failed to remove asset file: {$file}");
        }
    }

    error_log("[AUTH WORKSPACE CLEANUP] Executed — Contact ID: {$contactId} | Target Dir: {$artifactDir} | Removed={$deleted} | Blocked={$failed}");
}

// ─────────────────────────────────────────
// 📜 AUTH ACTION LOGGER
// ─────────────────────────────────────────

/**
 * Shared authenticated logout audit operation.
 * Used by both manual logout (auth.php) and SSE idle logout (sse.php).
 *
 * Codex actionOrigin:
 *   0 = User-initiated logout (UI / manual)
 *   1 = SSE inactivity logout
 *   2 = Sentinel inactivity logout
 *
 * Geo resolution order:
 *   1. Both latitude AND longitude supplied → use supplied pair
 *   2. Either missing → getLastKnownGeo() matched pair
 *   3. No history → both null
 *
 * Structured data:
 *   Caller-supplied actionPayloadData / actionResponseData take precedence.
 *   Otherwise defaults are built as PHP arrays (never left null for logout).
 *   Pass arrays — logAction() performs the single JSON encode step.
 *
 * Ordering contract (caller responsibility):
 *   1. Preserve contactId + activitySessionId
 *   2. Call executeAuthLogout()  ← audit insert happens here
 *   3. Only then clear session / persist logged-out state
 *   4. Send forceLogout (SSE) or return success (manual)
 *
 * @param PDO   $pdo
 * @param int   $contactId  Authenticated contact (must be > 0)
 * @param int   $origin     Codex origin 0|1|2
 * @param array $meta       Optional: latitude, longitude, activitySessionId|sessionId,
 *                          actionPayloadData, actionResponseData, response, source,
 *                          timeoutSeconds, lastActivityUnix
 * @return bool true only when a tblActions row was inserted (actionId > 0)
 */
function executeAuthLogout(PDO $pdo, int $contactId, int $origin, array $meta = []): bool
{
    if ($contactId <= 0) {
        error_log('[executeAuthLogout] Skipped — invalid contactId');
        return false;
    }

    // Normalize origin to Codex set; default to 0 (user-initiated) if out of range
    if (!in_array($origin, [0, 1, 2], true)) {
        error_log("[executeAuthLogout] Invalid origin={$origin}; coercing to 0");
        $origin = 0;
    }

    // --- activitySessionId (needed for defaults + forward)
    $activitySessionId = null;
    if (!empty($meta['activitySessionId']) && is_string($meta['activitySessionId'])) {
        $activitySessionId = trim($meta['activitySessionId']);
    } elseif (!empty($meta['sessionId']) && is_string($meta['sessionId'])) {
        $activitySessionId = trim($meta['sessionId']);
    }
    if ($activitySessionId === null || $activitySessionId === '') {
        $activitySessionId = session_id() ?: null;
    }

    // --- Geo resolution (matched pair only)
    $lat = $meta['latitude']  ?? null;
    $lng = $meta['longitude'] ?? null;

    $latOk = ($lat !== null && $lat !== '');
    $lngOk = ($lng !== null && $lng !== '');

    if ($latOk && $lngOk) {
        $latitude  = $lat;
        $longitude = $lng;
    } else {
        $geo = getLastKnownGeo($pdo, $contactId);
        $latitude  = $geo['latitude'];
        $longitude = $geo['longitude'];
    }

    // --- Structured payload / response
    // Pass PHP arrays only. logAction() is the single encoding step
    // (it json_encodes arrays; strings are stored as-is).
    $responseText = $meta['response'] ?? 'logout_success';

    if (array_key_exists('actionPayloadData', $meta) && $meta['actionPayloadData'] !== null) {
        $actionPayloadData = $meta['actionPayloadData'];
    } else {
        $actionPayloadData = [
            'source'            => $meta['source'] ?? 'auth',
            'origin'            => $origin,
            'activitySessionId' => $activitySessionId,
            'contactId'         => $contactId,
        ];
        if (isset($meta['timeoutSeconds'])) {
            $actionPayloadData['timeoutSeconds'] = $meta['timeoutSeconds'];
        }
        if (isset($meta['lastActivityUnix'])) {
            $actionPayloadData['lastActivityUnix'] = $meta['lastActivityUnix'];
        }
    }

    if (array_key_exists('actionResponseData', $meta) && $meta['actionResponseData'] !== null) {
        $actionResponseData = $meta['actionResponseData'];
    } else {
        $actionResponseData = [
            'result' => $responseText,
        ];
    }

    // Build meta for logAuthAction — numeric origin is authoritative
    $payload = [
        'origin'             => $origin,
        'latitude'           => $latitude,
        'longitude'          => $longitude,
        'activitySessionId'  => $activitySessionId,
        'actionPayloadData'  => $actionPayloadData,
        'actionResponseData' => $actionResponseData,
        'response'           => $responseText,
    ];

    // Backward-compat string flag still accepted by logAuthAction for intent mapping
    if ($origin === 1) {
        $payload['actionOrigin'] = 'idle_timeout';
    } elseif ($origin === 2) {
        $payload['actionOrigin'] = 'sentinel_timeout';
    } else {
        $payload['actionOrigin'] = 'ui_logout';
    }

    return logAuthAction($pdo, 'auth.logout', $contactId, $payload);
}

// 🔐 logAuthAction() — Adapter to new action system
// Returns true only when logAction() actually inserted a row (actionId > 0)
//
// Prefer executeAuthLogout() for logout paths. This function remains the
// general adapter for login / login.fail / logout.
//
function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): bool
{
    // 🧹 CRITICAL FIX: Intercept logout instantly at entry point.
    // This executes before any session modifications or database writes drop context.
    if ($actionKey === 'auth.logout') {
        error_log("[Auth Engine] Intercepted explicit logout event. Instigating workspace cleanup.");
        clearUserWorkspaceArtifacts($contactId);
    }

    try {
        // --- Map OLD keys → NEW actionNames
        $actionName = match ($actionKey) {
            'auth.login'       => 'auth.session.login',
            'auth.logout'      => 'auth.session.logout',
            'auth.login.fail'  => 'auth.session.login', // still log attempt
            default            => 'auth.session.login'  // fallback (safe)
        };

        // --- Numeric origin (Codex: 0=UI, 1=SSE idle, 2=Sentinel)
        // Prefer explicit meta['origin']; fall back from string actionOrigin; default 0
        $origin = 0;
        if (isset($meta['origin']) && in_array((int)$meta['origin'], [0, 1, 2], true)) {
            $origin = (int)$meta['origin'];
        } elseif (($meta['actionOrigin'] ?? '') === 'idle_timeout') {
            $origin = 1;
        } elseif (($meta['actionOrigin'] ?? '') === 'sentinel_timeout') {
            $origin = 2;
        }

        // --- Intent mapping (textual distinction)
        $intent = match ($actionKey) {
            'auth.login'       => 'ui_login',
            'auth.logout'      => match ($origin) {
                                    1 => 'idle_logout',
                                    2 => 'sentinel_logout',
                                    default => 'ui_logout',
                                 },
            'auth.login.fail'  => 'auth_fail',
            default            => 'auth_event'
        };

        // --- Prompt + response
        // prompt is written to promptText column by logAction()
        // MUST be the stable key (auth.login / auth.logout) so getLastAuthAction works
        $prompt   = $actionKey;
        $response = $meta['response'] ?? ($actionKey === 'auth.logout' ? 'logout_success' : 'login_success');

        // --- activitySessionId (critical for SSE idle path)
        // Prefer preserved value from caller; accept activitySessionId or sessionId key.
        $activitySessionId = null;
        if (!empty($meta['activitySessionId']) && is_string($meta['activitySessionId'])) {
            $activitySessionId = trim($meta['activitySessionId']);
        } elseif (!empty($meta['sessionId']) && is_string($meta['sessionId'])) {
            $activitySessionId = trim($meta['sessionId']);
        }
        if ($activitySessionId === null || $activitySessionId === '') {
            $activitySessionId = session_id() ?: null;
        }

        // --- Call NEW system
        $actionId = logAction($pdo, [
            'actionName'         => $actionName,
            'contactId'          => $contactId,
            'origin'             => $origin,
            'activitySessionId'  => $activitySessionId,
            'intent'             => $intent,
            'prompt'             => $prompt,
            'response'           => $response,
            'confidence'         => 1.0,
            'lat'                => $meta['latitude']  ?? null,
            'lng'                => $meta['longitude'] ?? null,
            'actionPayloadData'  => $meta['actionPayloadData']  ?? null,
            'actionResponseData' => $meta['actionResponseData'] ?? null
        ]);

        return $actionId > 0;

    } catch (Throwable $e) {
        error_log('[logAuthAction ERROR] ' . $e->getMessage());
        return false;
    }
}

// 🔐 getContactName()
function getContactName(mixed $contactId): array {
    // Accept string/int/null/bool and sanitize
    if ($contactId === null || $contactId === '' || $contactId === false || $contactId === 'null') {
        return ['firstName' => null, 'lastName' => null];
    }

    $contactId = filter_var($contactId, FILTER_VALIDATE_INT);
    if ($contactId === false) {
        error_log('[getContactName] Invalid contactId: ' . var_export($contactId, true));
        return ['firstName' => null, 'lastName' => null];
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("
            SELECT contactFirstName, contactLastName 
            FROM tblContacts 
            WHERE contactId = :id LIMIT 1
        ");
        $stmt->execute([':id' => $contactId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'firstName' => $row['contactFirstName'] ?? null,
            'lastName'  => $row['contactLastName']  ?? null
        ];
    } catch (Throwable $e) {
        error_log('[getContactName ERROR] ' . $e->getMessage());
        return ['firstName' => null, 'lastName' => null];
    }
}

/**
 * Return the most recent matched lat/lon pair for a contact from tblActions.
 * Coordinates are always a pair from the same row — never mixed across rows.
 *
 * @return array{latitude: ?float, longitude: ?float}
 */
function getLastKnownGeo(PDO $pdo, int $contactId): array
{
    $empty = ['latitude' => null, 'longitude' => null];

    if ($contactId <= 0) {
        return $empty;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT latitude, longitude
            FROM tblActions
            WHERE contactId = :contactId
              AND latitude  IS NOT NULL
              AND longitude IS NOT NULL
            ORDER BY actionUnix DESC, actionId DESC
            LIMIT 1
        ");

        $stmt->execute([':contactId' => $contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return $empty;
        }

        // Matched pair only — both present on this same row
        $lat = $row['latitude'];
        $lng = $row['longitude'];

        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return $empty;
        }

        return [
            'latitude'  => (float)$lat,
            'longitude' => (float)$lng,
        ];
    } catch (Throwable $e) {
        error_log('[getLastKnownGeo ERROR] ' . $e->getMessage());
        return $empty;
    }
}

// 🔐 getLastAuthAction() — Fetch last auth action for a user (login/logout)
// MUST read promptText (the column logAction actually writes)
// Login records MUST also write promptText = 'auth.login' for duplicate protection to work.
function getLastAuthAction(PDO $pdo, int $contactId): ?string
{
    try {
        $stmt = $pdo->prepare("
            SELECT promptText
            FROM tblActions
            WHERE contactId = :contactId
              AND promptText IN ('auth.login', 'auth.logout')
            ORDER BY actionUnix DESC, actionId DESC
            LIMIT 1
        ");

        $stmt->execute([':contactId' => $contactId]);
        $result = $stmt->fetchColumn();

        return $result !== false ? (string)$result : null;

    } catch (Throwable $e) {
        error_log('[getLastAuthAction ERROR] ' . $e->getMessage());
        return null;
    }
}