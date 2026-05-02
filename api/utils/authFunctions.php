<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — authFunctions.php
// Shared Authentication Utilities (SSE SAFE)
// ======================================================================

// 🔗 Dependencies (explicit + safe)
require_once __DIR__ . '/../dbConnect.php';

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
// 📜 AUTH ACTION LOGGER - FIXED (No undefined constants)
// ─────────────────────────────────────────
// 🔐 logAuthAction() — Adapter to new action system
function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): void
{
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
function getContactName(?int $contactId): array {

    if ($contactId === null) {
        return ['firstName' => null, 'lastName' => null];
    }

    try {
        $pdo = getPDO();

        $stmt = $pdo->prepare("
            SELECT contactFirstName, contactLastName
            FROM tblContacts
            WHERE contactId = :id
            LIMIT 1
        ");

        $stmt->execute([':id' => $contactId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'firstName' => $row['contactFirstName'] ?? null,
            'lastName'  => $row['contactLastName'] ?? null
        ];

    } catch (Throwable $e) {
        error_log('[NAME RESOLVE ERROR] ' . $e->getMessage());
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