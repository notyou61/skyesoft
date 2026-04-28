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
function jsonError($msg) {
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
You are an expert at parsing email signatures and business card text for a CRM in Phoenix, Arizona.

Your job is to detect if the pasted text is a contact signature and extract clean structured data.

Return ONLY valid JSON with this exact structure:

{
  "intent": "contact_proposal",
  "confidence": 85,
  "parsed": {
    "entity": { "name": "Company Name" },
    "contact": {
      "firstName": "",
      "lastName": "",
      "salutation": "Mr.",
      "title": "",
      "primaryPhone": "",
      "email": ""
    },
    "location": {
      "address": "",
      "city": "",
      "state": "AZ",
      "zip": ""
    }
  }
}

Critical Rules:
- If the text contains name + phone OR email OR title + company → treat as contact_proposal.
- Be very aggressive at extracting from typical email signatures.
- Common formats: Name, Title, Company, Phone, Email, Address.
- Fix common issues: extra dashes, | separators, extra spaces, OCR errors.
- Always normalize phone to (XXX) XXX-XXXX format.
- Default state to AZ if Phoenix-metro cities are detected.
- Infer company name from email domain if not explicitly stated.
EOT;

$extractionPrompt = "Parse this email signature or business card text into structured contact data:\n\n" . $rawInput;

#endregion

#region SECTION 5 — AI Request Execution

$apiUrl = 'https://skyelighting.com/skyesoft/api/askOpenAI.php?type=skyebot&ai=true';

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

if ($response === false) {
    jsonError('cURL error: ' . curl_error($ch));
}

curl_close($ch);

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
    // Infer company from email domain
    if (empty($parsed['entity']['name']) && !empty($parsed['contact']['email'])) {
        $domain = explode('@', $parsed['contact']['email'])[1] ?? '';
        if ($domain) {
            $company = explode('.', $domain)[0];
            $parsed['entity']['name'] = ucwords(str_replace(['-', '_'], ' ', $company));
        }
    }

    // Default salutation
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