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

$rawInput          = trim($input['input'] ?? '');
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

#region SECTION 05.5 — 🛡️ Schema Enforcement + Fallback Recovery

// -------------------------------------------------
// 🧩 Ensure Full Schema (prevents partial AI output)
// -------------------------------------------------
$parsed = $aiData['parsed'] ?? [];

$parsed = array_merge_recursive([
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
], $parsed);

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

#region SECTION 06 — 🔍 Intent Validation

if (($aiData['intent'] ?? '') !== 'contact_proposal') {
    echo json_encode([
        'status'  => 'reject',
        'message' => 'Input not recognized as a contact signature.',
        'success' => false
    ]);
    exit;
}

#endregion

#region SECTION 07 — 📞 Assign Primary + Secondary Phones

$phones = extractPhones($rawInput);

// Normalize extracted phones into unique list (by raw)
$uniquePhones = [];
foreach ($phones as $p) {
    if (!empty($p['raw']) && !isset($uniquePhones[$p['raw']])) {
        $uniquePhones[$p['raw']] = $p;
    }
}
$phones = array_values($uniquePhones);

// Prefer "Cell" as primary if label exists
if (stripos($rawInput, 'cell') !== false && count($phones) >= 2) {
    [$phones[0], $phones[1]] = [$phones[1], $phones[0]];
}

// -----------------------------
// PRIMARY PHONE
// -----------------------------
if (!empty($phones[0])) {

    $existingPrimary = $parsed['contact']['primaryPhoneRaw'] ?? null;

    if (empty($existingPrimary)) {
        $parsed['contact']['primaryPhone']    = $phones[0]['formatted'];
        $parsed['contact']['primaryPhoneRaw'] = $phones[0]['raw'];
    }
}

// -----------------------------
// SECONDARY PHONE
// -----------------------------
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

#endregion

#region SECTION 08 — 🧩 Data Processing & Enrichment

// -------------------------------------------------
// 🧠 SALUTATION (safe inference)
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
// 🧾 DATA INTEGRITY STATUS (DIS)
// -------------------------------------------------
$dataIntegrityStatus = [
    'status' => 'complete',
    'missing' => []
];

$missing = validateParsed($parsed);

if (!empty($missing)) {
    $dataIntegrityStatus['status'] = 'incomplete';
    $dataIntegrityStatus['missing'] = $missing;
}

// -------------------------------------------------
// 📍 ADDRESS PREP
// -------------------------------------------------
$fullAddress = trim(implode(' ', array_filter([
    $parsed['location']['address'] ?? '',
    $parsed['location']['city'] ?? '',
    $parsed['location']['state'] ?? '',
    $parsed['location']['zip'] ?? ''
])));

$lookupAddress = sanitizeAddressForLookup($fullAddress);

// -------------------------------------------------
// 🌍 GOOGLE LOCATION VALIDATION
// -------------------------------------------------
$locationValidation = [
    'status' => 'invalid',
    'confidence' => 0,
    'placeIdResolved' => false,
    'latLonResolved' => false,
    'isMaricopa' => false,
    'apnResolved' => false,
    'jurisdictionResolved' => false,
    'issues' => []
];

$googleData = null;

if (!empty($googleApiKey) && !empty($parsed['location']['address']) && !empty($parsed['location']['city'])) {

    $googleData = validateLocationWithGoogle([
        'address' => $lookupAddress,
        'city'    => $parsed['location']['city'],
        'state'   => $parsed['location']['state'],
        'zip'     => $parsed['location']['zip'] ?? ''
    ]);

    if (empty($googleData['placeId'])) {
        $googleData = validateLocationWithGoogle([
            'address' => $fullAddress,
            'city'    => $parsed['location']['city'],
            'state'   => $parsed['location']['state'],
            'zip'     => $parsed['location']['zip'] ?? ''
        ]);
    }

    if (!empty($googleData['placeId'])) {

        $parsed['location']['locationPlaceId']  = $googleData['placeId'];
        $parsed['location']['latitude']         = $googleData['lat'] ?? null;
        $parsed['location']['longitude']        = $googleData['lng'] ?? null;
        $parsed['location']['formattedAddress'] = str_replace(', USA', '', $googleData['address'] ?? $fullAddress);

        $locationValidation['status'] = 'valid';
        $locationValidation['confidence'] = 90;
        $locationValidation['placeIdResolved'] = true;
        $locationValidation['latLonResolved'] = true;

    } else {
        $locationValidation['issues'][] = 'google_place_not_resolved';
    }
}

// -------------------------------------------------
// 🗺️ CENSUS GEO
// -------------------------------------------------
$geo = resolveGeographyFromAddress($parsed['location']['formattedAddress'] ?? $lookupAddress);

if ($geo) {
    if (!empty($geo['county'])) $parsed['location']['county'] = trim($geo['county']);
    if (!empty($geo['state']))  $parsed['location']['state']  = $geo['state'];
}

// -------------------------------------------------
// 🌵 MARICOPA LOGIC
// -------------------------------------------------
$county = strtoupper(trim($parsed['location']['county'] ?? ''));
$state  = strtoupper(trim($parsed['location']['state'] ?? ''));

$isMaricopa = ($county === 'MARICOPA' && $state === 'AZ');

$locationValidation['isMaricopa'] = $isMaricopa;

$parcel = null;
$parcelCandidates = null;
$jurisdiction = null;

if ($isMaricopa && !empty($parsed['location']['address']) && !empty($parsed['location']['city'])) {

    $parcelLookupAddress = $parsed['location']['formattedAddress'] ?? $lookupAddress;

    $mca = lookupMaricopaParcel($parcelLookupAddress);

    if ($mca && !empty($mca['apn'])) {

        $apnRaw = preg_replace('/[^A-Za-z0-9]/', '', $mca['apn']);

        $parcel = [
            'apnRaw'     => $apnRaw,
            'apnDisplay' => formatAPN($apnRaw),
            'source'     => $mca['source'],
            'confidence' => 95
        ];

        $locationValidation['apnResolved'] = true;

        $jurisdiction = $mca['jurisdiction'] ?? null;

    } else {
        $locationValidation['issues'][] = 'parcel_not_found';
    }
}

if (!empty($jurisdiction)) {
    $jurisdiction = ucwords(strtolower(trim($jurisdiction)));
    $locationValidation['jurisdictionResolved'] = true;
}

if ($isMaricopa && !$locationValidation['apnResolved']) {
    $locationValidation['issues'][] = 'maricopa_parcel_required';
}

if ($isMaricopa && !$locationValidation['jurisdictionResolved']) {
    $locationValidation['issues'][] = 'maricopa_jurisdiction_required';
}

// -------------------------------------------------
// 🔁 DUPLICATE DETECTION (DB)
// -------------------------------------------------
$duplicate = evaluateDuplicate($parsed, $pdo);

#endregion

#region SECTION 09 — 🧠 PCM + Final Response

// -------------------------------------------------
// 🔁 DUPLICATE (placeholder)
// -------------------------------------------------
$duplicate = [
    'status' => 'none'
];

// -------------------------------------------------
// 🧠 PCM (UNIFIED DECISION OBJECT)
// -------------------------------------------------
if ($dataIntegrityStatus['status'] !== 'complete') {

    $pcm = [
        'status' => 'incomplete',
        'readyForCommit' => false,
        'requiresReview' => true,
        'blocksCommit' => true,
        'action' => 'resolve_missing_fields'
    ];

} elseif ($duplicate['status'] === 'exact') {

    $pcm = [
        'status' => 'duplicate_contact',
        'readyForCommit' => false,
        'requiresReview' => false,
        'blocksCommit' => true,
        'action' => 'reject_duplicate'
    ];

} else {

    $pcm = [
        'status' => 'new_elc',
        'readyForCommit' => true,
        'requiresReview' => false,
        'blocksCommit' => false,
        'action' => 'insert_new'
    ];
}

// -------------------------------------------------
// 📦 FINAL OUTPUT
// -------------------------------------------------
echo json_encode([
    'status' => 'proposed',
    'confidence' => $aiData['confidence'] ?? 82,

    'parsed' => $parsed,
    'dataIntegrityStatus' => $dataIntegrityStatus,
    'locationValidation' => $locationValidation,

    'parcel' => $parcel,
    'parcelCandidates' => $parcelCandidates,
    'jurisdiction' => $jurisdiction,

    'duplicate' => $duplicate,
    'pcm' => $pcm,

    'activitySessionId' => $activitySessionId,
    'raw_preview' => substr($rawInput, 0, 250),

    'success' => true
]);

#endregion

#region SECTION 10 — 🛠️ Internal Utilities

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
    if (empty($parsed['contact']['firstName'] ?? '')) $missing[] = 'firstName';
    if (empty($parsed['contact']['lastName']  ?? '')) $missing[] = 'lastName';

    $hasEmail = !empty($parsed['contact']['email'] ?? '');
    $hasPhone = !empty($parsed['contact']['primaryPhoneRaw'] ?? '');

    if (!$hasEmail && !$hasPhone) {
        $missing[] = 'contactMethod';
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

    $county = $countyRaw ? str_replace(' County', '', $countyRaw) : null;

    return [
        'county'         => $county,
        'state'          => $state,
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
// Infer Location Name - if locationName is missing, create it from address + city
function inferLocationName(array $parsed): array {
    if (!empty($parsed['location']['locationName'])) {
        $parsed['location']['locationNameConfirmed'] = true;
        return $parsed;
    }

    $address = trim($parsed['location']['address'] ?? '');
    $city    = trim($parsed['location']['city'] ?? '');

    if ($address && $city) {
        $parsed['location']['locationName'] = $address . ' - ' . $city;
        $parsed['location']['locationNameInferred'] = true;
        $parsed['location']['locationNameConfirmed'] = false;
    }

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

    $email = strtolower(trim($parsed['contact']['email'] ?? ''));
    $phone = preg_replace('/\D/', '', $parsed['contact']['primaryPhoneRaw'] ?? '');
    $first = strtolower(trim($parsed['contact']['firstName'] ?? ''));
    $last  = strtolower(trim($parsed['contact']['lastName'] ?? ''));
    $entityName = strtolower(trim($parsed['entity']['name'] ?? ''));

    // -------------------------------------------------
    // 1. Exact Email Match (STRONGEST)
    // -------------------------------------------------
    if (!empty($email)) {
        $stmt = $pdo->prepare("
            SELECT contactId, entityId
            FROM tblContacts
            WHERE LOWER(contactEmail) = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status' => 'exact',
                'contactId' => $row['contactId'],
                'entityId' => $row['entityId'],
                'matchType' => 'email'
            ];
        }
    }

    // -------------------------------------------------
    // 2. Phone Match
    // -------------------------------------------------
    if (!empty($phone)) {
        $stmt = $pdo->prepare("
            SELECT contactId, entityId
            FROM tblContacts
            WHERE contactPrimaryPhoneRaw = :phone
            LIMIT 1
        ");
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status' => 'possible',
                'contactId' => $row['contactId'],
                'entityId' => $row['entityId'],
                'matchType' => 'phone'
            ];
        }
    }

    // -------------------------------------------------
    // 3. Name + Entity Match
    // -------------------------------------------------
    if ($first && $last && $entityName) {

        $stmt = $pdo->prepare("
            SELECT c.contactId, c.entityId
            FROM tblContacts c
            JOIN tblEntities e ON c.entityId = e.entityId
            WHERE LOWER(c.contactFirstName) = :first
              AND LOWER(c.contactLastName) = :last
              AND LOWER(e.entityName) = :entity
            LIMIT 1
        ");
        $stmt->execute([
            'first' => $first,
            'last' => $last,
            'entity' => $entityName
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'status' => 'possible',
                'contactId' => $row['contactId'],
                'entityId' => $row['entityId'],
                'matchType' => 'name_entity'
            ];
        }
    }

    return [
        'status' => 'none',
        'contactId' => null,
        'entityId' => null,
        'matchType' => null
    ];
}
// Get PDO connection (singleton)
function getPDO(): PDO {

    static $pdo = null;

    if ($pdo === null) {

        $dsn = "mysql:host=localhost;dbname=your_db;charset=utf8mb4";
        $user = "your_user";
        $pass = "your_pass";

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    return $pdo;
}

#endregion