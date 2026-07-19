<?php
declare(strict_types=1);

/**
 * Skyesoft — commitProposal.php
 * Deterministic Commit Engine Layer
 * 
 * File Version:     1.3.0
 * Schema Version:   2.1.1
 * Last Updated:     2026-07-14
 */

#region SECTION 00 — Bootstrap & Request Initialization

if (!headers_sent()) {
    header('Content-Type: application/json');
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/dbConnect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

#endregion

#region SECTION 01 — Intake Data Processing

$rawJson = file_get_contents('php://input');
$inputData = json_decode($rawJson, true) ?? [];

$proposalId       = trim((string)($inputData['proposalId'] ?? ''));
$proposalActionId = isset($inputData['proposalActionId']) ? (int)$inputData['proposalActionId'] : null;

if (empty($proposalId)) {
    echo json_encode(['success' => false, 'error' => 'Missing proposalId']);
    exit;
}

$db = getPDO();
if (!$db) {
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
    exit;
}

$actorContactId = (int)($_SESSION['contactId'] ?? 1);

#endregion

#region SECTION 02 — Load Snapshot Context

$snapshot = null;
$originatingActivitySessionId = '';

// =====================================================
// Primary Source — Originating Proposal Action
// =====================================================
if ($proposalActionId !== null && $proposalActionId > 0) {
    $stmt = $db->prepare("
        SELECT
            actionResponseData,
            activitySessionId
        FROM tblActions
        WHERE actionId = ?
        LIMIT 1
    ");

    $stmt->execute([$proposalActionId]);
    $actionRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($actionRow) {
        $snapshot = json_decode(
            (string)($actionRow['actionResponseData'] ?? ''),
            true
        );

        $originatingActivitySessionId = trim(
            (string)($actionRow['activitySessionId'] ?? '')
        );

        error_log(
            "[COMMIT] Loaded originating Action row " .
            "(ID: {$proposalActionId})"
        );
    }
}

// =====================================================
// Transitional Fallback — Ephemeral Snapshot
// =====================================================
if (!$snapshot) {
    $cleanId = preg_replace('/^PROP-?/', '', $proposalId);

    $candidates = [
        __DIR__ . "/../data/runtimeEphemeral/proposals/{$proposalId}.json",
        __DIR__ . "/../data/runtimeEphemeral/proposals/{$cleanId}.json"
    ];

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }

        $snapshot = json_decode(
            (string)file_get_contents($path),
            true
        );

        if (is_array($snapshot)) {
            error_log("[COMMIT] Loaded snapshot from filesystem: {$path}");
            break;
        }

        $snapshot = null;
    }
}

if (!$snapshot || !is_array($snapshot)) {
    error_log(
        "[COMMIT] CRITICAL: Proposal metadata could not be resolved " .
        "for Proposal ID {$proposalId}"
    );

    echo json_encode([
        'success' => false,
        'error'   => 'Proposal metadata could not be resolved'
    ]);

    exit;
}

// =====================================================
// Canonical Activity Session Resolution
// =====================================================
$sessionCandidates = [
    $originatingActivitySessionId,
    trim((string)($snapshot['activitySessionId'] ?? '')),
    trim((string)($snapshot['context']['activitySessionId'] ?? '')),
    trim((string)($inputData['activitySessionId'] ?? '')),
    trim((string)($_SESSION['activitySessionId'] ?? '')),
    session_id()
];

$activitySessionId = '';

foreach ($sessionCandidates as $candidate) {
    if ($candidate !== '' && $candidate !== 'no_session') {
        $activitySessionId = $candidate;
        break;
    }
}

// Activity session is mandatory for commitment.
if ($activitySessionId === '') {
    error_log(
        '[COMMIT] CRITICAL: Unable to resolve activitySessionId ' .
        "for Proposal Action {$proposalActionId}"
    );

    echo json_encode([
        'success' => false,
        'error'   => 'Proposal activity session could not be resolved'
    ]);

    exit;
}

error_log(
    '[COMMIT] Snapshot keys: ' .
    implode(', ', array_keys($snapshot))
);

error_log(
    '[COMMIT] Resolved activitySessionId: ' .
    $activitySessionId
);

#endregion

#region SECTION 03 — Governance Validation Gates

$commitPlan = $snapshot['commitPlan'] ?? $snapshot['persistence'] ?? [];
$actions    = $commitPlan['actions'] ?? [];
$payload    = $snapshot['data'] ?? [];

$pc        = $snapshot['pcm']['pc'] ?? null;
$rsList    = $snapshot['pcm']['rs'] ?? [];
$canCommit = (bool)($commitPlan['canCommit'] ?? false);

if (!$canCommit) {
    echo json_encode(['success' => false, 'error' => 'Proposal is not authorized for commitment']);
    exit;
}

if ($pc === 'PC-0') {
    echo json_encode(['success' => false, 'error' => 'PC-0 requires no database commitment']);
    exit;
}

if ($rsList !== ['RS-0']) {
    echo json_encode(['success' => false, 'error' => 'Proposal contains unresolved governance conditions']);
    exit;
}

if ($pc !== 'PC-1') {   // Expand in future phases
    echo json_encode(['success' => false, 'error' => "Commit engine currently supports PC-1 only (received: {$pc})"]);
    exit;
}

if (empty($actions)) {
    echo json_encode(['success' => false, 'error' => 'No operational commit actions designated']);
    exit;
}

#endregion

#region SECTION 04 — Execution Engine & Orchestration

# ─────────────────────────────────────────────────────────────
# Commit Audit Record Enhancement (Phase 2 — Refined)
# Fully self-contained Action Type 14 audit receipt with
# centralized audit context and human-readable promptText.
# ─────────────────────────────────────────────────────────────

$artifactMoves = [];
$response = [
    'success'    => false,
    'message'    => '',
    'contactId'  => null,
    'entityId'   => null,
    'locationId' => null
];

try {
    $db->beginTransaction();

    $entityId   = null;
    $locationId = null;
    $contactId  = null;

    foreach ($actions as $action) {
        switch ($action) {

        case 'insert_entity':
            $entityId = insertEntity(
                $db,
                $payload['entity'] ?? [],
                $payload['location'] ?? [],
                $snapshot['narratives'] ?? [],
                (int)$proposalActionId
            );
            break;

        case 'insert_location':
            $locationId = insertLocation(
                $db,
                $payload['location'] ?? [],
                $payload['entity'] ?? [],
                $snapshot['narratives'] ?? [],
                (int)$entityId,
                (int)$proposalActionId
            );
            break;

        case 'insert_contact':
        case 'insert_replacement_contact':
            $contactId = insertContact(
                $db,
                $payload['contact'] ?? [],
                $snapshot['narratives'] ?? [],
                (int)$entityId,
                (int)$locationId,
                (int)$proposalActionId
            );
            break;

        case 'retire_contact':
            if ($contactId) retireContact($db, (int)$contactId);
            break;

        case 'link_entity':
            $entityId = (int)($commitPlan['entity']['entityId'] ?? $snapshot['databaseResolution']['entity']['entityId'] ?? 0);
            break;

        case 'link_location':
            $locationId = (int)($commitPlan['location']['locationId'] ?? $snapshot['databaseResolution']['location']['locationId'] ?? 0);
            break;
        }
    }

    $governingObjectId = in_array($pc, ['PC-4','PC-5'], true) ? (int)$locationId : (int)$contactId;

    $reportArtifacts = $snapshot['reportArtifacts'] ?? $snapshot['artifactRegistry'] ?? [];
    error_log("[COMMIT] Report Artifacts count: " . count($reportArtifacts));
    $promotedArtifacts = promoteArtifacts($proposalId, $governingObjectId, $reportArtifacts, $artifactMoves);

    // =====================================================
    // Commit Audit Context Initialization
    // =====================================================
    // All execution context and audit values resolved once here.

    $entityName = trim(
        (string)(
            $payload['entity']['entityName']
            ?? $payload['entity']['entityNameRaw']
            ?? ''
        )
    );

    $contactName = trim(sprintf(
        '%s %s',
        $payload['contact']['contactFirstName'] ?? '',
        $payload['contact']['contactLastName'] ?? ''
    ));

    // Robust coordinate lookup + explicit type
    $latitude  = $snapshot['data']['location']['locationLatitude']  ?? 
                $payload['location']['locationLatitude'] ?? null;
    $longitude = $snapshot['data']['location']['locationLongitude'] ?? 
                $payload['location']['locationLongitude'] ?? null;

    if ($latitude !== null)  $latitude  = (float)$latitude;
    if ($longitude !== null) $longitude = (float)$longitude;

    // Use the robust session ID resolved in SECTION 02
    $ipAddress         = $snapshot['ipAddress'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $userAgent         = $snapshot['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);

    $actionOrigin      = 0;
    $intent            = 'proposal_commit';
    $intentConfidence  = 1.0000;
    $actionUnix        = time();

    // Human-readable prompt for the audit log
    $promptText = sprintf(
        'Accepted proposal for %s (%s)',
        $contactName,
        $entityName ?: 'Unknown Entity'
    );

    // Temporary diagnostics
    error_log('[COMMIT] Audit Context - Session: ' . $activitySessionId);
    error_log('[COMMIT] Audit Context - Prompt: ' . $promptText);
    error_log('[COMMIT] Audit Context - Location: ' . json_encode(['lat' => $latitude, 'lng' => $longitude]));

    // ─────────────────────────────────────────────────────────────
    // Build self-contained Commit Audit Record
    // ─────────────────────────────────────────────────────────────
    $actionPayload = [
        'proposalId'       => $proposalId,
        'proposalActionId' => $proposalActionId,
        'proposalKind'     => $snapshot['proposalKind'] ?? 'contact',
        'proposalParser'   => $snapshot['proposalParser'] ?? 'contact',
        'pc'               => $pc,
        'rs'               => $snapshot['pcm']['rs'] ?? [],
        'commitPlan'       => $actions
    ];

    $finalResponseData = [
        'success'          => true,
        'status'           => 'committed',
        'message'          => 'Data successfully synchronized into records.',

        'proposalId'       => $proposalId,
        'proposalActionId' => $proposalActionId,
        'pc'               => $pc,
        'actionUnix'       => $actionUnix,

        'entityId'         => $entityId,
        'locationId'       => $locationId,
        'contactId'        => $contactId,

        'entityName'       => $entityName,
        'contactName'      => $contactName,

        'latitude'         => $latitude,
        'longitude'        => $longitude,

        'artifacts'        => $promotedArtifacts ?? [],

        'governingObjectId' => $governingObjectId,

        'governingObjectType' => in_array(
            $pc,
            ['PC-4', 'PC-5'],
            true
        ) ? 'location' : 'contact'
    ];

    $finalResponseData['clientInstructions'] =
        buildCommitClientInstructions(
            $pc,
            $entityName,
            $contactName,
            $payload['location'] ?? [],
            $entityId,
            $locationId,
            $contactId
        );

    $stmt = $db->prepare("
        INSERT INTO tblActions (
            contactId, actionTypeId, actionUnix, activitySessionId,
            promptText, responseText, actionPayloadData, actionResponseData,
            actionOrigin, intent, intentConfidence,
            latitude, longitude, ipAddress, userAgent
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $actorContactId,
        14,
        $actionUnix,
        $activitySessionId,
        $promptText,
        "proposal_committed_successfully",
        json_encode($actionPayload, JSON_UNESCAPED_SLASHES),
        json_encode($finalResponseData, JSON_UNESCAPED_SLASHES),
        $actionOrigin,
        $intent,
        $intentConfidence,
        $latitude,
        $longitude,
        $ipAddress,
        $userAgent
    ]);

    $db->commit();

    // Cleanup
    $snapshotPath = __DIR__ . "/../data/runtimeEphemeral/proposals/{$proposalId}.json";
    if (is_file($snapshotPath)) @unlink($snapshotPath);

    $response = array_merge($response, $finalResponseData);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();

    foreach (array_reverse($artifactMoves) as $move) {
        if (is_file($move['to'])) @rename($move['to'], $move['from']);
    }

    $response['error'] = 'Commit processing failed: ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion

#region SECTION 05 — Single Responsibility Insertion Units

/**
 * Build the canonical UI instructions for a committed proposal.
 */
function buildCommitClientInstructions(
    string $pc,
    string $entityName,
    string $contactName,
    array $locationData,
    ?int $entityId,
    ?int $locationId,
    ?int $contactId
): array {
    $city = trim((string)(
        $locationData['locationCity']
        ?? ''
    ));

    $locationText = $city !== ''
        ? "its {$city} location"
        : 'its location';

    $narrative = match ($pc) {
        'PC-1' => sprintf(
            '%s, %s, and %s were created and linked successfully. ' .
            'Parcel and zoning details were saved with the location.',
            $entityName,
            $locationText,
            $contactName
        ),

        'PC-2' => sprintf(
            'A new location and contact were created and linked to %s.',
            $entityName
        ),

        'PC-3' => sprintf(
            '%s was created and linked to the existing %s location.',
            $contactName,
            $entityName
        ),

        'PC-4' => sprintf(
            'A new location was created and linked to %s.',
            $entityName
        ),

        'PC-5' => sprintf(
            'The existing contact and location relationships for %s were updated.',
            $contactName
        ),

        'PC-6' => sprintf(
            'The former contact record was retired and a replacement record for %s was created.',
            $contactName
        ),

        default =>
            'The proposal was accepted and committed successfully.'
    };

    $references = [];

    if ($entityId !== null && $entityId > 0) {
        $references[] = [
            'label' => 'Entity',
            'id' => $entityId
        ];
    }

    if ($locationId !== null && $locationId > 0) {
        $references[] = [
            'label' => 'Location',
            'id' => $locationId
        ];
    }

    if ($contactId !== null && $contactId > 0) {
        $references[] = [
            'label' => 'Contact',
            'id' => $contactId
        ];
    }

    return [
        'action' => 'replace_proposal',
        'template' => 'proposal_commit_receipt',
        'removeProposalControls' => true,
        'tone' => 'success',
        'icon' => 'robot',
        'badgeText' => "{$pc} COMMITTED",
        'title' => 'Proposal accepted and committed',
        'narrative' => $narrative,
        'recordReferences' => $references
    ];
}

function insertEntity(
    PDO $db,
    array $entityData,
    array $locationData,
    array $narrativeData,
    int $proposalActionId
): int
{
    // Entity Name
    $entityName = trim(
        (string)(
            $entityData['entityName']
            ?? $entityData['entityNameRaw']
            ?? ''
        )
    );

    // Entity State
    // Current proposal classes assume:
    // Entity Location == Contact Location.
    $entityState = strtoupper(trim(
        (string)(
            $locationData['locationState']
            ?? ''
        )
    ));

    // Entity Narrative
    $contentLine = trim(
        (string)(
            $narrativeData['contentLine']
            ?? ''
        )
    );

    // Entity Note
    $entityNote =
        "Skyesoft Record Provenance\n\n" .
        "Originating Proposal Action : #{$proposalActionId}";

    if ($contentLine !== '') {
        $entityNote .= "\n\nSummary:\n{$contentLine}";
    }

    $stmt = $db->prepare("
        INSERT INTO tblEntities (
            entityName,
            entityLegalName,
            entityNormalizedName,
            entityState,
            entityStatus,
            entityIsVerified,
            entityVerificationSource,
            entityVerifiedUnix,
            entityType,
            entityNote,
            entityDate
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?, ?, UNIX_TIMESTAMP()
        )
    ");

    $stmt->execute([
        $entityName,
        $entityName,
        strtolower($entityName),
        $entityState !== '' ? $entityState : null,
        'Active',
        1,
        'Skyesoft Proposal',
        'company',
        $entityNote
    ]);

    $entityId = (int)$db->lastInsertId();

    if ($entityId <= 0) {
        throw new RuntimeException('Entity insert failed.');
    }

    return $entityId;
}

function insertLocation(
    PDO $db, 
    array $locationData, 
    array $entityData, 
    array $narrativeData, 
    int $entityId,
    int $proposalActionId
): int
{
    // Accepted Parcel (primary source for assessor data)
    $acceptedParcel = $locationData['parcelDetails'][0] ?? [];

    // =====================================================
    // locationName — Prioritized meaningful name
    // =====================================================
    $locationName = trim((string)($locationData['locationName'] ?? ''));

    if ($locationName === '') {
        // 1. Try Google Place / Location Name
        $locationName = trim((string)(
            $locationData['locationPlaceName'] 
            ?? $locationData['name'] 
            ?? ''
        ));

        // 2. Fallback: Entity - Address
        if ($locationName === '') {
            $entityName = trim((string)(
                $entityData['entityName']
                ?? $entityData['entityNameRaw']
                ?? $locationData['entityName']
                ?? ''
            ));

            $address = trim((string)(
                $locationData['locationAddress'] 
                ?? $locationData['address'] 
                ?? ''
            ));

            if ($entityName !== '' && $address !== '') {
                $locationName = $entityName . ' - ' . $address;
            } elseif ($entityName !== '') {
                $locationName = $entityName . ' Location';
            } else {
                $locationName = 'New Location';
            }
        }
    }

    // =====================================================
    // locationNote — Provenance
    // =====================================================
    $contentLine = trim((string)(
        $narrativeData['contentLine'] 
        ?? $locationData['summary'] 
        ?? ''
    ));

    $locationNote = 
        "Skyesoft Record Provenance\n\n" .
        "Originating Proposal Action : #" . $proposalActionId;

    if ($contentLine !== '') {
        $locationNote .= "\n\nSummary:\n" . $contentLine;
    }

    // =====================================================
    // INSERT
    // =====================================================
    $stmt = $db->prepare("
        INSERT INTO tblLocations (
            locationEntityId,
            locationName,
            locationAddress,
            locationAddressSuite,
            locationCity,
            locationState,
            locationZip,
            locationPlaceId,
            locationLatitude,
            locationLongitude,
            locationCounty,
            locationCountyFips,
            locationJurisdiction,
            locationParcelNumber,
            locationParcelNumberRaw,
            locationHasMultipleParcels,
            locationParcelCount,
            locationNote,
            locationDate
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())
    ");

    $stmt->execute([
        $entityId,
        $locationName,
        $locationData['locationAddress'] ?? '',
        $locationData['locationSuite'] ?? '',
        $locationData['locationCity'] ?? '',
        $locationData['locationState'] ?? '',
        $locationData['locationZip'] ?? '',
        $locationData['locationPlaceId'] ?? null,
        $locationData['locationLatitude'] ?? null,
        $locationData['locationLongitude'] ?? null,
        $locationData['locationCounty'] ?? null,
        $locationData['locationCountyFips'] ?? null,
        $locationData['jurisdictionName'] ?? null,
        $acceptedParcel['parcelNumber'] ?? null,
        $acceptedParcel['parcelNumber'] ?? null,
        ($locationData['hasMultipleParcels'] ?? false) ? 1 : 0,
        $locationData['parcelCount'] ?? 1,
        $locationNote
    ]);

    $locationId = (int)$db->lastInsertId();

    if ($locationId <= 0) {
        throw new RuntimeException('Location insert failed.');
    }

    // =====================================================
    // Populate Parcel Details with Assessor and Zoning Data
    // =====================================================
    $parcelRecord = is_array($acceptedParcel['parcelRecord'] ?? null)
        ? $acceptedParcel['parcelRecord']
        : [];

    $parcelZoning = is_array($acceptedParcel['zoning'] ?? null)
        ? $acceptedParcel['zoning']
        : [];

    $apnRaw = trim((string)(
        $parcelRecord['apnRaw']
        ?? $acceptedParcel['parcelNumber']
        ?? ''
    ));

    if ($apnRaw !== '') {
        $stmt = $db->prepare("
            INSERT INTO tblLocationParcelDetails (
                locationId,
                apnRaw,
                ownerName,
                subdivision,
                lotSize,
                yearBuilt,
                zoningCode,
                zoningDescription,
                zoningSource,
                zoningVerifiedAt,
                source,
                confidence,
                createdAt,
                updatedAt
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                UNIX_TIMESTAMP(),
                NULL
            )
        ");

        $stmt->execute([
            $locationId,
            $apnRaw,
            $parcelRecord['ownerName']
                ?? $acceptedParcel['ownerName']
                ?? null,
            $parcelRecord['subdivision'] ?? null,
            $parcelRecord['lotSize'] ?? null,
            $parcelRecord['yearBuilt'] ?? null,
            $parcelRecord['zoningCode']
                ?? $parcelZoning['zoningCode']
                ?? null,
            $parcelRecord['zoningDescription']
                ?? $parcelZoning['zoningDescription']
                ?? null,
            $parcelRecord['zoningSource']
                ?? $parcelZoning['zoningSource']
                ?? null,
            $parcelRecord['zoningVerifiedAt']
                ?? $parcelZoning['zoningVerifiedAt']
                ?? null,
            $parcelRecord['source']
                ?? 'maricopa_assessor',
            (int)(
                $parcelRecord['confidence']
                ?? $parcelZoning['confidence']
                ?? 95
            )
        ]);
    }

    return $locationId;
}

function insertContact(
    PDO $db, 
    array $contactData, 
    array $narrativeData, 
    int $entityId, 
    int $locationId,
    int $proposalActionId
): int
{
    // Contact Email
    $email = trim((string)($contactData['contactEmail'] ?? ''));

    // =====================================================
    // Contact Note — Provenance (consistent pattern)
    // =====================================================
    $contentLine = trim((string)(
        $narrativeData['contentLine'] 
        ?? ''
    ));

    $contactNote = 
        "Skyesoft Record Provenance\n\n" .
        "Originating Proposal Action : #" . $proposalActionId;

    if ($contentLine !== '') {
        $contactNote .= "\n\nSummary:\n" . $contentLine;
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
            contactPrimaryPhoneRaw,
            contactEmail,
            contactEmailNormalized,
            contactNote,
            isActive,
            contactDate,
            contactCreatedAt,
            contactUpdatedAt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
    ");

    $stmt->execute([
        $entityId,
        $locationId,
        $contactData['contactSalutation'] ?? null,
        $contactData['contactFirstName'] ?? '',
        $contactData['contactLastName'] ?? '',
        $contactData['contactTitle'] ?? null,
        $contactData['contactPrimaryPhone'] ?? null,
        $contactData['contactPrimaryPhoneRaw'] ?? null,
        $email,
        $email ? strtolower($email) : null,
        $contactNote
    ]);

    $contactId = (int)$db->lastInsertId();

    if ($contactId <= 0) {
        throw new RuntimeException('Contact insert failed.');
    }

    return $contactId;
}

function retireContact(PDO $db, int $contactId): void 
{
    $stmt = $db->prepare("UPDATE tblContacts SET isRetired = 1, retiredAt = NOW() WHERE contactId = ?");
    $stmt->execute([$contactId]);
}

function promoteArtifacts(string $proposalId, int $governingObjectId, array $artifacts, array &$artifactMoves): array 
{
    $promoted = [];
    $artifactsDir = __DIR__ . '/../artifacts';

    $proposalSegment = str_pad(preg_replace('/[^0-9]/', '', $proposalId), 6, '0', STR_PAD_LEFT);
    
    // Governing Object Identifier
    // Current PC-1 promotes location artifacts using the
    // governing record associated with the proposal.
    $recordSegment = str_pad(
        (string)$governingObjectId,
        6,
        '0',
        STR_PAD_LEFT
    );

    foreach ($artifacts as $key => $tempPath) {
        if (!is_string($tempPath) || trim($tempPath) === '') {
            continue;
        }

        $filename = basename($tempPath);

        $pattern = '/^TMP-([A-Z]{3})-([A-Z]{3})-' . preg_quote($proposalSegment, '/') . '-([0-9]{3})-([0-9]{10})-([0-9]{3})\.([a-z0-9]+)$/';

        if (!preg_match($pattern, $filename, $matches)) {
            continue;
        }

        // For location-based artifacts (street view, parcel map, satellite), use Location ID
        // For contact-specific artifacts, use Contact ID
        $newName = sprintf(
            'REC-%s-%s-%s-%s-%s-%s.%s',
            $matches[1],
            $matches[2],
            $recordSegment,           // This is the governing object ID (Location ID for maps)
            $matches[3],
            $matches[4],
            $matches[5],
            $matches[6]
        );

        $sourceFile = $artifactsDir . '/' . $filename;
        $targetFile = $artifactsDir . '/' . $newName;

        if (!is_file($sourceFile)) {
            throw new RuntimeException("TMP artifact missing: {$filename}");
        }

        if (!rename($sourceFile, $targetFile)) {
            throw new RuntimeException("Artifact promotion failed: {$filename}");
        }

        $artifactMoves[] = [
            'from' => $sourceFile,
            'to'   => $targetFile
        ];

        $promoted[$key] = '/skyesoft/artifacts/' . $newName;
    }

    return $promoted;
}

#endregion