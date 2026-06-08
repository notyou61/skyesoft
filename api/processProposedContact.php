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

#region SECTION 03 — AI Extraction

// =====================================================
// ENVIRONMENT VALIDATION
// =====================================================

$openAiApiKey = skyesoftGetEnv('OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY');

if (empty($openAiApiKey)) {
    echo json_encode([
        'success' => false,
        'status'  => 'missing_openai_key'
    ]);
    exit;
}

error_log('[PPC][SECTION-03] OpenAI key loaded');
error_log('[PPC][SECTION-03] Starting OpenAI request');

// =====================================================
// AI PROMPT CONSTRUCTION
// =====================================================

$systemPrompt = <<<PROMPT
You are a structured data extraction engine.

Extract entity, contact, and location information from the input.

Return ONLY valid JSON in this exact structure. Do not add any explanation or markdown.

{
  "entity": {
    "name": ""
  },
  "contact": {
    "firstName": "",
    "lastName": "",
    "salutation": "",
    "title": "",
    "primaryPhone": "",
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
PROMPT;

$userPrompt = $rawInput;

// =====================================================
// OPENAI REQUEST
// =====================================================

$payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        [
            'role'    => 'system',
            'content' => $systemPrompt
        ],
        [
            'role'    => 'user',
            'content' => $userPrompt
        ]
    ],
    'temperature' => 0
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
    CURLOPT_TIMEOUT        => 30
]);

$response = curl_exec($ch);
error_log('[PPC][SECTION-03] OpenAI response received');

safeCurlClose($ch);

if (!$response) {
    echo json_encode([
        'success' => false,
        'status'  => 'openai_request_failed'
    ]);
    exit;
}

// =====================================================
// RESPONSE VALIDATION
// =====================================================

$responseData = json_decode($response, true);
$content = $responseData['choices'][0]['message']['content'] ?? '';

if (empty($content)) {
    echo json_encode([
        'success' => false,
        'status'  => 'invalid_ai_response'
    ]);
    exit;
}

// =====================================================
// PARSE AI JSON (Cleaned)
// =====================================================

$parsed = json_decode(trim($content), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'success'   => false,
        'status'    => 'invalid_ai_json',
        'jsonError' => json_last_error_msg(),
        'content'   => $content
    ]);
    exit;
}

if (!is_array($parsed)) {
    echo json_encode([
        'success' => false,
        'status'  => 'invalid_ai_json_structure'
    ]);
    exit;
}

error_log('[PPC][SECTION-03] AI extraction complete');

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
// PHONE NORMALIZATION
// =====================================================

$parsed['contact']['primaryPhone'] =
    preg_replace(
        '/[^0-9]/',
        '',
        $parsed['contact']['primaryPhone']
    );

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

#region SECTION 09 — Parce#region SECTION 09 — Parcel Resolution (Skeleton)

$data['location']['parcelDetails']   = [];
$data['location']['parcelCount']     = 0;

$data['location']['jurisdictionName'] = null;
$data['location']['jurisdictionType'] = null;

error_log('[PPC][SECTION-09] Parcel resolution skeleton initialized');

#endregion

#region SECTION 99 — Debug Output (Temporary)

echo json_encode([
    'success'  => true,
    'status'   => 'section_09_initialized',
    'location' => $data['location']
], JSON_PRETTY_PRINT);

exit;

#endregion