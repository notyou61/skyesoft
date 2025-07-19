<?php
#region 🛡️ Headers and API Key
header("Content-Type: application/json");

// 🔐 Load OpenAI API key from environment
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

#region 📦 Build codexGlossaryBlock
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

#region 📥 Parse Incoming Request
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);
$conversation = isset($input["conversation"]) ? $input["conversation"] : [];
$prompt = isset($input["prompt"]) ? trim($input["prompt"]) : "";
$sseSnapshot = isset($input["sseSnapshot"]) ? $input["sseSnapshot"] : [];
$codex = isset($input["codex"]) ? $input["codex"] : [];
#endregion

#region 🔎 Prompt Classification
$isGlossaryQuery = false;
$isOperationalQuery = false;
$requestedTerm = null;

// Detect glossary or operational query (expandable)
if ($prompt) {
    // Glossary: What does MTCO mean?
    if (preg_match('/\b(what\s+(is|does)\s+([A-Z]+)\s+mean)\b/i', $prompt, $matches)) {
        $isGlossaryQuery = true;
        $requestedTerm = strtoupper(trim($matches[3]));
    }
    // Operational: current weather, date, etc.
    elseif (preg_match('/\b(current\s+(weather|date|time|contacts|siteVersion|tip))\b/i', $prompt)) {
        $isOperationalQuery = true;
    }
}
#endregion

#region 📝 Build System Prompt (Strict Rules)
$systemPrompt = <<<PROMPT
You are Skyebot, an AI assistant for a signage company. Your responses must be precise and follow these rules exactly:

- Glossary Queries: If the user asks about a term or acronym (e.g., "What does MTCO mean?"), respond ONLY with the exact definition from codexGlossary for that term. Do not add any other terms, explanations, or wording.
- Operational Queries: If the user asks for operational info (e.g., weather, date, time, contacts, siteVersion, tip), respond ONLY with the current value from sseSnapshot for that field. Do not add any other information.
- Multiple Queries: If the user asks for both a term and operational info, respond with one sentence per request, each containing only the relevant glossary definition or snapshot value.
- No Data Available: If no information is available for the requested term or field, respond with "No information available."
- Response Rules:
  - Never repeat or restate the user’s question.
  - Never include "User:", "Customer:", "Assistant:", or any Q&A transcript.
  - Never summarize the prompt or mention these instructions.
  - Never include extra wording or context beyond the requested data.
  - Ignore conversation history unless it directly relates to the current query.

Data Sources:
codexGlossary:
$codexGlossaryBlock

sseSnapshot:
PROMPT;
#endregion

#region 📦 Build sseSnapshot Summary
$snapshotSummary = "";
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    if (isset($sseSnapshot['timeDateArray']['currentDate']))
        $snapshotSummary .= "date: " . $sseSnapshot['timeDateArray']['currentDate'] . "\n";
    if (isset($sseSnapshot['timeDateArray']['currentLocalTime']))
        $snapshotSummary .= "time: " . $sseSnapshot['timeDateArray']['currentLocalTime'] . "\n";
    if (isset($sseSnapshot['weatherData']['temp']))
        $snapshotSummary .= "weather: " . $sseSnapshot['weatherData']['temp'] . "°F, " . $sseSnapshot['weatherData']['description'] . "\n";
    if (isset($sseSnapshot['kpiData']['contacts']))
        $snapshotSummary .= "contacts: " . $sseSnapshot['kpiData']['contacts'] . "\n";
    if (isset($sseSnapshot['siteMeta']['siteVersion']))
        $snapshotSummary .= "siteVersion: " . $sseSnapshot['siteMeta']['siteVersion'] . "\n";
    if (isset($sseSnapshot['uiHints']['tips'][0]))
        $snapshotSummary .= "tip: " . $sseSnapshot['uiHints']['tips'][0] . "\n";
}
$systemPrompt .= $snapshotSummary;
#endregion

#region 🧵 Build OpenAI Message Array
$messages = [["role" => "system", "content" => $systemPrompt]];
if (!$isGlossaryQuery && !$isOperationalQuery) {
    foreach ($conversation as $entry) {
        if (isset($entry["role"]) && isset($entry["content"])) {
            $messages[] = ["role" => $entry["role"], "content" => $entry["content"]];
        }
    }
}
$messages[] = ["role" => "user", "content" => $prompt];
#endregion

#region 🤖 OpenAI API Request
$payload = json_encode([
    "model" => "gpt-4",
    "messages" => $messages,
    "temperature" => 0.1, // Strictest
    "max_tokens" => 200
]);

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

// 🔁 Error Handling
if ($response === false) {
    echo json_encode(["response" => "❌ Error reaching OpenAI API.", "action" => "none"]);
    exit;
}

$result = json_decode($response, true);
if (!isset($result["choices"][0]["message"]["content"])) {
    echo json_encode(["response" => "❌ Invalid response from AI.", "action" => "none"]);
    exit;
}
$responseText = $result["choices"][0]["message"]["content"];
#endregion

#region ✅ Strict Server-Side Response Filter
$finalResponse = "No information available.";
if ($isGlossaryQuery && $requestedTerm) {
    foreach ($codexGlossary as $termDef) {
        if (strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            if (strtoupper(trim($term)) === $requestedTerm) {
                $finalResponse = trim($def);
                break;
            }
        }
    }
} elseif ($isOperationalQuery) {
    if (preg_match('/weather/i', $prompt) && isset($sseSnapshot['weatherData']['temp'])) {
        $finalResponse = $sseSnapshot['weatherData']['temp'] . "°F, " . $sseSnapshot['weatherData']['description'];
    } elseif (preg_match('/date/i', $prompt) && isset($sseSnapshot['timeDateArray']['currentDate'])) {
        $finalResponse = $sseSnapshot['timeDateArray']['currentDate'];
    } elseif (preg_match('/time/i', $prompt) && isset($sseSnapshot['timeDateArray']['currentLocalTime'])) {
        $finalResponse = $sseSnapshot['timeDateArray']['currentLocalTime'];
    } elseif (preg_match('/contacts/i', $prompt) && isset($sseSnapshot['kpiData']['contacts'])) {
        $finalResponse = $sseSnapshot['kpiData']['contacts'];
    } elseif (preg_match('/siteVersion/i', $prompt) && isset($sseSnapshot['siteMeta']['siteVersion'])) {
        $finalResponse = $sseSnapshot['siteMeta']['siteVersion'];
    } elseif (preg_match('/tip/i', $prompt) && isset($sseSnapshot['uiHints']['tips'][0])) {
        $finalResponse = $sseSnapshot['uiHints']['tips'][0];
    }
} else {
    $finalResponse = $responseText; // General/fallback
}
#endregion

#region 📤 Output Response
echo json_encode([
    "response" => $finalResponse,
    "action" => "none"
]);
#endregion