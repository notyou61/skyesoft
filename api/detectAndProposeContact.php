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

if (!$rawInput) {
    jsonError('No input provided');
}

#endregion

#region SECTION 4 — AI Prompt Construction

$prompt = <<<EOT
Extract structured contact information from the pasted text.

Return ONLY valid JSON. No commentary.

{
  "intent": "contact_proposal",
  "confidence": 90,
  "parsed": {
    "entity": { "name": "" },
    "contact": {
      "firstName": "",
      "lastName": "",
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

Text:
{$rawInput}
EOT;

#endregion

#region SECTION 5 — AI Request Execution

$apiUrl = 'https://skyelighting.com/skyesoft/api/askOpenAI.php?type=skyebot&ai=true';

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'userQuery'         => $prompt,
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
$end   = strrpos($response, '}');

if ($start === false || $end === false) {
    jsonError('Invalid AI response format');
}

$jsonStr = substr($response, $start, $end - $start + 1);
$aiData  = json_decode($jsonStr, true);

#endregion

#region SECTION 7 — Intent Validation

if (!$aiData || ($aiData['intent'] ?? '') !== 'contact_proposal') {
    echo json_encode([
        'status'  => 'reject',
        'message' => 'Not recognized as contact data'
    ]);
    exit;
}

#endregion

#region SECTION 8 — Success Response

echo json_encode([
    'status'            => 'proposed',
    'confidence'        => $aiData['confidence'] ?? 85,
    'parsed'            => $aiData['parsed'] ?? [],
    'source'            => 'ai_eop',
    'activitySessionId' => $activitySessionId,
    'raw_preview'       => substr($rawInput, 0, 120)
]);

#endregion