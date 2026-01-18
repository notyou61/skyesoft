<?php

// Commit Narrator — governed AI assistant
// Reads git diff and produces a single commit message

$diff = shell_exec('git diff --stat');

if (!$diff) {
    exit;
}

// Fallback deterministic message
$fallback = "Update repository content";

// Optional: AI hook
$useAI = true;

if (!$useAI) {
    echo $fallback;
    exit;
}

// ---- AI PROMPT ----
$prompt = <<<PROMPT
You are generating a Git commit message.

Rules:
- One concise summary line
- No emojis
- No marketing language
- Neutral, factual tone
- Imperative mood
- Mention only what changed

Git diff summary:
$diff
PROMPT;

// --- OpenAI call (simplified, replace with your existing key loader) ---
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    echo $fallback;
    exit;
}

$payload = json_encode([
    "model" => "gpt-4.1-mini",
    "messages" => [
        ["role" => "system", "content" => "You write Git commit messages."],
        ["role" => "user", "content" => $prompt]
    ],
    "max_tokens" => 60
]);

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ],
    CURLOPT_POSTFIELDS => $payload
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

$message = $data["choices"][0]["message"]["content"] ?? null;
echo trim($message ?: $fallback);