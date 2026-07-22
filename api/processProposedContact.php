<?php
declare(strict_types=1);

/**
 * Skyesoft — processProposedContact.php
 * Main Orchestration + Proposal Report Generation
 * 
 * File Version:     1.6.6
 * Schema Version:   2.1.1
 * Last Updated:     2026-07-17
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
    $snapshotData = [];
    $targetedSnapshotPath = '';

    // 📍 SNAPSHOT RECOVERY — Authoritative for proposal lifecycle
    $snapshotDir = __DIR__ . '/../data/runtimeEphemeral/proposals';
    if (!empty($targetProposalId) && is_dir($snapshotDir)) {
        $targetedSnapshotPath = $snapshotDir . "/{$targetProposalId}.json";
        if (is_file($targetedSnapshotPath)) {
            $snapshotRaw = @file_get_contents($targetedSnapshotPath);
            if ($snapshotRaw) {
                $snapshotData = json_decode($snapshotRaw, true) ?? [];

                $cachedSessionId = trim(
                    $snapshotData['activitySessionId']
                    ?? $snapshotData['data']['activitySessionId']
                    ?? $snapshotData['context']['activitySessionId']
                    ?? ''
                );
            }
        }
    }

    // ── Multi-Tiered Latitude & Longitude Resolution ──
    // Tier 1: Proposal snapshot (authoritative)
    // Tier 2: UI payload fallback
    $lat = $snapshotData['data']['location']['locationLatitude']
        ?? $snapshotData['location']['latitude']
        ?? $inputData['data']['location']['locationLatitude']
        ?? $inputData['location']['locationLatitude']
        ?? $inputData['location']['latitude']
        ?? null;

    $lon = $snapshotData['data']['location']['locationLongitude']
        ?? $snapshotData['location']['longitude']
        ?? $inputData['data']['location']['locationLongitude']
        ?? $inputData['location']['locationLongitude']
        ?? $inputData['location']['longitude']
        ?? null;

    error_log(sprintf(
        '[PPC][DECLINE-GEO] Proposal #%s | Snapshot=%s | Lat=%s | Lon=%s | Payload=%s',
        $targetProposalId,
        !empty($snapshotData) ? 'YES' : 'NO',
        var_export($lat, true),
        var_export($lon, true),
        json_encode($inputData['data']['location'] ?? $inputData['location'] ?? [])
    ));

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
        if (!empty($targetProposalId) && !empty($targetedSnapshotPath)) {
            if (is_file($targetedSnapshotPath)) {
                @unlink($targetedSnapshotPath);
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

#region SECTION 03 — Proposal Parser Dispatch (Improved PC-4 Detection)

$proposalType = $proposalType ?? 'contact';

error_log("[PPC][SECTION-03] Proposal Type detected: {$proposalType}");

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
// IMPROVED LOCATION-ONLY INTENT DETECTION
// =====================================================
$isExplicitLocationOnlyIntent = ($proposalType === 'location');

if (!$isExplicitLocationOnlyIntent) {
    // Heuristic: If the input has multiple "name-like" lines but no phone/email, treat as location
    $nameCount = 0;
    $hasContactInfo = false;

    foreach ($lines as $line) {
        if (preg_match('/\b(?:Mr|Ms|Dr|Director|Manager|Coordinator|President|CEO)\b/i', $line)) {
            $hasContactInfo = true;
        }
        if (preg_match('/^([A-Z][a-zA-Z0-9&\'-]+(?:\s+[A-Z][a-zA-Z0-9&\'-]+){1,3})$/', trim($line))) {
            $nameCount++;
        }
        if (preg_match('/@|(\d{3}[-.\s]?\d{3}[-.\s]?\d{4})/', $line)) {
            $hasContactInfo = true;
        }
    }

    if ($nameCount >= 2 && !$hasContactInfo) {
        error_log('[PPC][SECTION-03] → Multiple names + no contact info → forcing location-only mode');
        $isExplicitLocationOnlyIntent = true;
    }
}

// =====================================================
// DISPATCH TO APPROPRIATE PARSER
// =====================================================
if ($isExplicitLocationOnlyIntent) {
    $parsed = parseLocationProposal($lines, $inputData['inputData'] ?? [], $rawInputOriginal);
    error_log('[PPC][SECTION-03] → Location Parser dispatched (PC-4/PC-5)');
} else {
    $parsed = parseContactProposal($rawInput);
    error_log('[PPC][SECTION-03] → Contact Parser dispatched (legacy AI path)');
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
    'locationAddressRaw' => $parsed['location']['address'] ?? '',
    'locationCity'       => $parsed['location']['city'] ?? '',
    'locationState'      => $parsed['location']['state'] ?? '',
    'locationZip'        => $parsed['location']['zip'] ?? '',

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

        // Build deterministic Google address component map
        $googleComponents = [];

        foreach (($result['address_components'] ?? []) as $component) {
            foreach (($component['types'] ?? []) as $componentType) {
                $googleComponents[$componentType] = [
                    'long'  => $component['long_name'] ?? '',
                    'short' => $component['short_name'] ?? ''
                ];
            }
        }

        // Normalize comparable street text
        $normalizeStreet = function ($value) {
            $value = strtolower(trim((string)$value));
            $value = preg_replace('/[^a-z0-9 ]+/', ' ', $value);
            $value = preg_replace('/\s+/', ' ', $value);

            $replacements = [
                ' north ' => ' n ',
                ' south ' => ' s ',
                ' east ' => ' e ',
                ' west ' => ' w ',
                ' street' => ' st',
                ' avenue' => ' ave',
                ' boulevard' => ' blvd',
                ' road' => ' rd',
                ' drive' => ' dr',
                ' lane' => ' ln',
                ' court' => ' ct',
                ' place' => ' pl',
                ' parkway' => ' pkwy',
                ' highway' => ' hwy',
                ' circle' => ' cir',
                ' terrace' => ' ter',
                ' trail' => ' trl',
                ' way' => ' way'
            ];

            return trim(str_replace(
                array_keys($replacements),
                array_values($replacements),
                ' ' . $value
            ));
        };

        // Extract submitted street number and route
        $submittedStreet = trim((string)($data['location']['locationAddress'] ?? ''));
        $submittedStreet = preg_split('/[\r\n,]+/', $submittedStreet)[0] ?? '';
        $submittedNumber = '';
        $submittedRoute = $submittedStreet;

        if (preg_match('/^\s*([0-9]+[a-zA-Z]?)\s+(.+)$/', $submittedStreet, $streetMatch)) {
            $submittedNumber = strtolower($streetMatch[1]);
            $submittedRoute = $streetMatch[2];
        }

        $resolvedNumber = strtolower((string)(
            $googleComponents['street_number']['short']
            ?? ''
        ));

        $resolvedRoute = (string)(
            $googleComponents['route']['long']
            ?? ''
        );

        $submittedZip = substr(
            preg_replace('/[^0-9]/', '', (string)($data['location']['locationZip'] ?? '')),
            0,
            5
        );

        $resolvedZip = substr(
            preg_replace('/[^0-9]/', '', (string)(
                $googleComponents['postal_code']['short']
                ?? ''
            )),
            0,
            5
        );

        $submittedCity = strtolower(trim((string)(
            $data['location']['locationCity']
            ?? ''
        )));

        $resolvedCity = strtolower(trim((string)(
            $googleComponents['locality']['long']
            ?? $googleComponents['postal_town']['long']
            ?? $googleComponents['administrative_area_level_3']['long']
            ?? ''
        )));

        $submittedState = strtoupper(trim((string)(
            $data['location']['locationState']
            ?? ''
        )));

        $resolvedState = strtoupper(trim((string)(
            $googleComponents['administrative_area_level_1']['short']
            ?? ''
        )));

        // Determine whether Google resolved the submitted physical address
        $addressMismatches = [];

        if (!empty($result['partial_match'])) {
            $addressMismatches[] = 'partial_match';
        }

        if ($submittedNumber === '' || $resolvedNumber === '') {
            $addressMismatches[] = 'street_number_missing';
        } elseif ($submittedNumber !== $resolvedNumber) {
            $addressMismatches[] = 'street_number_mismatch';
        }

        if ($submittedRoute === '' || $resolvedRoute === '') {
            $addressMismatches[] = 'street_route_missing';
        } elseif ($normalizeStreet($submittedRoute) !== $normalizeStreet($resolvedRoute)) {
            $addressMismatches[] = 'street_route_mismatch';
        }

        if (
            $submittedCity !== '' &&
            $resolvedCity !== '' &&
            $submittedCity !== $resolvedCity
        ) {
            $addressMismatches[] = 'city_mismatch';
        }

        if (
            $submittedState !== '' &&
            $resolvedState !== '' &&
            $submittedState !== $resolvedState
        ) {
            $addressMismatches[] = 'state_mismatch';
        }

        if (
            $submittedZip !== '' &&
            $resolvedZip !== '' &&
            $submittedZip !== $resolvedZip
        ) {
            $addressMismatches[] = 'zip_mismatch';
        }

        $isMaterialAddressMismatch = !empty($addressMismatches);

        $data['location']['locationPlaceId']   = $result['place_id'] ?? null;
        $data['location']['locationLatitude']  = $result['geometry']['location']['lat'] ?? null;
        $data['location']['locationLongitude'] = $result['geometry']['location']['lng'] ?? null;
        $data['location']['locationValidated'] = !$isMaterialAddressMismatch;
        $data['location']['locationResolvedAddress'] =
            $result['formatted_address'] ?? '';
        $data['location']['locationMatchQuality'] = [
            'partialMatch' => !empty($result['partial_match']),
            'locationType' => $result['geometry']['location_type'] ?? null,
            'mismatches'   => $addressMismatches
        ];

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

        error_log(
            '[PPC][SECTION-08] Google validation ' .
            ($data['location']['locationValidated'] ? 'PASS' : 'FAIL') .
            ' | Resolved: ' . ($data['location']['locationResolvedAddress'] ?: 'None') .
            ' | Mismatches: ' .
            (empty($addressMismatches) ? 'None' : implode(', ', $addressMismatches)) .
            ' | County: ' . ($data['location']['locationCounty'] ?? 'None')
        );

    } else {
        error_log('[PPC][SECTION-08] Google returned no results');
        $data['location']['locationValidated'] = false;
    }

} else {
    error_log('[PPC][SECTION-08] Skipping Google geocode (missing address or API key)');
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
require_once __DIR__ . '/utils/resolveZoning.php';

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
    count($data['location']['parcelDetails']);

$data['location']['jurisdictionName'] =
    $parcelResult['jurisdictionName'] ?? null;

$data['location']['jurisdictionType'] =
    $parcelResult['jurisdictionType'] ?? null;

$data['location']['hasMultipleParcels'] =
    ($data['location']['parcelCount'] > 1);

// =====================================================
// MARICOPA COUNTY ASSESSOR REQUEST CONFIGURATION
// =====================================================

$token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';

$httpHeaders = [
    'Accept: application/json, text/plain, */*',
    'Cache-Control: no-cache'
];

if ($token !== '') {
    $httpHeaders[] = 'Authorization: ' . trim($token);
}

// Maricopa County requires an empty User-Agent.
$userAgent = '';

// =====================================================
// CANONICAL VALUE NORMALIZERS
// =====================================================

// Find first populated key (recursive API response search).
$findValue = null;
$findValue = function(array $sources, array $possibleKeys, $default = null) use (&$findValue) {
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        foreach ($possibleKeys as $key) {
            if (
                array_key_exists($key, $source) &&
                $source[$key] !== '' &&
                $source[$key] !== null
            ) {
                return $source[$key];
            }
        }

        foreach ($source as $value) {
            if (!is_array($value)) {
                continue;
            }

            $match = $findValue([$value], $possibleKeys, null);

            if ($match !== null && $match !== '') {
                return $match;
            }
        }
    }

    return $default;
};

// Normalize nullable text.
$normalizeText = function($value): ?string {
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    $normalizedValue = trim((string)$value);

    if ($normalizedValue === '' || strtoupper($normalizedValue) === 'N/A') {
        return null;
    }

    return $normalizedValue;
};

// Normalize nullable database integer.
$normalizeInteger = function($value): ?int {
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        return (int)round($value);
    }

    $normalizedValue = str_replace(',', '', trim((string)$value));

    if ($normalizedValue === '' || !is_numeric($normalizedValue)) {
        return null;
    }

    return (int)round((float)$normalizedValue);
};

// Build a readable owner mailing address.
$buildMailingAddress = function(array $ownerRecord) use ($normalizeText): ?string {
    $parts = [];

    foreach (['MailingAddress1', 'MailingAddress2'] as $key) {
        $value = $normalizeText($ownerRecord[$key] ?? null);

        if ($value !== null) {
            $parts[] = $value;
        }
    }

    $city  = $normalizeText($ownerRecord['MailingCity'] ?? $ownerRecord['City'] ?? null);
    $state = $normalizeText($ownerRecord['MailingState'] ?? $ownerRecord['State'] ?? null);
    $zip   = $normalizeText($ownerRecord['MailingZip'] ?? $ownerRecord['Zip'] ?? null);

    $cityStateZip = trim(
        ($city ?? '') .
        ($city !== null && $state !== null ? ', ' : '') .
        ($state ?? '') .
        (($city !== null || $state !== null) && $zip !== null ? ' ' : '') .
        ($zip ?? '')
    );

    if ($cityStateZip !== '') {
        $parts[] = $cityStateZip;
    }

    return !empty($parts) ? implode(', ', $parts) : null;
};

// =====================================================
// ENRICH EACH PARCEL + BUILD PERSISTENCE-READY RECORD
// =====================================================

foreach ($data['location']['parcelDetails'] as &$parcel) {
    $apn = $normalizeText(
        $parcel['parcelNumber']
        ?? $parcel['apnRaw']
        ?? null
    );

    if ($apn === null) {
        $parcel['assessor'] = [
            'status'        => 'unresolved',
            'lastRetrieved' => null,
            'mapId'         => null,
            'mapUrl'        => null,
            'mapImage'      => null,
            'mapPdf'        => null
        ];

        $parcel['parcelRecord'] = [
            'parcelDetailsId'   => null,
            'locationId'        => null,
            'apnRaw'            => null,
            'ownerName'         => null,
            'subdivision'       => null,
            'lotSize'           => null,
            'yearBuilt'         => null,
            'zoningCode'        => null,
            'zoningDescription' => null,
            'zoningSource'      => null,
            'zoningVerifiedAt'  => null,
            'source'            => null,
            'confidence'        => 0,
            'createdAt'         => time(),
            'updatedAt'         => null
        ];

        continue;
    }

    $parcel['parcelNumber'] = $apn;

    $parcel['assessor'] = [
        'propertyType' => null,
        'mapId'        => null,
        'mapUrl'       => null,
        'status'       => 'pending'
    ];

    // -------------------------------------------------
    // 1. Core parcel endpoint
    // -------------------------------------------------
    $coreUrl = 'https://mcassessor.maricopa.gov/parcel/' . urlencode($apn);

    error_log('[PPC][SECTION-10] Calling CORE: ' . $coreUrl);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $coreUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_HTTPHEADER     => $httpHeaders
    ]);

    $coreResponse = curl_exec($ch);
    $coreHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $coreError    = curl_error($ch);
    curl_close($ch);

    $coreData = [];

    if ($coreHttpCode === 200 && is_string($coreResponse) && $coreResponse !== '') {
        $decodedCore = json_decode($coreResponse, true);
        $coreData = is_array($decodedCore) ? $decodedCore : [];
    }

    if ($coreError !== '') {
        error_log('[PPC][SECTION-10] CORE cURL error for ' . $apn . ': ' . $coreError);
    }

    // -------------------------------------------------
    // 2. Property information fallback endpoint
    // -------------------------------------------------
    $propertyUrl =
        'https://mcassessor.maricopa.gov/parcel/' .
        urlencode($apn) .
        '/propertyinfo';

    error_log('[PPC][SECTION-10] Calling PROPERTYINFO: ' . $propertyUrl);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $propertyUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_HTTPHEADER     => $httpHeaders
    ]);

    $propertyResponse = curl_exec($ch);
    $propertyHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $propertyError    = curl_error($ch);
    curl_close($ch);

    $propertyData = [];

    if (
        $propertyHttpCode === 200 &&
        is_string($propertyResponse) &&
        $propertyResponse !== ''
    ) {
        $decodedProperty = json_decode($propertyResponse, true);
        $propertyData = is_array($decodedProperty) ? $decodedProperty : [];
    }

    if ($propertyError !== '') {
        error_log('[PPC][SECTION-10] PROPERTYINFO cURL error for ' . $apn . ': ' . $propertyError);
    }

    $lookupResolved = !empty($coreData) || !empty($propertyData);
    $retrievedAt    = time();

    // -------------------------------------------------
    // 3. Canonical location identity
    // -------------------------------------------------
    $parcel['siteAddress'] = $normalizeText($findValue(
        [$coreData, $propertyData],
        ['PropertyAddress', 'Address', 'situsAddress'],
        $parcel['siteAddress'] ?? null
    ));

    $parcel['city'] = $normalizeText($findValue(
        [$coreData, $propertyData],
        ['City', 'PhysicalCity', 'PHYSICAL_CITY'],
        $parcel['city'] ?? null
    ));

    $parcel['jurisdiction'] = $normalizeText($findValue(
        [$coreData, $propertyData],
        ['Jurisdiction', 'Municipality', 'City'],
        $parcel['jurisdiction'] ?? null
    ));

    if (empty($parcel['source'])) {
        $parcel['source'] = 'arcgis_coordinate';
    }

    // -------------------------------------------------
    // 4. Canonical owner object
    // -------------------------------------------------
    $ownerRecord = $coreData['Owner']
        ?? $coreData['ownerName']
        ?? $coreData['OwnerName']
        ?? $propertyData['Owner']
        ?? $propertyData['ownerName']
        ?? $propertyData['OwnerName']
        ?? [];

    if (!is_array($ownerRecord)) {
        $ownerRecord = [
            'Ownership' => $normalizeText($ownerRecord)
        ];
    }

    $ownerName = $normalizeText($findValue(
        [$ownerRecord, $coreData, $propertyData],
        ['Ownership', 'OwnerName', 'OWNER_NAME'],
        null
    ));

    $ownerMailingAddress = $normalizeText($findValue(
        [$ownerRecord],
        ['FullMailingAddress', 'MailingAddress', 'MAILING_ADDRESS'],
        null
    ));

    if ($ownerMailingAddress === null) {
        $ownerMailingAddress = $buildMailingAddress($ownerRecord);
    }

    $parcel['owner'] = [
        'name'           => $ownerName,
        'mailingAddress' => $ownerMailingAddress,
        'inCareOf'       => $normalizeText($ownerRecord['InCareOf'] ?? null)
    ];

    // Legacy scalar retained during downstream migration.
    $parcel['ownerName'] = $ownerName;

    // -------------------------------------------------
    // 5. Assessor values
    // -------------------------------------------------
    $propertyType = $normalizeText($findValue(
        [$coreData, $propertyData],
        ['PropertyType', 'PROPERTY_TYPE', 'propertyType', 'Type'],
        null
    ));

    $subdivision = $normalizeText($findValue(
        [$coreData, $propertyData],
        [
            'SubdivisionName',
            'Subdivision',
            'SUBDIVISION',
            'subdivision',
            'Subdiv'
        ],
        null
    ));

    $lotSize = $normalizeInteger($findValue(
        [$coreData, $propertyData],
        ['LotSize', 'LOT_SIZE', 'LotSizeSqFt', 'lotSize'],
        null
    ));

    $yearBuilt = $normalizeInteger($findValue(
        [$coreData, $propertyData],
        [
            'ConstructionYear',
            'CONSTRUCTION_YEAR',
            'YearBuilt',
            'YEAR_BUILT',
            'constructionYear',
            'Year Built'
        ],
        null
    ));

    $parcel['assessor']['propertyType'] = $propertyType;
    $parcel['assessor']['status'] = $lookupResolved
        ? 'resolved'
        : 'unresolved';

    // -------------------------------------------------
    // 6. Persistence contract for tblLocationParcelDetails
    // -------------------------------------------------
    $parcel['parcelRecord'] = [
        'parcelDetailsId'   => null,
        'locationId'        => null,
        'apnRaw'            => $apn,
        'ownerName'         => $ownerName,
        'subdivision'       => $subdivision,
        'lotSize'           => $lotSize,
        'yearBuilt'         => $yearBuilt,
        'zoningCode'        => null,
        'zoningDescription' => null,
        'zoningSource'      => null,
        'zoningVerifiedAt'  => null,
        'source'            => 'maricopa_assessor',
        'confidence'        => $lookupResolved ? 95 : 0,
        'createdAt'         => $retrievedAt,
        'updatedAt'         => null
    ];

    // -------------------------------------------------
    // 7. Parcel map ID enrichment
    // -------------------------------------------------
    if ($token !== '') {
        $mapLookupUrl =
            'https://mcassessor.maricopa.gov/mapid/parcel/' .
            urlencode($apn);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $mapLookupUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => $httpHeaders
        ]);

        $mapResponse = curl_exec($ch);
        $mapHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mapError    = curl_error($ch);
        curl_close($ch);

        if ($mapError !== '') {
            error_log('[PPC][SECTION-10] MAPID cURL error for ' . $apn . ': ' . $mapError);
        }

        if ($mapHttpCode === 200 && is_string($mapResponse) && $mapResponse !== '') {
            $mapData = json_decode($mapResponse, true);

            if (is_array($mapData) && !empty($mapData[0])) {
                $mapItem = $mapData[0];

                $mapId = is_string($mapItem)
                    ? $mapItem
                    : (
                        $mapItem['FileName']
                        ?? $mapItem['fileName']
                        ?? $mapItem['mapId']
                        ?? null
                    );

                $mapId = $normalizeText($mapId);

                if ($mapId !== null) {
                    $mapId = preg_replace('/\.pdf$/i', '', $mapId);

                    $parcel['assessor']['mapId'] = $mapId;
                    $parcel['assessor']['mapUrl'] =
                        'https://mcassessor.maricopa.gov/getmapid/' .
                        rawurlencode($mapId) .
                        '/';
                }
            }
        }
    }

    // Record readiness describes the JSON, not commit authorization.
    $parcel['parcelRecordReady'] = (
        $parcel['parcelRecord']['apnRaw'] !== null &&
        $lookupResolved
    );
}

unset($parcel);

// =====================================================
// CANONICAL JURISDICTION RESOLUTION
// =====================================================

$registryPath = __DIR__ . '/../data/authoritative/jurisdictionRegistry.json';
$jurisdictionRegistry = [];

if (is_file($registryPath)) {
    $registryRaw = file_get_contents($registryPath);
    $decodedRegistry = json_decode((string)$registryRaw, true);

    if (is_array($decodedRegistry)) {
        $jurisdictionRegistry = $decodedRegistry;
    } else {
        error_log(
            '[PPC][SECTION-10] Invalid jurisdiction registry JSON: ' .
            $registryPath
        );
    }
} else {
    error_log(
        '[PPC][SECTION-10] Jurisdiction registry not found: ' .
        $registryPath
    );
}

$normalizeJurisdiction = static function ($value): string {
    return preg_replace(
        '/[^A-Z0-9]/',
        '',
        strtoupper(trim((string)$value))
    ) ?? '';
};

$rawJurisdiction = trim(
    (string)($data['location']['jurisdictionName'] ?? '')
);

if (
    $rawJurisdiction === '' &&
    !empty($data['location']['parcelDetails'])
) {
    $firstParcel = reset($data['location']['parcelDetails']);

    $rawJurisdiction = trim(
        (string)($firstParcel['jurisdiction'] ?? '')
    );
}

$registryMatch = null;
$normalizedJurisdiction = $normalizeJurisdiction($rawJurisdiction);

foreach ($jurisdictionRegistry as $registryKey => $registryEntry) {
    if (!is_array($registryEntry)) {
        continue;
    }

    $candidates = array_merge(
        [$registryKey, $registryEntry['label'] ?? ''],
        $registryEntry['aliases'] ?? []
    );

    foreach ($candidates as $candidate) {
        if (
            $normalizedJurisdiction !== '' &&
            $normalizeJurisdiction($candidate) === $normalizedJurisdiction
        ) {
            $registryMatch = $registryEntry;
            break 2;
        }
    }
}

if ($registryMatch !== null) {
    $data['location']['jurisdictionName'] =
        $registryMatch['label'] ?? $rawJurisdiction;

    $data['location']['jurisdictionType'] =
        $registryMatch['jurisdictionType'] ?? null;
} elseif (
    $rawJurisdiction !== '' &&
    strtoupper($rawJurisdiction) !== 'N/A'
) {
    $data['location']['jurisdictionName'] =
        ucwords(strtolower($rawJurisdiction));

    $data['location']['jurisdictionType'] = null;
}

error_log(
    '[PPC][SECTION-10] Parcel resolution + enrichment complete. ' .
    'Count=' . ($data['location']['parcelCount'] ?? 0) .
    ' | Jurisdiction=' . ($data['location']['jurisdictionName'] ?? 'NULL') .
    ' | Type=' . ($data['location']['jurisdictionType'] ?? 'NULL')
);

#endregion

#region SECTION 11 — Jurisdictional Zoning Resolution

// =====================================================
// SELECT GOVERNING PARCEL FOR ZONING
// =====================================================

$zoningParcelIndex = null;
$parcelDetails = $data['location']['parcelDetails'] ?? [];

foreach ($parcelDetails as $parcelIndex => $parcelCandidate) {
    if (!empty($parcelCandidate['accepted'])) {
        $zoningParcelIndex = $parcelIndex;
        break;
    }
}

// A single resolved parcel is deterministic without manual selection.
if ($zoningParcelIndex === null && count($parcelDetails) === 1) {
    $zoningParcelIndex = 0;
}

$zoningResult = [
    'success'           => false,
    'status'            => 'not_attempted',
    'reason'            => 'parcel_not_deterministic',
    'message'           => 'Zoning requires one resolved or accepted parcel.',
    'zoningCode'        => null,
    'zoningDescription' => null,
    'zoningSource'      => null,
    'zoningVerifiedAt'  => null,
    'confidence'        => 0,
    'requiresReview'    => true
];

if ($zoningParcelIndex !== null) {
    $zoningParcel = $parcelDetails[$zoningParcelIndex];
    $zoningApn = $zoningParcel['parcelRecord']['apnRaw']
        ?? $zoningParcel['parcelNumber']
        ?? null;

    $zoningJurisdiction = $data['location']['jurisdictionName']
        ?? $zoningParcel['jurisdiction']
        ?? null;

    $zoningResult = resolveZoning(
        $zoningJurisdiction,
        isset($data['location']['locationLatitude'])
            ? (float)$data['location']['locationLatitude']
            : null,
        isset($data['location']['locationLongitude'])
            ? (float)$data['location']['locationLongitude']
            : null,
        $zoningApn !== null ? (string)$zoningApn : null
    );

    $parcelDetails[$zoningParcelIndex]['zoning'] = [
        'status'            => $zoningResult['status'] ?? 'unresolved',
        'reason'            => $zoningResult['reason'] ?? null,
        'message'           => $zoningResult['message'] ?? null,
        'zoningCode'        => $zoningResult['zoningCode'] ?? null,
        'zoningDescription' => $zoningResult['zoningDescription'] ?? null,
        'zoningSource'      => $zoningResult['zoningSource'] ?? null,
        'zoningVerifiedAt'  => $zoningResult['zoningVerifiedAt'] ?? null,
        'confidence'        => (int)($zoningResult['confidence'] ?? 0),
        'requiresReview'    => (bool)($zoningResult['requiresReview'] ?? true)
    ];

    // Only verified zoning values enter the persistence contract.
    if (($zoningResult['status'] ?? null) === 'resolved') {
        $parcelDetails[$zoningParcelIndex]['parcelRecord']['zoningCode'] =
            $zoningResult['zoningCode'] ?? null;
        $parcelDetails[$zoningParcelIndex]['parcelRecord']['zoningDescription'] =
            $zoningResult['zoningDescription'] ?? null;
        $parcelDetails[$zoningParcelIndex]['parcelRecord']['zoningSource'] =
            $zoningResult['zoningSource'] ?? null;
        $parcelDetails[$zoningParcelIndex]['parcelRecord']['zoningVerifiedAt'] =
            $zoningResult['zoningVerifiedAt'] ?? null;
    }
}

$data['location']['parcelDetails'] = $parcelDetails;
$data['location']['zoning'] = [
    'status'            => $zoningResult['status'] ?? 'unresolved',
    'reason'            => $zoningResult['reason'] ?? null,
    'zoningCode'        => $zoningResult['zoningCode'] ?? null,
    'zoningDescription' => $zoningResult['zoningDescription'] ?? null,
    'zoningSource'      => $zoningResult['zoningSource'] ?? null,
    'zoningVerifiedAt'  => $zoningResult['zoningVerifiedAt'] ?? null,
    'confidence'        => (int)($zoningResult['confidence'] ?? 0),
    'requiresReview'    => (bool)($zoningResult['requiresReview'] ?? true)
];

error_log(
    '[PPC][SECTION-10A] Zoning resolution complete. ' .
    'Status=' . ($zoningResult['status'] ?? 'unknown') .
    ' | Code=' . ($zoningResult['zoningCode'] ?? 'NULL') .
    ' | Source=' . ($zoningResult['zoningSource'] ?? 'NULL')
);

// =====================================================
// GEOGRAPHIC VALIDATION SUMMARY
// =====================================================

$resolvedCounty = strtolower(
    (string)($data['location']['locationCounty'] ?? '')
);
$locationValidated =
    (bool)($data['location']['locationValidated'] ?? false);
$locationCensusValidated =
    (bool)($data['location']['locationCensusValidated'] ?? false);
$parcelCount =
    (int)($data['location']['parcelCount'] ?? 0);

error_log(sprintf(
    '[PPC][VALIDATION] Google=%s | Census=%s | Parcels=%d | County=%s',
    $locationValidated ? 'PASS' : 'FAIL',
    $locationCensusValidated ? 'PASS' : 'FAIL',
    $parcelCount,
    $resolvedCounty
));

// Preserve the normal proposal pipeline. Section 13 assigns RS-8 after
// database-driven PC classification so PC-4 / PC-5 identity is retained.
if (!$locationValidated) {
    error_log(
        '[PPC][SECTION-11] Location validation failed; ' .
        'continuing to PCM governance for RS-8 assignment'
    );
}

#endregion

#region SECTION 12 — Database Resolution

$databaseResolution = [
    'entity'   => null,
    'location' => null,
    'contact'  => null
];

if ($pdo) {

    // Resolve entity
    $databaseResolution['entity'] = evaluateEntityDuplicate(
        $parsed,
        $pdo
    );

    // Resolve location
    $databaseResolution['location'] = evaluateLocationDuplicate(
        $parsed,
        $pdo
    );

    // Resolve contact identity
    $databaseResolution['contact'] = evaluateDuplicate(
        $parsed,
        $pdo
    );

    // Preserve authoritative identity determination
    $contactStatus = $databaseResolution['contact']['status']
        ?? 'none';

    $contactIdentityResult =
        $databaseResolution['contact']['identityResult']
        ?? 'no_match';

    $isDuplicateContact =
        !empty($databaseResolution['contact']['isDuplicate']) &&
        $contactIdentityResult === 'confirmed_duplicate';

    $duplicateReason =
        $databaseResolution['contact']['duplicateReason']
        ?? null;

    // Normalize propagated identity fields
    $databaseResolution['contact']['identityResult'] =
        $contactIdentityResult;

    $databaseResolution['contact']['isDuplicate'] =
        $isDuplicateContact;

    $databaseResolution['contact']['duplicateReason'] =
        $duplicateReason;

    // Evaluate contact location transfer
    $databaseResolution['contact']['isLocationTransfer'] = false;

    // Confirmed duplicates require governance review
    if ($contactStatus === 'exact' && !$isDuplicateContact) {

        // Resolve existing contact location
        $existingContactLocationId =
            $databaseResolution['contact']['locationId']
            ?? $databaseResolution['contact']['location_id']
            ?? null;

        // Resolve proposed location
        $proposedLocationId =
            $databaseResolution['location']['locationId']
            ?? $databaseResolution['location']['location_id']
            ?? null;

        $locationStatus =
            $databaseResolution['location']['status']
            ?? 'none';

        // Detect legitimate location succession
        if (
            !empty($existingContactLocationId) &&
            (
                $locationStatus === 'none' ||
                $existingContactLocationId != $proposedLocationId
            )
        ) {
            $databaseResolution['contact']['isLocationTransfer'] = true;
        }
    }

    error_log(
        '[PPC][SECTION-12] Database resolution complete' .
        ' | Contact status: ' . $contactStatus .
        ' | Identity: ' . $contactIdentityResult .
        ' | Duplicate: ' . ($isDuplicateContact ? 'YES' : 'NO') .
        ' | Transfer: ' .
        (
            $databaseResolution['contact']['isLocationTransfer']
                ? 'YES'
                : 'NO'
        )
    );

} else {
    error_log(
        '[PPC][SECTION-12] No PDO connection — skipping DB resolution'
    );
}

#endregion

#region SECTION 13 — PCM Classification & Governance

$isExplicitLocationOnlyIntent = $isExplicitLocationOnlyIntent ?? false;

// =====================================================
// PASS 1 — DATABASE-DRIVEN PCM CLASSIFICATION
// =====================================================

$entityStatus =
    $databaseResolution['entity']['status']
    ?? 'none';

$locationStatus =
    $databaseResolution['location']['status']
    ?? 'none';

$contactStatus =
    $databaseResolution['contact']['status']
    ?? 'none';

$contactIdentityResult =
    $databaseResolution['contact']['identityResult']
    ?? 'no_match';

$isDuplicateContact =
    !empty($databaseResolution['contact']['isDuplicate']) &&
    $contactIdentityResult === 'confirmed_duplicate';

$isLocationTransfer =
    !empty($databaseResolution['contact']['isLocationTransfer']);

error_log(
    '[PPC][SECTION-13] Database resolution' .
    ' | Entity: ' . $entityStatus .
    ' | Location: ' . $locationStatus .
    ' | Contact: ' . $contactStatus .
    ' | Identity: ' . $contactIdentityResult .
    ' | Duplicate: ' . ($isDuplicateContact ? 'YES' : 'NO') .
    ' | Transfer: ' . ($isLocationTransfer ? 'YES' : 'NO')
);

// =====================================================
// PASS 2 — PC CLASSIFICATION
// =====================================================

if ($isExplicitLocationOnlyIntent === true) {

    // Classify explicit location-only proposal
    $pcm['pc'] = $entityStatus === 'exact'
        ? 'PC-4'
        : 'PC-5';

} elseif (
    $entityStatus === 'exact' &&
    $locationStatus === 'exact' &&
    $contactStatus === 'exact'
) {

    // Existing entity-location-contact structure
    $pcm['pc'] = 'PC-0';

} elseif (
    $entityStatus === 'exact' &&
    $locationStatus !== 'exact' &&
    $isDuplicateContact
) {

    // Proposed new location contains existing entity contact
    $pcm['pc'] = 'PC-2';

} elseif (
    $entityStatus === 'exact' &&
    $contactStatus === 'exact' &&
    $isLocationTransfer === true
) {

    // Authorized contact location succession
    $pcm['pc'] = 'PC-6';

} elseif (
    $entityStatus === 'exact' &&
    $locationStatus === 'exact' &&
    $contactStatus !== 'exact'
) {

    // Existing entity and location with new contact
    $pcm['pc'] = 'PC-3';

} elseif (
    $entityStatus === 'exact' &&
    $locationStatus !== 'exact' &&
    $contactStatus !== 'exact'
) {

    // Existing entity with new location and contact
    $pcm['pc'] = 'PC-2';

} elseif (
    $entityStatus === 'exact' &&
    $locationStatus !== 'exact' &&
    $contactStatus === 'exact'
) {

    // Existing contact associated with a different location
    $pcm['pc'] = 'PC-6';

} else {

    // New entity-location-contact structure
    $pcm['pc'] = 'PC-1';
}

// =====================================================
// DYNAMIC PC-6 NARRATIVE OVERRIDE
// =====================================================

if ($pcm['pc'] === 'PC-6') {
    $fullName = trim(
        ($data['contact']['contactFirstName'] ?? '') .
        ' ' .
        ($data['contact']['contactLastName'] ?? '')
    );

    $entityName =
        $data['entity']['entityName']
        ?? 'Existing Entity';

    $narratives['contentLine'] =
        "Contact Succession Proposal for {$fullName} at {$entityName}";

    $successionNarrative =
        'The proposal will retire the existing contact record, ' .
        'create a replacement contact associated with the new location, ' .
        'and preserve historical relationships with prior operational records.';

    $narratives['ui'] = $successionNarrative;
    $narratives['decisions'] = [$successionNarrative];
    $narratives['review'] = [];
}

// =====================================================
// PASS 3 — RS GOVERNANCE
// =====================================================

$governanceIssues = [];

// RS-5 — Duplicate contact outside its existing ELC
if (
    $isDuplicateContact &&
    $pcm['pc'] !== 'PC-0'
) {
    $governanceIssues[] = [
        'code' => 'RS-5',
        'message' => 'Duplicate contact detected',
        'action_text' =>
            'Action: Review the existing contact before proceeding',
        'details' => [
            'contactId' =>
                $databaseResolution['contact']['contactId']
                ?? null,
            'identityResult' => $contactIdentityResult,
            'duplicateReason' =>
                $databaseResolution['contact']['duplicateReason']
                ?? null
        ]
    ];
}

// RS-6 — Multiple unresolved parcels
$parcelDetails =
    $data['location']['parcelDetails']
    ?? [];

$acceptedParcels = array_filter(
    $parcelDetails,
    function ($parcel) {
        return !empty($parcel['accepted']);
    }
);

$resolvedCount = !empty($acceptedParcels)
    ? count($acceptedParcels)
    : count($parcelDetails);

if (
    $locationStatus !== 'exact' &&
    (
        !empty($data['location']['hasMultipleParcels']) ||
        ($data['location']['parcelCount'] ?? 0) > 1
    ) &&
    $resolvedCount !== 1
) {
    $governanceIssues[] = [
        'code' => 'RS-6',
        'message' =>
            'Multiple parcels found for this address - selection required',
        'details' => [
            'parcelCount' =>
                $data['location']['parcelCount']
                ?? 0
        ]
    ];
}

// RS-7 — Unresolved Maricopa County parcel
if (
    strtolower($data['location']['locationCounty'] ?? '') ===
        'maricopa' &&
    ($data['location']['parcelCount'] ?? 0) === 0
) {
    $governanceIssues[] = [
        'code' => 'RS-7',
        'message' => 'Parcel could not be resolved'
    ];
}

// RS-8 — Invalid or unverified location
$locationPlaceId =
    $data['location']['locationPlaceId']
    ?? null;

$locationValidated =
    $data['location']['locationValidated']
    ?? false;

if (
    empty($locationPlaceId) ||
    $locationValidated !== true
) {
    $governanceIssues[] = [
        'code' => 'RS-8',
        'message' => 'Location could not be validated',
        'action_text' =>
            'Action: Correct or verify the proposed location',
        'details' => [
            'locationPlaceId' => $locationPlaceId,
            'locationValidated' => $locationValidated,
            'resolvedAddress' =>
                $data['location']['locationResolvedAddress']
                ?? null,
            'matchQuality' =>
                $data['location']['locationMatchQuality']
                ?? []
        ]
    ];
}

// =====================================================
// PASS 4 — RS NORMALIZATION
// =====================================================

$pcm['rs'] = $pcm['rs'] ?? [];

foreach ($governanceIssues as $issue) {
    $pcm['rs'][] = $issue['code'];
}

$pcm['rs'] = array_values(
    array_unique($pcm['rs'])
);

// RS-0 cannot coexist with a governance issue
if (!empty($governanceIssues)) {
    $pcm['rs'] = array_values(
        array_diff($pcm['rs'], ['RS-0'])
    );
}

// Assign RS-0 only when no issue exists
if (empty($governanceIssues) && empty($pcm['rs'])) {
    $pcm['rs'][] = 'RS-0';
}

// =====================================================
// PASS 5 — COMMIT GOVERNANCE
// =====================================================

$blockingCodes = [
    'RS-3',
    'RS-5',
    'RS-6',
    'RS-7',
    'RS-8'
];

$blocksCommit = !empty(
    array_intersect(
        $pcm['rs'],
        $blockingCodes
    )
);

$governance = [
    'blockingIssues' => $governanceIssues
];

if (in_array('RS-5', $pcm['rs'], true)) {
    $governance['resolution_status'] = 'RS-5';
    $governance['reason'] =
        'Duplicate Contact Detected';

    $governance['action_text'] =
        'Action: Review the existing contact before proceeding';
}

if (in_array('RS-8', $pcm['rs'], true)) {
    $governance['resolution_status'] = 'RS-8';
    $governance['reason'] =
        'Invalid or Unverified Location';

    $governance['action_text'] =
        'Action: Correct or verify the proposed location';
}

// =====================================================
// PASS 6 — FINAL PCM OUTPUT
// =====================================================

$pcm = [
    'pc' => $pcm['pc'] ?? 'PC-1',
    'rs' => $pcm['rs']
];

$pc = $pcm['pc'];
$rsList = $pcm['rs'];

error_log(
    '[PPC][SECTION-13] PCM complete' .
    ' | PC=' . $pcm['pc'] .
    ' | RS=[' . implode(', ', $pcm['rs']) . ']' .
    ' | Blocks=' . ($blocksCommit ? 'YES' : 'NO')
);

$proposalId = str_pad(
    (string)mt_rand(1, 999999),
    6,
    '0',
    STR_PAD_LEFT
);

#endregion

#region SECTION 14 — Commit Plan Builder

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

// Define blocking governance codes
$blockingCodes = [
    'RS-3',
    'RS-5',
    'RS-6',
    'RS-7',
    'RS-8'
];

$activeBlockingCodes = array_values(
    array_intersect(
        $rsList,
        $blockingCodes
    )
);

$canCommit = empty($activeBlockingCodes);

error_log(
    '[PPC][SECTION-14] Building commit plan' .
    ' | PC=' . $pc .
    ' | RS=[' . implode(', ', $rsList) . ']' .
    ' | Eligible=' . ($canCommit ? 'YES' : 'NO')
);

// Build proposed database actions
switch ($pc) {

    case 'PC-0':

        // Existing ELC requires no database changes
        $commitPlan['canCommit'] = false;
        $commitPlan['actions'] = [];
        $commitPlan['summary'] =
            'No database changes required - ELC already exists';
        break;

    case 'PC-1':

        // New entity, location, and contact
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = [
            'insert_entity',
            'insert_location',
            'insert_contact',
            'link_elc'
        ];
        $commitPlan['summary'] =
            'Insert new Entity, Location, Contact and establish relationships';
        break;

    case 'PC-2':

        // Existing entity with new location and contact
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = [
            'link_entity',
            'insert_location',
            'insert_contact',
            'link_elc'
        ];
        $commitPlan['summary'] =
            'Link existing Entity, Insert new Location and Contact';
        break;

    case 'PC-3':

        // Existing entity and location with new contact
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = [
            'link_entity',
            'link_location',
            'insert_contact',
            'link_elc'
        ];
        $commitPlan['summary'] =
            'Link existing Entity and Location, Insert new Contact';
        break;

    case 'PC-4':

        // Existing entity with new location only
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = [
            'link_entity',
            'insert_location'
        ];
        $commitPlan['summary'] =
            'Link existing Entity and Insert new Location only';
        break;

    case 'PC-5':

        // New entity and location without contact
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = [
            'insert_entity',
            'insert_location'
        ];
        $commitPlan['summary'] =
            'Insert new Entity and Location without a Contact';
        break;

    case 'PC-6':

        // Contact succession to a new location
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = [
            'link_entity',
            'insert_location',
            'retire_contact',
            'insert_replacement_contact',
            'link_elc'
        ];
        $commitPlan['summary'] =
            'Retire the historical Contact, create its replacement, ' .
            'and associate the replacement with the new Location';
        break;

    default:

        // Reject unknown classification
        $commitPlan['canCommit'] = false;
        $commitPlan['actions'] = [];
        $commitPlan['summary'] =
            'Unknown proposal classification';
        break;
}

// Preserve resolved entity identity
if (!empty($databaseResolution['entity']['entityId'])) {
    $commitPlan['entity']['entityId'] =
        (int)$databaseResolution['entity']['entityId'];
}

// Preserve resolved location identity
if (!empty($databaseResolution['location']['locationId'])) {
    $commitPlan['location']['locationId'] =
        (int)$databaseResolution['location']['locationId'];

    $commitPlan['location']['locationParcelNumberRaw'] =
        $databaseResolution['location']['locationParcelNumberRaw']
        ?? null;
}

// Preserve resolved contact identity
if (!empty($databaseResolution['contact']['contactId'])) {
    $commitPlan['contact']['contactId'] =
        (int)$databaseResolution['contact']['contactId'];
}

// Preserve contact identity determination
$commitPlan['contact']['identityResult'] =
    $databaseResolution['contact']['identityResult']
    ?? 'no_match';

$commitPlan['contact']['isDuplicate'] =
    !empty($databaseResolution['contact']['isDuplicate']);

$commitPlan['contact']['duplicateReason'] =
    $databaseResolution['contact']['duplicateReason']
    ?? null;

// Enforce governance barrier
if (!empty($activeBlockingCodes)) {
    $commitPlan['canCommit'] = false;
    $commitPlan['actions'] = [];

    if (in_array('RS-5', $activeBlockingCodes, true)) {
        $commitPlan['summary'] =
            'Commit blocked: Contact is currently in the database';
    } else {
        $commitPlan['summary'] =
            'Commit blocked by governance: ' .
            implode(', ', $activeBlockingCodes);
    }
}

error_log(
    '[PPC][SECTION-14] Commit plan complete' .
    ' | CanCommit=' .
    ($commitPlan['canCommit'] ? 'YES' : 'NO') .
    ' | Actions=[' .
    implode(', ', $commitPlan['actions']) .
    ']'
);

#endregion

#region SECTION 15 — Narrative Builder + UI State

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

// Define blocking governance codes
$blockingUiCodes = [
    'RS-3',
    'RS-5',
    'RS-6',
    'RS-7',
    'RS-8'
];

$activeUiBlockingCodes = array_values(
    array_intersect($rsList, $blockingUiCodes)
);

if ($pc === 'PC-0') {
    // Existing ELC requires no proposal action
    $uiState['proposalStatus'] = 'existing';
    $uiState['canAccept'] = false;
    $uiState['canReject'] = false;
    $uiState['canEdit'] = false;
    $uiState['canCommit'] = false;

} elseif (!empty($activeUiBlockingCodes)) {
    // Set status for blocked proposal
    if (in_array('RS-6', $activeUiBlockingCodes, true)) {
        $uiState['proposalStatus'] = 'multiple_parcels';
    } elseif (in_array('RS-7', $activeUiBlockingCodes, true)) {
        $uiState['proposalStatus'] = 'unresolved_parcel';
    } elseif (in_array('RS-8', $activeUiBlockingCodes, true)) {
        $uiState['proposalStatus'] = 'invalid_location';
    } elseif (in_array('RS-3', $activeUiBlockingCodes, true)) {
        $uiState['proposalStatus'] = 'incomplete';
    } else {
        // RS-5 remains a proposal requiring review
        $uiState['proposalStatus'] = 'proposed';
    }

    $uiState['canAccept'] = false;
    $uiState['canReject'] = true;
    $uiState['canEdit'] = true;
    $uiState['canCommit'] = false;

} else {
    // Proposal is eligible for acceptance
    $uiState['proposalStatus'] = 'proposed';
    $uiState['canAccept'] = true;
    $uiState['canReject'] = true;
    $uiState['canEdit'] = true;
    $uiState['canCommit'] = !empty($commitPlan['canCommit']);
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

// Override generic narratives for confirmed duplicate contact
if (in_array('RS-5', $rsList, true)) {
    $fullName = trim(
        ($data['contact']['contactFirstName'] ?? '') .
        ' ' .
        ($data['contact']['contactLastName'] ?? '')
    );

    $entityName =
        $data['entity']['entityName']
        ?? 'the existing entity';

    $locationCity =
        $data['location']['locationCity']
        ?? '';

    $locationState =
        $data['location']['locationState']
        ?? '';

    $proposedLocation = trim(
        $locationCity .
        (
            $locationCity !== '' && $locationState !== ''
                ? ', '
                : ''
        ) .
        $locationState
    );

    $existingLocation =
        $databaseResolution['contact']['location']
        ?? [];

    $existingCity =
        $existingLocation['locationCity']
        ?? '';

    $existingState =
        $existingLocation['locationState']
        ?? '';

    $existingLocationText = trim(
        $existingCity .
        (
            $existingCity !== '' && $existingState !== ''
                ? ', '
                : ''
        ) .
        $existingState
    );

    $proposedLocationText = $proposedLocation !== ''
        ? "The proposed {$proposedLocation} location is new"
        : 'The proposed location is new';

    $aiNarrativeResult = [
        'contentLine' =>
            "Duplicate Contact Review for {$fullName} at {$entityName}",
        'decision' => [
            "{$proposedLocationText}, but {$fullName} already exists " .
            "as a contact for {$entityName}."
        ],
        'blocking' => [
            'The existing contact cannot be inserted as a new contact record.'
        ],
        'review' => [
            'Review the existing contact and determine whether the proposal ' .
            'should proceed without creating a duplicate contact.'
        ],
        'informational' => [
            $existingLocationText !== ''
                ? "The existing contact is currently associated with {$existingLocationText}."
                : 'The submitted identity matches an existing contact record.'
        ]
    ];
}

// Override generic narratives for invalid or unverified location
if (in_array('RS-8', $rsList, true)) {
    $entityName =
        $data['entity']['entityName']
        ?? 'the proposed entity';

    $submittedAddress = trim(
        implode(', ', array_filter([
            $data['location']['locationAddress'] ?? '',
            $data['location']['locationCity'] ?? '',
            $data['location']['locationState'] ?? '',
            $data['location']['locationZip'] ?? ''
        ]))
    );

    $resolvedAddress = trim((string)(
        $data['location']['locationResolvedAddress']
        ?? ''
    ));

    $mismatches =
        $data['location']['locationMatchQuality']['mismatches']
        ?? [];

    $resolvedNarrative = $resolvedAddress !== ''
        ? "Google resolved the input to {$resolvedAddress}, which does not " .
            'reliably match the submitted location.'
        : 'Google did not return a verifiable match for the submitted location.';

    $aiNarrativeResult = [
        'contentLine' =>
            "Invalid Location Review for {$entityName}",
        'decision' => [
            "The proposed address {$submittedAddress} could not be validated."
        ],
        'blocking' => [
            'The entity and location cannot be inserted until the address is corrected or verified.'
        ],
        'review' => [
            'Review and correct the street address, then resubmit the proposal.'
        ],
        'informational' => array_values(array_filter([
            $resolvedNarrative,
            !empty($mismatches)
                ? 'Validation differences: ' . implode(', ', $mismatches) . '.'
                : null
        ]))
    ];
}

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

#region SECTION 16 — Final Output Builder

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