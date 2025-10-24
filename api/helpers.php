<?php
// 📄 File: api/helpers.php
// Shared helper functions (PHP 5.6 compliant)

/**
 * Handle Codex-related commands (glossary, modules, constitution, etc.)
 * Always scales by using dynamicData Codex as the single source of truth.
 *
 * @param string $prompt
 * @param array $dynamicData
 */
function handleCodexCommand($prompt, $dynamicData) {
    $codex = isset($dynamicData['codex']) ? $dynamicData['codex'] : array();

    // Always hand Codex to AI to interpret semantically
    $messages = array(
        array(
            "role" => "system",
            "content" => "You are Skyebot™. Use the provided Codex to answer semantically. " .
                         "Codex contains glossary, modules, and constitution. " .
                         "Interpret user intent naturally — if they ask for glossary terms, modules, or constitution, pull from the relevant section. " .
                         "If user asks for 'show' or 'list', provide a clean readable list. " .
                         "Never invent content outside the Codex."
        ),
        array(
            "role" => "system",
            "content" => json_encode($codex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ),
        array(
            "role" => "user",
            "content" => $prompt
        )
    );

    $response = callOpenAi($messages);
    sendJsonResponse($response, "codex", array("sessionId" => session_id()));
}

/**
 * Send JSON response with proper HTTP status code
 * @param mixed $response
 * @param string $action
 * @param array $extra
 * @param int $status
 */
function sendJsonResponse($response, $action = "none", $extra = array(), $status = 200) {
    http_response_code($status);
    $data = array_merge(array(
        "response" => is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response,
        "action"   => $action,
        "sessionId"=> session_id()
    ), $extra);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Authenticate a user (placeholder)
 * @param string $username
 * @param string $password
 * @return bool
 */
function authenticateUser($username, $password) {
    $validCredentials = array('admin' => password_hash('secret', PASSWORD_DEFAULT));
    return isset($validCredentials[$username]) && password_verify($password, $validCredentials[$username]);
}

/**
 * Create a new entity (placeholder)
 * @param string $entity
 * @param array $details
 * @return bool
 */
function createCrudEntity($entity, $details) {
    file_put_contents(__DIR__ . "/create_" . $entity . ".log", json_encode($details) . "\n", FILE_APPEND);
    return true;
}

/**
 * Read an entity based on criteria (placeholder)
 * @param string $entity
 * @param array $criteria
 * @return mixed
 */
function readCrudEntity($entity, $criteria) {
    return "Sample " . $entity . " details for: " . json_encode($criteria);
}

/**
 * Update an entity (placeholder)
 * @param string $entity
 * @param array $updates
 * @return bool
 */
function updateCrudEntity($entity, $updates) {
    file_put_contents(__DIR__ . "/update_" . $entity . ".log", json_encode($updates) . "\n", FILE_APPEND);
    return true;
}

/**
 * Delete an entity (placeholder)
 * @param string $entity
 * @param array $target
 * @return bool
 */
function deleteCrudEntity($entity, $target) {
    file_put_contents(__DIR__ . "/delete_" . $entity . ".log", json_encode($target) . "\n", FILE_APPEND);
    return true;
}

/**
 * Perform logout
 */
function performLogout() {
    session_unset();
    session_destroy();
    session_write_close();
    session_start();
    $newSessionId = session_id();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    setcookie('skyelogin_user', '', time() - 3600, '/', 'www.skyelighting.com');
}

/**
 * Normalize address
 * @param string $address
 * @return string
 */
function normalizeAddress($address) {
    $address = preg_replace('/\s+/', ' ', trim($address));
    $address = strtolower($address);
    $address = ucwords($address);
    $address = preg_replace_callback(
        '/\b(\d+)(St|Nd|Rd|Th)\b/i',
        function($matches) { return $matches[1] . strtolower($matches[2]); },
        $address
    );
    return $address;
}

/**
 * Get Assessor API URL for Arizona counties by FIPS code
 * @param string $stateFIPS
 * @param string $countyFIPS
 * @return string|null
 */
function getAssessorApi($stateFIPS, $countyFIPS) {
    if ($stateFIPS !== "04") return null;
    switch ($countyFIPS) {
        case "013": return "https://mcassessor.maricopa.gov/api";
        case "019": return "https://placeholder.pima.az.gov/api";
        default: return null;
    }
}

/**
 * Normalize Jurisdiction Names
 * @param string $jurisdiction
 * @param string|null $county
 * @return string|null
 */
function normalizeJurisdiction($jurisdiction, $county = null) {
    if (!$jurisdiction) return null;
    $jurisdiction = strtoupper(trim($jurisdiction));
    if ($jurisdiction === "NO CITY/TOWN") {
        return $county ? $county : "Unincorporated Area";
    }
    static $jurisdictions = null;
    if ($jurisdictions === null) {
        $path = __DIR__ . "/../assets/data/jurisdictions.json";
        if (file_exists($path)) {
            $jurisdictions = json_decode(file_get_contents($path), true);
        } else {
            $jurisdictions = array();
        }
    }
    foreach ($jurisdictions as $name => $info) {
        if (!empty($info['aliases'])) {
            foreach ($info['aliases'] as $alias) {
                if (strtoupper($alias) === $jurisdiction) {
                    return $name;
                }
            }
        }
    }
    return ucwords(strtolower($jurisdiction));
}

/**
 * Load and apply disclaimers for a report
 * @param string $reportType
 * @param array $context
 * @return array
 */
function getApplicableDisclaimers($reportType, $context = array()) {
    $file = __DIR__ . "/../assets/data/reportDisclaimers.json";
    if (!file_exists($file)) {
        return array("⚠️ Disclaimer library not found.");
    }
    $json = file_get_contents($file);
    $allDisclaimers = json_decode($json, true);
    if ($allDisclaimers === null) {
        return array("⚠️ Disclaimer library is invalid JSON.");
    }
    if (!isset($allDisclaimers[$reportType])) {
        return array("⚠️ No disclaimers defined for " . $reportType . ".");
    }
    $reportDisclaimers = $allDisclaimers[$reportType];
    $result = array();
    if (isset($reportDisclaimers['dataSources']) && is_array($reportDisclaimers['dataSources'])) {
        foreach ($reportDisclaimers['dataSources'] as $ds) {
            if (is_string($ds) && trim($ds) !== "") $result[] = $ds;
        }
    }
    if (is_array($context)) {
        foreach ($context as $key => $value) {
            if ($value && isset($reportDisclaimers[$key]) && is_array($reportDisclaimers[$key])) {
                $result = array_merge($result, $reportDisclaimers[$key]);
            }
        }
    }
    return empty($result) ? array("⚠️ No applicable disclaimers resolved.") : array_values(array_unique($result));
}

/**
 * Call OpenAI API with Google Search fallback
 * @param array $messages
 * @return string
 */
function callOpenAi($messages) {
    $apiKey = getenv("OPENAI_API_KEY");

    $dynamicPath = __DIR__ . "/dynamicData.json";
    $reportKeys = array();
    if (file_exists($dynamicPath)) {
        $dynamicData = json_decode(file_get_contents($dynamicPath), true);
        if (!empty($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec'])) {
            $reportKeys = array_keys($dynamicData['modules']['reportGenerationSuite']['reportTypesSpec']);
        }
    }

    $model = "gpt-4o-mini";
    $lastUserMessage = end($messages);
    $promptText = isset($lastUserMessage['content']) ? strtolower($lastUserMessage['content']) : "";

    foreach ($reportKeys as $reportKey) {
        if (strpos($promptText, strtolower($reportKey)) !== false ||
            strpos($promptText, 'report') !== false) {
            $model = "gpt-4o";
            break;
        }
    }

    $payload = json_encode(array(
        "model" => $model,
        "messages" => $messages,
        "temperature" => 0.1,
        "max_tokens" => 800
    ), JSON_UNESCAPED_SLASHES);

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $response = curl_exec($ch);
    if ($response === false) {
        $curlError = curl_error($ch);
        file_put_contents(__DIR__ . '/error.log', "OpenAI API Curl Error: " . $curlError . "\n", FILE_APPEND);
        sendJsonResponse("❌ Curl error: " . $curlError, "none", array("sessionId" => session_id()));
    }
    curl_close($ch);

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        file_put_contents(__DIR__ . '/error.log', "JSON Decode Error: " . json_last_error_msg() . "\nResponse: " . $response . "\n", FILE_APPEND);
        sendJsonResponse("❌ JSON decode error from AI.", "none", array("sessionId" => session_id()));
    }

    if (!isset($result["choices"][0]["message"]["content"])) {
        if (isset($result["error"]["message"])) {
            file_put_contents(__DIR__ . '/error.log', "OpenAI API Error: " . $result["error"]["message"] . "\nResponse: " . $response . "\n", FILE_APPEND);
            sendJsonResponse("❌ API error: " . $result["error"]["message"], "none", array("sessionId" => session_id()));
        } else {
            file_put_contents(__DIR__ . '/error.log', "Invalid OpenAI Response: " . $response . "\n", FILE_APPEND);
            sendJsonResponse("❌ Invalid response structure from AI.", "none", array("sessionId" => session_id()));
        }
    }

    return trim($result["choices"][0]["message"]["content"]);
}

/**
 * Perform a Google Custom Search API query and summarize results with AI.
 * @param string $query
 * @return array
 */
function googleSearch($query) {
    $apiKey = getenv("GOOGLE_SEARCH_KEY");
    $cx     = getenv("GOOGLE_SEARCH_CX");

    if (!$apiKey || !$cx) {
        return array("error" => "Google Search API not configured.");
    }

    $url = "https://www.googleapis.com/customsearch/v1?q=" . urlencode($query) .
           "&key=" . $apiKey . "&cx=" . $cx;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false || $err) {
        error_log("GoogleSearch curl error ($httpCode): $err");
        return array("error" => "Curl error: " . $err);
    }

    $json = json_decode($res, true);
    if (!$json || isset($json['error'])) {
        $msg = isset($json['error']['message']) ? $json['error']['message'] : "Invalid API response";
        error_log("GoogleSearch API error ($httpCode): " . $msg);
        return array("error" => $msg);
    }

    // Build snippets
    $summaries = array();
    $firstLink = null;
    if (!empty($json['items']) && is_array($json['items'])) {
        foreach ($json['items'] as $idx => $item) {
            $title   = isset($item['title'])   ? trim($item['title'])   : "";
            $snippet = isset($item['snippet']) ? trim($item['snippet']) : "";

            $snippet = preg_replace('/\s*\.\.\.\s*/', ' ', $snippet);

            if ($idx === 0 && isset($item['link'])) {
                $firstLink = $item['link']; // capture top result link
            }

            if ($title !== "" || $snippet !== "") {
                $entry = $title;
                if ($snippet !== "") {
                    $entry .= ($title !== "" ? ": " : "") . $snippet;
                }
                $summaries[] = $entry;
            }
        }
    }

    if (empty($summaries)) {
        return array("error" => "No useful search results.");
    }

    // Single result
    if (count($summaries) === 1) {
        return array(
            "summary" => $summaries[0],
            "raw"     => $summaries,
            "link"    => $firstLink
        );
    }

    // Summarize multiple
    $messages = array(
        array("role" => "system",
              "content" => "You are Skyebot™, given Google search snippets. " .
                           "Summarize what the search results are mainly about, " .
                           "in one or two factual sentences. Keep it concise and factual."),
        array("role" => "system", "content" => implode("\n", $summaries)),
        array("role" => "user", "content" => "Summarize the search results for: " . $query)
    );

    $summary = callOpenAi($messages);
    if (!$summary) {
        $summary = $summaries[0] . " " . (isset($summaries[1]) ? $summaries[1] : "");
    }

    return array(
        "summary" => trim($summary),
        "raw"     => $summaries,
        "link"    => $firstLink
    );
}

/**
 * Normalize a Codex module title for comparison.
 * - Removes leading emoji or symbol characters
 * - Removes all parenthetical phrases (e.g., "(TIS)", "(beta)")
 * - Collapses multiple spaces
 * - Trims whitespace
 */
//  Normalize Codex module titles for comparison
function normalizeTitle($title) {
    if (empty($title)) {
        return '';
    }

    // Remove leading emoji/symbols
    $title = preg_replace('/^[\p{So}\p{Sk}\p{Sm}\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]+/u', '', $title);

    // Remove *all* parentheticals
    $title = preg_replace('/\([^)]*\)/', '', $title);

    // Collapse multiple spaces
    $title = preg_replace('/\s+/', ' ', $title);

    return trim($title);
}

//  Get Starship Troopers–style CTA link
function getTrooperLink($slug) {
    $safeSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $slug));
    return "\n\n👉 Would you like to know more?\n[View Report](/docs/reports/{$safeSlug}.pdf)";
}
// Find Codex match in text
function findCodexMatch($text, $codex) {
    $sources = [
        'modules' => isset($codex['modules']) ? $codex['modules'] : [],
        'informationSheetSuite' => isset($codex['informationSheetSuite']['types']) ? $codex['informationSheetSuite']['types'] : [],
        'glossary' => isset($codex['glossary']) ? $codex['glossary'] : [],
        'includedDocuments' => isset($codex['includedDocuments']['documents']) ? $codex['includedDocuments']['documents'] : []
    ];

    foreach ($sources as $type => $entries) {
        foreach ($entries as $key => $entry) {
            $title = is_array($entry) && isset($entry['title']) ? $entry['title'] : (is_string($entry) ? $entry : $key);
            $cleanTitle = normalizeTitle($title);
            if (preg_match('/\(([A-Z0-9]+)\)/', $title, $m)) $acro = $m[1]; else $acro = null;

            if (
                stripos($text, $title) !== false ||
                stripos($text, $cleanTitle) !== false ||
                ($acro && stripos($text, $acro) !== false) ||
                stripos($text, $key) !== false
            ) return ['type'=>$type,'key'=>$key,'title'=>$title];
        }
    }
    return null;
}
// ===============================================================
// ?? Semantic Intent Helpers
// ===============================================================
// Handle Intent CRUD Operations (Create, Read, Update, Delete)
function handleIntentCrud($intentData, $sessionId) {
    $entity = isset($intentData['target']) ? strtolower(trim($intentData['target'])) : 'unknown';
    $action = isset($intentData['intent']) ? ucfirst(strtolower($intentData['intent'])) : 'Unknown';

    return [
        "response" => "🧾 Semantic CRUD request detected.\nAction: **$action**\nEntity: **$entity**\n\n(Handler under construction.)",
        "action"   => "crud_placeholder",
        "sessionId"=> $sessionId
    ];
}
// Handle Intent Report Generation (PHP 5.6+ compatible)
function handleIntentReport($intentData, $sessionId) {
    // 🧭 Normalize target
    $target = '';
    if (isset($intentData['target']) && is_string($intentData['target'])) {
        $target = preg_replace('/\s+/', '', strtolower(trim($intentData['target'])));
    }
    if ($target === '') {
        $target = 'unspecified';
    }

    global $dynamicData;
    $codex = array();
    if (isset($dynamicData['codex']['modules']) && is_array($dynamicData['codex']['modules'])) {
        $codex = $dynamicData['codex']['modules'];
    }

    // 🚨 Validation
    if (!isset($codex[$target])) {
        error_log("⚠️ Unknown or missing Codex module: " . $target);
        return array(
            "response"  => "⚠️ No valid report target specified.",
            "action"    => "error",
            "sessionId" => $sessionId
        );
    }

    // 📘 Primary Module
    $module = $codex[$target];
    $title  = isset($module['title']) ? $module['title'] : ucfirst($target);
    $reportData = array(
        "title"    => $title,
        "slug"     => $target,
        "sections" => array()
    );

    // ----------------------------------------------------
    // 🔗 1. Primary Section
    // ----------------------------------------------------
    $reportData["sections"][] = array(
        "header"  => $title,
        "content" => $module
    );

    // ----------------------------------------------------
    // 🔗 2. Dependencies
    // ----------------------------------------------------
    if (isset($module['dependsOn']) && is_array($module['dependsOn'])) {
        foreach ($module['dependsOn'] as $dep) {
            if (isset($codex[$dep])) {
                $depTitle = isset($codex[$dep]['title']) ? $codex[$dep]['title'] : ucfirst($dep);
                $reportData["sections"][] = array(
                    "header"  => "Dependency: " . $depTitle,
                    "content" => $codex[$dep]
                );
                error_log("🔗 [" . $target . "] depends on → " . $depTitle);
            }
        }
    }

    // ----------------------------------------------------
    // 📡 3. Provides
    // ----------------------------------------------------
    if (isset($module['provides']) && is_array($module['provides']) && count($module['provides']) > 0) {
        $reportData["sections"][] = array(
            "header"  => "Provides Data Streams",
            "content" => $module['provides']
        );
        error_log("📡 [" . $target . "] provides → " . implode(', ', $module['provides']));
    }

    // ----------------------------------------------------
    // 🪞 4. Aliases (semantic match logging)
    // ----------------------------------------------------
    if (isset($module['aliases']) && is_array($module['aliases']) && count($module['aliases']) > 0) {
        error_log("🪞 [" . $target . "] aliases → " . implode(', ', $module['aliases']));
    }

    // ----------------------------------------------------
    // 🌐 5. Generate dynamic link
    // ----------------------------------------------------
    $reportUrl = "https://www.skyelighting.com/skyesoft/api/generateReports.php?module=" . urlencode($target);

    // ----------------------------------------------------
    // 🧠 6. Unified JSON response
    // ----------------------------------------------------
    $response = array(
        "response"    => "📘 The **" . $title . "** sheet has been compiled with ontology relationships.\n\n📄 [Open Report](" . $reportUrl . ")",
        "action"      => "sheet_generated",
        "slug"        => $target,
        "reportUrl"   => $reportUrl,
        "sessionId"   => $sessionId,
        "reportData"  => $reportData,
        "generatedAt" => date('c')
    );

    return $response;
}

// ======================================================================
// 🧭 Skyebot™ Semantic Object Resolver Helper
// Resolves the best matching Skyesoft object from SSE or Codex data.
// ======================================================================
// ======================================================================
// 🧠 Semantic Resolver – Skyebot™ v1.1 (Oct 2025)
// Normalizes user targets → Codex / SSE object keys (regex-free)
// ======================================================================

// ======================================================================
// 🧠 Semantic Resolver – Skyebot™ v1.2 (Oct 2025)
// Filters filler phrases and normalizes user targets → Codex/SSE keys
// ======================================================================

// ======================================================================
// 🧠 resolveSkyesoftObject() – Ontology-Aware Resolver (v2.0)
// ----------------------------------------------------------------------
// Purpose:
// • Uses Codex ontology (category, aliases, governs) to improve accuracy
// • Still compatible with PHP 5.6 (no typed arrays or arrow functions)
// • Returns best match with confidence score
// ======================================================================
if (!function_exists('resolveSkyesoftObject')) {
    function resolveSkyesoftObject($prompt, $data)
    {
        $promptLower = strtolower(trim($prompt));
        $codex = isset($data['codex']) ? $data['codex'] : array();
        if (empty($codex) || !is_array($codex)) {
            return array('key' => '', 'confidence' => 0, 'layer' => 'none');
        }

        $bestKey = '';
        $bestScore = 0;

        foreach ($codex as $key => $entry) {
            if (!is_array($entry)) continue;
            $score = 0;
            $keyNorm = strtolower(str_replace(array('_','-'), '', $key));

            // 1️⃣ Base lexical similarity
            similar_text($promptLower, $keyNorm, $sim);
            $score += $sim;

            // 2️⃣ Ontology reasoning layer
            if (isset($entry['ontology']) && is_array($entry['ontology'])) {
                $ont = $entry['ontology'];

                // Category (e.g., temporal, organizational)
                if (isset($ont['category']) && stripos($promptLower, strtolower($ont['category'])) !== false)
                    $score += 20;

                // Aliases
                if (isset($ont['aliases']) && is_array($ont['aliases'])) {
                    foreach ($ont['aliases'] as $alias) {
                        if (stripos($promptLower, strtolower($alias)) !== false)
                            $score += 15;
                    }
                }

                // Governs
                if (isset($ont['governs']) && is_array($ont['governs'])) {
                    foreach ($ont['governs'] as $rel) {
                        if (stripos($promptLower, strtolower($rel)) !== false)
                            $score += 10;
                    }
                }
            }

            // 3️⃣ Description text
            if (isset($entry['description']['text']) &&
                stripos($promptLower, strtolower($entry['description']['text'])) !== false) {
                $score += 10;
            }

            // 4️⃣ Track the highest score
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = $key;
            }
        }

        $confidence = round(min($bestScore, 100), 1);

        return array(
            'key' => $bestKey,
            'confidence' => $confidence,
            'layer' => isset($codex[$bestKey]['category'])
                ? $codex[$bestKey]['category']
                : (isset($codex[$bestKey]['ontology']['category'])
                    ? $codex[$bestKey]['ontology']['category']
                    : 'unknown')
        );
    }
}

// ======================================================================
// 🔍 querySSE()
// Purpose:
//   • Fallback semantic lookup inside SSE / dynamicData array
//   • Used only when LLM is unavailable or disabled
//   • Provides approximate, human-readable answers without hardcoding
// Compatibility: PHP 5.6
// ======================================================================
if (!function_exists('querySSE')) {
    function querySSE($prompt, $data)
    {
        if (!is_array($data) || empty($data)) {
            return null;
        }

        $promptLower = strtolower(trim($prompt));
        $flat = array();

        // --------------------------------------------------------------
        // Recursive flattening helper (dot notation)
        // --------------------------------------------------------------
        $flatten = function ($arr, $prefix = '') use (&$flatten, &$flat) {
            foreach ($arr as $k => $v) {
                $key = $prefix === '' ? $k : $prefix . '.' . $k;
                if (is_array($v)) {
                    $flatten($v, $key);
                } else {
                    $flat[$key] = $v;
                }
            }
        };
        $flatten($data);

        // --------------------------------------------------------------
        // Compute lexical similarity
        // --------------------------------------------------------------
        $bestKey = '';
        $bestScore = 0;
        foreach ($flat as $key => $value) {
            $keyNorm = strtolower(str_replace(array('_', '-', '.'), '', $key));
            similar_text($promptLower, $keyNorm, $score);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = $key;
            }
        }

        // --------------------------------------------------------------
        // Context weighting (adds bias for semantic proximity)
        // --------------------------------------------------------------
        if (strpos($promptLower, 'time') !== false && strpos($bestKey, 'time') !== false) {
            $bestScore += 10;
        }
        if (strpos($promptLower, 'date') !== false && strpos($bestKey, 'date') !== false) {
            $bestScore += 8;
        }
        if (strpos($promptLower, 'holiday') !== false && strpos($bestKey, 'holiday') !== false) {
            $bestScore += 12;
        }
        if (strpos($promptLower, 'weather') !== false && strpos($bestKey, 'weather') !== false) {
            $bestScore += 10;
        }

        // --------------------------------------------------------------
        // Humanized fallback (only if strong lexical match)
        // --------------------------------------------------------------
        if ($bestKey !== '' && $bestScore > 45) {
            $val = $flat[$bestKey];
            $cleanKey = ucwords(str_replace('.', ' → ', $bestKey));

            // Basic contextual phrasing (minimal, not hardcoded)
            $msg = '📡 ' . $cleanKey . ': ' . $val;
            if (preg_match('/\btime\b/i', $bestKey)) {
                $msg = '🕒 It appears the relevant time is ' . $val . '.';
            } elseif (preg_match('/\bdate\b/i', $bestKey)) {
                $msg = '📅 The relevant date seems to be ' . $val . '.';
            } elseif (preg_match('/holiday/i', $bestKey)) {
                $msg = '🎉 The upcoming holiday is ' . $val . '.';
            } elseif (preg_match('/weather/i', $bestKey)) {
                $msg = '🌤 Weather info: ' . $val . '.';
            }

            return array(
                'key'     => $bestKey,
                'value'   => $val,
                'score'   => $bestScore,
                'message' => $msg
            );
        }

        // No useful match
        return null;
    }
}
// ======================================================================
// 🌐 webFallbackSearch()
// ----------------------------------------------------------------------
// Performs a quick web lookup using Google and asks OpenAI to interpret
// the results instead of using regex. PHP 5.6 compatible.
// ======================================================================
if (!function_exists('webFallbackSearch')) {
    function webFallbackSearch($query)
    {
        $encoded = urlencode(trim($query));
        $url = "https://www.google.com/search?q={$encoded}";
        $html = @file_get_contents($url);

        // Bail early if no content
        if (!$html || strlen($html) < 200) {
            return array(
                "source" => "web",
                "response" => "🌍 I tried searching the web, but no readable summary was found.",
                "url" => $url
            );
        }

        // Truncate for token efficiency (~1k chars)
        $snippet = substr(strip_tags($html), 0, 1000);

        $system = "You are Skyebot™, an intelligent assistant summarizing factual web results.\n" .
                  "Use the following text (from Google Search) to answer the question truthfully and briefly.\n" .
                  "If it clearly states a fact, give it. Otherwise, respond with: 'I couldn’t find a clear answer.'";

        $messages = array(
            array("role" => "system", "content" => $system),
            array("role" => "user", "content" => "Question: {$query}\n\nExtracted web text:\n{$snippet}")
        );

        $summary = callOpenAi($messages);
        $clean = trim(strip_tags($summary));

        return array(
            "source" => "web",
            "response" => "🌍 According to the web: " . ($clean ?: "I couldn’t find a clear summary."),
            "url" => $url
        );
    }
}
// Compute seconds between "now" and a target "h:i A" today (America/Phoenix)
function secondsUntilTodayClock($targetClock, $tzName) {
    // Init timezone (America/Phoenix)
    $tz = new DateTimeZone($tzName);

    // Build "now" (server clock in tz)
    $now = new DateTime('now', $tz);

    // Parse target (sunset etc.) (format strict)
    $t = DateTime::createFromFormat('g:i A', trim($targetClock), $tz);
    if (!$t) return null;

    // Align target to today's date
    $t->setDate($now->format('Y'), $now->format('m'), $now->format('d'));

    // If already passed today, return 0 (or keep negative if you prefer)
    $diff = $t->getTimestamp() - $now->getTimestamp();
    return ($diff < 0) ? 0 : $diff;
}

// Turn seconds into "X hours Y minutes" (concise)
function humanizeSecondsShort($secs) {
    if ($secs === null) return '';
    $mins = floor($secs / 60);
    $hrs  = floor($mins / 60);
    $rem  = $mins % 60;
    if ($hrs > 0 && $rem > 0) return $hrs . " hours and " . $rem . " minutes";
    if ($hrs > 0) return $hrs . " hours";
    return $mins . " minutes";
}
/**
 * 🔹 resolveDayType()
 * Purpose: Determine the day classification (Workday / Weekend / Holiday)
 * Used by: temporal.php, scheduling modules, and SSE temporal layer.
 * Version: v2.2 – Codex-aligned; PHP 5.6 safe
 *
 * @param array|string $tis       Time Interval Standards (from Codex or JSON)
 * @param array        $holidays  Array of dynamic holiday objects
 * @param int|string   $timestamp UNIX timestamp or date string
 * @return array Structured classification
 */

function resolveDayType($tis, $holidays, $timestamp)
{
    // --- Normalize timestamp input ---
    if (!is_numeric($timestamp)) {
        $timestamp = strtotime($timestamp);
    }
    if (!$timestamp) $timestamp = time();

    // --- Normalize Codex + holidays ---
    if (!is_array($tis)) $tis = array();
    if (!is_array($holidays)) $holidays = array();

    // --- Load Codex-defined dayTypeArray or fallback ---
    $dayTypes = array();
    if (isset($tis['dayTypeArray']) && is_array($tis['dayTypeArray'])) {
        $dayTypes = $tis['dayTypeArray'];
    } else {
        // Fallback (standard office/work pattern)
        $dayTypes = array(
            array('DayType' => 'Workday', 'Days' => 'Mon,Tue,Wed,Thu,Fri'),
            array('DayType' => 'Weekend', 'Days' => 'Sat,Sun'),
            array('DayType' => 'Holiday', 'Days' => 'Dynamic')
        );
    }

    // --- Extract weekday + formatted date ---
    $weekday   = date('D', $timestamp); // e.g., Fri
    $todayDate = date('Y-m-d', $timestamp);
    $dayType   = 'Unknown';

    // --- Step 1: Base classification from Codex ---
    foreach ($dayTypes as $dt) {
        if (!isset($dt['Days']) || !isset($dt['DayType'])) continue;

        // Support comma or dash-separated formats
        $daysNorm = str_replace(array('-', ' '), ',', $dt['Days']);
        $daysArr  = array_map('trim', explode(',', $daysNorm));

        if (in_array($weekday, $daysArr)) {
            $dayType = ucfirst(strtolower($dt['DayType']));
            break;
        }
    }

    // --- Step 2: Override with dynamic holiday list ---
    foreach ($holidays as $h) {
        $hDate = isset($h['date']) ? $h['date'] : null;
        if ($hDate && $hDate === $todayDate) {
            $dayType = 'Holiday';
            break;
        }
    }

    // --- Step 3: Fallback normalization ---
    if ($dayType === 'Unknown') {
        $dow = date('N', $timestamp);
        if ($dow >= 1 && $dow <= 5) $dayType = 'Workday';
        else $dayType = 'Weekend';
    }

    // --- Step 4: Return structured result ---
    return array(
        'dayType'    => $dayType,
        'weekday'    => $weekday,
        'timestamp'  => $timestamp,
        'isWorkday'  => ($dayType === 'Workday'),
        'isWeekend'  => ($dayType === 'Weekend'),
        'isHoliday'  => ($dayType === 'Holiday')
    );
}