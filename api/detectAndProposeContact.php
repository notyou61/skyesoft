<?php
// =====================================================
// Skyesoft — detectAndProposeContact.php
// EOP (Ease of Paste) Contact Proposal Engine
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config.php'; // Adjust if your config is elsewhere
require_once __DIR__ . '/auth_check.php';   // Reuse your existing auth

$input = json_decode(file_get_contents('php://input'), true);

$rawInput = trim($input['input'] ?? '');
$activitySessionId = $input['activitySessionId'] ?? 'no_session';
$mode = $input['mode'] ?? 'propose';

if (empty($rawInput)) {
    echo json_encode(['status' => 'error', 'message' => 'No input provided']);
    exit;
}

// =====================================================
// 1. Lightweight AI Intent + Structured Extraction
// =====================================================

$systemPrompt = <<<EOT
You are a precise contact extraction assistant for a CRM system in Phoenix, AZ.

Classify and extract from the user's pasted text.

Return ONLY valid JSON with this exact structure:

{
  "intent": "contact_proposal" | "not_contact",
  "confidence": 0-100,
  "parsed": {
    "entity": {
      "name": "Company Name or null"
    },
    "contact": {
      "firstName": "...",
      "lastName": "...",
      "title": "Job Title or null",
      "primaryPhone": "...",
      "email": "..."
    },
    "location": {
      "address": "...",
      "city": "Peoria or Phoenix etc",
      "state": "AZ",
      "zip": "85345"
    }
  },
  "notes": "Any extra observations"
}

Rules:
- If it looks like a name + phone/email/address → intent = "contact_proposal"
- Be aggressive on extraction. Fix common OCR/paste errors.
- Phone: normalize to (623) 977-0599 format if possible
- Always include state AZ if Phoenix metro area
EOT;

$extractionPrompt = "Extract contact from this pasted information:\n\n" . $rawInput;

try {
    // Call your existing OpenAI wrapper
    $aiResponse = callAI($systemPrompt, $extractionPrompt, $activitySessionId);

    $jsonStart = strpos($aiResponse, '{');
    $jsonEnd = strrpos($aiResponse, '}') + 1;
    $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart);

    $aiData = json_decode($jsonStr, true);

    if (!$aiData || $aiData['intent'] !== 'contact_proposal') {
        echo json_encode([
            'status' => 'reject',
            'message' => 'Not recognized as contact information.'
        ]);
        exit;
    }

    $parsed = $aiData['parsed'] ?? [];

    // =====================================================
    // 2. Light Validation + Maricopa Parcel Hint
    // =====================================================

    $needsParcel = false;
    $parcelMessage = '';

    if (!empty($parsed['location']['address']) && !empty($parsed['location']['city'])) {
        // You can call your existing parcel logic here if you want
        // For now we just flag it
        if (stripos($parsed['location']['city'], 'Peoria') !== false ||
            stripos($parsed['location']['city'], 'Phoenix') !== false ||
            stripos($parsed['location']['city'], 'Glendale') !== false) {
            $needsParcel = true;
            $parcelMessage = 'Maricopa County parcel lookup recommended before final save.';
        }
    }

    // =====================================================
    // 3. Return Proposal
    // =====================================================

    echo json_encode([
        'status' => 'proposed',
        'confidence' => $aiData['confidence'] ?? 85,
        'parsed' => $parsed,
        'source' => 'ai_eop_signature',
        'needs_parcel' => $needsParcel,
        'message' => $parcelMessage,
        'raw_input_preview' => substr($rawInput, 0, 200) . '...'
    ]);

} catch (Exception $e) {
    error_log('[detectAndProposeContact] Error: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'AI processing failed. Try the classic "add ..." command.'
    ]);
}

// =====================================================
// Helper: Reuse your existing AI call
// =====================================================
function callAI($system, $user, $sessionId) {
    $payload = [
        'userQuery' => $user,
        'systemPrompt' => $system,
        'type' => 'structured',
        'activitySessionId' => $sessionId
    ];

    $ch = curl_init('http://localhost/skyesoft/api/askOpenAI.php?type=skyebot&ai=true');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE'] ?? ''); // Pass session

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['response'] ?? $response;
}
?>