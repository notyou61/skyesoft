<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — createContact.php
//  Version: 1.4.4
//  Last Updated: 2026-04-13
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

// SAFE INITIALIZATION — Prevents undefined variable issues in any path
$outcome        = null;
$entity         = null;
$locationRecord = null;
$contactRecord  = null;
$insertResult   = null;
$decision       = null;
$location       = null;
$parsed         = null;
$input          = '';

#endregion

#region MAIN EXECUTION WRAPPER (Guarantees logging on every request)

try {

    #region SECTION 1 — Input Handling + Validation (ELC Entry Gate)

    $rawRequest = file_get_contents('php://input');
    $jsonInput  = json_decode($rawRequest, true);

    // Normalize input
    $input = $jsonInput['input'] ?? $_POST['input'] ?? '';

    // Hard validation: input must exist
    if (!$input || trim($input) === '') {
        $outcome = [
            'outcome' => 'reject',
            'reason'  => 'No input provided'
        ];
        goto FINAL_LOG;
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

    // Validation Gates
    if (!isset($contact['salutation']) || trim($contact['salutation']) === '') {
        $outcome = ['outcome' => 'reject', 'reason' => 'Salutation (Mr/Ms) required'];
        goto FINAL_LOG;
    }
    if (empty($contact['title'])) {
        $outcome = ['outcome' => 'reject', 'reason' => 'Contact title required'];
        goto FINAL_LOG;
    }
    if (empty($contact['email'])) {
        $outcome = ['outcome' => 'reject', 'reason' => 'Email required'];
        goto FINAL_LOG;
    }
    if (empty($contact['phone'])) {
        $outcome = ['outcome' => 'reject', 'reason' => 'Primary phone required'];
        goto FINAL_LOG;
    }
    if (empty($parsed['entity']) || empty($parsed['contact'])) {
        $outcome = ['outcome' => 'reject', 'reason' => 'Missing entity or contact'];
        goto FINAL_LOG;
    }
    if (empty($location['placeId'])) {
        $outcome = ['outcome' => 'partial', 'reason' => 'Location not validated', 'location' => $location];
        goto FINAL_LOG;
    }
    if ($location['state'] === 'AZ' &&
        strpos(strtoupper($location['county'] ?? ''), 'MARICOPA') !== false &&
        empty($location['parcelNumber'])) {
        $outcome = ['outcome' => 'reject', 'reason' => 'Parcel required for Maricopa County', 'location' => $location];
        goto FINAL_LOG;
    }

    #endregion

    #region HELPER FUNCTIONS

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
        // curl_close removed - deprecated and unnecessary

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

            // ENTITY INSERT
            if ($entity['status'] === 'new') {
                $stmt = $db->prepare("
                    INSERT INTO tblEntities (entityName, entityType, createdAt)
                    VALUES (:name, :type, NOW())
                ");
                $stmt->execute([
                    'name' => $entity['entityName'],
                    'type' => $entity['entityType'] ?? 'customer'
                ]);
                $entityId = (int)$db->lastInsertId();
            } else {
                $entityId = (int)$entity['entityId'];
            }

            // LOCATION INSERT
            if ($locationRecord['status'] === 'new') {
                $stmt = $db->prepare("
                    INSERT INTO tblLocations (
                        locationEntityId, locationPlaceId, locationAddress, locationCity,
                        locationState, locationCounty, locationParcelNumber, lat, lng, createdAt
                    ) VALUES (
                        :entityId, :placeId, :address, :city, :state, :county, :parcel, :lat, :lng, NOW()
                    )
                ");
                $stmt->execute([
                    'entityId'   => $entityId,
                    'placeId'    => $location['placeId'],
                    'address'    => $location['address'] ?? '',
                    'city'       => $location['city'] ?? '',
                    'state'      => $location['state'] ?? '',
                    'county'     => $location['county'] ?? '',
                    'parcel'     => $location['parcelNumber'] ?? null,
                    'lat'        => $location['lat'] ?? null,
                    'lng'        => $location['lng'] ?? null
                ]);
                $locationId = (int)$db->lastInsertId();
            } else {
                $locationId = (int)$locationRecord['locationId'];
            }

            // CONTACT INSERT
            $stmt = $db->prepare("
                INSERT INTO tblContacts (
                    contactEntityId, contactLocationId, contactSalutation, contactTitle,
                    contactFirstName, contactLastName, contactEmail, contactPhone, createdAt
                ) VALUES (
                    :entityId, :locationId, :salutation, :title, :firstName, :lastName, :email, :phone, NOW()
                )
            ");
            $stmt->execute([
                'entityId'    => $entityId,
                'locationId'  => $locationId,
                'salutation'  => $parsed['contact']['salutation'],
                'title'       => $parsed['contact']['title'],
                'firstName'   => $parsed['contact']['firstName'] ?? '',
                'lastName'    => $parsed['contact']['lastName'] ?? '',
                'email'       => strtolower($parsed['contact']['email']),
                'phone'       => $parsed['contact']['phone']
            ]);

            $contactId = (int)$db->lastInsertId();

            $db->commit();

            return [
                'success'   => true,
                'entityId'  => $entityId,
                'locationId'=> $locationId,
                'contactId' => $contactId
            ];

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Insert failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    #endregion

    #region MAIN FLOW — Resolution & Insert

    // ─────────────────────────────────────────
    // Resolve Entity
    // ─────────────────────────────────────────
    $entity = resolveEntity($db, $parsed['entity']['name'] ?? '');

    // ─────────────────────────────────────────
    // Scenario Evaluation (EARLY EXIT ONLY FOR HARD STOP)
    // ─────────────────────────────────────────
    $decision = evaluateScenario($db, $entity, $location, $parsed['contact'] ?? []);

    if ($decision['status'] === 'reject' || $decision['status'] === 'conflict') {

        $outcome = [
            'outcome' => $decision['status'],
            'reason'  => $decision['message'] ?? 'conflict',
            'data'    => $decision
        ];

        goto FINAL_LOG; // ✅ correct early exit
    }

    // ─────────────────────────────────────────
    // Apply Scenario Data (NON-BLOCKING)
    // ─────────────────────────────────────────
    if (!empty($decision['data']['entityType'])) {
        $parsed['entity']['entityType'] = $decision['data']['entityType'];
        $entity['entityType'] = $decision['data']['entityType'];
    }

    // ─────────────────────────────────────────
    // Resolve Location
    // ─────────────────────────────────────────
    if ($entity['status'] === 'existing') {
        $locationRecord = resolveLocationRecord($db, $entity['entityId'], $location['placeId']);
    } else {
        $locationRecord = ['status' => 'new', 'locationId' => null];
    }

    // ─────────────────────────────────────────
    // Resolve Contact
    // ─────────────────────────────────────────
    if ($entity['status'] === 'existing' && $locationRecord['status'] === 'existing') {
        $contactRecord = resolveContact($db, $entity['entityId'], $locationRecord['locationId'], $parsed['contact']);
    } else {
        $contactRecord = ['status' => 'new', 'contactId' => null];
    }

    // ─────────────────────────────────────────
    // Build Outcome (DETERMINES INSERT)
    // ─────────────────────────────────────────
    $outcome = buildResolutionOutcome($entity, $locationRecord, $contactRecord);

    // ─────────────────────────────────────────
    // INSERT (ONLY AFTER OUTCOME IS CONFIRMED)
    // ─────────────────────────────────────────
    $insertResult = null;

    if (($outcome['outcome'] ?? null) === 'resolved_new') {

        // 🔥 DEBUG (optional — remove later)
        error_log('INSERT BLOCK HIT');

        $insertResult = executeInsert(
            $db,
            $parsed,
            $location,
            $entity,
            $locationRecord,
            $input
        );

        // ─────────────────────────────────────
        // Handle race condition (duplicate on insert)
        // ─────────────────────────────────────
        if (
            $insertResult['success'] === false &&
            !empty($insertResult['contactId'] ?? null)
        ) {
            $outcome = [
                'outcome' => 'resolved_duplicate',
                'entity'  => $entity,
                'location'=> $locationRecord,
                'contact' => [
                    'status'    => 'duplicate_email',
                    'contactId' => (int)$insertResult['contactId']
                ]
            ];

            $insertResult = null; // prevent misleading success
        }
    }

    // ─────────────────────────────────────────
    // Continue to unified logging
    // ─────────────────────────────────────────

    #endregion

} catch (Throwable $e) {
    // Catch any unexpected error and still ensure logging happens
    $outcome = [
        'outcome' => 'reject',
        'reason'  => 'Internal processing error',
        'error'   => $e->getMessage()
    ];
    error_log('createContact.php exception: ' . $e->getMessage());
    // Fall through to FINAL_LOG
}

#endregion

#region SECTION 17 — Unified Action Logging (Single Source of Truth + Hardened Fallback)

FINAL_LOG:

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = $_SESSION['contactId'] ?? 1;

if (!empty($currentUserId)) {

    $actionType      = ACTION_TYPE_PROPOSE;
    $intent          = 'system_event';
    $reason          = 'unknown';
    $targetContactId = null;

    $outcomeType = $outcome['outcome'] ?? null;

    // ─────────────────────────────────────────
    // Outcome Mapping
    // ─────────────────────────────────────────

    if ($outcomeType === 'resolved_new') {

        $actionType = ACTION_TYPE_ACCEPT;
        $intent     = 'create_contact';
        $reason     = 'new_contact_created';

        $targetContactId =
            $insertResult['contactId']
            ?? ($outcome['contact']['contactId'] ?? null);

    } elseif (
        $outcomeType === 'resolved_duplicate' ||
        (($outcome['contact']['status'] ?? null) === 'duplicate_email')
    ) {

        $actionType = ACTION_TYPE_PROPOSE;
        $intent     = 'duplicate_attempt';
        $reason     = 'duplicate_detected';

        $targetContactId =
            $outcome['contact']['contactId']
            ?? $contactRecord['contactId']
            ?? ($insertResult['contactId'] ?? null);

    } elseif (!empty($insertResult) && ($insertResult['success'] === false)) {

        $actionType = ACTION_TYPE_PROPOSE;
        $intent     = 'contact_insert_failed';
        $reason     = 'insert_failed';

    } elseif ($outcomeType === 'reject') {

        $actionType = 4;
        $intent     = 'reject';
        $reason     = $outcome['reason'] ?? 'validation_failed';

    } elseif ($outcomeType === 'partial') {

        $actionType = 4;
        $intent     = 'partial';
        $reason     = $outcome['reason'] ?? 'partial_resolution';

    } elseif ($outcomeType === 'conflict') {

        $actionType = ACTION_TYPE_PROPOSE;
        $intent     = 'conflict_detected';
        $reason     = $outcome['reason'] ?? 'conflict';
    }

    // ─────────────────────────────────────────
    // Safe Payload Encoding
    // ─────────────────────────────────────────

    $responsePayload = [
        'requestId'   => $varRequestId,
        'input'       => $input,
        'outcome'     => $outcomeType,
        'entityId'    => $entity['entityId'] ?? null,
        'locationId'  => $locationRecord['locationId'] ?? null,
        'contactId'   => $targetContactId,
        'reason'      => $reason
    ];

    $responseJson = json_encode($responsePayload, JSON_UNESCAPED_UNICODE);

    if ($responseJson === false) {
        $responseJson = '{"error":"json_encode_failed"}';
    }

    // ─────────────────────────────────────────
    // Primary Logging Attempt
    // ─────────────────────────────────────────

    $logged = false;

    try {
        logAction($db, [
            'type'      => $actionType,
            'contactId' => (int)$currentUserId,
            'prompt'    => $input,
            'response'  => $responseJson,
            'intent'    => $intent,
            'lat'       => $location['lat'] ?? null,
            'lng'       => $location['lng'] ?? null,
            'origin'    => ACTION_ORIGIN_USER
        ]);

        $logged = true;

    } catch (Throwable $e) {
        error_log('UNIFIED LOG FAILURE: ' . $e->getMessage());
    }

    // ─────────────────────────────────────────
    // HARDENED FALLBACK (Guaranteed Insert)
    // ─────────────────────────────────────────

    if (!$logged) {
        try {
            $stmt = $db->prepare("
                INSERT INTO tblActions 
                (
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
                )
                VALUES 
                (
                    :type,
                    :contactId,
                    :origin,
                    UNIX_TIMESTAMP(),
                    :prompt,
                    :response,
                    :intent,
                    :confidence,
                    :ip,
                    :lat,
                    :lng,
                    :ua
                )
            ");

            $stmt->execute([
                'type'       => $actionType,
                'contactId'  => (int)$currentUserId,
                'origin'     => ACTION_ORIGIN_USER,
                'prompt'     => substr($input, 0, 500),
                'response'   => substr($responseJson, 0, 1000),
                'intent'     => $intent,
                'confidence' => 1.00,
                'ip'         => $_SERVER['REMOTE_ADDR']     ?? null,
                'lat'        => $location['lat'] ?? null,
                'lng'        => $location['lng'] ?? null,
                'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

        } catch (Throwable $e2) {
            error_log('FALLBACK LOG FAILURE: ' . $e2->getMessage());
        }
    }
}

#endregion

#region SECTION 18 — Final Response

if (($outcome['outcome'] ?? '') === 'reject') {
    echo json_encode([
        'status' => 'reject',
        'reason' => $outcome['reason'] ?? 'Invalid input'
    ]);
} elseif (($outcome['outcome'] ?? '') === 'partial') {
    echo json_encode([
        'status'   => 'partial',
        'reason'   => $outcome['reason'] ?? 'Partial resolution',
        'location' => $location ?? null,
        'requestId'=> $varRequestId
    ]);
} elseif (($outcome['outcome'] ?? '') === 'conflict') {
    echo json_encode($outcome);
} else {
    echo json_encode([
        'status'           => $outcome['outcome'] ?? 'unknown',
        'entity'           => $entity,
        'location'         => $locationRecord,
        'contact'          => $outcome['contact'] ?? $contactRecord ?? null,
        'insert'           => $insertResult,
        'resolvedLocation' => $location,
        'scenario'         => $decision ?? null,
        'requestId'        => $varRequestId
    ]);
}

#endregion