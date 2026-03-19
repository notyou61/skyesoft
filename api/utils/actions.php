<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — actions.php
//  Version: 1.0.0
//  Codex Tier: 2 — Action Layer / System Execution + Logging
//
//  Role:
//  Centralized action handling layer for Skyesoft.
//  Responsibilities:
//   • Persist all system/user actions to tblActions (authoritative log)
//   • Execute UI-level intents (clear, logout, etc.)
//   • Normalize action metadata (intent, geo, device)
//
//  Guarantees:
//   • No business logic mutation
//   • No AI orchestration
//   • No Codex mutation
//
//  Notes:
//   • This file is shared by askOpenAI.php and auth.php
//   • Acts as the "DO" layer (execution + logging)
//
// ======================================================================

#region SECTION 1 — Action Logging (tblActions)

// Append Prompt Ledger Entry (non-blocking, best-effort)
function insertActionPrompt(array $entry, ?PDO $db): void {

    error_log('[ACTIONS] FUNCTION ENTERED');
    error_log('[ACTIONS] ENTRY: ' . json_encode($entry));

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


    // #region 👤 Resolve Contact (STRICT — REQUIRED)

    $contactId =
        $entry['contactId']
        ?? $_SESSION['contactId']
        ?? null;

    if (!$contactId) {
        error_log('[ACTIONS] WARNING - missing contactId, using fallback');
        $contactId = 999999; // 🔥 debug fallback
    }

    // #endregion


    // #region 🧠 Normalize Fields

    $response   = $entry['responseText'] ?? null;
    $intent     = $entry['intent'] ?? 'unknown';

    $confidence = isset($entry['intentConfidence'])
        ? (float)$entry['intentConfidence']
        : 0.0;

    $unixTime = isset($entry['createdUnixTime'])
        ? (int)$entry['createdUnixTime']
        : time();

    // 🌍 Geo (validated numeric only)
    $latitude = is_numeric($entry['latitude'] ?? null)
        ? (float)$entry['latitude']
        : null;

    $longitude = is_numeric($entry['longitude'] ?? null)
        ? (float)$entry['longitude']
        : null;

    // 🌐 Network / Device Context
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // #endregion


    // #region 🧭 Action Origin Resolution

    $origin = (
        isset($_SESSION['contactId']) &&
        $contactId === $_SESSION['contactId']
    )
        ? ACTION_ORIGIN_USER
        : ACTION_ORIGIN_SYSTEM;

    // #endregion


    // #region 🧠 Action Type Mapping

    $actionTypeId = match ($intent) {
        'ui_login'  => 1,
        'ui_logout' => 2,
        default     => 3 // prompt / general action
    };

    // #endregion


    // #region ✂️ Truncate Payloads (DB Safety)

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
                promptText,
                responseText,
                intent,
                intentConfidence,
                ipAddress,
                latitude,
                longitude,
                userAgent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        error_log('[actions] INSERT ATTEMPT');

        $stmt->execute([
            $actionTypeId,
            $contactId,
            $origin,
            $unixTime,
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

        error_log('[actions] INSERT SUCCESS: actionId=' . $actionId);

    } catch (Throwable $e) {
        error_log('[actions] insert failed: ' . $e->getMessage());
        throw $e;
    }

    // #endregion
}

#endregion

#region SECTION 2 — Intent Execution Engine

// Execute Intent → returns UI action payload or null
function executeIntent(string $intent, float $confidence): ?array {

    // #region 🧹 UI Clear Screen

    if ($intent === "ui_clear" && $confidence >= 0.80) {
        return [
            "type"     => "ui_action",
            "response" => "clear_screen"
        ];
    }

    // #endregion


    // #region 🚪 UI Logout (frontend-handled)

    if ($intent === "ui_logout" && $confidence >= 0.90) {
        return [
            "type"     => "ui_action",
            "response" => "logout"
        ];
    }

    // #endregion


    // #region 🧭 Default (No Execution)

    return null;

    // #endregion
}

#endregion