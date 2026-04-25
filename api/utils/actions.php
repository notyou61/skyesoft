<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — actions.php
//  Version: 1.1.0
//  Centralized Action Logging Layer
// ======================================================================

#region SECTION 1 — Action Logging (tblActions)

function insertActionPrompt(array $entry, ?PDO $db): void {

    if (!$db) {
        error_log('[actions] DB not available');
        return;
    }

    // 🔐 Ensure session exists
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($entry['promptText'])) {
        error_log('[actions] Missing promptText');
        return;
    }

    // ─────────────────────────────
    // Normalize
    // ─────────────────────────────
    $contactId  = $entry['contactId'] ?? null;
    $requestId  = session_id(); // 🔥 authoritative
    $response   = $entry['responseText'] ?? null;
    $intent     = $entry['intent'] ?? 'unknown';
    $confidence = (float)($entry['intentConfidence'] ?? 1.00);
    $unixTime   = (int)($entry['createdUnixTime'] ?? time());

    $latitude   = is_numeric($entry['latitude'] ?? null) ? (float)$entry['latitude'] : null;
    $longitude  = is_numeric($entry['longitude'] ?? null) ? (float)$entry['longitude'] : null;

    $ipAddress  = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $origin     = $entry['origin'] ?? ACTION_ORIGIN_SYSTEM;

    // 🔥 Deterministic action type (caller decides)
    $actionTypeId = $entry['actionTypeId'] ?? 3;

    // ─────────────────────────────
    // Truncate
    // ─────────────────────────────
    $promptText = function_exists('mb_substr')
        ? mb_substr((string)$entry['promptText'], 0, 5000)
        : substr((string)$entry['promptText'], 0, 5000);

    $response = $response
        ? (function_exists('mb_substr')
            ? mb_substr((string)$response, 0, 10000)
            : substr((string)$response, 0, 10000))
        : null;

    // ─────────────────────────────
    // Insert
    // ─────────────────────────────
    try {
        $stmt = $db->prepare("
            INSERT INTO tblActions (
                actionTypeId, contactId, actionOrigin, actionUnix, requestId,
                promptText, responseText, intent, intentConfidence,
                ipAddress, latitude, longitude, userAgent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $actionTypeId,
            $contactId,
            $origin,
            $unixTime,
            $requestId,
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
        error_log("[actions] SUCCESS | actionId=$actionId | requestId=$requestId | intent=$intent");

    } catch (Throwable $e) {
        error_log('[actions] insert failed: ' . $e->getMessage());
        if (isset($stmt)) {
            error_log('[actions] SQL ERROR: ' . json_encode($stmt->errorInfo()));
        }
    }
}

#endregion

#region SECTION 2 — Intent Execution Engine

function executeIntent(string $intent, float $confidence): ?array {
    if ($intent === "ui_clear" && $confidence >= 0.80) {
        return ["type" => "ui_action", "response" => "clear_screen"];
    }

    if (in_array($intent, ["ui_logout", "idle_logout"], true) && $confidence >= 0.80) {
        return ["type" => "ui_action", "response" => "logout"];
    }

    return null;
}

#endregion