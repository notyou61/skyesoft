<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — createContact.php
//  Version: 1.2.0
//  Last Updated: 2026-04-07
//  Codex Tier: 2 — ELC Execution Layer
//
//  Role:
//  Orchestrates contact creation pipeline (ELC).
//
//  Responsibilities:
//   • Parse incoming contact input
//   • Coordinate entity, location, contact resolution
//   • Execute duplicate + fork engine
//   • Execute transaction-safe inserts
//
//  Forbidden:
//   • No AI parsing logic (delegated)
//   • No bypass of location validation
//   • No partial persistence outside transaction boundaries
// ======================================================================

#region SECTION 0 — Environment Bootstrap

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/parseContact.php';
require_once __DIR__ . '/resolveLocation.php';
require_once __DIR__ . '/dbConnect.php';

const ACTION_TYPE_CREATE_CONTACT = 1;

const ACTION_ORIGIN_USER       = 1;
const ACTION_ORIGIN_SYSTEM     = 2;
const ACTION_ORIGIN_AUTOMATION = 3;

#endregion

#region SECTION 1 — Input Handling

$input = $_POST['input'] ?? '';

if (!$input) {
    echo json_encode([
        'status' => 'reject',
        'reason' => 'No input provided'
    ]);
    exit;
}

#endregion

#region SECTION 2 — Core Processing

$parsed = parseContact($input);
$location = resolveLocation($parsed['location'] ?? []);

#endregion

#region SECTION 3 — Validation Gates

// 🚫 Missing Entity / Contact
if (empty($parsed['entity']) || empty($parsed['contact'])) {
    echo json_encode([
        'status' => 'reject',
        'reason' => 'Missing entity or contact'
    ]);
    exit;
}

// ⚠️ Location Validation Gate (placeId required)
if (empty($location['placeId'])) {
    echo json_encode([
        'status' => 'partial',
        'reason' => 'Location not validated',
        'location' => $location
    ]);
    exit;
}

#endregion

#region SECTION 4 — Entity + Location + Contact Resolution

$entity = resolveEntity($db, $parsed['entity']['name'] ?? '');

// ─────────────────────────────────────────
// Location DB Resolution
// ─────────────────────────────────────────
if ($entity['status'] === 'existing') {
    $locationRecord = resolveLocationRecord(
        $db,
        $entity['entityId'],
        $location['placeId']
    );
} else {
    $locationRecord = [
        'status' => 'new',
        'locationId' => null
    ];
}

// ─────────────────────────────────────────
// Contact Resolution
// ─────────────────────────────────────────
if (
    $entity['status'] === 'existing' &&
    $locationRecord['status'] === 'existing'
) {
    $contactRecord = resolveContact(
        $db,
        $entity['entityId'],
        $locationRecord['locationId'],
        $parsed['contact']
    );
} else {
    $contactRecord = [
        'status' => 'new',
        'contactId' => null
    ];
}

// ─────────────────────────────────────────
// Fork Outcome
// ─────────────────────────────────────────
$outcome = buildResolutionOutcome(
    $entity,
    $locationRecord,
    $contactRecord
);

#endregion

#region SECTION 5 — Entity Resolution

function resolveEntity(PDO $db, string $entityName): array {

    $entityName = trim($entityName);

    if ($entityName === '') {
        return [
            'status' => 'new',
            'entityId' => null
        ];
    }

    $stmt = $db->prepare("
        SELECT entityId
        FROM tblEntities
        WHERE entityName = :name
        LIMIT 1
    ");

    $stmt->execute(['name' => $entityName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'status' => 'existing',
            'entityId' => (int)$row['entityId']
        ];
    }

    return [
        'status' => 'new',
        'entityId' => null
    ];
}

#endregion

#region SECTION 6 — Location Resolution (DB Layer)

function resolveLocationRecord(PDO $db, int $entityId, string $placeId): array {

    $stmt = $db->prepare("
        SELECT locationId
        FROM tblLocations
        WHERE locationEntityId = :entityId
        AND locationPlaceId = :placeId
        LIMIT 1
    ");

    $stmt->execute([
        'entityId' => $entityId,
        'placeId' => $placeId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'status' => 'existing',
            'locationId' => (int)$row['locationId']
        ];
    }

    return [
        'status' => 'new',
        'locationId' => null
    ];
}

#endregion

#region SECTION 7 — Contact Duplicate Detection

function resolveContact(PDO $db, int $entityId, int $locationId, array $contact): array {

    $firstName = trim((string)($contact['firstName'] ?? ''));
    $lastName  = trim((string)($contact['lastName'] ?? ''));

    // 🔍 Primary Match (ELC Identity)
    $stmt = $db->prepare("
        SELECT contactId
        FROM tblContacts
        WHERE contactEntityId = :entityId
        AND contactLocationId = :locationId
        AND contactFirstName = :firstName
        AND contactLastName = :lastName
        LIMIT 1
    ");

    $stmt->execute([
        'entityId' => $entityId,
        'locationId' => $locationId,
        'firstName' => $firstName,
        'lastName' => $lastName
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'status' => 'exact_match',
            'contactId' => (int)$row['contactId']
        ];
    }

    // ⚠️ Secondary Match (Email)
    if (!empty($contact['email'])) {

        $stmt = $db->prepare("
            SELECT contactId
            FROM tblContacts
            WHERE contactEntityId = :entityId
            AND contactEmail = :email
            LIMIT 1
        ");

        $stmt->execute([
            'entityId' => $entityId,
            'email' => $contact['email']
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status' => 'possible_match',
                'contactId' => (int)$row['contactId']
            ];
        }
    }

    return [
        'status' => 'new',
        'contactId' => null
    ];
}

#endregion

#region SECTION 8 — Resolution Fork Engine

function buildResolutionOutcome(array $entity, array $locationRecord, array $contact): array {

    // ❌ Reject (invalid structure)
    if (empty($entity['status'])) {
        return ['outcome' => 'reject'];
    }

    // ⚠️ Partial (defensive fallback)
    if (empty($locationRecord['status'])) {
        return ['outcome' => 'partial'];
    }

    // 🔁 Duplicate
    if (($contact['status'] ?? null) === 'exact_match') {
        return ['outcome' => 'resolved_duplicate'];
    }

    if (($contact['status'] ?? null) === 'possible_match') {
        return ['outcome' => 'resolved_conflict'];
    }

    // ✅ New
    return ['outcome' => 'resolved_new'];
}

#endregion

#region SECTION 10 — Transaction + Insert Engine

function executeInsert(PDO $db, array $parsed, array $location, array $entity, array $locationRecord): array {

    try {

        $db->beginTransaction();

        // =========================================================
        // 1. ENTITY INSERT (IF NEW)
        // =========================================================
        if ($entity['status'] === 'new') {

            // ─────────────────────────────────────────
            // 🛡️ Safe Entity Extraction
            // ─────────────────────────────────────────
            $entityName = trim($parsed['entity']['name'] ?? '');
            $entityType = $parsed['entity']['type'] ?? 'company';

            if ($entityName === '') {
                throw new RuntimeException('Entity name is required for insert.');
            }

            // ─────────────────────────────────────────
            // 🗄️ Insert / Reuse Entity
            // ─────────────────────────────────────────
            $stmt = $db->prepare("
                INSERT INTO tblEntities (
                    entityName,
                    entityType,
                    entityDate
                ) VALUES (
                    :name,
                    :type,
                    UNIX_TIMESTAMP()
                )
                ON DUPLICATE KEY UPDATE entityId = LAST_INSERT_ID(entityId)
            ");

            $stmt->execute([
                'name' => $entityName,
                'type' => $entityType
            ]);

            $entityId = (int)$db->lastInsertId();

        } else {
            $entityId = (int)$entity['entityId'];
        }

        // =========================================================
        // 2. LOCATION INSERT (IF NEW)
        // =========================================================
        if ($locationRecord['status'] === 'new') {

            if (empty($location['placeId'])) {
                throw new RuntimeException('locationPlaceId is required.');
            }

            $stmt = $db->prepare("
                INSERT INTO tblLocations (
                    locationEntityId,
                    locationName,
                    locationPlaceId,
                    locationLatitude,
                    locationLongitude,
                    locationAddress,
                    locationCity,
                    locationState,
                    locationZip,
                    locationParcelNumber,
                    locationJurisdiction,
                    locationCounty,
                    locationCountyFips,
                    locationDate
                ) VALUES (
                    :entityId,
                    :name,
                    :placeId,
                    :lat,
                    :lng,
                    :address,
                    :city,
                    :state,
                    :zip,
                    :parcel,
                    :jurisdiction,
                    :county,
                    :fips,
                    UNIX_TIMESTAMP()
                )
            ");

            $stmt->execute([
                'entityId' => $entityId,
                'name' => $location['name'] ?? (trim($parsed['entity']['name']) . ' - Primary'),
                'placeId' => $location['placeId'],
                'lat' => $location['lat'] ?? null,
                'lng' => $location['lng'] ?? null,
                'address' => $location['address'] ?? null,
                'city' => $location['city'] ?? null,
                'state' => $location['state'] ?? null,
                'zip' => $location['zip'] ?? null,
                'parcel' => $location['parcelNumber'] ?? null,
                'jurisdiction' => $location['jurisdiction'] ?? null,
                'county' => $location['county'] ?? null,
                'fips' => $location['countyFips'] ?? null
            ]);

            $locationId = (int)$db->lastInsertId();

        } else {
            $locationId = (int)$locationRecord['locationId'];
        }

        // =========================================================
        // 3. CONTACT INSERT
        // =========================================================
        $contact = $parsed['contact'];

        if (empty($contact['firstName']) || empty($contact['lastName'])) {
            throw new RuntimeException('Contact name is required.');
        }

        $stmt = $db->prepare("
            INSERT INTO tblContacts (
                contactEntityId,
                contactLocationId,
                contactSalutation,
                contactFirstName,
                contactLastName,
                contactTitle,
                contactPrimaryPhone,
                contactEmail,
                contactDate
            ) VALUES (
                :entityId,
                :locationId,
                :salutation,
                :firstName,
                :lastName,
                :title,
                :phone,
                :email,
                UNIX_TIMESTAMP()
            )
        ");

        $stmt->execute([
            'entityId' => $entityId,
            'locationId' => $locationId,
            'salutation' => $contact['salutation'] ?? null,
            'firstName' => trim((string)$contact['firstName']),
            'lastName' => trim((string)$contact['lastName']),
            'title' => $contact['title'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'email' => $contact['email'] ?? null
        ]);

        $contactId = (int)$db->lastInsertId();

        // =========================================================
        // 4. ACTION LOG
        // =========================================================
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
                :actionTypeId,
                :contactId,
                :origin,
                UNIX_TIMESTAMP(),
                :prompt,
                'Contact created',
                'create_contact',
                1.00,
                :ip,
                :lat,
                :lng,
                :ua
            )
        ");

        $stmt->execute([
            'actionTypeId' => ACTION_TYPE_CREATE_CONTACT,
            'contactId' => $contactId,
            'origin' => ACTION_ORIGIN_USER,
            'prompt' => $_POST['input'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'lat' => $location['lat'] ?? null,
            'lng' => $location['lng'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        $db->commit();

        return [
            'success' => true,
            'entityId' => $entityId,
            'locationId' => $locationId,
            'contactId' => $contactId
        ];

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

#endregion

#region SECTION 11 — Execute Insert (Only if NEW)

$insertResult = null;

if ($outcome['outcome'] === 'resolved_new') {
    $insertResult = executeInsert(
        $db,
        $parsed,
        $location,
        $entity,
        $locationRecord
    );
}

#endregion

#region SECTION 12 — Output / Response

echo json_encode([
    'status' => $outcome['outcome'],
    'entity' => $entity,
    'location' => $locationRecord,
    'contact' => $contactRecord,
    'insert' => $insertResult,
    'resolvedLocation' => $location
]);

#endregion