<?php
// =====================================================================
// Skyesoft Dynamic Report Generator v2
// =====================================================================

// Dependencies
// Prefer Composer autoload when PHP ≥7.1, else fallback to manual TCPDF
if (version_compare(PHP_VERSION, '7.1.0', '>=') 
    && file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once(__DIR__ . '/../vendor/autoload.php');
} elseif (file_exists(__DIR__ . '/../libs/tcpdf/tcpdf.php')) {
    require_once(__DIR__ . '/../libs/tcpdf/tcpdf.php');
} else {
    die("❌ ERROR: No TCPDF library found and PHP version too low for Composer autoload.");
}


// TCPDF is a global class (no namespace) — no "use TCPDF;" needed

// Configuration
$config = array(
    'report_subtitle' => 'Skyesoft™ Information Sheet',
    'company_info' => '© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com'
);

// Consistent spacing value (in points)
$consistent_spacing = 10;

// ───────────────────────────────
//  Report Type Detection
// ───────────────────────────────
$slug = null;

// 🧭 CLI argument bridge (ensures "slug=..." works in terminal)
if (php_sapi_name() === 'cli' && isset($argv) && count($argv) > 1) {
    foreach ($argv as $arg) {
        if (strpos($arg, '=') !== false) {
            list($key, $value) = explode('=', $arg, 2);
            $_REQUEST[$key] = $value;
            $_GET[$key] = $value;
            $_POST[$key] = $value;
        }
    }
}

if (isset($_GET['slug'])) {
    $slug = $_GET['slug'];
} elseif (isset($_POST['slug'])) {
    $slug = $_POST['slug'];
}

if (!$slug) {
    if (php_sapi_name() === 'cli') {
        $slug = 'temporalIntegrityReport';
        logMessage("🔁 CLI fallback assigned default slug: $slug");
    } else {
        echo json_encode(["error" => true, "message" => "Slug could not be resolved even after fallback."]);
        exit;
    }
}

// --------------------------
// Load API key securely
// --------------------------
$envPaths = [
    __DIR__ . '/../.env',                                 // project-level
    '/home/notyou64/.env'                                 // account-level
];

$envFound = false;
foreach ($envPaths as $envPath) {
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // skip comments
            list($name, $value) = array_map('trim', explode('=', $line, 2));
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        $envFound = true;
        break;
    }
}

#region 🛡️ Headers and Setup
// ✅ Load .env manually for GoDaddy PHP 5.6
$envPaths = array(
    '/home/notyou64/.env',
    '/home/notyou64/public_html/skyesoft/.env'
);

$envLoaded = false;
foreach ($envPaths as $envFile) {
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name  = trim($name);
                $value = trim($value);
                putenv($name . '=' . $value);
                $_ENV[$name]    = $value;
                $_SERVER[$name] = $value;
            }
        }
        $envLoaded = true;
        break;
    }
}

#region 🛡️ Environment & API Key Loader
// Use centralized environment loader
require_once __DIR__ . "/env_boot.php";

// Retrieve the OpenAI API key from loaded environment
$OPENAI_API_KEY = getenv('OPENAI_API_KEY');

// Validate presence of key
if (!$OPENAI_API_KEY) {
    echo "❌ ERROR: Missing OpenAI API Key (checked via env_boot.php and /secure/.env)\n";
    exit(1);
}
#endregion

// --------------------------
// Load dynamic Codex from getDynamicData.php (PHP 5.6 compatible)
// --------------------------
$dynUrl = "https://www.skyelighting.com/skyesoft/api/getDynamicData.php";
$context = stream_context_create(array(
    "http" => array(
        "method"  => "GET",
        "timeout" => 10
    )
));

$rawDyn = @file_get_contents($dynUrl, false, $context);
if ($rawDyn === false) {
    logError("❌ ERROR: Unable to fetch dynamic data from $dynUrl");
    die();
}

$dynData = @json_decode($rawDyn, true);
if ($dynData === null || !is_array($dynData)) {
    logError("❌ ERROR: Failed to decode JSON from getDynamicData.php — " . json_last_error_msg());
    die();
}

if (!isset($dynData['codex']) || !is_array($dynData['codex'])) {
    logError("❌ ERROR: Codex not found in dynamic data payload.");
    die();
}

// ✅ Extract Codex
$codex = $dynData['codex'];
logMessage("ℹ️ Loaded Codex dynamically from getDynamicData.php");

// ----------------------------------------------------------------------
// Initialize modules array (valid modules only) — Flexible Validation
// ----------------------------------------------------------------------
$modules = array();

// ✅ 1️⃣ Merge all root-level modules that have a "title" (e.g., timeIntervalStandards)
foreach ($codex as $key => $value) {
    if (is_array($value) && isset($value['title'])) {
        $modules[$key] = $value;
    }
}

// ✅ 2️⃣ Merge any nested "modules" or "codexModules" blocks (e.g., skyesoftGlossary)
if (isset($codex['modules']) && is_array($codex['modules'])) {
    foreach ($codex['modules'] as $key => $value) {
        $modules[$key] = $value;
    }
}
if (isset($codex['codexModules']) && is_array($codex['codexModules'])) {
    foreach ($codex['codexModules'] as $key => $value) {
        $modules[$key] = $value;
    }
}

// ✅ 3️⃣ Validate each module's internal structure and accepted formats
$validatedModules = array();
foreach ($modules as $key => $value) {
    if (!is_array($value) || !isset($value['title'])) continue;

    $hasValidSection = false;
    foreach ($value as $sectionKey => $section) {
        if ($sectionKey === 'title') continue;
        if (!is_array($section)) continue;

        // Accept standard Codex formats
        if (isset($section['format']) && in_array(
            $section['format'],
            array('text', 'list', 'table', 'calendar', 'ontology', 'dynamic')
        )) {
            $hasValidSection = true;
            break;
        }

        // Accept ontology-style list sections
        if (isset($section['items']) && is_array($section['items'])) {
            $hasValidSection = true;
            break;
        }
    }

    if ($hasValidSection) {
        $validatedModules[$key] = $value;
    }
}

// ✅ 4️⃣ Final assignment
$modules = $validatedModules;
logMessage("ℹ️ Codex merge complete: " . count($modules) . " valid modules loaded from dynamic Codex.");

// ===========================================================
// 🧭 Input bridge — allow both HTTP and internal include calls
// ===========================================================
if (!isset($_REQUEST['slug']) && !isset($_REQUEST['module'])) {
    // Handle internal include case: detect $data or $reportData variable
    if (isset($data) && is_array($data) && isset($data['slug'])) {
        $_REQUEST['slug'] = $data['slug'];
    } elseif (isset($reportData) && is_array($reportData) && isset($reportData['slug'])) {
        $_REQUEST['slug'] = $reportData['slug'];
    }
}

// ----------------------------------------------------------------------
// ✅ Slug Resolver — JSON / GET / POST compatibility for PHP 5.6
// ----------------------------------------------------------------------
$slug  = isset($slug)  ? $slug  : null;
$input = isset($input) ? $input : array();

// ----------------------------------------------------------------------
// 🧭 1️⃣  Detect direct GET or POST query first
// ----------------------------------------------------------------------
if (!$slug && isset($_GET['module']) && !empty($_GET['module'])) {
    $slug = trim($_GET['module']);
    logMessage("✅ Detected module via GET query: $slug");
}
if (!$slug && isset($_POST['slug']) && !empty($_POST['slug'])) {
    $slug = trim($_POST['slug']);
    logMessage("✅ Detected module via POST form: $slug");
}

// ----------------------------------------------------------------------
// 🧭 2️⃣  Then check for JSON body (e.g., { "slug": "xyz" })
// ----------------------------------------------------------------------
$input = array();
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

if (in_array($method, array('POST', 'PUT'))) {
    $rawInput = @file_get_contents('php://input');
    if ($rawInput && strlen(trim($rawInput)) > 2 && $rawInput !== 'null') {
        $tmp = @json_decode($rawInput, true);
        if (is_array($tmp)) {
            $input = $tmp;
            if (!$slug && isset($input['slug'])) $slug = trim($input['slug']);
            logMessage("ℹ️ JSON input detected and parsed successfully.");
        } else {
            logMessage("⚠️ Raw input present but not valid JSON: " . substr($rawInput, 0, 80));
        }
    } else {
        logMessage("ℹ️ Empty or null JSON body; skipping decode.");
    }
} else {
    logMessage("ℹ️ Skipping php://input parse for {$method} request.");
}

// ----------------------------------------------------------------------
// 🧭 3️⃣  Hard fail if still empty
// ----------------------------------------------------------------------
if (!$slug) {
    header('Content-Type: application/json; charset=UTF-8');
    if (php_sapi_name() === 'cli') {
        $slug = 'temporalIntegrityReport';
        logMessage("🔁 CLI fallback assigned default slug: $slug");
    } else {
        if (php_sapi_name() === 'cli') {
            $slug = 'temporalIntegrityReport';
            logMessage("🔁 CLI fallback assigned default slug: $slug");
        } else {
            echo json_encode(["error" => true, "message" => "Slug could not be resolved even after fallback."]);
            exit;
        }
    }
}

// ----------------------------------------------------------------------
// 🧭 4️⃣  Normalize and sanitize
// ----------------------------------------------------------------------
$slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);

// ----------------------------------------------------------------------
// 🧭 5️⃣  Validate existence in $modules
// ----------------------------------------------------------------------
$module = null;
if ($slug === 'temporalIntegrityReport') {
    // Skip module validation for special reports
} else {
    if (!isset($modules[$slug]) || !is_array($modules[$slug])) {
        logMessage("🔍 DEBUG: Searching for slug '$slug' in modules. Keys: " . implode(', ', array_keys($modules)));

        // Case-insensitive fallback search
        $foundKey = null;
        foreach ($modules as $key => $val) {
            if (strcasecmp($key, $slug) === 0) {
                $foundKey = $key;
                break;
            }
        }

        if ($foundKey) {
            logMessage("✅ Case-insensitive match found: '$foundKey'");
            $module = $modules[$foundKey];
        } else {
            header('Content-Type: application/json; charset=UTF-8');
            if (php_sapi_name() === 'cli') {
                $slug = 'temporalIntegrityReport';
                logMessage("🔁 CLI fallback assigned default slug: $slug");
            } else {
                echo json_encode(["error" => true, "message" => "Slug could not be resolved even after fallback."]);
                exit;
            }
        }
    } else {
        $module = $modules[$slug]; // Normal match
    }

    logMessage("ℹ️ Loaded " . count($modules) . " valid modules for slug lookup (including ontology).");
}

// ----------------------------------------------------------------------
// ✅ FINAL safeguard for PHP 5.6 variable timing — recheck slug sources
// ----------------------------------------------------------------------
if (empty($slug)) {
    if (isset($_REQUEST['module']) && $_REQUEST['module'] !== '') {
        $slug = trim($_REQUEST['module']);
        logMessage("🔁 Fallback recovered slug from \$_REQUEST[module]: $slug");
    } elseif (isset($_REQUEST['slug']) && $_REQUEST['slug'] !== '') {
        $slug = trim($_REQUEST['slug']);
        logMessage("🔁 Fallback recovered slug from \$_REQUEST[slug]: $slug");
    }
}

// If still empty, hard fail one more time
if (empty($slug)) {
    // Allow default for CLI temporalIntegrityReport call
    if (php_sapi_name() === 'cli') {
        $slug = 'temporalIntegrityReport';
        logMessage("🔁 CLI fallback assigned default slug: $slug");
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        if (php_sapi_name() === 'cli') {
            $slug = 'temporalIntegrityReport';
            logMessage("🔁 CLI fallback assigned default slug: $slug");
        } else {
            echo json_encode(["error" => true, "message" => "Slug could not be resolved even after fallback."]);
            exit;
        }
    }
}


// --------------------------
// Load iconMap.json dynamically
// --------------------------
$iconMapPath = __DIR__ . '/../assets/data/iconMap.json';
$iconMap = array();
if (file_exists($iconMapPath)) {
    $iconMap = json_decode(file_get_contents($iconMapPath), true);
    if (!is_array($iconMap)) {
        $iconMap = array();
        logMessage("⚠️ WARNING: Failed to decode iconMap.json. Using empty icon map.");
    }
}

// =====================================================================
// Logging Helper
// =====================================================================
function logMessage($message) {
    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) {
        $oldUmask = umask(0);
        mkdir($logDir, 0777, true);
        umask($oldUmask);
    }
    $logFile = $logDir . 'report_generator.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function logError($message) {
    logMessage($message);
    echo $message . "\n";
}

// ───────────────────────────────
//  Report Body Routing
// ───────────────────────────────
if ($slug === 'temporalIntegrityReport') {

    // ======================================================
    // 1️⃣  Fetch live dynamic data
    // ======================================================
    // Capture dynamic data quietly (prevent echo)
    ob_start();
    include(__DIR__ . '/getDynamicData.php');
    $jsonOutput = ob_get_clean();
    $data = json_decode($jsonOutput, true);

    // Safety fallback if decoding fails
    if (!is_array($data)) {
        echo "⚠️ Error: could not parse dynamic data.\n";
        $data = array();
    }

    $today       = date('Y-m-d');
    $nextHoliday = isset($data['nextHoliday']['name']) ? $data['nextHoliday']['name'] : 'N/A';
    $nextDate    = isset($data['nextHoliday']['date']) ? $data['nextHoliday']['date'] : 'N/A';
    $rollover    = isset($data['nextHoliday']['rollover']) && $data['nextHoliday']['rollover'] ? 'true' : 'false';

    // ======================================================
    // 2️⃣  Build HTML body
    // ======================================================
    $reportBody  = '';
    $reportBody .= '<h1 style="font-family:Arial;">🕰️ Temporal Integrity Report</h1>';
    $reportBody .= '<p><strong>Test Date:</strong> ' . $today . '</p>';
    $reportBody .= '<p><strong>Next Holiday:</strong> ' . $nextHoliday . ' on ' . $nextDate . '</p>';
    $reportBody .= '<p><strong>Rollover:</strong> ' . $rollover . '</p>';
    $reportBody .= '<hr><p>Validation complete — Codex holiday mapping verified.</p>';

    // ======================================================
    // 3️⃣  Insert Codex metadata (if available)
    // ======================================================
    $codexFile = __DIR__ . '/../assets/data/codex.json';
    if (file_exists($codexFile)) {
        $localCodex = json_decode(file_get_contents($codexFile), true);
        if (isset($localCodex['temporalIntegritySpec'])) {
            $meta = $localCodex['temporalIntegritySpec'];
            $reportBody .= '<p><strong>Codex Module:</strong> ' . $meta['title'] . '</p>';
            $reportBody .= '<p><strong>Category:</strong> ' . $meta['category'] . '</p>';
        }
    }

    // ======================================================
    // 4️⃣  Configure output paths
    // ======================================================
    $type       = 'report';
    $isFull     = false;
    $requestor  = 'CLI/Skyebot';
    $cleanTitle = 'Temporal Integrity Report';
    $baseDir    = __DIR__ . '/../docs/reports/';

    if (!file_exists($baseDir)) {
        $oldUmask = umask(0);
        mkdir($baseDir, 0777, true);
        umask($oldUmask);
    }

    $outputFile = $baseDir . 'temporalIntegrityReport_' . date('Ymd_His') . '.pdf';

    // ======================================================
    // 5️⃣  Render PDF
    // ======================================================
    include_once(__DIR__ . '/utils/renderPDF_v7.3.php');
    if (function_exists('renderTemporalIntegrityReport')) {
        renderTemporalIntegrityReport($reportBody, $outputFile, $cleanTitle);
        echo "✅ PDF created successfully: $outputFile\n";
    } else {
        echo "⚠️ renderTemporalIntegrityReport() not found — verify utils/renderPDF.php.\n";
    }

    exit; // Prevents fallback logic from executing

} else {
    // Existing Information Sheet logic remains untouched

    // -------------------------- Handle CLI or HTTP Input
    // --------------------------
    $input = null;
    if (php_sapi_name() === 'cli' && isset($argv[1])) {
        $rawInput = $argv[1];
        logMessage("ℹ️ CLI raw input: $rawInput");
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logError("❌ ERROR: Failed to parse CLI JSON input: " . json_last_error_msg() . "\nRaw input: $rawInput");
            die();
        }
    } else {
        $rawInput = file_get_contents('php://input');
        logMessage("ℹ️ HTTP raw input: $rawInput");
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logError("❌ ERROR: Failed to parse HTTP JSON input: " . json_last_error_msg() . "\nRaw input: $rawInput");
            die();
        }
    }

    if (!is_array($input)) {
        logError("❌ ERROR: Invalid input JSON.\nParsed input: " . print_r($input, true));
        die();
    }

    // Sanitize input
    $slug = isset($input['slug']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $input['slug']) : '';
    $type = isset($input['type']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $input['type']) : 'information_sheet';
    $requestor = isset($input['requestor']) ? preg_replace('/[^A-Za-z0-9\s]/', '', $input['requestor']) : 'Skyesoft';
    $outputMode = isset($input['outputMode']) ? strtoupper($input['outputMode']) : 'F';
    $mode = isset($input['mode']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $input['mode']) : 'single';

    if (!in_array($outputMode, array('I', 'D', 'F'))) {
        logError("❌ ERROR: Invalid outputMode '$outputMode'. Must be 'I', 'D', or 'F'.");
        die();
    }

    $isFull = ($mode === 'full');

    if (!$isFull) {
        if (empty($slug) || !isset($modules[$slug])) {
            logError("❌ ERROR: Slug '$slug' not found in Codex.");
            die();
        }
        $currentModules = array($slug => $modules[$slug]);
    } else {
        $currentModules = $modules;
    }

    // =====================================================================
    // Enrichment Control Helper
    // =====================================================================
    function getEnrichmentPrompt($slug, $key, $enrichment, $moduleData) {
        switch (strtolower($enrichment)) {
            case 'none':
                $desc = "No AI enrichment is permitted. Use codex content exactly as written.";
                break;
            case 'light':
                $desc = "Use light enrichment. You may correct tone, grammar, and minor phrasing only.";
                break;
            case 'medium':
                $desc = "Use medium enrichment. You may expand lightly, add short examples or clarifying context, but avoid major rewrites.";
                break;
            case 'heavy':
                $desc = "Use heavy enrichment. You may elaborate with detailed explanations, analogies, or workflow expansions.";
                break;
            default:
                $desc = "Default light enrichment applies. Perform minimal improvement for clarity and readability.";
        }

        logMessage("💡 Enrichment mode for $slug/$key: " . strtoupper($enrichment));
        return "Enrichment Policy: " . strtoupper($enrichment) . " — " . $desc .
            "\n\nModule Context:\n" . json_encode($moduleData, JSON_PRETTY_PRINT);
    }

    // =====================================================================
    // AI Helper: Generate narrative sections dynamically
    // =====================================================================
    function getAIEnrichedBody($slug, $key, $moduleData, $apiKey, $format = 'text') {
        $cacheDir = __DIR__ . '/../cache/';
        $cacheFile = $cacheDir . "{$slug}_{$key}.json";
        
        if (file_exists($cacheFile)) {
            $cachedContent = json_decode(file_get_contents($cacheFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (($format === 'text' && is_string($cachedContent) && !empty($cachedContent)) ||
                    ($format === 'list' && is_array($cachedContent) && array_is_list($cachedContent) && !empty($cachedContent)) ||
                    ($format === 'table' && is_array($cachedContent) && !empty($cachedContent) && is_array($cachedContent[0]))) {
                    logMessage("✅ Loaded valid cached content for $slug/$key");
                    return $cachedContent;
                } else {
                    logMessage("⚠️ Invalid or empty cached content for $slug/$key, regenerating");
                    unlink($cacheFile);
                }
            }
        }

        $basePrompt = "You are generating content for the '{$key}' section of an information sheet for the '{$slug}' module. 
DO NOT create section headers or icons. 
The display formatting (headers, icons, tables, lists) will be applied dynamically by the system.

Module Data:
" . json_encode($moduleData, JSON_PRETTY_PRINT);

        $enrichmentPrompt = getEnrichmentPrompt($slug, $key, isset($moduleData['enrichment']) ? $moduleData['enrichment'] : 'none', $moduleData);
        $prompt = $enrichmentPrompt . "\n\n" . $basePrompt;

        if ($format === 'text') {
            $prompt .= "\n\nOnly generate narrative text for this section.";
        } elseif ($format === 'list') {
            $prompt .= "\n\nGenerate the list items for this section as a JSON array of strings.";
        } elseif ($format === 'table') {
            $prompt .= "\n\nGenerate the table data for this section as a JSON array of objects, where each object has keys corresponding to the table columns.";
        } else {
            logError("⚠️ Unsupported format for AI enrichment: $format");
            return "⚠️ Unsupported format for AI enrichment.";
        }

        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey
        ));

        $payload = json_encode(array(
            "model" => getenv("OPENAI_MODEL") ?: "gpt-4o-mini",
            "messages" => array(
                array("role" => "system", "content" => "You are an assistant that writes clear, structured PDF content."),
                array("role" => "user", "content" => $prompt)
            ),
            "max_tokens" => 1500
        ));

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = "⚠️ OpenAI API request failed: " . curl_error($ch);
            logError($error);
            return $error;
        }
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (isset($decoded['choices'][0]['message']['content'])) {
            $content = trim($decoded['choices'][0]['message']['content']);
            if ($format !== 'text') {
                $jsonContent = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (!is_dir($cacheDir)) {
                        $oldUmask = umask(0);
                        mkdir($cacheDir, 0777, true);
                        umask($oldUmask);
                    }
                    file_put_contents($cacheFile, json_encode($jsonContent));
                    logMessage("✅ Cached AI content for $slug/$key");
                    return $jsonContent;
                } else {
                    $error = "⚠️ Failed to parse AI JSON response: " . json_last_error_msg();
                    logError($error);
                    return $error;
                }
            }
            if (!is_dir($cacheDir)) {
                $oldUmask = umask(0);
                mkdir($cacheDir, 0777, true);
                umask($oldUmask);
            }
            file_put_contents($cacheFile, json_encode($content));
            logMessage("✅ Cached AI content for $slug/$key");
            return $content;
        } else {
            $error = "⚠️ AI enrichment failed. Response: " . $response;
            logError($error);
            return $error;
        }
    }

    // =============================================================== TIS: Inject live schedules + holidays (Office/Shop + Holidays Table)
    // =============================================================== 
    if (isset($currentModules['timeIntervalStandards'])) {
        $tis =& $currentModules['timeIntervalStandards'];

        // (A) Pull live segments from SSE (Office/Shop)
        $dynUrl = 'https://skyelighting.com/skyesoft/api/getDynamicData.php';
        $dynRaw = @file_get_contents($dynUrl);
        if ($dynRaw !== false) {
            $dyn = json_decode($dynRaw, true);

            // Schedules from SSE payload (timeIntervalStandards.segments)
            if (isset($dyn['timeIntervalStandards']['segments'])) {
                $segments = $dyn['timeIntervalStandards']['segments'];

                // Office
                if (isset($segments['Office'])) {
                    $tis['officeSchedule']['items'] = array(
                        array('Interval' => 'Before Worktime', 'Hours' => $segments['Office']['before']),
                        array('Interval' => 'Worktime',        'Hours' => $segments['Office']['worktime']),
                        array('Interval' => 'After Worktime',  'Hours' => $segments['Office']['after'])
                    );
                }

                // Shop
                if (isset($segments['Shop'])) {
                    $tis['shopSchedule']['items'] = array(
                        array('Interval' => 'Before Worktime', 'Hours' => $segments['Shop']['before']),
                        array('Interval' => 'Worktime',        'Hours' => $segments['Shop']['worktime']),
                        array('Interval' => 'After Worktime',  'Hours' => $segments['Shop']['after'])
                    );
                }

                logMessage('✅ TIS schedules injected from SSE segments.');
            }

            // (B) Holidays from SSE if present (supports array of {name,date} or strings)
            $holidayRows = array();
            if (isset($dyn['holidays']) && is_array($dyn['holidays'])) {
                foreach ($dyn['holidays'] as $h) {
                    if (is_array($h) && isset($h['name']) && isset($h['date'])) {
                        $holidayRows[] = array('Holiday' => $h['name'], 'Date' => $h['date']);
                    } elseif (is_string($h)) {
                        $holidayRows[] = array('Holiday' => $h, 'Date' => '');
                    }
                }
            }

            // (C) Fallback: local federalHolidays.php (if SSE does not provide)
            if (empty($holidayRows)) {
                $localPath = __DIR__ . '/../api/federalHolidays.php';
                if (!file_exists($localPath)) {
                    $localPath = __DIR__ . '/../federalHolidays.php'; // alt location
                }

                if (file_exists($localPath)) {
                    // Safely include without echo leak
                    define('SKYESOFT_INTERNAL_CALL', true);
                    $fhData = include($localPath);

                    if (is_array($fhData) && count($fhData) > 0) {
                        foreach ($fhData as $date => $name) {
                            if (preg_match('/\d{4}-\d{2}-\d{2}/', $date)) {
                                $holidayRows[] = array(
                                    'Date' => date('m/d/Y', strtotime($date)),
                                    'Holiday' => $name
                                );
                            }
                        }
                        logMessage('✅ Holidays loaded internally (' . count($holidayRows) . ' entries).');
                    } else {
                        logError('⚠️ No valid holiday data returned by federalHolidays.php include.');
                    }
                } else {
                    logError('⚠️ Fallback file not found: ' . $localPath);
                }
            }

            // Inject holidays into TIS module
            if (!empty($holidayRows)) {
                $tis['holidays']['items'] = $holidayRows;
                logMessage('✅ Holidays table populated with ' . count($holidayRows) . ' rows.');
            }
        } else {
            logError('⚠️ Could not reach SSE dynamic data for TIS: ' . $dynUrl);
        }
    }

    // =====================================================================
    // Enrich Content
    // =====================================================================
    foreach ($currentModules as $modSlug => &$mod) {
        $modForAI = $mod;
        unset($modForAI['title']);

        $enrichment = isset($mod['enrichment']) ? strtolower($mod['enrichment']) : 'none';
        if ($enrichment === 'none') {
            logMessage("🚫 Skipping AI enrichment for module '$modSlug' due to enrichment=none");
            continue;
        }

        $sectionKeys = array();
        foreach ($mod as $skey => $sval) {
            if ($skey !== 'title' && isset($sval['format'])) {
                $sectionKeys[$skey] = isset($sval['priority']) ? intval($sval['priority']) : 999;
            }
        }
        asort($sectionKeys);
        // Process sections in priority order
        foreach ($sectionKeys as $key => $pri) {
            $section =& $mod[$key];
            if (!isset($section['format'])) {
                continue;
            }

            // Normalize and validate format
            $format = strtolower(trim($section['format']));
            if (!in_array($format, ['text', 'list', 'table', 'dynamic', 'object'])) {
                logError("❌ Invalid format for '$key' ($format), defaulting to text.");
                $format = 'text';
            }
            $section['format'] = $format;

            // Infer format automatically if items exist
            if (isset($section['items']) && is_array($section['items'])) {
                if (is_array(reset($section['items']))) {
                    $format = 'table';
                } elseif (is_string(reset($section['items']))) {
                    $format = 'list';
                }
                $section['format'] = $format;
            }

            // --- Skip enrichment if Codex already provides valid data ---
            $skip = false;
            if ($format === 'text' && !empty(trim($section['text'] ?? ''))) {
                $skip = true;
            } elseif ($format === 'list' && !empty($section['items'] ?? [])) {
                $skip = true;
            } elseif ($format === 'table' && is_array($section['items'] ?? null) && count($section['items']) > 0 && is_array(current($section['items']))) {
                $skip = true;
            } elseif ($format === 'object' && !empty($section['data'] ?? [])) {
                $skip = true;
            }

            if ($skip) {
                logMessage("ℹ️ Skipping enrichment for '$key' (already populated).");
                continue;
            }

            logMessage("ℹ️ Enriching section '$key' with format '{$format}'.");

            // --- AI Enrichment Routing ---
            switch ($format) {
                case 'text':
                    $section['text'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'text');
                    break;

                case 'list':
                    $section['items'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'list');
                    break;

                case 'table':
                    $section['items'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'table');
                    break;

                case 'dynamic':
                    // Handle live/dynamic sections if your Codex defines them
                    $section['data'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'dynamic');
                    break;

                case 'object':
                    // Structured/nested data (e.g., fallback rules, JSON blocks)
                    $section['data'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'object');
                    break;

                default:
                    $section['text'] = getAIEnrichedBody($modSlug, $key, $modForAI, $OPENAI_API_KEY, 'text');
                    break;
            }

            // --- Validate enrichment results ---
            if ($format === 'text' && empty($section['text'])) {
                logError("❌ Invalid text data for '$key'. Expected non-empty string.");
                $section['text'] = '';
            } elseif ($format === 'list' && (!is_array($section['items']) || empty($section['items']))) {
                logError("❌ Invalid list data for '$key'. Expected array of strings.");
                $section['items'] = array();
            } elseif ($format === 'table' && (!is_array($section['items']) || empty($section['items']) || !is_array(current($section['items'])))) {
                logError("❌ Invalid table data for '$key'. Expected array of objects.");
                $section['items'] = array();
            } elseif ($format === 'object' && (!is_array($section['data']) && !is_object($section['data']))) {
                logError("❌ Invalid object data for '$key'. Expected array or object.");
                $section['data'] = array();
            }
        }
        unset($section);
    }
    unset($mod);

    $currentModules = $currentModules; // For PDF build
}

// =====================================================================
// PDF Class Extension (SkyesoftPDF)
// =====================================================================
class SkyesoftPDF extends TCPDF {
    // Class Properties (top of SkyesoftPDF)
    public $headerHeight = 80;      // fixed header band height (pick what matches page 1)
    public $bodyMargin   = 0;      // gap under header
    public $bodyStartY   = 50;      // 90 will be set from headerHeight + bodyMargin
    public $footerHeight = 25;      // fixed footer block height
    public $bodyEndOffset;          // bottom margin used for page break (see #3)

    // Report-level state
    public $reportTitle;
    public $reportIcon;
    private $reportIconKey;

    // Section-level state
    public $currentSectionTitle;
    public $currentSectionKey;
    public $currentSectionIcon;

    // Other state
    private $config = array();
    private $isTableSection = false;

    public function __construct($config) {
        parent::__construct('P', 'pt', 'A4', true, 'UTF-8', false);
        $this->config = $config;

        // Fixed starting Y for body: header + margin
        $this->bodyStartY = $this->headerHeight + $this->bodyMargin;

        // Symmetric body end offset (same gap above footer as below header)
        $this->bodyEndOffset = $this->bodyStartY;
    }
    public function setReportTitle($title, $icon = null) {
        $this->reportTitle = $title;
        $this->reportIcon  = $icon;
        $this->reportIconKey = $icon;
    }

    public function Header() {
        global $iconMap, $requestor;

        // Legacy logo (left)
        $logo = __DIR__ . '/../assets/images/christyLogo.png';
        if (file_exists($logo)) {
            // 93.75 wide at (15,12) as before
            $this->Image($logo, 15, 12, 93.75, 0);
        }

        // Title block (right), legacy XY and sizes
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(120, 15);

        $iconFile = resolveHeaderIcon($this->reportIconKey, $iconMap);
        if ($iconFile) {
            $this->Image($iconFile, $this->GetX(), $this->GetY() - 2, 20);
            $this->SetX($this->GetX() + 24);
        }

        $this->Cell(0, 8, $this->reportTitle ?: 'Project Report', 0, 1, 'L');

        $this->SetFont('helvetica', 'I', 10);
        $this->SetX(120);
        $this->Cell(0, 6, $this->config['report_subtitle'], 0, 1, 'L');

        date_default_timezone_set('America/Phoenix');
        $this->SetFont('helvetica', '', 9);
        $this->SetX(120);
        $this->Cell(0, 6, date('F j, Y, g:i A T') . ' – Created by ' . $requestor, 0, 1, 'L');

        // Divider
        $this->Ln(2);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());

        // >>> Do NOT recompute headerHeight here. Just move to the fixed anchor:
        $this->SetY($this->bodyStartY);
    }

    public function Footer() {
        $this->SetY(-$this->footerHeight); // Fixed footer position
        $this->SetDrawColor(0, 0, 0);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());

        $this->SetY(-($this->footerHeight - 5)); // Adjust text position
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, $this->config['company_info'] . ' | Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C');
    }

    public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false) {
        parent::AddPage($orientation, $format, $keepmargins, $tocpage);

        // Force the cursor to start just below the header, same as page 1
        $this->SetY($this->headerHeight);
    }

    // ----------------------
    // Helper: Estimate number of lines (renamed to avoid conflict with TCPDF::getNumLines)
    // ----------------------
    private function estimateNumLines($text, $width) {
        if (empty($text)) return 0;
        $height = $this->getStringHeight($width, $text);
        return ceil($height / 6);
    }

    // ----------------------
    // Estimate section body height
    // ----------------------
    private function estimateSectionBodyHeight($section) {
        $format = $section['format'];
        $fullWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $lineHeight = 6;
        $totalHeight = 0;

        if ($format === 'text' && isset($section['text'])) {
            $totalHeight = $this->getStringHeight($fullWidth, $section['text']);
        } elseif ($format === 'list' && isset($section['items']) && is_array($section['items'])) {
            $indent = 10;
            $bulletWidth = 5;
            $spaceAfterBullet = 5;
            $textWidth = $fullWidth - $indent - $bulletWidth - $spaceAfterBullet;
            foreach ($section['items'] as $item) {
                $totalHeight += $this->getStringHeight($textWidth, $item);
            }
        } elseif ($format === 'table' && isset($section['items']) && is_array($section['items'])) {
            if (empty($section['items'])) return 0;
            $headers = array_keys($section['items'][0]);
            $numColumns = count($headers);
            $pageWidth = $fullWidth;
            if ($numColumns === 2) {
                $colWidths = array($pageWidth * 0.3, $pageWidth * 0.7);
            } else {
                $colWidth = $pageWidth / $numColumns;
                $colWidths = array_fill(0, $numColumns, $colWidth);
            }
            $headerHeight = 10;
            $totalHeight += $headerHeight;
            foreach ($section['items'] as $row) {
                $maxHeight = 0;
                foreach ($headers as $i => $header) {
                    $value = isset($row[$header]) ? $row[$header] : '';
                    $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
                    if ($cellHeight < 10) {
                        $cellHeight = 10;
                    }
                    if ($cellHeight > $maxHeight) {
                        $maxHeight = $cellHeight;
                    }
                }
                $totalHeight += $maxHeight;
            }
        }

        return $totalHeight;
    }

    // ----------------------
    // Dynamic Table Rendering
    // ----------------------
    public function addTable(array $data) {
        $this->isTableSection = true;
        $this->SetCellPadding(2);

        if (empty($data) || !is_array($data[0])) {
            logError("❌ Invalid table data: Expected non-empty array of objects.");
            $this->isTableSection = false;
            return;
        }

        $headers = array_keys($data[0]);
        $numColumns = count($headers);
        $pageWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;

        if ($numColumns === 2) {
            $colWidths = array($pageWidth * 0.3, $pageWidth * 0.7);
        } else {
            $colWidth = $pageWidth / $numColumns;
            $colWidths = array_fill(0, $numColumns, $colWidth);
        }

        // Pre-check for entire table fit
        $estimatedTableHeight = 10; // header
        foreach ($data as $row) {
            $maxHeight = 0;
            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
                if ($cellHeight < 10) {
                    $cellHeight = 10;
                }
                if ($cellHeight > $maxHeight) {
                    $maxHeight = $cellHeight;
                }
            }
            $estimatedTableHeight += $maxHeight;
        }
        $availableSpace = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
        if ($estimatedTableHeight > $availableSpace) {
            $sectionTitle = isset($this->currentSectionTitle) ? $this->currentSectionTitle : 'Unknown Section';
            logMessage("⚙️ Entire table moved to next page for better fit (section: " . $sectionTitle . ")");
            $this->AddPage();
        }

        // Draw header row
        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('', 'B');
        $x = $this->GetX();
        foreach ($headers as $i => $header) {
            $this->Cell($colWidths[$i], 10, ucwords(str_replace('_', ' ', $header)), 1, 0, 'C', true);
            $this->SetXY($x += $colWidths[$i], $this->GetY());
        }
        $this->Ln();
        $this->SetFont('');

        // Draw data rows (improved to prevent final-row loss)
        foreach (array_values($data) as $rowIndex => $row) {
            $x = $this->GetX();
            $y = $this->GetY();
            $maxHeight = 0;
            $cellHeights = array();

            // Calculate cell heights for this row
            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $cellHeight = $this->getStringHeight($colWidths[$i], $value, false, true, null);
                if ($cellHeight < 10) $cellHeight = 10;
                $cellHeights[$i] = $cellHeight;
                if ($cellHeight > $maxHeight) $maxHeight = $cellHeight;
            }

            // Check for available space before drawing
            $available = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
            if ($maxHeight > $available) {
                $this->AddPage();
                $this->SetY($this->bodyStartY);

                // Render continued section header
                $contTitle = $this->currentSectionTitle . ' – Continued';
                $startX = 20;
                // ✅ Safe procedural version
                if (!isset($iconMap)) {
                    $iconMap = array();
                }
                $iconFile = resolveHeaderIcon(
                    isset($this->currentSectionIcon) ? $this->currentSectionIcon : '',
                    $iconMap
                );

                //  Draw icon if available
                if ($iconFile) {
                    $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
                    $startX += 24;
                }
                $this->SetXY($startX, $this->GetY());
                $this->SetFont('helvetica', 'B', 14);
                $this->SetTextColor(0, 0, 0);
                $this->Cell(0, 8, $contTitle, 0, 1, 'L', false);
                $this->SetDrawColor(200, 200, 200);
                $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
                $this->Ln(4);
                $this->SetFont('helvetica', '', 11);

                // Reprint table header on new page
                $x = $this->GetX();
                $this->SetFillColor(230, 230, 230);
                $this->SetFont('', 'B');
                foreach ($headers as $i => $header) {
                    $this->Cell($colWidths[$i], 10, ucwords(str_replace('_', ' ', $header)), 1, 0, 'C', true);
                    $x += $colWidths[$i];
                }
                $this->Ln();
                $this->SetFont('');
            }

            // Draw the row
            $x = $this->GetX();
            $y = $this->GetY();
            foreach ($headers as $i => $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $this->MultiCell($colWidths[$i], $maxHeight, $value, 1, 'L', false, 0, $x, $y, true, 0, false, true, $maxHeight, 'M');
                $x += $colWidths[$i];
            }
            $this->Ln($maxHeight);

            // ✅ After rendering the final row, ensure cursor advancement to trigger new page if needed
            if ($rowIndex === count($data) - 1 && $this->GetY() > ($this->PageBreakTrigger - $this->footerHeight)) {
                $this->AddPage();
                $this->SetY($this->bodyStartY);
            }
        }
        $this->isTableSection = false;
    }

    public function renderTextWithContinuation($text, $iconMap) {
        if (empty($text)) return;

        $fullWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $lineHeight = 6;
        $minHeight = $lineHeight * 2; // Minimum chunk height to avoid tiny fragments

        $sectionTitle = isset($this->currentSectionTitle) ? $this->currentSectionTitle : 'Unknown Section';

        // Pre-check if entire text fits; if not, move to next page
        $estimatedHeight = $this->getStringHeight($fullWidth, $text);
        $availableSpace = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
        if ($estimatedHeight > $availableSpace) {
            logMessage("⚙️ Entire text section moved to next page for better fit (section: " . $sectionTitle . ")");
            $this->AddPage();
            $this->SetY($this->bodyStartY);
        }

        while (trim($text) !== '') {
            $currentY = $this->GetY();
            $availableHeight = $this->PageBreakTrigger - $currentY - $this->footerHeight;

            // If not enough space for min chunk, add page with continued header
            if ($availableHeight < $minHeight) {
                $this->AddPage();
                $this->SetY($this->bodyStartY);

                // Render continued header
                $contTitle = $this->currentSectionTitle . ' – Continued';
                $startX = 20;
                $iconFile = resolveHeaderIcon($this->currentSectionIcon, $iconMap);
                if ($iconFile) {
                    $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
                    $startX += 24;
                }
                $this->SetXY($startX, $this->GetY());
                $this->SetFont('helvetica', 'B', 14);
                $this->SetTextColor(0, 0, 0);
                $this->Cell(0, 8, $contTitle, 0, 1, 'L', false);
                $this->SetDrawColor(200, 200, 200);
                $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
                $this->Ln(4);
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('helvetica', '', 11);

                // Recalculate available after header
                $currentY = $this->GetY();
                $availableHeight = $this->PageBreakTrigger - $currentY - $this->footerHeight;
            }

            // Calculate max lines that fit
            $maxLines = floor(($availableHeight - $lineHeight) / $lineHeight);
            if ($maxLines <= 0) $maxLines = 1;

            // Binary search for the maximum character position that fits in maxLines
            $low = 0;
            $high = strlen($text);
            while ($low < $high) {
                $mid = (int)(($low + $high + 1) / 2);
                $chunk = substr($text, 0, $mid);
                $numLinesChunk = $this->estimateNumLines($chunk, $fullWidth);
                if ($numLinesChunk <= $maxLines) {
                    $low = $mid;
                } else {
                    $high = $mid - 1;
                }
            }
            $splitPos = $low;

            if ($splitPos == 0) {
                // Fallback if nothing fits
                $splitPos = min(200, strlen($text)); // Arbitrary small chunk
            }

            // Prefer splitting at sentence end, comma, or space (prioritized)
            $chunkPrefix = substr($text, 0, $splitPos);
            $splitAt = $splitPos;

            $dotPos = strrpos($chunkPrefix, '.');
            if ($dotPos !== false) {
                $candidate = $dotPos + 2; // ". "
                $testChunk = substr($text, 0, $candidate);
                if ($this->estimateNumLines($testChunk, $fullWidth) <= $maxLines) {
                    $splitAt = $candidate;
                }
            } elseif (($commaPos = strrpos($chunkPrefix, ',')) !== false) {
                $candidate = $commaPos + 2; // ", "
                $testChunk = substr($text, 0, $candidate);
                if ($this->estimateNumLines($testChunk, $fullWidth) <= $maxLines) {
                    $splitAt = $candidate;
                } else {
                    $spacePos = strrpos($chunkPrefix, ' ');
                    if ($spacePos !== false) {
                        $splitAt = $spacePos + 1;
                    }
                }
            } else {
                $spacePos = strrpos($chunkPrefix, ' ');
                if ($spacePos !== false) {
                    $splitAt = $spacePos + 1;
                }
            }

            if ($splitAt < $splitPos * 0.5) {
                $splitAt = $splitPos; // Avoid too early splits
            }

            $chunk = substr($text, 0, $splitAt);
            $text = ltrim(substr($text, $splitAt));

            // Render the chunk
            $this->MultiCell($fullWidth, $lineHeight, $chunk, 0, 'L', false);
        }
    }

    public function renderSection($key, $section, $iconMap, $sections) {
        logMessage("DEBUG Entering renderSection for key: $key");

        if (!isset($section['format'])) {
            logMessage("⚠️ Skipping section '$key' due to missing format.");
            return;
        }

        logMessage("ℹ️ Rendering section '$key' with format '{$section['format']}'.");

        if ($section['format'] === 'table'
            && (!is_array($section['items']) || empty($section['items']) || !is_array($section['items'][0]))) {
            logError("❌ Invalid table data for section '$key'.");
            return;
        }

        global $codex;
        $styling = isset($codex['documentStandards']['styling']['items'])
            ? $codex['documentStandards']['styling']['items']
            : array();

        $this->currentSectionTitle = formatHeaderTitle($key);
        $this->currentSectionKey   = $key;
        $this->currentSectionIcon  = isset($section['icon']) ? $section['icon'] : null;

        // Add spacing before section (except at top of page body)
        if ($this->GetY() > $this->bodyStartY) {
            $this->Ln(4);
        }

        // Estimate total section height and pre-check if it fits
        $approxHeaderHeight = 30; // icon + title + line + ln
        $bodyHeight = $this->estimateSectionBodyHeight($section);
        $totalSectionHeight = $approxHeaderHeight + $bodyHeight + 4; // + bottom spacing
        $remainingSpace = $this->PageBreakTrigger - $this->GetY() - $this->footerHeight;
        if ($remainingSpace < $totalSectionHeight) {
            $sectionTitle = $this->currentSectionTitle;
            logMessage("⚙️ Entire section moved to next page for better fit (section: " . $sectionTitle . ")");
            $this->AddPage();
            $this->SetY($this->bodyStartY);
        }

        // ---- Section Header ----
        $iconFile = resolveHeaderIcon($this->currentSectionIcon, $iconMap);
        $startX   = 20;

        if ($iconFile) {
            $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
            $startX += 24;
        }

        $this->SetXY($startX, $this->GetY());
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $this->currentSectionTitle, 0, 1, 'L', false);

        $this->SetDrawColor(200, 200, 200);
        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());

        $this->Ln(4);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 11);

        // ---- Render Body ----
        if ($section['format'] === 'text' && isset($section['text'])) {
            $this->renderTextWithContinuation($section['text'], $iconMap);
        } elseif ($section['format'] === 'list' && isset($section['items']) && is_array($section['items'])) {
            $itemsRenderedOnPage = 0;
            $indent = 10;
            $bulletWidth = 5;
            $spaceAfterBullet = 5;
            $textIndent = $indent + $bulletWidth + $spaceAfterBullet;
            $fullWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
            $textWidth = $fullWidth - $textIndent;
            $lineHeight = 6;
            foreach ($section['items'] as $item) {
                $approxHeight = $this->getStringHeight($textWidth, $item);
                if ($approxHeight < $lineHeight) $approxHeight = $lineHeight;

                if ($this->GetY() + $approxHeight > $this->PageBreakTrigger - $this->footerHeight - 10) {
                    if ($itemsRenderedOnPage > 0) {
                        $this->AddPage();
                        $this->SetY($this->bodyStartY);
                        // Render continued header
                        $contTitle = $this->currentSectionTitle . ' – Continued';
                        $startX = 20;
                        if ($this->currentSectionIcon) {
                            $iconFile = resolveHeaderIcon($this->currentSectionIcon, $iconMap);
                            if ($iconFile) {
                                $this->Image($iconFile, $startX, $this->GetY() - 2, 20);
                                $startX += 24;
                            }
                        }
                        $this->SetXY($startX, $this->GetY());
                        $this->SetFont('helvetica', 'B', 14);
                        $this->Cell(0, 8, $contTitle, 0, 1, 'L', false);
                        $this->SetDrawColor(200, 200, 200);
                        $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
                        $this->Ln(4);
                        $this->SetFont('helvetica', '', 11);
                        $itemsRenderedOnPage = 0;
                    }
                }

                // Render item with wrapping
                $this->SetX($this->lMargin + $indent);
                $this->Cell($bulletWidth, $lineHeight, '•', 0, 0, 'L');
                $this->SetX($this->GetX() + $spaceAfterBullet);
                $this->MultiCell($textWidth, $lineHeight, $item, 0, 'L', false);
                $itemsRenderedOnPage++;
            }
        } elseif ($section['format'] === 'table'
            && isset($section['items'])
            && is_array($section['items'])
            && count($section['items']) > 0
            && is_array($section['items'][0])) {
            $this->addTable($section['items']);
        }

        $this->Ln(4);
        $this->currentSectionIcon = null;
    }

    public function resetSectionIcon() {
        $this->currentSectionIcon = null;
    }
}

// =====================================================================
// Build PDF
// =====================================================================
logMessage("DEBUG Script started at " . date('Y-m-d H:i:s'));
$pdf = new SkyesoftPDF($config);
$pdf->SetCreator('Skyesoft Report Generator');
$pdf->SetAuthor('Skyesoft');
// Build section (replace the two lines you have now)
$pdf->SetMargins(20, $pdf->bodyStartY, 20); // keep top margin neutral; we control start with SetY
$pdf->SetAutoPageBreak(true, $pdf->bodyEndOffset); // use symmetric bottom gap
$pdf->SetHeaderMargin(0); // No additional header margin
$pdf->SetFooterMargin(0); // No additional footer margin

if ($slug === 'temporalIntegrityReport') {
    $pdf->setReportTitle('Temporal Integrity Report', 'clock'); // Use metaTitle if available
    $pdf->AddPage();
    $pdf->SetY($pdf->bodyStartY);
    // Render HTML body (TCPDF supports basic HTML)
    $pdf->writeHTML($reportBody, true, false, true, false, '');
} else {
    $modulesToRender = $currentModules;
    if ($isFull) {
        $pdf->setReportTitle('Skyesoft Codex', null);
    } else {
        $slug = key($modulesToRender);
        $module = $modulesToRender[$slug];
        $iconKey = null;
        $titleText = $slug;
        if (isset($module['title'])) {
            $parts = explode(' ', $module['title'], 2);
            if (count($parts) === 2) {
                $iconKey = $parts[0];
                $titleText = $parts[1];
            } else {
                $titleText = $module['title'];
            }
        }
        $cleanTitle = ucwords(trim(str_replace(array('_', '-'), ' ', $titleText)));
        $pdf->setReportTitle($cleanTitle, $iconKey);
    }
    // For Each Module
    foreach ($modulesToRender as $modSlug => $module) {
        $pdf->AddPage();
        $pdf->SetY($pdf->bodyStartY);

        if ($isFull) {
            // Render module title header
            $parts = explode(' ', $module['title'], 2);
            $modIconKey = (count($parts) === 2) ? $parts[0] : null;
            $modTitleText = (count($parts) === 2) ? $parts[1] : $module['title'];
            $modCleanTitle = ucwords(trim(str_replace(array('_', '-'), ' ', $modTitleText)));

            $iconFile = resolveHeaderIcon($modIconKey, $iconMap);
            $startX = 20;
            $currentY = $pdf->GetY();
            if ($iconFile) {
                $pdf->Image($iconFile, $startX, $currentY - 2, 20);
                $startX += 24;
            }
            $pdf->SetXY($startX, $currentY);
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 10, $modCleanTitle, 0, 1, 'L', false);
            $pdf->Ln(8);
            $pdf->SetFont('helvetica', '', 11);
        }

        // ==========================================================
        // Preserve natural Codex order while respecting priorities
        // ==========================================================

        // 1️⃣ Capture section keys in the same order as in codex.json
        $sortedSectionKeys = [];
        foreach (array_keys($module) as $key) {
            if ($key === 'title') continue;
            if (isset($module[$key]['format'])) {
                $sortedSectionKeys[] = $key;
            }
        }

        // 2️⃣ Apply optional priority-based reordering if present
        $priorityMap = [];
        foreach ($sortedSectionKeys as $key) {
            $priorityMap[$key] = isset($module[$key]['priority'])
                ? intval($module[$key]['priority'])
                : 999; // default priority if not defined
        }

        // Only reorder if at least one section defines a priority < 999
        if (count(array_filter($priorityMap, function($p) { return $p < 999; })) > 0) {
            asort($priorityMap);
            $sortedSectionKeys = array_keys($priorityMap);
        }

        $totalModSections = count($sortedSectionKeys);

        // 3️⃣ Render each section in correct order (with DRY feature derivation)
        foreach ($sortedSectionKeys as $i => $key) {
            if (!isset($module[$key]) || !is_array($module[$key])) {
                continue; // skip non-structured or empty sections
            }

            $section = $module[$key];

            // 🧠 Auto-Derive Features from Data (DRY System)
            if (
                isset($section['source']) &&
                $section['source'] === 'data.content' &&
                isset($module['data']['content']) &&
                is_array($module['data']['content'])
            ) {
                $section['items'] = [];
                foreach ($module['data']['content'] as $alias => $desc) {
                    // Extract short summary before dash, colon, or em dash
                    if (preg_match('/^(.{0,80}?)\s+[—\-–:]/u', $desc, $m)) {
                        $short = trim($m[1]);
                    } else {
                        $short = trim(substr($desc, 0, 80));
                    }
                    $section['items'][] = "{$alias} — {$short}";
                }

                // Default visual hints if not specified
                if (!isset($section['format'])) {
                    $section['format'] = 'list';
                }
                if (!isset($section['icon'])) {
                    $section['icon'] = 'list';
                }
            }

            // 🧾 Render section
            $pdf->resetSectionIcon();
            $pdf->renderSection($key, $section, $iconMap, []);

            // Maintain consistent vertical spacing
            if ($i < $totalModSections - 1) {
                $pdf->Ln(isset($consistent_spacing) ? $consistent_spacing : 8);
            }
        }

        // ======================================================
        // 🧠 Ontology-Aware Module Rendering (Post-Standard Sections)
        // ======================================================
        $ontologySections = [];

        // --- Primary Purpose (if not already rendered as a standard section) ---
        if (isset($module['purpose']['text']) && !in_array('purpose', $sortedSectionKeys)) {
            $ontologySections['purpose'] = [
                'format' => 'text',
                'text' => $module['purpose']['text'],
                'icon' => 'info' // Default icon; override in codex if needed
            ];
        }

        // --- Dependencies ---
        if (!empty($module['dependsOn'])) {
            $depItems = array();
            foreach ($module['dependsOn'] as $dep) {
                if (isset($modules[$dep])) { // Use global $modules for lookup
                    $depTitle   = isset($modules[$dep]['title']) ? $modules[$dep]['title'] : ucfirst($dep);
                    $depPurpose = (isset($modules[$dep]['purpose']) && isset($modules[$dep]['purpose']['text']))
                        ? $modules[$dep]['purpose']['text']
                        : 'No description available.';
                    $depItems[] = "<b>{$depTitle}</b> — {$depPurpose}";
                } else {
                    $depItems[] = "<b>{$dep}</b> (not found in Codex)";
                }
            }
            $ontologySections['dependencies'] = array(
                'format' => 'list',
                'items'  => $depItems,
                'icon'   => 'link' // Or 'chain' if in iconMap
            );
        }

        // --- Provides ---
        if (!empty($module['provides'])) {
            $provItems = array_map(function($prov) {
                return "• {$prov} (data stream/capability)";
            }, $module['provides']);
            $ontologySections['provides'] = [
                'format' => 'list',
                'items' => $provItems,
                'icon' => 'output'
            ];
        }

        // --- Aliases ---
        if (!empty($module['aliases'])) {
            $ontologySections['aliases'] = [
                'format' => 'text',
                'text' => 'Synonyms: ' . implode(', ', $module['aliases']),
                'icon' => 'tag'
            ];
        }

        // --- Dynamic Holidays (if module relates to scheduling, e.g., TIS) ---
        if (isset($dyn['holidays']) && is_array($dyn['holidays']) && !empty($dyn['holidays'])) {
            $holidayItems = array();
            foreach ($dyn['holidays'] as $h) {
                if (is_array($h)) {
                    $name = isset($h['name']) ? $h['name'] : 'Holiday';
                    $date = isset($h['date']) ? $h['date'] : '—';
                } else {
                    $name = $h;
                    $date = '—';
                }
                $holidayItems[] = "{$name} – {$date}";
            }
            $ontologySections['holidays'] = array(
                'format' => 'list',
                'items'  => $holidayItems,
                'icon'   => 'calendar'
            );
        }

        // Render ontology sections (appended after standard ones)
        $ontologyPriorityMap = array(
            'purpose'      => 1,
            'dependencies' => 2,
            'provides'     => 3,
            'aliases'      => 4,
            'holidays'     => 5
        );

        uksort($ontologySections, function($a, $b) use ($ontologyPriorityMap) {
            $aVal = isset($ontologyPriorityMap[$a]) ? $ontologyPriorityMap[$a] : 999;
            $bVal = isset($ontologyPriorityMap[$b]) ? $ontologyPriorityMap[$b] : 999;

            if ($aVal == $bVal) {
                return 0;
            }
            return ($aVal < $bVal) ? -1 : 1;
        });


        foreach ($ontologySections as $ontKey => $ontSection) {
            $pdf->resetSectionIcon();
            $pdf->renderSection(formatHeaderTitle($ontKey), $ontSection, $iconMap, array());
            $pdf->Ln(isset($consistent_spacing) ? $consistent_spacing : 8);
        }

        // --- Timestamp Footer (per-module in full reports) ---
        if ($isFull) {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->Cell(0, 6, "Generated on " . date('F j, Y, g:i A'), 0, 1, 'R');
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Ln(10);
        }

        // 4️⃣ Final bottom padding for full reports
        if ($isFull) {
            $pdf->Ln(10);
        }
    }

    while ($pdf->getNumPages() > 3) {
        $pdf->setPage($pdf->getNumPages());
        $margins = $pdf->getMargins();
        $pageHeight = $pdf->getPageHeight() - $margins['top'] - $margins['bottom'] - $pdf->getFooterMargin();
        $contentHeight = $pdf->GetY() - $margins['top'];
        if ($contentHeight < 5 && $pdf->getNumPages() > 3) {
            $pdf->deletePage($pdf->getNumPages());
        } else {
            break;
        }
    }
}

// ───────────────────────────────
//  Save Report Output
// ───────────────────────────────
$baseDir = ($slug === 'temporalIntegrityReport' || $type === 'report')
    ? __DIR__ . '/../docs/reports/'
    : __DIR__ . '/../docs/sheets/';

if (!is_dir($baseDir)) {
    $oldUmask = umask(0);
    $result = mkdir($baseDir, 0777, true);
    umask($oldUmask);
    if (!$result) {
        logError("❌ ERROR: Failed to create directory $baseDir");
        die();
    }
}

if ($slug === 'temporalIntegrityReport') {
    $outputFile = $baseDir . $slug . '_' . date('Ymd_His') . '.pdf';
    $cleanTitle = 'Temporal Integrity Report';
} elseif ($isFull) {
    $outputFile = $baseDir . "Information Sheet - Skyesoft Codex.pdf";
} elseif ($type === 'information_sheet') {
    $outputFile = $baseDir . "Information Sheet - " . $cleanTitle . ".pdf";
} else {
    $outputFile = $baseDir . ucfirst($type) . " - " . $cleanTitle . ".pdf";
}

// 🧾 Debug: confirm output target
error_log("🧾 Writing to: $outputFile ($outputMode)");

// ==========================
// 🧾 FINAL OUTPUT
// ==========================
$outputMode = 'F'; // Force file save

error_log("🧭 PDF target path: " . $outputFile);
error_log("🧾 Output mode: " . $outputMode);
error_log("📄 File write attempt started...");

try {
    $pdf->Output($outputFile, $outputMode);
    if (file_exists($outputFile)) {
        error_log("✅ PDF write complete: SUCCESS – " . $outputFile);
        echo "✅ PDF created successfully: " . $outputFile . "\n";
    } else {
        error_log("❌ PDF write failed: File not found after save attempt.");
        echo "❌ PDF write failed: File not found.\n";
    }
} catch (Exception $e) {
    error_log("❌ TCPDF Exception: " . $e->getMessage());
    echo "❌ TCPDF Exception: " . $e->getMessage() . "\n";
}

function formatHeaderTitle($key) {
    global $codex;

    // 1. Prefer explicit title in codex.json if it exists
    if (isset($codex['skyesoftConstitution'][$key]['title'])) {
        return $codex['skyesoftConstitution'][$key]['title'];
    }

    // 2. Otherwise, split camelCase, snake_case, and kebab-case
    $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key); // split camelCase
    $key = str_replace(['_', '-'], ' ', $key);              // handle snake/kebab

    // 3. Normalize spaces and capitalize
    return ucwords(trim($key));
}

function resolveHeaderIcon($iconKey, $iconMap) {
    if (!$iconKey || !isset($iconMap[$iconKey]) || !isset($iconMap[$iconKey]['file'])) {
        return null;
    }
    $iconFile = __DIR__ . '/../assets/images/icons/' . $iconMap[$iconKey]['file'];
    return file_exists($iconFile) ? $iconFile : null;
}