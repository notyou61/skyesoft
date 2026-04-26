<?php
declare(strict_types=1);

/**
 * =============================================================================
 * 📇 getContacts.php
 * =============================================================================
 * PURPOSE
 * -----------------------------------------------------------------------------
 * Handles contact retrieval requests for the Skyesoft Command Interface.
 *
 * -----------------------------------------------------------------------------
 * KEY ARCHITECTURAL DECISIONS (Final Robust)
 * -----------------------------------------------------------------------------
 * • Always returns valid JSON (even on error)
 * • Uses canonical activitySessionId from sessionBootstrap
 * • ONE logging block
 * • Graceful error handling
 *
 * =============================================================================
 */

#region ⚙️ Init

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/sessionBootstrap.php';
require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/actions.php';

$input = json_decode(file_get_contents("php://input"), true) ?? [];

// === CRITICAL: Capture ORIGINAL query BEFORE any mutation ===
$originalQuery = trim($input['query'] ?? '');
$query         = strtolower($originalQuery);

if (!$query) {
    echo json_encode(["success" => false, "error" => "Missing query."]);
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

// Clean command words
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
    c.contactId, c.contactFirstName, c.contactLastName,
    c.contactEmail, c.contactPrimaryPhone, c.contactTitle,
    e.entityId, e.entityName
FROM tblContacts c
LEFT JOIN tblEntities e ON c.contactEntityId = e.entityId
WHERE 1=1
";

$params = [];

if ($nameFilter) {
    $sql .= " AND CONCAT(LOWER(c.contactFirstName), ' ', LOWER(c.contactLastName)) LIKE :name";
    $params[':name'] = "%$nameFilter%";
}
if ($entityFilter) {
    $sql .= " AND LOWER(e.entityName) LIKE :entity";
    $params[':entity'] = "%$entityFilter%";
}

if ($mode === 'single') {
    $sql .= " LIMIT 5";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($mode === 'single' && count($results) > 1) {
    $mode = 'list';
}

#endregion

#region 🌍 Geo Location Handling

$latitude  = is_numeric($input['latitude'] ?? null) ? (float)$input['latitude'] : null;
$longitude = is_numeric($input['longitude'] ?? null) ? (float)$input['longitude'] : null;

// Session fallback
if ($latitude === null || $longitude === null) {
    $entry = $_SESSION['userEntry'] ?? [];
    $latitude  = is_numeric($entry['latitude'] ?? null) ? (float)$entry['latitude'] : $latitude;
    $longitude = is_numeric($entry['longitude'] ?? null) ? (float)$entry['longitude'] : $longitude;
}

$latitude  = $latitude  ?? 0.0;
$longitude = $longitude ?? 0.0;

#endregion

#region 📝 Build Response Text for Logging

if (count($results) === 1) {
    $c = $results[0];
    $name    = trim(($c['contactFirstName'] ?? '') . ' ' . ($c['contactLastName'] ?? ''));
    $title   = $c['contactTitle'] ?? '';
    $company = $c['entityName'] ?? '';
    $phone   = $c['contactPrimaryPhone'] ?? '';
    $email   = $c['contactEmail'] ?? '';

    $responseParts = [];
    $responseParts[] = trim($name . ($title ? ", {$title}" : ""));
    if ($company) $responseParts[] = $company;
    if ($phone)   $responseParts[] = $phone;
    if ($email)   $responseParts[] = $email;

    $response = implode("\n", $responseParts);
} elseif (count($results) > 1) {
    $response = "Multiple contacts returned (" . count($results) . ")";
} else {
    $response = "No contacts found";
}

#endregion

#region 🧾 Log Contact Query Action (Unified)

try {
    $contactId = $results[0]['contactId'] ?? null;

    $lowerQuery = strtolower($originalQuery);
    $intent = match (true) {
        str_contains($lowerQuery, 'list') => 'contact_list',
        str_contains($lowerQuery, 'show') => 'contact_view',
        default => 'contact_query'
    };

    // 🔥 CANONICAL SESSION ID
    $activitySessionId = session_id();

    insertActionPrompt([
        'contactId'        => $contactId,
        'promptText'       => $originalQuery,
        'responseText'     => $response,
        'intent'           => $intent,
        'intentConfidence' => 1.00,
        'latitude'         => $latitude,
        'longitude'        => $longitude,
        'activitySessionId'=> $activitySessionId,     // ← updated
        'actionTypeId'     => 4,
        'origin'           => 2
    ], $db);

} catch (Throwable $e) {
    error_log('[getContacts] Logging failed: ' . $e->getMessage());
}

#endregion

#region 📦 Response

echo json_encode([
    "success"           => true,
    "mode"              => $mode,
    "contacts"          => $results ?? [],
    "activitySessionId" => $activitySessionId ?? session_id()   // ← added for frontend consistency
]);

#endregion