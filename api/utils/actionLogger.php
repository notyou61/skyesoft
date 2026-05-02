<?php
declare(strict_types=1);

// ============================================================
// Skyesoft — actionLogger.php
// Centralized action logging (ELC-compliant, consistent)
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

// 🧾 logAction() — User action logger (name → ID resolved)
function logAction(PDO $db, array $p): void
{
    try {

        // 🔐 Ensure session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 🔕 Suppress logging
        if (!empty($p['suppressLog'])) {
            return;
        }

        // --- Required (NEW MODEL)
        $actionName = trim((string)($p['actionName'] ?? ''));
        $intent     = trim((string)($p['intent'] ?? ''));
        $prompt     = trim((string)($p['prompt'] ?? ''));

        if ($actionName === '' || $intent === '' || $prompt === '') {
            error_log('logAction: missing required fields (actionName, intent, prompt).');
            return;
        }

        // --- Resolve actionTypeId (cached)
        static $actionCache = [];

        // 🔎 Resolve actionTypeId (cached + validated)
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

                // Optional strict mode (dev)
                if (ini_get('display_errors')) {
                    throw new RuntimeException("Invalid actionName: {$actionName}");
                }

                return;
            }

            $actionCache[$actionName] = (int)$actionTypeId;
        }

        $actionTypeId = $actionCache[$actionName];

        // --- Optional normalization
        $contactId = !empty($p['contactId']) ? (int)$p['contactId'] : null;

        $allowedOrigins = [1, 2, 3];

        $originValue = $p['origin'] ?? 1;

        $origin = in_array($originValue, $allowedOrigins, true)
            ? (int)$originValue
            : 1;

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

        // 🔥 Canonical session ID
        $activitySessionId = session_id();

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
            'intent'            => $intent,
            'confidence'        => $confidence,
            'ip'                => $ip,
            'lat'               => $lat,
            'lng'               => $lng,
            'ua'                => $ua
        ]);

    } catch (Throwable $e) {
        error_log('[logAction ERROR] ' . $e->getMessage());
    }
}