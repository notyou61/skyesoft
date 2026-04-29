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
if (!$apiKey) {
    jsonError('OPENAI_API_KEY not found');
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

// Extract JSON
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

#region SECTION 7 — Data Processing & Enhancement (Geo → Parcel FINAL)

// --------------------------------------------------
// 🔧 Normalize + Infer + Validate
// --------------------------------------------------
$parsed = $aiData['parsed'] ?? [];
$parsed = normalizeParsed($parsed);
$parsed = inferMissingFields($parsed);

$missing = validateParsed($parsed);

$issues = [];
$meta   = [];
$flags  = [];
$parcel = null;


// --------------------------------------------------
// 📍 Build Clean Address (Census-safe)
// --------------------------------------------------
$fullAddress = trim(implode(' ', array_filter([
    $parsed['location']['address'] ?? '',
    $parsed['location']['city'] ?? '',
    $parsed['location']['state'] ?? '',
    $parsed['location']['zip'] ?? ''
])));

error_log('[EOP ADDRESS] ' . $fullAddress);


// --------------------------------------------------
// 🌍 Geographic Resolution (Census)
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

            $meta['geo_overrides'] = $meta['geo_overrides'] ?? [];
            $meta['geo_overrides']['state'] = true;
        }

    } else {

        $issues[] = 'geography_not_resolved';
        $meta['geo_error'] = 'Census lookup failed or no match found';

        error_log('[CENSUS FAIL] ' . $fullAddress);
    }

    error_log('[CENSUS RESULT] ' . json_encode($geo));
}


// --------------------------------------------------
// 🏆 Parcel Resolution (Authoritative — AFTER Census)
// --------------------------------------------------
if (
    !empty($parsed['location']['address']) &&
    !empty($parsed['location']['city']) &&
    !empty($parsed['location']['state']) &&
    !empty($parsed['location']['county'])
) {

    if (strtolower($parsed['location']['county']) === 'maricopa') {

        $lookupAddress = $fullAddress;

        $meta['parcel_lookup_address'] = $lookupAddress;

        error_log('[PARCEL LOOKUP] ' . $lookupAddress);

        $mca = lookupMaricopaParcel($lookupAddress);

        error_log('[PARCEL RAW RESULT] ' . json_encode($mca));

        if ($mca && !empty($mca['apn'])) {

            $parcel = [
                'apn'        => $mca['apn'],
                'source'     => $mca['source'] ?? 'mca',
                'confidence' => $mca['confidence'] ?? 100
            ];

            $meta['parcel'] = $parcel;

            error_log('[PARCEL FOUND] ' . json_encode($parcel));

        } else {

            // Only flag as issue if we EXPECTED a result
            $issues[] = 'parcel_not_found';

            error_log('[PARCEL FAIL] ' . $lookupAddress);
        }
    }
}


// --------------------------------------------------
// 🧠 Validation Issues
// --------------------------------------------------
if (!empty($missing)) {
    $issues[] = 'missing_required_fields';
    $meta['missing'] = $missing;
}

#endregion

#region SECTION 8 — Success Response (Updated: Parcel-Aware)

echo json_encode([
    'status'     => 'proposed',
    'confidence' => $aiData['confidence'] ?? 82,
    // Core structured result
    'parsed'     => $parsed,
    // Data source
    'source'     => 'ai_eop_signature',
    // 🔑 NEW: authoritative parcel data (replaces needs_parcel)
    'parcel'     => $parcel,
    // 🔧 Flags (future-proof, keep even if empty)
    'flags'      => !empty($flags) ? $flags : null,
    // 🧠 Metadata (debug + enrichment visibility)
    'meta'       => !empty($meta) ? $meta : null,
    // ⚠ Issues (missing fields, lookup failures, etc.)
    'issues'     => !empty($issues) ? $issues : (!empty($missing) ? $missing : null),
    // Session tracking
    'activitySessionId' => $activitySessionId,
    // Preview for UI
    'raw_preview' => substr($rawInput, 0, 250)
]);

// Debug logs (keep during development)
error_log('[EOP ADDRESS] ' . ($fullAddress ?? 'N/A'));
error_log('[EOP PARCEL] ' . json_encode($parcel));

#endregion

#region SECTION 10 - Helper Functions

function normalizeParsed(array $parsed): array
{
    if (!empty($parsed['contact']['email'])) {
        $parsed['contact']['email'] = strtolower(trim($parsed['contact']['email']));
    }

    if (!empty($parsed['contact']['primaryPhone'])) {
        $phone = preg_replace('/[^0-9]/', '', $parsed['contact']['primaryPhone']);
        if (strlen($phone) === 10) {
            $parsed['contact']['primaryPhone'] = '(' . substr($phone, 0, 3) . ') ' .
                                                 substr($phone, 3, 3) . '-' . substr($phone, 6);
        }
    }

    if (!empty($parsed['location']['state'])) {
        $parsed['location']['state'] = strtoupper($parsed['location']['state']);
    }

    return $parsed;
}

function inferMissingFields(array $parsed): array
{
    // Safely infer company name from email (avoid parser confusion)
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

    if ($response === false) {
        error_log('[CENSUS] CURL ERROR');
        return null;
    }

    $data = json_decode($response, true);
    $result = $data['result']['addressMatches'][0] ?? null;

    if (!$result) {
        return null;
    }

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
    // --------------------------------------------------
    // 🔹 Step 1: Normalize Address (critical)
    // --------------------------------------------------
    $normalized = strtolower(trim($address));
    $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);

    $query = urlencode($address);

    // --------------------------------------------------
    // 🔹 Step 2: Request MCA search page (with timeout)
    // --------------------------------------------------
    $url = "https://mcassessor.maricopa.gov/search/property/?q={$query}";

    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header'  => "User-Agent: Skyesoft/1.0\r\n"
        ]
    ]);

    $html = @file_get_contents($url, false, $context);

    if (!$html) {
        error_log('[MCA] Request failed for: ' . $address);
        return null;
    }

    // --------------------------------------------------
    // 🔹 Step 3: Extract ALL APNs (not just first match)
    // --------------------------------------------------
    preg_match_all('/\b\d{3}-\d{2}-\d{3}\b/', $html, $matches);

    if (empty($matches[0])) {
        error_log('[MCA] No APN found for: ' . $address);
        return null;
    }

    $apns = array_unique($matches[0]);

    // --------------------------------------------------
    // 🔹 Step 4: Confidence logic
    // --------------------------------------------------
    $confidence = count($apns) === 1 ? 100 : 80;

    return [
        'apn'        => $apns[0],   // best match (first for now)
        'all_apns'   => $apns,      // keep for debugging
        'source'     => 'mca_scrape',
        'confidence' => $confidence
    ];
}

#endregion