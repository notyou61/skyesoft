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

#region SECTION 03 — 🧠 Unified AI Prompt Construction & Execution

$openAiApiKey = skyesoftGetEnv('OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY');

if (empty($openAiApiKey)) {
    echo json_encode(['success' => false, 'status' => 'missing_openai_key']);
    exit;
}

error_log('[PPC][SECTION-03] Starting AI extraction');

// =====================================================
// STRONG SYSTEM PROMPT (Improved Title Extraction)
// =====================================================
$systemPrompt = <<<EOT
You are an extremely precise structured data extraction engine specialized in cleaning and normalizing messy business contact signatures, Outlook signatures, website blocks, and pasted content.

PERFORM THESE STEPS IN ORDER:

1. CLEAN & NORMALIZE FIRST
   - Restore logical line breaks and structure from collapsed, HTML-contaminated, or poorly formatted input.
   - Remove noise: icons, emojis, HTML tags, disclaimers, repeated separators, social media links, and decorative text.
   - Fix common formatting issues such as extra spaces and broken lines.
   - Do NOT invent or hallucinate information.

2. THEN EXTRACT CLEAN DATA
   - Extract Entity, Location, and Contact fields from the cleaned text with high accuracy.

CRITICAL RULES:
- Title extraction is MANDATORY when a job title or role is clearly present in the input (e.g. Accounting Manager, Director of Operations, Project Manager, etc.).
- If no clear title is present, leave the "title" field as an empty string.
- Use empty string "" for any missing value. Never omit fields.
- Phone numbers: preserve the raw version exactly as shown in "primaryPhoneRaw" and provide a cleanly formatted version in "primaryPhone".
- Be conservative with inference — better to use "" than to guess.

Return ONLY valid JSON in this exact structure. No explanations, no markdown, no extra text.

{
  "intent": "contact_proposal",
  "confidence": 85,
  "parsed": {
    "entity": {
      "name": ""
    },
    "contact": {
      "firstName": "",
      "lastName": "",
      "salutation": "",
      "title": "",
      "primaryPhone": "",
      "primaryPhoneRaw": "",
      "email": ""
    },
    "location": {
      "address": "",
      "city": "",
      "state": "",
      "zip": "",
      "suite": "",
      "locationName": ""
    }
  }
}
EOT;

// =====================================================
// USER PROMPT
// =====================================================
$extractionPrompt = "Clean and normalize the following pasted contact information, then extract structured data.\n\nINPUT:\n{$rawInput}";

// =====================================================
// AI CALL
// =====================================================
$payload = [
    'model'       => 'gpt-4o-mini',
    'temperature' => 0,
    'max_tokens'  => 600,
    'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $extractionPrompt]
    ]
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openAiApiKey
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 25
]);

$response = curl_exec($ch);
safeCurlClose($ch);

if (!$response) {
    echo json_encode(['success' => false, 'status' => 'openai_request_failed']);
    exit;
}

$responseData = json_decode($response, true);
$content = trim($responseData['choices'][0]['message']['content'] ?? '');

// Extract JSON
preg_match('/\{.*\}/s', $content, $matches);
$jsonString = $matches[0] ?? $content;

$aiData = json_decode($jsonString, true);

if (!$aiData || !isset($aiData['parsed'])) {
    echo json_encode(['success' => false, 'status' => 'invalid_ai_response', 'content' => $content]);
    exit;
}

$parsed = $aiData['parsed'];

// =====================================================
// CLEAN PHONE FORMATTING
// =====================================================
if (!empty($parsed['contact']['primaryPhoneRaw'])) {
    $raw = $parsed['contact']['primaryPhoneRaw'];
    $digits = preg_replace('/[^0-9]/', '', $raw);
    
    if (strlen($digits) === 10) {
        $formatted = '(' . substr($digits, 0, 3) . ') ' .
                     substr($digits, 3, 3) . '-' .
                     substr($digits, 6);
        $parsed['contact']['primaryPhone'] = $formatted;
    } else {
        $parsed['contact']['primaryPhone'] = $raw;
    }
} elseif (!empty($parsed['contact']['primaryPhone'])) {
    $digits = preg_replace('/[^0-9]/', '', $parsed['contact']['primaryPhone']);
    if (strlen($digits) === 10) {
        $parsed['contact']['primaryPhone'] = '(' . substr($digits, 0, 3) . ') ' .
                                             substr($digits, 3, 3) . '-' .
                                             substr($digits, 6);
    }
}

// =====================================================
// INFER SALUTATION using askOpenAI.php function
// =====================================================
if (empty($parsed['contact']['salutation'])) {

    $firstName = $parsed['contact']['firstName'] ?? '';
    $lastName  = $parsed['contact']['lastName'] ?? '';

    if (function_exists('inferSalutation')) {

        error_log("[PPC][SECTION-03] Calling inferSalutation for: {$firstName} {$lastName}");

        $inferred = inferSalutation($firstName, $lastName);

        error_log("[PPC][SECTION-03] inferSalutation returned: " . var_export($inferred, true));

        if (!empty($inferred)) {
            $parsed['contact']['salutation'] = $inferred;
            $parsed['contact']['salutationInferred'] = true;
        }

    } else {
        error_log('[PPC][SECTION-03] WARNING: inferSalutation() function not found');
    }
}

error_log('[PPC][SECTION-03] Extraction complete - Title: ' . 
    (!empty($parsed['contact']['title']) ? $parsed['contact']['title'] : 'MISSING') .
    ' | Salutation: ' . (!empty($parsed['contact']['salutation']) ? $parsed['contact']['salutation'] : 'MISSING') .
    ' | Phone: ' . (!empty($parsed['contact']['primaryPhone']) ? $parsed['contact']['primaryPhone'] : 'MISSING'));

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

#region SECTION 05 — Data Normalization

// =====================================================
// 🔥 MTCO: SAFE FALLBACK MAPPING FOR PC-4 INTENTS
// =====================================================
if (isset($isExplicitLocationOnlyIntent) && $isExplicitLocationOnlyIntent) {
    error_log('[PPC][SECTION-05] Running safe alignment fallback checks...');
    
    $clientLoc    = $inputData['inputData']['location'] ?? [];
    $clientEntity = $inputData['inputData']['entity'] ?? [];

    // Only assign if Section 03 returned an empty string, preventing extraction loss
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
// ENTITY
// =====================================================

$parsed['entity']['name'] =
    trim($parsed['entity']['name']);

// =====================================================
// CONTACT
// =====================================================

$parsed['contact']['firstName'] =
    trim($parsed['contact']['firstName']);

$parsed['contact']['lastName'] =
    trim($parsed['contact']['lastName']);

$parsed['contact']['salutation'] =
    trim($parsed['contact']['salutation']);

$parsed['contact']['title'] =
    trim($parsed['contact']['title']);

$parsed['contact']['email'] =
    strtolower(
        trim($parsed['contact']['email'])
    );
    
// =====================================================
// PHONE NORMALIZATION (Preserve formatting from Section 03)
// =====================================================

$phoneValue = $parsed['contact']['primaryPhone'] ?? '';

if (!empty($phoneValue)) {
    // Always store a clean digits-only version for matching
    $parsed['contact']['primaryPhoneDigits'] = preg_replace('/[^0-9]/', '', $phoneValue);

    // Only re-format if it's still raw digits (no formatting yet)
    $digitsOnly = preg_replace('/[^0-9]/', '', $phoneValue);
    if (strlen($digitsOnly) === 10 && strpos($phoneValue, '(') === false) {
        $parsed['contact']['primaryPhone'] =
            '(' . substr($digitsOnly, 0, 3) . ') ' .
            substr($digitsOnly, 3, 3) . '-' .
            substr($digitsOnly, 6);
    }
    // Otherwise keep the already-formatted value from Section 03
}

// =====================================================
// LOCATION
// =====================================================

$parsed['location']['address'] =
    trim($parsed['location']['address']);

$parsed['location']['city'] =
    trim($parsed['location']['city']);

$parsed['location']['state'] =
    strtoupper(
        trim($parsed['location']['state'])
    );

$parsed['location']['zip'] =
    trim($parsed['location']['zip']);

$parsed['location']['suite'] =
    trim($parsed['location']['suite']);

$parsed['location']['locationName'] =
    trim($parsed['location']['locationName']);

error_log(
    '[PPC][SECTION-05] Data normalization complete'
);

// =====================================================
// COMPLETENESS CHECK (Hard Gate per Governance Notes)
// =====================================================

error_log('[PPC][PHASE-3] Running Completeness Check');

// 🔥 MTCO: Dynamically split requirements based on layout intent context
if (isset($isExplicitLocationOnlyIntent) && $isExplicitLocationOnlyIntent) {
    // Requirements for Location-Only Workflow
    $requiredFields = [
        'location.address'  => 'Street Address',
        'location.city'     => 'City',
        'location.state'    => 'State',
        'location.zip'      => 'ZIP Code'
    ];
} else {
    // Full Standard Contact Record Requirements
    $requiredFields = [
        'entity.name'       => 'Entity Name',
        'contact.firstName' => 'Contact First Name',
        'contact.lastName'  => 'Contact Last Name',
        'contact.email'     => 'Email Address',
        'location.address'  => 'Street Address',
        'location.city'     => 'City',
        'location.state'    => 'State',
        'location.zip'      => 'ZIP Code'
    ];
}

$missingFields = [];

// Evaluate requirements dynamically
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

// Build the self-documenting completeness object for the UI
$hasFirst = !empty(trim($parsed['contact']['firstName'] ?? ''));
$hasLast  = !empty(trim($parsed['contact']['lastName'] ?? ''));
$hasEmail = !empty(trim($parsed['contact']['email'] ?? ''));

$completeness = [
    'entity' => [
        // Forces a clean pass if it is an explicit location proposal OR if it contains a name string
        'name' => ($isExplicitLocationOnlyIntent || !empty(trim($parsed['entity']['name'] ?? ''))) ? '✔ Complete' : '✖ Missing Entity Name'
    ],
    'contact' => [
        // Bypasses name requirement for location proposals cleanly
        'names' => ($isExplicitLocationOnlyIntent || ($hasFirst && $hasLast)) ? '✔ N/A' : '✖ First/Last Name Missing',
        'email' => ($isExplicitLocationOnlyIntent || $hasEmail) ? '✔ N/A' : '✖ Email Address Required'
    ],
    'location' => [
        'street' => !empty(trim($parsed['location']['address'] ?? '')) ? '✔ Street Address' : '✖ Street Address Missing',
        'city'   => !empty(trim($parsed['location']['city'] ?? '')) ? '✔ City' : '✖ City Missing',
        'state'  => !empty(trim($parsed['location']['state'] ?? '')) ? '✔ State' : '✖ State Missing',
        'zip'    => !empty(trim($parsed['location']['zip'] ?? '')) ? '✔ ZIP' : '✖ ZIP Missing (Required)'
    ],
    'overall' => empty($missingFields) ? 'PASS' : 'FAIL'
];

error_log('[PPC][PHASE-3] Completeness Result: ' . $completeness['overall']);

// HARD GATE — Early Exit with Dynamic Error Messages
if ($completeness['overall'] !== 'PASS') {
    error_log('[PPC][PHASE-3] INCOMPLETE — Early Exit with RS-3');

    // Extract paths and labels for formatting the message and JSON response
    $pathsOnly  = array_column($missingFields, 'path');
    $labelsOnly = array_column($missingFields, 'label');
    
    // Build a dynamic bulleted list string for the user message
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
            'missingFields'     => $pathsOnly // FIXED: Array of structured string targets (e.g. ["contact.email"])
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

// Continue only on PASS
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
// LOCATION
// =====================================================
$data['location'] = [
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
    )
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
// ENRICH EACH PARCEL WITH DETAILED ASSESSOR DATA
// =====================================================

foreach ($data['location']['parcelDetails'] as &$parcel) {

    $apn =
        $parcel['parcelNumber'] ?? null;

    if (!$apn) {
        continue;
    }

    $detailUrl =
        'https://mcassessor.maricopa.gov/parcel/' .
        urlencode($apn);

    error_log(
        '[PPC][SECTION-09] Enriching parcel: ' .
        $apn
    );

    $context =
        stream_context_create([
            'http' => [
                'timeout' => 10,
                'header'  => "User-Agent: Skyesoft/1.0\r\n"
            ]
        ]);

    $detailResponse =
        @file_get_contents(
            $detailUrl,
            false,
            $context
        );

    if ($detailResponse === false) {

        error_log(
            '[PPC][SECTION-09] Failed to enrich parcel: ' .
            $apn
        );

        continue;
    }

    $detailData =
        json_decode(
            $detailResponse,
            true
        );

    if (!is_array($detailData)) {

        error_log(
            '[PPC][SECTION-09] Invalid detail response for: ' .
            $apn
        );

        continue;
    }

    // Merge useful fields from the detail response
    $parcel['ownerMailingAddress'] =
        $detailData['mailing_address'] ?? null;

    $parcel['propertyType'] =
        $detailData['property_type'] ??
        $detailData['use_code'] ??
        null;

    $parcel['lotSizeSqFt'] =
        $detailData['lot_size_sqft'] ?? null;

    $parcel['buildingSizeSqFt'] =
        $detailData['building_size_sqft'] ?? null;

    $parcel['yearBuilt'] =
        $detailData['year_built'] ?? null;

    $parcel['lastSaleDate'] =
        $detailData['last_sale_date'] ?? null;

    $parcel['lastSalePrice'] =
        $detailData['last_sale_price'] ?? null;

    // Keep raw assessor detail for future use if needed
    $parcel['assessorDetail'] =
        $detailData;
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
    $pcm['pc'] = 'PC-4';
} elseif ($entityStatus === 'exact' && $locationStatus === 'exact' && $contactStatus === 'exact') {
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

// =====================================================
// GOVERNANCE — RS Rules (Completeness already handled early)
// =====================================================

$governanceIssues = [];

// RS-5 Duplicate Contact
if ($contactStatus === 'exact' && $pcm['pc'] !== 'PC-0') {
    $governanceIssues[] = ['code' => 'RS-5', 'message' => 'Duplicate contact detected'];
}

// RS-6 Multiple Parcels (Only block if we don't already have an exact location match)
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

// Default RS-0 if nothing else
$pcm['rs'] = $pcm['rs'] ?? [];
if (empty($pcm['rs'])) {
    $pcm['rs'][] = 'RS-0';
}

// =====================================================
// GOVERNANCE — RS Rules (Completeness already handled early)
// =====================================================

$governanceIssues = [];

// RS-5 Duplicate Contact
if ($contactStatus === 'exact' && $pcm['pc'] !== 'PC-0') {
    $governanceIssues[] = ['code' => 'RS-5', 'message' => 'Duplicate contact detected'];
}

// RS-6 Multiple Parcels (FIX: Skip if location is an exact database match)
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

// Sync governance issues back into PCM RS array so classification reflects reality
$pcm['rs'] = $pcm['rs'] ?? [];
if (!empty($governanceIssues)) {
    foreach ($governanceIssues as $issue) {
        $pcm['rs'][] = $issue['code'];
    }
}

// Default RS-0 if nothing else blocked it
if (empty($pcm['rs'])) {
    $pcm['rs'][] = 'RS-0';
}

// =====================================================
// FINAL GOVERNANCE STATE
// =====================================================

// FIX: Clean up duplicates and properly check if any active blocking issue codes exist
$pcm['rs'] = array_values(array_unique($pcm['rs']));

$blockingCodes = ['RS-3', 'RS-5', 'RS-6', 'RS-7', 'RS-8'];
$blocksCommit = !empty(array_intersect($pcm['rs'], $blockingCodes));

$governance = ['blockingIssues' => $governanceIssues];

// Simplify PCM for output
$pcm = [
    'pc' => $pcm['pc'] ?? 'PC-1',
    'rs' => $pcm['rs']
];

error_log('[PPC][SECTION-12] PCM complete → PC=' . $pcm['pc'] . ' | RS=[' . implode(', ', $pcm['rs']) . '] | Blocks=' . ($blocksCommit ? 'YES' : 'NO'));

$proposalId = $proposalId ?? 'PRP-' . date('Ymd') . '-' . substr(uniqid(), -6);

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

    case 'PC-4':
        $commitPlan['canCommit'] = $canCommit;
        $commitPlan['actions'] = ['insert_location'];
        $commitPlan['summary'] = 'Insert new Location only';
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

error_log("[PPC][SECTION-13] Commit Plan complete → canCommit=" . ($commitPlan['canCommit'] ? 'YES' : 'NO'));

#endregion

#region SECTION 14 — Narrative Builder + UI State

// =====================================================
// UI State Builder
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
// Narrative Builder
// =====================================================
$narratives = ['ui' => null, 'report' => null];

$contactName = trim(($data['contact']['contactFirstName'] ?? '') . ' ' . ($data['contact']['contactLastName'] ?? ''));
$entityName  = trim($data['entity']['entityName'] ?? '');
$loc         = trim($data['location']['locationAddressRaw'] ?? '');

if (empty($entityName)) $entityName = 'the entity';
if (empty($contactName)) $contactName = 'the contact';
if (empty($loc)) $loc = 'the provided address';

if (in_array('RS-3', $rsList)) {
    $missingFields = $governance['blockingIssues'][0]['fields'] ?? ['required information'];
    $fieldList = implode(', ', $missingFields);

    $narratives['ui'] = "The proposal is incomplete.\n\nRequired information is missing.\n\nMissing fields: {$fieldList}.\n\nThis proposal cannot be accepted until the missing information is provided.";
    $narratives['report'] = "Incomplete proposal — missing required fields ({$fieldList}).";
} elseif (in_array('RS-6', $rsList)) {
    $parcelCount = $data['location']['parcelCount'] ?? 0;
    $narratives['ui'] = "{$contactName} was identified for {$entityName} at {$loc}.\n\nMultiple parcels ({$parcelCount}) were found.\n\nParcel selection is required before this proposal can be accepted.";
    $narratives['report'] = "New proposal for {$contactName} at {$entityName}. Multiple parcels detected — selection required.";
} elseif ($pc === 'PC-0') {
    $narratives['ui'] = "{$contactName} was identified for {$entityName} at {$loc}.\n\nAll records already exist. No action required.";
    $narratives['report'] = "Existing record match for {$contactName} at {$entityName}.";
} else {
    $narratives['ui'] = "{$contactName} was identified for {$entityName} at {$loc}.\n\nThis proposal is eligible for acceptance.";
    $narratives['report'] = "New proposal for {$contactName} at {$entityName}, {$loc}.";
}

error_log('[PPC][SECTION-14] Narrative Builder complete');

#endregion

#region SECTION 15 — Proposal Snapshot Creation

// =====================================================
// Prepare Snapshot
// =====================================================
$proposalSnapshot = [
    'proposalId'        => $proposalId,
    'generatedAt'       => date('c'),
    'version'           => '1.9.0',
    'activitySessionId' => $context['activitySessionId'] ?? '',
    'rawInput'          => $rawInput ?? '',
    'proposalStatus'    => 'proposed',
    
    'data'              => $data ?? [],
    'databaseResolution'=> $databaseResolution ?? [],
    
    // Classification
    'pcm'               => [
        'pc' => (isset($pcm['pc']) ? $pcm['pc'] : null),
        'rs' => (isset($pcm['rs']) ? $pcm['rs'] : [])
    ],
    
    // Execution Plan
    'commitPlan'        => $commitPlan ?? [],
    
    // UI Presentation State
    'ui'                => $uiState ?? [],
    
    // Governance Details (blocking reasons)
    'governance'        => $governance ?? ['blockingIssues' => []],
    
    // Human-readable Narratives
    'narratives'        => $narratives ?? [],
    
    'meta'              => [
        'hasMultipleParcels' => $data['location']['hasMultipleParcels'] ?? false,
        'parcelCount'        => $data['location']['parcelCount'] ?? 0,
        'censusValidated'    => $data['location']['locationCensusValidated'] ?? false,
        'googleValidated'    => $data['location']['locationValidated'] ?? false
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
    error_log("[PPC][SECTION-15] ✅ Snapshot saved: {$proposalId}.json");
} else {
    error_log("[PPC][SECTION-15] ❌ Failed to save snapshot");
}

// Attach path for reference
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
// FINAL ACTION RESPONSE UPDATE
// =====================================================
if (isset($_SESSION['lastContactProposalActionId']) && $pdo) {
    try {
        $finalResponseData = [
            'success'           => true,
            'status'            => 'proposed',
            'proposalId'        => $proposalId,
            'data'              => $data ?? [],
            'databaseResolution'=> $databaseResolution ?? [],
            'pcm'               => $pcm ?? [],
            'commitPlan'        => $commitPlan ?? [],
            'ui'                => $uiState ?? [],
            'governance'        => $governance ?? [],
            'narratives'        => $narratives ?? [],
            'meta'              => $proposalSnapshot['meta'] ?? [],
            'rawInput'          => $proposalSnapshot['rawInput'] ?? []
        ];

        // Ensure key is appended to internal historical tracking logs
        $finalResponseData['google']['apiKey'] = $clientWorkspaceKey;

        // Update the existing action record
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
    'activitySessionId' => $context['activitySessionId'] ?? '',

    // Inject secure key parameters for frontend SDK bootstrap chaining
    'google' => [
        'apiKey' => $clientWorkspaceKey
    ],

    // Core Structured Data
    'data' => [
        'entity'   => $data['entity']   ?? [],
        'contact'  => $data['contact']  ?? [],
        'location' => $data['location'] ?? []
    ],

    // Database Resolution
    'databaseResolution' => $databaseResolution ?? [],

    // Classification
    'pcm' => [
        'pc' => (isset($pcm['pc']) ? $pcm['pc'] : null),
        'rs' => (isset($pcm['rs']) ? $pcm['rs'] : [])
    ],

    // Execution Plan
    'commitPlan' => $commitPlan ?? [],

    // UI Presentation State
    'ui' => $uiState ?? [],

    // Governance Details (why blocked, missing fields, etc.)
    'governance' => $governance ?? ['blockingIssues' => []],

    // Human-readable Narratives
    'narratives' => $narratives ?? [],

    // Meta / Summary
    'meta' => [
        'hasMultipleParcels' => $data['location']['hasMultipleParcels'] ?? false,
        'parcelCount'        => $data['location']['parcelCount'] ?? 0,
        'censusValidated'    => $data['location']['locationCensusValidated'] ?? false,
        'googleValidated'    => $data['location']['locationValidated'] ?? false,
        'searchAddress'      => $searchAddress ?? ''
    ],

    // Raw Input
    'rawInput' => [
        'original' => $rawInput ?? '',
        'type'     => 'signature',
        'source'   => 'skyebot_prompt'
    ]

], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion