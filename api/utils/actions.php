<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — actions.php
//  Version: 1.5.0
//  Centralized Action Logging Layer
//  - Universal actionPayloadData / actionResponseData contract
//  - Honors caller-supplied activitySessionId
// ======================================================================

#region SECTION 1 — Action Logging (tblActions)

/**
 * Normalize any value into a JSON object string.
 * Never returns null. Always returns a valid JSON object (at minimum "{}").
 */
function normalizeActionJson(mixed $value): string
{
    // Already a non-empty JSON object/array string
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '{}';
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        // Scalar / non-object JSON → wrap it
        return json_encode(['value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    // Array or object
    if (is_array($value) || is_object($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($encoded !== false) ? $encoded : '{}';
    }

    // Null or missing
    if ($value === null) {
        return '{}';
    }

    // Scalar (int, float, bool)
    return json_encode(['value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function insertActionPrompt(array $entry, ?PDO $db): int
{
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
    $contactId = $entry['contactId'] ?? null;

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

    $ipAddress = $_SERVER['REMOTE_ADDR']     ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $origin       = $entry['origin'] ?? ACTION_ORIGIN_SYSTEM;
    $actionTypeId = $entry['actionTypeId'] ?? null;

    if (!$actionTypeId) {
        error_log('[actions] Missing actionTypeId');
        return 0;
    }

    // ─────────────────────────────
    // Universal structured-data contract
    // Both columns are ALWAYS valid JSON objects. Never SQL NULL.
    // ─────────────────────────────
    $actionPayloadData  = normalizeActionJson($entry['actionPayloadData']  ?? null);
    $actionResponseData = normalizeActionJson($entry['actionResponseData'] ?? null);

    // Truncate text fields
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
        error_log("[actions] SUCCESS | actionId=$actionId | activitySessionId=" . ($activitySessionId ?? 'null'));

        return $actionId;

    } catch (Throwable $e) {
        error_log('[actions] insert failed: ' . $e->getMessage());
        return 0;
    }
}

#endregion

#region SECTION 2 — Intent Execution Engine

function executeIntent(string $intent, float $confidence): ?array
{
    if ($intent === "ui_clear" && $confidence >= 0.80) {
        return ["type" => "ui_action", "response" => "clear_screen"];
    }

    if (in_array($intent, ["ui_logout", "idle_logout"], true) && $confidence >= 0.80) {
        return ["type" => "ui_action", "response" => "logout"];
    }

    return null;
}

#endregion