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
function resolveSkyesoftObject($prompt, $dynamicData) {
    $promptLower = strtolower($prompt);
    $best = array('layer' => null, 'key' => null, 'confidence' => 0.0);

    // ============================================================
    // 1. Collect searchable keys from SSE
    // ============================================================
    $searchSets = array();
    if (isset($dynamicData['sseSnapshot']) && is_array($dynamicData['sseSnapshot'])) {
        $searchSets['sse'] = array_keys($dynamicData['sseSnapshot']);
    }

    // ============================================================
    // 2. Collect searchable keys and aliases from Codex
    // ============================================================
    if (isset($dynamicData['codex']) && is_array($dynamicData['codex'])) {
        foreach ($dynamicData['codex'] as $slug => $mod) {
            if (!isset($searchSets['codex'])) $searchSets['codex'] = array();

            // Primary slug
            $searchSets['codex'][] = strtolower($slug);

            // Title text (plain, stripped of punctuation)
            if (isset($mod['title'])) {
                $titleClean = strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $mod['title']));
                $searchSets['codex'][] = $titleClean;
            }

            // Aliases (semantic equivalents)
            if (isset($mod['relationships']['aliases']) && is_array($mod['relationships']['aliases'])) {
                foreach ($mod['relationships']['aliases'] as $alias) {
                    $aliasClean = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $alias)));
                    $searchSets['codex'][] = $aliasClean;
                }
            }
        }
    }

    // ============================================================
    // 3. Compute similarity across all layers
    // ============================================================
    foreach ($searchSets as $layer => $keys) {
        foreach ($keys as $key) {
            if (!is_string($key) || strlen($key) < 3) continue;

            if (strpos($promptLower, $key) !== false) {
                $score = 100; // direct match boost
            } else {
                similar_text($promptLower, $key, $score);
            }

            if ($score > $best['confidence']) {
                $best = array('layer' => $layer, 'key' => $key, 'confidence' => $score);
            }
        }
    }

    // ============================================================
    // 4. Normalize output key (Codex-compatible form)
    // ============================================================
    if ($best['confidence'] > 30 && !empty($best['key'])) {
        $clean = strtolower($best['key']);
        // Remove anything in parentheses, e.g. "(TIS)"
        $clean = preg_replace('/\([^)]*\)/', '', $clean);
        // Remove punctuation and collapse spaces
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', trim($clean));
        // Convert to camelCase
        $normalized = lcfirst(str_replace(' ', '', ucwords($normalized)));
        $best['key'] = $normalized;

        error_log("🧭 resolveSkyesoftObject() matched {$best['layer']} → {$best['key']} ({$best['confidence']}%)");
        return $best;
    }


    error_log("⚠️ resolveSkyesoftObject() found no strong match (max={$best['confidence']}%)");
    return null;
}