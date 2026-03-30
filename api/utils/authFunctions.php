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
// 📜 AUTH ACTION LOGGER
// ─────────────────────────────────────────

function logAuthAction(PDO $pdo, string $actionKey, ?int $contactId, array $meta = []): void
{
    file_put_contents(__DIR__ . '/../auth_debug.log',
        json_encode([
            'stage' => 'entered_logAuthAction',
            'actionKey' => $actionKey,
            'contactId' => $contactId
        ]) . PHP_EOL,
        FILE_APPEND
    );

    if ($contactId === null) {
        error_log('[auth] missing contactId — skipping log');
        return;
    }

    $reason = $meta['actionOrigin'] ?? 'manual';

    $intent = match ($actionKey) {
        'auth.login'      => 'ui_login',
        'auth.logout'     => $reason === 'idle_timeout'
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
        "origin"           => $meta['actionOrigin'] ?? ACTION_ORIGIN_SYSTEM,
        "intentConfidence" => 1.0,
        "createdUnixTime"  => time()
    ];

    file_put_contents(
        __DIR__ . '/../auth_debug.log',
        json_encode([
            'time'       => date('Y-m-d H:i:s'),
            'stage'      => 'before_insert',
            'actionKey'  => $actionKey,
            'contactId'  => $contactId,
            'payload'    => $payload
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );

    insertActionPrompt($payload, $pdo);

    file_put_contents(
        __DIR__ . '/../auth_debug.log',
        json_encode([
            'time'       => date('Y-m-d H:i:s'),
            'stage'      => 'after_insert',
            'actionKey'  => $actionKey,
            'contactId'  => $contactId
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}