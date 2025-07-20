<?php
#region 🛡️ Headers and API Key
header("Content-Type: application/json");
$apiKey = getenv("OPENAI_API_KEY");
if (!$apiKey) {
    echo json_encode(["response" => "❌ API key not found.", "action" => "none"]);
    exit;
}
#endregion

#region 📚 Load Codex JSON (and Parse Input)
$codexPath = __DIR__ . '/../docs/codex/codex.json';
$codexData = [];
if (file_exists($codexPath)) {
    $codexRaw = file_get_contents($codexPath);
    $codexData = json_decode($codexRaw, true);
}
$inputRaw = file_get_contents("php://input");
$input = json_decode($inputRaw, true);
$conversation = isset($input["conversation"]) ? $input["conversation"] : [];
$prompt = isset($input["prompt"]) ? trim($input["prompt"]) : "";
$sseSnapshot = isset($input["sseSnapshot"]) ? $input["sseSnapshot"] : [];
#endregion

#region 📚 Build All Codex Data (Glossary, Modules, Other)
// --- Glossary (old and new formats) ---
$codexGlossary = [];
$codexGlossaryAssoc = [];
if (isset($codexData['modules']['glossaryModule']['contents'])) {
    $codexGlossary = $codexData['modules']['glossaryModule']['contents'];
}
if (isset($codexData['glossary']) && is_array($codexData['glossary'])) {
    $codexGlossaryAssoc = $codexData['glossary'];
}

// --- Build glossary block/terms ---
$codexGlossaryBlock = "";
$codexTerms = [];
if (!empty($codexGlossary) && is_array($codexGlossary)) {
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
if (!empty($codexGlossaryAssoc)) {
    foreach ($codexGlossaryAssoc as $term => $def) {
        $codexGlossaryBlock .= trim($term) . ": " . trim($def) . "\n";
        $codexTerms[] = trim($def);
        $codexTerms[] = trim($term) . ": " . trim($def);
    }
}

// --- Build modules array for quick user display ---
$modulesArr = [];
if (isset($codexData['readme']['modules'])) {
    foreach ($codexData['readme']['modules'] as $mod) {
        if (isset($mod['name'], $mod['purpose'])) {
            $modulesArr[] = $mod['name'] . ": " . $mod['purpose'];
        }
    }
}

// --- Build Codex Other block/terms (use $modulesArr for modules) ---
$codexOtherBlock = "";
$codexOtherTerms = [];

// --- Version Info ---
if (isset($codexData['version']['number'])) {
    $codexOtherBlock .= "Codex Version: " . $codexData['version']['number'] . "\n";
    $codexOtherTerms[] = $codexData['version']['number'];
    $codexOtherTerms[] = "Codex Version: " . $codexData['version']['number'];
}

// --- Changelog (latest entry only) ---
if (isset($codexData['changelog'][0]['description'])) {
    $codexOtherBlock .= "Latest Changelog: " . $codexData['changelog'][0]['description'] . "\n";
    $codexOtherTerms[] = $codexData['changelog'][0]['description'];
    $codexOtherTerms[] = "Latest Changelog: " . $codexData['changelog'][0]['description'];
}

// --- Readme: Title & Vision ---
if (isset($codexData['readme']['title'])) {
    $codexOtherBlock .= "Readme Title: " . $codexData['readme']['title'] . "\n";
    $codexOtherTerms[] = $codexData['readme']['title'];
    $codexOtherTerms[] = "Readme Title: " . $codexData['readme']['title'];
}
if (isset($codexData['readme']['vision'])) {
    $codexOtherBlock .= "Vision: " . $codexData['readme']['vision'] . "\n";
    $codexOtherTerms[] = $codexData['readme']['vision'];
    $codexOtherTerms[] = "Vision: " . $codexData['readme']['vision'];
}

// --- Modules block for prompt/validator (already in $modulesArr) ---
if ($modulesArr) {
    $modBlock = implode("\n", $modulesArr);
    $codexOtherBlock .= "Modules:\n" . $modBlock . "\n";
    $codexOtherTerms = array_merge($codexOtherTerms, $modulesArr);
}

// --- Meta Section ---
if (isset($codexData['meta']['description'])) {
    $codexOtherBlock .= "Meta: " . $codexData['meta']['description'] . "\n";
    $codexOtherTerms[] = $codexData['meta']['description'];
    $codexOtherTerms[] = "Meta: " . $codexData['meta']['description'];
}

// --- Constitution Section ---
if (isset($codexData['constitution']['description'])) {
    $codexOtherBlock .= "Skyesoft Constitution: " . $codexData['constitution']['description'] . "\n";
    $codexOtherTerms[] = $codexData['constitution']['description'];
    $codexOtherTerms[] = "Skyesoft Constitution: " . $codexData['constitution']['description'];
}

// --- RAG Explanation ---
if (isset($codexData['ragExplanation']['summary'])) {
    $codexOtherBlock .= "RAG Explanation: " . $codexData['ragExplanation']['summary'] . "\n";
    $codexOtherTerms[] = $codexData['ragExplanation']['summary'];
    $codexOtherTerms[] = "RAG Explanation: " . $codexData['ragExplanation']['summary'];
}

// --- Included Documents ---
if (isset($codexData['includedDocuments']['summary'])) {
    $codexOtherBlock .= "Included Documents: " . $codexData['includedDocuments']['summary'] . "\n";
    $codexOtherTerms[] = $codexData['includedDocuments']['summary'];
    $codexOtherTerms[] = "Included Documents: " . $codexData['includedDocuments']['summary'];
    if (isset($codexData['includedDocuments']['documents'])) {
        $docList = implode(", ", $codexData['includedDocuments']['documents']);
        $codexOtherBlock .= "Documents: " . $docList . "\n";
        $codexOtherTerms[] = $docList;
        $codexOtherTerms[] = "Documents: " . $docList;
    }
}

// --- Shared Sources of Truth ---
if (isset($codexData['shared']['sourcesOfTruth'])) {
    $sotList = implode("; ", $codexData['shared']['sourcesOfTruth']);
    $codexOtherBlock .= "Sources of Truth: " . $sotList . "\n";
    $codexOtherTerms[] = $sotList;
    $codexOtherTerms[] = "Sources of Truth: " . $sotList;
}

// --- Shared AI Behavior Rules ---
if (isset($codexData['shared']['aiBehaviorRules'])) {
    $ruleList = implode(" | ", $codexData['shared']['aiBehaviorRules']);
    $codexOtherBlock .= "AI Behavior Rules: " . $ruleList . "\n";
    $codexOtherTerms[] = $ruleList;
    $codexOtherTerms[] = "AI Behavior Rules: " . $ruleList;
}
#endregion

#region 📊 Build sseSnapshot Summary & Array for Validation
// Initialize empty summary and values array
$snapshotSummary = "";
// Initialize empty array for SSE values
$sseValues = [];
// Helper function for flattening key-value pairs
function flattenSse($arr, &$summary, &$values, $prefix = "") {
    foreach ($arr as $k => $v) {
        $key = $prefix ? "$prefix.$k" : $k;
        if (is_array($v)) {
            // If this is a numerically-indexed array (e.g., announcements), make a summary line
            if (array_keys($v) === range(0, count($v) - 1)) {
                foreach ($v as $i => $entry) {
                    if (is_array($entry)) {
                        // For array of objects (e.g., announcements), summarize
                        $title = isset($entry['title']) ? $entry['title'] : '';
                        $desc = isset($entry['description']) ? $entry['description'] : '';
                        $summary .= "$key[$i]: $title $desc\n";
                        $values[] = trim("$title $desc");
                    } else {
                        $summary .= "$key[$i]: $entry\n";
                        $values[] = $entry;
                    }
                }
            } else {
                flattenSse($v, $summary, $values, $key);
            }
        } else {
            $summary .= "$key: $v\n";
            $values[] = $v;
        }
    }
}
// Only if sseSnapshot is not empty
if (is_array($sseSnapshot) && !empty($sseSnapshot)) {
    flattenSse($sseSnapshot, $snapshotSummary, $sseValues);
}
#endregion

#region 📋 User Codex Commands (Glossary, Modules, etc.)

// --- Show Full Glossary (deduped) ---
if (preg_match('/\b(show glossary|all glossary|list all terms|full glossary)\b/i', $prompt)) {
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

// --- Show Modules (if requested) ---
if (preg_match('/\b(show modules|list modules|all modules)\b/i', $prompt)) {
    $modulesDisplay = "";
    if (!empty($modulesArr)) {
        $modulesDisplay = nl2br(htmlspecialchars(implode("\n\n", $modulesArr)));
    } else {
        $modulesDisplay = "No modules found in Codex.";
    }
    echo json_encode([
        "response" => $modulesDisplay,
        "action" => "none"
    ]);
    exit;
}

// (Add more codex commands here if you want!)
#endregion

#region 📝 Build System Prompt
$systemPrompt = <<<PROMPT
You are Skyebot, an assistant for a signage company. You have three sources of truth:
- codexGlossary: internal company terms/definitions
- codexOther: other company knowledge base items (version, modules, constitution, etc.)
- sseSnapshot: current operational data (date, time, weather, KPIs, etc.)

Rules:
- Infer what the user is asking about (a term, a module, an operational value, etc.).
- If the answer is found in codexGlossary, codexOther, or sseSnapshot, respond ONLY with that value or definition. No extra wording.
- If no information is found, reply: "No information available."
- Do not repeat the user’s question, explain reasoning, or add extra context.

codexGlossary:
$codexGlossaryBlock

codexOther:
$codexOtherBlock

sseSnapshot:
$snapshotSummary
PROMPT;
#endregion

#region 💬 Build OpenAI Message Array
$messages = [
    ["role" => "system", "content" => $systemPrompt]
];
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
$allValid = array_merge($codexTerms, $codexOtherTerms, $sseValues);
$isValid = false;
foreach ($allValid as $entry) {
    if (strcasecmp(trim($responseText), trim($entry)) === 0) {
        $isValid = true;
        break;
    }
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