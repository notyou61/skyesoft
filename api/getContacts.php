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
 * KEY ARCHITECTURAL DECISIONS (Latest MTCO)
 * -----------------------------------------------------------------------------
 * • ONE logging block only (uses centralized insertActionPrompt)
 * • Original query always preserved
 * • Full session tracing via requestId
 * • Rich, clean responseText (contact summary or status)
 * • Reliable Latitude & Longitude (input → session → safe default)
 * • contactId = NULL when no match
 * • Consistent logging with askOpenAI.php
 *
 * =============================================================================
 */

#region ⚙️ Init

header("Content-Type: application/json; charset=UTF-8");

session_start();

require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/actions.php';   // ← Centralized logging

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

$latitude  = null;
$longitude = null;

// Priority 1: From frontend request
if (isset($input['latitude']) && is_numeric($input['latitude'])) {
    $latitude = (float)$input['latitude'];
}
if (isset($input['longitude']) && is_numeric($input['longitude'])) {
    $longitude = (float)$input['longitude'];
}

// Priority 2: Session fallback
if ($latitude === null || $longitude === null) {
    $entry = $_SESSION['userEntry'] ?? [];
    $latitude = is_numeric($entry['latitude'] ?? null)
        ? (float)$entry['latitude']
        : $latitude;

    $longitude = is_numeric($entry['longitude'] ?? null)
        ? (float)$entry['longitude']
        : $longitude;
}

// Final safety net
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

    // Smart intent
    $lowerQuery = strtolower($originalQuery);
    $intent = match (true) {
        str_contains($lowerQuery, 'list') => 'contact_list',
        str_contains($lowerQuery, 'show') => 'contact_view',
        default => 'contact_query'
    };

    insertActionPrompt([
        'contactId'        => $contactId,
        'promptText'       => $originalQuery,
        'responseText'     => $response ?? null,
        'intent'           => $intent,
        'intentConfidence' => 1.00,
        'latitude'         => $latitude,
        'longitude'        => $longitude,
        'requestId'        => session_id(),           // ← Full session tracing
        'actionTypeId'     => 4,                      // query
        'origin'           => 2                       // command interface
    ], $db);

    error_log("[actions] contact query logged | contactId=" . ($contactId ?? 'NULL') . " | requestId=" . session_id());

} catch (Throwable $e) {
    error_log('[actions] insertActionPrompt failed in getContacts.php: ' . $e->getMessage());
}

#endregion

#region 📦 Response

echo json_encode([
    "success"  => true,
    "mode"     => $mode,
    "contacts" => $results
]);

#endregion