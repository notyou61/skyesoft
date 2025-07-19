<?php
#region 🛡️ Headers and API Key
header("Content-Type: application/json");
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    echo json_encode(["response" => "❌ API key not found.", "action" => "none"]);
    exit;
}
#endregion

#region 📚 Load Codex Glossary
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codexGlossary = [];
if (file_exists($codexPath)) {
    $codexRaw = file_get_contents($codexPath);
    $codexData = json_decode($codexRaw, true);
    if (isset($codexData['modules']['glossaryModule']['contents'])) {
        $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
    }
}
#endregion

#region 📦 Parse Input
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);
$conversation = isset($input["conversation"]) ? $input["conversation"] : [];
$prompt = isset($input["prompt"]) ? trim($input["prompt"]) : "";
$sseSnapshot = isset($input["sseSnapshot"]) ? $input["sseSnapshot"] : [];
#endregion

#region 📘 Build codexGlossary Block
$codexGlossaryBlock = "";
if (!empty($codexGlossary) && is_array($codexGlossary)) {
    foreach ($codexGlossary as $termDef) {
        if (strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
        } else {
            $codexGlossaryBlock .= $termDef . "\n";
        }
    }
}
#endregion

#region 📊 Build sseSnapshot Summary
$snapshotSummary = "";
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    if (isset($sseSnapshot['timeDateArray']['currentDate']))
        $snapshotSummary .= "date: " . $sseSnapshot['timeDateArray']['currentDate'] . "\n";
    if (isset($sseSnapshot['timeDateArray']['currentLocalTime']))
        $snapshotSummary .= "time: " . $sseSnapshot['timeDateArray']['currentLocalTime'] . "\n";
    if (isset($sseSnapshot['weatherData']['temp']) && isset($sseSnapshot['weatherData']['description']))
        $snapshotSummary .= "weather: " . $sseSnapshot['weatherData']['temp'] . "°F, " . $sseSnapshot['weatherData']['description'] . "\n";
    if (isset($sseSnapshot['kpiData']['contacts']))
        $snapshotSummary .= "contacts: " . $sseSnapshot['kpiData']['contacts'] . "\n";
    if (isset($sseSnapshot['siteMeta']['siteVersion']))
        $snapshotSummary .= "siteVersion: " . $sseSnapshot['siteMeta']['siteVersion'] . "\n";
    if (isset($sseSnapshot['uiHints']['tips'][0]))
        $snapshotSummary .= "tip: " . $sseSnapshot['uiHints']['tips'][0] . "\n";
}
#endregion

#region 📝 Build System Prompt
$systemPrompt = <<<PROMPT
You are Skyebot, an assistant for a signage company. You have two sources of truth:
- codexGlossary: internal company terms/definitions
- sseSnapshot: current operational data (date, time, weather, KPIs, etc.)

Rules:
- Infer what the user is asking about (a term or an operational value).
- If the answer is found in codexGlossary or sseSnapshot, respond ONLY with that value or definition. No extra wording.
- If no information is found, reply: "No information available."
- Do not repeat the user’s question, explain reasoning, or add extra context.

codexGlossary:
$codexGlossaryBlock

sseSnapshot:
$snapshotSummary
PROMPT;
#endregion

#region 💬 Build OpenAI Message Array
$messages = [
    ["role" => "system", "content" => $systemPrompt]
];
// Optionally add the last 1-2 user/assistant turns for context (avoid drift)
if (!empty($conversation)) {
    $history = array_slice($conversation, -2);
    foreach ($history as $entry) {
        if (isset($entry["role"]) && isset($entry["content"])) {
            $messages[] = ["role" => $entry["role"], "content" => $entry["content"]];
        }
    }
}
$messages[] = ["role" => "user", "content" => $prompt];
#endregion

#region 🚀 OpenAI API Request
$payload = json_encode([
    "model" => "gpt-4",
    "messages" => $messages,
    "temperature" => 0.1,   // Minimal creativity, stick to rules
    "max_tokens" => 200
], JSON_UNESCAPED_SLASHES);

$opts = [
    "http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
        "content" => $payload,
        "timeout" => 25
    ]
];

$context = stream_context_create($opts);
$response = @file_get_contents("https://api.openai.com/v1/chat/completions", false, $context);

if ($response === false) {
    echo json_encode(["response" => "❌ Error reaching OpenAI API.", "action" => "none"]);
    exit;
}

$result = json_decode($response, true);

if (!isset($result["choices"][0]["message"]["content"])) {
    echo json_encode(["response" => "❌ Invalid response from AI.", "action" => "none"]);
    exit;
}

$responseText = trim($result["choices"][0]["message"]["content"]);

// Final fallback: if empty or contains the question, block it
if ($responseText === "" || stripos($responseText, $prompt) !== false) {
    $responseText = "No information available.";
}
#endregion

#region 📤 Output
echo json_encode([
    "response" => $responseText,
    "action" => "none"
]);
#endregion
