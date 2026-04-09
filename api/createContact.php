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

require_once __DIR__ . '/utils/parseContactCore.php';
require_once __DIR__ . '/resolveLocation.php';
require_once __DIR__ . '/dbConnect.php';

const ACTION_TYPE_CREATE_CONTACT = 1;

const ACTION_ORIGIN_USER       = 1;
const ACTION_ORIGIN_SYSTEM     = 2;
const ACTION_ORIGIN_AUTOMATION = 3;

#endregion

#region SECTION 1 — Input Handling (JSON First)

$rawRequest = file_get_contents('php://input');
$jsonInput  = json_decode($rawRequest, true);

// Prefer JSON input
$input = $jsonInput['input'] ?? $_POST['input'] ?? '';

if (!$input || trim($input) === '') {
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

#region SECTION 10 — Transaction + Insert Engine (Phase 4: ELC Execution)

function executeInsert(PDO $db, array $parsed, array $location, array $entity, array $locationRecord): array {

    try {

        $db->beginTransaction();

        #region Normalize Inputs

        $varEntityName = trim((string)($parsed['entity']['name'] ?? ''));
        $varEntityType = $parsed['entity']['type'] ?? 'company';

        $contact = $parsed['contact'] ?? [];

        $varFirstName = trim((string)($contact['firstName'] ?? ''));
        $varLastName  = trim((string)($contact['lastName'] ?? ''));
        $varEmail     = strtolower(trim((string)($contact['email'] ?? '')));

        if ($varEntityName === '') {
            throw new RuntimeException('ELC FAIL: Entity name required.');
        }

        if ($varFirstName === '' || $varLastName === '') {
            throw new RuntimeException('ELC FAIL: Contact first and last name required.');
        }

        if (empty($location['placeId'])) {
            throw new RuntimeException('ELC FAIL: locationPlaceId required.');
        }

        #endregion

        #region ENTITY — Insert or Reuse

        if ($entity['status'] === 'new') {

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
            ");

            $stmt->execute([
                'name' => $varEntityName,
                'type' => $varEntityType
            ]);

            $entityId = (int)$db->lastInsertId();

            if ($entityId <= 0) {
                throw new RuntimeException('ELC FAIL: Entity insert failed.');
            }

        } else {
            $entityId = (int)$entity['entityId'];
        }

        #endregion

        #region LOCATION — Insert or Reuse

        if ($locationRecord['status'] === 'new') {

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
                'name' => $location['name'] ?? ($varEntityName . ' - Primary'),
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

            if ($locationId <= 0) {
                throw new RuntimeException('ELC FAIL: Location insert failed.');
            }

        } else {
            $locationId = (int)$locationRecord['locationId'];
        }

        #endregion

        #region CONTACT — Defensive Duplicate Enforcement

        // 🔍 ELC Identity Check
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
            'firstName' => $varFirstName,
            'lastName' => $varLastName
        ]);

        if ($stmt->fetch()) {
            throw new RuntimeException('ELC FAIL: Duplicate contact exists.');
        }

        // 🔍 Email Uniqueness (Per Entity)
        if ($varEmail !== '') {

            $stmt = $db->prepare("
                SELECT contactId
                FROM tblContacts
                WHERE contactEntityId = :entityId
                AND contactEmail = :email
                LIMIT 1
            ");

            $stmt->execute([
                'entityId' => $entityId,
                'email' => $varEmail
            ]);

            if ($stmt->fetch()) {
                throw new RuntimeException('ELC FAIL: Email already exists for entity.');
            }
        }

        #endregion

        #region CONTACT — Insert

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
            'firstName' => $varFirstName,
            'lastName' => $varLastName,
            'title' => $contact['title'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'email' => $varEmail !== '' ? $varEmail : null
        ]);

        $contactId = (int)$db->lastInsertId();

        if ($contactId <= 0) {
            throw new RuntimeException('ELC FAIL: Contact insert failed.');
        }

        #endregion

        #region ACTION LOG (Enhanced Payload)

        $payload = json_encode([
            'input' => $_POST['input'] ?? '',
            'entityId' => $entityId,
            'locationId' => $locationId,
            'contactId' => $contactId,
            'placeId' => $location['placeId'],
            'jurisdiction' => $location['jurisdiction'] ?? null,
            'parcelNumber' => $location['parcelNumber'] ?? null
        ], JSON_UNESCAPED_UNICODE);

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
                'create_contact',
                1.00,
                :ip,
                :lat,
                :lng,
                :ua
            )
        ");

        $stmt->execute([
            'type' => ACTION_TYPE_CREATE_CONTACT,
            'contactId' => $contactId,
            'origin' => ACTION_ORIGIN_USER,
            'prompt' => $_POST['input'] ?? '',
            'response' => $payload,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'lat' => $location['lat'] ?? null,
            'lng' => $location['lng'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        #endregion

        #region COMMIT

        $db->commit();

        return [
            'success' => true,
            'entityId' => $entityId,
            'locationId' => $locationId,
            'contactId' => $contactId
        ];

        #endregion

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