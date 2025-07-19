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
    if ($codexRaw === false) {
        error_log("Failed to read codex.json at: " . $codexPath);
    } else {
        $codexData = json_decode($codexRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error for codex.json: " . json_last_error_msg());
        } elseif (isset($codexData['modules']['glossaryModule']['contents'])) {
            $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
        } else {
            error_log("codex.json missing modules/glossaryModule contents");
        }
    }
} else {
    error_log("codex.json not found at: " . $codexPath);
}

// 📝 Build codexGlossaryBlock for prompt
$codexGlossaryBlock = "";
if (!empty($codexGlossary) && is_array($codexGlossary)) {
    foreach ($codexGlossary as $termDef) {
        if (is_string($termDef) && strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
        } else {
            error_log("Invalid glossary entry format: " . json_encode($termDef));
            $codexGlossaryBlock .= $termDef . "\n";
        }
    }
} else {
    error_log("codexGlossary is empty or not an array");
}
#endregion

#region 📨 Parse Incoming Request
$inputRaw = file_get_contents("php://input");
if ($inputRaw === false) {
    echo json_encode(["response" => "❌ Failed to read input.", "action" => "none"]);
    exit;
}
$input = json_decode($inputRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error for input: " . json_last_error_msg());
    echo json_encode(["response" => "❌ Invalid input format.", "action" => "none"]);
    exit;
}
$conversation = isset($input["conversation"]) ? $input["conversation"] : [];
$prompt = isset($input["prompt"]) ? trim($input["prompt"]) : "";
$sseSnapshot = isset($input["sseSnapshot"]) ? $input["sseSnapshot"] : [];
$codex = isset($input["codex"]) ? $input["codex"] : [];
#endregion

#region 🔎 Prompt Classification
$isGlossaryQuery = false;
$isOperationalQuery = false;
$isGlossaryListQuery = false;
$requestedTerm = null;

if ($prompt) {
    // Glossary: What does MTCO mean? / Define MTCO / Tell me about MTCO
    if (preg_match('/\b(what\s+(is|does)|define|tell\s+me\s+about)\s+([A-Z]+)\s*(mean)?\b/i', $prompt, $matches)) {
        $isGlossaryQuery = true;
        $requestedTerm = strtoupper(trim($matches[4]));
    }
    // Glossary List: What is in the glossary? / List glossary terms
    elseif (preg_match('/\b(what\s+is\s+in\s+the\s+glossary|list\s+(glossary\s+terms|glossary))\b/i', $prompt)) {
        $isGlossaryListQuery = true;
    }
    // Operational: What is the time? / What’s the weather? / Current time
    elseif (preg_match('/\b(what\s*(is|’s)\s*(the)?\s*(weather|date|time|contacts|siteVersion|tip)|current\s+(weather|date|time|contacts|siteVersion|tip))\b/i', $prompt)) {
        $isOperationalQuery = true;
    }
}
// Log sseSnapshot for debugging if needed
if ($isOperationalQuery) {
    error_log("sseSnapshot for prompt '$prompt': " . json_encode($sseSnapshot));
}
#endregion

#region 📝 Build System Prompt
$systemPrompt = <<<PROMPT
You are Skyebot, an AI assistant for a signage company. Your responses must be precise and follow these rules exactly:

- Glossary Queries: If the user asks about a term or acronym (e.g., "What does MTCO mean?" or "Define MTCO"), respond ONLY with the exact definition from codexGlossary for that term. Do not add other terms, explanations, or wording.
- Glossary List Queries: If the user asks for the glossary contents (e.g., "What is in the glossary?" or "List glossary terms"), respond with a comma-separated list of terms (not definitions) from codexGlossary.
- Operational Queries: If the user asks for operational info (e.g., weather, date, time, contacts, siteVersion, tip), respond ONLY with the current value from sseSnapshot for that field. Do not add other information.
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

// 🟦 Build sseSnapshot Summary
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
} else {
    error_log("sseSnapshot is empty or not an array for prompt: " . $prompt);
}
$systemPrompt .= $snapshotSummary;
#endregion

#region 📤 OpenAI Message and Request
$messages = [["role" => "system", "content" => $systemPrompt]];
if (!$isGlossaryQuery && !$isOperationalQuery && !$isGlossaryListQuery) {
    foreach ($conversation as $entry) {
        if (isset($entry["role"]) && isset($entry["content"])) {
            $messages[] = ["role" => $entry["role"], "content" => $entry["content"]];
        }
    }
}
$messages[] = ["role" => "user", "content" => $prompt];

// 🔗 OpenAI API Request
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
#endregion

#region 🧯 Error Handling & Response
if ($response === false) {
    $error = error_get_last();
    error_log("OpenAI API request failed: " . json_encode($error));
    echo json_encode(["response" => "❌ Error reaching OpenAI API.", "action" => "none"]);
    exit;
}

$result = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error for OpenAI response: " . json_last_error_msg());
    echo json_encode(["response" => "❌ Invalid response from AI.", "action" => "none"]);
    exit;
}

if (!isset($result["choices"][0]["message"]["content"])) {
    error_log("Invalid OpenAI response structure: " . json_encode($result));
    echo json_encode(["response" => "❌ Invalid response from AI.", "action" => "none"]);
    exit;
}
$responseText = $result["choices"][0]["message"]["content"];
#endregion

#region ✅ Strict Server-Side Response Filter
$finalResponse = "No information available.";
if ($isGlossaryQuery && $requestedTerm) {
    foreach ($codexGlossary as $termDef) {
        if (is_string($termDef) && strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            if (strtoupper(trim($term)) === $requestedTerm) {
                $finalResponse = trim($def);
                break;
            }
        }
    }
} elseif ($isGlossaryListQuery) {
    $terms = [];
    foreach ($codexGlossary as $termDef) {
        if (is_string($termDef) && strpos($termDef, '—') !== false) {
            list($term, $def) = explode('—', $termDef, 2);
            $terms[] = trim($term);
        }
    }
    $finalResponse = !empty($terms) ? implode(", ", $terms) : "No glossary terms available.";
} elseif ($isOperationalQuery) {
    if (preg_match('/\bweather\b/i', $prompt) && isset($sseSnapshot['weatherData']['temp']) && isset($sseSnapshot['weatherData']['description'])) {
        $finalResponse = $sseSnapshot['weatherData']['temp'] . "°F, " . $sseSnapshot['weatherData']['description'];
    } elseif (preg_match('/\bdate\b/i', $prompt) && isset($sseSnapshot['timeDateArray']['currentDate'])) {
        $finalResponse = $sseSnapshot['timeDateArray']['currentDate'];
    } elseif (preg_match('/\btime\b/i', $prompt) && isset($sseSnapshot['timeDateArray']['currentLocalTime'])) {
        $finalResponse = $sseSnapshot['timeDateArray']['currentLocalTime'];
    } elseif (preg_match('/\bcontacts\b/i', $prompt) && isset($sseSnapshot['kpiData']['contacts'])) {
        $finalResponse = $sseSnapshot['kpiData']['contacts'];
    } elseif (preg_match('/\bsiteVersion\b/i', $prompt) && isset($sseSnapshot['siteMeta']['siteVersion'])) {
        $finalResponse = $sseSnapshot['siteMeta']['siteVersion'];
    } elseif (preg_match('/\btip\b/i', $prompt) && isset($sseSnapshot['uiHints']['tips'][0])) {
        $finalResponse = $sseSnapshot['uiHints']['tips'][0];
    } else {
        error_log("No matching sseSnapshot field for prompt: " . $prompt . " | sseSnapshot: " . json_encode($sseSnapshot));
    }
} else {
    $finalResponse = "No information available.";
}
#endregion

#region 🚀 Output Response
echo json_encode([
    "response" => $finalResponse,
    "action" => "none"
]);
#endregion