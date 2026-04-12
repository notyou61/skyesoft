<?php
declare(strict_types=1);

// ============================================================
// Skyesoft — actionLogger.php
// Centralized action logging (ELC-compliant, consistent)
// ============================================================

if (!defined('ACTION_ORIGIN_USER')) {
    define('ACTION_ORIGIN_USER', 'user');
}

function logAction(PDO $db, array $p): void
{
    // --- Required
    $type   = (int)($p['type'] ?? 0);
    $intent = (string)($p['intent'] ?? '');
    $prompt = (string)($p['prompt'] ?? '');

    if ($type <= 0 || $intent === '' || $prompt === '') {
        throw new InvalidArgumentException('logAction: missing required fields (type, intent, prompt).');
    }

    // --- Optional
    $contactId  = $p['contactId'] ?? null;
    $origin     = $p['origin'] ?? ACTION_ORIGIN_USER;
    $response   = $p['response'] ?? null;
    $confidence = isset($p['confidence']) ? (float)$p['confidence'] : 1.00;
    $lat        = $p['lat'] ?? null;
    $lng        = $p['lng'] ?? null;

    $ip = $_SERVER['REMOTE_ADDR']     ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

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
        ) VALUES (
            :type,
            :contactId,
            :origin,
            UNIX_TIMESTAMP(),
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
        'prompt'     => $prompt,      // 🔥 always stored
        'response'   => $response,    // JSON or text
        'intent'     => $intent,
        'confidence' => $confidence,
        'ip'         => $ip,
        'lat'        => $lat,
        'lng'        => $lng,
        'ua'         => $ua
    ]);
}