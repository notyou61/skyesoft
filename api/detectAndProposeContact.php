<?php
// =====================================================
// Skyesoft — detectAndProposeContact.php
// -----------------------------------------------------
// Purpose:
//   STEP 1 — EOP Contact Proposal (AI Parsing)
//
// Description:
//   Accepts raw pasted contact data → sends to AI →
//   returns structured proposal JSON (no DB interaction)
//
// Notes:
//   - No auth required (early pipeline stage)
//   - No config dependencies
//   - Must return JSON ONLY (no HTML errors)
// =====================================================

#region SECTION 1 — Runtime Configuration
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Prevent HTML output
#endregion

#region SECTION 2 — Helpers

// JSON Error Response
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

$extractionPrompt = <<<EOT
Extract structured contact data from the following text:

{$rawInput}
EOT;

#endregion

#region SECTION 5 — AI Request Execution (Direct OpenAI)

// ─────────────────────────────────────────
// 🌍 Load environment (Skyesoft standard)
// ─────────────────────────────────────────
if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/utils/envLoader.php';
}
skyesoftLoadEnv();

// --------------------------------------------------
// 🔑 Retrieve API Key
// --------------------------------------------------
$apiKey = getenv("OPENAI_API_KEY");

if (!$apiKey) {
    jsonError('OPENAI_API_KEY not found');
}

// --------------------------------------------------
// 🧠 Build OpenAI request payload
// --------------------------------------------------
$payload = [
    "model" => "gpt-4.1-mini",
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $extractionPrompt]
    ],
    "temperature" => 0
];

// --------------------------------------------------
// 📡 Execute request
// --------------------------------------------------
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

if ($response === false) {
    error_log('[EOP] CURL ERROR: ' . curl_error($ch));
    jsonError('AI request failed');
}

curl_close($ch);

// --------------------------------------------------
// 🔍 Extract message content from OpenAI response
// --------------------------------------------------
$decoded = json_decode($response, true);

$content = $decoded['choices'][0]['message']['content'] ?? '';

if (!$content) {
    error_log('[EOP] Empty AI content: ' . $response);
    jsonError('Invalid AI response format');
}

// IMPORTANT: overwrite response for SECTION 6
$response = $content;

#endregion

#region SECTION 6 — AI Response Processing

error_log('[EOP RAW AI RESPONSE] ' . $response);

// Extract JSON
preg_match('/\{.*\}/s', $response, $matches);

if (empty($matches[0])) {
    error_log('[EOP] No JSON found');
    jsonError('Invalid AI response format');
}

$aiData = json_decode($matches[0], true);

if (!$aiData || !isset($aiData['parsed'])) {
    error_log('[EOP] JSON decode failed');
    jsonError('Invalid AI response format');
}

#endregion

#region SECTION 7 — Intent Validation

if (!$aiData || ($aiData['intent'] ?? '') !== 'contact_proposal') {
    echo json_encode([
        'status'  => 'reject',
        'message' => 'Not recognized as a contact signature.'
    ]);
    exit;
}

#endregion

#region SECTION 8 — Data Processing & Enhancement

$parsed = $aiData['parsed'] ?? [];
$parsed = normalizeParsed($parsed);
$parsed = inferMissingFields($parsed);

$missing = validateParsed($parsed);

// Maricopa County parcel hint
$needsParcel = false;
$parcelMsg = '';
if (!empty($parsed['location']['city'])) {
    $city = strtolower($parsed['location']['city']);
    if (str_contains($city, 'phoenix') || str_contains($city, 'peoria') ||
        str_contains($city, 'glendale') || str_contains($city, 'scottsdale') ||
        str_contains($city, 'tempe') || str_contains($city, 'mesa') ||
        str_contains($city, 'chandler')) {
        $needsParcel = true;
        $parcelMsg = 'Maricopa County parcel lookup recommended.';
    }
}

#endregion

#region SECTION 9 — Success Response

echo json_encode([
    'status'           => 'proposed',
    'confidence'       => $aiData['confidence'] ?? 82,
    'parsed'           => $parsed,
    'source'           => 'ai_eop_signature',
    'needs_parcel'     => $needsParcel,
    'message'          => $parcelMsg,
    'issues'           => !empty($missing) ? $missing : null,
    'activitySessionId'=> $activitySessionId,
    'raw_preview'      => substr($rawInput, 0, 250)
]);

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
            $parsed['contact']['primaryPhone'] = '(' . substr($phone,0,3) . ') ' .
                                                 substr($phone,3,3) . '-' . substr($phone,6);
        }
    }

    if (!empty($parsed['location']['state'])) {
        $parsed['location']['state'] = strtoupper($parsed['location']['state']);
    }

    return $parsed;
}

function inferMissingFields(array $parsed): array
{
    if (empty($parsed['entity']['name']) && !empty($parsed['contact']['email'])) {
        $domain = explode('@', $parsed['contact']['email'])[1] ?? '';
        if ($domain) {
            $company = explode('.', $domain)[0];
            $parsed['entity']['name'] = ucwords(str_replace(['-', '_'], ' ', $company));
        }
    }

    if (empty($parsed['contact']['salutation'])) {
        $parsed['contact']['salutation'] = 'Mr.';
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

#endregion