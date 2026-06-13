<?php
declare(strict_types=1);

/**
 * Skyesoft — processProposedContact.php
 * Main Orchestration + Proposal Report Generation
 * Version: 1.6.1
 * Last Updated: 2026-06-28
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
'version'          => '2.0.0'
];

// =====================================================
// INPUT CAPTURE
// =====================================================

$rawJson = file_get_contents('php://input');

$inputData = json_decode($rawJson, true);

if (!is_array($inputData)) {
$inputData = [];
}

$rawInput = trim(
$inputData['input']
?? ''
);

$rawInputOriginal =
    $inputData['input']
    ?? '';

$context['activitySessionId'] =
trim($inputData['activitySessionId'] ?? '');

// =====================================================
// DIAGNOSTIC LOGGING
// =====================================================

error_log('[PPC] Request ID: ' . $context['requestId']);
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

error_log('[PPC] Input validated');

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
    ?: getenv('GOOGLE_MAPS_BACKEND_API_KEY');

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

        error_log('[PPC][SECTION-07] Google validation successful');

    } else {
        error_log('[PPC][SECTION-07] Google returned no results');
        $data['location']['locationValidated'] = false;
    }

} else {
    error_log('[PPC][SECTION-07] Skipping Google geocode (missing address or API key)');
    $data['location']['locationValidated'] = false;
}

#endregion

#region SECTION 09 — County Resolution (Census)

require_once __DIR__ . '/utils/validateAddressCensus.php';

$censusResult = validateAddressCensus($searchAddress);

$data['location']['locationCensusValidated'] = $censusResult['valid']     ?? false;
$data['location']['locationCounty']          = $censusResult['county']     ?? null;
$data['location']['locationCountyFips']      = $censusResult['countyFips'] ?? null;
$data['location']['locationCountyGeoId']     = $censusResult['countyGeoId'] ?? null;

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
        ($censusResult['reason'] ?? 'Unknown reason')
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

$pcm = [
    'pc'               => null,      // Proposal Intent
    'pcStatus'         => null,
    'rs'               => [],        // Eligibility / Review flags
    'rsStatuses'       => [],
    'readyForCommit'   => false,
    'requiresReview'   => false,
    'blocksCommit'     => true,
    'action'           => null
];

$isExplicitLocationOnlyIntent = $isExplicitLocationOnlyIntent ?? false;

// =====================================================
// PASS 1 — PC Classification (What is the proposal?)
// =====================================================

$entityStatus   = $databaseResolution['entity']['status']   ?? 'none';
$locationStatus = $databaseResolution['location']['status'] ?? 'none';
$contactStatus  = $databaseResolution['contact']['status']  ?? 'none';

error_log("[PPC][SECTION-12] Database Resolution → Entity: $entityStatus | Location: $locationStatus | Contact: $contactStatus");

if ($isExplicitLocationOnlyIntent === true) {
    $pcm['pc']       = 'PC-4';
    $pcm['pcStatus'] = 'proposed_location';
    $pcm['action']   = 'create_location_only';

} elseif ($entityStatus === 'exact' && $locationStatus === 'exact' && $contactStatus === 'exact') {
    $pcm['pc']       = 'PC-0';
    $pcm['pcStatus'] = 'existing_elc';
    $pcm['action']   = 'link_existing_elc';

} elseif ($contactStatus === 'exact') {
    // Duplicate contact detected — treat as PC-3 + RS-5 (better Codex alignment)
    $pcm['pc']       = 'PC-3';
    $pcm['pcStatus'] = 'existing_location';
    $pcm['action']   = 'reject_duplicate';

} elseif ($locationStatus === 'exact') {
    $pcm['pc']       = 'PC-3';
    $pcm['pcStatus'] = 'existing_location';
    $pcm['action']   = 'link_existing_location';

} elseif ($entityStatus === 'exact') {
    $pcm['pc']       = 'PC-2';
    $pcm['pcStatus'] = 'existing_entity_new_location';
    $pcm['action']   = 'link_existing_entity';

} else {
    $pcm['pc']       = 'PC-1';
    $pcm['pcStatus'] = 'new_elc';
    $pcm['action']   = 'insert_new';
}

// =====================================================
// MINIMUM FIELD REQUIREMENTS (Codex-aligned)
// =====================================================

$missingRequired = [];

// Always required fields (all PC types)
if (empty($parsed['entity']['name'])) {
    $missingRequired[] = 'entity.name';
}
if (empty($parsed['location']['address'])) {
    $missingRequired[] = 'location.address';
}
if (empty($parsed['location']['city'])) {
    $missingRequired[] = 'location.city';
}

// Contact identity fields required for PC-1, PC-2, PC-3
if (in_array($pcm['pc'], ['PC-1', 'PC-2', 'PC-3'], true)) {
    if (empty($parsed['contact']['firstName'])) {
        $missingRequired[] = 'contact.firstName';
    }
    if (empty($parsed['contact']['lastName'])) {
        $missingRequired[] = 'contact.lastName';
    }
    if (empty($parsed['contact']['email'])) {
        $missingRequired[] = 'contact.email';
    }
}

// Apply RS flags based on what is missing
if (!empty($missingRequired)) {

    $contactRequiredFields = [
        'contact.firstName',
        'contact.lastName',
        'contact.email'
    ];

    $hasContactFieldsMissing = false;
    foreach ($contactRequiredFields as $field) {
        if (in_array($field, $missingRequired, true)) {
            $hasContactFieldsMissing = true;
            break;
        }
    }

    if ($hasContactFieldsMissing) {
        $pcm['rs'][]         = 'RS-3';
        $pcm['rsStatuses'][] = 'incomplete_contact';
    } else {
        $pcm['rs'][]         = 'RS-1';
        $pcm['rsStatuses'][] = 'incomplete';
    }

    $pcm['requiresReview'] = true;
    $pcm['blocksCommit']   = true;

    error_log('[PPC][SECTION-12] Missing required fields per Codex: ' . implode(', ', $missingRequired));
}

// =====================================================
// PASS 2 — RS Governance (Can this proposal proceed?)
// =====================================================

// RS-5 — Duplicate Contact
if ($contactStatus === 'exact' && $pcm['pc'] !== 'PC-0') {
    $pcm['rs'][]         = 'RS-5';
    $pcm['rsStatuses'][] = 'duplicate_contact';
}

// RS-6 — Multiple Parcels (only if no parcel has been accepted yet)
$hasMultipleParcels   = $data['location']['hasMultipleParcels'] ?? false;
$acceptedParcelNumber = $databaseResolution['location']['locationParcelNumberRaw'] 
                     ?? $data['location']['locationParcelNumberRaw'] 
                     ?? null;

if ($hasMultipleParcels && empty($acceptedParcelNumber)) {
    $pcm['rs'][]         = 'RS-6';
    $pcm['rsStatuses'][] = 'multiple_parcels';
    $pcm['requiresReview'] = true;
}

// RS-7 — Unresolved Parcel (Maricopa only)
$countyForCheck = strtolower(trim($data['location']['locationCounty'] ?? ''));

if (in_array($countyForCheck, ['maricopa', 'maricopa county'], true) && 
    ($data['location']['parcelCount'] ?? 0) === 0) {
    $pcm['rs'][]         = 'RS-7';
    $pcm['rsStatuses'][] = 'unresolved_parcel';
}

// RS-8 — Invalid Location
if (empty($data['location']['locationPlaceId'] ?? null)) {
    $pcm['rs'][]         = 'RS-8';
    $pcm['rsStatuses'][] = 'invalid_location';
}

// RS-0 — Acceptable (default if no issues)
if (empty($pcm['rs'])) {
    $pcm['rs'][]         = 'RS-0';
    $pcm['rsStatuses'][] = 'acceptable';
}

// =====================================================
// FINAL GOVERNANCE STATE
// =====================================================

$pcm['blocksCommit'] = in_array('RS-5', $pcm['rs']) || 
                       in_array('RS-6', $pcm['rs']) || 
                       in_array('RS-7', $pcm['rs']) || 
                       in_array('RS-8', $pcm['rs']) ||
                       in_array('RS-1', $pcm['rs']) ||
                       in_array('RS-3', $pcm['rs']);

$pcm['readyForCommit'] = !$pcm['blocksCommit'];

// Future-safe requiresReview logic
$pcm['requiresReview'] = !(
    count($pcm['rs']) === 1 && 
    $pcm['rs'][0] === 'RS-0'
);

error_log(
    '[PPC][SECTION-12] PCM complete → PC=' . ($pcm['pc'] ?? 'null') .
    ' | Ready=' . ($pcm['readyForCommit'] ? 'YES' : 'NO') .
    ' | Blocks=' . ($pcm['blocksCommit'] ? 'YES' : 'NO') .
    ' | RS=[' . implode(', ', $pcm['rs']) . ']'
);


// =====================================================
// SINGLE PROPOSAL ID GENERATION (used by both snapshot and response)
// =====================================================
$proposalId = $proposalId ?? 'PRP-' . date('Ymd') . '-' . substr(uniqid(), -6);

#endregion

#region SECTION 13 — Commit Plan Builder

// =====================================================
// Commit Plan Builder — Deterministic + IDs for Accept workflow
// =====================================================

$commitPlan = [
    'canCommit'     => false,
    'entity'        => [],
    'location'      => [],
    'contact'       => [],
    'actions'       => [],
    'summary'       => ''
];

$pc = $pcm['pc'] ?? 'PC-UNKNOWN';

error_log("[PPC][SECTION-13] Building Commit Plan for PC={$pc}");

switch ($pc) {
    case 'PC-0':
        $commitPlan['canCommit'] = true;
        $commitPlan['actions'] = ['link_existing_elc'];
        $commitPlan['summary'] = 'No database changes required - ELC already exists';
        $commitPlan['entity']['action']   = 'link';
        $commitPlan['location']['action'] = 'link';
        $commitPlan['contact']['action']  = 'link';
        break;

    case 'PC-1':
        $commitPlan['canCommit'] = $pcm['readyForCommit'] ?? false;
        $commitPlan['actions'] = ['insert_entity', 'insert_location', 'insert_contact', 'link_elc'];
        $commitPlan['summary'] = 'Insert new Entity, Location, Contact and establish relationships';
        $commitPlan['entity']['action']   = 'insert';
        $commitPlan['location']['action'] = 'insert';
        $commitPlan['contact']['action']  = 'insert';
        break;

    case 'PC-2':
        $commitPlan['canCommit'] = $pcm['readyForCommit'] ?? false;
        $commitPlan['actions'] = ['link_entity', 'insert_location', 'insert_contact', 'link_elc'];
        $commitPlan['summary'] = 'Link to existing Entity, Insert new Location + Contact';
        $commitPlan['entity']['action']   = 'link';
        $commitPlan['location']['action'] = 'insert';
        $commitPlan['contact']['action']  = 'insert';
        break;

    case 'PC-3':
        $commitPlan['canCommit'] = $pcm['readyForCommit'] ?? false;
        $commitPlan['actions'] = ['link_entity', 'link_location', 'insert_contact'];
        $commitPlan['summary'] = 'Link to existing Entity + Location, Insert new Contact';
        $commitPlan['entity']['action']   = 'link';
        $commitPlan['location']['action'] = 'link';
        $commitPlan['contact']['action']  = 'insert';
        break;

    case 'PC-4':
        $commitPlan['canCommit'] = $pcm['readyForCommit'] ?? false;
        $commitPlan['actions'] = ['insert_location'];
        $commitPlan['summary'] = 'Insert new Location only';
        $commitPlan['location']['action'] = 'insert';
        break;

    default:
        $commitPlan['summary'] = 'Unknown PC type - manual review required';
        break;
}

// Attach concrete IDs (critical for future Accept + audit)
if (!empty($databaseResolution['entity']['entityId'])) {
    $commitPlan['entity']['entityId'] = $databaseResolution['entity']['entityId'];
}
if (!empty($databaseResolution['location']['locationId'])) {
    $commitPlan['location']['locationId'] = $databaseResolution['location']['locationId'];
    $commitPlan['location']['locationParcelNumberRaw'] = 
        $databaseResolution['location']['locationParcelNumberRaw'] ?? null;
}

if ($pcm['blocksCommit'] ?? false) {
    $commitPlan['canCommit'] = false;
    $commitPlan['summary'] .= ' (Blocked by governance)';
}

error_log("[PPC][SECTION-13] Commit Plan complete → canCommit=" . ($commitPlan['canCommit'] ? 'YES' : 'NO'));

#endregion

#region SECTION 14 — Narrative Builder

// =====================================================
// Narrative Builder — askOpenAI.php + Fully Dynamic Fallback
// =====================================================

$narratives = array(
    'ui'       => null,
    'report'   => null,
    'database' => null,
    'audit'    => null,
    'activity' => null
);

error_log('[PPC][SECTION-14] Starting Narrative Builder');

// Safe variable extraction
$pc = (isset($pcm['pc']) ? $pcm['pc'] : 'UNKNOWN');
$pcStatus = (isset($pcm['pcStatus']) ? $pcm['pcStatus'] : '');
$rsStatuses = (isset($pcm['rsStatuses']) ? $pcm['rsStatuses'] : array());
$canCommit = (isset($commitPlan['canCommit']) ? $commitPlan['canCommit'] : false);

$narrativeContext = array(
    'pc'                       => $pc,
    'pcStatus'                 => $pcStatus,
    'rs'                       => (isset($pcm['rs']) ? $pcm['rs'] : array()),
    'rsStatuses'               => $rsStatuses,
    'canCommit'                => $canCommit,
    'entityName'               => (isset($data['entity']['entityName']) ? $data['entity']['entityName'] : ''),
    'entityId'                 => (isset($commitPlan['entity']['entityId']) ? $commitPlan['entity']['entityId'] : null),
    'contactName'              => trim(
        (isset($data['contact']['contactFirstName']) ? $data['contact']['contactFirstName'] : '') . 
        ' ' . 
        (isset($data['contact']['contactLastName']) ? $data['contact']['contactLastName'] : '')
    ),
    'contactTitle'             => (isset($data['contact']['contactTitle']) ? $data['contact']['contactTitle'] : ''),
    'locationAddress'          => (isset($data['location']['locationAddressRaw']) ? $data['location']['locationAddressRaw'] : ''),
    'locationId'               => (isset($commitPlan['location']['locationId']) ? $commitPlan['location']['locationId'] : null),
    'locationParcelNumberRaw'  => (isset($commitPlan['location']['locationParcelNumberRaw']) ? $commitPlan['location']['locationParcelNumberRaw'] : null),
    'commitActions'            => (isset($commitPlan['actions']) ? $commitPlan['actions'] : array())
);

// PC-aware descriptions
$pcDescriptions = array(
    'PC-0' => 'an existing entity, location, and contact',
    'PC-1' => 'a new entity, new location, and new contact',
    'PC-2' => 'an existing entity with a new location and new contact',
    'PC-3' => 'an existing location with a new contact',
    'PC-4' => 'a new location only'
);

$proposalDescription = (isset($pcDescriptions[$pc]) ? $pcDescriptions[$pc] : 'a proposal');
$commitStatus = ($canCommit ? 'Ready for commitment.' : 'Requires review before commitment.');

// =====================================================
// AI Narratives via askOpenAI.php (UI + Report only)
// =====================================================
$aiNarrativePrompt = "Generate two factual narratives for this proposal.\n\n" .
    json_encode($narrativeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$systemPromptForNarratives = <<<EOT
You are a precise operational documentation engine for Skyesoft CRM.

Generate ONLY "ui" and "report" narratives. Be strictly factual.

UI Narrative:
- What was found in the input
- What already exists in the database
- What is new
- What happens if accepted

Report Narrative:
- Formal operational summary suitable for PDF

Rules:
- Use only facts from the context
- Never mention proposal IDs, JSON, UI, screens, interfaces, workflows, or buttons
- Never speculate

Return ONLY valid JSON:
{
  "ui": "...",
  "report": "..."
}
EOT;

if (function_exists('askOpenAI')) {
    $aiResponse = askOpenAI($systemPromptForNarratives, $aiNarrativePrompt, array(
        'model'       => 'gpt-4o-mini',
        'temperature' => 0.0,
        'max_tokens'  => 600
    ));

    if ($aiResponse && !empty($aiResponse['content'])) {
        $content = trim($aiResponse['content']);
        preg_match('/\{.*\}/s', $content, $matches);
        $jsonString = (isset($matches[0]) ? $matches[0] : $content);

        $parsed = json_decode($jsonString, true);
        if (is_array($parsed)) {
            $narratives['ui']    = (isset($parsed['ui']) ? $parsed['ui'] : null);
            $narratives['report'] = (isset($parsed['report']) ? $parsed['report'] : null);
            error_log('[PPC][SECTION-14] ✅ AI narratives received from askOpenAI.php');
        }
    }
} else {
    error_log('[PPC][SECTION-14] WARNING: askOpenAI() function not available');
}

// =====================================================
// DETERMINISTIC NARRATIVES
// =====================================================
$narratives['database'] = (isset($commitPlan['actions']) ? $commitPlan['actions'] : array());

$narratives['audit'] = array(
    "Entity resolved by " . (isset($databaseResolution['entity']['matchType']) ? $databaseResolution['entity']['matchType'] : 'unknown') . 
    " (ID " . (isset($commitPlan['entity']['entityId']) ? $commitPlan['entity']['entityId'] : 'N/A') . ").",
    "Location resolved by " . (isset($databaseResolution['location']['matchType']) ? $databaseResolution['location']['matchType'] : 'unknown') .
    (isset($commitPlan['location']['locationId']) ? " (ID " . $commitPlan['location']['locationId'] . ")" : "") .
    (isset($commitPlan['location']['locationParcelNumberRaw']) ? ", parcel " . $commitPlan['location']['locationParcelNumberRaw'] : "") . ".",
    "Contact resolution status: " . (isset($databaseResolution['contact']['status']) ? $databaseResolution['contact']['status'] : 'none') . ".",
    "Proposal classified as " . $pc . " (" . $pcStatus . ").",
    "Governance: " . implode(', ', $rsStatuses)
);

$narratives['activity'] = "Created " . $pc . " proposal for " . 
    ((isset($narrativeContext['entityName']) && $narrativeContext['entityName'] !== '') ? $narrativeContext['entityName'] : 'the entity') . ".";

// =====================================================
// Dynamic Fallback
// =====================================================
if (empty($narratives['ui'])) {

    $contactName = (
        isset($narrativeContext['contactName']) &&
        $narrativeContext['contactName'] !== ''
    )
        ? $narrativeContext['contactName']
        : 'the contact';

    $entityName = (
        isset($narrativeContext['entityName']) &&
        $narrativeContext['entityName'] !== ''
    )
        ? $narrativeContext['entityName']
        : 'the entity';

    $loc = (
        isset($narrativeContext['locationAddress']) &&
        $narrativeContext['locationAddress'] !== ''
    )
        ? $narrativeContext['locationAddress']
        : 'the location';

    $governanceStatus = !empty($pcm['rsStatuses'])
        ? implode(', ', $pcm['rsStatuses'])
        : 'unknown';

    $narratives['ui'] =
        "This proposal represents {$proposalDescription}.\n\n" .
        "{$contactName} was identified for {$entityName} at {$loc}.\n\n" .
        "Classified as {$pc}. Governance status: {$governanceStatus}.\n" .
        $commitStatus;

    $narratives['report'] =
        "Proposal classified as {$pc} ({$pcStatus}).\n\n" .
        "Entity: {$entityName}.\n" .
        "Location: {$loc}.\n" .
        "Contact: {$contactName}.\n\n" .
        "Governance status: {$governanceStatus}.";
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
    'version'           => '1.8.0',                    // bumped
    'activitySessionId' => $context['activitySessionId'] ?? '',
    'rawInput'          => $rawInput ?? '',
    'proposalStatus'    => 'proposed',
    
    'data'              => $data ?? [],
    'databaseResolution'=> $databaseResolution ?? [],
    'pcm'               => $pcm ?? [],
    'commitPlan'        => $commitPlan ?? [],
    'narratives'        => $narratives ?? [],          // ← NEW
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
    error_log("[PPC][SECTION-15] ✅ Snapshot saved with narratives: {$proposalId}.json");
} else {
    error_log("[PPC][SECTION-15] ❌ Failed to save snapshot");
}

// Attach path for reference
$proposalSnapshot['snapshotPath'] = $snapshotPath;

#endregion

#region SECTION 16 — Final Output Builder

echo json_encode([
    'success'           => true,
    'status'            => 'proposed',
    'proposalId'        => $proposalId,
    'activitySessionId' => $context['activitySessionId'] ?? '',

    // Core Structured Data
    'data' => [
        'entity'   => $data['entity']   ?? [],
        'contact'  => $data['contact']  ?? [],
        'location' => $data['location'] ?? []
    ],

    // Database Resolution
    'databaseResolution' => $databaseResolution ?? [],

    // PCM Governance Decision
    'pcm' => $pcm ?? [],

    // Commit Plan
    'commitPlan' => $commitPlan ?? [],

    // NEW: Narratives
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