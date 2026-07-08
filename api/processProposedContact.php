<?php
declare(strict_types=1);

/**
 * Skyesoft — processProposedContact.php
 * Main Orchestration + Proposal Report Generation
 * Version: 1.6.2
 * Last Updated: 2026-07-02
 */

#region SECTION 00 — Bootstrap & Request Initialization

// =====================================================
// PROCESS START
// =====================================================

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
// DEPENDENCY LOADING
// =====================================================

require_once __DIR__ . '/utils/processProposedContact.utils.php';
require_once __DIR__ . '/askOpenAI.php';

error_log('[PPC][SECTION-00] Bootstrap complete');

// =====================================================
// REQUEST CONTEXT
// =====================================================

$context = [
    'requestId'        => uniqid('ppc_', true),
    'startedAt'        => microtime(true),
    'activitySessionId'=> '',
    'version'          => '2.1.0'   // bumped for parser dispatch
];

// =====================================================
// INPUT CAPTURE
// =====================================================

$rawJson = file_get_contents('php://input');

$inputData = json_decode($rawJson, true);

if (!is_array($inputData)) {
    $inputData = [];
}

// =====================================================
// PROPOSAL TYPE DETECTION (Semantic + Legacy Support)
// =====================================================

$proposalTypeInput = trim($inputData['proposalType'] ?? '');
$legacyType        = trim($inputData['type'] ?? '');

$proposalType = $proposalTypeInput !== '' 
    ? $proposalTypeInput 
    : (($legacyType === 'PC-4') ? 'location' : 'contact');

$isExplicitLocationOnlyIntent = ($proposalType === 'location');

$rawInput = trim(
    $inputData['input']
    ?? ''
);

$rawInputOriginal = $inputData['input'] ?? '';

$context['activitySessionId'] = trim($inputData['activitySessionId'] ?? '');

// =====================================================
// DIAGNOSTIC LOGGING
// =====================================================

error_log('[PPC] Request ID: ' . $context['requestId']);
error_log('[PPC] Proposal Type: ' . $proposalType . ' (input=' . $proposalTypeInput . ', legacy=' . $legacyType . ')');
error_log('[PPC] Input Length: ' . strlen($rawInput));

if (!empty($inputData)) {
    error_log(
        '[PPC] Input Keys: ' .
        implode(', ', array_keys($inputData))
    );
}

#endregion

#region SECTION 01 — Runtime Services

require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/envLoader.php';

skyesoftLoadEnv();

$pdo = getPDO() ?? null;

error_log('[PPC] Runtime services loaded');


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

#region SECTION 02.5 — Proposal Parser Dispatch (Architectural Branch Point)

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

    error_log("[PPC][ACTION-LOG] ✅ Success - ActionID: " . ($actionId ?? 'NULL') .
              " | Lat: " . ($data['location']['locationLatitude'] ?? 'NULL') .
              " | Lon: " . ($data['location']['locationLongitude'] ?? 'NULL'));

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
        '[PPC][SECTION-08] ✅ Census county resolved: ' .
        ($data['location']['locationCounty'] ?? 'N/A') .
        ' | FIPS: ' . ($data['location']['locationCountyFips'] ?? 'N/A') .
        ' | GEOID: ' . ($data['location']['locationCountyGeoId'] ?? 'N/A')
    );
} else {
    error_log(
        '[PPC][SECTION-08] ❌ Census validation failed: ' . 
        ($censusResult['reason'] ?? 'Unknown reason') .
        ' | Retaining Baseline County: ' . ($data['location']['locationCounty'] ?? 'None')
    );
}

// =====================================================================
// GEOGRAPHIC GOVERNANCE GATE (Maricopa County Parcel Protection)
// =====================================================================
$resolvedCounty          = strtolower($data['location']['locationCounty'] ?? '');
$locationValidated       = $data['location']['locationValidated'] ?? false;
$locationCensusValidated = $data['location']['locationCensusValidated'] ?? false;

if ($resolvedCounty === 'maricopa' && $locationValidated && !$locationCensusValidated) {
    error_log('[PPC][GOVERNANCE] CRITICAL: Maricopa County property address could not be validated by Census. Threat of unrelated parcel assignment detected. Early exiting with RS-8.');

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
            'pc' => 'PC-2', 
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
                    'message' => 'Maricopa County location could not be validated by Census.',
                    'details' => [
                        'county' => 'maricopa',
                        'googleValidated' => true,
                        'censusValidated' => false
                    ]
                ]
            ],
            'resolution_status' => 'RS-8',
            'reason'            => 'Maricopa County location could not be validated by Census.'
        ],
        'narratives' => [
            'ui'     => "Locations within Maricopa County require explicit validation by federal census records to prevent inaccurate parcel mapping. Please provide a verified address.",
            'report' => "Maricopa County address failed federal Census validation."
        ],
        'meta' => [
            'hasMultipleParcels' => false,
            'parcelCount'        => 0,
            'censusValidated'    => false,
            'googleValidated'    => true,
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

// NEW DIAGNOSTIC
error_log('[PPC][SECTION-10] After parcelResult → JurisdictionType=' . 
    json_encode($data['location']['jurisdictionType']));

// =====================================================
// ENRICH EACH PARCEL WITH DETAILED ASSESSOR DATA + MAP ID
// =====================================================

$token = getenv('MARICOPA_COUNTY_API_KEY') ?: '';

foreach ($data['location']['parcelDetails'] as &$parcel) {

    $apn = $parcel['parcelNumber'] ?? null;

    if (!$apn) {
        continue;
    }

    // 1. Fetch Standard Assessor Details
    $detailUrl = 'https://mcassessor.maricopa.gov/parcel/' . urlencode($apn);

    error_log('[PPC][SECTION-09] Enriching parcel: ' . $apn);

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "User-Agent: Skyesoft/1.0\r\n"
        ]
    ]);

    $detailResponse = @file_get_contents($detailUrl, false, $context);

    if ($detailResponse !== false) {
        $detailData = json_decode($detailResponse, true);

        if (is_array($detailData)) {
            // Merge useful fields from the detail response
            $parcel['ownerMailingAddress'] = $detailData['mailing_address'] ?? null;
            $parcel['propertyType'] = $detailData['property_type'] ?? $detailData['use_code'] ?? null;
            $parcel['lotSizeSqFt'] = $detailData['lot_size_sqft'] ?? null;
            $parcel['buildingSizeSqFt'] = $detailData['building_size_sqft'] ?? null;
            $parcel['yearBuilt'] = $detailData['year_built'] ?? null;
            $parcel['lastSaleDate'] = $detailData['last_sale_date'] ?? null;
            $parcel['lastSalePrice'] = $detailData['last_sale_price'] ?? null;
            
            // Keep raw assessor detail for future use if needed
            $parcel['assessorDetail'] = $detailData;
        }
    } else {
        error_log('[PPC][SECTION-09] Failed to enrich standard details for parcel: ' . $apn);
    }

    // 2. Fetch Map ID and Map URL via Cloudflare-safe cURL
    $parcel['mapId'] = null;
    $parcel['mapUrl'] = null;

    if ($token) {
        $mapMetaUrl = 'https://mcassessor.maricopa.gov/mapid/parcel/' . urlencode($apn);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $mapMetaUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Authorization: ' . trim($token),
                'Cache-Control: no-cache'
            ]
        ]);

        $mapMetaResponse = curl_exec($ch);
        $httpCode        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Track and audit response shapes safely
        error_log('[PPC][SECTION-09] MapID raw response for ' . $apn . ': ' . substr((string)$mapMetaResponse, 0, 500));

        if ($httpCode === 200 && $mapMetaResponse !== false) {
            $mapData = json_decode($mapMetaResponse, true);
            
            if (is_array($mapData)) {
                $mapItem = $mapData[0] ?? null;

                if (is_string($mapItem)) {
                    $mapId = preg_replace('/\.pdf$/i', '', trim($mapItem));

                    $parcel['mapId']  = $mapId;
                    $parcel['mapUrl'] = 'https://mcassessor.maricopa.gov/getmapid/' . rawurlencode($mapId) . '/';
                } elseif (is_array($mapItem)) {
                    $mapId = $mapItem['FileName'] ?? $mapItem['fileName'] ?? $mapItem['filename'] ?? $mapItem['mapId'] ?? null;
                    $mapId = $mapId ? preg_replace('/\.pdf$/i', '', trim((string)$mapId)) : null;

                    $parcel['mapId']  = $mapId;
                    $parcel['mapUrl'] = $mapItem['Url'] ?? $mapItem['url'] ?? ($mapId ? 'https://mcassessor.maricopa.gov/getmapid/' . rawurlencode($mapId) . '/' : null);
                }
            }
        } else {
            error_log('[PPC][SECTION-09] MapID resolution failed for: ' . $apn . ' (HTTP ' . $httpCode . ')');
        }
    }
}

unset($parcel);

error_log(
    '[PPC][SECTION-09] Parcel resolution + enrichment complete. ' .
    'Count=' . ($data['location']['parcelCount'] ?? 0) .
    ' | Jurisdiction=' . ($data['location']['jurisdictionName'] ?? 'NULL') .
    ' | Type=' . ($data['location']['jurisdictionType'] ?? 'NULL')
);

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

    error_log('[PPC][SECTION-10] Database resolution complete');

} else {
    error_log('[PPC][SECTION-10] No PDO connection — skipping DB resolution');
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

error_log("[PPC][SECTION-12] Database Resolution → Entity: $entityStatus | Location: $locationStatus | Contact: $contactStatus");

if ($isExplicitLocationOnlyIntent === true) {
    // NEW: Location-only proposals
    $pcm['pc'] = ($entityStatus === 'exact') ? 'PC-4' : 'PC-5';
} else {
    // EXISTING contact classification logic (unchanged — preserves PC-0 through PC-3)
    if ($entityStatus === 'exact' && $locationStatus === 'exact' && $contactStatus === 'exact') {
        $pcm['pc'] = 'PC-0';
    } elseif ($contactStatus === 'exact') {
        $pcm['pc'] = 'PC-3';
    } elseif ($locationStatus === 'exact') {
        $pcm['pc'] = 'PC-3';
    } elseif ($entityStatus === 'exact') {
        $pcm['pc'] = 'PC-2';
    } else {
        $pcm['pc'] = 'PC-1';
    }
}

// =====================================================
// GOVERNANCE — RS Rules
// =====================================================

$governanceIssues = [];

// RS-5 Duplicate Contact
if ($contactStatus === 'exact' && $pcm['pc'] !== 'PC-0') {
    $governanceIssues[] = [
        'code' => 'RS-5', 
        'message' => 'Duplicate contact detected',
        // 🔥 Overrides standard UI text rows for action labels
        'action_text' => 'Action: Contact is currently in the database' 
    ];
}

// RS-6 Multiple Parcels
if ($locationStatus !== 'exact' && (($data['location']['hasMultipleParcels'] ?? false) || ($data['location']['parcelCount'] ?? 0) > 1)) {
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

// 🔥 Hard override if caught in an RS-5 validation trap to prevent UI component mismatch
if (in_array('RS-5', $rsList)) {
    $commitPlan['actions'] = []; // Clears standard insert arrays out of the snapshot loop
    $commitPlan['summary'] = 'Action: Contact is currently in the database';
}

error_log("[PPC][SECTION-13] Commit Plan complete → canCommit=" . ($commitPlan['canCommit'] ? 'YES' : 'NO'));

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
    'canCommit'      => false
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
    $uiState['canAccept'] = $uiState['canCommit'] = true;
}

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
    
    // 🌟 Explicitly flattened keys matching the prompt definitions exactly
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

// Extract the standalone Content Line
$contentLine = $aiNarrativeResult['contentLine'] ?? 'Proposal Information Update';

// Format the structural array to map seamlessly with your existing framework
$narratives = [
    'contentLine'=> $contentLine, 
    'ui'         => $aiNarrativeResult['decision'][0] ?? 'Proposal processing routing initiated.',
    'report'     => implode(' ', $aiNarrativeResult['informational'] ?? []),
    'decisions'  => $aiNarrativeResult['decision'] ?? [],
    'blocking'   => $aiNarrativeResult['blocking'] ?? [],
    'review'     => $aiNarrativeResult['review'] ?? [],
    'info'       => $aiNarrativeResult['informational'] ?? []
];

error_log("[PPC][SECTION-14] AI Narrative Generation complete → Content Line: '{$contentLine}'");

#endregion

#region SECTION 15 — Proposal Snapshot Creation

// =====================================================
// Prepare Snapshot Matching Utility Architecture
// =====================================================
$proposalSnapshot = [
    'proposalId'        => $proposalId,
    'contentLine'       => $contentLine ?? 'Proposal Information Update', 
    'generatedAt'       => date('c'),
    'version'           => '1.9.0',
    'activitySessionId' => $context['activitySessionId'] ?? '',
    'rawInput'          => $rawInput ?? '',
    'proposalStatus'    => 'proposed',
    
    'parsed'            => $parsed ?? [],
    'data'              => $data ?? [],
    'meta'              => [
        'hasMultipleParcels' => $data['location']['hasMultipleParcels'] ?? false,
        'parcelCount'        => $data['location']['parcelCount'] ?? 0,
        'censusValidated'    => $data['location']['locationCensusValidated'] ?? false,
        'googleValidated'    => $data['location']['locationValidated'] ?? false
    ],
    
    'pcm'               => [
        'pc' => ($pcm['pc'] ?? null),
        'rs' => ($pcm['rs'] ?? [])
    ],
    'locationValidation'=> $locationValidation ?? [],
    'resolution'        => $databaseResolution ?? [],
    'persistence'       => $commitPlan ?? [],
    
    'status'            => ($pcm['readyForCommit'] ?? false) ? 'ready' : 'review',
    'reportStatus'      => 'pending',
    
    'governance'        => $governance ?? ['blockingIssues' => []],
    'narratives'        => $narratives ?? [],
    
    'artifactRegistry'  => [
        'parcelImages'  => $parcelImages ?? [],
        'satelliteView' => null,
        'streetView'    => null,
        'pdfReport'     => null
    ]
];

// =====================================================
// Save Snapshot to Disk
// =====================================================
$snapshotDir = __DIR__ . '/../data/runtimeEphemeral/proposals';
if (!is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0755, true);
}

$snapshotPath = $snapshotDir . "/{$proposalId}.json";

$written = file_put_contents(
    $snapshotPath,
    json_encode($proposalSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

if ($written !== false) {
    error_log("[PPC][SECTION-15] ✅ Snapshot saved with Content Line: {$proposalId}.json");
} else {
    error_log("[PPC][SECTION-15] ❌ Failed to save snapshot");
}

// Attach path reference
$proposalSnapshot['snapshotPath'] = $snapshotPath;

#endregion

#region SECTION 16 — Final Output Builder

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

            // NEW: Artifact Registry Tracking Compliant with Tier 2 Architecture
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
    'narratives' => $narratives ?? [], // 🌟 Contains contentLine internally as well
    'meta' => $proposalSnapshot['meta'] ?? [],
    'rawInput' => $proposalSnapshot['rawInput'] ?? [],

    // NEW: Artifact Registry Tracking Compliant with Tier 2 Architecture
    'reportArtifacts' => $artifacts

], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion