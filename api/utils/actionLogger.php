<?php
declare(strict_types=1);

// ============================================================
// Skyesoft — actionLogger.php
// Centralized action logging (ELC-compliant, consistent)
// Version: 1.4.0 — Universal actionPayloadData / actionResponseData contract
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Normalize any value into a JSON object string.
 * Never returns null. Always returns a valid JSON object (at minimum "{}").
 */
function normalizeActionJson(mixed $value): string
{
    // Already a JSON object string
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '{}';
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Re-encode to guarantee consistent formatting
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

/**
 * logAction() — User action logger (name → ID resolved)
 * Returns the inserted actionId (0 on failure)
 *
 * Universal contract:
 *   actionPayloadData  and actionResponseData are ALWAYS valid JSON objects.
 *   Callers may omit them; the logger stores "{}".
 */
function logAction(PDO $db, array $p): int
{
    try {

        // 🔐 Ensure session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 🔕 Suppress logging
        if (!empty($p['suppressLog'])) {
            return 0;
        }

        // --- Required
        $actionName = trim((string)($p['actionName'] ?? ''));
        $intent     = trim((string)($p['intent'] ?? ''));
        $prompt     = trim((string)($p['prompt'] ?? ''));

        if ($actionName === '' || $intent === '' || $prompt === '') {
            error_log('logAction: missing required fields (actionName, intent, prompt).');
            return 0;
        }

        // --- Resolve actionTypeId (cached)
        static $actionCache = [];

        if (!isset($actionCache[$actionName])) {

            $stmt = $db->prepare("
                SELECT actionTypeId
                FROM tblActionTypes
                WHERE actionName = :name
                LIMIT 1
            ");

            $stmt->execute(['name' => $actionName]);

            $actionTypeId = $stmt->fetchColumn();

            if ($actionTypeId === false) {
                error_log("[logAction] Invalid actionName: {$actionName}");
                if (ini_get('display_errors')) {
                    throw new RuntimeException("Invalid actionName: {$actionName}");
                }
                return 0;
            }

            $actionCache[$actionName] = (int)$actionTypeId;
        }

        $actionTypeId = $actionCache[$actionName];

        // --- Optional normalization
        $contactId = !empty($p['contactId']) ? (int)$p['contactId'] : null;

        // Codex actionOrigin values:
        //   0 = User-initiated (UI / manual)
        //   1 = SSE inactivity logout
        //   2 = Sentinel inactivity logout
        $allowedOrigins = [0, 1, 2];
        $originValue = $p['origin'] ?? 0;
        $origin = in_array($originValue, $allowedOrigins, true) ? (int)$originValue : 0;

        $response = isset($p['response'])
            ? (is_string($p['response'])
                ? $p['response']
                : json_encode($p['response'], JSON_UNESCAPED_UNICODE))
            : null;

        // 🔒 truncate responseText
        if ($response) {
            $response = function_exists('mb_substr')
                ? mb_substr($response, 0, 10000)
                : substr($response, 0, 10000);
        }

        $confidence = isset($p['confidence']) ? (float)$p['confidence'] : 1.00;
        $lat        = $p['lat'] ?? null;
        $lng        = $p['lng'] ?? null;

        $ip = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // activitySessionId — honor caller-supplied value
        $activitySessionId = null;
        if (!empty($p['activitySessionId']) && is_string($p['activitySessionId'])) {
            $activitySessionId = trim($p['activitySessionId']);
        } elseif (!empty($p['sessionId']) && is_string($p['sessionId'])) {
            $activitySessionId = trim($p['sessionId']);
        }
        if ($activitySessionId === null || $activitySessionId === '') {
            $activitySessionId = session_id() ?: null;
        }

        // ============================================================
        // UNIVERSAL STRUCTURED DATA CONTRACT
        // Both fields are always valid JSON objects. Never NULL.
        // ============================================================
        $actionPayloadData  = normalizeActionJson($p['actionPayloadData']  ?? null);
        $actionResponseData = normalizeActionJson($p['actionResponseData'] ?? null);

        // --- Insert
        $stmt = $db->prepare("
            INSERT INTO tblActions (
                actionTypeId,
                contactId,
                actionOrigin,
                actionUnix,
                activitySessionId,
                promptText,
                responseText,
                actionPayloadData,
                actionResponseData,
                intent,
                intentConfidence,
                ipAddress,
                latitude,
                longitude,
                userAgent
            ) VALUES (
                :actionTypeId,
                :contactId,
                :origin,
                UNIX_TIMESTAMP(),
                :activitySessionId,
                :prompt,
                :response,
                :actionPayloadData,
                :actionResponseData,
                :intent,
                :confidence,
                :ip,
                :lat,
                :lng,
                :ua
            )
        ");

        $stmt->execute([
            'actionTypeId'       => $actionTypeId,
            'contactId'          => $contactId,
            'origin'             => $origin,
            'activitySessionId'  => $activitySessionId,
            'prompt'             => $prompt,
            'response'           => $response,
            'actionPayloadData'  => $actionPayloadData,
            'actionResponseData' => $actionResponseData,
            'intent'             => $intent,
            'confidence'         => $confidence,
            'ip'                 => $ip,
            'lat'                => $lat,
            'lng'                => $lng,
            'ua'                 => $ua
        ]);

        $actionId = (int)$db->lastInsertId();
        error_log("[logAction] SUCCESS | actionId=$actionId | actionName=$actionName | origin=$origin | activitySessionId=" . ($activitySessionId ?? 'null'));
        return $actionId;

    } catch (Throwable $e) {
        error_log('[logAction ERROR] ' . $e->getMessage());
        return 0;
    }
}