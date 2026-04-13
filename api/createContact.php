<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — createContact.php
//  Version: 1.4.0
//  Last Updated: 2026-04-12
//  Codex Tier: 2 — ELC Execution Layer
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

if (!defined('ACTION_ORIGIN_USER'))       define('ACTION_ORIGIN_USER', 1);
if (!defined('ACTION_ORIGIN_SYSTEM'))     define('ACTION_ORIGIN_SYSTEM', 2);
if (!defined('ACTION_ORIGIN_AUTOMATION')) define('ACTION_ORIGIN_AUTOMATION', 3);

if (!defined('ACTION_TYPE_PROPOSE'))   define('ACTION_TYPE_PROPOSE', 7);
if (!defined('ACTION_TYPE_ACKNOWLEDGE')) define('ACTION_TYPE_ACKNOWLEDGE', 8);
if (!defined('ACTION_TYPE_ACCEPT'))    define('ACTION_TYPE_ACCEPT', 9);

// Global logging control
$varHasLoggedAction = false;
$varRequestId       = uniqid('req_', true);

#endregion

#region SECTION 1 — Input Handling (Assignment)

$rawRequest = file_get_contents('php://input');
$jsonInput  = json_decode($rawRequest, true);

$input = $jsonInput['input'] ?? $_POST['input'] ?? '';

#endregion

#region SECTION 1A — Input Validation (ELC Gate: Reject + Log)

// 🔒 Hard validation: input must exist
// 📌 Behavior:
//   - Logs ALL rejected user attempts (audit trail)
//   - Uses lightweight logging (type: query)
//   - Does NOT enter resolution pipeline
//   - Exits immediately after response

if (!$input || trim($input) === '') {

    try {
        logAction($db, [
            'type'      => 4,
            'contactId' => 1,
            'prompt'    => '',
            'response'  => json_encode([
                'reason' => 'No input provided',
                'stage'  => 'validation'
            ], JSON_UNESCAPED_UNICODE),
            'intent'    => 'reject',
            'origin'    => ACTION_ORIGIN_USER
        ]);
    } catch (Throwable $e) {
        error_log('REJECT LOG FAILURE: ' . $e->getMessage());
    }

    echo json_encode([
        'status' => 'reject',
        'reason' => 'No input provided'
    ]);
    exit;
}

#endregion

#region SECTION 2–5 — Core Processing & Validation Gates

$parsed = parseContact($input);

// Address Enrichment
$fullAddress = trim(
    ($parsed['location']['address'] ?? '') . ' ' .
    ($parsed['location']['city'] ?? '') . ' ' .
    ($parsed['location']['state'] ?? '')
);

$validation = validateAddressCensus($fullAddress);
if (!empty($validation['valid']) && !empty($validation['normalized'])) {
    $parsed['location']['address']     = $validation['normalized']['address'];
    $parsed['location']['censusValid'] = true;
} else {
    $parsed['location']['censusValid'] = false;
}

$location = resolveLocation($parsed['location'] ?? []);

$contact = $parsed['contact'] ?? [];

// Validation
if (!isset($contact['salutation']) || trim($contact['salutation']) === '') {
    echo json_encode(['status' => 'reject', 'reason' => 'Salutation (Mr/Ms) required']);
    exit;
}
if (empty($contact['title'])) {
    echo json_encode(['status' => 'reject', 'reason' => 'Contact title required']);
    exit;
}
if (empty($contact['email'])) {
    echo json_encode(['status' => 'reject', 'reason' => 'Email required']);
    exit;
}
if (empty($contact['phone'])) {
    echo json_encode(['status' => 'reject', 'reason' => 'Primary phone required']);
    exit;
}
if (empty($parsed['entity']) || empty($parsed['contact'])) {
    echo json_encode(['status' => 'reject', 'reason' => 'Missing entity or contact']);
    exit;
}
if (empty($location['placeId'])) {
    echo json_encode(['status' => 'partial', 'reason' => 'Location not validated', 'location' => $location]);
    exit;
}
if ($location['state'] === 'AZ' &&
    strpos(strtoupper($location['county'] ?? ''), 'MARICOPA') !== false &&
    empty($location['parcelNumber'])) {
    echo json_encode(['status' => 'reject', 'reason' => 'Parcel required for Maricopa County', 'location' => $location]);
    exit;
}

#endregion

#region HELPER FUNCTIONS (Defined BEFORE use)

function resolveEntity(PDO $db, string $entityName): array {
    $entityName = trim($entityName);
    if ($entityName === '') {
        return ['status' => 'new', 'entityId' => null, 'entityName' => $entityName];
    }

    $stmt = $db->prepare("SELECT entityId, entityName FROM tblEntities WHERE LOWER(entityName) = :name LIMIT 1");
    $stmt->execute(['name' => strtolower($entityName)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return ['status' => 'existing', 'entityId' => (int)$row['entityId'], 'entityName' => $row['entityName']];
    }

    return ['status' => 'new', 'entityId' => null, 'entityName' => $entityName];
}

function resolveLocationRecord(PDO $db, int $entityId, string $placeId): array {
    $stmt = $db->prepare("
        SELECT locationId 
        FROM tblLocations 
        WHERE locationEntityId = :entityId AND locationPlaceId = :placeId 
        LIMIT 1
    ");
    $stmt->execute(['entityId' => $entityId, 'placeId' => $placeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row 
        ? ['status' => 'existing', 'locationId' => (int)$row['locationId']]
        : ['status' => 'new', 'locationId' => null];
}

function resolveContact(PDO $db, int $entityId, int $locationId, array $contact): array {
    $firstName = trim((string)($contact['firstName'] ?? ''));
    $lastName  = trim((string)($contact['lastName'] ?? ''));
    $email     = strtolower(trim((string)($contact['email'] ?? '')));

    // Primary: Email match
    if ($email !== '') {
        $stmt = $db->prepare("
            SELECT contactId FROM tblContacts 
            WHERE contactEntityId = :entityId AND contactEmail = :email LIMIT 1
        ");
        $stmt->execute(['entityId' => $entityId, 'email' => $email]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'exact_match', 'contactId' => (int)$row['contactId']];
        }
    }

    // Secondary: Name + Location
    if ($firstName !== '' && $lastName !== '') {
        $stmt = $db->prepare("
            SELECT contactId FROM tblContacts 
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
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'possible_match', 'contactId' => (int)$row['contactId']];
        }
    }

    return ['status' => 'new', 'contactId' => null];
}

function buildResolutionOutcome(array $entity, array $locationRecord, array $contact): array {
    if (empty($entity['status'])) {
        return ['outcome' => 'reject'];
    }
    if (empty($locationRecord['status'])) {
        return ['outcome' => 'partial'];
    }

    if (($contact['status'] ?? null) === 'exact_match') {
        return ['outcome' => 'resolved_duplicate'];
    }
    if (($contact['status'] ?? null) === 'possible_match') {
        return ['outcome' => 'resolved_conflict'];
    }

    return ['outcome' => 'resolved_new'];
}

function evaluateScenario(PDO $db, array $entity, array $location, array $contact): array {
    $decision = [
        "status" => "ok",
        "scenario" => "safe",
        "message" => "",
        "requiresAction" => false,
        "options" => [],
        "data" => []
    ];

    // Invalid Entity
    if (!empty($entity['isInvalid']) && $entity['isInvalid'] == 1) {
        return [
            "status" => "reject",
            "scenario" => "invalid_entity",
            "message" => "Entity is marked invalid.",
            "data" => ["entityId" => $entity['entityId'] ?? null]
        ];
    }

    // Location Ownership Conflict
    if (!empty($location['placeId'])) {
        $stmt = $db->prepare("SELECT locationEntityId FROM tblLocations WHERE locationPlaceId = :placeId LIMIT 1");
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

    // Entity Type Inference (for new entities)
    if (($entity['status'] ?? null) === 'new' && empty($entity['entityType']) && !empty($entity['entityName'])) {
        $inferredType = inferEntityTypeAI($entity['entityName']);
        $decision['scenario'] = "entity_type_inferred";
        $decision['message'] = "Entity type inferred by AI.";
        $decision['data']['entityType'] = $inferredType;
        $decision['data']['entityTypeSource'] = "ai";
    }

    return $decision;
}

function inferEntityTypeAI(string $entityName): string {
    static $cache = [];
    $key = strtolower(trim($entityName));
    if (isset($cache[$key])) return $cache[$key];

    $payload = ["prompt" => "Return ONLY one word: company, customer, vendor, or jurisdiction.\nEntity: " . $entityName];

    $ch = curl_init('https://skyelighting.com/skyesoft/api/askOpenAI.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 3
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        $cache[$key] = 'customer';
        return 'customer';
    }

    $result = json_decode($response, true);
    $type = strtolower(trim($result['response'] ?? 'customer'));
    $allowed = ['company', 'customer', 'vendor', 'jurisdiction'];
    $finalType = in_array($type, $allowed) ? $type : 'customer';

    $cache[$key] = $finalType;
    return $finalType;
}

function executeInsert(PDO $db, array $parsed, array $location, array $entity, array $locationRecord, string $input): array {
    try {
        $db->beginTransaction();

        // ... [All your existing normalization and INSERT logic stays exactly the same] ...

        // ENTITY INSERT
        if ($entity['status'] === 'new') {
            // your code ...
        } else {
            $entityId = (int)$entity['entityId'];
        }

        // LOCATION INSERT
        if ($locationRecord['status'] === 'new') {
            // your code ...
        } else {
            $locationId = (int)$locationRecord['locationId'];
        }

        // CONTACT DUPLICATE CHECK + INSERT
        // ... your existing defensive checks and INSERT ...

        $contactId = (int)$db->lastInsertId();

        // IMPORTANT: NO logAction() here anymore

        $db->commit();

        return [
            'success' => true,
            'entityId' => $entityId,
            'locationId' => $locationId,
            'contactId' => $contactId
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

#endregion

#region MAIN FLOW — Resolution & Insert

$entity = resolveEntity($db, $parsed['entity']['name'] ?? '');

$decision = evaluateScenario($db, $entity, $location, $parsed['contact'] ?? []);

if ($decision['status'] === 'reject' || $decision['status'] === 'conflict') {
    echo json_encode($decision);
    exit;
}

if (!empty($decision['data']['entityType'])) {
    $parsed['entity']['entityType'] = $decision['data']['entityType'];
    $entity['entityType'] = $decision['data']['entityType'];
}

if ($entity['status'] === 'existing') {
    $locationRecord = resolveLocationRecord($db, $entity['entityId'], $location['placeId']);
} else {
    $locationRecord = ['status' => 'new', 'locationId' => null];
}

if ($entity['status'] === 'existing' && $locationRecord['status'] === 'existing') {
    $contactRecord = resolveContact($db, $entity['entityId'], $locationRecord['locationId'], $parsed['contact']);
} else {
    $contactRecord = ['status' => 'new', 'contactId' => null];
}

$outcome = buildResolutionOutcome($entity, $locationRecord, $contactRecord);

$insertResult = null;
if ($outcome['outcome'] === 'resolved_new') {
    $insertResult = executeInsert($db, $parsed, $location, $entity, $locationRecord, $input);

    if ($insertResult['success'] === false && !empty($insertResult['contactId'])) {
        $outcome = [
            'outcome' => 'resolved_duplicate',
            'entity'  => $entity,
            'location'=> $locationRecord,
            'contact' => ['status' => 'duplicate_email', 'contactId' => (int)$insertResult['contactId']]
        ];
        $insertResult = null;
    }
}

#endregion

#region SECTION 17 — Unified Action Logging (Single Source of Truth)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['contactId'] ?? 1;

if (!$varHasLoggedAction && !empty($currentUserId)) {

    $actionType      = ACTION_TYPE_PROPOSE;
    $intent          = 'system_event';
    $reason          = 'unknown';
    $targetContactId = null;

    if (($outcome['outcome'] ?? '') === 'resolved_new') {
        $actionType      = ACTION_TYPE_ACCEPT;
        $intent          = 'create_contact';
        $reason          = 'new_contact_created';
        $targetContactId = $insertResult['contactId'] ?? null;

    } elseif (($outcome['outcome'] ?? '') === 'resolved_duplicate') {
        $actionType      = ACTION_TYPE_PROPOSE;
        $intent          = 'duplicate_attempt';
        $reason          = 'duplicate_detected';
        $targetContactId =
            $outcome['contact']['contactId']
            ?? $contactRecord['contactId']
            ?? ($insertResult['contactId'] ?? null);

    } elseif (!empty($insertResult) && $insertResult['success'] === false) {
        $actionType      = ACTION_TYPE_PROPOSE;
        $intent          = 'contact_insert_failed';
        $reason          = 'insert_failed';
        $targetContactId = $insertResult['contactId'] ?? null;
    }

    $responsePayload = [
        'requestId'   => $varRequestId,
        'input'       => $input,
        'outcome'     => $outcome['outcome'] ?? null,
        'entityId'    => $entity['entityId'] ?? null,
        'locationId'  => $locationRecord['locationId'] ?? null,
        'contactId'   => $targetContactId,
        'reason'      => $reason
    ];

    try {
        logAction($db, [
            'type'      => $actionType,
            'contactId' => (int)$currentUserId,   // Actor = logged-in user
            'prompt'    => $input,
            'response'  => json_encode($responsePayload, JSON_UNESCAPED_UNICODE),
            'intent'    => $intent,
            'lat'       => $location['lat'] ?? null,
            'lng'       => $location['lng'] ?? null,
            'origin'    => ACTION_ORIGIN_USER
        ]);

        $varHasLoggedAction = true;
    } catch (Throwable $e) {
        error_log('UNIFIED LOG FAILURE: ' . $e->getMessage());
    }
}

#endregion

#region SECTION 18 — Final Response

echo json_encode([
    'status'           => $outcome['outcome'],
    'entity'           => $entity,
    'location'         => $locationRecord,
    'contact'          => $outcome['contact'] ?? $contactRecord,
    'insert'           => $insertResult,
    'resolvedLocation' => $location,
    'scenario'         => $decision ?? null,
    'requestId'        => $varRequestId
]);

#endregion