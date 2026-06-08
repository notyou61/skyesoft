<?php
declare(strict_types=1);

/**
 * Skyesoft — processProposedContact.php
 * Main Orchestration + Proposal Report Generation
 * Version: 1.6.1
 * Last Updated: 2026-06-28
 */

#region SECTION 00 — Bootstrap & Request Initialization

// =====================================================
// PROCESS START
// =====================================================

error_log('[PPC] ====================================================');
error_log('[PPC] processProposedContact START ' . date('Y-m-d H:i:s'));
error_log('[PPC] ====================================================');

// =====================================================
// RUNTIME CONFIGURATION
// =====================================================

if (!headers_sent()) {
header('Content-Type: application/json');
}

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// =====================================================
// DEPENDENCY LOADING
// =====================================================

require_once __DIR__ . '/utils/processProposedContact.utils.php';

error_log('[PPC][SECTION-00] Bootstrap complete');

// =====================================================
// REQUEST CONTEXT
// =====================================================

$context = [
'requestId'        => uniqid('ppc_', true),
'startedAt'        => microtime(true),
'activitySessionId'=> '',
'version'          => '2.0.0'
];

// =====================================================
// INPUT CAPTURE
// =====================================================

$rawJson = file_get_contents('php://input');

$inputData = json_decode($rawJson, true);

if (!is_array($inputData)) {
$inputData = [];
}

$rawInput = trim(
$inputData['input']
?? ''
);

$context['activitySessionId'] =
trim($inputData['activitySessionId'] ?? '');

// =====================================================
// DIAGNOSTIC LOGGING
// =====================================================

error_log('[PPC] Request ID: ' . $context['requestId']);
error_log('[PPC] Input Length: ' . strlen($rawInput));

if (!empty($inputData)) {
error_log(
'[PPC] Input Keys: ' .
implode(', ', array_keys($inputData))
);
}

#endregion

#region SECTION 01 — Runtime Services

require_once __DIR__ . '/dbConnect.php';
require_once __DIR__ . '/utils/envLoader.php';

skyesoftLoadEnv();

$pdo = getPDO() ?? null;

error_log('[PPC] Runtime services loaded');

#endregion

#region SECTION 02 — Input Validation

if ($rawInput === '') {

    echo json_encode([
        'success' => false,
        'status'  => 'missing_input'
    ]);

    exit;
}

error_log('[PPC] Input validated');

#endregion

#region SECTION 03 — AI Extraction

// =====================================================
// ENVIRONMENT VALIDATION
// =====================================================

$openAiApiKey =
skyesoftGetEnv('OPENAI_API_KEY')
?: getenv('OPENAI_API_KEY');

if (empty($openAiApiKey)) {

echo json_encode([
    'success' => false,
    'status'  => 'missing_openai_key'
]);

exit;

}

error_log('[PPC][SECTION-03] OpenAI key loaded');

// =====================================================
// AI PROMPT CONSTRUCTION
// =====================================================

$systemPrompt = <<<PROMPT
You are a structured data extraction engine.

Extract entity, contact, and location information.

Return ONLY valid JSON.

{
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
"suite": "",
"locationName": ""
}
}
PROMPT;

$userPrompt = $rawInput;

// =====================================================
// OPENAI REQUEST
// =====================================================

$payload = [
'model' => 'gpt-4o-mini',
'messages' => [
[
'role' => 'system',
'content' => $systemPrompt
],
[
'role' => 'user',
'content' => $userPrompt
]
],
'temperature' => 0
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');

curl_setopt_array($ch, [
CURLOPT_POST => true,
CURLOPT_RETURNTRANSFER => true,
CURLOPT_HTTPHEADER => [
'Content-Type: application/json',
'Authorization: Bearer ' . $openAiApiKey
],
CURLOPT_POSTFIELDS => json_encode($payload),
CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);

safeCurlClose($ch);

if (!$response) {


echo json_encode([
    'success' => false,
    'status'  => 'openai_request_failed'
]);

exit;


}

// =====================================================
// RESPONSE VALIDATION
// =====================================================

$responseData = json_decode($response, true);

$content =
$responseData['choices'][0]['message']['content']
?? '';

if (empty($content)) {


echo json_encode([
    'success' => false,
    'status'  => 'invalid_ai_response'
]);

exit;

}

preg_match('/{.*}/s', $content, $matches);

$parsed =
json_decode($matches[0] ?? '{}', true);

if (!is_array($parsed)) {


echo json_encode([
    'success' => false,
    'status'  => 'invalid_ai_json'
]);

exit;

}

error_log('[PPC][SECTION-03] AI extraction complete');

#endregion


#region Output Generation — Placeholder for future implementation

echo json_encode([
    'success' => true,
    'status' => 'section_02_complete',
    'inputLength' => strlen($rawInput),
    'requestId' => $context['requestId'],
    'pdoConnected' => ($pdo instanceof PDO)
]);

exit;

#endregion