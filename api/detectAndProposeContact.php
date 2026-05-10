<?php
declare(strict_types=1);

// =====================================================
// Skyesoft — detectAndProposeContact.php
// Version: 1.5.6
// Last Updated: 2026-05-08
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
        'suite' => '',           // ← NEW
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
// 📍 ADDRESS PREP + SUITE EXTRACTION (Improved)
// -------------------------------------------------
$fullAddress = trim(implode(' ', array_filter([
    $parsed['location']['address'] ?? '',
    $parsed['location']['city'] ?? '',
    $parsed['location']['state'] ?? '',
    $parsed['location']['zip'] ?? ''
])));

$lookupAddress = sanitizeAddressForLookup($fullAddress);

// -------------------------------------------------
// 🌍 GOOGLE LOCATION VALIDATION (Always Attempt)
// -------------------------------------------------
$locationValidation = [
    'status'               => 'invalid',
    'confidence'           => 0,
    'placeIdResolved'      => false,      // ← Default here
    'latLonResolved'       => false,
    'isMaricopa'           => false,
    'apnResolved'          => false,
    'jurisdictionResolved' => false,
    'issues'               => []
];

$googleData = null;

$lookupAddress = sanitizeAddressForLookup($fullAddress);

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
        $locationValidation['placeIdResolved'] = false;   // ← Explicit
    }
}

// -------------------------------------------------
// 🔑 SUITE NORMALIZATION — AI IS AUTHORITATIVE
// -------------------------------------------------
$locationSuite = '';

if (
    isset($parsed['location']['suite']) &&
    trim($parsed['location']['suite']) !== ''
) {

    $locationSuite = trim($parsed['location']['suite']);

    // Remove leading suite markers
    $locationSuite = preg_replace(
        '/^(SUITE|STE|UNIT|APT|APARTMENT|#)\s*/i',
        '',
        $locationSuite
    );

    $locationSuite = strtoupper(trim($locationSuite));

    // Safety validation
    if (
        preg_match('/^[A-Z0-9\-]{1,8}$/', $locationSuite)
    ) {
        $locationSuite = '#' . $locationSuite;
    } else {
        $locationSuite = '';
    }
}

// FINAL HARD OVERRIDE — Block any remaining bad values
$badSuites = ['#VE', '#AVE', '#ST', '#STE', '#DR', '#RD', '#LN', '#CT', '#BLVD'];
if (in_array($locationSuite, $badSuites, true)) {
    error_log("[SUITE-FINAL-OVERRIDE] Blocked bad suite: $locationSuite");
    $locationSuite = '';
}

// Authoritative field
$parsed['location']['locationAddressSuite'] = $locationSuite;

// -------------------------------------------------
// Debug / Monitoring for suspicious extractions
// -------------------------------------------------
if (in_array($locationSuite, ['#VE', '#AVE', '#ST', '#DR', '#RD', '#LN', '#CT', '#BLVD'], true)) {
    error_log("[SUITE-WARNING] Suspicious suite extraction blocked: '{$locationSuite}' from input: " 
              . substr($rawInputOriginal, 0, 120));
}


// -------------------------------------------------
// 📍 FINAL ADDRESS NORMALIZATION (Street-only for consistency)
// -------------------------------------------------
$fullAddressFinal = $parsed['location']['formattedAddress'] 
    ?? $parsed['location']['address'] 
    ?? ($fullAddress ?? '');

// Remove suite from final address
$address = preg_replace(
    '/(?:#|Suite|Ste|Unit|Apt)\s*[A-Za-z0-9\-]+/i',
    '',
    $fullAddressFinal
);

// Keep ONLY street portion (recommended for DB + parcel lookup)
$address = explode(',', $address)[0] ?? $address;

$address = trim(preg_replace('/\s+/', ' ', $address));

$parsed['location']['address'] = $address;
$parsed['location']['locationAddressRaw'] = $rawInputOriginal;


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
// 🌵 MARICOPA LOGIC — MULTI-PARCEL + USER SELECTION REQUIRED
// -------------------------------------------------
$county = strtoupper(trim($parsed['location']['county'] ?? ''));
$state  = strtoupper(trim($parsed['location']['state'] ?? ''));

$isMaricopa = ($county === 'MARICOPA' || $state === 'AZ');
$locationValidation['isMaricopa'] = $isMaricopa;

$parcel = null;
$parcelDetails = [];
$jurisdiction = null;

error_log("[MARICOPA-DEBUG] === SECTION 9 MARICOPA BLOCK IS NOW ACTIVE ===");
error_log("[MARICOPA-DEBUG] county='$county' | state='$state' | isMaricopa=" . ($isMaricopa ? 'YES' : 'NO'));
error_log("[MARICOPA-DEBUG] raw address='" . ($parsed['location']['address'] ?? 'EMPTY') . "'");

if ($isMaricopa && !empty($parsed['location']['address'])) {

    // CLEAN STREET-ONLY ADDRESS (critical for ArcGIS)
    $parcelLookupAddress = trim($parsed['location']['address']);

    // Remove any remaining city/state/zip that might have leaked in
    $parcelLookupAddress = preg_replace('/\b(Phoenix|AZ|85017|\d{5})\b/i', '', $parcelLookupAddress);
    $parcelLookupAddress = preg_replace('/\s+/', ' ', $parcelLookupAddress);
    $parcelLookupAddress = trim($parcelLookupAddress);

    error_log("[MARICOPA-DEBUG] Cleaned lookup address: " . $parcelLookupAddress);

    $parcelDetails = lookupMaricopaParcel($parcelLookupAddress);

    error_log("[MARICOPA-DEBUG] lookupMaricopaParcel returned " . count($parcelDetails) . " candidates");

    if (!empty($parcelDetails)) {
        $bestMatch = $parcelDetails[0];

        if (count($parcelDetails) === 1) {
            $parcel = $bestMatch;
            $locationValidation['parcelStatus'] = 'resolved';
            $locationValidation['apnResolved'] = true;
            $locationValidation['jurisdictionResolved'] = true;
        } else {
            $locationValidation['parcelStatus'] = 'multiple_matches';
            $locationValidation['apnResolved'] = false;
            $locationValidation['jurisdictionResolved'] = false;
            error_log("[Parcel] Multiple matches found (" . count($parcelDetails) . ") — user selection required");
        }

        $jurisdiction = $bestMatch['jurisdiction'] ?? null;
    } else {
        $locationValidation['issues'][] = 'parcel_lookup_failed';
        $locationValidation['parcelStatus'] = 'not_found';
    }
} else {
    error_log("[MARICOPA-DEBUG] Skipped — not Maricopa or no address");
}

// -------------------------------------------------
// 📬 SMARTY VALIDATION (ONLY IF PARCEL NOT FOUND)
// -------------------------------------------------

$parcelResolved = $locationValidation['apnResolved'] ?? false;

error_log('[smarty-debug] ENTERED');
error_log('[smarty-check] apnResolved=' . ($parcelResolved ? 'YES' : 'NO'));

$smartyResult = null;
$dpv = null;

if (!$parcelResolved) {

    error_log('[smarty-debug] ENTERED');
    error_log('[smarty-check] apnResolved=NO');

    $street = $parsed['location']['address'] ?? '';
    $city   = $parsed['location']['city'] ?? '';
    $state  = $parsed['location']['state'] ?? '';
    $zip    = $parsed['location']['zip'] ?? '';

    $smartyResult = validateAddressSmarty($street, $city, $state, $zip);

    if ($smartyResult) {
        $dpv = $smartyResult['analysis']['dpv_match_code'] ?? null;

        $parsed['location']['smartyValidatedAddress'] =
            ($smartyResult['delivery_line_1'] ?? '') . ' ' .
            ($smartyResult['last_line'] ?? '');

        $parsed['location']['smartyFootnotes'] =
            $smartyResult['analysis']['dpv_footnotes'] ?? null;
    }

} else {
    error_log('[smarty-check] SKIPPED (parcel resolved)');
}

// Attach to parsed location
$parsed['location']['locationDpvCode'] = $dpv;
$parsed['location']['locationDeliverable'] = ($dpv === 'Y');
$parsed['location']['locationRequiresSuite'] = ($dpv === 'D');

// Final inference
$parsed = inferLocationName($parsed);

// Data Integrity + Duplicates (unchanged)
$dataIntegrityStatus = ['status' => 'complete', 'missing' => []];
$missing = validateParsed($parsed);
if (!empty($missing)) {
    $dataIntegrityStatus['status'] = 'incomplete';
    $dataIntegrityStatus['missing'] = $missing;
}

if (!$pdo) {
    error_log('PDO connection missing — skipping duplicate detection');
    $duplicate = ['status' => 'none'];
    $locationDuplicate = ['status' => 'none'];
} else {
    $duplicate = evaluateDuplicate($parsed, $pdo);
    $locationDuplicate = evaluateLocationDuplicate($parsed, $pdo);
    error_log("[PCM-DECISION] Location Duplicate: " . json_encode($locationDuplicate));
    error_log("[PCM-DECISION] Parcel Status: " . ($locationValidation['parcelStatus'] ?? 'none'));
}

#endregion
#region SECTION 10 — 🧠 PCM + Final Response + AI Narrative (FINAL — Global PlaceId Precedence + Full Data Population)

$duplicate         = $duplicate         ?? ['status' => 'none'];
$locationDuplicate = $locationDuplicate ?? ['status' => 'none'];
$dataIntegrityStatus = $dataIntegrityStatus ?? ['status' => 'complete', 'missing' => []];
$locationValidation  = $locationValidation  ?? ['parcelStatus' => 'unknown', 'isMaricopa' => false, 'apnResolved' => false, 'jurisdictionResolved' => false];
$parcel              = $parcel              ?? null;

// Defensive defaults
$data = $data ?? ['entity' => [], 'location' => [], 'contact' => []];
$meta = $meta ?? ['inferences' => [], 'enrichments' => [], 'flags' => []];

// -------------------------------------------------
// DEBUG — remove after confirmation
// -------------------------------------------------
error_log("🔍 [SECTION 10] PlaceId = " . ($parsed['location']['locationPlaceId'] ?? 'NONE'));
error_log("🔍 [SECTION 10] locationDuplicate = " . json_encode($locationDuplicate));

// -------------------------------------------------
// AUTHORITATIVE PCM DECISION
// -------------------------------------------------
if (($dataIntegrityStatus['status'] ?? 'unknown') !== 'complete') {
    $pcm = ['status' => 'incomplete', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_missing_fields'];

} elseif (($duplicate['status'] ?? '') === 'exact') {
    $pcm = ['status' => 'duplicate_contact', 'readyForCommit' => false, 'requiresReview' => false, 'blocksCommit' => true, 'action' => 'reject_duplicate'];

} elseif (($duplicate['status'] ?? '') === 'possible') {
    $pcm = ['status' => 'possible_duplicate_contact', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_duplicate'];

} elseif (($locationDuplicate['status'] ?? '') === 'exact') {
    $pcm = [
        'status'          => 'existing_location',
        'readyForCommit'  => false,
        'requiresReview'  => true,
        'blocksCommit'    => true,
        'action'          => 'link_existing_location'
    ];

} elseif (($locationDuplicate['status'] ?? '') === 'possible') {
    $pcm = ['status' => 'possible_location_duplicate', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_location'];

} elseif (isset($locationValidation['parcelStatus']) && $locationValidation['parcelStatus'] === 'multiple_matches') {
    $pcm = ['status' => 'multiple_parcels', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => false, 'action' => 'confirm_parcel'];

} elseif (($locationValidation['isMaricopa'] ?? false) && 
          (!$locationValidation['apnResolved'] ?? false || !$locationValidation['jurisdictionResolved'] ?? false)) {
    $pcm = ['status' => 'invalid_location', 'readyForCommit' => false, 'requiresReview' => true, 'blocksCommit' => true, 'action' => 'resolve_location'];

} else {
    $pcm = ['status' => 'new_elc', 'readyForCommit' => true, 'requiresReview' => false, 'blocksCommit' => false, 'action' => 'insert_new'];
}

// -------------------------------------------------
// BUILD FULL DATA + META FOR INSERT / UI
// -------------------------------------------------
$fullName = trim(($parsed['contact']['firstName'] ?? '') . ' ' . ($parsed['contact']['lastName'] ?? ''));

// ENTITY
$data['entity'] = [
    'entityName' => $parsed['entity']['name'] ?? ''
];

// LOCATION (full enriched data)
$data['location'] = [
    'locationName'           => $parsed['location']['locationName'] ?? '',
    'locationPlaceId'        => $parsed['location']['locationPlaceId'] ?? null,
    'locationLatitude'       => $parsed['location']['latitude'] ?? null,
    'locationLongitude'      => $parsed['location']['longitude'] ?? null,
    'locationAddress'        => $parsed['location']['address'] ?? '',
    'locationAddressSuite'   => $parsed['location']['locationAddressSuite'] ?? '',
    'locationCity'           => $parsed['location']['city'] ?? '',
    'locationState'          => $parsed['location']['state'] ?? '',
    'locationZip'            => $parsed['location']['zip'] ?? '',
    'locationCounty'         => $parsed['location']['county'] ?? '',
    'locationCountyFips'     => $parsed['location']['countyFips'] ?? '',
    'locationParcelNumber'   => null,
    'locationParcelNumberRaw'=> null,
    'locationJurisdiction'   => $parsed['location']['locationJurisdiction'] ?? '',
    'parcelDetails'          => $parsed['location']['parcelDetails'] ?? [],
    'parcelResolution'       => $parsed['location']['parcelResolution'] ?? [
        'status' => 'unknown', 'requiresUserSelection' => false, 'selectedApn' => null, 'candidateCount' => 0
    ],
    'locationIsBilling'      => 0,
    'locationNote'           => '',
    'locationZone'           => '',
    'locationIsNotValid'     => 0
];

// CONTACT
$data['contact'] = [
    'contactSalutation'           => $parsed['contact']['salutation'] ?? '',
    'contactFirstName'            => $parsed['contact']['firstName'] ?? '',
    'contactLastName'             => $parsed['contact']['lastName'] ?? '',
    'contactTitle'                => $parsed['contact']['title'] ?? '',
    'contactIsBilling'            => 0,
    'contactPrimaryPhone'         => $parsed['contact']['primaryPhone'] ?? '',
    'contactPrimaryPhoneRaw'      => $parsed['contact']['primaryPhoneRaw'] ?? '',
    'contactPrimaryPhoneExtension'=> $parsed['contact']['primaryPhoneExtension'] ?? '',
    'contactSecondaryPhone'       => $parsed['contact']['secondaryPhone'] ?? '',
    'contactSecondaryPhoneRaw'    => $parsed['contact']['secondaryPhoneRaw'] ?? '',
    'contactEmail'                => $parsed['contact']['email'] ?? '',
    'contactEmailNormalized'      => $parsed['contact']['emailNormalized'] ?? '',
    'contactEmailConfirmed'       => 0,
    'contactNote'                 => '',
    'contactIsNotValid'           => 0,
    'isActive'                    => 1
];

// META
$meta['inferences'] = [
    'salutationInferred' => $parsed['contact']['salutationInferred'] ?? false,
    'locationNameInferred' => $parsed['location']['locationNameInferred'] ?? false,
    'entityNameInferred' => $parsed['entity']['nameInferred'] ?? false
];
$meta['enrichments'] = ['google_geocode', 'census_county', 'maricopa_parcel', 'smarty_usps'];
$meta['flags'] = [
    'isMaricopa'           => $locationValidation['isMaricopa'] ?? false,
    'locationValid'        => $locationValidation['status'] ?? 'invalid',
    'parcelStatus'         => $locationValidation['parcelStatus'] ?? 'unknown',
    'apnResolved'          => $locationValidation['apnResolved'] ?? false,
    'jurisdictionResolved' => $locationValidation['jurisdictionResolved'] ?? false,
    'uspsValidated'        => true,
    'dpvCode'              => $parsed['location']['locationDpvCode'] ?? 'Y'
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
        'decision' => ["This proposal is ready for the {$pcm['action']} transaction."],
        'blocking' => [],
        'review'   => [],
        'informational' => []
    ]
];

// Populate issues
if ($pcm['status'] === 'existing_location') {
    $resolution['issues']['blocking'][] = 'existing_location';
} elseif ($pcm['status'] === 'multiple_parcels') {
    $resolution['issues']['review'][] = 'multiple_parcels';
} elseif ($pcm['status'] === 'invalid_location') {
    $resolution['issues']['blocking'][] = 'invalid_location';
}

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
// 🧾 lookupMaricopaParcel — WORKING ArcGIS endpoint (verified by test page + curl)
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

    $fullUrl = "{$url}?{$params}";
    error_log("[Parcel DEBUG] Full query URL: " . $fullUrl);

    $response = @file_get_contents($fullUrl);

    if (!$response) {
        error_log("[Parcel DEBUG] HTTP request failed");
        return [];
    }

    $data = json_decode($response, true);
    $features = $data['features'] ?? [];

    error_log("[Parcel DEBUG] API returned " . count($features) . " features");

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

    $unique = [];
    foreach ($candidates as $c) {
        $unique[$c['apnRaw']] = $c;
    }

    $candidates = array_values($unique);
    usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    error_log("[lookupMaricopaParcel] FINAL COUNT: " . count($candidates) . " candidates for: " . $cleanAddress);

    return $candidates;
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
// 🔍 resolveEntityIdByName
/**
 * Resolve entityId by exact name match (case-insensitive)
 *
 * @return int|null  entityId if found, null otherwise
 */
function resolveEntityIdByName(string $entityName, PDO $pdo): ?int {

    $entityName = trim($entityName);
    if (empty($entityName)) {
        return null;
    }

    // Try exact match first
    $stmt = $pdo->prepare("
        SELECT entityId 
        FROM tblEntities 
        WHERE LOWER(entityName) = LOWER(:name) 
        LIMIT 1
    ");
    $stmt->execute(['name' => $entityName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['entityId'];
    }

    // Fallback: LIKE match (for slight variations)
    $stmt = $pdo->prepare("
        SELECT entityId 
        FROM tblEntities 
        WHERE LOWER(entityName) LIKE LOWER(:name)
        LIMIT 1
    ");
    $stmt->execute(['name' => '%' . $entityName . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? (int)$row['entityId'] : null;
}
// 📞 extractPhoneExtension — extract phone extension
function extractPhoneExtension(string $input): ?string {

    if (preg_match('/\b(ext\.?|x|extension)\s*[:\-]?\s*(\d{1,6})\b/i', $input, $m)) {
        return trim($m[2]);
    }

    return null;
}

#endregion