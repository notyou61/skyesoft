<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — authFunctions.php
// Shared Authentication Utilities (SSE SAFE)
// Version: 1.4.3
// ======================================================================

require_once __DIR__ . '/../../sessionBootstrap.php';
require_once __DIR__ . '/../../dbConnect.php';
require_once __DIR__ . '/actionLogger.php';

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

function clearUserWorkspaceArtifacts(?int $contactId = null): void
{
    // Backup fallback if execution loop drops variables early
    if ($contactId === null || $contactId <= 0) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $contactId = isset($_SESSION['contactId']) ? (int)$_SESSION['contactId'] : 0;
    }

    if ($contactId <= 0) {
        error_log('[AUTH WORKSPACE CLEANUP] Aborted — No active contact context could be resolved.');
        return;
    }

    // 🔍 Adjusted structural pathing to find /skyesoft/artifacts folder reliably
    $artifactDir = dirname(__DIR__, 2) . '/artifacts';

    if (!is_dir($artifactDir)) {
        error_log("[AUTH WORKSPACE CLEANUP] Missing target space directory: {$artifactDir}");
        return;
    }

    $contactSegment = str_pad((string)$contactId, 3, '0', STR_PAD_LEFT);
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
            error_log("[AUTH WORKSPACE CLEANUP] File locked or unresolvable: {$file}");
        }
    }

    error_log("[AUTH WORKSPACE CLEANUP] Complete — Contact: {$contactId} | Purged: {$deleted} | Blocked: {$failed}");
}

// ─────────────────────────────────────────
// 📜 AUTH ACTION LOGGER
// ─────────────────────────────────────────

function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): void
{
    // Intercept immediately on structural boundary transitions
    if ($actionKey === 'auth.logout') {
        error_log("[Auth Engine] Running pre-destruction workspace clear tracking.");
        clearUserWorkspaceArtifacts($contactId);
    }

    try {
        $actionName = match ($actionKey) {
            'auth.login'       => 'auth.session.login',
            'auth.logout'      => 'auth.session.logout',
            'auth.login.fail'  => 'auth.session.login',
            default            => 'auth.session.login'
        };

        $intent = match ($actionKey) {
            'auth.login'       => 'ui_login',
            'auth.logout'      => ($meta['actionOrigin'] ?? '') === 'idle_timeout' ? 'idle_logout' : 'ui_logout',
            'auth.login.fail'  => 'auth_fail',
            default            => 'auth_event'
        };

        $response = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null;

        logAction($pdo, [
            'actionName' => $actionName,
            'contactId'  => $contactId,
            'intent'     => $intent,
            'prompt'     => $actionKey,
            'response'   => $response,
            'confidence' => 1.0,
            'lat'        => $meta['latitude']  ?? null,
            'lng'        => $meta['longitude'] ?? null
        ]);

    } catch (Throwable $e) {
        error_log('[logAuthAction ERROR] ' . $e->getMessage());
    }
}

function getContactName(mixed $contactId): array {
    if ($contactId === null || $contactId === '' || $contactId === false || $contactId === 'null') {
        return ['firstName' => null, 'lastName' => null];
    }

    $contactId = filter_var($contactId, FILTER_VALIDATE_INT);
    if ($contactId === false) {
        return ['firstName' => null, 'lastName' => null];
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT contactFirstName, contactLastName FROM tblContacts WHERE contactId = :id LIMIT 1");
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