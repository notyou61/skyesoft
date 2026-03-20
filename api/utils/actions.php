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

#region SECTION 1 — Action Logging

function insertActionPrompt(array $entry, ?PDO $db): void {

    if (!$db) {
        error_log('[actions] DB not available');
        return;
    }

    if (empty($entry['promptText'])) {
        error_log('[actions] Missing promptText');
        return;
    }

    $contactId =
        $entry['contactId']
        ?? $_SESSION['contactId']
        ?? null;

    if (!$contactId) {
        error_log('[actions] contactId missing — blocking insert');
        return;
    }

    $response   = $entry['responseText'] ?? null;
    $intent     = $entry['intent'] ?? 'unknown';
    $confidence = isset($entry['intentConfidence'])
        ? (float)$entry['intentConfidence']
        : 0.0;

    $unixTime = $entry['createdUnixTime'] ?? time();

    $latitude  = is_numeric($entry['latitude'] ?? null) ? (float)$entry['latitude'] : null;
    $longitude = is_numeric($entry['longitude'] ?? null) ? (float)$entry['longitude'] : null;

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $origin = (
        isset($_SESSION['contactId']) &&
        $contactId === $_SESSION['contactId']
    )
        ? ACTION_ORIGIN_USER
        : ACTION_ORIGIN_SYSTEM;

    $actionTypeId = match ($intent) {
        'ui_login'  => 1,
        'ui_logout' => 2,
        default     => 3
    };

    $promptText = mb_substr((string)$entry['promptText'], 0, 5000);
    $response   = $response ? mb_substr((string)$response, 0, 10000) : null;

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

        error_log('[actions] INSERT SUCCESS');

    } catch (Throwable $e) {
        error_log('[actions] insert failed: ' . $e->getMessage());
    }
}

#endregion

#region SECTION 2 — Intent Execution

function executeIntent(string $intent, float $confidence): ?array {

    if ($intent === "ui_clear" && $confidence >= 0.80) {
        return ["type" => "ui_action", "response" => "clear_screen"];
    }

    if ($intent === "ui_logout" && $confidence >= 0.90) {
        return ["type" => "ui_action", "response" => "logout"];
    }

    return null;
}

#endregion