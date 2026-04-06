<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — parseContact.php
//  Version: 1.0.0
//  Last Updated: 2026-04-06
//  Codex Tier: 3 — AI Augmentation / Structured Data Extraction
//
//  Role:
//  Codex-aligned contact parsing engine.
//  Converts raw user input into structured ELC objects.
//
//  Pipeline Position:
//   • Stage: processingModel (Step 2)
//   • Lifecycle: propose → acknowledge
//
//  Forbidden:
//   • No DB writes
//   • No validation
//   • No duplicate detection
// ======================================================================

#region SECTION 0 — Environment Bootstrap

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");

// Load environment (reuse your standard loader)
require_once __DIR__ . '/sessionBootstrap.php';
skyesoftLoadEnv();

#endregion

#region SECTION 1 — Input Intake

$input = json_decode(file_get_contents('php://input'), true);

$rawInput   = $input['rawInput']   ?? null;
$proposalId = $input['proposalId'] ?? null;

if (!$rawInput) {
    echo json_encode(["error" => "Missing rawInput"]);
    exit;
}

#endregion

#region SECTION 2 — Prompt Construction

$systemPrompt = <<<EOT
You are a structured data extraction engine.

Extract contact information from raw input such as email signatures.

Return ONLY valid JSON. No explanation.

Schema:
{
  "entity": {
    "entityName": ""
  },
  "location": {},
  "contact": {
    "contactFirstName": "",
    "contactLastName": "",
    "contactTitle": "",
    "contactPrimaryPhone": "",
    "contactEmail": ""
  }
}

Rules:
- Do not invent data
- If unknown, return empty string
- Split first/last name correctly
- Normalize phone number format if possible
EOT;

#endregion

#region SECTION 3 — OpenAI Execution

$apiKey = skyesoftGetEnv("OPENAI_API_KEY");

if (!$apiKey) {
    echo json_encode(["error" => "API key missing"]);
    exit;
}

$payload = json_encode([
    "model" => "gpt-4o-mini",
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $rawInput]
    ],
    "temperature" => 0
]);

$context = stream_context_create([
    "http" => [
        "method"  => "POST",
        "header"  => "Content-type: application/json\r\nAuthorization: Bearer $apiKey\r\n",
        "content" => $payload,
        "timeout" => 15
    ]
]);

$response = file_get_contents(
    "https://api.openai.com/v1/chat/completions",
    false,
    $context
);

if ($response === false) {
    echo json_encode(["error" => "OpenAI request failed"]);
    exit;
}

#endregion

#region SECTION 4 — Response Parsing

$result = json_decode($response, true);

$content = $result['choices'][0]['message']['content'] ?? null;

if (!$content) {
    echo json_encode(["error" => "Invalid OpenAI response"]);
    exit;
}

// Clean markdown wrapping (optional but safe)
$content = preg_replace('/```json|```/', '', $content);

$parsed = json_decode($content, true);

if (!$parsed) {
    echo json_encode([
        "error" => "Failed to parse model JSON",
        "raw" => $content
    ]);
    exit;
}

#endregion

#region SECTION 5 — Output

echo json_encode([
    "status" => "parsed",
    "proposalId" => $proposalId,
    "data" => $parsed
], JSON_UNESCAPED_SLASHES);

exit;

#endregion