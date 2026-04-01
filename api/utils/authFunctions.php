<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — authFunctions.php
// Shared Authentication Utilities (SSE SAFE)
// ======================================================================

// 🔗 Dependencies (explicit + safe)
require_once __DIR__ . '/../dbConnect.php';
require_once __DIR__ . '/actions.php';

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
function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): void
{
    // Safe debug logging
    file_put_contents(__DIR__ . '/../auth_debug.log',
        json_encode([
            'time'      => date('Y-m-d H:i:s'),
            'stage'     => 'entered_logAuthAction',
            'actionKey' => $actionKey,
            'contactId' => $contactId,
            'meta'      => $meta
        ]) . PHP_EOL,
        FILE_APPEND
    );

    if ($contactId === null && strpos($actionKey, '.fail') === false) {
        error_log('[auth] missing contactId — skipping log for ' . $actionKey);
        return;
    }

    // Safe origin (fallback to 2 = SYSTEM)
    $origin = $meta['actionOrigin'] ?? 2;

    // Intent mapping
    $intent = match ($actionKey) {
        'auth.login'      => 'ui_login',
        'auth.logout'     => ($meta['actionOrigin'] ?? '') === 'idle_timeout' 
            ? 'idle_logout' 
            : 'ui_logout',
        'auth.login.fail' => 'auth_fail',
        default           => 'auth_event'
    };

    $payload = [
        "contactId"        => $contactId,
        "promptText"       => $actionKey,
        "responseText"     => !empty($meta) 
            ? json_encode($meta, JSON_UNESCAPED_SLASHES) 
            : null,
        "intent"           => $intent,
        "origin"           => $origin,                    // Fixed
        "intentConfidence" => 1.0,
        "createdUnixTime"  => time(),
        "latitude"         => $meta['latitude']  ?? null,
        "longitude"        => $meta['longitude'] ?? null
    ];

    try {
        insertActionPrompt($payload, $pdo);
    } catch (Throwable $e) {
        error_log('[logAuthAction INSERT ERROR] ' . $e->getMessage());
    }
}
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