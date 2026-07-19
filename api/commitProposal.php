<?php
declare(strict_types=1);

/**
 * Skyesoft — commitProposal.php
 * Deterministic Commit Engine Layer
 * 
 * File Version:     1.4.2
 * Schema Version:   2.2.0
 * Last Updated:     2026-07-19
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

// Force session reload
session_write_close();
session_start();

error_log('[COMMIT] session_id = ' . session_id());
error_log('[COMMIT] SESSION = ' . json_encode($_SESSION));
error_log('[COMMIT] contactId from session = ' . ($_SESSION['contactId'] ?? 'MISSING'));

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

// Require valid proposalActionId for provenance and audit integrity
if ($proposalActionId === null || $proposalActionId <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid proposalActionId'
    ]);
    exit;
}

$db = getPDO();
if (!$db) {
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
    exit;
}

$actorContactId = (int)($_SESSION['contactId'] ?? 0);

if ($actorContactId <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'An authenticated operator is required.'
    ]);
    exit;
}

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
// Proposal ID Binding Verification
// =====================================================
$snapshotProposalId = trim((string)(
    $snapshot['proposalId']
    ?? ''
));

if (
    $snapshotProposalId === ''
    || !hash_equals($snapshotProposalId, $proposalId)
) {
    error_log(
        '[COMMIT] Proposal identity mismatch. Requested=' .
        $proposalId .
        ' Snapshot=' .
        $snapshotProposalId
    );

    echo json_encode([
        'success' => false,
        'error' =>
            'The proposal ID does not match the originating proposal action.'
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

$commitPlan = is_array(
    $snapshot['commitPlan']
    ?? $snapshot['persistence']
    ?? null
)
    ? (
        $snapshot['commitPlan']
        ?? $snapshot['persistence']
    )
    : [];

$actions = is_array($commitPlan['actions'] ?? null)
    ? array_values($commitPlan['actions'])
    : [];

$payload = is_array($snapshot['data'] ?? null)
    ? $snapshot['data']
    : [];

$pc = trim((string)(
    $snapshot['pcm']['pc']
    ?? ''
));

$rsList = is_array($snapshot['pcm']['rs'] ?? null)
    ? array_values($snapshot['pcm']['rs'])
    : [];

$canCommit = (
    $commitPlan['canCommit']
    ?? false
) === true;

// =====================================================
// Commit Authorization
// =====================================================
if (!$canCommit) {
    echo json_encode([
        'success' => false,
        'error' =>
            'Proposal is not authorized for commitment.'
    ]);
    exit;
}

// =====================================================
// No-Action Proposal Class
// =====================================================
if ($pc === 'PC-0') {
    echo json_encode([
        'success' => false,
        'error' =>
            'PC-0 represents an existing ELC relationship ' .
            'and requires no database commitment.'
    ]);
    exit;
}

// =====================================================
// Governance Status
// =====================================================
if ($rsList !== ['RS-0']) {
    echo json_encode([
        'success' => false,
        'error' =>
            'Proposal contains unresolved governance conditions.'
    ]);
    exit;
}

// =====================================================
// PC-6 Contact Succession Identity Validation
// =====================================================
if ($pc === 'PC-6') {
    $existingContactId = (int)(
        $commitPlan['contact']['contactId']
        ?? $snapshot['databaseResolution']['contact']['contactId']
        ?? 0
    );

    if ($existingContactId <= 0) {
        echo json_encode([
            'success' => false,
            'error' =>
                'PC-6 requires an existing contact record.'
        ]);
        exit;
    }

    $stmt = $db->prepare("
        SELECT
            contactId,
            contactFirstName,
            contactLastName,
            contactEmailNormalized
        FROM tblContacts
        WHERE contactId = ?
        LIMIT 1
    ");

    $stmt->execute([$existingContactId]);
    $existingContact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingContact) {
        echo json_encode([
            'success' => false,
            'error' =>
                'The existing PC-6 contact record could not be found.'
        ]);
        exit;
    }

    $normalizeIdentityValue = static function (mixed $value): string {
        $value = strtolower(trim((string)$value));
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    };

    $submittedFirstName = $normalizeIdentityValue(
        $payload['contact']['contactFirstName'] ?? ''
    );

    $submittedLastName = $normalizeIdentityValue(
        $payload['contact']['contactLastName'] ?? ''
    );

    $submittedEmail = strtolower(trim((string)(
        $payload['contact']['contactEmailNormalized']
        ?? $payload['contact']['contactEmail']
        ?? ''
    )));

    $existingFirstName = $normalizeIdentityValue(
        $existingContact['contactFirstName'] ?? ''
    );

    $existingLastName = $normalizeIdentityValue(
        $existingContact['contactLastName'] ?? ''
    );

    $existingEmail = strtolower(trim((string)(
        $existingContact['contactEmailNormalized']
        ?? ''
    )));

    if (
        $submittedFirstName === ''
        || $submittedLastName === ''
        || $submittedEmail === ''
        || $existingEmail === ''
        || $submittedFirstName !== $existingFirstName
        || $submittedLastName !== $existingLastName
        || !hash_equals($existingEmail, $submittedEmail)
    ) {
        error_log(
            '[COMMIT] PC-6 blocked by contact identity conflict. ' .
            'ExistingContactId=' . $existingContactId
        );

        echo json_encode([
            'success' => false,
            'status' => 'blocked',
            'rs' => ['RS-5'],
            'reason' => 'contact_identity_conflict',
            'error' =>
                'Commit blocked: the submitted email belongs to ' .
                'a different contact identity.'
        ]);
        exit;
    }
}

// =====================================================
// Linked Record Validation for Dependent Classes
// =====================================================
if (
    in_array($pc, ['PC-2', 'PC-3', 'PC-4', 'PC-6'], true)
    && (int)(
        $commitPlan['entity']['entityId']
        ?? $snapshot['databaseResolution']['entity']['entityId']
        ?? 0
    ) <= 0
) {
    echo json_encode([
        'success' => false,
        'error' => "{$pc} requires an existing entity."
    ]);
    exit;
}

if (
    $pc === 'PC-3'
    && (int)(
        $commitPlan['location']['locationId']
        ?? $snapshot['databaseResolution']['location']['locationId']
        ?? 0
    ) <= 0
) {
    echo json_encode([
        'success' => false,
        'error' => 'PC-3 requires an existing location.'
    ]);
    exit;
}

// =====================================================
// Replay Protection — Check for Prior Commitment
// =====================================================
$stmt = $db->prepare("
    SELECT actionId
    FROM tblActions
    WHERE actionTypeId = 14
      AND JSON_UNQUOTE(
          JSON_EXTRACT(
              actionPayloadData,
              '$.proposalActionId'
          )
      ) = ?
    LIMIT 1
");

$stmt->execute([(string)$proposalActionId]);

if ($stmt->fetchColumn()) {
    echo json_encode([
        'success' => false,
        'status' => 'already_committed',
        'error' =>
            'This proposal has already been committed.'
    ]);
    exit;
}

// =====================================================
// Supported Commit Classes + Exact Plan Validation
// =====================================================
$supportedCommitClasses = [
    'PC-1', 'PC-2', 'PC-3', 'PC-4', 'PC-5', 'PC-6'
];

if (!in_array($pc, $supportedCommitClasses, true)) {
    echo json_encode([
        'success' => false,
        'error' =>
            'Commit engine does not currently support ' .
            "proposal class {$pc}."
    ]);
    exit;
}

$expectedActionsByPc = [
    'PC-1' => ['insert_entity', 'insert_location', 'insert_contact', 'link_elc'],
    'PC-2' => ['link_entity', 'insert_location', 'insert_contact', 'link_elc'],
    'PC-3' => ['link_entity', 'link_location', 'insert_contact', 'link_elc'],
    'PC-4' => ['link_entity', 'insert_location'],
    'PC-5' => ['insert_entity', 'insert_location'],
    'PC-6' => ['link_entity', 'insert_location', 'retire_contact', 'insert_replacement_contact', 'link_elc']
];

$expectedActions = $expectedActionsByPc[$pc] ?? [];

if ($actions !== $expectedActions) {
    echo json_encode([
        'success' => false,
        'error' =>
            "The operational commit plan does not match {$pc}.",
        'expectedActions' => $expectedActions,
        'actualActions' => $actions
    ]);
    exit;
}

// =====================================================
// Operational Commit Plan
// =====================================================
if (empty($actions)) {
    echo json_encode([
        'success' => false,
        'error' =>
            'No operational commit actions were designated.'
    ]);
    exit;
}

#endregion

#region SECTION 04 — Execution Engine & Orchestration

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
            $existingContactId = (int)(
                $commitPlan['contact']['contactId']
                ?? $snapshot['databaseResolution']['contact']['contactId']
                ?? 0
            );

            if ($existingContactId <= 0) {
                throw new RuntimeException(
                    'PC-6 could not resolve the contact being retired.'
                );
            }

            retireContact($db, $existingContactId);
            break;

        case 'link_entity':
            $entityId = (int)($commitPlan['entity']['entityId'] ?? $snapshot['databaseResolution']['entity']['entityId'] ?? 0);
            break;

        case 'link_location':
            $locationId = (int)($commitPlan['location']['locationId'] ?? $snapshot['databaseResolution']['location']['locationId'] ?? 0);
            break;

        case 'link_elc':
            // Relationship established via foreign keys in insertContact()
            break;

        default:
            throw new RuntimeException(
                'Unsupported commit action: ' . (string)$action
            );
        }
    }

    $governingObjectId = in_array($pc, ['PC-4','PC-5'], true) ? (int)$locationId : (int)$contactId;

    $reportArtifacts = $snapshot['reportArtifacts'] ?? $snapshot['artifactRegistry'] ?? [];
    error_log("[COMMIT] Report Artifacts count: " . count($reportArtifacts));
    $promotedArtifacts = promoteArtifacts($proposalId, $governingObjectId, $reportArtifacts, $artifactMoves);

    // =====================================================
    // Commit Audit Context Initialization
    // =====================================================
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

    $latitude  = $snapshot['data']['location']['locationLatitude']  ?? 
                $payload['location']['locationLatitude'] ?? null;
    $longitude = $snapshot['data']['location']['locationLongitude'] ?? 
                $payload['location']['locationLongitude'] ?? null;

    if ($latitude !== null)  $latitude  = (float)$latitude;
    if ($longitude !== null) $longitude = (float)$longitude;

    $ipAddress         = $snapshot['ipAddress'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $userAgent         = $snapshot['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);

    $actionOrigin      = 0;
    $intent            = 'proposal_commit';
    $intentConfidence  = 1.0000;
    $actionUnix        = time();

    $promptText = sprintf(
        'Accepted proposal for %s (%s)',
        $contactName,
        $entityName ?: 'Unknown Entity'
    );

    error_log('[COMMIT] Audit Context - Session: ' . $activitySessionId);
    error_log('[COMMIT] Audit Context - Prompt: ' . $promptText);
    error_log('[COMMIT] Audit Context - Location: ' . json_encode(['lat' => $latitude, 'lng' => $longitude]));

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
        'retiredContactId' => $pc === 'PC-6'
            ? (int)(
                $commitPlan['contact']['contactId']
                ?? $snapshot['databaseResolution']['contact']['contactId']
                ?? 0
            )
            : null,

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
            '%s and %s were created successfully. ' .
            'Parcel and zoning details were saved with the location.',
            $entityName,
            $locationText
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
    $entityName = trim(
        (string)(
            $entityData['entityName']
            ?? $entityData['entityNameRaw']
            ?? ''
        )
    );

    $entityState = strtoupper(trim(
        (string)(
            $locationData['locationState']
            ?? ''
        )
    ));

    $contentLine = trim(
        (string)(
            $narrativeData['contentLine']
            ?? ''
        )
    );

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
    $acceptedParcel = $locationData['parcelDetails'][0] ?? [];

    $locationName = trim((string)($locationData['locationName'] ?? ''));

    if ($locationName === '') {
        $locationName = trim((string)(
            $locationData['locationPlaceName'] 
            ?? $locationData['name'] 
            ?? ''
        ));

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
    // -------------------------------------------------
    // Canonical Identity
    // -------------------------------------------------
    $email = trim((string)(
        $contactData['contactEmail'] ?? ''
    ));

    $emailNormalized = trim((string)(
        $contactData['contactEmailNormalized']
        ?? ''
    ));

    if ($emailNormalized === '' && $email !== '') {
        $emailNormalized = strtolower($email);
    }

    // -------------------------------------------------
    // Provenance
    // -------------------------------------------------
    $contentLine = trim((string)(
        $narrativeData['contentLine'] ?? ''
    ));

    $contactNote =
        "Skyesoft Record Provenance\n\n" .
        "Originating Proposal Action : #{$proposalActionId}";

    if ($contentLine !== '') {
        $contactNote .= "\n\nSummary:\n{$contentLine}";
    }

    // -------------------------------------------------
    // Insert
    // -------------------------------------------------
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
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            1,
            UNIX_TIMESTAMP(),
            UNIX_TIMESTAMP(),
            UNIX_TIMESTAMP()
        )
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
        $email !== '' ? $email : null,
        $emailNormalized !== '' ? $emailNormalized : null,
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
    $stmt = $db->prepare("
        UPDATE tblContacts
        SET
            isActive = 0,
            isRetired = 1,
            retiredAt = NOW(),
            contactUpdatedAt = UNIX_TIMESTAMP()
        WHERE contactId = ?
          AND COALESCE(isRetired, 0) = 0
    ");

    $stmt->execute([$contactId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'The existing contact could not be retired or was already retired.'
        );
    }
}

function promoteArtifacts(string $proposalId, int $governingObjectId, array $artifacts, array &$artifactMoves): array 
{
    $promoted = [];
    $artifactsDir = __DIR__ . '/../artifacts';

    $proposalSegment = str_pad(preg_replace('/[^0-9]/', '', $proposalId), 6, '0', STR_PAD_LEFT);
    
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

        $newName = sprintf(
            'REC-%s-%s-%s-%s-%s-%s.%s',
            $matches[1],
            $matches[2],
            $recordSegment,
            $matches[3],
            $matches[4],
            $matches[5],
            $matches[6]
        );

        $sourceFile = $artifactsDir . '/' . $filename;
        $targetFile = $artifactsDir . '/' . $newName;

        if (!is_file($sourceFile)) {
            error_log("[COMMIT] WARNING: TMP artifact missing (skipping): {$filename}");
            continue; // Graceful skip instead of throw
        }

        if (!rename($sourceFile, $targetFile)) {
            error_log("[COMMIT] WARNING: Artifact promotion failed (skipping): {$filename}");
            continue;
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