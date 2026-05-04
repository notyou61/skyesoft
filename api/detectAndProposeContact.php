<?php
// =====================================================
// Skyesoft — detectAndProposeContact.php
// Version: 1.5.0
// Last Updated: 2026-05-01
// Status: Production Ready for UI
// =====================================================

#region SECTION 01 — ⚙️ Runtime Configuration

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

error_log('=== DEBUG START detectAndProposeContact v1.5.0 ===');

require_once __DIR__ . '/askOpenAI.php';
require_once __DIR__ . '/dbConnect.php'; // must expose $pdo

$pdo = getPDO();

#endregion

#region SECTION 02 — 🧰 Helper Functions

function jsonError(string $msg): void {
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

#endregion

#region SECTION 03 — 📥 Input Resolution

$input = json_decode(file_get_contents('php://input'), true);

// -------------------------------------------------
// RAW INPUT (DO NOT MODIFY)
// -------------------------------------------------
$rawInputOriginal = $input['input'] ?? '';

// -------------------------------------------------
// WORKING INPUT (SAFE TO MODIFY)
// -------------------------------------------------
$rawInput = trim($rawInputOriginal);

$activitySessionId = $input['activitySessionId'] ?? 'no_session';

if (empty($rawInput)) {
    jsonError('No input provided');
}

#endregion

#region SECTION 04 — 🧠 AI Prompt Construction

$systemPrompt = <<<EOT
You are a strict structured data extraction engine.

CRITICAL RULES:
- Respond ONLY with valid JSON
- Do NOT include explanations, markdown, or comments
- Output MUST begin with { and end with }
- You MUST return ALL fields in the schema
- NEVER omit keys
- If a value is unknown, return "" (empty string)
- NEVER return partial objects

EXTRACTION RULES:
- Extract the FIRST valid full name as firstName and lastName
- Extract email if present
- Extract phone numbers exactly as shown
- Extract company/entity name if present
- Extract address, city, state, zip if present
- If unsure, leave value as ""

Return EXACTLY this structure:

{
  "intent": "contact_proposal",
  "confidence": 90,
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
      "email": ""
    },
    "location": {
      "address": "",
      "city": "",
      "state": "",
      "zip": "",
      "locationName": ""
    }
  }
}
EOT;

$extractionPrompt = <<<EOT
Extract structured contact data from the following text.

IMPORTANT:
- Always return ALL fields
- Never omit fields
- Use empty string "" if unknown

INPUT:
{$rawInput}
EOT;

#endregion

#region SECTION 05 — 🤖 AI Request Execution

if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/utils/envLoader.php';
}
skyesoftLoadEnv();

$apiKey = getenv("OPENAI_API_KEY");
$googleApiKey = skyesoftGetEnv("GOOGLE_MAPS_BACKEND_API_KEY") ?? getenv("GOOGLE_MAPS_BACKEND_API_KEY");

if (!$apiKey) jsonError('OPENAI_API_KEY not found');

$payload = [
    "model"       => "gpt-4.1-mini",
    "messages"    => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user",   "content" => $extractionPrompt]
    ],
    "temperature" => 0
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer $apiKey"],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 20
]);

$response = curl_exec($ch);
curl_close($ch);

if ($response === false) jsonError('AI request failed');

$decoded = json_decode($response, true);
$content = $decoded['choices'][0]['message']['content'] ?? '';

if (!$content) jsonError('Invalid AI response format');

preg_match('/\{.*\}/s', $content, $matches);
if (empty($matches[0])) jsonError('Invalid AI response format');

$aiData = json_decode($matches[0], true);

if (!$aiData || !isset($aiData['parsed'])) jsonError('Invalid AI response format');

#endregion

#region SECTION 06 — 🛡️ Schema Enforcement + Fallback Recovery

// -------------------------------------------------
// 🧩 Ensure Full Schema (prevents partial AI output)
// -------------------------------------------------
$parsed = $aiData['parsed'] ?? [];

// 🛡️ Schema enforcement (SAFE)
$parsed = array_replace_recursive([
    'entity' => ['name' => ''],
    'contact' => [
        'firstName' => '',
        'lastName' => '',
        'salutation' => '',
        'title' => '',
        'primaryPhone' => '',
        'primaryPhoneRaw' => '',
        'secondaryPhone' => '',
        'secondaryPhoneRaw' => '',
        'email' => ''
    ],
    'location' => [
        'address' => '',
        'city' => '',
        'state' => '',
        'zip' => '',
        'locationName' => ''
    ]
], $parsed ?? []);

// -------------------------------------------------
// 🧠 FIX #3 — Name Fallback (repair AI miss)
// -------------------------------------------------
$parsed = fallbackExtractName($parsed, $rawInput);

// -------------------------------------------------
// 🧠 Email fallback (recommended)
// -------------------------------------------------
if (empty($parsed['contact']['email'])) {

    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $rawInput, $m)) {

        $email = strtolower(trim($m[0]));

        // Validate before assigning
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $parsed['contact']['email'] = $email;
            $parsed['contact']['emailNormalized'] = $email;
        }
    }
}

// -------------------------------------------------
// 🧠 Location Fallback (recover city/state/zip)
// -------------------------------------------------
if (
    empty($parsed['location']['city']) ||
    empty($parsed['location']['state'])
) {

    // Pattern: "City, ST ZIP"
    if (preg_match('/([A-Za-z\s]+),\s*([A-Z]{2})\s*(\d{5})?/', $rawInput, $m)) {

        $city  = ucwords(strtolower(trim($m[1])));
        $state = strtoupper(trim($m[2]));
        $zip   = isset($m[3]) ? substr($m[3], 0, 5) : '';

        if (empty($parsed['location']['city'])) {
            $parsed['location']['city'] = $city;
        }

        if (empty($parsed['location']['state'])) {
            $parsed['location']['state'] = $state;
        }

        if (empty($parsed['location']['zip']) && !empty($zip)) {
            $parsed['location']['zip'] = $zip;
        }
    }
}
// -------------------------------------------------
// 🧠 Street Address Fallback
// -------------------------------------------------
if (empty($parsed['location']['address'])) {

    if (preg_match('/^\s*(\d{1,6}\s+[A-Za-z0-9\s\.\-]+(?:Ave|Avenue|Rd|Road|St|Street|Blvd|Lane|Ln|Dr|Drive|Way))/mi', $rawInput, $m)) {

        $address = trim(preg_replace('/\s+/', ' ', $m[1]));

        $parsed['location']['address'] = $address;
    }
}

#endregion

#region SECTION 07 — 🔍 Intent Validation

if (($aiData['intent'] ?? '') !== 'contact_proposal') {
    echo json_encode([
        'status'  => 'reject',
        'message' => 'Input not recognized as a contact signature.',
        'success' => false
    ]);
    exit;
}

#endregion

#region SECTION 08 — 📞 Assign Primary + Secondary Phones (Enhanced)

// -------------------------------------------------
// 📞 Extract Phones + Extension
// -------------------------------------------------
$phones = extractPhones($rawInput);
$extension = extractPhoneExtension($rawInput);

// -------------------------------------------------
// 🧹 Deduplicate Phones (by raw)
// -------------------------------------------------
$uniquePhones = [];

foreach ($phones as $p) {
    if (!empty($p['raw']) && !isset($uniquePhones[$p['raw']])) {
        $uniquePhones[$p['raw']] = $p;
    }
}

$phones = array_values($uniquePhones);

// -------------------------------------------------
// 🧠 Label-Based Priority (Cell > Mobile > Office)
// -------------------------------------------------
$hasCellLabel = preg_match('/\b(cell|mobile)\b/i', $rawInput);

if ($hasCellLabel && count($phones) >= 2) {
    // Move likely mobile to primary
    [$phones[0], $phones[1]] = [$phones[1], $phones[0]];
}

// -------------------------------------------------
// 📱 PRIMARY PHONE
// -------------------------------------------------
if (!empty($phones[0])) {

    $existingPrimary = $parsed['contact']['primaryPhoneRaw'] ?? null;

    if (empty($existingPrimary)) {

        $parsed['contact']['primaryPhone']    = $phones[0]['formatted'];
        $parsed['contact']['primaryPhoneRaw'] = $phones[0]['raw'];

        // -------------------------------------------------
        // ☎️ Extension (only applies to primary)
        // -------------------------------------------------
        if (!empty($extension)) {
            $parsed['contact']['primaryPhoneExtension'] = $extension;
        }
    }
}

// -------------------------------------------------
// 📞 SECONDARY PHONE
// -------------------------------------------------
if (!empty($phones[1])) {

    $existingSecondary = $parsed['contact']['secondaryPhoneRaw'] ?? null;
    $primaryRaw        = $parsed['contact']['primaryPhoneRaw'] ?? null;

    if (
        empty($existingSecondary) &&
        $phones[1]['raw'] !== $primaryRaw
    ) {
        $parsed['contact']['secondaryPhone']    = $phones[1]['formatted'];
        $parsed['contact']['secondaryPhoneRaw'] = $phones[1]['raw'];
    }
}

// -------------------------------------------------
// 🛡️ Ensure Extension Field Exists (schema safety)
// -------------------------------------------------
if (!isset($parsed['contact']['primaryPhoneExtension'])) {
    $parsed['contact']['primaryPhoneExtension'] = '';
}

#endregion

#region SECTION 09 — 🧩 Data Processing & Enrichment (Deterministic)

// -------------------------------------------------
// 🧩 CORE NORMALIZATION PIPELINE
// -------------------------------------------------
$parsed = normalizeParsed($parsed);
$parsed = inferMissingFields($parsed);
$parsed = inferLocationName($parsed); // Early inference

// -------------------------------------------------
// 🧠 SALUTATION
// -------------------------------------------------
$firstName = trim($parsed['contact']['firstName'] ?? '');
$lastName  = trim($parsed['contact']['lastName'] ?? '');

$existingSalutation = trim($parsed['contact']['salutation'] ?? '');

if ($existingSalutation !== '') {
    $parsed['contact']['salutation'] = $existingSalutation;
    $parsed['contact']['salutationInferred'] = false;
} else {
    $salutation = inferSalutation($firstName, $lastName);
    if ($salutation !== null) {
        $parsed['contact']['salutation'] = $salutation;
        $parsed['contact']['salutationInferred'] = true;
    } else {
        $parsed['contact']['salutation'] = null;
        $parsed['contact']['salutationInferred'] = null;
    }
}

// -------------------------------------------------
// 📍 ADDRESS PREP + SUITE EXTRACTION
// -------------------------------------------------
$fullAddress = trim(implode(' ', array_filter([
    $parsed['location']['address'] ?? '',
    $parsed['location']['city'] ?? '',
    $parsed['location']['state'] ?? '',
    $parsed['location']['zip'] ?? ''
])));

$lookupAddress = sanitizeAddressForLookup($fullAddress);

// Extract suite
$locationSuite = extractSuite($parsed['location']['address'] ?? '');
if ($locationSuite) {
    $parsed['location']['locationAddressSuite'] = $locationSuite;
    // Clean suite from main address
    $parsed['location']['address'] = trim(preg_replace('/\b(Suite|Ste|Unit|Apt|#)\s*[A-Za-z0-9\-]+\b/i', '', $parsed['location']['address']));
}

// -------------------------------------------------
// 🌍 GOOGLE LOCATION VALIDATION (Always Attempt)
// -------------------------------------------------
$locationValidation = [
    'status'               => 'invalid',
    'confidence'           => 0,
    'placeIdResolved'      => false,
    'latLonResolved'       => false,
    'isMaricopa'           => false,
    'apnResolved'          => false,
    'jurisdictionResolved' => false,
    'issues'               => []
];

$googleData = null;
if (!empty($googleApiKey) && !empty($fullAddress)) {

    $googleData = validateLocationWithGoogle([
        'address' => $lookupAddress,
        'city'    => $parsed['location']['city'] ?? '',
        'state'   => $parsed['location']['state'] ?? '',
        'zip'     => $parsed['location']['zip'] ?? ''
    ]);

    if (empty($googleData['placeId']) && !empty($fullAddress)) {
        $googleData = validateLocationWithGoogle([
            'address' => $fullAddress,
            'city'    => $parsed['location']['city'] ?? '',
            'state'   => $parsed['location']['state'] ?? '',
            'zip'     => $parsed['location']['zip'] ?? ''
        ]);
    }

    if (!empty($googleData['placeId'])) {
        $parsed['location']['locationPlaceId']  = $googleData['placeId'];
        $parsed['location']['latitude']         = $googleData['lat'] ?? null;
        $parsed['location']['longitude']        = $googleData['lng'] ?? null;
        $parsed['location']['formattedAddress'] = str_replace(', USA', '', $googleData['address'] ?? $fullAddress);

        $locationValidation['status']          = 'valid';
        $locationValidation['placeIdResolved'] = true;
        $locationValidation['latLonResolved']  = true;
        $locationValidation['confidence']      = 90;
    } else {
        $locationValidation['issues'][] = 'google_place_not_resolved';
    }
}

// -------------------------------------------------
// 🗺️ CENSUS GEO (Always Attempt)
// -------------------------------------------------
$geoAddress = $parsed['location']['formattedAddress'] ?? $lookupAddress ?? $fullAddress;
$geo = resolveGeographyFromAddress($geoAddress);

if ($geo) {
    if (!empty($geo['county'])) $parsed['location']['county'] = trim($geo['county']);
    if (!empty($geo['state']))  $parsed['location']['state']  = $geo['state'];
    if (!empty($geo['countyFips'])) $parsed['location']['countyFips'] = $geo['countyFips'];
}

// -------------------------------------------------
// 🌵 MARICOPA LOGIC (Broader Trigger)
// -------------------------------------------------
$county = strtoupper(trim($parsed['location']['county'] ?? ''));
$state  = strtoupper(trim($parsed['location']['state'] ?? ''));

$isMaricopa = ($county === 'MARICOPA' || $state === 'AZ');

$locationValidation['isMaricopa'] = $isMaricopa;

$parcel = null;
$parcelCandidates = null;
$jurisdiction = null;
$parcelLookupAttempted = false;

if ($isMaricopa && !empty($parsed['location']['address'])) {

    $parcelLookupAttempted = true;
    $parcelLookupAddress = $parsed['location']['formattedAddress'] ?? $lookupAddress ?? $fullAddress;

    $mca = lookupMaricopaParcel($parcelLookupAddress);

    if ($mca && !empty($mca['apn'])) {
        $apnRaw = preg_replace('/[^A-Za-z0-9]/', '', $mca['apn']);

        $parcel = [
            'apnRaw'     => $apnRaw,
            'apnDisplay' => formatAPN($apnRaw),
            'source'     => $mca['source'],
            'confidence' => 95
        ];

        // ✅ PARCEL IS AUTHORITATIVE
        $jurisdiction = $mca['jurisdiction'] ?? null;

    } else {
        $locationValidation['issues'][] = 'parcel_lookup_failed';
    }
}

// Normalize jurisdiction (only if not from parcel)
if (!empty($jurisdiction)) {
    $jurisdiction = ucwords(strtolower(trim($jurisdiction)));
}

// -------------------------------------------------
// 🧠 PARCEL STATUS (Authoritative — Simple & Correct)
// -------------------------------------------------
if (!empty($parcel) && !empty($parcel['apnRaw'])) {
    $locationValidation['parcelStatus'] = 'resolved';
} elseif ($parcelLookupAttempted) {
    $locationValidation['parcelStatus'] = 'not_found';
} else {
    $locationValidation['parcelStatus'] = 'not_attempted';
}

// -------------------------------------------------
// Final resolution flags (AUTHORITATIVE)
// -------------------------------------------------
$locationValidation['apnResolved']          = !empty($parcel);
$locationValidation['jurisdictionResolved'] = !empty($jurisdiction);

// -------------------------------------------------
// Issue Reset (prevents duplication across passes)
// -------------------------------------------------
$locationValidation['issues'] = array_values(array_unique($locationValidation['issues'] ?? []));

// -------------------------------------------------
// Maricopa Requirements
// -------------------------------------------------
if ($isMaricopa) {
    if (!$locationValidation['apnResolved']) {
        $locationValidation['issues'][] = 'maricopa_parcel_required';
    }
    if (!$locationValidation['jurisdictionResolved']) {
        $locationValidation['issues'][] = 'maricopa_jurisdiction_required';
    }
}

// -------------------------------------------------
// STATUS (Single Source of Truth)
// -------------------------------------------------
if (!$locationValidation['placeIdResolved']) {

    $locationValidation['status'] = 'invalid';

} elseif ($isMaricopa && (
    !$locationValidation['apnResolved'] ||
    !$locationValidation['jurisdictionResolved']
)) {

    $locationValidation['status'] = 'partial';

} else {

    $locationValidation['status'] = 'valid';
}

// -------------------------------------------------
// OPTIONAL (HIGH VALUE) — Explicit Readiness Flag
// -------------------------------------------------
$locationValidation['readyForCommit'] = (
    $locationValidation['status'] === 'valid'
);

// -------------------------------------------------
// 🧾 FINAL INFERENCE
// -------------------------------------------------
$parsed = inferLocationName($parsed);

// -------------------------------------------------
// 🧾 DATA INTEGRITY STATUS (DIS)
// -------------------------------------------------
$dataIntegrityStatus = ['status' => 'complete', 'missing' => []];

$missing = validateParsed($parsed);

if (!empty($missing)) {
    $dataIntegrityStatus['status'] = 'incomplete';
    $dataIntegrityStatus['missing'] = $missing;
}

// -------------------------------------------------
// 🔁 DUPLICATES
// -------------------------------------------------
if (!$pdo) {
    error_log('PDO connection missing — skipping duplicate detection');
    $duplicate = ['status' => 'none'];
    $locationDuplicate = ['status' => 'none'];
} else {
    $duplicate = evaluateDuplicate($parsed, $pdo);
    $locationDuplicate = evaluateLocationDuplicate($parsed, $pdo);
}

#endregion

#region SECTION 10 — 🧠 PCM + Final Response + AI Narrative

// -------------------------------------------------
// 🔁 DUPLICATE (contact-level)
// -------------------------------------------------
$duplicate = isset($duplicate) ? $duplicate : ['status' => 'none'];

// -------------------------------------------------
// 📍 LOCATION DUPLICATE
// -------------------------------------------------
$locationDuplicate = isset($locationDuplicate) ? $locationDuplicate : ['status' => 'none'];

// -------------------------------------------------
// 🚫 AUTHORITATIVE PCM DECISION (Single Source of Truth)
// -------------------------------------------------
// Priority order: Data Integrity → Parcel Status → Duplicates → New

if ($dataIntegrityStatus['status'] !== 'complete') {

    $pcm = ['status' => 'incomplete', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_missing_fields'];

} elseif (
    isset($locationValidation['parcelStatus']) && 
    $locationValidation['parcelStatus'] !== 'resolved'
) {

    // Parcel-level failure takes precedence (most business-critical)
    $pcm = ['status' => 'invalid_location', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_location'];

} elseif ($locationValidation['isMaricopa'] && (!$locationValidation['apnResolved'] || !$locationValidation['jurisdictionResolved'])) {

    $pcm = ['status' => 'invalid_location', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_location'];

} elseif ($duplicate['status'] === 'exact') {

    $pcm = ['status' => 'duplicate_contact', 'readyForCommit' => false, 'requiresReview' => false, 'blocksCommit' => true, 'action' => 'reject_duplicate'];

} elseif ($duplicate['status'] === 'possible') {

    $pcm = ['status' => 'possible_duplicate_contact', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_duplicate'];

} elseif ($locationDuplicate['status'] === 'exact') {

    $pcm = ['status' => 'existing_location', 'readyForCommit' => true, 'requiresReview' => false, 'blocksCommit' => false, 'action' => 'link_existing_location'];

} elseif ($locationDuplicate['status'] === 'possible') {

    $pcm = ['status' => 'possible_location_duplicate', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_location'];

} else {

    $pcm = ['status' => 'new_elc', 'readyForCommit' => true, 'requiresReview' => false, 'blocksCommit' => false, 'action' => 'insert_new'];

}

// -------------------------------------------------
// 📦 Clean DB-Ready Data
// -------------------------------------------------
$data = [
    'entity' => [
        'entityName' => isset($parsed['entity']['name']) ? trim($parsed['entity']['name']) : ''
    ],
    'location' => [
        'locationName'            => isset($parsed['location']['locationName']) ? trim($parsed['location']['locationName']) : '',
        'locationPlaceId'         => $parsed['location']['locationPlaceId'] ?? null,
        'locationLatitude'        => $parsed['location']['latitude'] ?? null,
        'locationLongitude'       => $parsed['location']['longitude'] ?? null,
        'locationAddress'         => isset($parsed['location']['address']) ? preg_replace('/\s+/', ' ', trim($parsed['location']['address'])) : '',
        'locationAddressSuite'    => isset($parsed['location']['addressSuite']) ? trim($parsed['location']['addressSuite'])  : '',
        'locationCity'            => isset($parsed['location']['city']) ? trim($parsed['location']['city']) : '',
        'locationState'           => isset($parsed['location']['state']) ? strtoupper(trim($parsed['location']['state'])) : '',
        'locationZip'             => isset($parsed['location']['zip']) ? trim($parsed['location']['zip']) : '',
        'locationCounty'          => isset($parsed['location']['county']) ? trim($parsed['location']['county']) : '',
        'locationCountyFips'      => isset($parsed['location']['countyFips']) ? trim($parsed['location']['countyFips']) : '',
        'locationParcelNumber'    => $parcel['apnDisplay'] ?? null,
        'locationParcelNumberRaw' => $parcel['apnRaw'] ?? null,
        'locationJurisdiction'    => isset($jurisdiction) ? $jurisdiction : null,
        'locationIsBilling'       => 0,
        'locationNote'            => '',
        'locationZone'            => '',
        'locationIsNotValid'      => 0
    ],
    'contact' => [
        'contactSalutation'             => $parsed['contact']['salutation'] ?? null,
        'contactFirstName'              => isset($parsed['contact']['firstName']) ? trim($parsed['contact']['firstName']) : '',
        'contactLastName'               => isset($parsed['contact']['lastName']) ? trim($parsed['contact']['lastName']) : '',
        'contactTitle'                  => isset($parsed['contact']['title']) ? trim($parsed['contact']['title']) : '',
        'contactIsBilling'              => 0,
        'contactPrimaryPhone'           => $parsed['contact']['primaryPhone'] ?? '',
        'contactPrimaryPhoneRaw'        => $parsed['contact']['primaryPhoneRaw'] ?? '',
        'contactPrimaryPhoneExtension'  => isset($parsed['contact']['primaryPhoneExtension']) ? trim($parsed['contact']['primaryPhoneExtension']) : '',
        'contactSecondaryPhone'         => $parsed['contact']['secondaryPhone'] ?? '',
        'contactSecondaryPhoneRaw'      => $parsed['contact']['secondaryPhoneRaw'] ?? '',
        'contactEmail'                  => $parsed['contact']['email'] ?? '',
        'contactEmailNormalized'        => $parsed['contact']['emailNormalized'] ?? '',
        'contactEmailConfirmed'         => 0,
        'contactNote'                   => '',
        'contactIsNotValid'             => 0,
        'isActive'                      => 1
    ]
];

// -------------------------------------------------
// Decision + Meta + Issues
// -------------------------------------------------
$decision = [
    'ready'     => isset($pcm['readyForCommit']) ? $pcm['readyForCommit'] : false,
    'action'    => isset($pcm['action']) ? $pcm['action'] : 'review',
    'pcmStatus' => isset($pcm['status']) ? $pcm['status'] : 'unknown'
];

$issues = array_values(array_unique(array_merge(
    isset($dataIntegrityStatus['missing']) ? $dataIntegrityStatus['missing'] : [],
    isset($locationValidation['issues']) ? $locationValidation['issues'] : []
)));

$issuesText = !empty($issues) ? implode(', ', $issues) : 'none';

// Meta (Add parcelStatus for UI)
$meta = [
    'inferences' => [
        'salutationInferred'   => isset($parsed['contact']['salutationInferred']) ? $parsed['contact']['salutationInferred'] : false,
        'locationNameInferred' => isset($parsed['location']['locationNameInferred']) ? $parsed['location']['locationNameInferred'] : false,
        'entityNameInferred'   => isset($parsed['entity']['nameInferred']) ? $parsed['entity']['nameInferred'] : false,
    ],
    'enrichments' => array_values(array_filter([
        !empty($parsed['location']['locationPlaceId']) ? 'google_geocode' : null,
        !empty($parsed['location']['county']) ? 'census_county' : null,
        isset($locationValidation['apnResolved']) && $locationValidation['apnResolved'] ? 'maricopa_parcel' : null,
    ])),
    'flags' => [
        'isMaricopa'           => $locationValidation['isMaricopa'] ?? false,
        'locationValid'        => $locationValidation['status'] ?? 'invalid',
        'parcelStatus'         => $locationValidation['parcelStatus'] ?? 'unknown',   // ← ADD THIS
        'apnResolved'          => $locationValidation['apnResolved'] ?? false,
        'jurisdictionResolved' => $locationValidation['jurisdictionResolved'] ?? false
    ]
];

// -------------------------------------------------
// AI NARRATIVE (Tighter — No Hallucination)
// -------------------------------------------------
$entityName  = isset($parsed['entity']['name']) ? trim($parsed['entity']['name']) : '';
$fullName    = trim((isset($parsed['contact']['firstName']) ? trim($parsed['contact']['firstName']) : '') . ' ' . 
                 (isset($parsed['contact']['lastName']) ? trim($parsed['contact']['lastName']) : ''));
$locationStr = isset($parsed['location']['locationName']) ? trim($parsed['location']['locationName']) : '';

$narrativePrompt = 'You are summarizing a structured contact proposal result for a business system. ' .
'ONLY use the facts below. Do not infer business context.' . "\n\n" .
'RULES:' . "\n" .
'- Maricopa County requires valid parcel (APN) + jurisdiction for commit.' . "\n" .
'- Be concise and factual.' . "\n\n" .
'DATA:' . "\n" .
'- Entity: ' . $entityName . "\n" .
'- Contact: ' . $fullName . "\n" .
'- Location: ' . $locationStr . "\n" .
'- Location Status: ' . (isset($locationValidation['status']) ? $locationValidation['status'] : 'unknown_status') . "\n" .
'- Parcel Status: ' . (isset($locationValidation['parcelStatus']) ? $locationValidation['parcelStatus'] : 'unknown') . "\n" .
'- APN Resolved: ' . (isset($locationValidation['apnResolved']) ? ($locationValidation['apnResolved'] ? 'true' : 'false') : 'false') . "\n" .
'- Jurisdiction Resolved: ' . (isset($locationValidation['jurisdictionResolved']) ? ($locationValidation['jurisdictionResolved'] ? 'true' : 'false') : 'false') . "\n" .
'- Decision: ' . (isset($decision['pcmStatus']) ? $decision['pcmStatus'] : 'new_elc') . "\n" .
'- Ready: ' . (isset($decision['ready']) ? ($decision['ready'] ? 'true' : 'false') : 'false') . "\n" .
'- Issues: ' . $issuesText . "\n\n" .
'Write a clear 2-3 sentence explanation for the user. Explain successes, issues, and next steps. Return ONLY plain text.';

$apiKey = skyesoftGetEnv("OPENAI_API_KEY") ?: getenv("OPENAI_API_KEY");
$narrativeText = '';
if ($apiKey && function_exists('callOpenAI')) {
    $narrativeText = callOpenAI($narrativePrompt, $apiKey);
}

if (empty($narrativeText)) {
    $narrativeText = (isset($decision['ready']) && $decision['ready'])
        ? 'Contact proposal is complete and ready for database insertion.'
        : 'Contact proposal requires review due to missing or invalid information.';
}

// -------------------------------------------------
// FINAL OUTPUT
// -------------------------------------------------
echo json_encode([
    'status'        => 'proposed',
    'confidence'    => isset($aiData['confidence']) ? $aiData['confidence'] : 82,
    'success'       => true,
    'rawInput'      => [
        'original' => $rawInputOriginal,
        'type'     => 'signature',
        'source'   => 'skyebot_prompt'
    ],
    'data'          => $data,
    'parcelDetails' => isset($parcel) ? $parcel : (object)[],
    'decision'      => $decision,
    'meta'          => $meta,
    'issues'        => $issues,
    'narrative'     => ['text' => $narrativeText],

    'activitySessionId' => $activitySessionId
], JSON_UNESCAPED_SLASHES);

#endregion

#region SECTION 11 — 🛠️ Internal Utilities

// 🧩 normalizeParsed — standardize parsed contact structure
function normalizeParsed(array $parsed): array {
    if (!empty($parsed['contact']['email'])) {
        $email = trim($parsed['contact']['email']);
        $parsed['contact']['email'] = strtolower($email);
        $parsed['contact']['emailNormalized'] = strtolower($email);
    }

    if (!empty($parsed['contact']['primaryPhone'])) {
        $phoneStr = $parsed['contact']['primaryPhone'];
        $digits = preg_replace('/[^0-9]/', '', $phoneStr);
        if (strlen($digits) === 10) {
            $parsed['contact']['primaryPhone'] = '(' . substr($digits, 0, 3) . ') ' .
                                                 substr($digits, 3, 3) . '-' . substr($digits, 6);
        }
        $parsed['contact']['primaryPhoneRaw'] = $digits;
    }

    if (!empty($parsed['location']['state'])) {
        $parsed['location']['state'] = strtoupper(trim($parsed['location']['state']));
    }

    if (isset($parsed['contact']['title']) && trim($parsed['contact']['title']) === '') {
        $parsed['contact']['title'] = null;
    }

    return $parsed;
}
// 🧠 inferMissingFields — infer missing fields with flags (Option A)
function inferMissingFields(array $parsed): array {

    // -------------------------------------------------
    // 🏢 ENTITY — Initialize flags
    // -------------------------------------------------
    if (!isset($parsed['entity']['nameInferred'])) {
        $parsed['entity']['nameInferred'] = false;
    }

    if (!isset($parsed['entity']['nameConfirmed'])) {
        $parsed['entity']['nameConfirmed'] = !empty($parsed['entity']['name'] ?? '');
    }

    // -------------------------------------------------
    // 🏢 ENTITY — Infer from email domain (ONLY if missing)
    // -------------------------------------------------
    if (empty($parsed['entity']['name'] ?? '') && !empty($parsed['contact']['email'] ?? '')) {

        $email = strtolower(trim($parsed['contact']['email']));
        $atPos = strpos($email, '@');

        if ($atPos !== false) {

            $domain = substr($email, $atPos + 1);

            // Remove common subdomains
            $domain = preg_replace('/^(mail|email|info|contact|admin)\./i', '', $domain);

            $dotPos = strpos($domain, '.');

            if ($dotPos !== false) {

                $company = substr($domain, 0, $dotPos);

                // Clean company string
                $company = str_replace(['-', '_'], ' ', $company);
                $company = preg_replace('/[^a-zA-Z0-9\s]/', '', $company);
                $company = trim($company);

                if (!empty($company)) {
                    $parsed['entity']['name'] = ucwords($company);
                    $parsed['entity']['nameInferred'] = true;
                    $parsed['entity']['nameConfirmed'] = false;
                }
            }
        }
    }

    return $parsed;
}
// 🔍 validateParsed — validate required parsed fields
function validateParsed(array $parsed): array {

    $missing = [];

    // -------------------------
    // CONTACT
    // -------------------------
    if (empty($parsed['contact']['firstName'])) $missing[] = 'contact.firstName';
    if (empty($parsed['contact']['lastName']))  $missing[] = 'contact.lastName';

    $hasEmail = !empty($parsed['contact']['email']);
    $hasPhone = !empty($parsed['contact']['primaryPhoneRaw']);

    if (!$hasEmail && !$hasPhone) {
        $missing[] = 'contact.contactMethod';
    }

    // -------------------------
    // ENTITY (REQUIRED)
    // -------------------------
    if (empty($parsed['entity']['name'])) {
        $missing[] = 'entity.name';
    }

    // -------------------------
    // LOCATION CORE
    // -------------------------
    if (empty($parsed['location']['address'])) $missing[] = 'location.address';
    if (empty($parsed['location']['city']))    $missing[] = 'location.city';
    if (empty($parsed['location']['state']))   $missing[] = 'location.state';

    // -------------------------
    // LOCATION NAME (REQUIRED)
    // -------------------------
    if (empty($parsed['location']['locationName'])) {
        $missing[] = 'location.locationName';
    }

    return $missing;
}
// 🧼 sanitizeAddressForLookup — clean address for lookup
function sanitizeAddressForLookup(string $input): string {
    $clean = preg_replace('/\s+/', ' ', $input);
    $clean = preg_replace('/#\s*\w+/i', '', $clean);
    $clean = preg_replace('/\b(Suite|Ste|Unit|Apt|#)\b\.?\s*\w+/i', '', $clean);
    $clean = preg_replace('/^[^0-9]*?(?=\d)/', '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}
// 🏷️ formatAPN — format parcel number
function formatAPN(string $apnRaw): string {
    $clean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($apnRaw));
    if (strlen($clean) === 8) {
        return substr($clean, 0, 3) . '-' . substr($clean, 3, 2) . '-' . substr($clean, 5);
    }
    if (strlen($clean) === 13) {
        return substr($clean, 0, 3) . '-' . substr($clean, 3, 2) . '-' .
               substr($clean, 5, 3) . '-' . substr($clean, 8);
    }
    return $clean;
}
// 🗺️ resolveGeographyFromAddress — resolve county/state from address
function resolveGeographyFromAddress(string $address): ?array {
    if (!$address) return null;

    $url = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress" .
           "?address=" . urlencode($address) .
           "&benchmark=Public_AR_Current" .
           "&vintage=Current_Current" .
           "&format=json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) return null;

    $data = json_decode($response, true);
    $result = $data['result']['addressMatches'][0] ?? null;
    if (!$result) return null;

    $geo = $result['geographies'] ?? [];
    $countyRaw = $geo['Counties'][0]['NAME'] ?? null;
    $state = $geo['States'][0]['STUSAB'] ?? null;
    $countyFips = $geo['Counties'][0]['COUNTY'] ?? null;

    $county = $countyRaw ? str_replace(' County', '', $countyRaw) : null;

    return [
        'county'         => $county,
        'state'          => $state,
        'countyFips'     => $countyFips, // ✅ REQUIRED
        'matchedAddress' => $result['matchedAddress'] ?? null
    ];
}
// 🧾 lookupMaricopaParcel — fetch Maricopa parcel data
function lookupMaricopaParcel(string $address): ?array {
    $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query";

    $address = str_replace(', USA', '', trim($address));
    $address = preg_replace('/\s+/', ' ', $address);

    $parts = [];
    if (preg_match('/^(\d+)\s+(W|E|N|S)?\s*([A-Za-z\s]+?)\s+(AVE|RD|ST|DR|LN|WAY|BLVD|PL|CT|CIR)?,?\s*([A-Za-z\s]+?),\s*AZ/i', $address, $m)) {
        $parts = [
            'num'   => trim($m[1]),
            'dir'   => trim($m[2] ?? ''),
            'name'  => trim($m[3]),
            'type'  => trim($m[4] ?? ''),
            'city'  => trim($m[5])
        ];
    }

    $whereClauses = [];
    if (!empty($parts['num'])) $whereClauses[] = "PHYSICAL_STREET_NUM = '{$parts['num']}'";
    if (!empty($parts['name'])) $whereClauses[] = "UPPER(PHYSICAL_STREET_NAME) LIKE UPPER('%{$parts['name']}%')";
    if (!empty($parts['city'])) $whereClauses[] = "UPPER(PHYSICAL_CITY) = UPPER('{$parts['city']}')";

    $where = $whereClauses ? implode(' AND ', $whereClauses) : "1=1";

    $params = http_build_query([
        'where'          => $where,
        'outFields'      => 'APN,PHYSICAL_ADDRESS,JURISDICTION',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ]);

    $response = @file_get_contents("{$url}?{$params}");
    if (!$response) return null;

    $data = json_decode($response, true);

    if (empty($data['features'])) {
        $clean = strtoupper(trim(str_replace(',', '', $address)));
        $fallbackWhere = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%{$clean}%')";
        $params = http_build_query(['where' => $fallbackWhere, 'outFields' => 'APN,PHYSICAL_ADDRESS,JURISDICTION', 'f' => 'json']);
        $response = @file_get_contents("{$url}?{$params}");
        $data = json_decode($response, true);
    }

    $attr = $data['features'][0]['attributes'] ?? null;
    if (!$attr || empty($attr['APN'])) return null;

    return [
        'apn'          => $attr['APN'],
        'source'       => 'mca_arcgis_mcassessor',
        'confidence'   => 95,
        'matched'      => $attr['PHYSICAL_ADDRESS'] ?? $address,
        'jurisdiction' => $attr['JURISDICTION'] ?? null
    ];
}
// 🏛️ resolveMaricopaJurisdiction — resolve city jurisdiction
function resolveMaricopaJurisdiction(string $address): ?string {
    return null;
}
// 📍 validateLocationWithGoogle — resolve placeId and coordinates
function validateLocationWithGoogle(array $locationInput): array {
    $queryParts = [
        $locationInput['address'] ?? '',
        $locationInput['city'] ?? '',
        $locationInput['state'] ?? '',
        $locationInput['zip'] ?? ''
    ];

    $query = trim(implode(', ', array_filter($queryParts)));
    if ($query === '') return ['placeId' => null];

    $apiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY');
    if (!$apiKey) return ['placeId' => null];

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($query) . '&key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return ['placeId' => null];
    }

    $data = json_decode($response, true);

    if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) {
        return ['placeId' => null];
    }

    $result = $data['results'][0];

    return [
        'placeId' => $result['place_id'] ?? null,
        'address' => $result['formatted_address'] ?? $query,
        'lat'     => $result['geometry']['location']['lat'] ?? null,
        'lng'     => $result['geometry']['location']['lng'] ?? null
    ];
}
// ✅ assessContactLegitimacy — evaluate contact against acceptance rules
function assessContactLegitimacy(array $parsed, array $meta, array $issues): array {
    $failures = [];
    $warnings = [];

    // 1. Structural
    if (empty($parsed['contact']['firstName']) || empty($parsed['contact']['lastName'])) {
        $failures[] = 'missing_name';
    }

    $hasEmail = !empty($parsed['contact']['email']);
    $hasPhone = !empty($parsed['contact']['primaryPhoneRaw']);

    if (!$hasEmail && !$hasPhone) {
        $failures[] = 'missing_contact_method';
    }

    if (empty($parsed['location']['address']) || empty($parsed['location']['city']) || empty($parsed['location']['state'])) {
        $failures[] = 'missing_location_core';
    }

    // 2. Critical Location & Maricopa Rules
    if (empty($parsed['location']['locationPlaceId'])) {
        $failures[] = 'missing_placeId';
    }

    if (!empty($meta['is_maricopa'])) {
        if (empty($meta['parcel'])) {
            $failures[] = 'missing_parcel';
        }
        if (empty($meta['jurisdiction'])) {
            $failures[] = 'missing_jurisdiction';
        }
    }

    // 3. Identity Sanity (improved)
    $invalidNames = ['test', 'admin', 'user', 'unknown', 'dummy', 'sample'];
    $firstLower = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    if (strlen($firstLower) < 2 || in_array($firstLower, $invalidNames)) {
        $failures[] = 'invalid_name';
    }

    // 4. Format validation
    if ($hasEmail && !filter_var($parsed['contact']['email'], FILTER_VALIDATE_EMAIL)) {
        $failures[] = 'invalid_email';
    }
    if ($hasPhone && !preg_match('/^\d{10}$/', $parsed['contact']['primaryPhoneRaw'])) {
        $warnings[] = 'invalid_phone_format';
    }

    // 5. Issue mapping
    foreach ($issues as $issue) {
        if (in_array($issue, ['missing_required_fields'])) {
            $failures[] = $issue;
        }
    }

    // Deduplicate
    $failures = array_values(array_unique($failures));
    $warnings = array_values(array_unique($warnings));

    // Severity for UI
    $severity = !empty($failures) ? 'critical' : (!empty($warnings) ? 'warning' : 'none');

    if (!empty($failures)) {
        return [
            'status'   => 'reject',
            'severity' => $severity,
            'failures' => $failures,
            'warnings' => $warnings,
            'readyForCommit' => false
        ];
    }

    if (!empty($warnings)) {
        return [
            'status'   => 'partial',
            'severity' => $severity,
            'failures' => [],
            'warnings' => $warnings,
            'readyForCommit' => false
        ];
    }

    return [
        'status'   => 'accepted',
        'severity' => $severity,
        'failures' => [],
        'warnings' => [],
        'readyForCommit' => true
    ];
}
// 📞 extractPhones — parse all phone numbers
function extractPhones(string $input): array {

    // Find all phone-like patterns
    preg_match_all('/\(?\d{3}\)?[\s\.\-]?\d{3}[\.\-]?\d{4}/', $input, $matches);

    $phones = [];

    foreach ($matches[0] as $raw) {

        // Normalize
        $digits = preg_replace('/\D/', '', $raw);

        if (strlen($digits) === 10) {
            $phones[] = [
                'formatted' => sprintf('(%s) %s-%s',
                    substr($digits, 0, 3),
                    substr($digits, 3, 3),
                    substr($digits, 6, 4)
                ),
                'raw' => $digits
            ];
        }
    }

    return $phones;
}
// 📍 Infer Location Name - priority:
function inferLocationName(array $parsed): array {

    // -------------------------------------------------
    // 1. Already provided → confirmed
    // -------------------------------------------------
    if (!empty($parsed['location']['locationName'])) {
        $parsed['location']['locationNameConfirmed'] = true;
        $parsed['location']['locationNameInferred'] = false;
        return $parsed;
    }

    $entity  = trim($parsed['entity']['name'] ?? '');
    $address = trim($parsed['location']['address'] ?? '');
    $city    = trim($parsed['location']['city'] ?? '');

    // -------------------------------------------------
    // 2. Entity + City (PREFERRED)
    // -------------------------------------------------
    if (!empty($entity) && !empty($city)) {

        $parsed['location']['locationName'] = $entity . ' - ' . $city;

        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;

        return $parsed;
    }

    // -------------------------------------------------
    // 3. Address + City (FALLBACK)
    // -------------------------------------------------
    if (!empty($address) && !empty($city)) {

        $parsed['location']['locationName'] = $address . ' - ' . $city;

        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;

        return $parsed;
    }

    // -------------------------------------------------
    // 4. Nothing usable
    // -------------------------------------------------
    $parsed['location']['locationName'] = '';
    $parsed['location']['locationNameInferred'] = false;
    $parsed['location']['locationNameConfirmed'] = false;

    return $parsed;
}
// 🧠 fallbackExtractName — recover name if AI fails
function fallbackExtractName(array $parsed, string $rawInput): array {

    $first = $parsed['contact']['firstName'] ?? '';
    $last  = $parsed['contact']['lastName'] ?? '';

    if (empty($first) || empty($last)) {

        // Look for first valid "First Last" pattern
        if (preg_match('/^\s*([A-Za-z]{2,})\s+([A-Za-z]{2,})/m', $rawInput, $m)) {

            if (empty($first)) {
                $parsed['contact']['firstName'] = ucfirst(strtolower($m[1]));
            }

            if (empty($last)) {
                $parsed['contact']['lastName'] = ucfirst(strtolower($m[2]));
            }
        }
    }

    return $parsed;
}
// 🔁 evaluateDuplicate — DB-backed duplicate detection
function evaluateDuplicate(array $parsed, PDO $pdo): array {

    #region Normalize Inputs

    $email = strtolower(trim($parsed['contact']['email'] ?? ''));
    $phone = preg_replace('/\D/', '', $parsed['contact']['primaryPhoneRaw'] ?? '');
    $first = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    $last  = strtolower(trim($parsed['contact']['lastName'] ?? ''));

    #endregion

    #region 1. Exact Email Match

    if (!empty($email)) {

        $sql = "
            SELECT contactId, contactEntityId
            FROM tblContacts
            WHERE LOWER(contactEmail) = :email
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['email' => $email]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status'    => 'exact',
                'contactId' => $row['contactId'],
                'entityId'  => $row['contactEntityId'],
                'matchType' => 'email'
            ];
        }
    }

    #endregion

    #region 2. Phone Match

    if (!empty($phone)) {

        $sql = "
            SELECT contactId, contactEntityId
            FROM tblContacts
            WHERE contactPrimaryPhoneRaw = :phone
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['phone' => $phone]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status'    => 'possible',
                'contactId' => $row['contactId'],
                'entityId'  => $row['contactEntityId'],
                'matchType' => 'phone'
            ];
        }
    }

    #endregion

    #region 3. Name Match

    if (!empty($first) && !empty($last)) {

        $sql = "
            SELECT contactId, contactEntityId
            FROM tblContacts
            WHERE LOWER(contactFirstName) = :first
              AND LOWER(contactLastName) = :last
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'first' => $first,
            'last'  => $last
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status'    => 'possible',
                'contactId' => $row['contactId'],
                'entityId'  => $row['contactEntityId'],
                'matchType' => 'name'
            ];
        }
    }

    #endregion

    #region Default Result

    return [
        'status'    => 'none',
        'contactId' => null,
        'entityId'  => null,
        'matchType' => null
    ];

    #endregion
}
// 🧼 normalizeLocationName — standardize for comparison
function normalizeLocationName(string $name): string {

    $name = strtolower($name);

    // Remove punctuation
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);

    // Normalize whitespace
    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);
}
// 📍 evaluateLocationDuplicate
function evaluateLocationDuplicate(array $parsed, PDO $pdo): array {

    $entityName = trim($parsed['entity']['name'] ?? '');
    $locationName = trim($parsed['location']['locationName'] ?? '');
    $city = strtolower(trim($parsed['location']['city'] ?? ''));
    $placeId = $parsed['location']['locationPlaceId'] ?? null;

    if (empty($entityName) || empty($locationName)) {
        return ['status' => 'none'];
    }

    $entityId = resolveEntityIdByName($entityName, $pdo);

    if (!$entityId) {
        return [
            'status' => 'new_entity',
            'entityId' => null
        ];
    }

    $normalizedInput = normalizeLocationName($locationName);

    // -------------------------------------------------
    // 1. Exact PlaceId match (STRONGEST)
    // -------------------------------------------------
    if (!empty($placeId)) {

        $stmt = $pdo->prepare("
            SELECT locationId, locationName
            FROM tblLocations
            WHERE locationPlaceId = :placeId
            AND entityId = :entityId
            LIMIT 1
        ");

        $stmt->execute([
            'placeId' => $placeId,
            'entityId' => $entityId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status' => 'exact',
                'locationId' => $row['locationId'],
                'matchType' => 'placeId'
            ];
        }
    }

    // -------------------------------------------------
    // 2. Name + City match (normalized)
    // -------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT locationId, locationName, city
        FROM tblLocations
        WHERE entityId = :entityId
    ");

    $stmt->execute(['entityId' => $entityId]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $dbName = normalizeLocationName($row['locationName']);
        $dbCity = strtolower(trim($row['city'] ?? ''));

        if ($dbName === $normalizedInput && $dbCity === $city) {

            return [
                'status' => 'exact',
                'locationId' => $row['locationId'],
                'matchType' => 'name_city'
            ];
        }
    }

    // -------------------------------------------------
    // 3. Loose match (same city, similar name)
    // -------------------------------------------------
    foreach ($pdo->query("
        SELECT locationId, locationName, city
        FROM tblLocations
        WHERE entityId = {$entityId}
    ") as $row) {

        $dbName = normalizeLocationName($row['locationName']);
        $dbCity = strtolower(trim($row['city'] ?? ''));

        if ($dbCity === $city) {

            similar_text($dbName, $normalizedInput, $percent);

            if ($percent > 85) {
                return [
                    'status' => 'possible',
                    'locationId' => $row['locationId'],
                    'matchType' => 'fuzzy'
                ];
            }
        }
    }

    return [
        'status' => 'none',
        'entityId' => $entityId
    ];
}
// 🔍 resolveEntityIdByName
function resolveEntityIdByName(string $entityName, PDO $pdo): ?int {

    if (empty($entityName)) return null;

    $stmt = $pdo->prepare("
        SELECT entityId
        FROM tblEntities
        WHERE LOWER(entityName) = :name
        LIMIT 1
    ");

    $stmt->execute([
        'name' => strtolower(trim($entityName))
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row['entityId'] ?? null;
}
// 🏢 extractSuite — extract suite/unit from address
// 🏢 extractSuite — extract suite/unit from address
function extractSuite(string $input): ?string {
    if (preg_match('/\b(Suite|Ste|Unit|Apt|#|Suite #|Ste #)\s*([A-Za-z0-9\-]+)\b/i', $input, $m)) {
        return trim($m[1] . ' ' . $m[2]);
    }
    if (preg_match('/\b#?\s*([A-Za-z0-9\-]{1,6})\s*$/i', $input, $m)) {
        return '#' . trim($m[1]);
    }
    return null;
}
// 📞 extractPhoneExtension — extract phone extension
function extractPhoneExtension(string $input): ?string {

    if (preg_match('/\b(ext\.?|x|extension)\s*[:\-]?\s*(\d{1,6})\b/i', $input, $m)) {
        return trim($m[2]);
    }

    return null;
}

#endregion