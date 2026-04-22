<?php
declare(strict_types=1);

// #region ⚙️ Init

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

// #endregion

// #region 🧠 Parse Query

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

// #endregion

// #region 🔍 Build SQL (ELC-Aware)

$sql = "
SELECT 
    c.id,
    c.contactFirstName,
    c.contactLastName,
    c.contactEmail,
    c.contactPrimaryPhone,
    c.contactTitle,

    e.id   AS entityId,
    e.name AS entityName

FROM contacts c
LEFT JOIN entities e ON c.entityId = e.id
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
    $sql .= " AND LOWER(e.name) LIKE :entity";
    $params[':entity'] = "%$entityFilter%";
}

// Limit for single queries
if ($mode === 'single') {
    $sql .= " LIMIT 5";
}

// #endregion

// #region 🚀 Execute

$stmt = $db->prepare($sql);
$stmt->execute($params);

$results = $stmt->fetchAll();

// Adjust mode if ambiguous
if ($mode === 'single' && count($results) > 1) {
    $mode = 'list';
}

// #endregion

// #region 📦 Response

echo json_encode([
    "success"  => true,
    "mode"     => $mode,
    "contacts" => $results
]);

// #endregion