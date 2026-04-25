<?php
declare(strict_types=1);

// ============================================================
// Skyesoft — actionLogger.php
// Centralized action logging (ELC-compliant, consistent)
// ============================================================

function logAction(PDO $db, array $p): void
{
    try {

        // 🔐 Ensure session exists
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 🔕 Skip logging when requested
        if (!empty($p['suppressLog'])) {
            return;
        }

        // --- Required
        $type   = (int)($p['type'] ?? 0);
        $intent = (string)($p['intent'] ?? '');
        $prompt = (string)($p['prompt'] ?? '');

        if ($type <= 0 || $intent === '' || $prompt === '') {
            error_log('logAction: missing required fields.');
            return;
        }

        // --- Optional (safe normalization)
        $contactId = !empty($p['contactId']) ? (int)$p['contactId'] : null;

        $allowedOrigins = [1, 2, 3];
        $origin = in_array(($p['origin'] ?? 1), $allowedOrigins)
            ? (int)$p['origin']
            : 1;

        $response = isset($p['response'])
            ? (is_string($p['response'])
                ? $p['response']
                : json_encode($p['response'], JSON_UNESCAPED_UNICODE))
            : null;

        // 🔒 truncate response
        $response = $response
            ? (function_exists('mb_substr')
                ? mb_substr($response, 0, 10000)
                : substr($response, 0, 10000))
            : null;

        $confidence = isset($p['confidence']) ? (float)$p['confidence'] : 1.00;
        $lat        = $p['lat'] ?? null;
        $lng        = $p['lng'] ?? null;

        $ip = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // 🔥 Authoritative requestId
        $requestId = session_id();

        $stmt = $db->prepare("
            INSERT INTO tblActions (
                actionTypeId,
                contactId,
                actionOrigin,
                actionUnix,
                requestId,
                promptText,
                responseText,
                intent,
                intentConfidence,
                ipAddress,
                latitude,
                longitude,
                userAgent
            ) VALUES (
                :type,
                :contactId,
                :origin,
                UNIX_TIMESTAMP(),
                :requestId,
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
            'type'       => $type,
            'contactId'  => $contactId,
            'origin'     => $origin,
            'requestId'  => $requestId,
            'prompt'     => $prompt,
            'response'   => $response,
            'intent'     => $intent,
            'confidence' => $confidence,
            'ip'         => $ip,
            'lat'        => $lat,
            'lng'        => $lng,
            'ua'         => $ua
        ]);

    } catch (Throwable $e) {
        error_log('[logAction ERROR] ' . $e->getMessage());
        return;
    }
}