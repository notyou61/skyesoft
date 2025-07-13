<?php
header("Content-Type: application/json");

// 🔐 Load API key
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    echo json_encode(array("response" => "❌ API key not found.", "action" => "none"));
    exit;
}

// 🔍 Parse incoming request
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);

$conversation = isset($input["conversation"]) ? $input["conversation"] : array();
$prompt = isset($input["prompt"]) ? trim($input["prompt"]) : "";
$sseSnapshot = isset($input["sseSnapshot"]) ? $input["sseSnapshot"] : array();

// 📋 Initialize base system prompt
$systemPrompt = "You are Skyebot, a helpful assistant for a signage company.

You are provided with a JSON object called 'sseSnapshot' that includes live operational data such as:
• time and date
• work intervals
• weather
• performance KPIs
• site metadata
• motivational tips

Use this to provide helpful, context-aware responses. Do not say you lack real-time data access.";

// 📦 Flatten key snapshot values
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    $summary = "\n\n📊 Here's the current operational snapshot:\n";

    // Time and Date
    if (isset($sseSnapshot['timeDateArray'])) {
        $td = $sseSnapshot['timeDateArray'];
        $summary .= "- 📆 Date: " . @$td['currentDate'] . "\n";
        $summary .= "- 🕒 Local Time: " . @$td['currentLocalTime'] . "\n";
    }

    // Intervals
    if (isset($sseSnapshot['intervalsArray'])) {
        $intv = $sseSnapshot['intervalsArray'];
        $summary .= "- 📅 Day Type: " . @$intv['dayType'] . " (0=Workday, 1=Weekend, 2=Holiday)\n";
        $summary .= "- ⏳ Seconds Left Today: " . @$intv['currentDaySecondsRemaining'] . "\n";
        $summary .= "- 🧭 Interval: " . @$intv['intervalLabel'] . "\n";
        $summary .= "- 🕘 Work Hours: " . @$intv['workdayIntervals']['start'] . "–" . @$intv['workdayIntervals']['end'] . "\n";
    }

    // Weather
    if (isset($sseSnapshot['weatherData'])) {
        $w = $sseSnapshot['weatherData'];
        $summary .= "- 🌡️ Weather: " . @$w['temp'] . "°F, " . @$w['description'] . " " . @$w['icon'] . "\n";
    }

    // KPIs
    if (isset($sseSnapshot['kpiData'])) {
        $k = $sseSnapshot['kpiData'];
        $summary .= "- 📈 KPIs — Contacts: " . @$k['contacts'] . ", Orders: " . @$k['orders'] . ", Approvals: " . @$k['approvals'] . "\n";
    }

    // Site Meta
    if (isset($sseSnapshot['siteMeta'])) {
        $s = $sseSnapshot['siteMeta'];
        $summary .= "- 🛠️ Site Version: " . @$s['siteVersion'] . ", Deploy Live: " . (@$s['deployIsLive'] ? "Yes" : "No") . "\n";
        $summary .= "- 🔁 Stream Count: " . @$s['streamCount'] . ", AI Query Count: " . @$s['aiQueryCount'] . "\n";
    }

    // Tips
    if (isset($sseSnapshot['uiHints']['tips']) && is_array($sseSnapshot['uiHints']['tips'])) {
        $tip = $sseSnapshot['uiHints']['tips'][0];
        $summary .= "- 💡 Tip of the Day: \"$tip\"\n";
    }

    $systemPrompt .= $summary;

    // Optional full JSON snapshot for traceability
    $systemPrompt .= "\n\n🔧 Full sseSnapshot (for reference):\n" . json_encode($sseSnapshot, JSON_PRETTY_PRINT);
}

// 🧵 Build full message history
$messages = array(
    array("role" => "system", "content" => $systemPrompt)
);

foreach ($conversation as $entry) {
    if (isset($entry["role"]) && isset($entry["content"])) {
        $messages[] = array(
            "role" => $entry["role"],
            "content" => $entry["content"]
        );
    }
}

// 🧠 Send to OpenAI API
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
$response = @file_get_contents("https://api.openai.com/v1/chat/completions", false, $context);

// 🔁 Error handling
if ($response === false) {
    echo json_encode(array("response" => "❌ Error reaching OpenAI API.", "action" => "none"));
    exit;
}

$result = json_decode($response, true);

if (!isset($result["choices"][0]["message"]["content"])) {
    echo json_encode(array("response" => "❌ Invalid response from AI.", "action" => "none"));
    exit;
}

// ✅ Return result
echo json_encode(array(
    "response" => $result["choices"][0]["message"]["content"],
    "action" => "none"
));
?>
