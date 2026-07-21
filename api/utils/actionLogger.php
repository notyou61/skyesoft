<?php
declare(strict_types=1);

// ============================================================
// Skyesoft — actionLogger.php
// Centralized action logging (ELC-compliant, consistent)
// Version: 1.2.0 — honors caller-supplied activitySessionId
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

// 🧾 logAction() — User action logger (name → ID resolved)
// Returns the inserted actionId (0 on failure)
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

        // --- Required (NEW MODEL)
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

        $allowedOrigins = [1, 2, 3];
        $originValue = $p['origin'] ?? 1;
        $origin = in_array($originValue, $allowedOrigins, true) ? (int)$originValue : 1;

        $response = isset($p['response'])
            ? (is_string($p['response'])
                ? $p['response']
                : json_encode($p['response'], JSON_UNESCAPED_UNICODE))
            : null;

        // 🔒 truncate response
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

        // 🔥 activitySessionId — honor caller-supplied value (critical for SSE idle logout)
        // Fallback to live session_id() only when the caller did not preserve one.
        $activitySessionId = null;
        if (!empty($p['activitySessionId']) && is_string($p['activitySessionId'])) {
            $activitySessionId = trim($p['activitySessionId']);
        }
        if ($activitySessionId === null || $activitySessionId === '') {
            $activitySessionId = session_id() ?: null;
        }

        // ============================================================
        // Structured Action Data (NEW)
        // ============================================================
        $actionPayloadData = null;
        if (array_key_exists('actionPayloadData', $p)) {
            $actionPayloadData = is_string($p['actionPayloadData'])
                ? $p['actionPayloadData']
                : json_encode($p['actionPayloadData'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $actionResponseData = null;
        if (array_key_exists('actionResponseData', $p)) {
            $actionResponseData = is_string($p['actionResponseData'])
                ? $p['actionResponseData']
                : json_encode($p['actionResponseData'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

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
            'actionTypeId'      => $actionTypeId,
            'contactId'         => $contactId,
            'origin'            => $origin,
            'activitySessionId' => $activitySessionId,
            'prompt'            => $prompt,
            'response'          => $response,
            'actionPayloadData' => $actionPayloadData,
            'actionResponseData'=> $actionResponseData,
            'intent'            => $intent,
            'confidence'        => $confidence,
            'ip'                => $ip,
            'lat'               => $lat,
            'lng'               => $lng,
            'ua'                => $ua
        ]);

        $actionId = (int)$db->lastInsertId();
        error_log("[logAction] SUCCESS | actionId=$actionId | actionName=$actionName | activitySessionId=" . ($activitySessionId ?? 'null'));
        return $actionId;

    } catch (Throwable $e) {
        error_log('[logAction ERROR] ' . $e->getMessage());
        return 0;
    }
}
