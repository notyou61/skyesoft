<?php
#region 🛡️ Headers and API Key
header("Content-Type: application/json");
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    echo json_encode(["response" => "❌ API key not found.", "action" => "none"]);
    exit;
}
#endregion

#region 📚 Load Codex Glossary (Old & New Format)
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codexGlossary = [];
$codexGlossaryAssoc = [];
if (file_exists($codexPath)) {
    $codexRaw = file_get_contents($codexPath);
    $codexData = json_decode($codexRaw, true);

    // Old: Array of definition lines (for legacy support)
    if (isset($codexData['modules']['glossaryModule']['contents'])) {
        $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
    }
    // New: Associative glossary ("glossary": { ... })
    if (isset($codexData['glossary']) && is_array($codexData['glossary'])) {
        $codexGlossaryAssoc = $codexData['glossary'];
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

#region 📘 Build codexGlossary Block & Array for Validation
$codexGlossaryBlock = "";
$codexTerms = [];

// Old glossary (array)
if (!empty($codexGlossary)) {
    foreach ($codexGlossary as $termDef) {
        if (strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
            $codexTerms[] = trim($def);
        } else {
            $codexGlossaryBlock .= $termDef . "\n";
            $codexTerms[] = trim($termDef);
        }
    }
}

// New glossary (assoc array)
if (!empty($codexGlossaryAssoc)) {
    foreach ($codexGlossaryAssoc as $term => $def) {
        $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
        $codexTerms[] = trim($def);
        // Also allow "Term: Definition" for matching
        $codexTerms[] = trim($term) . ": " . trim($def);
    }
}
#endregion

#region 📊 Build sseSnapshot Summary & Array for Validation
$snapshotSummary = "";
$sseValues = [];
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    if (isset($sseSnapshot['timeDateArray']['currentDate'])) {
        $val = $sseSnapshot['timeDateArray']['currentDate'];
        $snapshotSummary .= "date: " . $val . "\n";
        $sseValues[] = $val;
    }
    if (isset($sseSnapshot['timeDateArray']['currentLocalTime'])) {
        $val = $sseSnapshot['timeDateArray']['currentLocalTime'];
        $snapshotSummary .= "time: " . $val . "\n";
        $sseValues[] = $val;
    }
    if (isset($sseSnapshot['weatherData']['temp']) && isset($sseSnapshot['weatherData']['description'])) {
        $val = $sseSnapshot['weatherData']['temp'] . "°F, " . $sseSnapshot['weatherData']['description'];
        $snapshotSummary .= "weather: " . $val . "\n";
        $sseValues[] = $val;
    }
    if (isset($sseSnapshot['kpiData']['contacts'])) {
        $val = $sseSnapshot['kpiData']['contacts'];
        $snapshotSummary .= "contacts: " . $val . "\n";
        $sseValues[] = $val;
    }
    if (isset($sseSnapshot['siteMeta']['siteVersion'])) {
        $val = $sseSnapshot['siteMeta']['siteVersion'];
        $snapshotSummary .= "siteVersion: " . $val . "\n";
        $sseValues[] = $val;
    }
    if (isset($sseSnapshot['uiHints']['tips'][0])) {
        $val = $sseSnapshot['uiHints']['tips'][0];
        $snapshotSummary .= "tip: " . $val . "\n";
        $sseValues[] = $val;
    }
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
// Optionally add the last 1-2 user/assistant turns for context
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

#region 📋 User Glossary Command — Show Full Glossary
if (preg_match('/\b(show glossary|all glossary|list all terms|full glossary)\b/i', $prompt)) {
    // Unique glossary display
    $displayed = [];
    $uniqueGlossary = "";
    foreach (explode("\n", $codexGlossaryBlock) as $line) {
        $key = strtolower(trim(strtok($line, ":")));
        if ($key && !isset($displayed[$key])) {
            $uniqueGlossary .= $line . "\n\n";
            $displayed[$key] = true;
        }
    }
$formattedGlossary = nl2br(htmlspecialchars($uniqueGlossary));

    echo json_encode([
        "response" => $formattedGlossary,
        "action" => "none"
    ]);
    exit;
}
#endregion

#region 🚀 OpenAI API Request
$payload = json_encode([
    "model" => "gpt-4",
    "messages" => $messages,
    "temperature" => 0.1,
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
#endregion

#region ✅ Final Post-Validation Step
$allValid = array_merge($codexTerms, $sseValues);
$isValid = false;
foreach ($allValid as $entry) {
    if (strcasecmp(trim($responseText), trim($entry)) === 0) {
        $isValid = true;
        break;
    }
    // Allow "Term: Definition" match if that's how glossary returns it
    if (strpos($responseText, ":") !== false && stripos($entry, trim($responseText)) !== false) {
        $isValid = true;
        break;
    }
}
if (!$isValid || $responseText === "") {
    $responseText = "No information available.";
}
#endregion

#region 📤 Output
echo json_encode([
    "response" => $responseText,
    "action" => "none"
]);
#endregion