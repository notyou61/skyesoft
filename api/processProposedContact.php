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
// STRONG SYSTEM PROMPT (Title Extraction Emphasis)
// =====================================================
$systemPrompt = <<<EOT
You are an extremely precise structured data extraction engine.

STEPS:
1. Clean the messy input (restore line breaks, remove noise).
2. Extract all fields accurately.

CRITICAL RULES:
- Title extraction is MANDATORY when present.
- If the input contains a job title/role (e.g. Accounting Manager, Director of Operations, Project Manager, etc.), extract it into the "title" field.
- Do NOT guess or invent a title if none is clearly present.
- Extract any phone number present.
- Put the cleaned/formatted version in "primaryPhone".
- Put the exact original text in "primaryPhoneRaw".
- Be accurate with names and email.

Return ONLY valid JSON in this exact structure. No extra text.
{
  "intent": "contact_proposal",
  "confidence": 85,
  "parsed": {
    "entity": { "name": "" },
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
$extractionPrompt = "Clean and extract structured data from this contact information:\n\n{$rawInput}";

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

// =====================================================
// TITLE FALLBACK (from raw input if AI missed it)
// =====================================================
if (empty($parsed['contact']['title'])) {
    if (preg_match('/(?:^|\n)([A-Za-z][A-Za-z\s&]+(?:Manager|Director|Coordinator|Specialist|Supervisor|Analyst|Engineer|Executive|Officer|President|VP|Vice President))/i', $rawInput, $titleMatch)) {
        $parsed['contact']['title'] = trim($titleMatch[1]);
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

#region SECTION 07 — Google Location Validation

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

#region SECTION 08 — County Resolution (Census)

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

#region SECTION 09 — Parcel Resolution + Enrichment

require_once __DIR__ . '/utils/resolveParcel.php';

$parcelResult = resolveParcel(
    $data['location']['locationLatitude']  ?? null,
    $data['location']['locationLongitude'] ?? null,
    $data['location']['locationCounty']    ?? null,
    $data['location']['locationCountyFips'] ?? null,
    $searchAddress
);

$data['location']['parcelDetails']   = $parcelResult['parcelDetails']   ?? [];
$data['location']['parcelCount']     = $parcelResult['parcelCount']     ?? 0;
$data['location']['jurisdictionName'] = $parcelResult['jurisdictionName'] ?? null;
$data['location']['jurisdictionType'] = $parcelResult['jurisdictionType'] ?? null;
$data['location']['hasMultipleParcels'] = ($data['location']['parcelCount'] > 1);

// =====================================================
// ENRICH EACH PARCEL WITH DETAILED ASSESSOR DATA
// =====================================================
foreach ($data['location']['parcelDetails'] as &$parcel) {
    $apn = $parcel['parcelNumber'] ?? null;
    if (!$apn) continue;

    $detailUrl = 'https://mcassessor.maricopa.gov/parcel/' . urlencode($apn);

    error_log('[PPC][SECTION-09] Enriching parcel: ' . $apn);

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "User-Agent: Skyesoft/1.0\r\n"
        ]
    ]);

    $detailResponse = @file_get_contents($detailUrl, false, $context);

    if ($detailResponse === false) {
        error_log('[PPC][SECTION-09] Failed to enrich parcel: ' . $apn);
        continue;
    }

    $detailData = json_decode($detailResponse, true);

    if (!is_array($detailData)) {
        error_log('[PPC][SECTION-09] Invalid detail response for: ' . $apn);
        continue;
    }

    // Merge useful fields from the detail response
    $parcel['ownerMailingAddress'] = $detailData['mailing_address'] ?? null;
    $parcel['propertyType']        = $detailData['property_type'] ?? $detailData['use_code'] ?? null;
    $parcel['lotSizeSqFt']         = $detailData['lot_size_sqft'] ?? null;
    $parcel['buildingSizeSqFt']    = $detailData['building_size_sqft'] ?? null;
    $parcel['yearBuilt']           = $detailData['year_built'] ?? null;
    $parcel['lastSaleDate']        = $detailData['last_sale_date'] ?? null;
    $parcel['lastSalePrice']       = $detailData['last_sale_price'] ?? null;

    // Keep the raw detail for future use if needed
    $parcel['assessorDetail']      = $detailData;
}

unset($parcel);

error_log(
    '[PPC][SECTION-09] Parcel resolution + enrichment complete. ' .
    'Count: ' . $data['location']['parcelCount']
);

#endregion

#region SECTION 10 — Database Resolution

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

#region SECTION 11 — PCM Governance (PC + RS Model)

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

// Temporary relaxation during development
$dataIntegrityStatus = $dataIntegrityStatus ?? ['status' => 'complete'];

error_log("[PPC][SECTION-11] Database Resolution → Entity: $entityStatus | Location: $locationStatus | Contact: $contactStatus");

if ($isExplicitLocationOnlyIntent === true) {
    $pcm['pc']       = 'PC-4';
    $pcm['pcStatus'] = 'proposed_location';
    $pcm['action']   = 'create_location_only';

} elseif ($entityStatus === 'exact' && $locationStatus === 'exact' && $contactStatus === 'exact') {
    $pcm['pc']       = 'PC-0';
    $pcm['pcStatus'] = 'existing_elc';
    $pcm['action']   = 'link_existing_elc';

} elseif ($contactStatus === 'exact') {
    $pcm['pc']       = null;
    $pcm['pcStatus'] = 'duplicate_contact';
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
// PASS 2 — RS Governance (Can this proposal proceed?)
// =====================================================

// RS-1 — Incomplete (always blocking if present)
if (($dataIntegrityStatus['status'] ?? 'unknown') !== 'complete') {
    $pcm['rs'][]         = 'RS-1';
    $pcm['rsStatuses'][] = 'incomplete';
}

// RS-5 — Duplicate Contact
if ($contactStatus === 'exact' && $pcm['pc'] !== 'PC-0') {
    $pcm['rs'][]         = 'RS-5';
    $pcm['rsStatuses'][] = 'duplicate_contact';
}

// RS-6 — Multiple Parcels
if ($data['location']['hasMultipleParcels'] ?? false) {
    $pcm['rs'][]         = 'RS-6';
    $pcm['rsStatuses'][] = 'multiple_parcels';
    $pcm['requiresReview'] = true;
}

// RS-7 — Unresolved Parcel (Maricopa only)
if ($data['location']['locationCounty'] === 'Maricopa' && 
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
                       in_array('RS-1', $pcm['rs']);

$pcm['readyForCommit'] = !$pcm['blocksCommit'];
$pcm['requiresReview'] = count($pcm['rs']) > 0 && $pcm['rs'][0] !== 'RS-0';

error_log(
    '[PPC][SECTION-11] PCM complete → PC=' . ($pcm['pc'] ?? 'null') .
    ' | Ready=' . ($pcm['readyForCommit'] ? 'YES' : 'NO') .
    ' | Blocks=' . ($pcm['blocksCommit'] ? 'YES' : 'NO') .
    ' | RS=[' . implode(', ', $pcm['rs']) . ']'
);

#endregion

#region SECTION 12 — Final Output Builder

// Generate a temporary proposal ID if one doesn't exist yet
$proposalId = $proposalId ?? 'PRP-' . date('Ymd') . '-' . substr(uniqid(), -6);

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

    // Meta / Summary
    'meta' => [
        'hasMultipleParcels' => $data['location']['hasMultipleParcels'] ?? false,
        'parcelCount'        => $data['location']['parcelCount'] ?? 0,
        'censusValidated'    => $data['location']['locationCensusValidated'] ?? false,
        'googleValidated'    => $data['location']['locationValidated'] ?? false,
        'searchAddress'      => $searchAddress ?? ''
    ],

    // Raw Input (for auditing and debugging)
    'rawInput' => [
        'original' => $rawInput ?? '',
        'type'     => 'signature',
        'source'   => 'skyebot_prompt'
    ]

], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

exit;

#endregion

#region SECTION 13 — Proposal Snapshot Creation

// =====================================================
// Generate Unique Proposal ID
// =====================================================
$timestamp = microtime(true);
$uniquePart = str_pad((string)((int)($timestamp * 100000) % 999999), 6, '0', STR_PAD_LEFT);
$proposalId = 'PRP-' . date('Ymd') . '-' . $uniquePart;

// =====================================================
// Prepare Snapshot
// =====================================================
$proposalSnapshot = [
    'proposalId'        => $proposalId,
    'generatedAt'       => date('c'),
    'version'           => '1.6.1',
    'activitySessionId' => $context['activitySessionId'] ?? '',
    'rawInput'          => $rawInput ?? '',
    
    'data'              => $data ?? [],
    'databaseResolution'=> $databaseResolution ?? [],
    'pcm'               => $pcm ?? [],
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
    error_log("[PPC][SECTION-13] ✅ Snapshot saved: {$proposalId}.json");
} else {
    error_log("[PPC][SECTION-13] ❌ Failed to save snapshot");
}

// Update the main response data
$proposalSnapshot['snapshotPath'] = $snapshotPath;

#endregion
