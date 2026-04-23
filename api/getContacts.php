<?php
declare(strict_types=1);

/**
 * =============================================================================
 * 📇 getContacts.php
 * =============================================================================
 * PURPOSE
 * -----------------------------------------------------------------------------
 * Handles contact retrieval requests for the Skyesoft Command Interface.
 * Accepts natural language queries (e.g., "show bill smith of legacy homes")
 * and returns structured contact data from the database.
 *
 * -----------------------------------------------------------------------------
 * INPUT (JSON via POST)
 * -----------------------------------------------------------------------------
 * {
 *   "query": "show contacts mesa"
 * }
 *
 * -----------------------------------------------------------------------------
 * OUTPUT (JSON)
 * -----------------------------------------------------------------------------
 * {
 *   "success": true,
 *   "mode": "single" | "list",
 *   "contacts": [ ... ]
 * }
 *
 * -----------------------------------------------------------------------------
 * QUERY BEHAVIOR
 * -----------------------------------------------------------------------------
 * - "show ..." → attempts single contact lookup
 * - "list ..." → returns multiple contacts
 * - Supports:
 *     • Name filtering (e.g., "bill smith")
 *     • Entity filtering (e.g., "of legacy homes")
 *
 * - If multiple matches are found in "single" mode:
 *     → automatically downgraded to "list"
 *
 * -----------------------------------------------------------------------------
 * DATABASE STRUCTURE (ELC MODEL)
 * -----------------------------------------------------------------------------
 * contacts → entities (via entityId)
 *
 * Tables:
 *   • contacts (c)
 *   • entities (e)
 *
 * -----------------------------------------------------------------------------
 * MATCHING LOGIC
 * -----------------------------------------------------------------------------
 * - Case-insensitive partial matching using SQL LIKE
 * - Name: CONCAT(firstName + lastName)
 * - Entity: entity name
 *
 * -----------------------------------------------------------------------------
 * FRONTEND CONTRACT
 * -----------------------------------------------------------------------------
 * - Frontend always attempts this endpoint first for "show/list" commands
 * - If:
 *     • success = false
 *     • contacts = []
 *   → frontend falls back to AI (askOpenAI.php)
 *
 * -----------------------------------------------------------------------------
 * CONSTRAINTS
 * -----------------------------------------------------------------------------
 * - No AI parsing occurs here (deterministic only)
 * - No fuzzy scoring (simple LIKE matching)
 * - No pagination (future enhancement)
 *
 * -----------------------------------------------------------------------------
 * FUTURE ENHANCEMENTS
 * -----------------------------------------------------------------------------
 * - Fuzzy matching / ranking (Levenshtein or scoring)
 * - Search by email / phone
 * - Location joins (ELC expansion)
 * - Pagination / limits
 * - AI-assisted parsing (hybrid mode)
 *
 * =============================================================================
 */

#region ⚙️ Init

header("Content-Type: application/json; charset=UTF-8");

session_start();                                           // Required for $_SESSION['user_id']

require_once __DIR__ . '/dbConnect.php';

$input = json_decode(file_get_contents("php://input"), true);
$originalQuery = trim($input['query'] ?? '');             // Keep original query for logging
$query = strtolower($originalQuery);

if (!$query) {
    echo json_encode([
        "success" => false,
        "error" => "Missing query."
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

// Clean command words
$query = preg_replace('/^(show|list)\s+/', '', $query);
$query = str_replace(['contacts', 'for'], '', $query);
$query = trim($query);

// Remaining text = name
if (!empty($query)) {
    $nameFilter = $query;
}

#endregion

#region 🔍 Build SQL (ELC-Aware — FIXED)

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

// Limit for single queries
if ($mode === 'single') {
    $sql .= " LIMIT 5";
}

#endregion

#region 🚀 Execute

$stmt = $db->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Adjust mode if ambiguous
if ($mode === 'single' && count($results) > 1) {
    $mode = 'list';
}

#endregion

#region 🧾 Log Action Helper

function logAction($db, string $actionType, array $data = []): void
{
    try {
        $stmt = $db->prepare("
            INSERT INTO tblActions (
                actionType, 
                contactId, 
                entityId, 
                userId, 
                actionNote, 
                createdAt
            ) VALUES (
                :actionType, 
                :contactId, 
                :entityId, 
                :userId,
                :actionNote, 
                NOW()
            )
        ");

        $stmt->execute([
            ':actionType' => $actionType,
            ':contactId'  => $data['contactId'] ?? null,
            ':entityId'   => $data['entityId']   ?? null,
            ':userId'     => $data['userId']     ?? ($_SESSION['user_id'] ?? null),
            ':actionNote' => $data['actionNote'] ?? null
        ]);
    } catch (Exception $e) {
        error_log('[ACTION LOG ERROR] ' . $e->getMessage());
    }
}

#endregion

#region 🧾 Log Contact View Action

$resultsCount = count($results);

if ($mode === 'single' && $resultsCount === 1) {
    // Single contact viewed
    $c = $results[0];
    logAction($db, 'contact_view', [
        'contactId'  => $c['contactId'] ?? null,
        'entityId'   => $c['entityId'] ?? null,
        'actionNote' => "Viewed contact via command interface | Query: {$originalQuery}"
    ]);
}
elseif ($resultsCount > 0) {
    // List / multiple results
    logAction($db, 'contact_list_view', [
        'actionNote' => "Viewed contact list ({$resultsCount} results) | Query: {$originalQuery}"
    ]);
}
else {
    // No results found
    logAction($db, 'contact_search_no_results', [
        'actionNote' => "No contacts found | Query: {$originalQuery}"
    ]);
}

#endregion

#region 📦 Response

echo json_encode([
    "success"  => true,
    "mode"     => $mode,
    "contacts" => $results
]);

#endregion