<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — createContact.php
//  Version: 1.3.0
//  Last Updated: 2026-04-12
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
require_once __DIR__ . '/utils/validateAddressCensus.php';
require_once __DIR__ . '/utils/actionLogger.php';

$db = getPDO();

if (!$db instanceof PDO) {
    throw new RuntimeException('Database connection failed: invalid PDO instance.');
}

if (!defined('ACTION_ORIGIN_USER')) {
    define('ACTION_ORIGIN_USER', 1);
}
if (!defined('ACTION_ORIGIN_SYSTEM')) {
    define('ACTION_ORIGIN_SYSTEM', 2);
}
if (!defined('ACTION_ORIGIN_AUTOMATION')) {
    define('ACTION_ORIGIN_AUTOMATION', 3);
}
if (!defined('ACTION_TYPE_CREATE_CONTACT')) {
    define('ACTION_TYPE_CREATE_CONTACT', 9);
}
if (!defined('ACTION_TYPE_DUPLICATE_ATTEMPT')) {
    define('ACTION_TYPE_DUPLICATE_ATTEMPT', 7);
}

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

#endregion

#region SECTION 2A — Address Enrichment (CENSUS OPTIONAL)

// Build full address string
$fullAddress = trim(
    ($parsed['location']['address'] ?? '') . ' ' .
    ($parsed['location']['city'] ?? '') . ' ' .
    ($parsed['location']['state'] ?? '')
);

// Execute Census validation
$validation = validateAddressCensus($fullAddress);

// 🔍 Enrichment only — never block execution
if (!empty($validation['valid']) && !empty($validation['normalized'])) {

    // Normalize address ONLY if valid
    $parsed['location']['address'] = $validation['normalized']['address'];
    $parsed['location']['censusValid'] = true;

} else {

    // Keep original parsed address
    $parsed['location']['censusValid'] = false;
}

#endregion

#region SECTION 2B — Location Resolution

$location = resolveLocation($parsed['location'] ?? []);

#endregion

#region SECTION 3 — Validation Gates

// 🔥 REQUIRED CONTACT FIELDS
$contact = $parsed['contact'] ?? [];

if (!isset($contact['salutation']) || trim($contact['salutation']) === '') {
    echo json_encode([
        'status' => 'reject',
        'reason' => 'Salutation (Mr/Ms) required'
    ]);
    exit;
}

if (empty($contact['title'])) {
    echo json_encode([
        'status' => 'reject',
        'reason' => 'Contact title required'
    ]);
    exit;
}

if (empty($contact['email'])) {
    echo json_encode([
        'status' => 'reject',
        'reason' => 'Email required'
    ]);
    exit;
}

if (empty($contact['phone'])) {
    echo json_encode([
        'status' => 'reject',
        'reason' => 'Primary phone required'
    ]);
    exit;
}

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

// 🔥 Maricopa Parcel Enforcement
if (
    $location['state'] === 'AZ' &&
    strpos(strtoupper($location['county'] ?? ''), 'MARICOPA') !== false &&
    empty($location['parcelNumber'])
) {
    echo json_encode([
        'status' => 'reject',
        'reason' => 'Parcel required for Maricopa County',
        'location' => $location
    ]);
    exit;
}

#endregion

#region SECTION 4 — Entity Resolution

$entity = resolveEntity($db, $parsed['entity']['name'] ?? '');

#endregion

#region SECTION 5 — Scenario Engine (Decision Layer)

$decision = evaluateScenario(
    $db,
    $entity,
    $location,
    $parsed['contact'] ?? []
);

// 🚫 HARD STOP
if ($decision['status'] === 'reject') {
    echo json_encode($decision);
    exit;
}

// ⚠️ CONFLICT (UI / next step later)
if ($decision['status'] === 'conflict') {
    echo json_encode($decision);
    exit;
}

// 🔥 APPLY DECISIONS HERE (CORRECT LOCATION)
if (!empty($decision['data']['entityType'])) {
    $parsed['entity']['entityType'] = $decision['data']['entityType'];
    $entity['entityType'] = $decision['data']['entityType'];
}


#endregion

#region SECTION 6 — Location + Contact Record Resolution

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

#region SECTION 7 — Entity Resolution Function

function resolveEntity(PDO $db, string $entityName): array {

    // #region Normalize Input
    $entityName = trim($entityName);

    if ($entityName === '') {
        return [
            'status' => 'new',
            'entityId' => null,
            'entityName' => $entityName
        ];
    }

    // Normalize for comparison (Phase 2 foundation)
    $normalizedName = strtolower($entityName);
    // #endregion

    // #region Exact Match (Case-Insensitive)
    $stmt = $db->prepare("
        SELECT entityId, entityName
        FROM tblEntities
        WHERE LOWER(entityName) = :name
        LIMIT 1
    ");

    $stmt->execute(['name' => $normalizedName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'status' => 'existing',
            'entityId' => (int)$row['entityId'],
            'entityName' => $row['entityName']
        ];
    }
    // #endregion

    // #region Future Hook — Possible Match (Phase 2)
    /*
    Example (NOT ACTIVE YET):

    SELECT entityId, entityName
    FROM tblEntities
    WHERE LOWER(entityName) LIKE :partial
    */

    // #endregion

    // #region New Entity
    return [
        'status' => 'new',
        'entityId' => null,
        'entityName' => $entityName
    ];
    // #endregion
}

#endregion

#region SECTION 8 — Location Resolution (DB Layer)

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

#region SECTION 9 — Contact Duplicate Detection

function resolveContact(PDO $db, int $entityId, int $locationId, array $contact): array {

    $firstName = trim((string)($contact['firstName'] ?? ''));
    $lastName  = trim((string)($contact['lastName'] ?? ''));
    $email     = strtolower(trim((string)($contact['email'] ?? '')));

    // 🔥 PRIMARY MATCH — EMAIL (Authoritative Identity)
    if ($email !== '') {

        $stmt = $db->prepare("
            SELECT contactId
            FROM tblContacts
            WHERE contactEntityId = :entityId
            AND contactEmail = :email
            LIMIT 1
        ");

        $stmt->execute([
            'entityId' => $entityId,
            'email' => $email
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status' => 'exact_match',
                'contactId' => (int)$row['contactId']
            ];
        }
    }

    // ⚠️ SECONDARY MATCH — NAME + LOCATION (Human Duplicate Detection)
    if ($firstName !== '' && $lastName !== '') {

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

#region SECTION 10 — Resolution Fork Engine

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

#region SECTION 11 — Scenario Engine — Step 1

function evaluateScenario(PDO $db, array $entity, array $location, array $contact): array {

    // #region Default Decision (Safe)
    $decision = [
        "status" => "ok",
        "scenario" => "safe",
        "message" => "",
        "requiresAction" => false,
        "options" => [],
        "data" => []
    ];
    // #endregion


    // #region Scenario 1 — Invalid Entity (Hard Stop)
    if (!empty($entity['isInvalid']) && $entity['isInvalid'] == 1) {
        return [
            "status" => "reject",
            "scenario" => "invalid_entity",
            "message" => "Entity is marked invalid.",
            "requiresAction" => false,
            "options" => [],
            "data" => [
                "entityId" => $entity['entityId'] ?? null
            ]
        ];
    }
    // #endregion


    // #region Scenario 2 — Location Ownership Conflict
    if (!empty($location['placeId'])) {

        $stmt = $db->prepare("
            SELECT locationEntityId
            FROM tblLocations
            WHERE locationPlaceId = :placeId
            LIMIT 1
        ");

        $stmt->execute(['placeId' => $location['placeId']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (string)$row['locationEntityId'] !== (string)($entity['entityId'] ?? null)) {

            return [
                "status" => "conflict",
                "scenario" => "ownership_change",
                "message" => "Location belongs to another entity.",
                "requiresAction" => true,
                "options" => ["reassign", "cancel"],
                "data" => [
                    "existingEntityId" => $row['locationEntityId'],
                    "incomingEntityId" => $entity['entityId'] ?? null,
                    "placeId" => $location['placeId']
                ]
            ];
        }
    }
    // #endregion


    // #region Scenario 3 — Entity Type Inference (AI) — Only for New Entities
    $entityName = $entity['entityName'] ?? null;

    if (
        ($entity['status'] ?? null) === 'new' &&
        empty($entity['entityType']) &&
        !empty($entityName)
    ) {
        $inferredType = inferEntityTypeAI($entityName);

        if ($decision['scenario'] === 'safe') {
            $decision['scenario'] = "entity_type_inferred";
        }

        if ($decision['message'] === "") {
            $decision['message'] = "Entity type inferred by AI.";
        }

        $decision['data'] = array_merge(
            $decision['data'],
            [
                "entityType" => $inferredType,
                "entityTypeSource" => "ai"
            ]
        );
    }
    // #endregion


    // #region Default Return (Safe)
    return $decision;
    // #endregion
}

#endregion

#region SECTION 11A — AI Entity Type Inference (Production Hardened)

function inferEntityTypeAI(string $entityName): string {

    static $cache = [];

    $key = strtolower(trim($entityName));

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $payload = [
        "prompt" => "Return ONLY one word: company, customer, vendor, or jurisdiction.\nEntity: " . $entityName
    ];

    $ch = curl_init('https://skyelighting.com/skyesoft/api/askOpenAI.php');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 3
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        $cache[$key] = 'customer';
        return 'customer';
    }

    $result = json_decode($response, true);

    if (!is_array($result)) {
        $cache[$key] = 'customer';
        return 'customer';
    }

    $type = strtolower(trim($result['response'] ?? 'customer'));

    $allowed = ['company', 'customer', 'vendor', 'jurisdiction'];

    $finalType = in_array($type, $allowed) ? $type : 'customer';

    $cache[$key] = $finalType;
    return $finalType;
}

#endregion

#region SECTION 12 — Transaction + Insert Engine (Phase 4: ELC Execution)

function executeInsert(PDO $db, array $parsed, array $location, array $entity, array $locationRecord, string $input): array {

    try {

        $db->beginTransaction();

        #region Normalize Inputs

        $varEntityName = trim((string)($parsed['entity']['name'] ?? ''));
        $varEntityType = $parsed['entity']['entityType'] ?? 'customer';

        $contact = $parsed['contact'] ?? [];

        $varSalutation = isset($contact['salutation'])
            ? trim($contact['salutation'])
            : null;
        $varTitle      = trim((string)($contact['title'] ?? ''));
        $varPhone      = preg_replace('/\D/', '', (string)($contact['phone'] ?? ''));

        // Format phone
        if (strlen($varPhone) === 10) {
            $varPhone =
                substr($varPhone, 0, 3) . '-' .
                substr($varPhone, 3, 3) . '-' .
                substr($varPhone, 6);
        }

        $varFirstName = trim((string)($contact['firstName'] ?? ''));
        $varLastName  = trim((string)($contact['lastName'] ?? ''));
        $varEmail     = strtolower(trim((string)($contact['email'] ?? '')));

        if ($varSalutation === null) {
            throw new RuntimeException('ELC FAIL: Salutation required.');
        }

        if ($varTitle === '') {
            throw new RuntimeException('ELC FAIL: Title required.');
        }

        if ($varPhone === '' || strlen($varPhone) < 10) {
            throw new RuntimeException('ELC FAIL: Valid phone required.');
        }

        if ($varEmail === '') {
            throw new RuntimeException('ELC FAIL: Email required.');
        }

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

            $varEntityState = $location['state'] ?? null;

            $stmt = $db->prepare("
                INSERT INTO tblEntities (
                    entityName,
                    entityType,
                    entityState,
                    entityDate
                ) VALUES (
                    :name,
                    :type,
                    :state,
                    UNIX_TIMESTAMP()
                )
            ");

            $stmt->execute([
                'name'  => $varEntityName,
                'type'  => $varEntityType,
                'state' => $varEntityState
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

            // --- Name (standard: entity name unless explicitly provided)
            $locationName = trim((string)($location['name'] ?? ''));
            if ($locationName === '') {
                $locationName = trim((string)$varEntityName);
            }

            // --- Address (standard: street only, strip city/state)
            $locationAddress = trim((string)($location['address'] ?? ''));

            if ($locationAddress !== '' && strpos($locationAddress, ',') !== false) {
                $locationAddress = trim(substr($locationAddress, 0, strpos($locationAddress, ',')));
            }

            // --- County (standard: remove "County")
            $locationCounty = trim((string)($location['county'] ?? ''));
            $locationCounty = preg_replace('/\s+County$/i', '', $locationCounty);

            // --- FIPS (keep as-is for now, consistent going forward)
            $locationFips = $location['countyFips'] ?? null;

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

            // Statement
            $stmt->execute([
                'entityId'      => $entityId,
                'name'          => $locationName,
                'placeId'       => $location['placeId'],
                'lat'           => $location['lat'] ?? null,
                'lng'           => $location['lng'] ?? null,
                'address'       => $locationAddress !== '' ? $locationAddress : null,
                'city'          => $location['city'] ?? null,
                'state'         => $location['state'] ?? null,
                'zip'           => $location['zip'] ?? null,
                'parcel'        => $location['parcelNumber'] ?? null,
                'jurisdiction'  => $location['jurisdiction'] ?? null,
                'county'        => $locationCounty !== '' ? $locationCounty : null,
                'fips'          => $locationFips
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
            'entityId'   => $entityId,
            'locationId' => $locationId,
            'salutation' => $varSalutation,
            'firstName' => trim($varFirstName),
            'lastName'  => trim($varLastName),
            'title'      => $varTitle,
            'phone'      => $varPhone,
            'email'      => $varEmail
        ]);

        $contactId = (int)$db->lastInsertId();

        if ($contactId <= 0) {
            throw new RuntimeException('ELC FAIL: Contact insert failed.');
        }

        #endregion

        #region ACTION LOG — Create Contact (Centralized)

        $payload = json_encode([
            'input' => $input,
            'entityId' => $entityId,
            'locationId' => $locationId,
            'contactId' => $contactId,
            'placeId' => $location['placeId'] ?? null,
            'jurisdiction' => $location['jurisdiction'] ?? null,
            'parcelNumber' => $location['parcelNumber'] ?? null
        ], JSON_UNESCAPED_UNICODE);

        logAction($db, [
            'type'      => ACTION_TYPE_CREATE_CONTACT,
            'contactId' => $contactId,
            'prompt'    => $input,
            'response'  => $payload,
            'intent'    => 'create_contact',
            'lat'       => $location['lat'] ?? null,
            'lng'       => $location['lng'] ?? null,
            'origin'    => ACTION_ORIGIN_USER   // 🔥 THIS IS THE FIX
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

#region SECTION 13 — Action Log (Duplicate Attempt)

if ($outcome['outcome'] === 'resolved_duplicate') {

    $payload = json_encode([
        'input' => $input,
        'entityId' => $entity['entityId'] ?? null,
        'locationId' => $locationRecord['locationId'] ?? null,
        'contactId' => $contactRecord['contactId'] ?? null,
        'placeId' => $location['placeId'] ?? null,
        'jurisdiction' => $location['jurisdiction'] ?? null,
        'parcelNumber' => $location['parcelNumber'] ?? null,
        'reason' => 'Duplicate contact submission blocked'
    ], JSON_UNESCAPED_UNICODE);

    logAction($db, [
        'type'      => ACTION_TYPE_DUPLICATE_ATTEMPT,
        'contactId' => $contactRecord['contactId'] ?? null,
        'prompt'    => $input,
        'response'  => $payload,
        'intent'    => 'duplicate_attempt',
        'lat'       => $location['lat'] ?? null,
        'lng'       => $location['lng'] ?? null,
        'origin'    => ACTION_ORIGIN_USER   // 🔥 REQUIRED
    ]);
}

#endregion

#region SECTION 14 — Execute Insert (Only if NEW)

$insertResult = null;

if ($outcome['outcome'] === 'resolved_new') {
    $insertResult = executeInsert(
        $db,
        $parsed,
        $location,
        $entity,
        $locationRecord,
        $input
    );
}

#endregion

#region SECTION 15 — Output / Response

echo json_encode([
    'status' => $outcome['outcome'],
    'entity' => $entity,
    'location' => $locationRecord,
    'contact' => $contactRecord,
    'insert' => $insertResult,
    'resolvedLocation' => $location,
    'scenario' => $decision ?? null
]);

#endregion