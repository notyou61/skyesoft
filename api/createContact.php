<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — createContact.php
//  Version: 1.4.6
//  Last Updated: 2026-04-17
//  Codex Tier: 2 — ELC Execution Layer
// ======================================================================

#region SECTION 0 — Environment Bootstrap

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/utils/parseContactCore.php';
require_once __DIR__ . '/resolveLocation.php';
require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/validateAddressCensus.php';
require_once __DIR__ . '/utils/actionLogger.php';
require_once __DIR__ . '/askOpenAI.php';

// Resolve Salutation (MASTER - DRY - Single Source of Truth)
function resolveSalutation($input, $firstName, $lastName): string {

    // Normalize incoming value
    $salutation = rtrim(trim((string)$input), '.');

    // If valid, return immediately
    if (in_array($salutation, ['Mr', 'Ms'], true)) {
        return $salutation;
    }

    // Attempt AI inference (only if function exists)
    if (function_exists('inferSalutation')) {

        try {
            $ai = inferSalutation((string)$firstName, (string)$lastName);

            if ($ai) {
                $ai = rtrim(trim($ai), '.');
                $ai = ucfirst(strtolower($ai));

                if (in_array($ai, ['Mr', 'Ms'], true)) {
                    return $ai;
                }
            }

        } catch (Throwable $e) {
            error_log('[SALUTATION RESOLVER ERROR] ' . $e->getMessage());
        }
    }

    // Final fallback (guaranteed value)
    return 'Mr';
}

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

#region SECTION 1 — MAIN EXECUTION WRAPPER (Guarantees logging on every request)

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

    #region 🧠 Resolve Contact Fields (Salutation + Title)

    // Reference
    $contact =& $parsed['contact'];

    // ─────────────────────────────
    // Resolve Salutation (existing)
    // ─────────────────────────────
    $contact['salutation'] = resolveSalutation(
        $contact['salutation'] ?? '',
        $contact['firstName'] ?? '',
        $contact['lastName'] ?? ''
    );

    // ─────────────────────────────
    // Resolve Title (AI + fallback)
    // ─────────────────────────────
    $title = trim((string)($contact['title'] ?? ''));

    if ($title === '') {

        if (function_exists('inferTitle')) {

            try {
                $aiTitle = inferTitle($input);

                if ($aiTitle) {
                    $aiTitle = trim($aiTitle);

                    // Normalize simple edge cases
                    if ($aiTitle !== '') {
                        $title = $aiTitle;
                    }
                }

            } catch (Throwable $e) {
                error_log('[TITLE RESOLVER ERROR] ' . $e->getMessage());
            }
        }
    }

    // Final fallback (guaranteed value)
    if ($title === '') {
        $title = 'Unknown';
    }

    $contact['title'] = $title;

    #endregion

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

    // ─────────────────────────────────────────
    // LOCATION NORMALIZATION (Guarantees structure)
    // ─────────────────────────────────────────
    $location = [
        'placeId'      => $location['placeId'] ?? null,
        'address'      => $location['address'] ?? '',
        'suite'        => $location['suite'] ?? null,
        'city'         => $location['city'] ?? '',
        'state'        => $location['state'] ?? '',
        'zip'          => $location['zip'] ?? null,
        'county'       => $location['county'] ?? '',
        'countyFips'   => $location['countyFips'] ?? null,
        'jurisdiction' => $location['jurisdiction'] ?? null,
        'parcelNumber' => $location['parcelNumber'] ?? null,
        'lat'          => $location['lat'] ?? null,
        'lng'          => $location['lng'] ?? null
    ];

    $contact = $parsed['contact'] ?? [];

    // Validation Gates
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
        // 🔒 COORDINATE GUARD (No silent NULL lat/lng inserts)
        if (empty($location['lat']) || empty($location['lng'])) {
            $outcome = [
                'outcome' => 'reject',
                'reason'  => 'Missing coordinates from location resolution',
                'location'=> $location
            ];
            goto FINAL_LOG;
        }
    }
    if ($location['state'] === 'AZ' &&
        strpos(strtoupper($location['county'] ?? ''), 'MARICOPA') !== false &&
        empty($location['parcelNumber'])) {
        $outcome = ['outcome' => 'reject', 'reason' => 'Parcel required for Maricopa County', 'location' => $location];
        goto FINAL_LOG;
    }

    #endregion

    #region HELPER FUNCTIONS (unchanged)

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

        if (!empty($entity['isInvalid']) && $entity['isInvalid'] == 1) {
            return [
                "status" => "reject",
                "scenario" => "invalid_entity",
                "message" => "Entity is marked invalid.",
                "data" => ["entityId" => $entity['entityId'] ?? null]
            ];
        }

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

            // 🔒 ENTITY GUARD
            if (empty($entity['entityName'])) {
                throw new RuntimeException('Missing entity name');
            }

            // ENTITY INSERT
            if ($entity['status'] === 'new') {

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
                    'name'  => $entity['entityName'],
                    'type'  => $entity['entityType'] ?? 'customer',
                    'state' => $location['state'] ?? null
                ]);

                $entityId = (int)$db->lastInsertId();

                if (empty($entityId)) {
                    throw new RuntimeException('Entity insert failed — no ID returned');
                }

            } else {
                $entityId = (int)$entity['entityId'];
            }

            // LOCATION INSERT
            if ($locationRecord['status'] === 'new') {

                if (empty($location['placeId'])) {
                    throw new RuntimeException('Missing placeId for location');
                }

                $stmt = $db->prepare("
                    INSERT INTO tblLocations (
                        locationEntityId,
                        locationName,
                        locationPlaceId,
                        locationAddress,
                        locationAddressSuite,
                        locationCity,
                        locationState,
                        locationZip,
                        locationCounty,
                        locationCountyFips,
                        locationJurisdiction,
                        locationParcelNumber,
                        locationLatitude,
                        locationLongitude,
                        locationDate
                    ) VALUES (
                        :entityId,
                        :name,
                        :placeId,
                        :address,
                        :suite,
                        :city,
                        :state,
                        :zip,
                        :county,
                        :countyFips,
                        :jurisdiction,
                        :parcel,
                        :latitude,
                        :longitude,
                        UNIX_TIMESTAMP()
                    )
                ");

                $stmt->execute([
                    'entityId'     => $entityId,
                    'name'         => $entity['entityName'],
                    'placeId'      => $location['placeId'],
                    'address'      => $location['address'] ?? '',
                    'suite'        => $location['suite'] ?? null,
                    'city'         => $location['city'] ?? '',
                    'state'        => $location['state'] ?? '',
                    'zip'          => $location['zip'] ?? null,
                    'county'       => $location['county'] ?? '',
                    'countyFips'   => $location['countyFips'] ?? null,
                    'jurisdiction' => $location['jurisdiction'] ?? null,
                    'parcel'       => $location['parcelNumber'] ?? null,
                    'latitude'     => $location['lat'] ?? null,
                    'longitude'    => $location['lng'] ?? null
                ]);

                $locationId = (int)$db->lastInsertId();

            } else {
                $locationId = (int)$locationRecord['locationId'];
            }

            // 🔒 CONTACT GUARDS
            if (empty($parsed['contact']['email'] ?? '')) {
                throw new RuntimeException('Missing contact email');
            }

            if (empty($parsed['contact']['phone'] ?? '')) {
                throw new RuntimeException('Missing contact phone');
            }

            // CONTACT INSERT
            $stmt = $db->prepare("
                INSERT INTO tblContacts (
                    contactEntityId,
                    contactLocationId,
                    contactSalutation,
                    contactTitle,
                    contactFirstName,
                    contactLastName,
                    contactPrimaryPhone,
                    contactEmail,
                    contactDate
                ) VALUES (
                    :entityId,
                    :locationId,
                    :salutation,
                    :title,
                    :firstName,
                    :lastName,
                    :phone,
                    :email,
                    UNIX_TIMESTAMP()
                )
            ");

            $stmt->execute([
                'entityId'   => $entityId,
                'locationId' => $locationId,
                'salutation' => $parsed['contact']['salutation'] ?? null,
                'title'      => $parsed['contact']['title'] ?? null,
                'firstName'  => $parsed['contact']['firstName'] ?? '',
                'lastName'   => $parsed['contact']['lastName'] ?? '',
                'email'      => strtolower(trim($parsed['contact']['email'])),
                'phone'      => $parsed['contact']['phone']
            ]);

            $contactId = (int)$db->lastInsertId();

            $db->commit();

            return [
                'success'    => true,
                'entityId'   => $entityId,
                'locationId' => $locationId,
                'contactId'  => $contactId
            ];

        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('executeInsert failed: ' . $e->getMessage() . ' | requestId=' . ($GLOBALS['varRequestId'] ?? 'unknown'));

            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    #endregion

    #region MAIN FLOW — Resolution & Insert (Hardened)

    // Resolve Entity
    $entity = resolveEntity($db, $parsed['entity']['name'] ?? '');

    // Scenario Evaluation
    $decision = evaluateScenario($db, $entity, $location, $parsed['contact'] ?? []);

    if ($decision['status'] === 'reject' || $decision['status'] === 'conflict') {
        $outcome = [
            'outcome' => $decision['status'],
            'reason'  => $decision['message'] ?? 'conflict',
            'data'    => $decision
        ];
        goto FINAL_LOG;
    }

    // Apply non-blocking decisions
    if (!empty($decision['data']['entityType'])) {
        $parsed['entity']['entityType'] = $decision['data']['entityType'];
        $entity['entityType'] = $decision['data']['entityType'];
    }

    // Resolve Location Record
    if ($entity['status'] === 'existing' && !empty($location['placeId'])) {
        $locationRecord = resolveLocationRecord($db, $entity['entityId'], $location['placeId']);
    } else {
        $locationRecord = ['status' => 'new', 'locationId' => null];
    }

    // Resolve Contact Record
    if ($entity['status'] === 'existing' && $locationRecord['status'] === 'existing') {
        $contactRecord = resolveContact($db, $entity['entityId'], $locationRecord['locationId'], $parsed['contact'] ?? []);
    } else {
        $contactRecord = ['status' => 'new', 'contactId' => null];
    }

    // Build Preliminary Outcome
    $outcome = buildResolutionOutcome($entity, $locationRecord, $contactRecord);

    // CRITICAL HARD GUARD — Prevent False "resolved_new"
    if (($outcome['outcome'] ?? null) === 'resolved_new') {
        $missing = [];

        if (empty($parsed['entity']['name'] ?? ''))          $missing[] = 'entityName';
        if (empty($parsed['contact']['email'] ?? ''))        $missing[] = 'contactEmail';
        if (empty($location['placeId'] ?? ''))               $missing[] = 'placeId';
        if ($entity['status'] === 'new' && empty($entity['entityName'] ?? '')) $missing[] = 'newEntityName';
        if ($entity['status'] === 'existing' && empty($entity['entityId'] ?? 0)) $missing[] = 'existingEntityId';

        if (!empty($missing)) {
            error_log("CRITICAL: resolved_new with missing data | requestId={$varRequestId} | missing=" . implode(',', $missing));

            $outcome = [
                'outcome' => 'reject',
                'reason'  => 'invalid_resolved_new_state',
                'missing' => $missing
            ];
            goto FINAL_LOG;
        }
    }

    // INSERT — ONLY if truly resolved_new
    $insertResult = null;

    if (($outcome['outcome'] ?? null) === 'resolved_new') {

        error_log("INSERT BLOCK HIT | requestId={$varRequestId}");

        $insertResult = executeInsert(
            $db,
            $parsed,
            $location,
            $entity,
            $locationRecord,
            $input
        );

        // Handle race-condition duplicate
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
            $insertResult = null;
        }
    }

    #endregion

} catch (Throwable $e) {

    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => basename($e->getFile())
    ]);

    exit;
}

#endregion

#region SECTION 2 — Unified Action Logging (Single Source of Truth + Hardened)

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
    // Outcome Mapping — HARDENED
    // ─────────────────────────────────────────
    if ($outcomeType === 'resolved_new') {

        if (empty($insertResult)) {
            // Insert block never ran
            $actionType = ACTION_TYPE_PROPOSE;
            $intent     = 'insert_not_attempted';
            $reason     = 'insert_block_not_executed';
            error_log("CRITICAL: resolved_new but insert block not executed | requestId={$varRequestId}");
        } 
        elseif (($insertResult['success'] ?? false) === true) {
            // Genuine success
            $actionType = ACTION_TYPE_ACCEPT;
            $intent     = 'create_contact';
            $reason     = 'new_contact_created';
            $targetContactId = $insertResult['contactId'] ?? null;
        } 
        else {
            // Insert attempted but failed
            $actionType = ACTION_TYPE_PROPOSE;
            $intent     = 'contact_insert_failed';
            $reason     = $insertResult['error'] ?? 'insert_failed';
            error_log("INSERT FAILED | requestId={$varRequestId} | error=" . ($insertResult['error'] ?? 'unknown'));
        }

    } elseif (
        $outcomeType === 'resolved_duplicate' ||
        (($outcome['contact']['status'] ?? null) === 'duplicate_email')
    ) {

        $actionType = ACTION_TYPE_PROPOSE;
        $intent     = 'duplicate_attempt';
        $reason     = 'duplicate_detected';

        $targetContactId = $outcome['contact']['contactId']
                        ?? $contactRecord['contactId']
                        ?? ($insertResult['contactId'] ?? null);

    } elseif (!empty($insertResult) && ($insertResult['success'] === false)) {

        $actionType = ACTION_TYPE_PROPOSE;
        $intent     = 'contact_insert_failed';
        $reason     = $insertResult['error'] ?? 'insert_failed';

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

    // Safe Payload Encoding
    $responsePayload = [
        'requestId'     => $varRequestId,
        'input'         => $input,
        'outcome'       => $outcomeType,
        'entityId'      => $entity['entityId'] ?? null,
        'locationId'    => $locationRecord['locationId'] ?? null,
        'contactId'     => $targetContactId,
        'reason'        => $reason,
        'insertSuccess' => $insertResult['success'] ?? null,
        'lat'           => $location['lat'] ?? null,
        'lng'           => $location['lng'] ?? null,
        'placeId'       => $location['placeId'] ?? null
    ];

    $responseJson = json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
    if ($responseJson === false) {
        $responseJson = '{"error":"json_encode_failed"}';
    }

    // Primary Logging
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

    // Hardened Fallback
    if (!$logged) {
        try {
            $stmt = $db->prepare("
                INSERT INTO tblActions 
                (actionTypeId, contactId, actionOrigin, actionUnix, promptText, responseText, 
                 intent, intentConfidence, ipAddress, latitude, longitude, userAgent)
                VALUES 
                (:type, :contactId, :origin, UNIX_TIMESTAMP(), :prompt, :response, 
                 :intent, :confidence, :ip, :lat, :lng, :ua)
            ");

            $stmt->execute([
                'type'       => $actionType,
                'contactId'  => (int)$currentUserId,
                'origin'     => ACTION_ORIGIN_USER,
                'prompt'     => substr($input, 0, 500),
                'response'   => substr($responseJson, 0, 1000),
                'intent'     => $intent,
                'confidence' => 1.00,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
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

#region SECTION 3 — Final Response

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