<?php
declare(strict_types=1);

/**
 * Skyesoft — commitProposal.php
 * Deterministic Commit Engine Layer
 * 
 * File Version:     1.2.0
 * Schema Version:   2.1.1
 * Last Updated:     2026-07-13
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

$proposalId = trim((string)($inputData['proposalId'] ?? ''));
$actionId   = isset($inputData['actionId']) ? (int)$inputData['actionId'] : null;

if (empty($proposalId)) {
    echo json_encode(['success' => false, 'error' => 'Missing proposalId']);
    exit;
}

$db = getPDO();
if (!$db) {
    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
    exit;
}

$actorContactId = (int)($_SESSION['contactId'] ?? 1);   // fallback to real contact

#endregion

#region SECTION 02 — Load Snapshot Context

$snapshot = null;

// Prefer authoritative actionResponseData from audit trail first
if ($actionId !== null) {
    $stmt = $db->prepare("SELECT actionResponseData FROM tblActions WHERE actionId = ?");
    $stmt->execute([$actionId]);
    $dbRow = $stmt->fetchColumn();
    if ($dbRow) {
        $snapshot = json_decode($dbRow, true);
    }
}

// Fallback to ephemeral workspace snapshot
if (!$snapshot) {
    $snapshotPath = __DIR__ . "/../data/runtimeEphemeral/proposals/{$proposalId}.json";
    if (is_file($snapshotPath)) {
        $snapshot = json_decode(file_get_contents($snapshotPath), true);
    }
}

if (!$snapshot) {
    echo json_encode(['success' => false, 'error' => 'Proposal metadata could not be resolved']);
    exit;
}

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

    $entityId   = $snapshot['databaseResolution']['entity']['entityId'] ?? null;
    $locationId = $snapshot['databaseResolution']['location']['locationId'] ?? null;
    $contactId  = $snapshot['databaseResolution']['contact']['contactId'] ?? null;

    foreach ($actions as $action) {
        switch ($action) {
            case 'insert_entity':
                $entityId = insertEntity($db, $payload['entity'] ?? []);
                break;

            case 'insert_location':
                $locationId = insertLocation($db, $payload['location'] ?? [], (int)$entityId);
                break;

            case 'insert_contact':
            case 'insert_replacement_contact':
                $contactId = insertContact($db, $payload['contact'] ?? [], (int)$entityId, (int)$locationId);
                break;

            case 'retire_contact':
                if ($contactId) {
                    retireContact($db, (int)$contactId);
                }
                break;

            case 'link_entity':
                $entityId = (int)($commitPlan['entity']['entityId'] ?? $snapshot['databaseResolution']['entity']['entityId'] ?? 0);
                break;

            case 'link_location':
                $locationId = (int)($commitPlan['location']['locationId'] ?? $snapshot['databaseResolution']['location']['locationId'] ?? 0);
                break;

            case 'link_elc':
            case 'link_existing_elc':
                // Relational join logic placeholder for future expansion
                break;
        }
    }

    $governingObjectId = in_array($pc, ['PC-4', 'PC-5'], true) ? (int)$locationId : (int)$contactId;

    $reportArtifacts = $snapshot['reportArtifacts'] ?? $snapshot['artifactRegistry'] ?? [];
    $promotedArtifacts = promoteArtifacts($proposalId, $governingObjectId, $reportArtifacts, $artifactMoves);

    // Audit Log — Full request/response symmetry
    $actionPayload = [
        'proposalId' => $proposalId,
        'actionId'   => $actionId,
        'pc'         => $pc
    ];

    $finalResponseData = [
        'success'    => true,
        'contactId'  => $contactId,
        'entityId'   => $entityId,
        'locationId' => $locationId,
        'artifacts'  => $promotedArtifacts,
        'proposalId' => $proposalId
    ];

    $stmt = $db->prepare("
        INSERT INTO tblActions (
            contactId, actionTypeId, actionUnix, activitySessionId, 
            promptText, responseText, actionPayloadData, actionResponseData
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $actorContactId,
        14, // Commit Success
        time(),
        $snapshot['activitySessionId'] ?? '',
        "Committed Proposal #{$proposalId}",
        "proposal_committed_successfully",
        json_encode($actionPayload, JSON_UNESCAPED_SLASHES),
        json_encode($finalResponseData, JSON_UNESCAPED_SLASHES)
    ]);

    $db->commit();

    // Cleanup ephemeral workspace
    $snapshotPath = __DIR__ . "/../data/runtimeEphemeral/proposals/{$proposalId}.json";
    if (is_file($snapshotPath)) {
        @unlink($snapshotPath);
    }

    $response = array_merge($response, $finalResponseData);
    $response['message'] = 'Data successfully synchronized into records.';

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Filesystem rollback
    foreach (array_reverse($artifactMoves) as $move) {
        if (is_file($move['to'])) {
            @rename($move['to'], $move['from']);
        }
    }

    $response['error'] = 'Commit processing failed: ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#endregion

#region SECTION 99 — Single Responsibility Insertion Units

function insertEntity(PDO $db, array $data): int 
{
    $entityName = $data['entityName'] ?? $data['entityNameRaw'] ?? '';

    $stmt = $db->prepare("INSERT INTO tblEntities (entityName) VALUES (?)");
    $stmt->execute([$entityName]);

    $newId = (int)$db->lastInsertId();

    if ($newId <= 0) {
        throw new RuntimeException('Entity insert failed.');
    }

    return $newId;
}

function insertLocation(PDO $db, array $data, int $entityId): int
{
    $parcel = $data['parcelDetails'][0] ?? [];

    $stmt = $db->prepare("
        INSERT INTO tblLocations (
            locationEntityId,
            locationName,
            locationAddress,
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
            locationDate
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())
    ");

    $stmt->execute([
        $entityId,
        $data['locationName'] ?? '',
        $data['locationAddress'] ?? '',
        $data['locationCity'] ?? '',
        $data['locationState'] ?? '',
        $data['locationZip'] ?? '',
        $data['locationPlaceId'] ?? null,
        $data['locationLatitude'] ?? null,
        $data['locationLongitude'] ?? null,
        $data['locationCounty'] ?? null,
        $data['locationCountyFips'] ?? null,
        $data['jurisdictionName'] ?? null,
        $parcel['parcelNumber'] ?? null
    ]);

    $locationId = (int)$db->lastInsertId();

    if ($locationId <= 0) {
        throw new RuntimeException('Location insert failed.');
    }

    return $locationId;
}

function insertContact(PDO $db, array $data, int $entityId, int $locationId): int
{
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())
    ");

    $stmt->execute([
        $entityId,
        $locationId,
        $data['contactSalutation'] ?? null,
        $data['contactFirstName'] ?? '',
        $data['contactLastName'] ?? '',
        $data['contactTitle'] ?? null,
        $data['contactPrimaryPhone'] ?? null,
        strtolower(trim($data['contactEmail'] ?? ''))
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
    $recordSegment   = str_pad((string)$governingObjectId, 6, '0', STR_PAD_LEFT);

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
            throw new RuntimeException("TMP artifact missing: {$filename}");
        }

        if (!rename($sourceFile, $targetFile)) {
            throw new RuntimeException("Artifact promotion failed: {$filename}");
        }

        // Track successful filesystem execution for safety rollbacks
        $artifactMoves[] = [
            'from' => $sourceFile,
            'to'   => $targetFile
        ];

        $promoted[$key] = '/skyesoft/artifacts/' . $newName;
    }

    return $promoted;
}

#endregion