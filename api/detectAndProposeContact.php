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
You are a strict JSON-only contact extraction engine.

You MUST return ONLY valid JSON. No explanations, no extra text, no markdown, no "Here is...", nothing else.

{
  "intent": "contact_proposal",
  "confidence": 85,
  "parsed": {
    "entity": { "name": "Company Name" },
    "contact": {
      "firstName": "Jennifer",
      "lastName": "Hammond",
      "salutation": "Ms.",
      "title": "General Manager",
      "primaryPhone": "(623) 977-0599",
      "email": "ihop3080@romulusinc.com"
    },
    "location": {
      "address": "10603 W Olive",
      "city": "Peoria",
      "state": "AZ",
      "zip": "85345"
    }
  }
}

Rules:
- Always return "intent": "contact_proposal" if there is any name + phone or email.
- Be aggressive at extracting data from email signatures.
- Fix formatting (remove extra dots, fix phone numbers, etc.).
- Use (XXX) XXX-XXXX phone format.
- Infer company from email if missing.
EOT;

$extractionPrompt = $rawInput;   // Just the raw text

#endregion

#region SECTION 5 — AI Request Execution

$apiUrl = 'https://skyelighting.com/skyesoft/api/askOpenAI.php';

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'userQuery'     => $extractionPrompt,
        'systemPrompt'  => $systemPrompt,
        'type'          => 'structured',
        'activitySessionId' => $activitySessionId
    ]),
    CURLOPT_TIMEOUT        => 20
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// curl_close($ch);  // Removed — deprecated in PHP 8.4+ and no longer needed

if ($response === false) {
    jsonError('cURL error: ' . curl_error($ch));
}

if ($httpCode !== 200 || !$response) {
    jsonError('AI service unavailable');
}

#endregion

#region SECTION 6 — AI Response Processing

$start = strpos($response, '{');
$end   = strrpos($response, '}') + 1;

if ($start === false || $end <= $start) {
    jsonError('Invalid AI response format');
}

$jsonStr = substr($response, $start, $end - $start);
$aiData  = json_decode($jsonStr, true);

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

// =====================================================
// Helper Functions
// =====================================================

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
?>