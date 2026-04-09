<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — parseContactCore.php
//  Version: 1.1.0
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

// Ensure environment functions exist (fail fast)
if (!function_exists('skyesoftGetEnv') || !function_exists('skyesoftLoadEnv')) {
    throw new RuntimeException('Environment bootstrap failed: required functions missing.');
}

// Load environment once per call
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

    $apiKey = skyesoftGetEnv("OPENAI_API_KEY");

    if (!$apiKey) {
        throw new RuntimeException('API key missing.');
    }

    #endregion

    #region PROMPT

    $systemPrompt = <<<EOT
You are a structured data extraction engine.

Return ONLY valid JSON.

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
EOT;

    #endregion

    #region OPENAI REQUEST

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
        throw new RuntimeException('OpenAI request failed.');
    }

    #endregion

    #region RESPONSE PARSE

    $result = json_decode($response, true);

    $content = $result['choices'][0]['message']['content'] ?? null;

    if (!$content) {
        throw new RuntimeException('Invalid OpenAI response.');
    }

    // Clean markdown wrapping
    $content = preg_replace('/```json|```/', '', $content);

    $parsed = json_decode($content, true);

    if (!is_array($parsed)) {
        throw new RuntimeException('JSON parse failed.');
    }

    return $parsed;

    #endregion
}

#endregion