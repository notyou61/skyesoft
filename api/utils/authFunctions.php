<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — authFunctions.php
// Shared Authentication Utilities (SSE SAFE)
// Version: 1.4.3
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
// 🔐 logAuthAction() — Adapter to new action system
function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): void
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

        // --- Intent mapping
        $intent = match ($actionKey) {
            'auth.login'       => 'ui_login',
            'auth.logout'      => ($meta['actionOrigin'] ?? '') === 'idle_timeout'
                                    ? 'idle_logout'
                                    : 'ui_logout',
            'auth.login.fail'  => 'auth_fail',
            default            => 'auth_event'
        };

        // --- Prompt + response
        $prompt = $actionKey;
        $response = !empty($meta)
            ? json_encode($meta, JSON_UNESCAPED_SLASHES)
            : null;

        // --- Call NEW system
        logAction($pdo, [
            'actionName' => $actionName,
            'contactId'  => $contactId,
            'intent'     => $intent,
            'prompt'     => $prompt,
            'response'   => $response,
            'confidence' => 1.0,
            'lat'        => $meta['latitude']  ?? null,
            'lng'        => $meta['longitude'] ?? null
        ]);

    } catch (Throwable $e) {
        error_log('[logAuthAction ERROR] ' . $e->getMessage());
    }
}

// 🔐 logAction() — Core logger (from actionLogger.php, adapted for auth)
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

// 🔐 getLastAuthAction() — Fetch last auth action for a user (login/logout)
function getLastAuthAction(PDO $pdo, int $contactId): ?string
{
    $stmt = $pdo->prepare("
        SELECT promptText
        FROM tblActions
        WHERE contactId = :contactId
        AND promptText IN ('auth.login','auth.logout')
        ORDER BY actionUnix DESC
        LIMIT 1
    ");

    $stmt->execute(['contactId' => $contactId]);

    return $stmt->fetchColumn() ?: null;
}