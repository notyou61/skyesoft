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
// 🟦 Core assistant prompt
$systemPrompt = "You are Skyebot, a helpful assistant for a signage company.

You are provided with a JSON object called 'sseSnapshot' that includes live operational data such as:
• time and date
• work intervals
• weather
• performance KPIs
• site metadata
• motivational tips

You are also provided with a 'Codex' glossary of internal terms, acronyms, policies, and best practices.

Use both live data and Codex knowledge to provide helpful, context-aware responses. Do not say you lack real-time data access.";

// 📦 Flatten important live snapshot values for LLM context
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    $summary = "\n\n📊 Here's the current operational snapshot:\n";

    // 🗓️ Date/Time details
    if (isset($sseSnapshot['timeDateArray'])) {
        $td = $sseSnapshot['timeDateArray'];
        $summary .= "- 📆 Date: " . @$td['currentDate'] . "\n";
        $summary .= "- 🕒 Local Time: " . @$td['currentLocalTime'] . "\n";
    }

    // 📆 Work intervals
    if (isset($sseSnapshot['intervalsArray'])) {
        $intv = $sseSnapshot['intervalsArray'];
        $summary .= "- 📅 Day Type: " . @$intv['dayType'] . " (0=Workday, 1=Weekend, 2=Holiday)\n";
        $summary .= "- ⏳ Seconds Left Today: " . @$intv['currentDaySecondsRemaining'] . "\n";
        $summary .= "- 🧭 Interval: " . @$intv['intervalLabel'] . "\n";
        $summary .= "- 🕘 Work Hours: " . @$intv['workdayIntervals']['start'] . "–" . @$intv['workdayIntervals']['end'] . "\n";
    }

    // 🌤️ Weather info
    if (isset($sseSnapshot['weatherData'])) {
        $w = $sseSnapshot['weatherData'];
        $summary .= "- 🌡️ Weather: " . @$w['temp'] . "°F, " . @$w['description'] . " " . @$w['icon'] . "\n";
    }

    // 📈 KPIs
    if (isset($sseSnapshot['kpiData'])) {
        $k = $sseSnapshot['kpiData'];
        $summary .= "- 📈 KPIs — Contacts: " . @$k['contacts'] . ", Orders: " . @$k['orders'] . ", Approvals: " . @$k['approvals'] . "\n";
    }

    // 🏷️ Site meta/version
    if (isset($sseSnapshot['siteMeta'])) {
        $s = $sseSnapshot['siteMeta'];
        $summary .= "- 🛠️ Site Version: " . @$s['siteVersion'] . ", Deploy Live: " . (@$s['deployIsLive'] ? "Yes" : "No") . "\n";
        $summary .= "- 🔁 Stream Count: " . @$s['streamCount'] . ", AI Query Count: " . @$s['aiQueryCount'] . "\n";
    }

    // 💡 Motivational tips
    if (isset($sseSnapshot['uiHints']['tips']) && is_array($sseSnapshot['uiHints']['tips'])) {
        $tip = $sseSnapshot['uiHints']['tips'][0];
        $summary .= "- 💡 Tip of the Day: \"$tip\"\n";
    }

    $systemPrompt .= $summary;

    // 🛠️ Full JSON (optional; may ignore)
    $systemPrompt .= "\n\n🔧 Full sseSnapshot (for reference):\n" . json_encode($sseSnapshot, JSON_PRETTY_PRINT);
}

// 🆕 📚 Codex integration (flatten glossary for AI)
if (is_array($codex) && isset($codex['glossary']) && is_array($codex['glossary'])) {
    $systemPrompt .= "\n\n📘 Codex Glossary (Key Terms):\n";
    foreach ($codex['glossary'] as $term => $definition) {
        $systemPrompt .= "- $term: $definition\n";
    }
}
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