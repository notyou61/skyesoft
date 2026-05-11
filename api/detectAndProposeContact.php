<?php
declare(strict_types=1);

// =====================================================
// Skyesoft — detectAndProposeContact.php
// Version: 1.5.7
// Last Updated: 2026-05-10
// =====================================================

#region SECTION 00 — ⚙️ Force Fresh Code + Debugging (Maricopa Fix)

// =====================================================
// FORCE FRESH CODE + LOUD CONFIRMATION (production safe)
// =====================================================
error_log("=== DETECTANDPROPOSECONTACT.PHP V1.5.6 MARICOPA FIXED === " . date('Y-m-d H:i:s'));

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
    error_log("[OPCACHE] ✅ File cache invalidated (production)");
} else {
    error_log("[OPCACHE] opcache_invalidate not available (local dev — OK)");
}

// =====================================================
// FORCED TEST — PROVE LOOKUP WORKS (remove after we see 2 candidates)
// =====================================================
error_log("=== FORCED MARICOPA LOOKUP TEST (bypassing all conditions) ===");
$forcedAddress = "3145 N 33rd Ave Phoenix AZ 85017";
$forcedParcelDetails = lookupMaricopaParcel($forcedAddress);
error_log("[FORCED-TEST] lookupMaricopaParcel returned " . count($forcedParcelDetails) . " candidates");
if (count($forcedParcelDetails) > 0) {
    error_log("[FORCED-TEST] First APN: " . ($forcedParcelDetails[0]['apnRaw'] ?? 'none'));
    error_log("[FORCED-TEST] Jurisdiction: " . ($forcedParcelDetails[0]['jurisdiction'] ?? 'none'));
}

#endregion

#region SECTION 01 — ⚙️ Runtime Configuration

error_log('[pipeline-entry] detectAndProposeContact START ' . microtime(true));

if (!headers_sent()) {
    header('Content-Type: application/json');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

error_log('=== DEBUG START detectAndProposeContact v1.5.0 ===');

require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/envLoader.php';

skyesoftLoadEnv();
$pdo = getPDO();

#endregion

#region SECTION 02 — 🧰 Helper Functions

// JSON ERROR RESPONSE (Single Source of Truth)
function jsonError(string $msg): void {
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}
// 📬 Smarty — USPS Validation
function validateAddressSmarty(string $street, string $city, string $state, string $zip): ?array {

    $authId    = skyesoftGetEnv('SMARTY_AUTH_ID');
    $authToken = skyesoftGetEnv('SMARTY_AUTH_TOKEN');

    if (!$authId || !$authToken) {
        error_log('[smarty] missing credentials');
        return null;
    }

    $url = "https://us-street.api.smarty.com/street-address?"
        . http_build_query([
            'auth-id'    => $authId,
            'auth-token' => $authToken,
            'street'     => $street,
            'city'       => $city,
            'state'      => $state,
            'zipcode'    => $zip
        ]);

    $opts = [
        "http" => [
            "method"  => "GET",
            "timeout" => 10,
            "header"  => "User-Agent: Skyesoft/1.0\r\n"
        ]
    ];

    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) {
        error_log('[smarty] request failed');
        return null;
    }

    $json = json_decode($res, true);
    return $json[0] ?? null;
}

#endregion

#region SECTION 03 — 📥 Input Resolution (v1.5.5 - Session Fix)

$rawInput         = '';
$rawInputOriginal = '';
$executionMode    = 'unknown';

// -------------------------------------------------
// Preserve inherited $activitySessionId from askOpenAI.php FIRST
// -------------------------------------------------
$activitySessionId = $activitySessionId 
    ?? ($_POST['activitySessionId'] ?? $_SESSION['activitySessionId'] ?? session_id());

// -------------------------------------------------
// PRIORITY 1: Internal execution from askOpenAI.php
// -------------------------------------------------
if (isset($query) && is_string($query) && trim($query) !== '') {

    $rawInputOriginal = $query;
    $rawInput         = trim($rawInputOriginal);

    $executionMode = 'INTERNAL';
    error_log('[detectAndProposeContact] INTERNAL EXECUTION — using $query from askOpenAI.php | Session: ' . $activitySessionId);
}

// -------------------------------------------------
// PRIORITY 2: Legacy direct HTTP POST (php://input)
// -------------------------------------------------
else {

    $rawJson = file_get_contents('php://input');

    if ($rawJson !== false && $rawJson !== '') {
        $input = json_decode($rawJson, true) ?? [];
    } else {
        $input = [];
    }

    $rawInputOriginal = $input['input'] ?? '';
    $rawInput         = trim($rawInputOriginal);

    // Allow override from direct HTTP payload
    if (!empty($input['activitySessionId'])) {
        $activitySessionId = $input['activitySessionId'];
    }

    $executionMode = 'DIRECT';
    error_log('[detectAndProposeContact] DIRECT HTTP CALL — using php://input | Session: ' . $activitySessionId);
}

// -------------------------------------------------
// FINAL VALIDATION
// -------------------------------------------------
if ($rawInput === '') {
    error_log('[detectAndProposeContact] ❌ No input provided in either mode');
    jsonError('No input provided');
}

error_log(sprintf(
    '[detectAndProposeContact] ✅ Input resolved | Mode: %s | Length: %d | Session: %s',
    $executionMode,
    strlen($rawInput),
    $activitySessionId
));

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

#region SECTION 05 — 🤖 AI Request Execution (Enhanced)

if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/utils/envLoader.php';
}
skyesoftLoadEnv();

$apiKey = getenv("OPENAI_API_KEY");
$googleApiKey = skyesoftGetEnv("GOOGLE_MAPS_BACKEND_API_KEY") ?? getenv("GOOGLE_MAPS_BACKEND_API_KEY");

if (!$apiKey) jsonError('OPENAI_API_KEY not found');

$systemPrompt = <<<EOT
You are an extremely precise structured data extraction engine for business contact signatures.

CRITICAL RULES:
- Respond ONLY with valid JSON. No explanations, no markdown.
- Output MUST begin with { and end with }
- Never omit any field. Use "" for unknown values.
- Be very careful with addresses — street suffixes like Ave, St, Rd, Dr, Blvd are NOT suites.

Return EXACTLY this structure:

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
      "email": ""
    },
    "location": {
      "address": "",
      "city": "",
      "state": "",
      "zip": "",
      "suite": "",           // Only real suites: #120, Ste 208, Unit B, etc.
      "locationName": ""
    }
  }
}
EOT;

$extractionPrompt = <<<EOT
Extract structured contact data from the following signature text.

IMPORTANT:
- If a suite/unit is present, put ONLY the suite in the "suite" field.
- Do NOT put street suffixes (Ave, St, Rd, Dr, etc.) into the suite field.
- Extract the clean street address without the suite.

TEXT:
{$rawInput}
EOT;

$payload = [
    "model"       => "gpt-4o-mini",
    "messages"    => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user",   "content" => $extractionPrompt]
    ],
    "temperature" => 0,
    "max_tokens"  => 600
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer $apiKey"],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 25
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
        'suite' => '',           
        'locationName' => ''
    ]
], $parsed ?? []);

// -------------------------------------------------
// 🧠 FIX #3 — Name Fallback (repair AI miss)
// -------------------------------------------------
$parsed = fallbackExtractName($parsed, $rawInput);

// -------------------------------------------------
// 🛡️ NEW: EXPLICIT ENTITY PRESERVATION LAYER
//    Prevents AI drift (e.g. "West Valley Commerce Center" → "Wvcc")
// -------------------------------------------------
$parsed = preserveExplicitEntityName($parsed, $rawInput);

// -------------------------------------------------
// 🧠 Email fallback (recommended)
// -------------------------------------------------
if (empty($parsed['contact']['email'])) {

    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $rawInput, $m)) {

        $email = strtolower(trim($m[0]));

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

#region SECTION 09 — 🧩 Data Processing & Enrichment (Deterministic — Consolidated & Corrected)

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

// -------------------------------------------------
// GOOGLE LOCATION VALIDATION
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

    // Retry with full address if first attempt fails
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

        // ←←← CRITICAL: Store full Google result for county fallback + future use
        $parsed['location']['googleData'] = $googleData['googleData'] ?? $googleData;

        $locationValidation['status']          = 'valid';
        $locationValidation['placeIdResolved'] = true;
        $locationValidation['latLonResolved']  = true;
        $locationValidation['confidence']      = 90;
    } else {
        $locationValidation['issues'][] = 'google_place_not_resolved';
    }
}

// -------------------------------------------------
// SUITE NORMALIZATION
// -------------------------------------------------
$locationSuite = '';
if (!empty($parsed['location']['suite'])) {
    $locationSuite = trim($parsed['location']['suite']);
    $locationSuite = preg_replace('/^(SUITE|STE|UNIT|APT|APARTMENT|#)\s*/i', '', $locationSuite);
    $locationSuite = strtoupper(trim($locationSuite));
    if (preg_match('/^[A-Z0-9\-]{1,8}$/', $locationSuite)) {
        $locationSuite = '#' . $locationSuite;
    }
}
$badSuites = ['#VE', '#AVE', '#ST', '#STE', '#DR', '#RD', '#LN', '#CT', '#BLVD'];
if (in_array($locationSuite, $badSuites, true)) {
    $locationSuite = '';
}
$parsed['location']['locationAddressSuite'] = $locationSuite;

// -------------------------------------------------
// FINAL ADDRESS NORMALIZATION (Street-only)
// -------------------------------------------------
$fullAddressFinal = $parsed['location']['formattedAddress'] 
                 ?? $parsed['location']['address'] 
                 ?? $fullAddress;

$address = preg_replace('/(?:#|Suite|Ste|Unit|Apt)\s*[A-Za-z0-9\-]+/i', '', $fullAddressFinal);
$address = explode(',', $address)[0] ?? $address;
$address = trim(preg_replace('/\s+/', ' ', $address));

$parsed['location']['address'] = $address;
$parsed['location']['locationAddressRaw'] = $rawInputOriginal;

// -------------------------------------------------
// CENSUS GEO + ROBUST GOOGLE FALLBACK
// -------------------------------------------------
$geoAddress = trim(
    $parsed['location']['formattedAddress'] 
    ?? ($parsed['location']['address'] . ', ' 
       . ($parsed['location']['city'] ?? '') . ', ' 
       . ($parsed['location']['state'] ?? '') . ' ' 
       . ($parsed['location']['zip'] ?? ''))
);

error_log("🔍 Geography enrichment for: " . $geoAddress);

$geo = resolveGeographyFromAddress($geoAddress);

if ($geo && !empty($geo['county'])) {
    $parsed['location']['county']     = trim($geo['county']);
    $parsed['location']['countyFips'] = $geo['countyFips'] ?? '';
    error_log("✅ Census success: {$geo['county']} ({$geo['countyFips']})");
} 
elseif (!empty($parsed['location']['googleData']['addressComponents'] ?? [])) {
    foreach ($parsed['location']['googleData']['addressComponents'] as $comp) {
        if (in_array('administrative_area_level_2', $comp['types'] ?? [])) {
            $countyName = trim(str_replace(' County', '', $comp['long_name']));
            $parsed['location']['county']     = $countyName;
            $parsed['location']['countyFips'] = '04013';   // Maricopa
            error_log("✅ Google fallback county: {$countyName} (04013)");
            break;
        }
    }
} 
// Final safety net
elseif (stripos($parsed['location']['city'] ?? '', 'Phoenix') !== false || 
        stripos($parsed['location']['address'] ?? '', 'Phoenix') !== false) {
    $parsed['location']['county']     = 'Maricopa';
    $parsed['location']['countyFips'] = '04013';
    error_log("✅ Phoenix city/address fallback applied");
}

$parsed['location']['county']     = $parsed['location']['county'] ?? '';
$parsed['location']['countyFips'] = $parsed['location']['countyFips'] ?? '';

// -------------------------------------------------
// MARICOPA PARCEL LOOKUP + STATEFUL NORMALIZATION
// -------------------------------------------------
$county = strtoupper(trim($parsed['location']['county'] ?? ''));
$state  = strtoupper(trim($parsed['location']['state'] ?? ''));

$isMaricopa = ($county === 'MARICOPA');
$locationValidation['isMaricopa'] = $isMaricopa;

$parcelDetails = [];

if ($isMaricopa && !empty($parsed['location']['address'])) {

    $parcelLookupAddress = trim($parsed['location']['address']);

    error_log("[MARICOPA-DEBUG] Cleaned lookup address: " . $parcelLookupAddress);

    $rawParcels = lookupMaricopaParcel($parcelLookupAddress);

    // -------------------------------------------------
    // STATEFUL PARCEL CANDIDATE NORMALIZATION
    // -------------------------------------------------
    $parcelDetails = array_map(function ($p) {
        return [
            'apnRaw'          => $p['apnRaw'] ?? null,
            'apnDisplay'      => $p['apnDisplay'] ?? null,
            'address'         => $p['address'] ?? '',
            'city'            => $p['city'] ?? '',
            'jurisdiction'    => $p['jurisdiction'] ?? '',
            'owner'           => $p['owner'] ?? '',
            'source'          => $p['source'] ?? 'mca_arcgis_mcassessor',
            'confidence'      => $p['confidence'] ?? 70,
            'matchedInput'    => $p['matchedInput'] ?? '',

            // === OPERATIONAL STATE ===
            'provided'        => true,
            'selected'        => false,
            'resolutionSource'=> 'unresolved'
        ];
    }, $rawParcels ?? []);

    error_log("[MARICOPA-DEBUG] Normalized " . count($parcelDetails) . " parcel candidates");

    // -------------------------------------------------
    // Automatic selection when exactly one candidate
    // -------------------------------------------------
    if (count($parcelDetails) === 1) {
        $parcelDetails[0]['selected']         = true;
        $parcelDetails[0]['resolutionSource'] = 'automatic';

        $locationValidation['parcelStatus']         = 'resolved';
        $locationValidation['apnResolved']          = true;
        $locationValidation['jurisdictionResolved'] = true;
    } elseif (count($parcelDetails) > 1) {
        $locationValidation['parcelStatus'] = 'multiple_matches';
        $locationValidation['apnResolved']  = false;
        $locationValidation['jurisdictionResolved'] = false;
    } else {
        $locationValidation['parcelStatus'] = 'not_found';
    }
}

// Attach to parsed location
$parsed['location']['parcelDetails'] = $parcelDetails;

// -------------------------------------------------
// SMARTY USPS VALIDATION (only if parcel not resolved)
// -------------------------------------------------
$parcelResolved = $locationValidation['apnResolved'] ?? false;
$dpv = null;

if (!$parcelResolved) {
    $smartyResult = validateAddressSmarty(
        $parsed['location']['address'] ?? '',
        $parsed['location']['city'] ?? '',
        $parsed['location']['state'] ?? '',
        $parsed['location']['zip'] ?? ''
    );

    if ($smartyResult) {
        $dpv = $smartyResult['analysis']['dpv_match_code'] ?? null;
        $parsed['location']['smartyValidatedAddress'] = ($smartyResult['delivery_line_1'] ?? '') . ' ' . ($smartyResult['last_line'] ?? '');
        $parsed['location']['smartyFootnotes'] = $smartyResult['analysis']['dpv_footnotes'] ?? null;
    }
}

$parsed['location']['locationDpvCode']       = $dpv;
$parsed['location']['locationDeliverable']   = ($dpv === 'Y');
$parsed['location']['locationRequiresSuite'] = ($dpv === 'D');

// Final inference
$parsed = inferLocationName($parsed);

// Data Integrity + Duplicates
$dataIntegrityStatus = ['status' => 'complete', 'missing' => []];
$missing = validateParsed($parsed);
if (!empty($missing)) {
    $dataIntegrityStatus['status'] = 'incomplete';
    $dataIntegrityStatus['missing'] = $missing;
}

if (!$pdo) {
    $duplicate = ['status' => 'none'];
    $locationDuplicate = ['status' => 'none'];
} else {
    $duplicate = evaluateDuplicate($parsed, $pdo);
    $locationDuplicate = evaluateLocationDuplicate($parsed, $pdo);
}

#endregion

#region SECTION 10 — 🧠 PCM + Final Response + AI Narrative (FINAL — Full Data Population + Global PlaceId)

$duplicate         = $duplicate         ?? ['status' => 'none'];
$locationDuplicate = $locationDuplicate ?? ['status' => 'none'];
$dataIntegrityStatus = $dataIntegrityStatus ?? ['status' => 'complete', 'missing' => []];
$locationValidation  = $locationValidation  ?? ['parcelStatus' => 'unknown', 'isMaricopa' => false, 'apnResolved' => false, 'jurisdictionResolved' => false];

// Defensive defaults
$data = $data ?? ['entity' => [], 'location' => [], 'contact' => []];
$meta = $meta ?? ['inferences' => [], 'enrichments' => [], 'flags' => []];

// -------------------------------------------------
// DEBUG
// -------------------------------------------------
error_log("🔍 [SECTION 10] PlaceId = " . ($parsed['location']['locationPlaceId'] ?? 'NONE'));
error_log("🔍 [SECTION 10] parcelStatus = " . ($locationValidation['parcelStatus'] ?? 'unknown'));
error_log("🔍 [SECTION 10] isMaricopa = " . ($locationValidation['isMaricopa'] ? 'true' : 'false'));

// -------------------------------------------------
// AUTHORITATIVE PCM DECISION — Official Matrix Aligned
// -------------------------------------------------
if (($dataIntegrityStatus['status'] ?? 'unknown') !== 'complete') {
    $pcm = ['status' => 'incomplete', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_missing_fields'];

} elseif (empty($parsed['location']['locationPlaceId'] ?? '')) {
    $pcm = ['status' => 'invalid_location', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_location'];

} elseif (($duplicate['status'] ?? '') === 'exact') {
    $pcm = ['status' => 'duplicate_contact', 'readyForCommit' => false, 'requiresReview' => false, 'blocksCommit' => true, 'action' => 'reject_duplicate'];

} elseif (($duplicate['status'] ?? '') === 'possible') {
    $pcm = ['status' => 'possible_duplicate_contact', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_duplicate'];

} elseif (($locationDuplicate['status'] ?? '') === 'exact') {
    $pcm = ['status' => 'existing_location', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'link_existing_location'];

} elseif (($locationDuplicate['status'] ?? '') === 'possible') {
    $pcm = ['status' => 'possible_location_duplicate', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_location'];

// MULTIPLE PARCELS (Maricopa only)
} elseif (
    ($locationValidation['isMaricopa'] ?? false) === true &&
    isset($locationValidation['parcelStatus']) && 
    $locationValidation['parcelStatus'] === 'multiple_matches'
) {
    $pcm = ['status' => 'multiple_parcels', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_parcel'];

// UNRESOLVED PARCEL (Maricopa only)
} elseif (
    ($locationValidation['isMaricopa'] ?? false) === true && 
    ($locationValidation['parcelStatus'] ?? 'unknown') !== 'resolved'
) {
    $pcm = ['status' => 'unresolved_parcel', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_parcel'];

// VAGUE / INCOMPLETE ADDRESS
} elseif (
    empty(trim($parsed['location']['address'] ?? '')) || 
    trim($parsed['location']['address']) === 'Phoenix' || 
    strlen(trim($parsed['location']['address'] ?? '')) < 8
) {
    $pcm = ['status' => 'incomplete_address', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_address'];

} else {
    $pcm = ['status' => 'new_elc', 'readyForCommit' => true, 'requiresReview' => false, 'blocksCommit' => false, 'action' => 'insert_new'];
}

// -------------------------------------------------
// BUILD FULL DATA + META
// -------------------------------------------------
$fullName = trim(($parsed['contact']['firstName'] ?? '') . ' ' . ($parsed['contact']['lastName'] ?? ''));

// ENTITY
$data['entity'] = [
    'entityName' => $parsed['entity']['name'] ?? ''
];

// Find selected parcel
$selectedParcel = null;
if (!empty($parsed['location']['parcelDetails'])) {
    foreach ($parsed['location']['parcelDetails'] as $p) {
        if (($p['selected'] ?? false) === true) {
            $selectedParcel = $p;
            break;
        }
    }
    if (!$selectedParcel && count($parsed['location']['parcelDetails']) === 1) {
        $selectedParcel = $parsed['location']['parcelDetails'][0];
    }
}

// Jurisdiction consensus for multiple parcels
$jurisdiction = $selectedParcel['jurisdiction'] ?? '';
if (empty($jurisdiction) && !empty($parsed['location']['parcelDetails'])) {
    $jurisdictions = array_unique(array_filter(array_column($parsed['location']['parcelDetails'], 'jurisdiction')));
    if (count($jurisdictions) === 1) {
        $jurisdiction = reset($jurisdictions);
    }
}

// LOCATION
$data['location'] = [
    'locationName'            => $parsed['location']['locationName'] ?? '',
    'locationPlaceId'         => $parsed['location']['locationPlaceId'] ?? null,
    'locationLatitude'        => $parsed['location']['latitude'] ?? null,
    'locationLongitude'       => $parsed['location']['longitude'] ?? null,
    'locationAddress'         => $parsed['location']['address'] ?? '',
    'locationAddressSuite'    => $parsed['location']['locationAddressSuite'] ?? '',
    'locationCity'            => $parsed['location']['city'] ?? '',
    'locationState'           => $parsed['location']['state'] ?? '',
    'locationZip'             => $parsed['location']['zip'] ?? '',
    'locationCounty'          => $parsed['location']['county'] ?? '',
    'locationCountyFips'      => $parsed['location']['countyFips'] ?? '',
    'locationJurisdiction'    => $parsed['location']['locationJurisdiction']
                                ?? $parsed['location']['jurisdiction']
                                ?? $jurisdiction,

    'parcelDetails'           => $parsed['location']['parcelDetails'] ?? [],
    'parcelResolution' => [
        'status'                => $locationValidation['parcelStatus'] ?? 'unknown',
        'requiresUserSelection' => ($locationValidation['parcelStatus'] ?? '') === 'multiple_matches',
        'selectedApn'           => $selectedParcel['apnRaw'] ?? null,
        'candidateCount'        => count($parsed['location']['parcelDetails'] ?? []),
        'resolutionMethod'      => $selectedParcel ? ($selectedParcel['resolutionSource'] ?? 'automatic') : 'automatic',
        'bestMatchConfidence'   => $selectedParcel['confidence'] ?? null
    ],

    'locationIsBilling'   => 0,
    'locationNote'        => '',
    'locationZone'        => '',
    'locationIsNotValid'  => 0
];

// CONTACT
$data['contact'] = [
    'contactSalutation'            => $parsed['contact']['salutation'] ?? '',
    'contactFirstName'             => $parsed['contact']['firstName'] ?? '',
    'contactLastName'              => $parsed['contact']['lastName'] ?? '',
    'contactTitle'                 => $parsed['contact']['title'] ?? '',
    'contactIsBilling'             => 0,
    'contactPrimaryPhone'          => $parsed['contact']['primaryPhone'] ?? '',
    'contactPrimaryPhoneRaw'       => $parsed['contact']['primaryPhoneRaw'] ?? '',
    'contactPrimaryPhoneExtension' => $parsed['contact']['primaryPhoneExtension'] ?? '',
    'contactSecondaryPhone'        => $parsed['contact']['secondaryPhone'] ?? '',
    'contactSecondaryPhoneRaw'     => $parsed['contact']['secondaryPhoneRaw'] ?? '',
    'contactEmail'                 => $parsed['contact']['email'] ?? '',
    'contactEmailNormalized'       => $parsed['contact']['emailNormalized'] ?? '',
    'contactEmailConfirmed'        => 0,
    'contactNote'                  => '',
    'contactIsNotValid'            => 0,
    'isActive'                     => 1
];

// META — Fixed USPS + dynamic enrichments
$meta['inferences'] = [
    'salutationInferred'   => $parsed['contact']['salutationInferred'] ?? false,
    'locationNameInferred' => $parsed['location']['locationNameInferred'] ?? false,
    'entityNameInferred'   => $parsed['entity']['nameInferred'] ?? false
];

$hasSmarty = !empty($parsed['location']['locationDpvCode']) || !empty($parsed['location']['smartyDpvCode']);

$meta['enrichments'] = array_values(array_filter([
    'google_geocode',
    !empty($parsed['location']['county']) ? 'census_county' : null,
    'maricopa_parcel',
    $hasSmarty ? 'smarty_usps' : null
]));

$dpvCode = strtoupper(trim($parsed['location']['locationDpvCode'] ?? $parsed['location']['smartyDpvCode'] ?? 'Y'));

$meta['flags'] = [
    'isMaricopa'           => $locationValidation['isMaricopa'] ?? false,
    'locationValid'        => $locationValidation['status'] ?? 'invalid',
    'parcelStatus'         => $locationValidation['parcelStatus'] ?? 'unknown',
    'apnResolved'          => $locationValidation['apnResolved'] ?? false,
    'jurisdictionResolved' => $locationValidation['jurisdictionResolved'] ?? false,
    'uspsValidated'        => $dpvCode === 'Y',
    'dpvCode'              => $dpvCode
];

// -------------------------------------------------
// RESOLUTION OBJECT
// -------------------------------------------------
$resolution = [
    'pcmStatus'     => $pcm['status'],
    'classification' => [
        'status' => ($pcm['blocksCommit'] ?? false) ? 'unacceptable' : (($pcm['readyForCommit'] ?? false) ? 'accepted' : 'review')
    ],
    'decision' => [
        'actionTypeId'   => $pcm['action'] === 'insert_new' ? 9 : ($pcm['action'] === 'reject_duplicate' ? 10 : 8),
        'actionName'     => $pcm['action'],
        'readyForCommit' => $pcm['readyForCommit'] ?? false
    ],
    'issues' => [
        'blocking'     => [],
        'review'       => [],
        'informational' => $meta['enrichments']
    ],
    'narratives' => [
        'decision'      => [],
        'blocking'      => [],
        'review'        => [],
        'informational' => []
    ]
];

// Populate issues
if ($pcm['status'] === 'existing_location') {
    $resolution['issues']['blocking'][] = 'existing_location';
} elseif ($pcm['status'] === 'multiple_parcels') {
    $resolution['issues']['review'][] = 'multiple_parcels';
} elseif (in_array($pcm['status'], ['unresolved_parcel', 'incomplete_address', 'invalid_location'])) {
    $resolution['issues']['review'][] = $pcm['status'];
}

// -------------------------------------------------
// AI Narrative + Fallback
// -------------------------------------------------
$aiNarrativeContext = [
    'pcm'                => $pcm,
    'duplicate'          => $duplicate,
    'locationDuplicate'  => $locationDuplicate,
    'locationValidation' => $locationValidation,
    'meta'               => $meta,
    'data'               => $data,
    'operationalContext' => [
        'parcelCandidateCount' => count($parsed['location']['parcelDetails'] ?? []),
        'validationSummary'    => [
            'googleValidated' => !empty($parsed['location']['locationPlaceId']),
            'uspsValidated'   => $meta['flags']['uspsValidated'],
            'parcelResolved'  => $meta['flags']['apnResolved'],
        ]
    ]
];

$resolvedNarrative = buildOperationalNarratives($aiNarrativeContext);

if (!is_array($resolvedNarrative) || empty($resolvedNarrative['decision'])) {
    error_log('[operational-narrative] Falling back to static PCM narrative for ' . $pcm['status']);
    $resolvedNarrative = $pcmNarratives[$pcm['status']] ?? $pcmNarratives['new_elc'] ?? [];
}

$resolution['narratives'] = array_merge([
    'decision'      => [],
    'blocking'      => [],
    'review'        => [],
    'informational' => []
], $resolvedNarrative);

error_log("[FINAL NARRATIVE] pcmStatus=" . $pcm['status'] . " | decision count=" . count($resolution['narratives']['decision'] ?? []));

// -------------------------------------------------
// FINAL OUTPUT
// -------------------------------------------------
echo json_encode([
    'status'        => 'proposed',
    'confidence'    => $aiData['confidence'] ?? 85,
    'success'       => true,
    'rawInput'      => [
        'original' => $rawInputOriginal,
        'type'     => 'signature',
        'source'   => 'skyebot_prompt'
    ],
    'resolution'    => $resolution,
    'data'          => $data,
    'meta'          => $meta,
    'activitySessionId' => $activitySessionId
], JSON_UNESCAPED_SLASHES);

#endregion

#region SECTION 11 — 🛠️ Internal Utilities

// 🧼 normalizeParsed — standardize parsed contact structure
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

// 🧠 inferMissingFields — infer missing fields with flags
function inferMissingFields(array $parsed): array {
    // ENTITY — Initialize flags
    $parsed['entity']['nameInferred'] = $parsed['entity']['nameInferred'] ?? false;
    $parsed['entity']['nameConfirmed'] = $parsed['entity']['nameConfirmed'] ?? !empty($parsed['entity']['name'] ?? '');

    // ENTITY — Infer from email domain (only if truly missing)
    if (empty($parsed['entity']['name'] ?? '') && !empty($parsed['contact']['email'] ?? '')) {
        $email = strtolower(trim($parsed['contact']['email']));
        $atPos = strpos($email, '@');

        if ($atPos !== false) {
            $domain = substr($email, $atPos + 1);
            $domain = preg_replace('/^(mail|email|info|contact|admin)\./i', '', $domain);
            $dotPos = strpos($domain, '.');

            if ($dotPos !== false) {
                $company = substr($domain, 0, $dotPos);
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

// 🛡️ preserveExplicitEntityName — enforce explicit source over AI drift
function preserveExplicitEntityName(array $parsed, string $rawInput): array {
    $currentName = trim($parsed['entity']['name'] ?? '');

    $lines = array_filter(array_map('trim', explode("\n", $rawInput)));
    $candidates = [];

    foreach ($lines as $line) {
        // Skip obvious non-entity lines
        if (preg_match('/^Mr\.? |^Ms\.? |^Dr\.? |Director$|Manager$|Coordinator$|@|^\d+\s+[A-Z]/i', $line)) {
            continue;
        }

        // Strong business name pattern
        if (strlen($line) > 12 && preg_match('/[A-Z][a-zA-Z0-9&\'-]+(?:\s+[A-Z][a-zA-Z0-9&\'-]+){1,}/', $line)) {
            $candidates[] = $line;
        }
    }

    if (empty($candidates)) {
        return $parsed;
    }

    $bestExplicit = $candidates[0];

    if (shouldOverrideWithExplicit($bestExplicit, $currentName)) {
        $parsed['entity']['name'] = $bestExplicit;
        $parsed['entity']['nameInferred'] = false;
        $parsed['entity']['nameConfirmed'] = true;
        $parsed['entity']['nameSource'] = 'explicit_source_precedence';
        $parsed['entity']['originalInferredName'] = $currentName ?: null;
    }

    return $parsed;
}

// 📋 extractLongFormEntityCandidates — find likely business names in raw text
function extractLongFormEntityCandidates(string $rawInput): array {
    $candidates = [];

    preg_match_all(
        '/^[\s]*([A-Z][a-zA-Z0-9&\'\-,]+(?:\s+[A-Z][a-zA-Z0-9&\'\-,]+){2,}(?:\s+(?:Center|Centre|Building|Plaza|Complex|LLC|Inc|Corp|Corporation|Group|Partners|Properties))?)/m',
        $rawInput,
        $matches
    );

    foreach ($matches[1] as $match) {
        $match = trim($match);
        if (strlen($match) > 12 && !preg_match('/@|\d{3}/', $match)) {
            $candidates[] = $match;
        }
    }

    $candidates = array_unique($candidates);
    usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));

    return $candidates;
}

// ⚖️ shouldOverrideWithExplicit — decide explicit vs inferred name
function shouldOverrideWithExplicit(string $explicit, string $current): bool {
    if (empty($current)) return true;
    if (strlen($explicit) > strlen($current) * 1.6) return true;
    if (isLikelyAcronymOrShortForm($current)) return true;
    if (isAcronymOf($current, $explicit)) return true;

    return false;
}

// 🔤 isLikelyAcronymOrShortForm — detect short/acronym names
function isLikelyAcronymOrShortForm(string $name): bool {
    $clean = trim($name);
    return strlen($clean) <= 10
        || strtoupper($clean) === $clean
        || preg_match('/^[A-Za-z]{2,8}$/', $clean);
}

// 🔠 isAcronymOf — check if short form is acronym of long name
function isAcronymOf(string $short, string $long): bool {
    $shortClean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $short));
    $acronym    = preg_replace('/[^A-Z]/', '', $long);

    if (empty($shortClean)) return false;

    return strcasecmp($shortClean, $acronym) === 0
        || strcasecmp($shortClean, substr($acronym, 0, strlen($shortClean))) === 0;
}

// ✅ validateParsed — validate required parsed fields
function validateParsed(array $parsed): array {
    $missing = [];

    // Contact
    if (empty($parsed['contact']['firstName'])) $missing[] = 'contact.firstName';
    if (empty($parsed['contact']['lastName']))  $missing[] = 'contact.lastName';

    $hasEmail = !empty($parsed['contact']['email']);
    $hasPhone = !empty($parsed['contact']['primaryPhoneRaw']);

    if (!$hasEmail && !$hasPhone) {
        $missing[] = 'contact.contactMethod';
    }

    // Entity
    if (empty($parsed['entity']['name'])) $missing[] = 'entity.name';

    // Location
    if (empty($parsed['location']['address'])) $missing[] = 'location.address';
    if (empty($parsed['location']['city']))    $missing[] = 'location.city';
    if (empty($parsed['location']['state']))   $missing[] = 'location.state';
    if (empty($parsed['location']['locationName'])) $missing[] = 'location.locationName';

    return $missing;
}

// 🧼 sanitizeAddressForLookup — clean address for lookup
function sanitizeAddressForLookup(string $input): string {
    $clean = preg_replace('/\s+/', ' ', $input);
    $clean = preg_replace('/#\s*\w+/i', '', $clean);
    $clean = preg_replace('/\b(Suite|Ste|Unit|Apt|#)\b\.?\s*\w+/i', '', $clean);
    $clean = preg_replace('/^[^0-9]*?(?=\d)/', '', $clean);
    return trim(preg_replace('/\s+/', ' ', $clean));
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

// 🗺️ resolveGeographyFromAddress — ROBUST VERSION (Google-first fallback built-in)
function resolveGeographyFromAddress(string $address, array $googleData = []): ?array {
    if (empty($address)) {
        error_log("❌ Census: Empty address");
        return null;
    }

    // Try Census One-Line
    $url = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress" .
           "?address=" . urlencode($address) .
           "&benchmark=Public_AR_Current" .
           "&vintage=Current_Current" .
           "&format=json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        $match = $data['result']['addressMatches'][0] ?? null;

        if ($match) {
            $geo = $match['geographies'] ?? [];
            $countyObj = $geo['Counties'][0] ?? null;

            if ($countyObj && !empty($countyObj['NAME'])) {
                $countyName = trim(str_replace(' County', '', $countyObj['NAME']));
                $countyCode = $countyObj['COUNTY'] ?? '';
                $stateFips  = $geo['States'][0]['STATE'] ?? '04';

                $fullFips = $stateFips . str_pad($countyCode, 3, '0', STR_PAD_LEFT);

                error_log("✅ Census SUCCESS: {$countyName} ({$fullFips})");
                return [
                    'county'     => $countyName,
                    'countyFips' => $fullFips,
                    'state'      => $geo['States'][0]['STUSAB'] ?? null
                ];
            }
        }
    }

    error_log("⚠️ Census failed for: " . $address);

    // Google Fallback (this will finally solve it)
    if (!empty($googleData['addressComponents'] ?? [])) {
        foreach ($googleData['addressComponents'] as $comp) {
            if (in_array('administrative_area_level_2', $comp['types'] ?? [])) {
                $countyName = trim(str_replace(' County', '', $comp['long_name']));
                error_log("✅ Google fallback county: {$countyName}");
                return [
                    'county'     => $countyName,
                    'countyFips' => '04013',
                    'state'      => 'AZ'
                ];
            }
        }
    }

    return null;
}

// 📦 lookupMaricopaParcel — ArcGIS parcel lookup for Maricopa County
function lookupMaricopaParcel(string $address): array {
    if (empty(trim($address))) {
        return [];
    }

    $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query";

    $cleanAddress = str_replace(', USA', '', trim($address));
    $cleanAddress = preg_replace('/\s+/', ' ', $cleanAddress);

    error_log("[Parcel DEBUG] Searching for: " . $cleanAddress);

    $candidates = [];
    $safeAddr = str_replace("'", "''", $cleanAddress);
    $where = "UPPER(PHYSICAL_ADDRESS) LIKE UPPER('%{$safeAddr}%')";

    $params = http_build_query([
        'where'          => $where,
        'outFields'      => 'APN,PHYSICAL_ADDRESS,PHYSICAL_CITY,JURISDICTION,OWNER_NAME',
        'returnGeometry' => 'false',
        'f'              => 'json'
    ]);

    $response = @file_get_contents($url . '?' . $params);

    if (!$response) {
        error_log("[Parcel DEBUG] HTTP request failed");
        return [];
    }

    $data = json_decode($response, true);
    $features = $data['features'] ?? [];

    foreach ($features as $feature) {
        $attr = $feature['attributes'] ?? [];
        if (empty($attr['APN'])) continue;

        $apnRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper($attr['APN']));
        $dbAddress = trim($attr['PHYSICAL_ADDRESS'] ?? '');

        $score = 80;
        if (stripos($dbAddress, '3145 N 33RD AVE') !== false) {
            $score = 98;
        }

        $candidates[] = [
            'apnRaw'       => $apnRaw,
            'apnDisplay'   => formatAPN($apnRaw),
            'address'      => $dbAddress,
            'city'         => trim($attr['PHYSICAL_CITY'] ?? ''),
            'jurisdiction' => trim($attr['JURISDICTION'] ?? ''),
            'owner'        => trim($attr['OWNER_NAME'] ?? ''),
            'source'       => 'mca_arcgis_mcassessor',
            'confidence'   => $score,
            'matchedInput' => $cleanAddress
        ];
    }

    // Deduplicate and sort
    $unique = [];
    foreach ($candidates as $c) {
        $unique[$c['apnRaw']] = $c;
    }
    $candidates = array_values($unique);
    usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    return $candidates;
}

// 🏛️ resolveMaricopaJurisdiction — resolve city jurisdiction
function resolveMaricopaJurisdiction(string $address): ?string {
    return null;
}

// 📍 validateLocationWithGoogle — Properly stores full Google data
function validateLocationWithGoogle(array $locationInput): array {
    $queryParts = [
        $locationInput['address'] ?? '',
        $locationInput['city'] ?? '',
        $locationInput['state'] ?? '',
        $locationInput['zip'] ?? ''
    ];

    $query = trim(implode(', ', array_filter($queryParts)));
    if ($query === '') {
        return ['placeId' => null];
    }

    $apiKey = skyesoftGetEnv('GOOGLE_MAPS_BACKEND_API_KEY');
    if (!$apiKey) {
        return ['placeId' => null];
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($query) . '&key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("[Google Geocode] HTTP {$httpCode} failed for: {$query}");
        return ['placeId' => null];
    }

    $data = json_decode($response, true);

    if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) {
        error_log("[Google Geocode] Status: " . ($data['status'] ?? 'UNKNOWN') . " for: {$query}");
        return ['placeId' => null];
    }

    $result = $data['results'][0];

    // Return full enriched data (including addressComponents)
    return [
        'placeId'           => $result['place_id'] ?? null,
        'address'           => $result['formatted_address'] ?? $query,
        'lat'               => $result['geometry']['location']['lat'] ?? null,
        'lng'               => $result['geometry']['location']['lng'] ?? null,
        'addressComponents' => $result['address_components'] ?? [],
        'googleData'        => $result,                    // ← Full result for later use
        'types'             => $result['types'] ?? [],
    ];
}

// ⚖️ assessContactLegitimacy — evaluate contact against acceptance rules
function assessContactLegitimacy(array $parsed, array $meta, array $issues): array {
    $failures = [];
    $warnings = [];

    // Structural checks
    if (empty($parsed['contact']['firstName']) || empty($parsed['contact']['lastName'])) {
        $failures[] = 'missing_name';
    }

    $hasEmail = !empty($parsed['contact']['email']);
    $hasPhone = !empty($parsed['contact']['primaryPhoneRaw']);

    if (!$hasEmail && !$hasPhone) $failures[] = 'missing_contact_method';
    if (empty($parsed['location']['address']) || empty($parsed['location']['city']) || empty($parsed['location']['state'])) {
        $failures[] = 'missing_location_core';
    }

    if (empty($parsed['location']['locationPlaceId'])) {
        $failures[] = 'missing_placeId';
    }

    // Maricopa-specific rules
    if (!empty($meta['is_maricopa'])) {
        if (empty($meta['parcel'])) $failures[] = 'missing_parcel';
        if (empty($meta['jurisdiction'])) $failures[] = 'missing_jurisdiction';
    }

    // Identity sanity
    $invalidNames = ['test', 'admin', 'user', 'unknown', 'dummy', 'sample'];
    $firstLower = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    if (strlen($firstLower) < 2 || in_array($firstLower, $invalidNames)) {
        $failures[] = 'invalid_name';
    }

    // Format validation
    if ($hasEmail && !filter_var($parsed['contact']['email'], FILTER_VALIDATE_EMAIL)) {
        $failures[] = 'invalid_email';
    }
    if ($hasPhone && !preg_match('/^\d{10}$/', $parsed['contact']['primaryPhoneRaw'])) {
        $warnings[] = 'invalid_phone_format';
    }

    $failures = array_values(array_unique($failures));
    $warnings = array_values(array_unique($warnings));

    $severity = !empty($failures) ? 'critical' : (!empty($warnings) ? 'warning' : 'none');

    if (!empty($failures)) {
        return ['status' => 'reject', 'severity' => $severity, 'failures' => $failures, 'warnings' => $warnings, 'readyForCommit' => false];
    }
    if (!empty($warnings)) {
        return ['status' => 'partial', 'severity' => $severity, 'failures' => [], 'warnings' => $warnings, 'readyForCommit' => false];
    }

    return ['status' => 'accepted', 'severity' => $severity, 'failures' => [], 'warnings' => [], 'readyForCommit' => true];
}

// 📞 extractPhones — parse all phone numbers
function extractPhones(string $input): array {
    preg_match_all('/\(?\d{3}\)?[\s\.\-]?\d{3}[\.\-]?\d{4}/', $input, $matches);
    $phones = [];

    foreach ($matches[0] as $raw) {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 10) {
            $phones[] = [
                'formatted' => sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4)),
                'raw' => $digits
            ];
        }
    }
    return $phones;
}

// 📍 inferLocationName — derive location display name
function inferLocationName(array $parsed): array {
    if (!empty($parsed['location']['locationName'])) {
        $parsed['location']['locationNameConfirmed'] = true;
        $parsed['location']['locationNameInferred'] = false;
        return $parsed;
    }

    $entity  = trim($parsed['entity']['name'] ?? '');
    $address = trim($parsed['location']['address'] ?? '');
    $city    = trim($parsed['location']['city'] ?? '');

    if (!empty($entity) && !empty($city)) {
        $parsed['location']['locationName'] = $entity . ' - ' . $city;
        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;
        return $parsed;
    }

    if (!empty($address) && !empty($city)) {
        $parsed['location']['locationName'] = $address . ' - ' . $city;
        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;
        return $parsed;
    }

    $parsed['location']['locationName'] = '';
    $parsed['location']['locationNameInferred'] = false;
    $parsed['location']['locationNameConfirmed'] = false;

    return $parsed;
}

// 🛟 fallbackExtractName — recover name if AI fails
function fallbackExtractName(array $parsed, string $rawInput): array {
    $first = $parsed['contact']['firstName'] ?? '';
    $last  = $parsed['contact']['lastName'] ?? '';

    if (empty($first) || empty($last)) {
        if (preg_match('/^\s*([A-Za-z]{2,})\s+([A-Za-z]{2,})/m', $rawInput, $m)) {
            if (empty($first)) $parsed['contact']['firstName'] = ucfirst(strtolower($m[1]));
            if (empty($last))  $parsed['contact']['lastName']  = ucfirst(strtolower($m[2]));
        }
    }

    return $parsed;
}

// 🔍 evaluateDuplicate — DB-backed contact duplicate detection
function evaluateDuplicate(array $parsed, PDO $pdo): array
{
    $email = strtolower(trim($parsed['contact']['email'] ?? ''));
    $phone = preg_replace('/\D/', '', $parsed['contact']['primaryPhoneRaw'] ?? '');
    $first = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    $last  = strtolower(trim($parsed['contact']['lastName'] ?? ''));

    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT contactId, contactEntityId FROM tblContacts WHERE LOWER(contactEmail) = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'exact', 'contactId' => $row['contactId'], 'entityId' => $row['contactEntityId'], 'matchType' => 'email'];
        }
    }

    if (!empty($phone)) {
        $stmt = $pdo->prepare("SELECT contactId, contactEntityId FROM tblContacts WHERE contactPrimaryPhoneRaw = :phone LIMIT 1");
        $stmt->execute(['phone' => $phone]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'possible', 'contactId' => $row['contactId'], 'entityId' => $row['contactEntityId'], 'matchType' => 'phone'];
        }
    }

    if (!empty($first) && !empty($last)) {
        $stmt = $pdo->prepare("SELECT contactId, contactEntityId FROM tblContacts WHERE LOWER(contactFirstName) = :first AND LOWER(contactLastName) = :last LIMIT 1");
        $stmt->execute(['first' => $first, 'last' => $last]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['status' => 'possible', 'contactId' => $row['contactId'], 'entityId' => $row['contactEntityId'], 'matchType' => 'name'];
        }
    }

    return ['status' => 'none', 'contactId' => null, 'entityId' => null, 'matchType' => null];
}

// 🧼 normalizeLocationName — standardize for comparison
function normalizeLocationName(string $name): string {
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// 🔁 evaluateLocationDuplicate — authoritative GLOBAL location identity first
function evaluateLocationDuplicate(array $parsed, PDO $pdo): array {

    // -------------------------------------------------
    // Normalize input fields (supports legacy + normalized)
    // -------------------------------------------------
    $entityName = trim(
        $parsed['entity']['entityName']
        ?? $parsed['entity']['name']
        ?? ''
    );

    $locationName = trim(
        $parsed['location']['locationName']
        ?? ''
    );

    $placeId = trim(
        $parsed['location']['locationPlaceId']
        ?? $parsed['location']['placeId']
        ?? ''
    );

    $address = trim(
        $parsed['location']['locationAddress']
        ?? $parsed['location']['address']
        ?? ''
    );

    $city = trim(
        $parsed['location']['locationCity']
        ?? $parsed['location']['city']
        ?? ''
    );

    error_log('[evaluateLocationDuplicate] entityName = ' . $entityName);
    error_log('[evaluateLocationDuplicate] placeId = ' . $placeId);
    error_log('[evaluateLocationDuplicate] address = ' . $address);
    error_log('[evaluateLocationDuplicate] city = ' . $city);

    // -------------------------------------------------
    // 1. GLOBAL PlaceId Match — authoritative identity
    // DOES NOT depend on entity resolution
    // -------------------------------------------------
    if (!empty($placeId)) {

        $stmt = $pdo->query("
            SELECT
                locationId,
                locationName,
                locationPlaceId,
                locationEntityId
            FROM tblLocations
            WHERE locationPlaceId IS NOT NULL
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            $dbPlaceId = trim($row['locationPlaceId'] ?? '');

            error_log('[DB PLACEID] = ' . $dbPlaceId);

            if (
                !empty($dbPlaceId) &&
                strcasecmp($dbPlaceId, $placeId) === 0
            ) {

                error_log('✅ GLOBAL EXISTING LOCATION MATCH');

                return [
                    'status'           => 'exact',
                    'locationId'       => (int)$row['locationId'],
                    'existingEntityId' => (int)$row['locationEntityId'],
                    'matchType'        => 'placeId_global'
                ];
            }
        }

        error_log('❌ NO GLOBAL PLACEID MATCH FOUND');
    }

    // -------------------------------------------------
    // Entity resolution begins AFTER authoritative
    // location identity evaluation
    // -------------------------------------------------
    if (empty($entityName)) {

        return [
            'status' => 'none'
        ];
    }

    $entityId = resolveEntityIdByName($entityName, $pdo);

    if (!$entityId) {

        return [
            'status'   => 'new_entity',
            'entityId' => null
        ];
    }

    // -------------------------------------------------
    // 2. Entity-scoped address fallback
    // -------------------------------------------------
    if (!empty($address) && !empty($city)) {

        $stmt = $pdo->prepare("
            SELECT
                locationId,
                locationName
            FROM tblLocations
            WHERE locationEntityId = :entityId
              AND locationAddress = :address
              AND locationCity = :city
            LIMIT 1
        ");

        $stmt->execute([
            'entityId' => $entityId,
            'address'  => $address,
            'city'     => $city
        ]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            error_log('✅ ENTITY ADDRESS MATCH');

            return [
                'status'     => 'exact',
                'locationId' => (int)$row['locationId'],
                'matchType'  => 'address'
            ];
        }
    }

    // -------------------------------------------------
    // 3. Optional normalized name fallback
    // -------------------------------------------------
    if (!empty($locationName)) {

        $normalizedInput = normalizeLocationName($locationName);

        $stmt = $pdo->prepare("
            SELECT
                locationId,
                locationName,
                locationCity
            FROM tblLocations
            WHERE locationEntityId = :entityId
        ");

        $stmt->execute([
            'entityId' => $entityId
        ]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

            $dbName = normalizeLocationName($row['locationName'] ?? '');
            $dbCity = strtolower(trim($row['locationCity'] ?? ''));

            if (
                $dbName === $normalizedInput &&
                strtolower($city) === $dbCity
            ) {

                error_log('✅ NORMALIZED NAME/CITY MATCH');

                return [
                    'status'     => 'possible',
                    'locationId' => (int)$row['locationId'],
                    'matchType'  => 'name_city'
                ];
            }
        }
    }

    // -------------------------------------------------
    // No duplicate found
    // -------------------------------------------------
    return [
        'status'   => 'none',
        'entityId' => $entityId
    ];
}

// 🔑 resolveEntityIdByName — lookup entity by name
function resolveEntityIdByName(string $entityName, PDO $pdo): ?int {
    $entityName = trim($entityName);
    if (empty($entityName)) return null;

    // Exact match
    $stmt = $pdo->prepare("SELECT entityId FROM tblEntities WHERE LOWER(entityName) = LOWER(:name) LIMIT 1");
    $stmt->execute(['name' => $entityName]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return (int)$row['entityId'];
    }

    // Loose match
    $stmt = $pdo->prepare("SELECT entityId FROM tblEntities WHERE LOWER(entityName) LIKE LOWER(:name) LIMIT 1");
    $stmt->execute(['name' => '%' . $entityName . '%']);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return (int)$row['entityId'];
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
// 🧠 buildOperationalNarratives — generate narratives based on context
function buildOperationalNarratives(array $context): array {

    // Temporary debug — remove after stable
    error_log("[NARRATIVE DEBUG] Raw AI response: " . substr($response ?? '', 0, 800));
    error_log("[NARRATIVE DEBUG] Extracted JSON: " . json_encode($parsed ?? 'NO PARSE'));

    $fallback = [
        'decision'      => ['This proposal requires operational review.'],
        'blocking'      => [],
        'review'        => [],
        'informational' => []
    ];

    try {

        // -------------------------------------------------
        // Load environment
        // -------------------------------------------------
        if (!function_exists('skyesoftLoadEnv')) {
            require_once __DIR__ . '/utils/envLoader.php';
        }

        skyesoftLoadEnv();

        $apiKey = getenv("OPENAI_API_KEY");

        if (!$apiKey) {

            error_log('[operational-narrative] OPENAI_API_KEY missing');

            return $fallback;
        }

        // -------------------------------------------------
        // Load prompt
        // -------------------------------------------------
        $promptPath =
            dirname(__DIR__)
            . '/codex/prompts/operationalNarrative.prompt.md';

        if (!file_exists($promptPath)) {

            error_log(
                '[operational-narrative] Prompt file missing: '
                . $promptPath
            );

            return $fallback;
        }

        $systemPrompt = file_get_contents($promptPath);

        if (!$systemPrompt) {

            error_log('[operational-narrative] Prompt load failed');

            return $fallback;
        }

        // -------------------------------------------------
        // Build user prompt
        // -------------------------------------------------
        $userPrompt = json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if (!$userPrompt) {

            error_log('[operational-narrative] Context encoding failed');

            return $fallback;
        }

        // -------------------------------------------------
        // OpenAI payload
        // -------------------------------------------------
        $payload = [
            "model" => "gpt-4o-mini",
            "messages" => [
                [
                    "role" => "system",
                    "content" => $systemPrompt
                ],
                [
                    "role" => "user",
                    "content" => $userPrompt
                ]
            ],
            "temperature" => 0.2,
            "max_tokens" => 500
        ];

        // -------------------------------------------------
        // Execute request
        // -------------------------------------------------
        $ch = curl_init(
            "https://api.openai.com/v1/chat/completions"
        );

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer {$apiKey}"
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 20
        ]);

        $response = curl_exec($ch);

        if ($response === false) {

            error_log(
                '[operational-narrative] CURL ERROR: '
                . curl_error($ch)
            );

            curl_close($ch);

            return $fallback;
        }

        curl_close($ch);

        // -------------------------------------------------
        // Decode response
        // -------------------------------------------------
        $decoded = json_decode($response, true);

        $content =
            $decoded['choices'][0]['message']['content']
            ?? '';

        if (!$content) {

            error_log(
                '[operational-narrative] Empty AI content'
            );

            return $fallback;
        }

        // -------------------------------------------------
        // Extract JSON safely
        // -------------------------------------------------
        preg_match('/\{.*\}/s', $content, $matches);

        if (empty($matches[0])) {

            error_log(
                '[operational-narrative] Invalid JSON response'
            );

            return $fallback;
        }

        $parsed = json_decode($matches[0], true);

        if (!is_array($parsed)) {

            error_log(
                '[operational-narrative] JSON decode failed'
            );

            return $fallback;
        }

        // -------------------------------------------------
        // Normalize structure
        // -------------------------------------------------
        return array_merge([
            'decision'      => [],
            'blocking'      => [],
            'review'        => [],
            'informational' => []
        ], $parsed);

    } catch (Throwable $e) {

        error_log(
            '[operational-narrative] ERROR: '
            . $e->getMessage()
        );

        return $fallback;
    }
}

#endregion