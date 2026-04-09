<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — parseContactCore.php
//  Version: 1.1.1
//  Last Updated: 2026-04-09
//  Codex Tier: 3 — AI Augmentation / Structured Data Extraction
//
//  Role:
//  Core parsing engine (function-based).
//  Converts raw input into structured ELC-ready data.
//
//  Notes:
//   • No direct output
//   • No DB interaction
//   • Safe for reuse across system
// ======================================================================

#region SECTION 0 — Environment Bootstrap

require_once __DIR__ . '/../sessionBootstrap.php';
require_once __DIR__ . '/envLoader.php';

// Ensure environment loader functions exist (fail fast)
if (
    !function_exists('skyesoftLoadEnv') ||
    !function_exists('skyesoftGetEnv')
) {
    throw new RuntimeException(
        'Environment bootstrap failed: required functions missing.'
    );
}

// Load environment
skyesoftLoadEnv();

#endregion

#region SECTION 1 — Core Function

function parseContact(string $rawInput): array
{
    #region VALIDATION

    $rawInput = trim($rawInput);

    if ($rawInput === '') {
        throw new RuntimeException('parseContact: rawInput is empty.');
    }

    #endregion

    #region ENV ACCESS

    $apiKey = skyesoftGetEnv('OPENAI_API_KEY');

    if (!$apiKey) {
        throw new RuntimeException('API key missing.');
    }

    #endregion

    #region PROMPT

    $systemPrompt = <<<EOT
You are a structured data extraction engine.

Extract contact information from raw input such as email signatures.

Return ONLY valid JSON. No explanation.

Schema:
{
  "entity": {
    "name": ""
  },
  "location": {},
  "contact": {
    "firstName": "",
    "lastName": "",
    "title": "",
    "phone": "",
    "email": ""
  }
}

Rules:
- Do not invent data
- If unknown, return empty string
- Split first/last name correctly
- Normalize phone number format if possible
EOT;

    #endregion

    #region OPENAI REQUEST

    $payload = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ],
            [
                'role' => 'user',
                'content' => $rawInput
            ]
        ],
        'temperature' => 0
    ]);

    if ($payload === false) {
        throw new RuntimeException('Failed to encode OpenAI payload.');
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
            'content' => $payload,
            'timeout' => 15
        ]
    ]);

    $response = file_get_contents(
        'https://api.openai.com/v1/chat/completions',
        false,
        $context
    );

    if ($response === false) {
        throw new RuntimeException('OpenAI request failed.');
    }

    #endregion

    #region RESPONSE PARSE

    $result = json_decode($response, true);

    if (!is_array($result)) {
        throw new RuntimeException('Invalid OpenAI response payload.');
    }

    $content = $result['choices'][0]['message']['content'] ?? null;

    if (!$content || !is_string($content)) {
        throw new RuntimeException('Invalid OpenAI response content.');
    }

    // Clean markdown wrapping
    $content = preg_replace('/```json|```/', '', $content);
    $content = trim((string)$content);

    $parsed = json_decode($content, true);

    if (!is_array($parsed)) {
        throw new RuntimeException('JSON parse failed.');
    }

    #endregion

    #region NORMALIZE OUTPUT

    return [
        'entity' => [
            'name' => trim((string)($parsed['entity']['name'] ?? ''))
        ],
        'location' => is_array($parsed['location'] ?? null)
            ? $parsed['location']
            : [],
        'contact' => [
            'firstName' => trim((string)($parsed['contact']['firstName'] ?? '')),
            'lastName'  => trim((string)($parsed['contact']['lastName'] ?? '')),
            'title'     => trim((string)($parsed['contact']['title'] ?? '')),
            'phone'     => trim((string)($parsed['contact']['phone'] ?? '')),
            'email'     => strtolower(trim((string)($parsed['contact']['email'] ?? '')))
        ]
    ];

    #endregion
}

#endregion