<?php
declare(strict_types=1);

/**
 * =============================================================================
 * 📇 getContacts.php
 * =============================================================================
 * PURPOSE
 * -----------------------------------------------------------------------------
 * Handles contact retrieval requests for the Skyesoft Command Interface.
 * Accepts natural language queries and returns structured contact data.
 *
 * -----------------------------------------------------------------------------
 * KEY ARCHITECTURAL DECISIONS (Current)
 * -----------------------------------------------------------------------------
 * • ONE logging block only
 * • Original query always preserved
 * • Full session tracing via requestId
 * • Full Latitude & Longitude support (from input + session)
 * • Logs every command (success/failure, single/list)
 *
 * =============================================================================
 */

#region ⚙️ Init

header("Content-Type: application/json; charset=UTF-8");

session_start();

require_once __DIR__ . '/dbConnect.php';

$input = json_decode(file_get_contents("php://input"), true);

// === CRITICAL: Capture ORIGINAL query BEFORE any mutation ===
$originalQuery = trim($input['query'] ?? '');
$query         = strtolower($originalQuery);

if (!$query) {
    echo json_encode([
        "success" => false,
        "error"   => "Missing query."
    ]);
    exit;
}

$db = getPDO();

#endregion

#region 🧠 Parse Query

$mode = str_starts_with($query, 'show') ? 'single' : 'list';

$nameFilter   = null;
$entityFilter = null;

// Extract "of entity"
if (strpos($query, ' of ') !== false) {
    [$before, $after] = explode(' of ', $query, 2);
    $entityFilter = trim($after);
    $query = trim($before);
}

// Clean command words (mutate only working copy)
$query = preg_replace('/^(show|list)\s+/', '', $query);
$query = str_replace(['contacts', 'for'], '', $query);
$query = trim($query);

if (!empty($query)) {
    $nameFilter = $query;
}

#endregion

#region 🔍 Build & Execute SQL

$sql = "
SELECT 
    c.contactId,
    c.contactFirstName,
    c.contactLastName,
    c.contactEmail,
    c.contactPrimaryPhone,
    c.contactTitle,
    e.entityId,
    e.entityName
FROM tblContacts c
LEFT JOIN tblEntities e ON c.contactEntityId = e.entityId
WHERE 1=1
";

$params = [];

// Name filter
if ($nameFilter) {
    $sql .= " AND CONCAT(LOWER(c.contactFirstName), ' ', LOWER(c.contactLastName)) LIKE :name";
    $params[':name'] = "%$nameFilter%";
}

// Entity filter
if ($entityFilter) {
    $sql .= " AND LOWER(e.entityName) LIKE :entity";
    $params[':entity'] = "%$entityFilter%";
}

// Limit for single mode
if ($mode === 'single') {
    $sql .= " LIMIT 5";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Downgrade to list if multiple matches
if ($mode === 'single' && count($results) > 1) {
    $mode = 'list';
}

#endregion

#region 🌍 Geo Location Handling

// 🌍 Geo — Pull from available sources
$latitude  = null;
$longitude = null;

// Priority 1: From current request payload (frontend can send real-time location)
if (isset($input['latitude']) && is_numeric($input['latitude'])) {
    $latitude = (float)$input['latitude'];
}
if (isset($input['longitude']) && is_numeric($input['longitude'])) {
    $longitude = (float)$input['longitude'];
}

// Priority 2: From session / user entry (your standard pattern)
if ($latitude === null || $longitude === null) {
    $entry = $_SESSION['userEntry'] ?? [];        // Change key if your session uses different name
    $latitude = is_numeric($entry['latitude'] ?? null)
        ? (float)$entry['latitude']
        : $latitude;

    $longitude = is_numeric($entry['longitude'] ?? null)
        ? (float)$entry['longitude']
        : $longitude;
}

#endregion

#region 🧾 Log Contact Query Action (SINGLE BLOCK)

try {
    $contactId = $results[0]['contactId'] ?? 0;
    $requestId = session_id();

    $actionTypeId = 4;                    // query
    $origin       = 2;                    // command interface
    $unixTime     = time();
    $promptText   = $originalQuery;

    // Smart intent
    $lowerQuery = strtolower($originalQuery);
    $intent = match (true) {
        str_contains($lowerQuery, 'list') => 'contact_list',
        str_contains($lowerQuery, 'show') => 'contact_view',
        default => 'contact_query'
    };

    $confidence = 1.00;
    $response   = null;
    $ipAddress  = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $logStmt = $db->prepare("
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $logStmt->execute([
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
        $latitude,      // ← Now properly populated
        $longitude,     // ← Now properly populated
        $userAgent
    ]);

    error_log("[actions] contact query logged | requestId={$requestId} | actionId=" . $db->lastInsertId() .
              " | geo=({$latitude},{$longitude})");

} catch (Throwable $e) {
    error_log('[actions] insert failed: ' . $e->getMessage());
    if (isset($logStmt)) {
        error_log('[actions] SQL ERROR: ' . json_encode($logStmt->errorInfo()));
    }
}

#endregion

#region 📦 Response

echo json_encode([
    "success"  => true,
    "mode"     => $mode,
    "contacts" => $results
]);

#endregion