<?php
declare(strict_types=1);

/**
 * Skyesoft — Database Integrity Check
 * Read-only diagnostic. Never mutates data.
 *
 * Usage:
 *   https://skyelighting.com/skyesoft/api/integrityCheck.php
 *   or with ?format=html for a readable table view
 */

header('Content-Type: application/json; charset=UTF-8');

// -------------------------------------------------
// Bootstrap (same pattern as askOpenAI.php)
// -------------------------------------------------
require_once __DIR__ . '/sessionBootstrap.php';
require_once __DIR__ . '/dbConnect.php';

if (!function_exists('getPDO')) {
    echo json_encode(['success' => false, 'error' => 'getPDO not available']);
    exit;
}

$db = getPDO();
$format = $_GET['format'] ?? 'json';

// -------------------------------------------------
// Helper
// -------------------------------------------------
function runQuery(PDO $db, string $sql): array
{
    try {
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['_error' => $e->getMessage()];
    }
}

// -------------------------------------------------
// Checks
// -------------------------------------------------
$results = [];

// 1. Summary counts
$results['summary'] = runQuery($db, "
    SELECT
        (SELECT COUNT(*) FROM tblContacts) AS total_contacts,
        (SELECT COUNT(*) FROM tblContacts WHERE COALESCE(isActive,1)=1) AS active_contacts,
        (SELECT COUNT(*) FROM tblEntities) AS total_entities,
        (SELECT COUNT(*) FROM tblContacts c
         LEFT JOIN tblEntities e ON e.entityId = c.contactEntityId
         WHERE c.contactEntityId IS NOT NULL AND e.entityId IS NULL) AS orphan_contacts,
        (SELECT COUNT(*) FROM (
            SELECT contactFirstName, contactLastName, contactEntityId
            FROM tblContacts
            WHERE COALESCE(isActive,1)=1
            GROUP BY LOWER(TRIM(contactFirstName)), LOWER(TRIM(contactLastName)), contactEntityId
            HAVING COUNT(*) > 1
        ) d) AS duplicate_name_groups
");

// 2. Orphan contacts (entity missing)
$results['orphan_contacts'] = runQuery($db, "
    SELECT
        c.contactId,
        c.contactFirstName,
        c.contactLastName,
        c.contactEntityId,
        c.contactEmail,
        c.contactPrimaryPhone,
        c.isActive
    FROM tblContacts c
    LEFT JOIN tblEntities e ON e.entityId = c.contactEntityId
    WHERE c.contactEntityId IS NOT NULL
      AND e.entityId IS NULL
    ORDER BY c.contactId
    LIMIT 200
");

// 3. Duplicate contacts by name + entity
$results['duplicate_name_entity'] = runQuery($db, "
    SELECT
        c.contactFirstName,
        c.contactLastName,
        c.contactEntityId,
        COUNT(*) AS duplicate_count,
        GROUP_CONCAT(c.contactId ORDER BY c.contactId) AS contact_ids
    FROM tblContacts c
    WHERE COALESCE(c.isActive, 1) = 1
    GROUP BY
        LOWER(TRIM(c.contactFirstName)),
        LOWER(TRIM(c.contactLastName)),
        c.contactEntityId
    HAVING COUNT(*) > 1
    ORDER BY duplicate_count DESC
    LIMIT 100
");

// 4. Duplicate contacts by email
$results['duplicate_email'] = runQuery($db, "
    SELECT
        LOWER(TRIM(c.contactEmail)) AS email,
        COUNT(*) AS cnt,
        GROUP_CONCAT(c.contactId ORDER BY c.contactId) AS contact_ids,
        GROUP_CONCAT(CONCAT(c.contactFirstName, ' ', c.contactLastName) SEPARATOR ' | ') AS names
    FROM tblContacts c
    WHERE c.contactEmail IS NOT NULL
      AND TRIM(c.contactEmail) <> ''
      AND COALESCE(c.isActive, 1) = 1
    GROUP BY LOWER(TRIM(c.contactEmail))
    HAVING COUNT(*) > 1
    ORDER BY cnt DESC
    LIMIT 100
");

// 5. Duplicate contacts by phone
$results['duplicate_phone'] = runQuery($db, "
    SELECT
        c.contactPrimaryPhone,
        COUNT(*) AS cnt,
        GROUP_CONCAT(c.contactId ORDER BY c.contactId) AS contact_ids
    FROM tblContacts c
    WHERE c.contactPrimaryPhone IS NOT NULL
      AND TRIM(c.contactPrimaryPhone) <> ''
      AND COALESCE(c.isActive, 1) = 1
    GROUP BY c.contactPrimaryPhone
    HAVING COUNT(*) > 1
    ORDER BY cnt DESC
    LIMIT 100
");

// 6. Contacts with NULL entityId
$results['contacts_no_entity'] = runQuery($db, "
    SELECT
        contactId,
        contactFirstName,
        contactLastName,
        contactEmail,
        contactPrimaryPhone,
        isActive
    FROM tblContacts
    WHERE contactEntityId IS NULL
      AND COALESCE(isActive, 1) = 1
    ORDER BY contactLastName, contactFirstName
    LIMIT 200
");

// 7. Entities with zero active contacts
$results['entities_no_contacts'] = runQuery($db, "
    SELECT
        e.entityId,
        e.entityName,
        e.entityStatus,
        e.entityIsNotValid
    FROM tblEntities e
    LEFT JOIN tblContacts c
           ON c.contactEntityId = e.entityId
          AND COALESCE(c.isActive, 1) = 1
    WHERE c.contactId IS NULL
    ORDER BY e.entityName
    LIMIT 200
");

// 8. Orphan locations (locationEntityId points to non-existent entity)
$results['orphan_locations'] = runQuery($db, "
    SELECT
        l.locationId,
        l.locationName,
        l.locationEntityId,
        l.locationAddress,
        l.locationCity,
        l.locationState
    FROM tblLocations l
    LEFT JOIN tblEntities e ON e.entityId = l.locationEntityId
    WHERE l.locationEntityId IS NOT NULL
      AND e.entityId IS NULL
    ORDER BY l.locationId
    LIMIT 200
");

// 9. Locations with NULL entityId
$results['locations_no_entity'] = runQuery($db, "
    SELECT
        locationId,
        locationName,
        locationAddress,
        locationCity,
        locationState
    FROM tblLocations
    WHERE locationEntityId IS NULL
    ORDER BY locationId
    LIMIT 200
");

// 10. Possible mismatched location ↔ entity
//     (city name in location does not appear in the linked entity name)
//     This is heuristic — review manually.
$results['possible_location_entity_mismatch'] = runQuery($db, "
    SELECT
        l.locationId,
        l.locationName,
        l.locationCity,
        l.locationEntityId,
        e.entityName,
        e.entityNormalizedName
    FROM tblLocations l
    INNER JOIN tblEntities e ON e.entityId = l.locationEntityId
    WHERE l.locationCity IS NOT NULL
      AND TRIM(l.locationCity) <> ''
      AND LOWER(e.entityName) NOT LIKE CONCAT('%', LOWER(TRIM(l.locationCity)), '%')
      AND LOWER(COALESCE(e.entityNormalizedName, '')) NOT LIKE CONCAT('%', LOWER(TRIM(l.locationCity)), '%')
    ORDER BY l.locationId
    LIMIT 200
");

// -------------------------------------------------
// Output
// -------------------------------------------------
if ($format === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Skyesoft DB Integrity Check</h1>';
    echo '<pre style="font-size:13px; background:#f8f8f8; padding:16px; border-radius:6px;">';
    echo htmlspecialchars(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '</pre>';
    exit;
}

echo json_encode([
    'success'   => true,
    'generated' => date('c'),
    'results'   => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);