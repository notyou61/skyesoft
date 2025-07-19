<?php
#region 🛡️ Headers and API Key
header("Content-Type: application/json"); // 📝 Output as JSON

// 🔐 Load OpenAI API key from environment
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    // ❌ Stop if API key is missing
    echo json_encode(array("response" => "❌ API key not found.", "action" => "none"));
    exit;
}
#endregion

#region 📚 Load Codex Glossary
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codexGlossary = array();

if (file_exists($codexPath)) {
    $codexRaw = file_get_contents($codexPath);
    $codexData = json_decode($codexRaw, true);
    // Update this line depending on your codex.json structure!
    if (isset($codexData['modules']['glossaryModule']['contents'])) {
        $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
    }
}
#endregion

#region 🔎 Parse Incoming Request
// 📥 Get raw POST input and decode as array
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);

// 🧵 Extract conversation history or empty array
$conversation = isset($input["conversation"]) ? $input["conversation"] : array();
// ✍️ Extract user prompt or empty string
$prompt = isset($input["prompt"]) ? trim($input["prompt"]) : "";
// 🌐 Extract current live SOT snapshot or empty array
$sseSnapshot = isset($input["sseSnapshot"]) ? $input["sseSnapshot"] : array();
// 📚 Extract codex data or empty array
$codex = isset($input["codex"]) ? $input["codex"] : array();
#endregion

#region 📝 Build System Prompt (Context)
$systemPrompt = <<<PROMPT
You are Skyebot, an assistant for a signage company.

You are provided two data sources for each response:
- 'sseSnapshot': Live operational data (date, weather, KPIs, work intervals, site metadata, tips).
- 'codexGlossary': Internal glossary of company terms and acronyms.

When asked about a term/acronym found in the codexGlossary, reply with its exact glossary definition.

When asked about operational data (like weather, date, KPIs), only use live values from sseSnapshot—never make up numbers.

If both are referenced, answer each using the correct data source. If a question doesn't match either, politely say you have no information.

Never say you lack real-time access. Always answer based on these sources.
PROMPT;

$codexGlossaryBlock = "";
if (!empty($codexGlossary) && is_array($codexGlossary)) {
    $codexGlossaryBlock = "\n\ncodexGlossary = [\n";
    foreach ($codexGlossary as $termDef) {
        // If each $termDef is "TERM — definition", split for clarity
        if (strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
        } else {
            $codexGlossaryBlock .= $termDef . "\n";
        }
    }
    $codexGlossaryBlock .= "]\n";
}

$snapshotSummary = "";
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    // Only key values, not the full JSON
    $snapshotSummary .= "\n\nsseSnapshot = {\n";
    if (isset($sseSnapshot['timeDateArray']['currentLocalTime']))
        $snapshotSummary .= "time: " . $sseSnapshot['timeDateArray']['currentLocalTime'] . "\n";
    if (isset($sseSnapshot['weatherData']['temp']))
        $snapshotSummary .= "weather: " . $sseSnapshot['weatherData']['temp'] . "°F, " . $sseSnapshot['weatherData']['description'] . "\n";
    if (isset($sseSnapshot['kpiData']['contacts']))
        $snapshotSummary .= "contacts: " . $sseSnapshot['kpiData']['contacts'] . "\n";
    // Add more as needed
    $snapshotSummary .= "}\n";
}

$systemPrompt .= $codexGlossaryBlock . $snapshotSummary;
#endregion

#region 📚 Build OpenAI Message Array
$messages = array(
    array("role" => "system", "content" => $systemPrompt)
);
// 🧵 Add each previous turn (role/content)
foreach ($conversation as $entry) {
    if (isset($entry["role"]) && isset($entry["content"])) {
        $messages[] = array(
            "role" => $entry["role"],
            "content" => $entry["content"]
        );
    }
}
#endregion

#region 🚀 OpenAI API Request
$payload = json_encode(array(
    "model" => "gpt-4",
    "messages" => $messages,
    "temperature" => 0.7
));

$opts = array(
    "http" => array(
        "method" => "POST",
        "header" => "Content-Type: application/json\r\nAuthorization: Bearer " . $apiKey . "\r\n",
        "content" => $payload,
        "timeout" => 25
    )
);

$context = stream_context_create($opts);
// 📡 Send request to OpenAI, suppress warnings
$response = @file_get_contents("https://api.openai.com/v1/chat/completions", false, $context);
#endregion

#region 🩹 Error Handling
if ($response === false) {
    // ❌ API request failed
    echo json_encode(array("response" => "❌ Error reaching OpenAI API.", "action" => "none"));
    exit;
}

$result = json_decode($response, true);

if (!isset($result["choices"][0]["message"]["content"])) {
    // ❌ Malformed response from AI
    echo json_encode(array("response" => "❌ Invalid response from AI.", "action" => "none"));
    exit;
}
#endregion

#region ✅ Output AI Result
echo json_encode(array(
    "response" => $result["choices"][0]["message"]["content"],
    "action" => "none"
));
#endregion