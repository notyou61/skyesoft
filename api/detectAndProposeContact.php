<?php
// =====================================================
// Skyesoft — detectAndProposeContact.php
// EOP (Ease of Paste) Contact Proposal Engine
// Production Version - With Type Hints
// =====================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth_check.php';

$input = json_decode(file_get_contents('php://input'), true);

$rawInput          = trim($input['input'] ?? '');
$activitySessionId = $input['activitySessionId'] ?? 'no_session';
$mode              = $input['mode'] ?? 'propose';

if (empty($rawInput)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'No input provided'
    ]);
    exit;
}

// =====================================================
// 1. AI Extraction Prompt
// =====================================================
$systemPrompt = <<<EOT
You are a precise contact extraction assistant for a CRM system based in Phoenix, Arizona.

Extract structured contact information from the pasted text (email signature, business card, etc.).

Return ONLY valid JSON with this exact structure:

{
  "intent": "contact_proposal" | "not_contact",
  "confidence": 0-100,
  "parsed": {
    "entity": { "name": "Company Name or null" },
    "contact": {
      "firstName": "...",
      "lastName": "...",
      "salutation": "Mr/Ms/Dr etc or null",
      "title": "Job Title or null",
      "primaryPhone": "...",
      "email": "..."
    },
    "location": {
      "address": "...",
      "city": "...",
      "state": "AZ",
      "zip": "..."
    }
  },
  "notes": "Any extra observations"
}

Rules:
- Be aggressive on extraction. Fix OCR/paste typos.
- Normalize phone numbers to (XXX) XXX-XXXX format when possible.
- Default state to AZ for Phoenix metro area.
- Infer company name from email domain if missing.
EOT;

$extractionPrompt = "Extract contact information from this pasted text:\n\n" . $rawInput;

try {
    // Call AI
    $aiResponse = callAI($systemPrompt, $extractionPrompt, $activitySessionId);

    // Extract JSON from AI response (defensive)
    $jsonStart = strpos($aiResponse, '{');
    $jsonEnd   = strrpos($aiResponse, '}') + 1;

    if ($jsonStart === false || $jsonEnd <= $jsonStart) {
        throw new Exception('Failed to extract JSON from AI response');
    }

    $jsonStr = substr($aiResponse, $jsonStart, $jsonEnd - $jsonStart);
    $aiData  = json_decode($jsonStr, true);

    if (!$aiData || ($aiData['intent'] ?? '') !== 'contact_proposal') {
        echo json_encode([
            'status'  => 'reject',
            'message' => 'Not recognized as contact information.'
        ]);
        exit;
    }

    // Process parsed data
    $parsed = $aiData['parsed'] ?? [];
    $parsed = normalizeParsed($parsed);
    $parsed = inferMissingFields($parsed);

    $missing = validateParsed($parsed);

    // Regional parcel logic
    $needsParcel = false;
    $parcelMessage = '';

    if (!empty($parsed['location']['city'])) {
        $city = strtolower($parsed['location']['city']);
        if (str_contains($city, 'peoria') || str_contains($city, 'phoenix') ||
            str_contains($city, 'glendale') || str_contains($city, 'scottsdale') ||
            str_contains($city, 'tempe') || str_contains($city, 'mesa')) {
            $needsParcel = true;
            $parcelMessage = 'Maricopa County parcel lookup recommended before final save.';
        }
    }

    // Final Response
    echo json_encode([
        'status'           => 'proposed',
        'confidence'       => $aiData['confidence'] ?? 75,
        'parsed'           => $parsed,
        'source'           => 'ai_eop_signature',
        'needs_parcel'     => $needsParcel,
        'message'          => $parcelMessage,
        'issues'           => !empty($missing) ? $missing : null,
        'raw_input_preview'=> substr($rawInput, 0, 250) . (strlen($rawInput) > 250 ? '...' : '')
    ]);

} catch (Exception $e) {
    error_log('[detectAndProposeContact] Error: ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'AI processing failed. You can still use the classic "add ..." command.'
    ]);
}

// =====================================================
// Helper Functions (with type hints)
// =====================================================

/**
 * Call AI service
 */
function callAI(string $system, string $user, string $sessionId): string
{
    $payload = [
        'userQuery'         => $user,
        'systemPrompt'      => $system,
        'type'              => 'structured',
        'activitySessionId' => $sessionId
    ];

    $ch = curl_init('https://skyelighting.com/skyesoft/api/askOpenAI.php?type=skyebot&ai=true');
    
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE'] ?? '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        error_log("[callAI] Failed with HTTP $httpCode");
        throw new Exception('AI service unavailable');
    }

    $data = json_decode($response, true);
    return $data['response'] ?? $response;
}

/**
 * Normalize phone, email, state, etc.
 */
function normalizeParsed(array $parsed): array
{
    // Email
    if (!empty($parsed['contact']['email'])) {
        $parsed['contact']['email'] = strtolower(trim($parsed['contact']['email']));
    }

    // Phone
    if (!empty($parsed['contact']['primaryPhone'])) {
        $phone = preg_replace('/[^0-9]/', '', $parsed['contact']['primaryPhone']);
        if (strlen($phone) === 10) {
            $parsed['contact']['primaryPhone'] = '(' . substr($phone, 0, 3) . ') ' .
                                                 substr($phone, 3, 3) . '-' .
                                                 substr($phone, 6);
        }
    }

    // State
    if (!empty($parsed['location']['state'])) {
        $parsed['location']['state'] = strtoupper($parsed['location']['state']);
    }

    return $parsed;
}

/**
 * Infer missing fields (company from email, salutation, etc.)
 */
function inferMissingFields(array $parsed): array
{
    // Infer company name from email
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

/**
 * Validate required fields
 */
function validateParsed(array $parsed): array
{
    $missing = [];

    if (empty($parsed['contact']['firstName'] ?? '')) $missing[] = 'firstName';
    if (empty($parsed['contact']['lastName']  ?? '')) $missing[] = 'lastName';
    if (empty($parsed['contact']['email']     ?? '')) $missing[] = 'email';

    if (empty($parsed['contact']['primaryPhone'] ?? '')) {
        $missing[] = 'primaryPhone (recommended)';
    }

    return $missing;
}
?>