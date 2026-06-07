<?php
declare(strict_types=1);

/**
 * Skyesoft — processProposedContact.php
 * Main Orchestration + Proposal Report Generation
 * Version: 1.6.1
 * Last Updated: 2026-05-28
 */

#region SECTION 00 — Force Fresh Code

error_log("=== PROCESSPROPOSEDCONTACT.PHP v1.6.1 START === " . date('Y-m-d H:i:s'));

if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
    error_log("[OPCACHE] Main file invalidated");
}

require_once __DIR__ . '/utils/processProposedContact.utils.php';

error_log("[PIPELINE] Utils file loaded successfully");

#endregion

#region SECTION 01 — Runtime Configuration + Input

if (!headers_sent()) {
    header('Content-Type: application/json');
}

// Defensive error settings for production + debugging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/envLoader.php';

skyesoftLoadEnv();
$pdo = getPDO() ?? null;

error_log('[pipeline-entry] processProposedContact START ' . microtime(true));

#endregion

#region SECTION 02 — Input Resolution (Robust + Debug)

$rawInputOriginal = '';
$activitySessionId = session_id() ?? '';

$rawJson = file_get_contents('php://input');
$inputData = json_decode($rawJson, true) ?? $_POST ?? [];

$rawInputOriginal = trim($inputData['input'] ?? '');

if (!empty($inputData['activitySessionId'])) {
    $activitySessionId = $inputData['activitySessionId'];
}

$rawInput = trim($rawInputOriginal);

error_log("[INPUT] Length: " . strlen($rawInput) . " | Content: " . substr($rawInput, 0, 150));

if ($rawInput === '') {
    jsonError('No input provided');
}

error_log("[INPUT] ✅ Valid input accepted");

#endregion

#region SECTION 03 — 🧠 Unified AI Prompt Construction & Execution

// =====================================================
// Unified Strong Extraction Prompt
// =====================================================
$systemPrompt = <<<EOT
You are an extremely precise structured data extraction engine specialized in cleaning and normalizing messy business contact signatures, website blocks, Outlook signatures, and pasted content.

PERFORM THESE STEPS IN ORDER:

1. CLEAN & NORMALIZE FIRST
   - Restore logical line breaks and structure from collapsed, HTML-contaminated, or poorly formatted input.
   - Remove noise: icons, emojis, HTML tags, disclaimers ("Sent from my iPhone", confidentiality notices), repeated separators, social media links, decorative text.
   - Fix common formatting issues: extra spaces, broken lines, inline artifacts.
   - Do NOT invent or hallucinate information.

2. THEN EXTRACT CLEAN DATA
   - Extract Entity, Location, and Contact fields from the cleaned text.

Return ONLY valid JSON. No explanations, no markdown, no extra text.

CRITICAL RULES:
- Use empty string "" for any missing value. Never omit fields.
- Suite field must contain only actual suite/unit info (e.g. "#120", "Ste 208", "Unit B"). Never place street suffixes (Ave, St, Rd, Dr, Blvd, etc.) into the suite field.
- Phone numbers: preserve raw version exactly as shown.
- Entity name: use the company/organization name when present.
- Do NOT use departments, divisions, slogans, marketing text, or organizational descriptors as locationName values.
- Only populate locationName when a true physical branch/site/location name is explicitly present.
- Be conservative with inference — better to use "" than guess.

Return EXACTLY this structure:

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

$extractionPrompt = <<<EOT
Clean and normalize the following pasted contact information, then extract structured data.

INPUT (may be messy or poorly formatted):
{$rawInput}
EOT;

// =====================================================
// AI Request Execution
// =====================================================

if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/utils/envLoader.php';
}
skyesoftLoadEnv();

$apiKey = getenv("OPENAI_API_KEY");
$googleApiKey = skyesoftGetEnv("GOOGLE_MAPS_BACKEND_API_KEY") ?? getenv("GOOGLE_MAPS_BACKEND_API_KEY");

if (!$apiKey) jsonError('OPENAI_API_KEY not found');

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

#region SECTION 04 — 🛡️ Schema Enforcement + Fallback Recovery

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
// 🛡️ EXPLICIT ENTITY PRESERVATION LAYER
// -------------------------------------------------
$parsed = preserveExplicitEntityName($parsed, $rawInput);

// =====================================================
// PCM-07 Legacy Defaults
// =====================================================

$isExplicitLocationOnlyIntent = false;
$declaredEntityName = '';

if ($isExplicitLocationOnlyIntent === true && !empty($declaredEntityName)) {
    $parsed['entity']['name'] = $declaredEntityName;
    error_log('[PCM-07] Entity overridden from directive: ' . $declaredEntityName);
}

// -------------------------------------------------
// 🧠 Positive Deterministic Entity Inference from Email Domain
//    (Safe, narrow, positive correction — no deletion heuristics)
// -------------------------------------------------
$contactEmail = strtolower(trim($parsed['contact']['email'] ?? ''));
if (!empty($contactEmail)) {
    $domain = substr(strrchr($contactEmail, "@"), 1);

    $knownDomains = [
        'christysigns.com' => 'Christy Signs',
        // Add more known domains here as needed
    ];

    if (isset($knownDomains[$domain])) {
        $parsed['entity']['name'] = $knownDomains[$domain];
        error_log('[ENTITY-SAFEGUARD] Email domain positively mapped entity: ' . $knownDomains[$domain]);
    }
}

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
if (empty($parsed['location']['city']) || empty($parsed['location']['state'])) {
    if (preg_match('/([A-Za-z\s]+),\s*([A-Z]{2})\s*(\d{5})?/', $rawInput, $m)) {
        $city  = ucwords(strtolower(trim($m[1])));
        $state = strtoupper(trim($m[2]));
        $zip   = isset($m[3]) ? substr($m[3], 0, 5) : '';

        if (empty($parsed['location']['city'])) $parsed['location']['city'] = $city;
        if (empty($parsed['location']['state'])) $parsed['location']['state'] = $state;
        if (empty($parsed['location']['zip']) && !empty($zip)) $parsed['location']['zip'] = $zip;
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

#region SECTION 05 — 🔍 Intent Validation

if (($aiData['intent'] ?? '') !== 'contact_proposal') {
    echo json_encode([
        'status'  => 'reject',
        'message' => 'Input not recognized as a contact signature.',
        'success' => false
    ]);
    exit;
}

#endregion

#region SECTION 06 — 📞 Assign Primary + Secondary Phones (Enhanced)

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

#region SECTION 07 — 🧩 Data Processing & Enrichment (Deterministic — Consolidated & Corrected)

// =====================================================
// DEFENSIVE SALUTATION HELPER (Critical Fix)
// =====================================================
if (!function_exists('inferSalutation')) {
    function inferSalutation(string $firstName = '', string $lastName = ''): ?string {
        $first = strtolower(trim($firstName));
        
        if (in_array($first, ['mr', 'mr.', 'ms', 'ms.', 'dr', 'miss', 'mrs', 'mrs.'], true)) {
            return null;
        }
        
        // Conservative default - safer than guessing gender
        return 'Ms.';
    }
}

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

        // --- DIAGNOSTIC LOGS ---
        error_log("[STREETVIEW-DEBUG] Place ID resolved successfully");
        error_log("[STREETVIEW-DEBUG] lat = " . ($googleData['lat'] ?? 'MISSING'));
        error_log("[STREETVIEW-DEBUG] lng = " . ($googleData['lng'] ?? 'MISSING'));

        $parsed['location']['googleData'] = $googleData['googleData'] ?? $googleData;

        $locationValidation['status']          = 'valid';
        $locationValidation['placeIdResolved'] = true;
        $locationValidation['latLonResolved']  = true;
        $locationValidation['confidence']      = 90;

        // =====================================================
        // NOTE: Street View image generation has been moved to
        // generateReports.php (called at report rendering time)
        // =====================================================

    } else {
        $locationValidation['issues'][] = 'google_place_not_resolved';
        error_log('[STREETVIEW] Google Place ID was not resolved');
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

// =====================================================
// CLEAN COUNTY RESOLUTION — ZERO HARDCODING
// =====================================================
$geoAddress = trim(
    $parsed['location']['formattedAddress'] 
    ?? ($parsed['location']['address'] . ', ' 
       . ($parsed['location']['city'] ?? '') . ', ' 
       . ($parsed['location']['state'] ?? '') . ' ' 
       . ($parsed['location']['zip'] ?? ''))
);

error_log("[COUNTY] Starting clean resolution for: " . $geoAddress);

// --- Primary: Census ---
$geo = resolveGeographyFromAddress($geoAddress);

if ($geo && !empty($geo['county'])) {
    $parsed['location']['county']     = trim($geo['county']);
    $parsed['location']['countyFips'] = $geo['countyFips'] ?? '';   // Only what Census actually returned
    error_log("[COUNTY] ✅ Census returned county: '{$parsed['location']['county']}' | FIPS: '{$parsed['location']['countyFips']}'");
} 
else {
    // --- Secondary: Strict Google addressComponents only ---
    $countyName = null;

    if (!empty($parsed['location']['googleData']['addressComponents'] ?? [])) {
        foreach ($parsed['location']['googleData']['addressComponents'] as $comp) {
            if (in_array('administrative_area_level_2', $comp['types'] ?? [])) {
                $countyName = trim(str_replace(' County', '', $comp['long_name']));
                break;
            }
        }
    }

    if ($countyName) {
        $parsed['location']['county']     = $countyName;
        $parsed['location']['countyFips'] = '';   // No hardcoding — Google does not provide FIPS here
        error_log("[COUNTY] ✅ Google addressComponents returned county: '{$countyName}' (FIPS left empty)");
    } else {
        // Explicit empty — no guessing, no defaults
        $parsed['location']['county']     = '';
        $parsed['location']['countyFips'] = '';
        error_log("[COUNTY] ❌ No county resolved from Census or Google addressComponents");
    }
}

// Final normalization (always runs)
$parsed['location']['county']     = trim($parsed['location']['county'] ?? '');
$parsed['location']['countyFips'] = trim($parsed['location']['countyFips'] ?? '');

error_log("[COUNTY] Final → county: '{$parsed['location']['county']}' | countyFips: '{$parsed['location']['countyFips']}'");

// -------------------------------------------------
// MARICOPA PARCEL LOOKUP + STATEFUL NORMALIZATION
// -------------------------------------------------
$county = strtoupper(trim($parsed['location']['county'] ?? ''));
$state  = strtoupper(trim($parsed['location']['state'] ?? ''));

$isMaricopa = ($county === 'MARICOPA');
$locationValidation['isMaricopa'] = $isMaricopa;

error_log("[MARICOPA-CHECK] county='" . ($parsed['location']['county'] ?? 'MISSING') . 
          "' | address='" . ($parsed['location']['address'] ?? 'MISSING') . 
          "' | isMaricopa=" . ($isMaricopa ? 'true' : 'false'));

$parcelDetails = [];

if ($isMaricopa && !empty($parsed['location']['address'])) {

    // -------------------------------------------------
    // TEMP DEBUG — Parcel Address Construction
    // -------------------------------------------------
    $parcelLookupAddress = trim(implode(', ', array_filter([
        $parsed['location']['address'] ?? '',
        $parsed['location']['city'] ?? '',
        $parsed['location']['state'] ?? ''
    ])));

    error_log("[PARCEL-CALL] Raw parcelLookupAddress = '" . $parcelLookupAddress . "'");
    error_log("[PARCEL-CALL] location.address = '" . ($parsed['location']['address'] ?? 'MISSING') . "'");
    error_log("[PARCEL-CALL] location.city    = '" . ($parsed['location']['city'] ?? 'MISSING') . "'");
    error_log("[PARCEL-CALL] location.state   = '" . ($parsed['location']['state'] ?? 'MISSING') . "'");

    $rawParcels = lookupMaricopaParcel($parcelLookupAddress);

    error_log("[PARCEL-CALL] lookupMaricopaParcel returned " . count($rawParcels) . " candidates");

    $rawParcels = lookupMaricopaParcel($parcelLookupAddress);

    // -------------------------------------------------
    // STATEFUL PARCEL CANDIDATE NORMALIZATION (Rich Data)
    // -------------------------------------------------
    $parcelDetails = array_map(function ($p) {
        return [
            // === Core Identification ===
            'apnRaw'          => $p['apnRaw'] ?? null,
            'apnDisplay'      => $p['apnDisplay'] ?? null,

            // === Location ===
            'address'         => $p['address'] ?? '',
            'city'            => $p['city'] ?? '',
            'jurisdiction'    => $p['jurisdiction'] ?? '',

            // === Ownership ===
            'owner'           => $p['owner'] ?? '',

            // === Mailing Address (New) ===
            'mailingAddress'  => $p['mailingAddress'] ?? '',
            'mailingCity'     => $p['mailingCity'] ?? '',
            'mailingState'    => $p['mailingState'] ?? '',
            'mailingZip'      => $p['mailingZip'] ?? '',

            // === Transactional Data (New) ===
            'deedNumber'      => $p['deedNumber'] ?? '',
            'saleDate'        => $p['saleDate'] ?? '',
            'salePrice'       => $p['salePrice'] ?? null,

            // === Property Details (New) ===
            'section'         => $p['section'] ?? '',
            'township'        => $p['township'] ?? '',
            'range'           => $p['range'] ?? '',
            'lotSizeSqFt'     => $p['lotSizeSqFt'] ?? null,
            'mcr'             => $p['mcr'] ?? '',
            'subdivision'     => $p['subdivision'] ?? '',
            'yearBuilt'       => $p['yearBuilt'] ?? null,

            // === Coordinates (Already added previously) ===
            'latitude'        => $p['latitude'] ?? null,
            'longitude'       => $p['longitude'] ?? null,

            // === Source & Confidence ===
            'source'          => $p['source'] ?? 'mca_arcgis_mcassessor',
            'confidence'      => $p['confidence'] ?? 70,
            'matchedInput'    => $p['matchedInput'] ?? '',

            // === OPERATIONAL STATE (Keep these) ===
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
// JURISDICTION PROMOTION + TITLE CASE NORMALIZATION
// -------------------------------------------------

if (
    empty($parsed['location']['locationJurisdiction']) &&
    !empty($parcelDetails[0]['jurisdiction'])
) {

    $rawJur = trim($parcelDetails[0]['jurisdiction']);

    // Convert to Title Case (handles PHOENIX → Phoenix)
    $normalizedJur = ucwords(strtolower($rawJur));

    $parsed['location']['locationJurisdiction'] = $normalizedJur;

    $locationValidation['jurisdictionResolved'] = true;

    error_log("[JURISDICTION] Normalized: {$rawJur} → {$normalizedJur}");
}

// -------------------------------------------------
// FINAL JURISDICTION CLEANUP
// -------------------------------------------------

$jur = trim($parsed['location']['locationJurisdiction'] ?? '');

if ($jur === '' || strtoupper($jur) === 'NO CITY/TOWN') {
    $parsed['location']['locationJurisdiction'] = 'Maricopa County';
    $locationValidation['jurisdictionResolved'] = true;
}

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

// =====================================================
// Proposal Type Detection — Proposed Location (PL)
// =====================================================

$hasStrongContactIndicators =
    !empty(trim($parsed['contact']['email'] ?? '')) ||
    !empty(trim($parsed['contact']['primaryPhoneRaw'] ?? '')) ||
    !empty(trim($parsed['contact']['title'] ?? '')) ||
    !empty(trim($parsed['contact']['contactSalutation'] ?? '')) ||
    !empty(trim($parsed['contact']['secondaryPhoneRaw'] ?? ''));

$isLocationOnlyProposal =
    !empty(trim($parsed['entity']['name'] ?? '')) &&
    !empty(trim($parsed['location']['address'] ?? '')) &&
    $hasStrongContactIndicators === false;




// =====================================================
// Data Integrity + Duplicates
// =====================================================

$dataIntegrityStatus = [
    'status' => 'complete',
    'missing' => []
];

$missing = validateParsed($parsed) ?? [];

// =====================================================
// PCM-07 — Relax Contact Requirements
// =====================================================
if ($isExplicitLocationOnlyIntent === true) {

    $relaxFields = [
        'contactFirstName', 'firstName',
        'contactLastName',  'lastName',
        'contactEmail',     'email', 'contactEmailNormalized',
        'contactPrimaryPhone', 'primaryPhone', 
        'contactPrimaryPhoneRaw', 'primaryPhoneRaw',
        'contactTitle',     'title',
        'contactSalutation','salutation', 
        'contactSalutationInferred',
        'contact.contactMethod',
        'contactMethod'
    ];

    $filtered = [];
    if (is_array($missing)) {
        foreach ($missing as $field) {
            if (!in_array($field, $relaxFields, true)) {
                $filtered[] = $field;
            }
        }
    }
    $missing = $filtered;

} elseif ($isLocationOnlyProposal === true) {

    // General location-only fallback
    $relaxFieldsGeneral = [
        'contactFirstName', 'firstName',
        'contactLastName',  'lastName',
        'contactEmail',     'email',
        'contactPrimaryPhone', 'primaryPhone',
        'contact.contactMethod',
        'contactMethod'
    ];

    $filtered = [];
    if (is_array($missing)) {
        foreach ($missing as $field) {
            if (!in_array($field, $relaxFieldsGeneral, true)) {
                $filtered[] = $field;
            }
        }
    }
    $missing = $filtered;
}

// =====================================================
// Relax Contact Requirements For General Location-Only
// =====================================================
if ($isLocationOnlyProposal === true && !$isExplicitLocationOnlyIntent) {

    error_log('[PCM-07] General Location-Only relaxation applied');

    $relaxFieldsGeneral = [
        'contactFirstName', 'firstName',
        'contactLastName',  'lastName',
        'contactEmail',     'email',
        'contactPrimaryPhone', 'primaryPhone',
        'contact.contactMethod',
        'contactMethod'
    ];

    $filtered = [];
    if (is_array($missing)) {
        foreach ($missing as $field) {
            if (!in_array($field, $relaxFieldsGeneral, true)) {
                $filtered[] = $field;
            }
        }
    }
    $missing = $filtered;
}

// =====================================================
// Final Integrity Decision
// =====================================================

if (!empty($missing)) {
    $dataIntegrityStatus['status'] = 'incomplete';
    $dataIntegrityStatus['missing'] = $missing;
    error_log('[PCM-07] ⚠️ Validation still incomplete after relaxation: ' . json_encode($missing));
} else {
    $dataIntegrityStatus['status'] = 'complete';
    error_log('[PCM-07] ✅ Validation PASSED after relaxation — ready for PCM decision');
}

// =====================================================
// Duplicate Checks
// =====================================================

if (!$pdo) {
    $duplicate = ['status' => 'none'];
    $locationDuplicate = ['status' => 'none'];
    $entityDuplicate = ['status' => 'none'];
} else {
    $duplicate = evaluateDuplicate($parsed, $pdo);
    $locationDuplicate = evaluateLocationDuplicate($parsed, $pdo);
    $entityDuplicate = evaluateEntityDuplicate($parsed, $pdo);
    error_log('[ENTITY DUPLICATE] ' . json_encode($entityDuplicate));
    error_log('[LOCATION DUPLICATE] ' . json_encode($locationDuplicate));
}

$parsed['location']['_activeCodeVersion'] = 'county-clean-v3-' . date('His');

#endregion

#region SECTION 08 — PCM DECISION — Governance Matrix (PC + RS Model)

// =====================================================
// Initialize PCM Object
// =====================================================

$pcm = [
    'pc'               => null,   // PC-1, PC-2, PC-3, PC-4
    'pcStatus'         => null,

    'rs'               => [],     // Additive governance overlays
    'rsStatuses'       => [],

    'readyForCommit'   => false,
    'requiresReview'   => false,
    'blocksCommit'     => true,

    'action'           => null
];

// =====================================================
// PASS 1 — Proposed Contact (PC) Classification
// =====================================================

// Explicit Location-Only Proposal
if ($isExplicitLocationOnlyIntent === true) {

    $pcm['pc']       = 'PC-4';
    $pcm['pcStatus'] = 'proposed_location';

    $pcm['action']   = 'create_location_only';

    $pcm['blocksCommit'] = false;

// Exact Duplicate Contact
} elseif (($duplicate['status'] ?? '') === 'exact') {

    $pcm['pc']       = null;
    $pcm['pcStatus'] = 'duplicate_contact';

    $pcm['action']   = 'reject_duplicate';

    $pcm['rs'][]         = 'RS-5';
    $pcm['rsStatuses'][] = 'duplicate_contact';

    $pcm['blocksCommit'] = true;

// Existing Location + New Contact
} elseif (($locationDuplicate['status'] ?? '') === 'exact') {

    $pcm['pc']       = 'PC-3';
    $pcm['pcStatus'] = 'existing_location';

    $pcm['action']   = 'link_existing_location';

    $pcm['blocksCommit'] = false;

// Existing Entity + New Location + New Contact
} elseif (
    ($entityDuplicate['status'] ?? '') === 'exact'
    && ($locationDuplicate['status'] ?? '') !== 'exact'
    && !$isExplicitLocationOnlyIntent
) {

    $pcm['pc']       = 'PC-2';
    $pcm['pcStatus'] = 'existing_entity_new_location';

    $pcm['action']   = 'link_existing_entity';

    $pcm['blocksCommit'] = false;

// New Entity + New Location + New Contact
} else {

    $pcm['pc']       = 'PC-1';
    $pcm['pcStatus'] = 'new_elc';

    $pcm['action']   = 'insert_new';

    $pcm['blocksCommit'] = false;
}

// =====================================================
// PASS 2 — Governance / Review States (RS)
// =====================================================

// -----------------------------------------------------
// RS-1 — Incomplete Proposal
// -----------------------------------------------------

if (
    ($dataIntegrityStatus['status'] ?? 'unknown') !== 'complete'
    && ($pcm['pc'] ?? null) !== 'PC-4'
)

// -----------------------------------------------------
// RS-8 — Invalid Location
// -----------------------------------------------------

if (empty($parsed['location']['locationPlaceId'] ?? null)) {

    $pcm['rs'][]         = 'RS-8';
    $pcm['rsStatuses'][] = 'invalid_location';

    $pcm['blocksCommit'] = true;
}

// -----------------------------------------------------
// RS-6 — Multiple Parcels
// -----------------------------------------------------
//
// Governance Principle:
//
// PC-2:
//     Existing Entity + New Location
//     → Requires parcel confirmation
//
// PC-3:
//     Existing Trusted Location
//     → Parcel ambiguity does NOT block continuity
//
// PC-4:
//     Location-Only Intake
//     → Parcel ambiguity does NOT block intake
//
// -----------------------------------------------------

if (
    ($locationValidation['isMaricopa'] ?? false) === true
    && ($locationValidation['parcelStatus'] ?? '') === 'multiple_matches'
    && !in_array(
        ($pcm['pc'] ?? null),
        ['PC-3', 'PC-4'],
        true
    )
) {

    $pcm['rs'][]         = 'RS-6';
    $pcm['rsStatuses'][] = 'multiple_parcels';

    $pcm['requiresReview'] = true;

    // Interactive governance pause
    $pcm['blocksCommit'] = true;
}

// -----------------------------------------------------
// RS-7 — Unresolved Parcel
// -----------------------------------------------------

if (
    ($locationValidation['isMaricopa'] ?? false) === true
    && ($locationValidation['parcelStatus'] ?? '') !== 'resolved'
    && ($locationValidation['parcelStatus'] ?? '') !== 'multiple_matches'
    && !in_array($pcm['pc'], ['PC-3', 'PC-4'], true)
) {

    $pcm['rs'][]         = 'RS-7';
    $pcm['rsStatuses'][] = 'unresolved_parcel';

    $pcm['blocksCommit'] = true;
}

// =====================================================
// RS-0 — Acceptable Governance State
// =====================================================

if (empty($pcm['rs'])) {

    $pcm['rs'][]         = 'RS-0';
    $pcm['rsStatuses'][] = 'acceptable';
}

// =====================================================
// Final Governance Resolution
// =====================================================

$pcm['readyForCommit'] = !$pcm['blocksCommit'];

#endregion

#region SECTION 09 - PREPARATION FOR PROPOSAL GENERATION

// Defensive variable initialization
$meta               = $meta               ?? [];
$resolution         = $resolution         ?? [];
$persistence        = $persistence        ?? [];
$data               = $data               ?? [];
$locationValidation = $locationValidation ?? [];
$aiData             = $aiData             ?? ['confidence' => 85];

#endregion

#region SECTION 10 — 📦 PROPOSAL SNAPSHOT CREATION

// Defensive initialization — critical for stability
$meta         = $meta         ?? [];
$resolution   = $resolution   ?? [];
$persistence  = $persistence  ?? [];
$data         = $data         ?? [];
$locationValidation = $locationValidation ?? [];

// Add this line above the call
$parcelImages = [];

$proposalResult = createProposalSnapshot(
    $rawInputOriginal,
    $parsed,
    $pcm,
    $locationValidation,
    $data,
    $meta,
    $resolution,
    $persistence,
    $activitySessionId,
    $parcelImages          // ← NEW: Pass the parcel images here
);

$proposalId = $proposalResult['proposalId'];

#endregion

#region SECTION 11 — 📄 PROPOSAL PDF REPORT GENERATION

$reportPath = generateProposalReport($proposalId, $proposalResult['snapshot']);

$reportUrl = $reportPath && file_exists($reportPath) 
    ? "/skyesoft/reports/proposals/{$proposalId}.pdf" 
    : null;

#endregion

#region SECTION 12 - FINAL OUTPUT — Response Builder

    require_once __DIR__ . '/detectAndProposeContact.response.php';

    // Do NOT echo JSON here — let response.php handle the full output
    global $proposalId, $reportUrl, $parsed, $pcm, $data, $meta, $resolution, $persistence, $aiData, $rawInputOriginal, $activitySessionId;

#endregion