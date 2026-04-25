<?php
declare(strict_types=1);
// 🔥 FIX #3 — Clear debug log per request (DEV ONLY)
file_put_contents(__DIR__ . '/createContact_debug.log', '');

// ======================================================================
//  Skyesoft — createContact.php
//  Version: 1.0.0
// ======================================================================
//
//  PURPOSE:
//  Entry point for contact creation using the ELC (Entity–Location–Contact)
//  resolution pipeline. Accepts raw input, orchestrates parsing, validation,
//  and resolution logic, and determines final outcome before any database
//  commit occurs.
//
//  RESPONSIBILITIES:
//  • Receive and validate incoming request payload
//  • Initiate proposeStage logging (raw input capture)
//  • Execute processingModel pipeline:
//      - parse input (AI / structured)
//      - resolveEntity()
//      - resolveLocation() (Google → Census → Parcel)
//      - resolveLocationRecord()
//  • Evaluate using EvaluationModel:
//      - completeness (ELC presence)
//      - properness (validation, consistency, duplication)
//  • Execute decisionPhase:
//      - resolved_new
//      - resolved_duplicate
//      - resolved_conflict
//      - partial
//      - reject
//  • Perform commitPhase ONLY if resolved_new
//  • Log all actions to actionLog
//
//  INPUT:
//  JSON payload (raw or structured contact data)
//
//  OUTPUT:
//  JSON response:
//  {
//      status: string,
//      entity: object|null,
//      location: object|null,
//      contact: object|null,
//      scenario: object,
//      requestId: string
//  }
//
//  RULES:
//  • No database writes occur before full location validation (placeId required)
//  • locationPlaceId is the authoritative location identity
//  • Duplicate detection is part of properness evaluation
//  • resolveLocation() is the single source of truth for location structure
//  • Address must be schema-compliant (street only, no formatted string)
//
//  NOTES:
//  • This file orchestrates — it does NOT contain core parsing or resolution logic
//  • All heavy logic is delegated to modular functions
//  • Designed to align with Codex ELC + EvaluationModel standards
//
// ======================================================================

#region SECTION 0 — Header

header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$input = $data['input'] ?? null;

if (!is_string($input) || trim($input) === '') {
    echo json_encode(['status' => 'reject', 'reason' => 'Invalid input']);
    exit;
}

$input = trim($input);

require_once __DIR__ . '/utils/parseContactCore.php';
require_once __DIR__ . '/resolveLocation.php';
require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/validateAddressCensus.php';
require_once __DIR__ . '/utils/actionLogger.php';
require_once __DIR__ . '/askOpenAI.php';

skyesoftLoadEnv();

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/createContact_debug.log');

error_log('=== HIT createContact ===');
error_log('[RAW] ' . $raw);
error_log('[INPUT] ' . $input);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = getPDO();

if (!$db instanceof PDO) {
    throw new RuntimeException('Database connection failed: invalid PDO instance.');
}

if (!defined('ACTION_ORIGIN_USER'))       define('ACTION_ORIGIN_USER', 1);
if (!defined('ACTION_ORIGIN_SYSTEM'))     define('ACTION_ORIGIN_SYSTEM', 2);
if (!defined('ACTION_ORIGIN_AUTOMATION')) define('ACTION_ORIGIN_AUTOMATION', 3);

if (!defined('ACTION_TYPE_PROPOSE'))      define('ACTION_TYPE_PROPOSE', 7);
if (!defined('ACTION_TYPE_ACKNOWLEDGE'))  define('ACTION_TYPE_ACKNOWLEDGE', 8);
if (!defined('ACTION_TYPE_ACCEPT'))       define('ACTION_TYPE_ACCEPT', 9);

$varRequestId = uniqid('req_', true);

#endregion

#region SECTION 1 — Main Pipeline Execution

$outcome        = null;
$entity         = null;
$locationRecord = null;
$contactRecord  = null;
$insertResult   = null;
$decision       = null;
$location       = null;
$parsed         = null;

try {

    #region 1. proposeStage

    logAction($db, [
        // 🧾 Action type
        'type'      => ACTION_TYPE_PROPOSE,
        // 👤 User context (NO fake fallback)
        'contactId' => $_SESSION['contactId'] ?? null,
        // 🔗 Session / request tracking (CRITICAL)
        'requestId' => $varRequestId ?? session_id(),
        // 🧠 Input
        'prompt'    => $input,
        // 🧾 Stage metadata (clean + accurate)
        'response'  => [
            'stage'     => 'propose',
            'requestId' => $varRequestId ?? session_id()
        ],
        // 🎯 Intent
        'intent'    => 'propose_contact',
        // 🧭 Origin
        'origin'    => ACTION_ORIGIN_USER
    ]);

    #endregion

    #region 2. processingModel

        #region 2a. derivationPhase

        $parsed = parseContact($input);

        $aiData = extractContactWithAI($input);

        // Merge AI → parsed (AI fills missing values ONLY)
        $parsed = array_replace_recursive($parsed, $aiData);

        error_log('[PARSED AFTER AI MERGE] ' . json_encode($parsed));

        foreach (['entity', 'location', 'contact'] as $section) {
            if (!isset($parsed[$section])) continue;
            foreach ($parsed[$section] as $key => $value) {
                $aiValue = $aiData[$section][$key] ?? null;
                if ((empty($value) || $value === null) && !empty($aiValue)) {
                    $parsed[$section][$key] = $aiValue;
                }
            }
        }

        $parsed = deriveContactAttributes($parsed, $input);

        // 🔥 Extract suite from address (critical normalization step)
        if (!empty($parsed['location']['address'])) {

            $split = splitAddressSuite($parsed['location']['address']);

            // Always clean the street address
            $parsed['location']['address'] = $split['street'];

            // Only set suite if it exists and hasn't already been set
            if (empty($parsed['location']['suite']) && !empty($split['suite'])) {
                $parsed['location']['suite'] = $split['suite'];
            }
        }

        #endregion

        #region 2b. resolutionPhase — Entity + Location + Contact

        // Resolve entity
        $entity = resolveEntity($db, $parsed['entity']['name'] ?? '');

        // Infer entity type if new
        if (
            ($entity['status'] ?? null) === 'new' &&
            empty($entity['entityType']) &&
            !empty($entity['entityName'])
        ) {
            $entity['entityType'] = inferEntityTypeAI($entity['entityName']);
            $parsed['entity']['entityType'] = $entity['entityType'];
        }

        // Resolve location
        $locationInput = $parsed['location'] ?? [];
        $location = resolveLocation($locationInput) ?? [];

        // 🔥 Preserve suite from parsed data (resolveLocation loses it)
        if (!empty($parsed['location']['suite'])) {
            $location['suite'] = $parsed['location']['suite'];
        }

        if (empty($location['placeId'])) {

            $outcome = [
                'outcome' => 'partial',
                'reason'  => 'Unable to validate location with Google Places.'
            ];

        } else {

            $locationRecord = resolveLocationRecord(
                $db,
                $entity['entityId'] ?? null,
                $location['placeId'],
                $location['suite'] ?? null
            );

            error_log('[LOCATION AFTER RESOLVE] ' . json_encode($location));

            $contactRecord = (
                ($entity['status'] === 'existing') &&
                ($locationRecord['status'] === 'existing')
            )
                ? resolveContact(
                    $db,
                    $entity['entityId'],
                    $locationRecord['locationId'],
                    $parsed['contact'] ?? []
                )
                : [
                    'status'    => 'new',
                    'contactId' => null
                ];

            $outcome = buildResolutionOutcome(
                $entity,
                $location,
                $contactRecord
            );

            $decision = evaluateScenario(
                $db,
                $entity,
                $location,
                $parsed['contact'] ?? []
            );
        }

        #endregion

    #endregion

    #region 3. decisionPhase & 4. commitPhase

    if (isset($outcome) && !in_array($outcome['outcome'] ?? '', ['reject', 'conflict'], true)) {
        if (($outcome['outcome'] ?? null) === 'resolved_new') {
            $insertResult = executeInsert(
                $db,
                $parsed,
                $location,
                $entity,
                $locationRecord,
                $input
            );
        }
    }

    #endregion

} catch (Throwable $e) {

    error_log('FATAL ERROR in createContact: ' . $e->getMessage());

    echo json_encode([
        'DEBUG' => true,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);

    exit;
}

#endregion

#region SECTION 2 — Action Logging & Final Response

// Determine final outcome and key values
$outcomeType     = $outcome['outcome'] ?? 'unknown';
$actionType      = ACTION_TYPE_PROPOSE;
$intent          = 'unknown';
$reason          = $outcome['reason'] ?? 'processed';
$targetContactId = null;
$message         = '';

if ($outcomeType === 'resolved_new' && !empty($insertResult['success'])) {
    $actionType      = ACTION_TYPE_ACCEPT;
    $intent          = 'create_contact';
    $reason          = 'new_contact_created';
    $targetContactId = $insertResult['contactId'] ?? null;
    $message         = 'Contact has been accepted and successfully added to the database.';

} elseif ($outcomeType === 'resolved_duplicate' || ($contactRecord['status'] ?? '') === 'exact_match') {
    $actionType      = ACTION_TYPE_PROPOSE;
    $intent          = 'duplicate_attempt';
    $reason          = 'duplicate_detected';
    $targetContactId = $contactRecord['contactId'] ?? null;
    $message         = 'This contact already exists. No new record was created.';

} elseif ($outcomeType === 'partial') {
    $actionType = ACTION_TYPE_ACKNOWLEDGE;
    $intent     = 'partial_resolution';
    $reason     = $outcome['reason'] ?? 'incomplete_elc';
    $message    = $outcome['reason'] ?? 'Contact information is incomplete.';

} elseif ($outcomeType === 'reject') {
    $actionType = 4;
    $intent     = 'reject';
    $reason     = $outcome['reason'] ?? 'validation_failed';
    $message    = $outcome['reason'] ?? 'Unable to process contact.';

} elseif ($outcomeType === 'conflict') {
    $actionType = ACTION_TYPE_ACKNOWLEDGE;
    $intent     = 'conflict_detected';
    $message    = 'Conflict detected. Manual review required.';
}

// === ACTION LOGGING ===
$currentUserId = $_SESSION['contactId'] ?? 1;

if (!empty($currentUserId)) {
    $responsePayload = [
        'requestId'     => $varRequestId,
        'input'         => $input,
        'outcome'       => $outcomeType,
        'contactId'     => $targetContactId,                    // ← Guaranteed top-level
        'entityId'      => $entity['entityId'] ?? null,
        'locationId'    => $locationRecord['locationId'] ?? null,
        'reason'        => $reason,
        'message'       => $message,
        'insertSuccess' => $insertResult['success'] ?? null,
        'lat'           => $location['lat'] ?? null,
        'lng'           => $location['lng'] ?? null,
        'placeId'       => $location['placeId'] ?? null
    ];

    $responseJson = json_encode($responsePayload, JSON_UNESCAPED_UNICODE) 
                    ?: '{"error":"json_encode_failed"}';

    logAction($db, [
        // 🧾 Action type (result)
        'type'      => $actionType,
        // 👤 User context (clean)
        'contactId' => $currentUserId ?? null,
        // 🔗 Session linkage (CRITICAL)
        'requestId' => $varRequestId ?? session_id(),
        // 🧠 Input
        'prompt'    => $input,
        // 🧾 Outcome payload
        'response'  => $responseJson,
        // 🎯 Final intent (resolved_new / reject / etc.)
        'intent'    => $intent,
        // 📍 Location context
        'lat'       => $location['lat'] ?? null,
        'lng'       => $location['lng'] ?? null,
        // 🧭 Origin (SYSTEM — important distinction)
        'origin'    => ACTION_ORIGIN_SYSTEM
    ]);
}

#endregion

#region SECTION 3 — Final Response (Standardized for Frontend)

$baseResponse = [
    'status'      => $outcomeType,
    'message'     => $message,
    'requestId'   => $varRequestId,
    'contactId'   => $targetContactId,           // ← Critical for frontend re-fetch
    'success'     => in_array($outcomeType, ['resolved_new', 'resolved_duplicate'], true),
    'verified'    => true                        // ← Signals DB-backed response
];

if ($outcomeType === 'reject') {
    echo json_encode(array_merge($baseResponse, [
        'reason' => $message
    ]));

} elseif ($outcomeType === 'partial') {
    echo json_encode(array_merge($baseResponse, [
        'location' => $location ?? null
    ]));

} else {
    echo json_encode(array_merge($baseResponse, [
        'entity'           => $entity,
        'location'         => $locationRecord,
        'contact'          => $contactRecord,
        'insert'           => $insertResult,
        'resolvedLocation' => $location,
        'scenario'         => $decision ?? null
    ]), JSON_UNESCAPED_UNICODE);
}

#endregion

#region SECTION 4 — Helper Functions

function resolveSalutation($input, $firstName, $lastName): string {
    $salutation = rtrim(trim((string)$input), '.');

    if (in_array($salutation, ['Mr', 'Ms'], true)) {
        return $salutation;
    }

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

    return 'Mr';
}

function deriveContactAttributes(array $parsed, string $rawInput): array {
    if (!isset($parsed['contact']) || !is_array($parsed['contact'])) {
        return $parsed;
    }

    $contact =& $parsed['contact'];

    $contact['salutation'] = resolveSalutation(
        $contact['salutation'] ?? '', 
        $contact['firstName'] ?? '', 
        $contact['lastName'] ?? ''
    );

    $contact['firstName'] = trim(ucwords(strtolower($contact['firstName'] ?? '')));
    $contact['lastName']  = trim(ucwords(strtolower($contact['lastName'] ?? '')));

    if (!empty($contact['phone'])) {
        $digits = preg_replace('/\D/', '', $contact['phone']);
        if (strlen($digits) === 10) {
            $contact['phone'] = substr($digits, 0, 3) . '-' .
                                substr($digits, 3, 3) . '-' .
                                substr($digits, 6, 4);
        } else {
            $contact['phone'] = $digits;
        }
    }

    if (!empty($contact['email'])) {
        $contact['email'] = strtolower(trim($contact['email']));
    }

    $title = trim($contact['title'] ?? '');
    if ($title === '' && function_exists('inferTitle')) {
        $title = trim(inferTitle($rawInput) ?? '');
    }
    $contact['title'] = $title ?: null;

    return $parsed;
}

// === Paste your original helper functions below (resolveEntity, resolveLocationRecord, resolveContact, 
// buildResolutionOutcome, evaluateScenario, inferEntityTypeAI, extractContactWithAI, executeInsert) ===
// They are unchanged from your attached file.

function resolveEntity(PDO $db, string $entityName): array {
    $entityName = trim($entityName);

    if ($entityName === '') {
        return [
            'status' => 'new',
            'entityId' => null,
            'entityName' => $entityName,
            'entityType' => 'customer'
        ];
    }

    $stmt = $db->prepare("
        SELECT entityId, entityName, entityType 
        FROM tblEntities 
        WHERE LOWER(entityName) = :name 
        LIMIT 1
    ");

    $stmt->execute(['name' => strtolower($entityName)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return [
            'status' => 'existing',
            'entityId' => (int)$row['entityId'],
            'entityName' => $row['entityName'],
            'entityType' => $row['entityType'] ?? 'customer'
        ];
    }

    return [
        'status' => 'new',
        'entityId' => null,
        'entityName' => $entityName,
        'entityType' => 'customer'
    ];
}

function resolveLocationRecord(PDO $db, ?int $entityId, string $placeId, ?string $suite): array {

    if ($entityId === null) {
        return ['status' => 'new', 'locationId' => null];
    }

    // 🔥 Normalize suite BEFORE comparison
    $suite = ($suite !== null && trim($suite) !== '')
        ? formatTitleCase(trim($suite))
        : null;

    $stmt = $db->prepare("
        SELECT locationId 
        FROM tblLocations 
        WHERE locationEntityId = :entityId
          AND locationPlaceId = :placeId
          AND (
                (locationAddressSuite IS NULL AND :suite IS NULL)
                OR locationAddressSuite = :suite
          )
        LIMIT 1
    ");

    $stmt->execute([
        'entityId' => $entityId,
        'placeId' => $placeId,
        'suite'   => $suite
    ]);

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

function buildResolutionOutcome(array $entity, array $location, array $contact): array {
    if (empty($entity['entityName'])) {
        return ['outcome' => 'reject'];
    }

    $hasValidLocation = !empty($location['placeId']) ||
                        (!empty($location['lat']) && !empty($location['lng']));

    if (!$hasValidLocation) {
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

function extractContactWithAI(string $input): array {
    if (!function_exists('callOpenAI')) {
        error_log('[AI] callOpenAI missing');
        return [];
    }

    $apiKey = skyesoftGetEnv("OPENAI_API_KEY");
    if (!$apiKey) {
        error_log('[AI] Missing API key');
        return [];
    }

    $prompt = <<<PROMPT
Extract structured contact data from the following text.

Return ONLY valid JSON with this exact structure:

{
  "entity": {"name": ""},
  "location": {"address": "", "city": "", "state": "", "zip": ""},
  "contact": {"firstName": "", "lastName": "", "title": "", "salutation": ""}
}

Rules:
- No extra text
- No explanation
- Leave unknown fields empty
- State must be 2-letter abbreviation

Text:
"""{$input}"""
PROMPT;

    try {
        $response = callOpenAI(
            $prompt,
            $apiKey,
            'gpt-4.1',
            ["type" => "json_object"]
        );

        if (!$response) {
            error_log('[AI RAW RESPONSE] null');
            return [];
        }

        error_log('[AI RAW RESPONSE] ' . $response);

        // Clean markdown fences
        $clean = trim($response);
        $clean = preg_replace('/^```[a-z]*\s*/i', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);

        // Extract full JSON
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $clean = substr($clean, $start, $end - $start + 1);
        } else {
            error_log('[AI JSON EXTRACTION FAILED]');
            return [];
        }

        $data = json_decode($clean, true);

        if (!is_array($data)) {
            error_log('[AI JSON DECODE FAILED] ' . $clean);
            return [];
        }

        error_log('[AI PARSED] ' . json_encode($data));

        return $data;

    } catch (Throwable $e) {
        error_log('[AI EXTRACT ERROR] ' . $e->getMessage());
        return [];
    }
}

// Format text to clean Title Case (safe for addresses)
function formatTitleCase(?string $value): ?string {

    if ($value === null) return null;

    $value = trim($value);

    if ($value === '') return null;

    // Base normalization
    $value = ucwords(strtolower($value));

    // 🔥 Fix common edge cases (pattern-based, not hardcoded words)

    // Preserve compass directions when standalone
    $value = preg_replace('/\b(N|S|E|W)\b/i', strtoupper('$1'), $value);

    // Preserve numbered highways / interstates
    $value = preg_replace('/\b(Us|I)\b/i', strtoupper('$1'), $value);

    // Fix Mc/Mac names (McDowell, McQueen, etc.)
    $value = preg_replace_callback('/\bMc([a-z])/', function ($m) {
        return 'Mc' . strtoupper($m[1]);
    }, $value);

    return $value;
}

function executeInsert(PDO $db, array $parsed, array $location, array $entity, array $locationRecord, string $input): array {
    try {
        $db->beginTransaction();

        if (empty($entity['entityName'])) {
            throw new RuntimeException('Missing entity name');
        }

        if ($entity['status'] === 'new') {
            $stmt = $db->prepare("
                INSERT INTO tblEntities (entityName, entityType, entityState, entityDate)
                VALUES (:name, :type, :state, UNIX_TIMESTAMP())
            ");
            $stmt->execute([
                'name'  => $entity['entityName'],
                'type'  => $entity['entityType'] ?? 'customer',
                'state' => $location['state'] ?? null
            ]);
            $entityId = (int)$db->lastInsertId();
            if (empty($entityId)) throw new RuntimeException('Entity insert failed');
        } else {
            $entityId = (int)$entity['entityId'];
        }

        if ($locationRecord['status'] === 'new') {
            if (empty($location['placeId'])) {
                throw new RuntimeException('Missing placeId for location');
            }

            // 🔥 HARD VALIDATION — Maricopa County REQUIRES a parcel number
            if (
                ($location['state'] ?? null) === 'AZ' &&
                strpos(strtoupper($location['county'] ?? ''), 'MARICOPA') !== false &&
                empty($location['parcelNumber'])
            ) {
                throw new RuntimeException('Parcel number required for Maricopa County at insert stage');
            }

            $stmt = $db->prepare("
                INSERT INTO tblLocations (
                    locationEntityId, locationName, locationPlaceId, locationAddress, locationAddressSuite,
                    locationCity, locationState, locationZip, locationCounty, locationCountyFips,
                    locationJurisdiction, locationParcelNumber, locationLatitude, locationLongitude, locationDate
                ) VALUES (
                    :entityId, :name, :placeId, :address, :suite,
                    :city, :state, :zip, :county, :countyFips,
                    :jurisdiction, :parcel, :latitude, :longitude, UNIX_TIMESTAMP()
                )
            ");

            // Execute Statement
            $stmt->execute([
                'entityId'     => $entityId,
                'name'         => formatTitleCase($entity['entityName']),

                'placeId'      => $location['placeId'],

                // 🔥 CLEAN + FORMAT
                'address'      => formatTitleCase(
                    preg_replace('/,.*$/', '', trim($location['address'] ?? ''))
                ),

                // 🔥 SUITE — preserve "Suite 210" format (NO uppercase forcing)
                'suite'        => isset($location['suite']) && $location['suite'] !== ''
                    ? formatTitleCase(preg_replace('/\s+/', ' ', trim($location['suite'])))
                    : null,

                'city'         => formatTitleCase($location['city'] ?? ''),
                'state'        => strtoupper($location['state'] ?? ''), // keep uppercase
                'zip'          => $location['zip'] ?? null,

                'county'       => formatTitleCase($location['county'] ?? ''),
                'countyFips'   => $location['countyFips'] ?? null,

                'jurisdiction' => formatTitleCase($location['jurisdiction'] ?? null),
                'parcel'       => $location['parcelNumber'] ?? null,

                'latitude'     => $location['lat'] ?? null,
                'longitude'    => $location['lng'] ?? null
            ]);

            $locationId = (int)$db->lastInsertId();
        } else {
            $locationId = (int)$locationRecord['locationId'];
        }

        $stmt = $db->prepare("
            INSERT INTO tblContacts (
                contactEntityId, contactLocationId, contactSalutation, contactTitle,
                contactFirstName, contactLastName, contactPrimaryPhone, contactEmail, contactDate
            ) VALUES (
                :entityId, :locationId, :salutation, :title,
                :firstName, :lastName, :phone, :email, UNIX_TIMESTAMP()
            )
        ");
        $stmt->execute([
            'entityId'   => $entityId,
            'locationId' => $locationId,
            'salutation' => $parsed['contact']['salutation'] ?? null,
            'title'      => $parsed['contact']['title'] ?? null,
            'firstName'  => $parsed['contact']['firstName'] ?? '',
            'lastName'   => $parsed['contact']['lastName'] ?? '',
            'email'      => strtolower(trim($parsed['contact']['email'] ?? '')),
            'phone'      => $parsed['contact']['phone'] ?? null
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
        if ($db->inTransaction()) $db->rollBack();
        error_log('executeInsert failed: ' . $e->getMessage() . ' | Input: ' . substr($input, 0, 500));
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Validate and enrich location using Google Geocoding API
function validateLocationWithGoogle(array $locationInput): array {

    // Build query
    $queryParts = [
        $locationInput['address'] ?? '',
        $locationInput['city'] ?? '',
        $locationInput['state'] ?? '',
        $locationInput['zip'] ?? ''
    ];

    $query = trim(implode(' ', array_filter($queryParts)));

    if ($query === '') {
        error_log('[Google] Empty query');
        return ['placeId' => null];
    }

    $apiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY');

    if (!$apiKey) {
        error_log('[Google] Missing GOOGLE_MAPS_BACKEND_API_KEY');
        return ['placeId' => null];
    }

    error_log('[GOOGLE KEY USED] ' . substr($apiKey, 0, 10));

    // ✅ Correct URL
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' 
        . urlencode($query) 
        . '&key=' . $apiKey;

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header'  => "User-Agent: Skyesoft/1.0\r\n"
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('[Google] Request failed: ' . $url);
        return ['placeId' => null];
    }

    error_log('[Google RAW RESPONSE] ' . $response);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        error_log('[Google] Invalid JSON response');
        return ['placeId' => null];
    }

    $status = $data['status'] ?? 'UNKNOWN';

    if ($status !== 'OK') {
        error_log('[Google STATUS ERROR] ' . $status . ' | ' . json_encode($data));
        return ['placeId' => null];
    }

    if (empty($data['results'])) {
        error_log('[Google] No results for query: ' . $query);
        return ['placeId' => null];
    }

    // 🔥 Smart result selection
    $result = null;

    foreach ($data['results'] as $r) {
        $types = $r['types'] ?? [];

        if (
            in_array('street_address', $types) ||
            in_array('premise', $types)
        ) {
            $result = $r;
            break;
        }

        if (!$result) {
            $result = $r;
        }
    }

    return [
        'placeId' => $result['place_id'] ?? null,
        'address' => $result['formatted_address'] ?? '',
        'city'    => $locationInput['city'] ?? '',
        'state'   => $locationInput['state'] ?? '',
        'zip'     => $locationInput['zip'] ?? '',
        'lat'     => $result['geometry']['location']['lat'] ?? null,
        'lng'     => $result['geometry']['location']['lng'] ?? null,
        'suite'   => null
    ];
}

function resolveCensusGeography(array $location): array {
    if (empty($location['lat']) || empty($location['lng'])) {
        return ['county' => '', 'countyFips' => null];
    }

    $url = "https://geocoding.geo.census.gov/geocoder/geographies/coordinates?x={$location['lng']}&y={$location['lat']}&benchmark=Public_AR_Current&vintage=Current_Current&format=json";

    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error_log('[Census Geocoder] Request timeout or failed');
        return ['county' => '', 'countyFips' => null];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['result']['geographies']['Counties'][0])) {
        error_log('[Census Geocoder] Invalid response');
        return ['county' => '', 'countyFips' => null];
    }

    $countyData = $data['result']['geographies']['Counties'][0];

    return [
        'county'     => $countyData['NAME'] ?? '',
        'countyFips' => $countyData['GEOID'] ?? null
    ];
}

#endregion