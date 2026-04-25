<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — actions.php
//  Version: 1.1.0
//  Codex Tier: 2 — Action Layer / System Execution + Logging
//
//  Role:
//  Centralized action handling layer for Skyesoft.
//  Responsibilities:
//   • Persist all system/user actions to tblActions (authoritative log)
//   • Execute UI-level intents (clear, logout, etc.)
//   • Normalize action metadata (intent, geo, device, requestId)
//
//  Guarantees:
//   • No business logic mutation
//   • No AI orchestration
//   • No Codex mutation
//
//  Notes:
//   • Shared by askOpenAI.php, getContacts.php, auth.php, etc.
//   • Now supports requestId for session tracing
//
// ======================================================================

#region SECTION 1 — Action Logging (tblActions)

// Append Prompt Ledger Entry (non-blocking, best-effort)
function insertActionPrompt(array $entry, ?PDO $db): void {

    // Debug entry (optional, safe)
    file_put_contents(
        __DIR__ . '/auth_debug.log',
        json_encode([
            'time'  => date('Y-m-d H:i:s'),
            'stage' => 'insert_function_entered',
            'hasRequestId' => isset($entry['requestId'])
        ]) . PHP_EOL,
        FILE_APPEND
    );

    // #region 🧾 Validate Input
    if (!$db) {
        error_log('[actions] DB not available');
        return;
    }

    if (empty($entry['promptText'])) {
        error_log('[actions] Missing promptText');
        return;
    }
    // #endregion

    // #region 👤 Resolve Contact
    $contactId = $entry['contactId'] ?? null;

    if ($contactId === null) {
        error_log('[ACTIONS] WARNING - missing contactId (will still attempt insert)');
        // Do NOT return — allow logging of system/system queries
    }
    // #endregion

    // #region 🧠 Normalize Fields
    $response   = $entry['responseText'] ?? null;
    $intent     = $entry['intent'] ?? 'unknown';

    $confidence = isset($entry['intentConfidence'])
        ? (float)$entry['intentConfidence']
        : 1.00;

    $unixTime = isset($entry['createdUnixTime'])
        ? (int)$entry['createdUnixTime']
        : time();

    // 🌍 Geo
    $latitude = is_numeric($entry['latitude'] ?? null)
        ? (float)$entry['latitude']
        : null;

    $longitude = is_numeric($entry['longitude'] ?? null)
        ? (float)$entry['longitude']
        : null;

    // 🌐 Network + Session
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $requestId = $entry['requestId'] ?? session_id();   // ← NEW: requestId support

    // #endregion

    // #region 🧭 Action Origin & Type
    $origin = $entry['origin'] ?? ACTION_ORIGIN_SYSTEM;

    $actionTypeId = $entry['actionTypeId'] ?? match ($intent) {
        'ui_login'    => 1,
        'ui_logout'   => 2,
        'idle_logout' => 2,
        'contact_query',
        'contact_view',
        'contact_list' => 4,           // query
        default       => 3
    };
    // #endregion

    // #region ✂️ Truncate Payloads
    $promptText = function_exists('mb_substr')
        ? mb_substr((string)$entry['promptText'], 0, 5000)
        : substr((string)$entry['promptText'], 0, 5000);

    $response = $response
        ? (function_exists('mb_substr')
            ? mb_substr((string)$response, 0, 10000)
            : substr((string)$response, 0, 10000))
        : null;
    // #endregion

    // #region 📥 Insert → tblActions
    try {
        $stmt = $db->prepare("
            INSERT INTO tblActions (
                actionTypeId,
                contactId,
                actionOrigin,
                actionUnix,
                requestId,                    -- ← Added
                promptText,
                responseText,
                intent,
                intentConfidence,
                ipAddress,
                latitude,
                longitude,
                userAgent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $actionTypeId,
            $contactId,
            $origin,
            $unixTime,
            $requestId,                       // ← Added
            $promptText,
            $response,
            $intent,
            $confidence,
            $ipAddress,
            $latitude,
            $longitude,
            $userAgent
        ]);

        $actionId = $db->lastInsertId();
        error_log("[actions] INSERT SUCCESS | actionId=$actionId | requestId=$requestId | contactId=" . ($contactId ?? 'NULL'));

    } catch (Throwable $e) {
        error_log('[actions] insert failed: ' . $e->getMessage());
        if (isset($stmt)) {
            error_log('[actions] SQL ERROR: ' . json_encode($stmt->errorInfo()));
        }
    }
    // #endregion
}

#endregion

#region SECTION 2 — Intent Execution Engine

// Execute Intent → returns UI action payload or null
function executeIntent(string $intent, float $confidence): ?array {

    if ($intent === "ui_clear" && $confidence >= 0.80) {
        return [
            "type"     => "ui_action",
            "response" => "clear_screen"
        ];
    }

    if ($intent === "ui_logout" && $confidence >= 0.90) {
        return [
            "type"     => "ui_action",
            "response" => "logout"
        ];
    }

    return null;
}

#endregion