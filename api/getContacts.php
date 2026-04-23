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

require_once __DIR__ . '/dbConnect.php';

$input = json_decode(file_get_contents("php://input"), true);
$query = strtolower(trim($input['query'] ?? ''));

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

$results = $stmt->fetchAll();

// Adjust mode if ambiguous
if ($mode === 'single' && count($results) > 1) {
    $mode = 'list';
}

#endregion

#region 📦 Response

echo json_encode([
    "success"  => true,
    "mode"     => $mode,
    "contacts" => $results
]);

#endregion