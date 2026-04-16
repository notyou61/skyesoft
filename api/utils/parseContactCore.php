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

require_once __DIR__ . '/envLoader.php';
//require_once __DIR__ . '/../api/askOpenAI.php';

// Verify environment functions exist
if (!function_exists('skyesoftLoadEnv') || !function_exists('skyesoftGetEnv')) {
    throw new RuntimeException('Environment bootstrap failed: required functions missing.');
}

// Initialize env (idempotent)
skyesoftLoadEnv();

#endregion

#region SECTION 1 — Core Function

// ─────────────────────────────────────────────
// 🔤 Normalize Salutation
// ─────────────────────────────────────────────
function normalizeSalutation(?string $value): ?string {

    if (empty($value)) {
        return null;
    }

    $value = trim($value);

    // Remove trailing period (Mr. → Mr)
    $value = rtrim($value, '.');

    // Normalize case
    $value = ucfirst(strtolower($value));

    if (in_array($value, ['Mr', 'Ms'], true)) {
        return $value;
    }

    return null;
}

// ─────────────────────────────────────────────
// 🧠 Resolve Salutation (AI + fallback)
// ─────────────────────────────────────────────
function resolveSalutation(?string $salutation, string $firstName, string $lastName): string {

    // 1. Normalize user input
    $salutation = normalizeSalutation($salutation);

    if (!empty($salutation)) {
        return $salutation;
    }

    // 2. Try AI
    try {
        $aiSalutation = inferSalutation($firstName, $lastName);

        if (!empty($aiSalutation)) {
            return normalizeSalutation($aiSalutation);
        }

    } catch (Throwable $e) {
        error_log('[SALUTATION AI ERROR] ' . $e->getMessage());
    }

    // 3. Final fallback
    return 'Mr';
}

// ─────────────────────────────────────────────
// 🔤 Parse Contact
// ─────────────────────────────────────────────
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
  "location": {
    "address": "",
    "city": "",
    "state": "",
    "zip": ""
  },
  "contact": {
    "salutation": "",
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
- Extract salutation (Mr or Ms) if present at the beginning of the name
- Only allow "Mr" or "Ms"
- If not present, return empty string
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
        $error = error_get_last();
        throw new RuntimeException('OpenAI request failed: ' . ($error['message'] ?? 'unknown'));
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

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('JSON parse failed: ' . json_last_error_msg());
    }

    if (!is_array($parsed)) {
        throw new RuntimeException('JSON parse failed.');
    }

    #endregion

    #region NORMALIZE OUTPUT

    $email = trim((string)($parsed['contact']['email'] ?? ''));
    $email = $email !== '' ? strtolower($email) : '';

    return [
        'entity' => [
            'name' => trim((string)($parsed['entity']['name'] ?? ''))
        ],
        'location' => is_array($parsed['location'] ?? null)
            ? $parsed['location']
            : [],
            'contact' => [
                'salutation' => trim((string)($parsed['contact']['salutation'] ?? '')),
                'firstName'  => trim((string)($parsed['contact']['firstName'] ?? '')),
                'lastName'   => trim((string)($parsed['contact']['lastName'] ?? '')),
                'title'      => trim((string)($parsed['contact']['title'] ?? '')),
                'phone'      => trim((string)($parsed['contact']['phone'] ?? '')),
                'email'      => $email
            ]
    ];

    #endregion
}

#endregion