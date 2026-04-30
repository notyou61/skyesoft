<?php
// =====================================================
// Skyesoft — detectAndProposeContact.php
// =====================================================

#region SECTION 1 — Runtime Configuration
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
#endregion

#region SECTION 2 — Helpers

function jsonError(string $msg): void {
    echo json_encode([
        'status'  => 'error',
        'message' => $msg
    ]);
    exit;
}

#endregion

#region SECTION 3 — Input Resolution

$input = json_decode(file_get_contents('php://input'), true);

$rawInput          = trim($input['input'] ?? '');
$activitySessionId = $input['activitySessionId'] ?? 'no_session';

if (empty($rawInput)) {
    jsonError('No input provided');
}

#endregion

#region SECTION 4 — AI Prompt Construction

$systemPrompt = <<<EOT
You are a strict data extraction engine.

CRITICAL RULES:
- Respond ONLY with valid JSON
- Do NOT include explanations, text, markdown, or comments
- Output must begin with { and end with }
- If unsure, leave fields empty
- Never summarize

Return EXACTLY this structure:

{
  "intent": "contact_proposal",
  "confidence": 90,
  "parsed": {
    "entity": { "name": "" },
    "contact": {
      "firstName": "",
      "lastName": "",
      "salutation": "Mr",
      "title": "",
      "primaryPhone": "",
      "email": ""
    },
    "location": {
      "address": "",
      "city": "",
      "state": "",
      "zip": ""
    }
  }
}

Extraction Rules:
- Detect contact from messy email signatures or pasted text
- Split full name into firstName / lastName
- Default salutation to "Mr" if unknown
- Normalize phone to (XXX) XXX-XXXX
- Infer company from email domain if needed
- Extract address, city, state, zip if present

If no contact-like data exists, return:
{
  "intent": "none",
  "confidence": 0,
  "parsed": {}
}
EOT;

$extractionPrompt = "Extract structured contact data from the following text:\n\n{$rawInput}";

#endregion

#region SECTION 5 — AI Request Execution

if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/utils/envLoader.php';
}
skyesoftLoadEnv();

$apiKey = getenv("OPENAI_API_KEY");
$googleApiKey = skyesoftGetEnv("GOOGLE_MAPS_BACKEND_API_KEY") ?? getenv("GOOGLE_MAPS_BACKEND_API_KEY");

if (!$apiKey) {
    jsonError('OPENAI_API_KEY not found');
}
if (!$googleApiKey) {
    error_log('[ENV ERROR] GOOGLE_MAPS_BACKEND_API_KEY missing or empty');
} elseif (strlen($googleApiKey) < 20) {
    error_log('[ENV WARNING] GOOGLE_MAPS_BACKEND_API_KEY looks invalid (too short)');
} else {
    error_log('[ENV OK] GOOGLE_MAPS_BACKEND_API_KEY loaded successfully');
}

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
    CURLOPT_HTTPHEADER     => [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 20
]);

$response = curl_exec($ch);
curl_close($ch);

if ($response === false) {
    jsonError('AI request failed');
}

$decoded = json_decode($response, true);
$content = $decoded['choices'][0]['message']['content'] ?? '';

if (!$content) {
    jsonError('Invalid AI response format');
}

preg_match('/\{.*\}/s', $content, $matches);
if (empty($matches[0])) {
    jsonError('Invalid AI response format');
}

$aiData = json_decode($matches[0], true);

if (!$aiData || !isset($aiData['parsed'])) {
    jsonError('Invalid AI response format');
}

#endregion

#region SECTION 6 — Intent Validation

if (($aiData['intent'] ?? '') !== 'contact_proposal') {
    echo json_encode([
        'status'  => 'reject',
        'message' => 'Not recognized as a contact signature.'
    ]);
    exit;
}

#endregion

#region SECTION 7 — Data Processing & Enhancement (Google → Census → Parcel → Jurisdiction)

// --------------------------------------------------
// 🔧 Normalize + Infer + Validate
// --------------------------------------------------
$parsed = $aiData['parsed'] ?? [];
$parsed = normalizeParsed($parsed);
$parsed = inferMissingFields($parsed);

$missing = validateParsed($parsed);

$issues = [];
$flags  = [];
$meta   = [];
$parcel = null;
$jurisdiction = null;
$mca = null;
$googlePlace = null;


// --------------------------------------------------
// 📍 Build Clean Address
// --------------------------------------------------
$fullAddress = trim(implode(' ', array_filter([
    $parsed['location']['address'] ?? '',
    $parsed['location']['city'] ?? '',
    $parsed['location']['state'] ?? '',
    $parsed['location']['zip'] ?? ''
])));

error_log('[EOP ADDRESS] ' . $fullAddress);


// --------------------------------------------------
// 🌐 1. Google Places (MANDATORY for ELC — PlaceId enforced)
// --------------------------------------------------
if (!empty($fullAddress) && strlen($fullAddress) > 10 && !empty($googleApiKey)) {
    $googlePlace = resolveGooglePlace($fullAddress, $googleApiKey);

    if ($googlePlace) {
        $parsed['location']['locationPlaceId'] = $googlePlace['placeId'];
        $parsed['location']['latitude']        = $googlePlace['lat'] ?? null;
        $parsed['location']['longitude']       = $googlePlace['lng'] ?? null;
        $parsed['location']['formattedAddress'] = $googlePlace['formattedAddress'] ?? $fullAddress; // NEW

        $meta['google_place'] = $googlePlace;
        $meta['google_source'] = 'places_findplacefromtext';
    } else {
        $issues[] = 'google_place_not_resolved';
        $flags[]  = 'location_unverified';
    }
} elseif (!empty($fullAddress)) {
    $issues[] = 'google_place_skipped_no_key';
    $flags[]  = 'location_unverified_no_google';
}


// --------------------------------------------------
// 🌍 2. Geographic Resolution (Census)
// --------------------------------------------------
$geo = null;
if (!empty($parsed['location']['address'])) {
    $geo = resolveGeographyFromAddress($fullAddress);

    if ($geo) {
        $meta['geo']        = $geo;
        $meta['geo_source'] = 'census';

        if (!empty($geo['county'])) {
            $parsed['location']['county'] = trim($geo['county']);
        }
        if (!empty($geo['state'])) {
            $parsed['location']['state'] = $geo['state'];
            $meta['geo_overrides']['state'] = true;
        }
    } else {
        $issues[] = 'geography_not_resolved';
    }
}


// --------------------------------------------------
// 🏆 3. Parcel Resolution (Maricopa Only)
// --------------------------------------------------
$county = strtoupper(trim($parsed['location']['county'] ?? ''));
$state  = strtoupper(trim($parsed['location']['state'] ?? ''));

$isMaricopa = ($county === 'MARICOPA' && $state === 'AZ');
$meta['is_maricopa'] = $isMaricopa;

if ($isMaricopa && !empty($parsed['location']['address']) && !empty($parsed['location']['city'])) {
    $lookupAddress = $geo['matchedAddress'] ?? $fullAddress;
    $meta['parcel_lookup_address'] = $lookupAddress;

    $mca = lookupMaricopaParcel($lookupAddress);

    if ($mca && !empty($mca['apn'])) {
        $apnRaw = preg_replace('/[^A-Za-z0-9]/', '', $mca['apn']);

        $parcel = [
            'apnRaw'     => $apnRaw,
            'apnDisplay' => $mca['apn'],
            'source'     => $mca['source'],
            'confidence' => 95
        ];

        $meta['parcel'] = $parcel;
        $jurisdiction   = $mca['jurisdiction'] ?? null;
    } else {
        $issues[] = 'parcel_not_found';
    }
}


// --------------------------------------------------
// 🏛️ 4. Jurisdiction (Authoritative Only)
// --------------------------------------------------
if ($isMaricopa && empty($jurisdiction)) {
    $jurisdiction = resolveMaricopaJurisdiction($lookupAddress ?? $fullAddress);
}

if ($isMaricopa && empty($jurisdiction)) {
    $issues[] = 'maricopa_jurisdiction_not_resolved';
}

$meta['jurisdiction'] = $jurisdiction;


// --------------------------------------------------
// 🧠 FINAL VALIDATION — Enforce PlaceId
// --------------------------------------------------
if (empty($parsed['location']['locationPlaceId'] ?? '')) {
    $issues[] = 'placeId_required';
}

if (!empty($missing)) {
    $issues[] = 'missing_required_fields';
    $meta['missing'] = $missing;
}

if ($isMaricopa) {
    if (empty($parcel))  $issues[] = 'maricopa_parcel_required';
    if (empty($jurisdiction)) $issues[] = 'maricopa_jurisdiction_required';
}

#endregion

#region SECTION 8 — Status-Aware Success Response

$status = 'proposed';
if (!empty($missing)) {
    $status = 'reject';
} elseif (!empty($issues)) {
    $status = 'partial';
}

echo json_encode([
    'status'       => $status,
    'confidence'   => $aiData['confidence'] ?? 82,
    'parsed'       => $parsed,
    'source'       => 'ai_eop_signature',
    'parcel'       => $parcel,
    'jurisdiction' => $jurisdiction,
    'flags'        => !empty($flags) ? $flags : null,
    'meta'         => !empty($meta) ? $meta : null,
    'issues'       => !empty($issues) ? $issues : null,
    'activitySessionId' => $activitySessionId,
    'raw_preview'  => substr($rawInput, 0, 250)
]);

error_log("[EOP FINAL] Status: $status | PlaceID: " . ($parsed['location']['locationPlaceId'] ?? 'MISSING') .
          " | Parcel: " . ($parcel ? 'YES' : 'NO') .
          " | Jurisdiction: " . ($jurisdiction ?: 'null'));

#endregion

#region SECTION 10 - Helper Functions

function normalizeParsed(array $parsed): array
{
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

    return $parsed;
}

function inferMissingFields(array $parsed): array
{
    if (empty($parsed['entity']['name'] ?? '') && !empty($parsed['contact']['email'] ?? '')) {
        $email = $parsed['contact']['email'];
        $atPos = strpos($email, '@');
        if ($atPos !== false) {
            $domain = substr($email, $atPos + 1);
            $dotPos = strpos($domain, '.');
            if ($dotPos !== false) {
                $company = substr($domain, 0, $dotPos);
                $parsed['entity']['name'] = ucwords(str_replace(['-', '_'], ' ', $company));
            }
        }
    }

    if (empty($parsed['contact']['salutation'] ?? '')) {
        $parsed['contact']['salutation'] = 'Mr';
    }

    return $parsed;
}

function validateParsed(array $parsed): array
{
    $missing = [];
    if (empty($parsed['contact']['firstName'] ?? '')) $missing[] = 'firstName';
    if (empty($parsed['contact']['lastName']  ?? '')) $missing[] = 'lastName';
    if (empty($parsed['contact']['email']     ?? '')) $missing[] = 'email';
    return $missing;
}

/**
 * Google Places — cURL consistent + richer fields
 */
function resolveGooglePlace(string $address, string $apiKey): ?array
{
    if (empty($address) || empty($apiKey)) return null;

    $url = "https://maps.googleapis.com/maps/api/place/findplacefromtext/json" .
           "?input=" . urlencode($address) .
           "&inputtype=textquery" .
           "&fields=place_id,geometry,formatted_address" .   // ← improved
           "&key=" . $apiKey;

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
        error_log("[GOOGLE PLACES] Request failed | HTTP $httpCode for: $address");
        return null;
    }

    $data = json_decode($response, true);

    if (($data['status'] ?? '') !== 'OK' || empty($data['candidates'][0])) {
        error_log('[GOOGLE PLACES] No candidates | Status: ' . ($data['status'] ?? 'UNKNOWN'));
        return null;
    }

    $candidate = $data['candidates'][0];

    return [
        'placeId'          => $candidate['place_id'],
        'lat'              => $candidate['geometry']['location']['lat'] ?? null,
        'lng'              => $candidate['geometry']['location']['lng'] ?? null,
        'formattedAddress' => $candidate['formatted_address'] ?? null
    ];
}

function resolveGeographyFromAddress(string $address): ?array
{
    if (!$address) return null;

    $url = "https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress" .
           "?address=" . urlencode($address) .
           "&benchmark=Public_AR_Current" .
           "&vintage=Current_Current" .
           "&format=json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) return null;

    $data = json_decode($response, true);
    $result = $data['result']['addressMatches'][0] ?? null;

    if (!$result) return null;

    $geo = $result['geographies'] ?? [];
    $countyRaw = $geo['Counties'][0]['NAME'] ?? null;
    $state     = $geo['States'][0]['STUSAB'] ?? null;

    $county = $countyRaw ? str_replace(' County', '', $countyRaw) : null;

    return [
        'county'         => $county,
        'state'          => $state,
        'matchedAddress' => $result['matchedAddress'] ?? null
    ];
}

function lookupMaricopaParcel(string $address): ?array
{
    $url = "https://gis.mcassessor.maricopa.gov/arcgis/rest/services/Parcels/MapServer/0/query";

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

function resolveMaricopaJurisdiction(string $address): ?string {
    return null; // TODO: dedicated layer
}

#endregion