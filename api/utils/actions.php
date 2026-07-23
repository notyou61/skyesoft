<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — actions.php
//  Version: 1.4.1
//  Centralized Action Logging Layer (activitySessionId)
// ======================================================================

#region SECTION 1 — Action Logging (tblActions)

function insertActionPrompt(array $entry, ?PDO $db): int {

    if (!$db) {
        error_log('[actions] DB not available');
        return 0;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($entry['promptText'])) {
        error_log('[actions] Missing promptText');
        return 0;
    }

    // ─────────────────────────────
    // Normalize
    // ─────────────────────────────
    $contactId   = $entry['contactId'] ?? null;

    // Honor caller-supplied activitySessionId; fall back to live session only if absent
    $activitySessionId = null;
    if (!empty($entry['activitySessionId']) && is_string($entry['activitySessionId'])) {
        $activitySessionId = trim($entry['activitySessionId']);
    }
    if ($activitySessionId === null || $activitySessionId === '') {
        $activitySessionId = session_id() ?: null;
    }

    $response   = $entry['responseText'] ?? null;
    $intent     = $entry['intent'] ?? 'unknown';
    $confidence = (float)($entry['intentConfidence'] ?? 1.00);
    $unixTime   = (int)($entry['createdUnixTime'] ?? time());

    $latitude  = is_numeric($entry['latitude'] ?? null)  ? (float)$entry['latitude']  : null;
    $longitude = is_numeric($entry['longitude'] ?? null) ? (float)$entry['longitude'] : null;

    $ipAddress = $_SERVER['REMOTE_ADDR']    ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $origin       = $entry['origin'] ?? ACTION_ORIGIN_SYSTEM;
    $actionTypeId = $entry['actionTypeId'] ?? null;

    if (!$actionTypeId) {
        error_log('[actions] Missing actionTypeId');
        return 0;
    }

    // ─────────────────────────────
    // Structured Action Data
    // Always valid JSON objects — never SQL NULL
    // ─────────────────────────────
    $actionPayloadData = '{}';
    if (array_key_exists('actionPayloadData', $entry) && $entry['actionPayloadData'] !== null) {
        if (is_string($entry['actionPayloadData'])) {
            $trimmed = trim($entry['actionPayloadData']);
            $actionPayloadData = ($trimmed !== '') ? $trimmed : '{}';
        } else {
            $encoded = json_encode(
                $entry['actionPayloadData'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $actionPayloadData = ($encoded !== false) ? $encoded : '{}';
        }
    }

    $actionResponseData = '{}';
    if (array_key_exists('actionResponseData', $entry) && $entry['actionResponseData'] !== null) {
        if (is_string($entry['actionResponseData'])) {
            $trimmed = trim($entry['actionResponseData']);
            $actionResponseData = ($trimmed !== '') ? $trimmed : '{}';
        } else {
            $encoded = json_encode(
                $entry['actionResponseData'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $actionResponseData = ($encoded !== false) ? $encoded : '{}';
        }
    }

    // Truncate
    $promptText = function_exists('mb_substr')
        ? mb_substr((string)$entry['promptText'], 0, 5000)
        : substr((string)$entry['promptText'], 0, 5000);

    $response = $response
        ? (function_exists('mb_substr')
            ? mb_substr((string)$response, 0, 10000)
            : substr((string)$response, 0, 10000))
        : null;

    // ─────────────────────────────
    // Insert — Guaranteed Non-NULL Structured JSON
    // ─────────────────────────────
    try {
        $stmt = $db->prepare("
            INSERT INTO tblActions (
                actionTypeId, contactId, actionOrigin, actionUnix, activitySessionId,
                promptText, responseText, actionPayloadData, actionResponseData,
                intent, intentConfidence,
                ipAddress, latitude, longitude, userAgent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $actionTypeId,
            $contactId,
            $origin,
            $unixTime,
            $activitySessionId,
            $promptText,
            $response,
            $actionPayloadData,
            $actionResponseData,
            $intent,
            $confidence,
            $ipAddress,
            $latitude,
            $longitude,
            $userAgent
        ]);

        $actionId = (int)$db->lastInsertId();
        error_log("[actions] SUCCESS | actionId=$actionId | activitySessionId=$activitySessionId");

        return $actionId;

    } catch (Throwable $e) {
        error_log('[actions] insert failed: ' . $e->getMessage());
        return 0;
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