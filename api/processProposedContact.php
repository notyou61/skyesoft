<?php
declare(strict_types=1);

/**
 * Skyesoft — processProposedContact.php
 * Main Orchestration + Proposal Report Generation
 * 
 * File Version:     1.6.4
 * Schema Version:   2.1.1
 * Last Updated:     2026-07-13
 */

#region SECTION 00 — Bootstrap & Request Initialization

error_log('[PPC] ====================================================');
error_log('[PPC] processProposedContact START ' . date('Y-m-d H:i:s'));
error_log('[PPC] ====================================================');

// =====================================================
// RUNTIME CONFIGURATION
// =====================================================

if (!headers_sent()) {
    header('Content-Type: application/json');
}

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// =====================================================
// DEPENDENCY LOADING + ENV
// =====================================================

require_once __DIR__ . '/utils/processProposedContact.utils.php';
require_once __DIR__ . '/askOpenAI.php';
require_once __DIR__ . '/utils/envLoader.php';
require_once __DIR__ . '/dbConnect.php';

skyesoftLoadEnv();

error_log('[PPC][SECTION-00] Bootstrap and Environment complete');

// =====================================================
// REQUEST CONTEXT
// =====================================================

$context = [
    'requestId'         => uniqid('ppc_', true),
    'startedAt'         => microtime(true),
    'activitySessionId' => '',
    'version'           => '2.1.1'   // Schema/Protocol version
];

// =====================================================
// INPUT CAPTURE
// =====================================================

$rawJson = file_get_contents('php://input');
$inputData = json_decode($rawJson, true) ?? [];

// =====================================================
// 🚨 INTERCEPT ROUTE: PROPOSAL DECLINE WORKFLOW
// =====================================================
if (isset($inputData['action']) && $inputData['action'] === 'decline') {

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $targetProposalId = trim($inputData['proposalId'] ?? '');

    $pdo = getPDO() ?? null;
    $displaySubject = '';
    $lat = $lon = null;
    $cachedSessionId = '';

    // 📍 SNAPSHOT RECOVERY — Authoritative for proposal lifecycle
    $snapshotDir = __DIR__ . '/../data/runtimeEphemeral/proposals';
    if (!empty($targetProposalId) && is_dir($snapshotDir)) {
        $targetedSnapshotPath = $snapshotDir . "/{$targetProposalId}.json";
        if (is_file($targetedSnapshotPath)) {
            $snapshotRaw = file_get_contents($targetedSnapshotPath);
            if ($snapshotRaw) {
                $snapshotData = json_decode($snapshotRaw, true) ?? [];

                $lat = $snapshotData['data']['location']['locationLatitude']
                    ?? $snapshotData['location']['latitude'] ?? null;
                $lon = $snapshotData['data']['location']['locationLongitude']
                    ?? $snapshotData['location']['longitude'] ?? null;

                $cachedSessionId = trim(
                    $snapshotData['activitySessionId']
                    ?? $snapshotData['data']['activitySessionId']
                    ?? $snapshotData['context']['activitySessionId']
                    ?? ''
                );
            }
        }
    }

    // ── Session ID Resolution (Proposal Snapshot first) ──
    $nativeSessionId = session_id();
    $incomingSession = trim($inputData['activitySessionId'] ?? '');

    if ($incomingSession === 'no_session') {
        $incomingSession = '';
    }

    if (!empty($cachedSessionId)) {
        $finalSessionId = $cachedSessionId;
    } elseif (!empty($nativeSessionId)) {
        $finalSessionId = $nativeSessionId;
    } elseif (!empty($incomingSession)) {
        $finalSessionId = $incomingSession;
    } else {
        error_log('[PPC][INTERCEPT] CRITICAL: Unable to resolve activitySessionId for decline');
        echo json_encode([
            'success' => false,
            'error'   => 'Unable to resolve proposal session. Please refresh and try again.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Propagate consistently
    $inputData['activitySessionId'] = $finalSessionId;
    $context['activitySessionId']   = $finalSessionId;

    error_log('[PPC][INTERCEPT] Decline action for Session: ' . $context['activitySessionId']);

    // Build display subject
    $entityName = trim($inputData['entityName'] ?? $inputData['data']['entity']['entityName'] ?? '');
    $firstName  = trim($inputData['data']['contact']['contactFirstName'] ?? '');
    $lastName   = trim($inputData['data']['contact']['contactLastName'] ?? '');
    $fullName   = trim($firstName . ' ' . $lastName);

    $displaySubject = !empty($fullName)
        ? "{$fullName} ({$entityName})"
        : (!empty($entityName) ? $entityName : "Proposal #{$targetProposalId}");

    // ── PURGE OPERATIONS ──
    $purgedSnapshots = 0;
    $purgedArtifacts = 0;

    // 1. Purge Proposal Snapshot(s)
    if (is_dir($snapshotDir)) {
        if (!empty($targetProposalId)) {
            $targetedPath = $snapshotDir . "/{$targetProposalId}.json";
            if (is_file($targetedPath)) {
                @unlink($targetedPath);
                $purgedSnapshots++;
            }
        }

        // Purge any other snapshots tied to this session
        if (!empty($context['activitySessionId'])) {
            $files = scandir($snapshotDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $filePath = $snapshotDir . '/' . $file;
                if (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'json') {
                    $content = file_get_contents($filePath);
                    if ($content && strpos($content, $context['activitySessionId']) !== false) {
                        @unlink($filePath);
                        $purgedSnapshots++;
                    }
                }
            }
        }
    }

    // 2. Purge Media Artifacts (TMP-IMG- files)
    $artifactsDir = __DIR__ . '/../artifacts';
    if (is_dir($artifactsDir) && !empty($targetProposalId)) {
        $artifacts = scandir($artifactsDir);
        foreach ($artifacts as $artifactFile) {
            if ($artifactFile === '.' || $artifactFile === '..') continue;
            if (strpos($artifactFile, 'TMP-IMG-') !== false && strpos($artifactFile, $targetProposalId) !== false) {
                $artifactPath = $artifactsDir . '/' . $artifactFile;
                if (is_file($artifactPath)) {
                    @unlink($artifactPath);
                    $purgedArtifacts++;
                }
            }
        }
    }

    error_log("[PPC][PURGE] Snapshots: {$purgedSnapshots} | Artifacts: {$purgedArtifacts}");

    // ── CANONICAL RESPONSE ──
    $response = [
        'success'           => true,
        'status'            => 'declined',
        'proposalId'        => $targetProposalId,
        'activitySessionId' => $context['activitySessionId'],
        'purgedSnapshots'   => $purgedSnapshots,
        'purgedArtifacts'   => $purgedArtifacts,
        'message'           => "❌ {$displaySubject} was declined. Session artifacts have been purged."
    ];

    // ── Audit Log (Action Type 10) ──
    if ($pdo) {
        try {
            $actionPayload = [
                'action'            => 'decline',
                'source'            => $inputData['source'] ?? 'ui_dashboard',
                'activitySessionId' => $context['activitySessionId'],
                'requestId'         => $context['requestId'],
                'proposalId'        => $targetProposalId
            ];

            $contactId = $inputData['data']['contact']['contactId']
                ?? $_SESSION['contactId']
                ?? null;

            $stmt = $pdo->prepare("
                INSERT INTO tblActions (
                    contactId, actionTypeId, actionUnix, activitySessionId,
                    promptText, responseText, actionPayloadData, actionResponseData,
                    intent, intentConfidence, ipAddress, latitude, longitude, userAgent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $contactId,
                10,
                time(),
                $context['activitySessionId'],
                "Decline proposal for {$displaySubject}",
                "proposal_declined_and_purged",
                json_encode($actionPayload, JSON_UNESCAPED_SLASHES),
                json_encode($response, JSON_UNESCAPED_SLASHES),   // Full response stored
                'contact_proposal_decline',
                1.00,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $lat,
                $lon,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            error_log('[PPC][ACTION-LOG] ✅ Decline tracked (Action Type 10) with full response');
        } catch (Throwable $e) {
            error_log('[PPC][ACTION-LOG] ❌ Decline log failed: ' . $e->getMessage());
        }
    }

    // Return the exact same response recorded in the audit log
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// =====================================================
// NORMAL PROPOSAL FLOW
// =====================================================

$proposalTypeInput = trim($inputData['proposalType'] ?? '');
$legacyType        = trim($inputData['type'] ?? '');

$proposalType = $proposalTypeInput !== ''
    ? $proposalTypeInput
    : (($legacyType === 'PC-4') ? 'location' : 'contact');

$isExplicitLocationOnlyIntent = ($proposalType === 'location');

$rawInput = trim($inputData['input'] ?? '');
$rawInputOriginal = $inputData['input'] ?? '';

// Session resolution for normal path (consistent philosophy)
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$nativeSessionId = session_id();
$context['activitySessionId'] = $nativeSessionId ?: trim($inputData['activitySessionId'] ?? '');

if (empty($context['activitySessionId'])) {
    error_log('[PPC] CRITICAL: No session could be resolved for proposal');
    echo json_encode([
        'success' => false,
        'error'   => 'Unable to resolve session. Please refresh and try again.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

error_log('[PPC] Request ID: ' . $context['requestId']);
error_log('[PPC] Activity Session ID: ' . $context['activitySessionId']);

#endregion

#region SECTION 01 — Runtime Services

// Re-fetch the PDO global instance smoothly since environment boot completed in Section 00
$pdo = getPDO() ?? null;

error_log('[PPC] Runtime services verified');

#endregion

#region SECTION 02 — Input Validation

if ($rawInput === '') {
    echo json_encode([
        'success' => false,
        'status'  => 'missing_input'
    ]);
    exit;
}

// Normalize line endings and drop redundant carriage returns
$normalizedInput = str_replace("\r\n", "\n", trim($rawInput));

// Split safely into non-empty lines without stripping valid string data
$lines = array_values(array_filter(explode("\n", $normalizedInput), function($line) {
    return trim($line) !== '';
}));

$fallbackFirstName = '';
$fallbackLastName  = '';
$fallbackTitle     = '';

if (!empty($lines[0])) {
    $line1 = trim($lines[0]);

    // Isolate title appended via comma or dash
    if (preg_match('/^(.*?)\s*[,\-]\s*(.+)$/', $line1, $matches)) {
        $namePart      = trim($matches[1]);
        $fallbackTitle = trim($matches[2]);
    } else {
        $namePart = $line1;
    }

    // Split names reliably on any spacing variant
    $namePieces = preg_split('/\s+/', $namePart);

    if (count($namePieces) >= 2) {
        $fallbackFirstName = array_shift($namePieces);
        $fallbackLastName  = implode(' ', $namePieces);
    } elseif (count($namePieces) === 1) {
        $fallbackFirstName = $namePieces[0];
    }
}

error_log("[PPC] Fallback Parser → Name: '{$fallbackFirstName} {$fallbackLastName}' | Title: '{$fallbackTitle}'");

#endregion

#region SECTION 03 — Proposal Parser Dispatch (Architectural Branch Point)

$proposalType = $proposalType ?? 'contact';   // Set in Section 00

error_log("[PPC][SECTION-02.5] Proposal Type detected: {$proposalType}");

$parsed = [
    'entity'   => ['name' => ''],
    'contact'  => [
        'firstName' => '', 'lastName' => '', 'salutation' => '', 'title' => '',
        'primaryPhone' => '', 'primaryPhoneRaw' => '', 'email' => ''
    ],
    'location' => [
        'address' => '', 'city' => '', 'state' => '', 'zip' => '',
        'suite' => '', 'locationName' => ''
    ]
];

// =====================================================
// DISPATCH TO APPROPRIATE PARSER
// =====================================================
if ($proposalType === 'location') {
    // New clean path for location-only proposals
    $parsed = parseLocationProposal($lines, $inputData['inputData'] ?? [], $rawInputOriginal);
    error_log('[PPC][SECTION-02.5] → Location Parser dispatched');
} else {
    // Legacy contact path — full AI extraction (unchanged behavior for PC-0..PC-3)
    $parsed = parseContactProposal($rawInput);
    error_log('[PPC][SECTION-02.5] → Contact Parser dispatched (legacy AI path)');
}

#endregion

#region SECTION 04 — Schema Enforcement

$parsed['entity'] =
    $parsed['entity'] ?? [];

$parsed['contact'] =
    $parsed['contact'] ?? [];

$parsed['location'] =
    $parsed['location'] ?? [];

// =====================================================
// ENTITY
// =====================================================

$parsed['entity'] = array_merge([
    'name' => ''
], $parsed['entity']);

// =====================================================
// CONTACT
// =====================================================

$parsed['contact'] = array_merge([
    'firstName'   => '',
    'lastName'    => '',
    'salutation'  => '',
    'title'       => '',
    'primaryPhone'=> '',
    'email'       => ''
], $parsed['contact']);

// =====================================================
// LOCATION
// =====================================================

$parsed['location'] = array_merge([
    'address'      => '',
    'city'         => '',
    'state'        => '',
    'zip'          => '',
    'suite'        => '',
    'locationName' => ''
], $parsed['location']);

error_log('[PPC][SECTION-04] Schema enforcement complete');

#endregion

#region SECTION 05 — Data Normalization & Completeness Validation

// =====================================================
// INTENT DETECTION ENGINE (Ensures PC-4 & PC-5 drop contact requirements)
// =====================================================
// If we don't have a contact name, email, or phone number, it's explicitly a location-only proposal.
$hasParserContactData = !empty(trim($parsed['contact']['firstName'] ?? $parsed['contact']['contactFirstName'] ?? '')) ||
                        !empty(trim($parsed['contact']['lastName'] ?? $parsed['contact']['contactLastName'] ?? '')) ||
                        !empty(trim($parsed['contact']['primaryPhone'] ?? $parsed['contact']['contactPrimaryPhone'] ?? '')) ||
                        !empty(trim($parsed['contact']['email'] ?? $parsed['contact']['contactEmail'] ?? ''));

if (!$hasParserContactData) {
    error_log('[PPC][SECTION-05] No contact details identified. Forcing location-only mode.');
    $isExplicitLocationOnlyIntent = true;
}

// =====================================================
// MINIMAL FALLBACK (Temporary safety net during transition)
// =====================================================
if (isset($isExplicitLocationOnlyIntent) && $isExplicitLocationOnlyIntent) {
    error_log('[PPC][SECTION-05] Minimal location fallback (parser should handle most data)');

    $clientLoc    = $inputData['inputData']['location'] ?? [];
    $clientEntity = $inputData['inputData']['entity'] ?? [];

    // Only fill true gaps — the dedicated parser should provide most values
    if (empty(trim($parsed['location']['locationName'] ?? ''))) {
        $parsed['location']['locationName'] = $clientLoc['locationName'] ?? '';
    }
    if (empty(trim($parsed['location']['address'] ?? ''))) {
        $parsed['location']['address'] = $clientLoc['locationAddress'] ?? '';
    }
    if (empty(trim($parsed['location']['city'] ?? ''))) {
        $parsed['location']['city'] = $clientLoc['locationCity'] ?? '';
    }
    if (empty(trim($parsed['location']['state'] ?? ''))) {
        $parsed['location']['state'] = $clientLoc['locationState'] ?? '';
    }
    if (empty(trim($parsed['location']['zip'] ?? ''))) {
        $parsed['location']['zip'] = $clientLoc['locationZip'] ?? '';
    }
    if (empty(trim($parsed['entity']['name'] ?? ''))) {
        $parsed['entity']['name'] = $clientEntity['entityName'] ?? '';
    }
}

// =====================================================
// KEY BRIDGE LAYER (Maps parser prefixes to naked keys)
// =====================================================
if (empty($parsed['entity']['name']) && !empty($parsed['entity']['entityName'])) {
    $parsed['entity']['name'] = $parsed['entity']['entityName'];
}

$contactFieldMap = [
    'firstName'    => 'contactFirstName',
    'lastName'     => 'contactLastName',
    'salutation'   => 'contactSalutation',
    'title'        => 'contactTitle',
    'primaryPhone' => 'contactPrimaryPhone',
    'email'        => 'contactEmail'
];
foreach ($contactFieldMap as $naked => $prefixed) {
    if (empty($parsed['contact'][$naked]) && !empty($parsed['contact'][$prefixed])) {
        $parsed['contact'][$naked] = $parsed['contact'][$prefixed];
    }
}

$locationFieldMap = [
    'address' => 'locationAddress',
    'city'    => 'locationCity',
    'state'   => 'locationState',
    'zip'     => 'locationZip',
    'suite'   => 'locationSuite'
];
foreach ($locationFieldMap as $naked => $prefixed) {
    if (empty($parsed['location'][$naked]) && !empty($parsed['location'][$prefixed])) {
        $parsed['location'][$naked] = $parsed['location'][$prefixed];
    }
}

// =====================================================
// ENTITY NORMALIZATION
// =====================================================
$parsed['entity']['name'] = trim($parsed['entity']['name'] ?? '');

// =====================================================
// CONTACT NORMALIZATION
// =====================================================
$parsed['contact']['firstName']   = trim($parsed['contact']['firstName'] ?? '');
$parsed['contact']['lastName']    = trim($parsed['contact']['lastName'] ?? '');
$parsed['contact']['salutation']  = trim($parsed['contact']['salutation'] ?? '');
$parsed['contact']['title']       = trim($parsed['contact']['title'] ?? '');
$parsed['contact']['email']       = strtolower(trim($parsed['contact']['email'] ?? ''));

// =====================================================
// PHONE NORMALIZATION (Preserve formatting from parser)
// =====================================================
$phoneValue = $parsed['contact']['primaryPhone'] ?? '';
if (!empty($phoneValue)) {
    $parsed['contact']['primaryPhoneDigits'] = preg_replace('/[^0-9]/', '', $phoneValue);

    $digitsOnly = preg_replace('/[^0-9]/', '', $phoneValue);
    if (strlen($digitsOnly) === 10 && strpos($phoneValue, '(') === false) {
        $parsed['contact']['primaryPhone'] = '(' . substr($digitsOnly, 0, 3) . ') ' .
                                             substr($digitsOnly, 3, 3) . '-' .
                                             substr($digitsOnly, 6);
    }
}

// =====================================================
// LOCATION NORMALIZATION
// =====================================================
$parsed['location']['address']      = trim($parsed['location']['address'] ?? '');
$parsed['location']['city']         = trim($parsed['location']['city'] ?? '');
$parsed['location']['state']        = strtoupper(trim($parsed['location']['state'] ?? ''));
$parsed['location']['zip']          = trim($parsed['location']['zip'] ?? '');
$parsed['location']['suite']        = trim($parsed['location']['suite'] ?? '');
$parsed['location']['locationName'] = trim($parsed['location']['locationName'] ?? '');

// Synchronize safely back to prefixed variants for downstream code sections
$parsed['entity']['entityName'] = $parsed['entity']['name'];
foreach ($contactFieldMap as $naked => $prefixed) {
    $parsed['contact'][$prefixed] = $parsed['contact'][$naked];
}
foreach ($locationFieldMap as $naked => $prefixed) {
    $parsed['location'][$prefixed] = $parsed['location'][$naked];
}

error_log('[PPC][SECTION-05] Data normalization complete');

// =====================================================
// COMPLETENESS CHECK (With Phone & Email Absolute Rules)
// =====================================================

error_log('[PPC][PHASE-3] Running Completeness Check');

if (isset($isExplicitLocationOnlyIntent) && $isExplicitLocationOnlyIntent) {
    // 🏢 Location-Only Workflow requirements (PC-4 / PC-5)
    $requiredFields = [
        'entity.name'           => 'Entity Name',
        'location.locationName' => 'Location Name',
        'location.address'      => 'Street Address',
        'location.city'         => 'City',
        'location.state'        => 'State',
        'location.zip'          => 'ZIP Code'
    ];
} else {
    // 👤 Full Standard Contact Record requirements (PC-1 / PC-2 / PC-3)
    $requiredFields = [
        'entity.name'          => 'Entity Name',
        'contact.firstName'    => 'Contact First Name',
        'contact.lastName'     => 'Contact Last Name',
        'contact.primaryPhone' => 'Primary Phone Number',
        'contact.email'        => 'Email Address',
        'location.address'     => 'Street Address',
        'location.city'        => 'City',
        'location.state'       => 'State',
        'location.zip'         => 'ZIP Code'
    ];
}

$missingFields = [];

// Evaluate required fields loop
foreach ($requiredFields as $dotPath => $label) {
    list($category, $field) = explode('.', $dotPath);
    $value = trim($parsed[$category][$field] ?? '');

    if (empty($value)) {
        $missingFields[] = [
            'path'  => $dotPath,
            'label' => $label
        ];
    }
}

// Build UI presentation completeness object
$hasFirst = !empty(trim($parsed['contact']['firstName'] ?? ''));
$hasLast  = !empty(trim($parsed['contact']['lastName'] ?? ''));
$hasPhone = !empty(trim($parsed['contact']['primaryPhone'] ?? ''));
$hasEmail = !empty(trim($parsed['contact']['email'] ?? ''));

$isLocationOnly = (isset($isExplicitLocationOnlyIntent) && $isExplicitLocationOnlyIntent);

$completeness = [
    'entity' => [
        'name' => !empty(trim($parsed['entity']['name'] ?? '')) ? '✔ Complete' : '✖ Missing Entity Name'
    ],
    'contact' => [
        'names' => $isLocationOnly ? '✔ Not Required' : (($hasFirst && $hasLast) ? '✔ Contact Name' : '✖ Contact Name Missing'),
        'phone' => $isLocationOnly ? '✔ Not Required' : ($hasPhone ? '✔ Primary Phone' : '✖ Primary Phone Required'),
        'email' => $isLocationOnly ? '✔ Not Required' : ($hasEmail ? '✔ Email Address' : '✖ Email Address Required')
    ],
    'location' => [
        'name'   => !empty(trim($parsed['location']['locationName'] ?? '')) ? '✔ Location Name' : '✖ Location Name Missing',
        'street' => !empty(trim($parsed['location']['address'] ?? '')) ? '✔ Street Address' : '✖ Street Address Missing',
        'city'   => !empty(trim($parsed['location']['city'] ?? '')) ? '✔ City' : '✖ City Missing',
        'state'  => !empty(trim($parsed['location']['state'] ?? '')) ? '✔ State' : '✖ State Missing',
        'zip'    => !empty(trim($parsed['location']['zip'] ?? '')) ? '✔ ZIP' : '✖ ZIP Missing (Required)'
    ],
    'overall' => empty($missingFields) ? 'PASS' : 'FAIL'
];

error_log('[PPC][PHASE-3] Completeness Result: ' . $completeness['overall']);

// HARD GATE — Early Exit for RS-3
if ($completeness['overall'] !== 'PASS') {
    error_log('[PPC][PHASE-3] INCOMPLETE — Early Exit with RS-3');

    $pathsOnly  = array_column($missingFields, 'path');
    $labelsOnly = array_column($missingFields, 'label');

    $bulletList = "• " . implode("\n• ", $labelsOnly);
    $uiMessage  = "Proposal is incomplete.\n\nMissing required field(s):\n{$bulletList}\n\nPlease provide the missing information before continuing.";

    echo json_encode([
        'success'      => true,
        'status'       => 'incomplete',
        'proposalId'   => $proposalId ?? 'PRP-' . date('Ymd') . '-' . substr(uniqid(), -6),
        'completeness' => $completeness,
        'governance'   => [
            'resolution_status' => 'RS-3',
            'reason'            => 'Incomplete Proposal',
            'missingFields'     => $pathsOnly
        ],
        'message' => $uiMessage,
        'data' => [
            'entity'   => $parsed['entity'] ?? [],
            'contact'  => $parsed['contact'] ?? [],
            'location' => $parsed['location'] ?? []
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

// Continue on PASS
error_log('[PPC][PHASE-3] PASS — Proceeding to validation');

#endregion

#region SECTION 06 — Address Resolution

$data = [
    'entity'   => $parsed['entity'],
    'contact'  => $parsed['contact'],
    'location' => [
        'locationAddress'   => $parsed['location']['address'],
        'locationCity'      => $parsed['location']['city'],
        'locationState'     => $parsed['location']['state'],
        'locationZip'       => $parsed['location']['zip'],
        'locationPlaceId'   => null,
        'locationLatitude'  => null,
        'locationLongitude' => null,
        'locationValidated' => false
    ]
];

error_log(
    '[PPC][SECTION-06] Location object initialized'
);

#endregion

#region SECTION 07 — Canonical Data Mapping (Legacy Contract)

error_log('[PPC][SECTION-06A] Building canonical $data object from parsed AI output');

// =====================================================
// ENTITY
// =====================================================
$data['entity'] = [
    'entityName'        => $parsed['entity']['name'] ?? '',
    'entityNameRaw'     => $parsed['entity']['name'] ?? ''
];

// =====================================================
// CONTACT
// =====================================================
$data['contact'] = [
    'contactSalutation'      => $parsed['contact']['salutation'] ?? '',
    'contactFirstName'       => $parsed['contact']['firstName'] ?? '',
    'contactLastName'        => $parsed['contact']['lastName'] ?? '',
    'contactTitle'           => $parsed['contact']['title'] ?? '',
    'contactPrimaryPhone'    => $parsed['contact']['primaryPhone'] ?? '',
    'contactPrimaryPhoneRaw' => $parsed['contact']['primaryPhoneRaw'] ?? '',
    'contactEmail'           => $parsed['contact']['email'] ?? '',
    'contactEmailNormalized' => strtolower(trim($parsed['contact']['email'] ?? '')),
    'salutationInferred'     => $parsed['contact']['salutationInferred'] ?? false
];

// =====================================================
// LOCATION — Fully preserved for PC-4 / PC-5
// =====================================================
$data['location'] = [
    'locationName'           => $parsed['location']['locationName'] ?? '',
    'locationNameConfirmed'  => $parsed['location']['locationNameConfirmed'] ?? (!empty($parsed['location']['locationName'])),
    'locationNameInferred'   => $parsed['location']['locationNameInferred'] ?? false,
    'locationNameSource'     => $parsed['location']['locationNameSource'] ?? 'location_parser',

    'locationAddress'    => $parsed['location']['address'] ?? '',
    'locationCity'       => $parsed['location']['city'] ?? '',
    'locationState'      => $parsed['location']['state'] ?? '',
    'locationZip'        => $parsed['location']['zip'] ?? '',
    'locationAddressRaw' => trim(
        ($parsed['location']['address'] ?? '') .
        ', ' .
        ($parsed['location']['city'] ?? '') .
        ', ' .
        ($parsed['location']['state'] ?? '') .
        ' ' .
        ($parsed['location']['zip'] ?? '')
    ),

    'locationPlaceId'   => null,
    'locationLatitude'  => null,
    'locationLongitude' => null,
    'locationValidated' => false
];

// =====================================================
// RAW INPUT (preserve for audit & reprocessing)
// =====================================================
$data['rawInput'] = [
    'original' => $rawInputOriginal,
    'source'   => 'skyebot_prompt',
    'type'     => 'signature'
];

error_log('[PPC][SECTION-06A] Canonical $data object created successfully');

#endregion

#region SECTION 08 — Google Location Validation

// =====================================================
// BUILD SEARCH ADDRESS
// =====================================================

$searchAddress = trim(
    implode(', ', array_filter([
        $data['location']['locationAddress'],
        $data['location']['locationCity'],
        $data['location']['locationState'],
        $data['location']['locationZip']
    ]))
);

error_log('[PPC][SECTION-07] Search Address: ' . $searchAddress);

// =====================================================
// GOOGLE GEOCODE
// =====================================================

$googleApiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY') 
    ?: getenv('GOOGLE_MAPS_BACKEND_API_KEY')
    ?: getenv('GOOGLE_MAPS_API_KEY')
    ?: getenv('GOOGLE_MAPS_PLACE_ID_API_KEY')
    ?: '';

if (!empty($searchAddress) && !empty($googleApiKey)) {

    $geocodeUrl = 'https://maps.googleapis.com/maps/api/geocode/json?' . 
        http_build_query([
            'address' => $searchAddress,
            'key'     => $googleApiKey
        ]);

    $geocodeResponse = @file_get_contents($geocodeUrl);
    $geocodeData     = json_decode($geocodeResponse, true);

    if (isset($geocodeData['results'][0])) {

        $result = $geocodeData['results'][0];

        $data['location']['locationPlaceId']   = $result['place_id'] ?? null;
        $data['location']['locationLatitude']  = $result['geometry']['location']['lat'] ?? null;
        $data['location']['locationLongitude'] = $result['geometry']['location']['lng'] ?? null;
        $data['location']['locationValidated'] = true;

        // Extract county directly from Google components as a reliable pre-Census baseline
        if (!empty($result['address_components'])) {
            foreach ($result['address_components'] as $component) {
                if (in_array('administrative_area_level_2', $component['types'])) {
                    // Strips out " County" suffix to store clean "Maricopa"
                    $data['location']['locationCounty'] = str_replace(' County', '', $component['long_name']);
                    break;
                }
            }
        }

        error_log('[PPC][SECTION-07] Google validation successful. Extracted County: ' . ($data['location']['locationCounty'] ?? 'None'));

    } else {
        error_log('[PPC][SECTION-07] Google returned no results');
        $data['location']['locationValidated'] = false;
    }

} else {
    error_log('[PPC][SECTION-07] Skipping Google geocode (missing address or API key)');
    $data['location']['locationValidated'] = false;
}

// =====================================================
// 📊 ACTION LOGGING (After Google Enrichment)
// =====================================================
error_log('[PPC][ACTION-LOG] Starting action insert (post-enrichment)');

$actionId = null;

try {
    $actionPayload = [
        'input'              => $rawInputOriginal,
        'rawInput'           => $rawInput,
        'activitySessionId'  => $context['activitySessionId'],
        'mode'               => $inputData['mode'] ?? 'propose',
        'requestId'          => $context['requestId'],
        'source'             => 'processProposedContact'
    ];

    $actionId = insertActionPrompt([
        'contactId'         => $_SESSION['contactId'] ?? null,
        'promptText'        => $rawInputOriginal,
        'responseText'      => 'contact_proposal_processed',
        'intent'            => 'contact_proposal',
        'intentConfidence'  => 0.97,
        'actionTypeId'      => $inputData['actionTypeId'] ?? 13,
        'origin'            => ACTION_ORIGIN_USER,
        'activitySessionId' => $context['activitySessionId'],
        
        'latitude'          => $data['location']['locationLatitude'] ?? null,
        'longitude'         => $data['location']['locationLongitude'] ?? null,

        'actionPayloadData' => $actionPayload,
        'actionResponseData'=> null
    ], $pdo);

    error_log("[PPC][ACTION-LOG] ✅ Success - ActionID: " . ($actionId ?? 'NULL'));

    $_SESSION['lastContactProposalActionId'] = $actionId;

} catch (Throwable $e) {
    error_log("[PPC][ACTION-LOG] ❌ Failed: " . $e->getMessage());
}

#endregion

#region SECTION 09 — County Resolution (Census)

require_once __DIR__ . '/utils/validateAddressCensus.php';

$censusResult = validateAddressCensus($searchAddress);

$data['location']['locationCensusValidated'] = $censusResult['valid'] ?? false;

// Explicitly preserve Google's extracted county if Census resolution returns null/fails
$data['location']['locationCounty']      = $censusResult['county']     ?? ($data['location']['locationCounty'] ?? null);
$data['location']['locationCountyFips']  = $censusResult['countyFips'] ?? null;
$data['location']['locationCountyGeoId'] = $censusResult['countyGeoId'] ?? null;

if ($data['location']['locationCensusValidated']) {
    error_log(
        '[PPC][SECTION-09] ✅ Census county resolved: ' .
        ($data['location']['locationCounty'] ?? 'N/A') .
        ' | FIPS: ' . ($data['location']['locationCountyFips'] ?? 'N/A') .
        ' | GEOID: ' . ($data['location']['locationCountyGeoId'] ?? 'N/A')
    );
} else {
    error_log(
        '[PPC][SECTION-09] ❌ Census validation failed: ' . 
        ($censusResult['reason'] ?? 'Unknown reason') .
        ' | Retaining Baseline County: ' . ($data['location']['locationCounty'] ?? 'None')
    );
}

#endregion

#region SECTION 10 — Parcel Resolution + Enrichment

require_once __DIR__ . '/utils/resolveParcel.php';

$parcelResult = resolveParcel(
    $data['location']['locationLatitude'] ?? null,
    $data['location']['locationLongitude'] ?? null,
    $data['location']['locationCounty'] ?? null,
    $data['location']['locationCountyFips'] ?? null,
    $searchAddress
);

$data['location']['parcelDetails'] =
    $parcelResult['parcelDetails'] ?? [];

$data['location']['parcelCount'] =
    $parcelResult['parcelCount'] ?? 0;

$data['location']['jurisdictionName'] =
    $parcelResult['jurisdictionName'] ?? null;

$data['location']['jurisdictionType'] =
    $parcelResult['jurisdictionType'] ?? null;

$data['location']['hasMultipleParcels'] =
    ($data['location']['parcelCount'] > 1);

// =====================================================
// ENRICH EACH PARCEL WITH DETAILED ASSESSOR DATA + MAP ID
// =====================================================

$token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';

foreach ($data['location']['parcelDetails'] as &$parcel) {

    $apn = $parcel['parcelNumber'] ?? null;

    if (!$apn) {
        continue;
    }

    // =====================================================
    // INITIALIZE LEAN ASSESSOR METADATA
    // =====================================================
    $parcel['assessor'] = [
        'detail'        => null,
        'mapId'         => null,
        'mapUrl'        => null,
        'mapImage'      => null,
        'mapPdf'        => null,
        'lastRetrieved' => null,
        'status'        => 'pending'
    ];

    // Shared headers for the Maricopa County API
    $httpHeaders = [
        'Accept: application/json, text/plain, */*',
        'Cache-Control: no-cache'
    ];
    if ($token) {
        $httpHeaders[] = 'Authorization: ' . trim($token);
    }

    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // =====================================================
    // FETCH STANDARD ASSESSOR DETAILS
    // =====================================================
    $detailUrl = 'https://mcassessor.maricopa.gov/parcel/' . urlencode($apn);

    error_log('[PPC][SECTION-10] Enriching parcel: ' . $apn);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $detailUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_HTTPHEADER     => $httpHeaders
    ]);

    $detailResponse = curl_exec($ch);
    $detailHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($detailHttpCode === 200 && $detailResponse !== false) {
        $detailData = json_decode($detailResponse, true);

        if (is_array($detailData)) {
            // 1. Owner & Mailing Mapping
            $ownerData = $detailData['Owner'] ?? [];
            if (is_array($ownerData)) {
                $parcel['ownerMailingAddress'] = $ownerData['FullMailingAddress'] 
                    ?? (!empty($ownerData['MailingAddress1']) ? trim(($ownerData['MailingAddress1'] ?? '') . ', ' . ($ownerData['MailingCity'] ?? '') . ', ' . ($ownerData['MailingState'] ?? '') . ' ' . ($ownerData['MailingZip'] ?? '')) : null);
                
                $parcel['lastSaleDate']  = $ownerData['SaleDate'] ?? null;
                $parcel['lastSalePrice'] = $ownerData['SalePrice'] ?? null;
            } else {
                $parcel['ownerMailingAddress'] = null;
                $parcel['lastSaleDate']  = null;
                $parcel['lastSalePrice'] = null;
            }

            // 2. Map standard property info (No dynamic calculations or valuation loops)
            $parcel['propertyType']     = $detailData['PropertyType'] ?? null;
            $parcel['lotSizeSqFt']      = $detailData['LotSize'] ?? null;
            $parcel['constructionYear'] = $detailData['ConstructionYear'] ?? null;
            $parcel['puc']              = $detailData['PUC'] ?? null;
            $parcel['subdivision']      = $detailData['Subdivision'] ?? null;
            $parcel['mcrNumber']        = $detailData['MCR'] ?? null;
            $parcel['str']              = $detailData['STR'] ?? null;

            // Retain ONLY the lean metrics in the details object—all historical valuations stripped
            $parcel['assessor']['detail'] = [
                'PropertyType'     => $parcel['propertyType'],
                'LotSize'          => $parcel['lotSizeSqFt'],
                'ConstructionYear' => $parcel['constructionYear'],
                'PUC'              => $parcel['puc'],
                'Subdivision'      => $parcel['subdivision'],
                'MCR'              => $parcel['mcrNumber'],
                'STR'              => $parcel['str']
            ];
            $parcel['assessor']['status'] = 'resolved';
        }
    } else {
        $parcel['assessor']['status'] = 'failed';
        error_log('[PPC][SECTION-10] Failed to fetch standard details for parcel: ' . $apn . ' (HTTP ' . $detailHttpCode . ')');
    }

    // =====================================================
    // FETCH MAP ID & MAP URL
    // =====================================================
    if ($token) {
        $mapMetaUrl = 'https://mcassessor.maricopa.gov/mapid/parcel/' . urlencode($apn);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $mapMetaUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => $httpHeaders
        ]);

        $mapMetaResponse = curl_exec($ch);
        $mapHttpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Track and audit response shapes safely
        error_log('[PPC][SECTION-10] MapID raw response for ' . $apn . ': ' . substr((string)$mapMetaResponse, 0, 500));

        if ($mapHttpCode === 200 && $mapMetaResponse !== false) {
            $mapData = json_decode($mapMetaResponse, true);
            
            if (is_array($mapData)) {
                $mapItem = $mapData[0] ?? null;

                if (is_string($mapItem)) {
                    $mapId = preg_replace('/\.pdf$/i', '', trim($mapItem));

                    $parcel['assessor']['mapId'] = $mapId;
                    $parcel['assessor']['mapUrl'] =
                        'https://mcassessor.maricopa.gov/getmapid/' .
                        rawurlencode($mapId) .
                        '/';
                } elseif (is_array($mapItem)) {
                    $mapId = $mapItem['FileName'] ?? $mapItem['fileName'] ?? $mapItem['filename'] ?? $mapItem['mapId'] ?? null;
                    $mapId = $mapId ? preg_replace('/\.pdf$/i', '', trim((string)$mapId)) : null;

                    $parcel['assessor']['mapId'] = $mapId;
                    $parcel['assessor']['mapUrl'] =
                        $mapItem['Url']
                        ?? $mapItem['url']
                        ?? (
                            $mapId
                                ? 'https://mcassessor.maricopa.gov/getmapid/' .
                                rawurlencode($mapId) .
                                '/'
                                : null
                        );
                }
            }
        } else {
            error_log('[PPC][SECTION-10] MapID resolution failed for: ' . $apn . ' (HTTP ' . $mapHttpCode . ')');
        }
    }
}

unset($parcel);

error_log(
    '[PPC][SECTION-10] Parcel resolution + enrichment complete. ' .
    'Count=' . ($data['location']['parcelCount'] ?? 0) .
    ' | Jurisdiction=' . ($data['location']['jurisdictionName'] ?? 'NULL') .
    ' | Type=' . ($data['location']['jurisdictionType'] ?? 'NULL')
);

// =====================================================================
// GEOGRAPHIC GOVERNANCE GATE (After Parcel Resolution)
// =====================================================================
$resolvedCounty          = strtolower($data['location']['locationCounty'] ?? '');
$locationValidated       = $data['location']['locationValidated'] ?? false;
$locationCensusValidated = $data['location']['locationCensusValidated'] ?? false;
$parcelCount             = $data['location']['parcelCount'] ?? 0;

// Combined validation log for easy debugging
error_log(sprintf(
    '[PPC][VALIDATION] Google=%s | Census=%s | Parcels=%d | County=%s',
    $locationValidated ? 'PASS' : 'FAIL',
    $locationCensusValidated ? 'PASS' : 'FAIL',
    $parcelCount,
    $resolvedCounty
));

if (!$locationValidated) {
    error_log('[PPC][GOVERNANCE] Google validation failed → RS-8');
    $rsCode = 'RS-8';
} elseif (
    $resolvedCounty === 'maricopa' &&
    !$locationCensusValidated &&
    $parcelCount === 0
) {
    error_log('[PPC][GOVERNANCE] Maricopa: Google OK but no parcel + Census failed → RS-8');
    $rsCode = 'RS-8';
} else {
    $rsCode = null; // continue normally
}

if ($rsCode === 'RS-8') {
    $proposalId = $proposalId ?? 'PRP-' . date('Ymd') . '-' . substr(uniqid(), -6);
    
    echo json_encode([
        'success'           => true,
        'status'            => 'incomplete',
        'proposalId'        => $proposalId,
        'activitySessionId' => $context['activitySessionId'] ?? '',
        'data' => [
            'entity'   => $parsed['entity'] ?? [],
            'contact'  => $parsed['contact'] ?? [],
            'location' => $data['location'] ?? []
        ],
        'databaseResolution' => [
            'entity'   => null,
            'location' => null,
            'contact'  => null
        ],
        'pcm' => [
            'pc' => $pcm['pc'] ?? 'PC-1', 
            'rs' => ['RS-8']
        ],
        'commitPlan' => [
            'canCommit' => false,
            'entity'    => [],
            'location'  => [],
            'contact'   => [],
            'actions'   => [],
            'summary'   => 'Location validation failed.'
        ],
        'ui' => [
            'proposalStatus' => 'incomplete',
            'canAccept'      => false,
            'canReject'      => true,
            'canEdit'        => true,
            'canCommit'      => false
        ],
        'governance' => [
            'blockingIssues' => [
                [
                    'code'    => 'RS-8',
                    'message' => 'The address could not be validated after exhausting all available geographic sources.',
                    'details' => [
                        'county' => 'maricopa',
                        'googleValidated' => $locationValidated,
                        'censusValidated' => $locationCensusValidated,
                        'parcelCount'     => $parcelCount
                    ]
                ]
            ],
            'resolution_status' => 'RS-8',
            'reason'            => 'Exhausted Google + Parcel + Census validation.'
        ],
        'narratives' => [
            'ui'     => "The address could not be verified using Google's location services, parcel records, or Census validation. Please provide a more precise address.",
            'report' => "Address failed validation across Google, Parcel, and Census sources."
        ],
        'meta' => [
            'hasMultipleParcels' => $data['location']['hasMultipleParcels'] ?? false,
            'parcelCount'        => $parcelCount,
            'censusValidated'    => $locationCensusValidated,
            'googleValidated'    => $locationValidated,
            'searchAddress'      => $searchAddress ?? ''
        ],
        'rawInput' => [
            'original' => $rawInputOriginal ?? '',
            'type'     => 'signature',
            'source'   => 'skyebot_prompt'
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

#endregion

#region SECTION 11 — Database Resolution

$databaseResolution = [
    'entity'   => null,
    'location' => null,
    'contact'  => null
];

if ($pdo) {

    // 1. Entity Resolution
    $databaseResolution['entity'] = evaluateEntityDuplicate($parsed, $pdo);

    // 2. Location Resolution
    $databaseResolution['location'] = evaluateLocationDuplicate($parsed, $pdo);

    // 3. Contact Resolution
    $databaseResolution['contact'] = evaluateDuplicate($parsed, $pdo);

    // ====================================================================
    // 🌟 FIXED: PC-6 Succession Evaluation (Key-Drift & Brand New Location Patch)
    // ====================================================================
    $contactStatus = $databaseResolution['contact']['status'] ?? 'none';
    
    if ($contactStatus === 'exact') {
        // Accommodate case variation (camelCase coming from JSON streams vs snake_case from direct DB schemas)
        $existingContactLocationId = $databaseResolution['contact']['locationId'] 
            ?? $databaseResolution['contact']['location_id'] 
            ?? null;
            
        $proposedLocationId = $databaseResolution['location']['locationId'] 
            ?? $databaseResolution['location']['location_id'] 
            ?? null;
            
        $locationStatus = $databaseResolution['location']['status'] ?? 'none';

        // An asset transfer (PC-6) is triggered if:
        // 1. The contact currently belongs to an existing canonical location ID...
        // 2. AND either the target location is completely new ('none') OR maps to a different record entirely.
        if ($existingContactLocationId && ($locationStatus === 'none' || $existingContactLocationId != $proposedLocationId)) {
            $databaseResolution['contact']['isLocationTransfer'] = true;
        } else {
            $databaseResolution['contact']['isLocationTransfer'] = false;
        }
    } else {
        $databaseResolution['contact']['isLocationTransfer'] = false;
    }

    error_log('[PPC][SECTION-11] Database resolution complete (Succession evaluated)');

} else {
    error_log('[PPC][SECTION-11] No PDO connection — skipping DB resolution');
}

#endregion

#region SECTION 12 — PCM Classification & Governance

$isExplicitLocationOnlyIntent = $isExplicitLocationOnlyIntent ?? false;

// =====================================================
// PASS 1 — PC Classification (Database-Driven)
// =====================================================

$entityStatus   = $databaseResolution['entity']['status']   ?? 'none';
$locationStatus = $databaseResolution['location']['status'] ?? 'none';
$contactStatus  = $databaseResolution['contact']['status']  ?? 'none';

// 🌟 NEW: Extracted from Section 11 to identify if an existing contact is migrating locations
$isLocationTransfer = $databaseResolution['contact']['isLocationTransfer'] ?? false;

error_log("[PPC][SECTION-12] Database Resolution → Entity: $entityStatus | Location: $locationStatus | Contact: $contactStatus | Transfer: " . ($isLocationTransfer ? 'YES' : 'NO'));

if ($isExplicitLocationOnlyIntent === true) {
    // Location-only proposals (explicitly requested by intake stream)
    $pcm['pc'] = ($entityStatus === 'exact') ? 'PC-4' : 'PC-5';
} else {
    // 🌟 REMADE: Strict deterministic PCM classification matrix
    if ($entityStatus === 'exact' && $locationStatus === 'exact' && $contactStatus === 'exact') {
        // Passive Verification: Graph perfectly matches existing records
        $pcm['pc'] = 'PC-0';
        
    } elseif ($entityStatus === 'exact' && $contactStatus === 'exact' && $isLocationTransfer === true) {
        // ⏳ CONTACT SUCCESSION: Existing contact relocating to a brand new or alternate location asset
        $pcm['pc'] = 'PC-6';
        
    } elseif ($entityStatus === 'exact' && $locationStatus === 'exact' && $contactStatus !== 'exact') {
        // Entity Expansion: Appending a brand new contact face to an existing operational facility
        $pcm['pc'] = 'PC-3';
        
    } elseif ($entityStatus === 'exact' && $locationStatus !== 'exact' && $contactStatus !== 'exact') {
        // Entity Expansion: Appending both a brand new location and a new contact to a known entity
        $pcm['pc'] = 'PC-2';
        
    } elseif ($entityStatus === 'exact' && $locationStatus !== 'exact' && $contactStatus === 'exact') {
        // Catch-all safety: If contact matches exactly but location is new, it MUST be a transfer (PC-6).
        // Forcing fallback to PC-6 prevents accidental routing into the location-only (PC-4) track.
        $pcm['pc'] = 'PC-6';
        
    } else {
        // Complete Ingestion: Entirely new topology graph node
        $pcm['pc'] = 'PC-1';
    }
}

// =====================================================
// 🌟 DYNAMIC TEXT OVERRIDES FOR PC-6 LIFECYCLES
// =====================================================
if ($pcm['pc'] === 'PC-6') {
    // 1. Correct the high-level description line
    $fullName = trim(($data['contact']['contactFirstName'] ?? '') . ' ' . ($data['contact']['contactLastName'] ?? ''));
    $entityName = $data['entity']['entityName'] ?? 'Existing Entity';
    $narratives['contentLine'] = "Contact Succession Proposal for {$fullName} at {$entityName}";
    
    // 2. Overwrite the generic fallback UI text with explicit succession language
    $successionNarrative = "The proposal will retire the existing contact record, create a replacement contact associated with the new location, and preserve historical relationships with prior operational records.";
    $narratives['ui'] = $successionNarrative;
    $narratives['decisions'] = [$successionNarrative];
    
    // 3. Clear out the contradictory "unresolved" warning since a new location is expected behavior
    $narratives['review'] = []; 
}

// =====================================================
// GOVERNANCE — RS Rules
// =====================================================

$governanceIssues = [];

// 🌟 UPDATED: RS-5 Duplicate Contact Scoping
// Strict Guardrail: Prevent RS-5 from triggering during an intentional PC-6 Contact Succession.
// Only flags unintended duplicates trying to be re-inserted under standard creation pipelines (PC-2, PC-3).
if ($contactStatus === 'exact' && in_array($pcm['pc'], ['PC-2', 'PC-3'])) {
    $governanceIssues[] = [
        'code' => 'RS-5', 
        'message' => 'Duplicate contact detected',
        // 🔥 Overrides standard UI text rows for action labels
        'action_text' => 'Action: Contact is currently in the database' 
    ];
}

// RS-6 Multiple Parcels (Proposal-Centric Evaluation)
$parcelDetails   = $data['location']['parcelDetails'] ?? [];
$acceptedParcels = array_filter($parcelDetails, fn($parcel) => !empty($parcel['accepted']));
$resolvedCount   = !empty($acceptedParcels) ? count($acceptedParcels) : count($parcelDetails);

if (
    $locationStatus !== 'exact' && 
    (($data['location']['hasMultipleParcels'] ?? false) || ($data['location']['parcelCount'] ?? 0) > 1) &&
    $resolvedCount !== 1
) {
    $governanceIssues[] = [
        'code' => 'RS-6',
        'message' => 'Multiple parcels found for this address - selection required',
        'details' => ['parcelCount' => $data['location']['parcelCount'] ?? 0]
    ];
}

// RS-7 Unresolved Parcel
if (strtolower($data['location']['locationCounty'] ?? '') === 'maricopa' && 
    ($data['location']['parcelCount'] ?? 0) === 0) {
    $governanceIssues[] = ['code' => 'RS-7', 'message' => 'Parcel could not be resolved'];
}

// RS-8 Invalid Location
if (empty($data['location']['locationPlaceId'] ?? null)) {
    $governanceIssues[] = ['code' => 'RS-8', 'message' => 'Invalid location'];
}

// Default RS-0 only if no other issues
$pcm['rs'] = $pcm['rs'] ?? [];
if (empty($governanceIssues) && empty($pcm['rs'])) {
    $pcm['rs'][] = 'RS-0';
}

// Sync governance issues
if (!empty($governanceIssues)) {
    foreach ($governanceIssues as $issue) {
        $pcm['rs'][] = $issue['code'];
    }
}

$pcm['rs'] = array_values(array_unique($pcm['rs']));

$blockingCodes = ['RS-3', 'RS-5', 'RS-6', 'RS-7', 'RS-8'];
$blocksCommit = !empty(array_intersect($pcm['rs'], $blockingCodes));

$governance = ['blockingIssues' => $governanceIssues];

// Attach a canonical reason and resolution action layout text directly onto governance parent
if (in_array('RS-5', $pcm['rs'])) {
    $governance['resolution_status'] = 'RS-5';
    $governance['reason'] = 'Duplicate Contact Detected';
    $governance['action_text'] = 'Action: Contact is currently in the database';
}

// Simplify PCM for output
$pcm = [
    'pc' => $pcm['pc'] ?? 'PC-1',
    'rs' => $pcm['rs']
];

// 🌟 UNPACK FOR DOWNSTREAM MATRIX AND UI STATE PIPELINE
$pc     = $pcm['pc'];
$rsList = $pcm['rs'];

error_log('[PPC][SECTION-12] PCM complete → PC=' . $pcm['pc'] . ' | RS=[' . implode(', ', $pcm['rs']) . '] | Blocks=' . ($blocksCommit ? 'YES' : 'NO'));

// Generates a 6-digit numeric sequence (e.g., using microseconds part or a random pad)
$proposalId = str_pad((string)mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

#endregion

#region SECTION 13 — Commit Plan Builder

$commitPlan = [
    'canCommit' => false,
    'entity'    => [],
    'location'  => [],
    'contact'   => [],
    'actions'   => [],
    'summary'   => ''
];

$pc = $pcm['pc'] ?? 'PC-UNKNOWN';
$rsList = $pcm['rs'] ?? [];

error_log("[PPC][SECTION-13] Building Commit Plan for PC={$pc}");

$canCommit = !in_array('RS-3', $rsList) && !in_array('RS-5', $rsList) && 
             !in_array('RS-6', $rsList) && !in_array('RS-7', $rsList) && 
             !in_array('RS-8', $rsList);

switch ($pc) {
    case 'PC-0':
        $commitPlan['canCommit'] = false;
        $commitPlan['actions'] = ['link_existing_elc'];
        $commitPlan['summary'] = 'No database changes required - ELC already exists';
        break;

    case 'PC-1':
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = ['insert_entity', 'insert_location', 'insert_contact', 'link_elc'];
        $commitPlan['summary'] = 'Insert new Entity, Location, Contact and establish relationships';
        break;

    case 'PC-2':
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = ['link_entity', 'insert_location', 'insert_contact', 'link_elc'];
        $commitPlan['summary'] = 'Link existing Entity, Insert new Location + Contact';
        break;

    case 'PC-3':
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = ['link_entity', 'link_location', 'insert_contact'];
        $commitPlan['summary'] = 'Link existing Entity + Location, Insert new Contact';
        break;

    case 'PC-4':  // Existing Entity + New Location (no contact)
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = ['link_entity', 'insert_location'];
        $commitPlan['summary'] = 'Link existing Entity, Insert new Location only';
        break;

    case 'PC-5':  // New Entity + New Location (no contact)
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = ['insert_entity', 'insert_location', 'link_elc'];
        $commitPlan['summary'] = 'Insert new Entity + new Location (no contact)';
        break;

    case 'PC-6':  // 🌟 NEW: Contact Succession Lifecycle
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = [
            'link_entity', 
            'insert_location', 
            'retire_contact', 
            'insert_replacement_contact', 
            'link_elc'
        ];
        $commitPlan['summary'] = 'Relocate contact: Retire historical contact record to preserve integrity, create replacement contact, and link to new location';
        break;

    default:
        $commitPlan['summary'] = 'Unknown PC type';
        break;
}

// Attach IDs where available
if (!empty($databaseResolution['entity']['entityId'])) {
    $commitPlan['entity']['entityId'] = $databaseResolution['entity']['entityId'];
}
if (!empty($databaseResolution['location']['locationId'])) {
    $commitPlan['location']['locationId'] = $databaseResolution['location']['locationId'];
    $commitPlan['location']['locationParcelNumberRaw'] = $databaseResolution['location']['locationParcelNumberRaw'] ?? null;
}
// 🌟 NEW: Pull target contactId into commit plan for explicit tracking during succession
if (!empty($databaseResolution['contact']['contactId'])) {
    $commitPlan['contact']['contactId'] = $databaseResolution['contact']['contactId'];
}

// 🔥 Hard override if caught in an RS-5 validation trap to prevent UI component mismatch
if (in_array('RS-5', $rsList)) {
    $commitPlan['actions'] = []; // Clears standard insert arrays out of the snapshot loop
    $commitPlan['summary'] = 'Action: Contact is currently in the database';
}

error_log("[PPC][SECTION-13] Commit Plan complete → canCommit=" . ($commitPlan['canCommit'] ? 'YES' : 'NO'));

#endregion

#region SECTION 14 — Narrative Builder + UI State

// =====================================================
// UI State Builder (Preserved Deterministic Rules)
// =====================================================
$uiState = [
    'proposalStatus' => 'proposed',
    'canAccept'      => false,
    'canReject'      => true,
    'canEdit'        => true,
    'canCommit'      => false,
    'theme'          => [] // 🎨 Centralized theme matrix container
];

$pc = $pcm['pc'] ?? 'UNKNOWN';
$rsList = $pcm['rs'] ?? [];

if ($pc === 'PC-0') {
    $uiState['proposalStatus'] = 'existing';
    $uiState['canAccept'] = $uiState['canReject'] = $uiState['canEdit'] = $uiState['canCommit'] = false;
} elseif (in_array('RS-3', $rsList) || in_array('RS-6', $rsList) || in_array('RS-7', $rsList)) {
    $uiState['proposalStatus'] = in_array('RS-6', $rsList) ? 'multiple_parcels' : (in_array('RS-7', $rsList) ? 'unresolved_parcel' : 'incomplete');
    $uiState['canAccept'] = false;
    $uiState['canCommit'] = false;
} else {
    // PC-6 falls cleanly in here with no blocking governance rules
    $uiState['canAccept'] = $uiState['canCommit'] = true;
}

// =====================================================
// CENTRALIZED UI THEME CONFIGURATION MATRICES
// =====================================================
$pcmMatrix = [
    'PC-0' => [
        'badgeText'   => 'EXISTING RECORD',
        'bgColor'     => '#17a2b8', // Info Teal
        'bgLight'     => '#eef9fa', // 🌟 Fixed: Solid flat Hex background for PDF engine parsing safety
        'textColor'   => '#117a8b',
        'borderColor' => '#cbeaf0'  // 🌟 Fixed: Solid flat Hex border
    ],
    'PC-1' => [
        'badgeText'   => 'READY TO CREATE',
        'bgColor'     => '#198754', // Emerald Success Green
        'bgLight'     => '#e8f5e9', 
        'textColor'   => '#198754',
        'borderColor' => '#a3cfbb'
    ],
    'PC-2' => [
        'badgeText'   => 'NEW LOCATION & CONTACT',
        'bgColor'     => '#0dcaf0', // Info Cyan
        'bgLight'     => '#e0f7fa', 
        'textColor'   => '#0a58ca',
        'borderColor' => '#9eeaf9'
    ],
    'PC-3' => [
        'badgeText'   => 'NEW CONTACT',
        'bgColor'     => '#6f42c1', // Purple
        'bgLight'     => '#f3e5f5', 
        'textColor'   => '#6f42c1',
        'borderColor' => '#e1bee7'
    ],
    'PC-4' => [
        'badgeText'   => 'LINK & MAP LOCATION',
        'bgColor'     => '#0d6efd', // Primary Blue
        'bgLight'     => '#e7f1ff',
        'textColor'   => '#0d6efd',
        'borderColor' => '#b6d4fe'
    ],
    'PC-5' => [
        'badgeText'   => 'NEW LOCATION ONLY',
        'bgColor'     => '#0d6efd', // Primary Blue
        'bgLight'     => '#e7f1ff',
        'textColor'   => '#0d6efd',
        'borderColor' => '#b6d4fe'
    ],
    'PC-6' => [ // 🌟 NEW: Contact Succession Visual Identity Theme
        'badgeText'   => 'CONTACT SUCCESSION',
        'bgColor'     => '#0f766e', // Deep Dark Teal
        'bgLight'     => '#e6fffb', // Soft Mint Light
        'textColor'   => '#0f766e', 
        'borderColor' => '#99f6e4'  // Balanced Transition Border
    ]
];

$rsMatrix = [
    'RS-3' => ['badgeText' => 'INCOMPLETE', 'bgColor' => '#ffc107', 'bgLight' => '#fff9db', 'textColor' => '#664d03', 'borderColor' => '#ffecb5'],
    'RS-5' => ['badgeText' => 'DUPLICATE', 'bgColor' => '#dc3545', 'bgLight' => '#f8d7da', 'textColor' => '#842029', 'borderColor' => '#f5c2c7'],
    'RS-6' => ['badgeText' => 'REVIEW REQUIRED', 'bgColor' => '#fd7e14', 'bgLight' => '#fff3cd', 'textColor' => '#b95000', 'borderColor' => '#ffe69c'],
    'RS-7' => ['badgeText' => 'PARCEL UNRESOLVED', 'bgColor' => '#dc3545', 'bgLight' => '#f8d7da', 'textColor' => '#842029', 'borderColor' => '#f5c2c7'],
    'RS-8' => ['badgeText' => 'LOCATION INVALID', 'bgColor' => '#dc3545', 'bgLight' => '#f8d7da', 'textColor' => '#842029', 'borderColor' => '#f5c2c7']
];

// 1. Establish Baseline Proposal Classification Mode
if (($pc === 'UNKNOWN' || empty($pc)) && (strpos(strtolower($contentLine ?? ''), 'existing') !== false)) {
    $pc = 'PC-0';
}

// Map baseline styles, falling back cleanly to a default gray state if undefined
$theme = $pcmMatrix[$pc] ?? [
    'badgeText'   => 'REVIEW STATE',
    'bgColor'     => '#6c757d', 
    'bgLight'     => '#f8f9fa', 
    'textColor'   => '#495057',
    'borderColor' => '#dee2e6'
];

// 2. Evaluate High-Priority Governance Code Overrides sequentially
if (!empty($rsList) && !in_array('RS-0', $rsList)) {
    foreach (['RS-3', 'RS-5', 'RS-6', 'RS-7', 'RS-8'] as $rsKey) {
        if (in_array($rsKey, $rsList) && isset($rsMatrix[$rsKey])) {
            $theme = $rsMatrix[$rsKey];
            break; // ✅ FIXED: Correct keyword usage eliminates Intelephense diagnostics
        }
    }
}

// 3. Inject Completed Presentation Model directly into UI State
$uiState['theme'] = [
    'pc'          => $pc,
    'rs'          => $rsList,
    'badgeText'   => $theme['badgeText'],
    'bgColor'     => $theme['bgColor'],
    'bgLight'     => $theme['bgLight'],
    'textColor'   => $theme['textColor'],
    'borderColor' => $theme['borderColor'],
    'variant'     => strtolower($pc ?: 'default')
];

// Synchronize tracking variables back into parent $pcm wrapper for context consistency
$pcm['pc'] = $pc;
$pcm['rs'] = $rsList;

// =====================================================
// AI Content Line & Narrative Builder Injection
// =====================================================
error_log('[PPC][SECTION-14] Gathering runtime context for operational AI narratives...');

// Flatten out data references so the AI prompt can find the keys immediately at the root level
$narrativeContext = [
    'pcmStatus'          => $pc,
    'pcm'                => $pcm,
    'databaseResolution' => $databaseResolution ?? [],
    'governance'         => $governance ?? [],
    
    // Explicitly flattened keys matching the prompt definitions exactly
    'contactFirstName'   => $data['contact']['contactFirstName'] ?? '',
    'contactLastName'    => $data['contact']['contactLastName'] ?? '',
    'entityName'         => $data['entity']['entityName'] ?? '',
    'locationCity'       => $data['location']['locationCity'] ?? '',
    'locationState'      => $data['location']['locationState'] ?? '',
    
    // Retain original arrays for full context evaluations
    'entity'             => $data['entity'] ?? [],
    'contact'            => $data['contact'] ?? [],
    'location'           => $data['location'] ?? []
];

// Call the utility function updated in processProposedContact.utils.php
$aiNarrativeResult = buildOperationalNarratives($narrativeContext);

// Strategy Fallback: Handle hardcoded defaults for PC-6 if buildOperationalNarratives hasn't been updated yet
if ($pc === 'PC-6') {
    $contentLine = $aiNarrativeResult['contentLine'] ?? 'CONTACT SUCCESSION';
    
    $fallbackDecision = [
        'Create replacement contact.',
        'Retire previous contact to preserve historical operational data.'
    ];
    $fallbackInformational = [
        'The proposal relocates an existing contact to a new location.',
        'The current contact record will be retired to preserve historical references.',
        'A replacement contact will be created and linked to the new location.'
    ];

    $aiNarrativeResult['decision']      = $aiNarrativeResult['decision'] ?? $fallbackDecision;
    $aiNarrativeResult['informational'] = $aiNarrativeResult['informational'] ?? $fallbackInformational;
} else {
    $contentLine = $aiNarrativeResult['contentLine'] ?? 'Proposal Information Update';
}

// =====================================================
// AI Content Line & Narrative Builder Injection Output Packaging
// =====================================================

// 1. Format the structural narratives array with the computed theme nested directly inside it
$narratives = [
    'contentLine'=> $contentLine, 
    'ui'         => $aiNarrativeResult['decision'][0] ?? 'Proposal processing routing initiated.',
    'report'     => implode(' ', $aiNarrativeResult['informational'] ?? []),
    'decisions'  => $aiNarrativeResult['decision'] ?? [],
    'blocking'   => $aiNarrativeResult['blocking'] ?? [],
    'review'     => $aiNarrativeResult['review'] ?? [],
    'info'       => $aiNarrativeResult['informational'] ?? [],
    
    // Nest inside narratives for the layout engine
    'theme'      => $uiState['theme']
];

// 2. Explicitly bind the flat parent attributes expected downstream
if (isset($data) && is_array($data)) {
    $data['proposalCode']     = $pc;
    $data['resolutionStatus'] = $uiState['proposalStatus'];
    $data['narratives']       = $narratives;
    $data['theme']            = $uiState['theme'];
}

if (isset($proposal) && is_array($proposal)) {
    $proposal['proposalCode']     = $pc;
    $proposal['resolutionStatus'] = $uiState['proposalStatus'];
    $proposal['narratives']       = $narratives;
    $proposal['theme']            = $uiState['theme'];
}

error_log("[PPC][SECTION-14] AI Narrative Generation complete → Content Line: '{$contentLine}' | Bound Code: {$pc}");

#endregion

#region SECTION 15 — Final Output Builder

// =====================================================
// 🌟 DYNAMIC TEXT OVERRIDES FOR PC-6 LIFECYCLES (Catch-All Sanitization)
// =====================================================
if (($pcm['pc'] ?? '') === 'PC-6') {
    $fullName   = trim(($data['contact']['contactFirstName'] ?? '') . ' ' . ($data['contact']['contactLastName'] ?? ''));
    $entityName = $data['entity']['entityName'] ?? 'Existing Entity';
    
    // 1. Correct the high-level description line
    $narratives['contentLine'] = "Contact Succession Proposal for {$fullName} at {$entityName}";
    
    // 2. Overwrite the generic fallback UI text with explicit succession language
    $successionNarrative = "The proposal will retire the existing contact record, create a replacement contact associated with the new location, and preserve historical relationships with prior operational records.";
    $narratives['ui']        = $successionNarrative;
    $narratives['decisions'] = [$successionNarrative];
    
    // 3. Clear out the contradictory "unresolved" warning since a new location is expected behavior
    $narratives['review']    = []; 
}

// =====================================================
// 🔐 SECURE KEY PROJECTION FOR WORKSPACE AUTO-CHAINING
// =====================================================
$clientWorkspaceKey = getenv('GOOGLE_MAPS_API_KEY')
    ?: getenv('GOOGLE_MAPS_PLACE_ID_API_KEY')
    ?: '';

// =====================================================
// ARTIFACT GENERATION (Street View + Parcel Maps) — Centralized
// =====================================================
$googleKey = skyesoftGetEnv('GOOGLE_MAPS_STATIC_API_KEY') 
    ?: getenv('GOOGLE_MAPS_STATIC_API_KEY') 
    ?: '';

// 🌟 This method builds your structured multi-artifact array
$artifacts = generateProposalArtifacts(
    $data['location'] ?? [],
    $proposalId,
    $googleKey
);

// =====================================================
// FINAL ACTION RESPONSE UPDATE
// =====================================================
if (isset($_SESSION['lastContactProposalActionId']) && $pdo) {
    try {
        $finalResponseData = [
            'success'           => true,
            'status'            => 'proposed',
            'proposalId'        => $proposalId,
            'proposalActionId'  => $actionId ?? $_SESSION['lastContactProposalActionId'] ?? null,   // ← Add this
            'proposalKind'      => $proposalType ?? 'contact',
            'proposalParser'    => ($proposalType === 'location' ? 'location' : 'contact'),
            'data'              => $data ?? [],
            'databaseResolution'=> $databaseResolution ?? [],
            'pcm'               => $pcm ?? [],
            'commitPlan'        => $commitPlan ?? [],
            'ui'                => $uiState ?? [],
            'governance'        => $governance ?? [],
            'narratives'        => $narratives ?? [],
            'meta'              => $proposalSnapshot['meta'] ?? [],
            'rawInput'          => $proposalSnapshot['rawInput'] ?? [],

            // 🌟 1. Saved directly to your Actions Table History Log
            'reportArtifacts'   => $artifacts
        ];

        $finalResponseData['google']['apiKey'] = $clientWorkspaceKey;

        $updateStmt = $pdo->prepare("
            UPDATE tblActions 
            SET actionResponseData = ? 
            WHERE actionId = ?
        ");
        
        $updateStmt->execute([
            json_encode($finalResponseData, JSON_UNESCAPED_SLASHES),
            $_SESSION['lastContactProposalActionId']
        ]);

        error_log('[PPC][ACTION-LOG] ✅ actionResponseData updated for ActionID: ' . $_SESSION['lastContactProposalActionId']);

    } catch (Throwable $e) {
        error_log("[PPC][ACTION-LOG] Final response update failed: " . $e->getMessage());
    }
}

// =====================================================
// FINAL OUTPUT
// =====================================================
echo json_encode([
    'success'           => true,
    'status'            => 'proposed',
    'proposalId'        => $proposalId,
    'proposalActionId'  => $actionId ?? $_SESSION['lastContactProposalActionId'] ?? null,   // ← Add this
    'proposalKind'      => $proposalType ?? 'contact',
    'proposalParser'    => ($proposalType === 'location' ? 'location' : 'contact'),
    'activitySessionId' => $context['activitySessionId'] ?? '',

    'google' => [
        'apiKey' => $clientWorkspaceKey
    ],

    'data' => [
        'entity'   => $data['entity']   ?? [],
        'contact'  => $data['contact']  ?? [],
        'location' => $data['location'] ?? []
    ],

    'databaseResolution' => $databaseResolution ?? [],
    'pcm' => $pcm ?? [],
    'commitPlan' => $commitPlan ?? [],
    'ui' => $uiState ?? [],
    'governance' => $governance ?? ['blockingIssues' => []],
    'narratives' => $narratives ?? [], 
    'meta' => $proposalSnapshot['meta'] ?? [],
    'rawInput' => $proposalSnapshot['rawInput'] ?? [],

    // 🌟 2. Outputted in real-time to the UI Engine for the baseReport compiler
    'reportArtifacts' => $artifacts

], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion