<?php
declare(strict_types=1);

// ============================================================
// Skyesoft — actionLogger.php
// Centralized action logging (ELC-compliant, consistent)
// ============================================================

function logAction(PDO $db, array $p): void
{
    // --- Required
    $type   = (int)($p['type'] ?? 0);
    $intent = (string)($p['intent'] ?? '');
    $prompt = (string)($p['prompt'] ?? '');

    if ($type <= 0 || $intent === '' || $prompt === '') {
        throw new InvalidArgumentException('logAction: missing required fields.');
    }

    // --- Optional (normalized)
    $contactId = isset($p['contactId']) ? (int)$p['contactId'] : null;

    $allowedOrigins = [1, 2, 3];
    $origin = in_array(($p['origin'] ?? 1), $allowedOrigins)
        ? (int)$p['origin']
        : 1;

    $response = isset($p['response'])
        ? (is_string($p['response']) ? $p['response'] : json_encode($p['response'], JSON_UNESCAPED_UNICODE))
        : null;

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

    if (!$stmt->execute([
        'type'       => $type,
        'contactId'  => $contactId,
        'origin'     => $origin,
        'prompt'     => $prompt,
        'response'   => $response,
        'intent'     => $intent,
        'confidence' => $confidence,
        'ip'         => $ip,
        'lat'        => $lat,
        'lng'        => $lng,
        'ua'         => $ua
    ])) {
        throw new RuntimeException('logAction: insert failed.');
    }
}