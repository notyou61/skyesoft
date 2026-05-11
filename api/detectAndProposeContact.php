<?php
declare(strict_types=1);

/**
 * @phpstan-ignore-next-line
 * @psalm-suppress TooManyTypes
 * @noinspection PhpUndefinedFunctionInspection
 * @noinspection PhpIncludeInspection
 * 
 * Intelephense Large File Suppression
 * This file intentionally exceeds normal complexity limits due to its role as the main orchestration script.
 */

// =====================================================
// Skyesoft — detectAndProposeContact.php
// Version: 1.5.7
// Last Updated: 2026-05-11
// =====================================================

#region SECTION 00 — ⚙️ Force Fresh Code + Debugging (Maricopa Fix)

error_log("=== DETECTANDPROPOSECONTACT.PHP V1.5.7 UTILS SPLIT === " . date('Y-m-d H:i:s'));

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
    error_log("[OPCACHE] ✅ File cache invalidated (production)");
} else {
    error_log("[OPCACHE] opcache_invalidate not available (local dev — OK)");
}

// Load utilities
require_once __DIR__ . '/utils/detectAndProposeContact.utils.php';


// =====================================================
// OPTIONAL FORCED TEST — Enable only when debugging
// =====================================================
$runForcedTest = false;   // ← Change to true only when needed

if ($runForcedTest) {
    error_log("=== FORCED MARICOPA LOOKUP TEST (bypassing all conditions) ===");
    $forcedAddress = "3145 N 33rd Ave Phoenix AZ 85017";
    $forcedParcelDetails = lookupMaricopaParcel($forcedAddress);
    error_log("[FORCED-TEST] lookupMaricopaParcel returned " . count($forcedParcelDetails) . " candidates");
    if (count($forcedParcelDetails) > 0) {
        error_log("[FORCED-TEST] First APN: " . ($forcedParcelDetails[0]['apnRaw'] ?? 'none'));
        error_log("[FORCED-TEST] Jurisdiction: " . ($forcedParcelDetails[0]['jurisdiction'] ?? 'none'));
    }
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

error_log('=== DEBUG START detectAndProposeContact v1.5.7 ===');

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
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json", 
        "Authorization: Bearer $apiKey"
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_CONNECTTIMEOUT => 10
]);

$response = curl_exec($ch);

// Use safe close to fix PHP 8.5 deprecation
safeCurlClose($ch);

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

#region PCM DECISION — Governance Matrix

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

} elseif (
    ($locationValidation['isMaricopa'] ?? false) === true &&
    ($locationValidation['parcelStatus'] ?? '') === 'multiple_matches'
) {
    $pcm = ['status' => 'multiple_parcels', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_parcel'];

} elseif (
    ($locationValidation['isMaricopa'] ?? false) === true &&
    ($locationValidation['parcelStatus'] ?? 'unknown') !== 'resolved'
) {
    $pcm = ['status' => 'unresolved_parcel', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_parcel'];

} elseif (
    empty(trim($parsed['location']['address'] ?? '')) || 
    trim($parsed['location']['address']) === 'Phoenix' || 
    strlen(trim($parsed['location']['address'] ?? '')) < 8
) {
    $pcm = ['status' => 'incomplete_address', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_address'];

} else {
    $pcm = ['status' => 'new_elc', 'readyForCommit' => true, 'requiresReview' => false, 'blocksCommit' => false, 'action' => 'insert_new'];
}

#endregion

#region FINAL OUTPUT — Response Builder

// === DEBUG BEFORE RESPONSE ===
error_log("=== [FINAL] Before response.php ===");
error_log("pcmStatus = " . ($pcm['status'] ?? 'NOT SET'));
error_log("parsed keys = " . implode(', ', array_keys($parsed ?? [])));
error_log("rawInputOriginal = " . ($rawInputOriginal ?? 'NULL'));

// ==================== FINAL OUTPUT (SECTION 10) ====================

// Safety net
$pcm = $pcm ?? ['status' => 'incomplete', 'action' => null, 'readyForCommit' => false, 'blocksCommit' => true];

require_once __DIR__ . '/detectAndProposeContact.response.php';

#endregion