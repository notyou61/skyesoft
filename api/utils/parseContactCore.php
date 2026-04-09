<?php
declare(strict_types=1);

function parseContact(string $rawInput): array
{
    #region ENV

    require_once __DIR__ . '/../sessionBootstrap.php';
    skyesoftLoadEnv();

    $apiKey = skyesoftGetEnv("OPENAI_API_KEY");

    if (!$apiKey) {
        throw new RuntimeException('API key missing');
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

    #region OPENAI

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
        throw new RuntimeException('OpenAI request failed');
    }

    #endregion

    #region PARSE

    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? null;

    if (!$content) {
        throw new RuntimeException('Invalid OpenAI response');
    }

    $content = preg_replace('/```json|```/', '', $content);

    $parsed = json_decode($content, true);

    if (!$parsed) {
        throw new RuntimeException('JSON parse failed');
    }

    return $parsed;

    #endregion
}