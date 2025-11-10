<?php
// 📁 File: api/getDynamicData.php
// Version: v5.0 – Codex-Compliant Initialization (strict mode, PHP 5.6 safe)

#region 🌐 HTTP Headers
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
#endregion

#region 🔧 Initialization and Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Normalize helper include path to avoid redeclaration on PHP 5.6
$helperPath = realpath(__DIR__ . '/helpers.php');
if ($helperPath && !in_array($helperPath, get_included_files())) {
    require_once $helperPath;
}

// Provide a lightweight fallback for getConst()
// (prevents “undefined function” before helpers are loaded)
if (!function_exists('getConst')) {
    function getConst($section, $key, $default = null) {
        global $codex;
        if (isset($codex[$section][$key])) {
            return $codex[$section][$key];
        }
        return $default;
    }
}

// Define base paths
$rootPath = realpath(dirname(__DIR__));
$dataPath = realpath($rootPath . '/assets/data');
$logPath  = realpath($rootPath . '/logs');

// 🔎 Force local error log if /logs/ is unavailable
$localLog = $logPath && is_dir($logPath)
    ? $logPath . '/error_log.txt'
    : $dataPath . '/error_log.txt';

ini_set('log_errors', '1');
ini_set('error_log', $localLog);
error_log("🧭 Skyesoft log initialized → {$localLog}");
#endregion

#region 📘 Load Codex Early (Dynamic Environment Safe)
$codex = array();
$codexFile = $dataPath . '/codex.json';

if (file_exists($codexFile)) {
    $rawCodex = file_get_contents($codexFile);
    $codex = json_decode($rawCodex, true);
    if (!is_array($codex)) {
        error_log('⚠️ Invalid Codex JSON — using empty structure');
        $codex = array();
    } else {
        error_log("✅ Codex loaded from {$codexFile}");
    }
} else {
    error_log('❌ Codex file not found at expected path: ' . $codexFile);
    $codex = array();
}
#endregion

#region ⚠️ Codex Integrity Check (strict structural validation)
$requiredCodexKeys = array(
    'timeIntervalStandards.segmentsOffice.items',
    'weatherData',
    'weatherData.defaultLocation',
    'weatherData.latitude',
    'weatherData.longitude'
);

// Step 1: Validate existence of required structures and keys
foreach ($requiredCodexKeys as $keyPath) {
    $parts = explode('.', $keyPath);
    $node = $codex;
    foreach ($parts as $p) {
        if (isset($node[$p])) {
            $node = $node[$p];
        } else {
            error_log("❌ Missing Codex key or structure: {$keyPath}");
            break;
        }
    }
}

// Step 2: Verify Office Worktime segment exists
$officeOK = false;
if (isset($codex['timeIntervalStandards']['segmentsOffice']['items'])
    && is_array($codex['timeIntervalStandards']['segmentsOffice']['items'])) {

    foreach ($codex['timeIntervalStandards']['segmentsOffice']['items'] as $segment) {
        if (isset($segment['Interval']) &&
            strtolower(trim($segment['Interval'])) === 'worktime' &&
            isset($segment['Hours']) &&
            trim($segment['Hours']) != '') {
            $officeOK = true;
            break;
        }
    }
}

if (!$officeOK) {
    error_log("🚫 Missing or invalid 'Worktime' interval in timeIntervalStandards.segmentsOffice.items");
}

// Step 3: Log successful structural verification
if ($officeOK
    && isset($codex['weatherData']['defaultLocation'])
    && isset($codex['weatherData']['latitude'])
    && isset($codex['weatherData']['longitude'])) {
    error_log("✅ Codex structural integrity verified (timeIntervalStandards + weatherData)");
}
#endregion

#region 🚫 Codex Failure Gate (Strict Mode – structure aware)
$codexIntegrityPassed = true;

// Validate key structural elements
if (!isset($codex['timeIntervalStandards']['segmentsOffice']['items'])
    || !is_array($codex['timeIntervalStandards']['segmentsOffice']['items'])) {
    error_log("🚫 Critical Codex failure: timeIntervalStandards.segmentsOffice.items missing or invalid.");
    $codexIntegrityPassed = false;
}

if (!isset($codex['weatherData']['defaultLocation'])
    || !isset($codex['weatherData']['latitude'])
    || !isset($codex['weatherData']['longitude'])) {
    error_log("🚫 Critical Codex failure: weatherData section incomplete.");
    $codexIntegrityPassed = false;
}

// Abort stream if any critical structure failed
if (!$codexIntegrityPassed) {
    header('Content-Type: application/json');
    echo json_encode(array(
        "error" => "❌ Critical Codex failure. SSE stream aborted.",
        "action" => "check_codex_integrity",
        "status" => "stopped"
    ));
    exit;
}

error_log("✅ Codex Failure Gate passed – all critical structures valid.");
#endregion

#region ⚙️ Cache Constants (used by safeJsonLoad + logCacheEvent)
if (!defined('CACHE_PATH')) define('CACHE_PATH',  $dataPath . '/cache.json');
if (!defined('CACHE_LOG'))  define('CACHE_LOG',   $logPath  . '/cache-events.log');
#endregion

#region 🧮 Holiday Derivation (Codex-based, single-source)
if (!isset($codex) || !is_array($codex)) {
    error_log("🚫 Codex not available — holiday derivation skipped.");
} else {
    // Extract holiday registry rules
    $registry = array();
    if (isset($codex['timeIntervalStandards']['holidayRegistry']['holidays'])) {
        $registry = $codex['timeIntervalStandards']['holidayRegistry']['holidays'];
    }

    // Helper: derive date from rule
    function resolveHolidayDate($rule, $year) {
        if (preg_match('/^[A-Za-z]{3,9}\s+\d{1,2}$/', $rule)) {
            return date('Y-m-d', strtotime($rule . ' ' . $year));
        }
        if (preg_match('/(First|Second|Third|Fourth)\s+([A-Za-z]+)\s+of\s+([A-Za-z]+)/i', $rule, $m)) {
            $nthMap = array('First'=>1,'Second'=>2,'Third'=>3,'Fourth'=>4);
            $n = $nthMap[ucfirst(strtolower($m[1]))];
            $weekday = ucfirst(strtolower($m[2]));
            $month = ucfirst(strtolower($m[3]));
            $monthNum = date('n', strtotime($month . ' 1 ' . $year));
            $firstDay = strtotime("$year-$monthNum-01");
            $firstWeekday = date('N', $firstDay);
            $targetWeekday = date('N', strtotime($weekday));
            $offsetDays = ($targetWeekday - $firstWeekday + 7) % 7;
            $day = 1 + $offsetDays + 7 * ($n - 1);
            return date('Y-m-d', mktime(0, 0, 0, $monthNum, $day, $year));
        }
        if (preg_match('/Last\s+([A-Za-z]+)\s+of\s+([A-Za-z]+)/i', $rule, $m)) {
            $weekday = ucfirst(strtolower($m[1]));
            $month = ucfirst(strtolower($m[2]));
            $monthNum = date('n', strtotime($month . ' 1 ' . $year));
            $lastDay = date('t', strtotime("$year-$monthNum-01"));
            $lastDate = strtotime("$year-$monthNum-$lastDay");
            $targetWeekday = date('N', strtotime($weekday));
            $lastWeekday = date('N', $lastDate);
            $offsetDays = ($lastWeekday - $targetWeekday + 7) % 7;
            $day = $lastDay - $offsetDays;
            return date('Y-m-d', mktime(0, 0, 0, $monthNum, $day, $year));
        }
        return null;
    }

    // Build derived holiday list from Codex rules
    $year = date('Y');
    $derivedHolidays = array();
    foreach ($registry as $h) {
        $date = resolveHolidayDate($h['rule'], $year);
        // Only include if date was successfully derived
        if ($date) {
            // Normalize to support multi-category arrays
            $categories = array();
            if (isset($h['categories']) && is_array($h['categories'])) {
                $categories = $h['categories'];
            } elseif (isset($h['category'])) {
                $categories = [$h['category']];
            } else {
                $categories = ['unspecified'];
            }

            $derivedHolidays[] = array(
                'name' => $h['name'],
                'date' => $date,
                'categories' => $categories
            );
        }
    }

    // Deduplicate by date (Codex-only)
    $unique = array();
    foreach ($derivedHolidays as $h) {
        $unique[$h['date']] = $h;
    }
    $holidaysFinal = array_values($unique);

    // Store into main data array for SSE
    $data['holidays'] = $holidaysFinal;

    error_log("✅ Holiday derivation completed (Codex-only, " . count($holidaysFinal) . " holidays for $year)");
}
#endregion

#region 🧩 Phase 2 – Holiday Data Normalization (Codex Unified)
// Purpose: Retire legacy 'federalHolidaysDynamic' mapping
// All holiday consumers must now use $data['holidays']
// Temporary compatibility note for any old front-end references.

if (!isset($weatherData)) {
    $weatherData = array();
}

// Maintain single-source consistency
if (isset($data['holidays']) && is_array($data['holidays'])) {
    $weatherData['holidays'] = $data['holidays'];
    error_log("🧩 Holiday data normalized → " . count($data['holidays']) . " total holidays available.");
}
#endregion

#region 📁 Constants and File Paths (strict Codex mode)

// ======================================================================
//  📘 STEP: Resolve Office Worktime from Codex (v5.4 Compliant)
//  PURPOSE: Replace static start/end with dynamic, Codex-governed values
// ======================================================================

$workdayStart = 0;
$workdayEnd   = 0;
$workDuration = 0;
$workSegmentFound = false;

if (isset($codex['timeIntervalStandards']['segmentsOffice']['items'])
    && is_array($codex['timeIntervalStandards']['segmentsOffice']['items'])) {

    foreach ($codex['timeIntervalStandards']['segmentsOffice']['items'] as $segment) {
        if (isset($segment['Interval']) &&
            strtolower(trim($segment['Interval'])) === 'worktime' &&
            !empty($segment['Hours'])) {

            // e.g. "7:30 AM – 3:30 PM" → ["7:30 AM", "3:30 PM"]
            $rangeParts = preg_split('/\s*[–-]\s*/u', trim($segment['Hours']));
            if (count($rangeParts) === 2) {
                list($startStr, $endStr) = $rangeParts;

                if (function_exists('timeStringToSeconds')) {
                    $workdayStart = timeStringToSeconds($startStr);
                    $workdayEnd   = timeStringToSeconds($endStr);
                    $workDuration = $workdayEnd - $workdayStart;
                    $workSegmentFound = true;
                } else {
                    error_log('❌ timeStringToSeconds() undefined when resolving Office Worktime.');
                }
            } else {
                error_log('⚠️ Invalid Hours format for Office Worktime segment: ' . $segment['Hours']);
            }
            break;
        }
    }
} else {
    error_log('⚠️ Codex segmentsOffice.items missing or invalid structure.');
}

// Fallback safeguard (prevents division by zero downstream)
if (!$workSegmentFound || $workDuration <= 0) {
    $workdayStart = 7.5 * 3600;   // 7:30 AM
    $workdayEnd   = 15.5 * 3600;  // 3:30 PM
    $workDuration = $workdayEnd - $workdayStart;
    error_log('⚠️ Using default Office Worktime (7:30 AM – 3:30 PM) due to invalid Codex data.');
}


if (!$workdayStart || !$workdayEnd) {
    error_log("🚫 Could not derive Office Workday times from Codex (segmentsOffice)");
    exit(json_encode(array(
        "error" => "Critical Codex structure error: Missing Office Worktime interval.",
        "status" => "stopped"
    )));
}

define('WORKDAY_START', $workdayStart);
define('WORKDAY_END',   $workdayEnd);

// Weather and KPI constants (direct Codex read)
define('WEATHER_LOCATION', getConst('weatherData', 'defaultLocation'));
define('LATITUDE',  getConst('weatherData', 'latitude'));
define('LONGITUDE', getConst('weatherData', 'longitude'));
define('DEFAULT_SUNRISE', getConst('kpiData', 'defaultSunrise'));
define('DEFAULT_SUNSET',  getConst('kpiData', 'defaultSunset'));

// Data and log paths
define('HOLIDAYS_PATH',        $dataPath . '/federal_holidays_dynamic.json');
define('COMPANY_HOLIDAYS_DYNAMIC', $dataPath . '/company_holidays_dynamic.json');
define('DATA_PATH',            $dataPath . '/skyesoft-data.json');
define('VERSION_PATH',         $dataPath . '/version.json');
define('ANNOUNCEMENTS_PATH',   $dataPath . '/announcements.json');
define('FEDERAL_HOLIDAYS_PHP', dirname(__FILE__) . '/federalHolidays.php');
define('CHAT_LOG_PATH',        $dataPath . '/chatLog.json');
define('WEATHER_CACHE_PATH',   $dataPath . '/weatherCache.json');
#endregion

#region 📊 Data Loading
// Version: Codex v1.5 (DRY-Compliant, Safe-Load Architecture)

// -------------------------------------------------------------
// Initialize base variables
// -------------------------------------------------------------
$mainData        = array();
$uiEvent         = null;
$siteMeta        = array();
$announcements   = array();
$federalHolidays = array();

// -------------------------------------------------------------
// Unified JSON loader (with logging + default fallback)
// -------------------------------------------------------------
if (!function_exists('safeJsonLoad')) {
    function safeJsonLoad($path, $context = 'unknown', $default = array()) {
        if (!is_readable($path)) {
            logCacheEvent('getDynamicData', "warning: ⚠️ {$context} not readable ({$path})");
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            logCacheEvent('getDynamicData', "warning: ❌ Failed to read {$context} ({$path})");
            return $default;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            logCacheEvent('getDynamicData', "warning: ⚠️ Invalid JSON in {$context}");
            return $default;
        }
        return $json;
    }
}

// -------------------------------------------------------------
// 1️⃣ Load main data & UI event
// -------------------------------------------------------------
$mainData = safeJsonLoad(DATA_PATH, 'DATA_PATH', array());

if (isset($mainData['uiEvent']) && $mainData['uiEvent'] !== null) {
    $uiEvent = $mainData['uiEvent'];
    $mainData['uiEvent'] = null;
    if (!@file_put_contents(DATA_PATH, json_encode($mainData, JSON_PRETTY_PRINT))) {
        logCacheEvent('getDynamicData', 'warning: ❌ Could not write back to DATA_PATH');
    }
}

// -------------------------------------------------------------
// 2️⃣ Load site meta and announcements
// -------------------------------------------------------------
$siteMeta      = safeJsonLoad(VERSION_PATH, 'VERSION_PATH', array());
$announceData  = safeJsonLoad(ANNOUNCEMENTS_PATH, 'ANNOUNCEMENTS_PATH', array());

if (isset($announceData['announcements']) && is_array($announceData['announcements'])) {
    $announcements = $announceData['announcements'];
}

// -------------------------------------------------------------
// 3️⃣ Load or generate federal holidays
// -------------------------------------------------------------
if (file_exists(FEDERAL_HOLIDAYS_PHP)) {
    define('SKYESOFT_INTERNAL_CALL', true); // triggers return-mode
    $federalHolidays = include FEDERAL_HOLIDAYS_PHP;
    if (!is_array($federalHolidays)) {
        logCacheEvent('getDynamicData', 'warning: ⚠️ federalHolidays.php did not return array');
        $federalHolidays = array();
    }
} else {
    $federalHolidays = safeJsonLoad(HOLIDAYS_PATH, 'HOLIDAYS_PATH', array());
    logCacheEvent('getDynamicData', 'warning: ⚠️ Using JSON fallback for holidays');
}

// Normalize associative {date:name} → indexed [{"date":..,"name":..}]
if (is_array($federalHolidays) && array_keys($federalHolidays) !== range(0, count($federalHolidays) - 1)) {
    $normalized = array();
    foreach ($federalHolidays as $date => $name) {
        $normalized[] = array('name' => $name, 'date' => $date);
    }
    $federalHolidays = $normalized;
}
#endregion

#region 🔐 Environment Variables
// Version: Codex v1.4
$env = array();
$envFiles = array(
    '/home/notyou64/secure/.env',
    realpath(dirname(__FILE__) . '/../secure/.env'),
    realpath(dirname(__FILE__) . '/../../../secure/.env'),
    realpath(dirname(dirname(__FILE__)) . '/../.data/.env'),
    realpath(dirname(__FILE__) . '/../../../.data/.env')
);

foreach ($envFiles as $p) {
    if ($p && is_readable($p)) {
        $lines = file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Parse key=value pairs
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments or blank lines
            if ($trimmed === '' || $trimmed[0] === '#') continue;

            // Must match a valid "KEY=value" pattern (letters, numbers, underscore)
            if (!isset($codex['ontology']['envFormatRule'])) continue;

            // Split at first '=' only
            list($k, $v) = array_map('trim', explode('=', $trimmed, 2));

            // Normalize key casing
            $k = strtoupper($k);
            $v = trim($v, "\"' \t");

            // Optional Codex ontology validation (if defined)
            if (isset($codex['ontology']['envKeys']) && !in_array($k, $codex['ontology']['envKeys'])) {
                logCacheEvent('getDynamicData', 'warning: ' . trim("[SSE] ⚠️ Non-standard env key skipped: $k"));
                continue;
            }

            $env[$k] = $v;
        }
        // Break
        break;
    }
}

function requireEnv($key) {
    $v = envVal($key);
    if (empty($v)) {  // Updated to empty() for consistency
        echo "data: " . json_encode(array('error' => "Missing env: $key")) . "\n\n";
        flush();
        exit;
    }
    return $v;
}
#endregion

#region ⚡ Codex-Aware Caching & TTL Enforcement
// Version: Codex v1.4
// -------------------------------------------------------------
// resolveCache() — Load or refresh cached data per TTL
// -------------------------------------------------------------
function resolveCache($key, $ttl = null, $fetchFn = null) {
    global $codex;

    // Step 1 – Resolve TTL dynamically from Codex or ENV
    if ($ttl === null) {
        $ttl = envVal('cacheTtlSeconds',
            isset($codex['kpiData']['cacheTtlSeconds'])
                ? intval($codex['kpiData']['cacheTtlSeconds'])
                : 300   // last-resort fallback
        );
    }

    $path  = CACHE_PATH;
    $cache = file_exists($path)
        ? json_decode(@file_get_contents($path), true)
        : array();

    $now      = time();
    $isValid  = isset($cache[$key]['ts']) && ($now - $cache[$key]['ts'] < $ttl);

    if ($isValid) {
        if (function_exists('sseEmit')) {
            sseEmit('cache_hit', array(
                'key' => $key,
                'age' => $now - $cache[$key]['ts']
            ));
        }
        return $cache[$key]['data'];
    }

    // Step 2 – Refresh data via callback
    $data = is_callable($fetchFn) ? $fetchFn() : null;
    $cache[$key] = array('ts' => $now, 'data' => $data);
    @file_put_contents($path, json_encode($cache, JSON_PRETTY_PRINT));

    if (function_exists('sseEmit')) {
        sseEmit('cache_miss', array('key' => $key));
    }

    logCacheEvent($key, 'refresh');
    return $data;
}

// -------------------------------------------------------------
// logCacheEvent() — Append lightweight diagnostics (optional)
// -------------------------------------------------------------
function logCacheEvent($key, $status) {
    $logPath = CACHE_LOG;

    if (!file_exists(dirname($logPath))) {
        @mkdir(dirname($logPath), 0755, true);
    }

    $msg = date('Y-m-d H:i:s') . " | Cache {$status}: {$key}\n";
    @file_put_contents($logPath, $msg, FILE_APPEND);

    // Optional SSE echo
    if (function_exists('sseEmit')) {
        sseEmit('cache_log', array('key' => $key, 'status' => $status));
    }
}
#endregion

#region ⏰ Time Computation
// Version: Codex v1.4
// --- Initialize timestamp helpers early to prevent undefined notices ---
$nowTs = time();  // current UNIX time baseline
$currentHour = (int)date('G', $nowTs);

// Define unified $timeData array for downstream references (e.g., UTCOffset)
$timeData = array(
    'currentUnixTime'          => $nowTs,
    'currentLocalTime'         => date('H:i:s', $nowTs),
    'currentDate'              => date('Y-m-d', $nowTs),
    'currentYearTotalDays'     => 365 + (date('L', $nowTs) ? 1 : 0),
    'currentYearDayNumber'     => (int)date('z', $nowTs) + 1,
    'currentYearDaysRemaining' => (365 + (date('L', $nowTs) ? 1 : 0)) - ((int)date('z', $nowTs) + 1),
    'currentMonthNumber'       => (int)date('n', $nowTs),
    'currentWeekdayNumber'     => (int)date('N', $nowTs),
    'currentDayNumber'         => (int)date('j', $nowTs),
    'currentHour'              => $currentHour,
    'timeOfDayDescription'     => ($currentHour < 12) ? 'morning' : (($currentHour < 17) ? 'afternoon' : 'evening'),
    'timeZone'                 => 'America/Phoenix',
    'UTCOffset'                => -7 // Phoenix is UTC-7 year-round
);

// Derivative fields used downstream
$currentUnixTime = $nowTs;
$currentTime     = $timeData['currentLocalTime'];
$currentDate     = $timeData['currentDate'];
$year            = (int)date('Y', $nowTs);
$yearTotalDays   = $timeData['currentYearTotalDays'];
$yearDayNumber   = $timeData['currentYearDayNumber'];
$yearDaysRemaining = $timeData['currentYearDaysRemaining'];
$monthNumber     = $timeData['currentMonthNumber'];
$weekdayNumber   = $timeData['currentWeekdayNumber'];
$dayNumber       = $timeData['currentDayNumber'];
$timeOfDayDesc   = $timeData['timeOfDayDescription'];
$utcOffset       = $timeData['UTCOffset'];

// 🕒 Add human-readable 12-hour time for SSE/UI
$currentTime12h = date('g:i A', $nowTs);
$currentTimeShort = date('g:i', $nowTs) . ' ' . strtolower(date('A', $nowTs));

// Compute day boundaries and workday interval flags
$currentDayStartUnix = strtotime(date('Y-m-d 00:00:00', $nowTs));
$currentDayEndUnix   = strtotime(date('Y-m-d 23:59:59', $nowTs));
$secondsRemaining    = $currentDayEndUnix - $nowTs;
$intervalLabel       = '1';  // Placeholder
$dayType             = isset($isWorkdayToday) && $isWorkdayToday ? '0' : '1';  // 0=workday, 1=non
#endregion

#region 📈 Record Counts (Stubbed)
// Version: Codex v1.4
$recordCounts = array(
    'actions' => 69,
    'entities' => 1,
    'locations' => 1,
    'contacts' => 1,
    'orders' => 0,
    'permits' => 0,
    'notes' => 0,
    'tasks' => 0
);
#endregion

#region 🔔 UI Event Handling
// Version: Codex v1.4
$dirty = false;
if (is_array($uiEvent) && isset($uiEvent['nonce'])) {
    $nowMs = (int) round(microtime(true) * 1000);
    $born = isset($uiEvent['nonce']) ? (int)$uiEvent['nonce'] : 0;
    $ttl = isset($uiEvent['ttlMs']) ? (int)$uiEvent['ttlMs'] : 15000;
    if ($born > 0 && ($nowMs - $born) > $ttl) {
        $mainData['uiEvent'] = null;
        $uiEvent = null;
        $dirty = true;
    }
}

if ($uiEvent && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $post = array();
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) $post = $json;
    }
    if (empty($post) && !empty($_POST)) $post = $_POST;

    if (isset($post['ackNonce'])) {
        $ack = (int) $post['ackNonce'];
        if (isset($uiEvent['nonce']) && (int)$uiEvent['nonce'] === $ack) {
            $mainData['uiEvent'] = null;
            $uiEvent = null;
            $dirty = true;
        }
    }
}

if ($dirty && is_writable(DATA_PATH)) {
    $fp = fopen(DATA_PATH, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($mainData, JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    } else {
        logCacheEvent('getDynamicData', 'warning: ' . trim('❌ Could not open ' . DATA_PATH . ' for writing'));
    }
}
#endregion

#region 🧭 Codex Tier Configuration
// Version: Codex v1.4
// ============================================================================
// Skyesoft Policy Governance Layer – Tier Configuration
// ----------------------------------------------------------------------------
// Reads the current Codex and extracts the active stream tiers.
// This allows getDynamicData.php to be guided by Codex-defined policies
// rather than fixed PHP logic.
// ============================================================================
$codexTiers = array();

if (isset($codex['sseStream']['tiers']) && is_array($codex['sseStream']['tiers'])) {
    $codexTiers = $codex['sseStream']['tiers'];
    // Optional: diagnostic logging
    // error_log('🧭 Codex Tiers Loaded: ' . json_encode(array_keys($codexTiers)));
} else {
    logCacheEvent('getDynamicData', 'warning: ' . trim('⚠️ Codex tiers missing or invalid – using legacy fallback.'));
}
#endregion

#region 📅 Time and Date Calculations
// Version: Codex v1.5 – Company Holiday Standard
date_default_timezone_set('America/Phoenix');
$now = $nowTs;
$yearTotalDays = (date('L', $now) ? 366 : 365);
$yearDayNumber = intval(date('z', $now)) + 1;
$yearDaysRemaining = $yearTotalDays - $yearDayNumber;
$monthNumber = intval(date('n', $now));
$weekdayNumber = intval(date('w', $now));
$dayNumber = intval(date('j', $now));
$currentHour = intval(date('G', $now));
$timeOfDayDesc = ($currentHour < 12) ? 'morning' : (($currentHour < 18) ? 'afternoon' : 'evening');
$dt = new DateTime('now', new DateTimeZone('America/Phoenix'));
$utcOffset = intval($dt->format('Z')) / 3600;
$currentDayStartUnix = strtotime('today', $now);
$currentDayEndUnix = strtotime('tomorrow', $now) - 1;

$currentDate = date('Y-m-d', $now);
$currentTime = date('h:i:s A', $now);
$currentSeconds = date('G', $now) * 3600 + date('i', $now) * 60 + date('s', $now);
$currentUnixTime = $now;

// 🗓️ Load company holiday list
$holidays = array();
if (is_readable(HOLIDAYS_PATH)) {
    $holidaysData = json_decode(file_get_contents(HOLIDAYS_PATH), true);
    $holidays = (is_array($holidaysData) && isset($holidaysData['holidays']) && is_array($holidaysData['holidays']))
        ? $holidaysData['holidays']
        : array();
}

// 🎯 Holiday Fallback & Next-Holiday Resolver (Codex v5.4.2)
if (empty($holidays)) {
    error_log("⚠️ No holidays found; loading from Codex fallback registry.");
    $codexPath = __DIR__ . '/../assets/data/codex.json';
    if (file_exists($codexPath)) {
        $codex = json_decode(file_get_contents($codexPath), true);
        if (isset($codex['timeIntervalStandards']['holidayRegistry']['holidays'])) {
            $holidays = $codex['timeIntervalStandards']['holidayRegistry']['holidays'];
        }
    }
}

// Ensure Codex is always loaded for time-interval standards
if (!isset($codex) || !is_array($codex)) {
    $codexPath = __DIR__ . '/../assets/data/codex.json';
    if (file_exists($codexPath)) {
        $codex = json_decode(file_get_contents($codexPath), true);
    } else {
        $codex = [];
        error_log("⚠️ Codex file not found; time intervals will default to zero.");
    }
}

// 🧭 Next-Holiday Determination
$nextHoliday = null;
$currentYear = intval(date('Y', strtotime($currentDate)));
$todayUnix = strtotime($currentDate);

// 🅰️  A. Same-Year Search
foreach ($holidays as $h) {
    if (!isset($h['rule'])) continue;
    $rule = $h['rule'];
    $ts = strtotime($rule . ' ' . $currentYear);

    if ($ts && $ts >= $todayUnix) {
        $nextHoliday = array(
            'name' => $h['name'],
            'date' => date('Y-m-d', $ts),
            'rollover' => false
        );
        break;
    }
}

// 🅱️  B. Year-Rollover (after final holiday, e.g. post-Christmas)
if (!$nextHoliday && !empty($holidays)) {
    foreach ($holidays as $h) {
        if (!isset($h['rule']) || !isset($h['categories'])) continue;
        $cats = array_map('strtolower', $h['categories']);
        // Only roll to next-year federal/company holidays
        if (in_array('company', $cats) || in_array('federal', $cats)) {
            $rule = $h['rule'];
            $ts = strtotime($rule . ' ' . ($currentYear + 1));
            if ($ts) {
                $nextHoliday = array(
                    'name' => $h['name'],
                    'date' => date('Y-m-d', $ts),
                    'rollover' => true
                );
                error_log("🎆 Year rollover → {$nextHoliday['name']} ({$nextHoliday['date']})");
                break;
            }
        }
    }
}

// 🅲️  C. Fallback Safety (no valid holiday found)
if (!$nextHoliday) {
    $nextHoliday = array(
        'name' => 'Undetermined (Provisional)',
        'date' => date('Y-m-d', strtotime('+7 days', $now)),
        'rollover' => true
    );
    error_log("⚠️ Fallback Tier 3 → provisional 7-day placeholder used.");
}

// 🧩 Diagnostic Log
error_log("🧭 Next holiday resolved: {$nextHoliday['name']} ({$nextHoliday['date']}) rollover=" . ($nextHoliday['rollover'] ? 'true' : 'false'));


// 🏢 Company Holiday Detection (Codex v5.4-compliant)
$isCompanyHoliday = false;

if (isset($data['holidays']) && is_array($data['holidays'])) {
    foreach ($data['holidays'] as $h) {
        // Normalize categories array
        $categories = array();
        if (isset($h['categories']) && is_array($h['categories'])) {
            $categories = array_map('strtolower', $h['categories']);
        } elseif (isset($h['category'])) {
            $categories = [strtolower($h['category'])];
        }

        // Check if "company" appears in category list and the date matches today
        if (
            in_array('company', $categories) &&
            isset($h['date']) &&
            $h['date'] === $currentDate
        ) {
            $isCompanyHoliday = true;
            error_log("🏢 Company holiday detected today: " . $h['name']);
            break;
        }
    }
}

// 🛠 Workday Determination
$isWorkday = isWorkday($currentDate, isset($data['holidays']) ? $data['holidays'] : array());

// ================================================================
//  🕓  Codex Time-Interval Integration (v5.4 TIS)
// ================================================================

// --- Derive Office Worktime from Codex --------------------------
$workStart = 0; $workEnd = 0;
if (isset($codex['timeIntervalStandards']['segmentsOffice']['items'])) {
    foreach ($codex['timeIntervalStandards']['segmentsOffice']['items'] as $segment) {
        if (strtolower($segment['Interval']) === 'worktime') {
            list($s, $e) = array_map('trim', preg_split('/\s*[–-]\s*/u', $segment['Hours']));
            $workStart = timeStringToSeconds($s);
            $workEnd   = timeStringToSeconds($e);
            break;
        }
    }
}

// --- Derive Shop Worktime from Codex (mirrored for SSE payload) ---
$shopStart = 0; $shopEnd = 0;
if (isset($codex['timeIntervalStandards']['segmentsShop']['items'])) {
    foreach ($codex['timeIntervalStandards']['segmentsShop']['items'] as $segment) {
        if (strtolower($segment['Interval']) === 'worktime') {
            list($s, $e) = array_map('trim', preg_split('/\s*[–-]\s*/u', $segment['Hours']));
            $shopStart = timeStringToSeconds($s);
            $shopEnd   = timeStringToSeconds($e);
            break;
        }
    }
}

// --- Determine current interval (Office-focused; Shop can be derived similarly if needed) ---
$currentSeconds = (int)date('G', $now)*3600 + (int)date('i', $now)*60 + (int)date('s', $now);

if ($currentSeconds < $workStart) {
    $intervalLabel = 0;        // Before Worktime
    $intervalName  = 'Before Worktime';
    $secondsRemaining = $workStart - $currentSeconds;
} elseif ($currentSeconds <= $workEnd) {
    $intervalLabel = 1;        // Worktime
    $intervalName  = 'Worktime';
    $secondsRemaining = max(0, $workEnd - $currentSeconds);
} else {
    $intervalLabel = 2;        // After Worktime
    $intervalName  = 'After Worktime';
    $secondsRemaining = ($currentDayEndUnix - $now);
}

// --- Day-type classification -----------------------------------
$weekday = (int)date('N', $now);
$dayType = $isCompanyHoliday ? 'Company Holiday'
          : ($weekday >= 6 ? 'Weekend'
          : 'Workday');

// --- Assemble interval object ----------------------------------
$intervalsArray = [
    'currentDaySecondsRemaining' => max(0, $currentDayEndUnix - $now),
    'intervalLabel'              => $intervalLabel,
    'intervalName'               => $intervalName,
    'dayType'                    => $dayType,
    'workdayIntervals' => [
        'office' => [
            'start' => $workStart,
            'end'   => $workEnd
        ],
        'shop' => [
            'start' => $shopStart,
            'end'   => $shopEnd
        ]
    ]
];
#endregion

#region 🔧 Utility Functions
// Version: Codex v1.5 – Company Holiday Standard

function timeStringToSeconds($timeStr) {
    // Normalize spacing and case
    $timeStr = trim(strtolower($timeStr));

    // Remove any unexpected characters (e.g., unicode dashes, spaces)
    $timeStr = preg_replace('/[^\d:apm\s]/', '', $timeStr);

    // Use strtotime to handle AM/PM safely
    $timestamp = strtotime($timeStr);

    if ($timestamp === false) {
        error_log("⚠️ Invalid time format passed to timeStringToSeconds(): {$timeStr}");
        return 0;
    }

    // Extract hours/minutes relative to midnight (not today’s date)
    $hours = (int)date('G', $timestamp);
    $minutes = (int)date('i', $timestamp);
    $seconds = (int)date('s', $timestamp);

    return ($hours * 3600) + ($minutes * 60) + $seconds;
}

// 🏢 Check if a date is a company holiday
function isHoliday($dateStr, $holidays) {
    if (isset($holidays['holidays']) && is_array($holidays['holidays'])) {
        $holidays = $holidays['holidays'];
    }
    foreach ($holidays as $holiday) {
        if (
            isset($holiday['category']) &&
            strtolower($holiday['category']) === 'company' &&
            isset($holiday['date']) &&
            $holiday['date'] === $dateStr
        ) {
            return true;
        }
    }
    return false;
}

// 🗓️ Check if a date is a workday (not weekend or company holiday)
function isWorkday($date, $holidays) {
    if (isset($holidays['holidays']) && is_array($holidays['holidays'])) {
        $holidays = $holidays['holidays'];
    }
    $day = date('w', strtotime($date)); // 0 = Sun, 6 = Sat
    return ($day != 0 && $day != 6 && !isHoliday($date, $holidays));
}

// ⏰ Find the next workday start time (company holidays only)
function findNextWorkdayStart($startDate, $holidays) {
    if (isset($holidays['holidays']) && is_array($holidays['holidays'])) {
        $holidays = $holidays['holidays'];
    }
    $date = strtotime($startDate . ' +1 day');
    while (!isWorkday(date('Y-m-d', $date), $holidays)) {
        $date = strtotime('+1 day', $date);
    }
    return strtotime(date('Y-m-d', $date) . ' ' . WORKDAY_START);
}
#endregion

#region 📊 Record Counts
// Version: Codex v1.4
$recordCounts = array('actions' => 0, 'entities' => 0, 'locations' => 0, 'contacts' => 0, 'orders' => 0, 'permits' => 0, 'notes' => 0, 'tasks' => 0);
if (is_readable(DATA_PATH)) {
    $data = json_decode(file_get_contents(DATA_PATH), true);
    if (is_array($data)) {
        foreach ($recordCounts as $key => $val) {
            if (isset($data[$key])) $recordCounts[$key] = count($data[$key]);
        }
    }
}
#endregion

#region 🔔 UI Event Handling
// Version: Codex v1.4
$dirty = false;
if (is_array($uiEvent) && isset($uiEvent['nonce'])) {
    $nowMs = (int) round(microtime(true) * 1000);
    $born = isset($uiEvent['nonce']) ? (int)$uiEvent['nonce'] : 0;
    $ttl = isset($uiEvent['ttlMs']) ? (int)$uiEvent['ttlMs'] : 15000;
    if ($born > 0 && ($nowMs - $born) > $ttl) {
        $mainData['uiEvent'] = null;
        $uiEvent = null;
        $dirty = true;
    }
}

if ($uiEvent && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $post = array();
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) $post = $json;
    }
    if (empty($post) && !empty($_POST)) $post = $_POST;

    if (isset($post['ackNonce'])) {
        $ack = (int) $post['ackNonce'];
        if (isset($uiEvent['nonce']) && (int)$uiEvent['nonce'] === $ack) {
            $mainData['uiEvent'] = null;
            $uiEvent = null;
            $dirty = true;
        }
    }
}

if ($dirty && is_writable(DATA_PATH)) {
    $fp = fopen(DATA_PATH, 'c+');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode($mainData, JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    } else {
        logCacheEvent('getDynamicData', 'warning: ' . trim('❌ Could not open ' . DATA_PATH . ' for writing'));
    }
}
#endregion

#region 🧭 Codex Tier Configuration
// Version: Codex v1.4
$codexTiers = array();

if (isset($codex['sseStream']['tiers']) && is_array($codex['sseStream']['tiers'])) {
    $codexTiers = $codex['sseStream']['tiers'];
} else {
    logCacheEvent('getDynamicData', 'warning: ' . trim('⚠️ Codex tiers missing or invalid – using legacy fallback.'));
}
#endregion

#region 🌦️ Weather Data (Codex v5.2 – unified holidays, no legacy keys)

// Codex & Env Defaults
$weatherLoc = isset($codex['weatherData']['defaultLocation'])
    ? $codex['weatherData']['defaultLocation']
    : 'Phoenix,US';
$weatherKey = envVal('WEATHER_API_KEY', '');

// Diagnostic: Confirm WEATHER_API_KEY resolution
if (empty($weatherKey)) {
    $envPaths = array(
        '/home/notyou64/secure/.env',
        realpath(dirname(__FILE__) . '/../secure/.env'),
        realpath('C:/Users/SteveS/Documents/skyesoft/secure/.env')
    );
    error_log("⚠️ WEATHER_API_KEY missing. Checking env paths:");
    foreach ($envPaths as $p) {
        error_log("   → {$p} " . ((file_exists($p) && is_readable($p)) ? "[✅ readable]" : "[❌ not found]"));
    }
    foreach ($envPaths as $p) {
        if (file_exists($p) && is_readable($p)) {
            $lines = file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, 'WEATHER_API_KEY=') === 0) {
                    list(, $val) = explode('=', $line, 2);
                    $weatherKey = trim($val);
                    putenv('WEATHER_API_KEY=' . $weatherKey);
                    error_log("✅ Loaded WEATHER_API_KEY manually from {$p}");
                    break 2;
                }
            }
        }
    }
    if (empty($weatherKey)) {
        error_log('❌ WEATHER_API_KEY could not be loaded — skipping weather fetch.');
    }
}

// Define timezone and offset early
date_default_timezone_set('America/Phoenix');
$utcOffset = -7; // Phoenix fixed offset (no DST)

// Initialize base weather array
$weatherData = array(
    'temp' => null,
    'icon' => '❓',
    'description' => 'Unavailable',
    'lastUpdatedUnix' => null,
    'sunrise' => null,
    'sunset' => null,
    'daytimeHours' => null,
    'nighttimeHours' => null,
    'forecast' => array()
);

// --- Utility helpers ---
function resolveApiUrl($endpoint, $opts = array()) {
    global $codex;
    $passedBase = isset($opts['base']) ? $opts['base'] : null;
    $base = !empty($passedBase)
        ? $passedBase
        : (isset($codex['apiMap']['openWeather']) ? $codex['apiMap']['openWeather'] : '');
    return rtrim($base, '/') . '/' . ltrim($endpoint, '/');
}

function fetchJsonCurl($url) {
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => getConst('curlConnectTimeout', 4),
        CURLOPT_TIMEOUT => getConst('curlTimeout', 6),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false, // GoDaddy SSL quirk fix
        CURLOPT_USERAGENT => 'SkyeSoft/1.0 (+skyelighting.com)',
    ));
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) return array('error' => $err, 'code' => $code);
    $json = json_decode($res, true);
    if (json_last_error() !== JSON_ERROR_NONE)
        return array('error' => 'Invalid JSON: ' . json_last_error_msg(), 'code' => $code, 'raw' => substr($res, 0, 200));
    return is_array($json) ? $json : array('error' => 'Non-array JSON', 'code' => $code);
}

// 🌤️ Fetch current conditions
$currentUrl = resolveApiUrl('weather', array('base' => $codex['apiMap']['openWeather'])) .
    '?q=' . rawurlencode($weatherLoc) .
    '&appid=' . rawurlencode($weatherKey) .
    '&units=imperial';
$current = fetchJsonCurl($currentUrl);

// Process current conditions
if (empty($current['error']) &&
    isset($current['main']['temp']) &&
    isset($current['weather'][0]['icon']) &&
    isset($current['sys']['sunrise']) &&
    isset($current['sys']['sunset'])) {

    $sunriseUnix = $current['sys']['sunrise'];
    $sunsetUnix  = $current['sys']['sunset'];

    $secondsPerHour = getConst('secondsPerHour', 3600);

    // --- Convert UTC timestamps to local Phoenix time correctly ---
    $tz = new DateTimeZone('America/Phoenix');

    $sunriseDT = new DateTime('@' . $sunriseUnix); // interpret as UTC
    $sunriseDT->setTimezone($tz);
    $sunriseLocal = $sunriseDT->format('g:i A');

    $sunsetDT = new DateTime('@' . $sunsetUnix);
    $sunsetDT->setTimezone($tz);
    $sunsetLocal = $sunsetDT->format('g:i A');

    // Optional debug logging (safe to remove after verification)
    // error_log("☀️ Sunrise UTC: {$sunriseUnix}, Local: {$sunriseLocal}");
    // error_log("🌇 Sunset  UTC: {$sunsetUnix}, Local: {$sunsetLocal}");

    // ======================================================================
    //  🌞 DAYLIGHT CALCULATIONS (Codex v5.4 compliant)
    //  Prevent division by zero if constants missing or malformed
    // ======================================================================
    $secondsPerHour = 3600; // Ensure defined (1 hour = 3600 seconds)
    $secondsPerDay  = getConst('secondsPerDay', 86400);

    if (!isset($sunriseUnix) || !isset($sunsetUnix) || $sunriseUnix >= $sunsetUnix) {
        error_log("⚠️ Invalid sunrise/sunset values → using defaults (6:00–18:00)");
        $sunriseUnix = strtotime('6:00 AM');
        $sunsetUnix  = strtotime('6:00 PM');
    }

    $daytimeSeconds   = max(0, $sunsetUnix - $sunriseUnix);
    $daytimeHours     = round($daytimeSeconds / $secondsPerHour, 2);
    $daytimeMins      = floor(($daytimeSeconds % $secondsPerHour) / 60);

    $nighttimeSeconds = max(0, $secondsPerDay - $daytimeSeconds);
    $nighttimeHours   = round($nighttimeSeconds / $secondsPerHour, 2);
    $nighttimeMins    = floor(($nighttimeSeconds % $secondsPerHour) / 60);


    $weatherData = array(
        'temp' => round($current['main']['temp']),
        'icon' => $current['weather'][0]['icon'],
        'description' => ucwords(strtolower($current['weather'][0]['description'])),
        'lastUpdatedUnix' => time(),
        'sunrise' => $sunriseLocal,
        'sunset' => $sunsetLocal,
        'daytimeHours' => "{$daytimeHours}h {$daytimeMins}m",
        'nighttimeHours' => "{$nighttimeHours}h {$nighttimeMins}m",
        'forecast' => array()
    );

    // 🌦️ 3-Day Forecast (optional stub)
    $forecastUrl = resolveApiUrl('forecast', array('base' => $codex['apiMap']['openWeather'])) .
        '?q=' . rawurlencode($weatherLoc) .
        '&appid=' . rawurlencode($weatherKey) .
        '&units=imperial';
    $forecast = fetchJsonCurl($forecastUrl);

    if (empty($forecast['error']) && !empty($forecast['list'])) {
        $days = array();
        $seen = array();
        foreach ($forecast['list'] as $item) {
            $day = date('l, M j', $item['dt']);
            if (!isset($seen[$day])) {
                $seen[$day] = true;
                $days[] = array(
                    'date' => $day,
                    'description' => ucwords($item['weather'][0]['description']),
                    'high' => round($item['main']['temp_max']),
                    'low' => round($item['main']['temp_min']),
                    'icon' => $item['weather'][0]['icon'],
                    'precip' => isset($item['pop']) ? round($item['pop'] * 100) : 0,
                    'wind' => round($item['wind']['speed'])
                );
                if (count($days) >= 3) break;
            }
        }
        $weatherData['forecast'] = $days;
    }
} else {
    error_log('Weather API error: ' . print_r($current, true));
    $weatherData['description'] = 'API call failed (current)';
}

// 💾 Cache successful response
$cachePath = envVal('WEATHER_CACHE_PATH', $dataPath . '/weather_cache.json');
if ($weatherData['temp'] !== null) {
    @file_put_contents($cachePath, json_encode($weatherData, JSON_PRETTY_PRINT));
}
#endregion

#region 📅 Time and Date Calculations
// Version: Codex v1.4
// Early definitions to avoid undefined notices
$timeData = array(
    'currentUnixTime' => $nowTs,
    'currentLocalTime'        => date('h:i:s A', $nowTs),     // full 12-hour with seconds + AM/PM
    'currentLocalTimeShort'   => date('g:i A', $nowTs),       // clean UI-friendly version
    'currentLocalTimeCompact' => strtolower(date('g:i a', $nowTs)), // lowercase for conversational AI
    'currentDate' => date('Y-m-d', $nowTs),
    'currentYearTotalDays' => $yearTotalDays,
    'currentYearDayNumber' => $yearDayNumber,
    'currentYearDaysRemaining' => $yearDaysRemaining,
    'currentMonthNumber' => strval($monthNumber),
    'currentWeekdayNumber' => strval($weekdayNumber),
    'currentDayNumber' => strval($dayNumber),
    'currentHour' => strval($currentHour),
    'timeOfDayDescription' => $timeOfDayDesc,
    'timeZone' => 'America/Phoenix',
    'UTCOffset' => $utcOffset,
    'daylightStartEndArray' => array(
        'daylightStart' => isset($weatherData['sunrise']) ? $weatherData['sunrise'] : DEFAULT_SUNRISE,
        'daylightEnd'   => isset($weatherData['sunset']) ? $weatherData['sunset'] : DEFAULT_SUNSET
    ),
    'defaultLatitudeLongitudeArray' => array(
        'defaultLatitude' => LATITUDE,
        'defaultLongitude' => LONGITUDE,
        'solarZenithAngle' => 90.83,
        'defaultUTCOffset' => $utcOffset
    ),
    'currentDayBeginningEndingUnixTimeArray' => array(
        'currentDayStartUnixTime' => $currentDayStartUnix,
        'currentDayEndUnixTime' => $currentDayEndUnix
    )
);
#endregion

#region 🧩 Response Assembly (Codex v5.3 – Unified Holidays + Ordered Output)
// Version: Codex v5.3
$response = array();
$codexVersion = isset($codex['codexMeta']['version']) ? $codex['codexMeta']['version'] : 'unknown';

// Build from tier map if available
if (is_array($codexTiers)) {
    foreach ($codexTiers as $tierName => $tierDef) {
        if (!isset($tierDef['members']) || !is_array($tierDef['members'])) continue;

        foreach ($tierDef['members'] as $member) {
            switch ($member) {
                case 'timeDateArray':
                    $response[$member] = $timeData;
                    break;

                case 'intervalsArray':
                    $response[$member] = array(
                        'currentDaySecondsRemaining' => $secondsRemaining,
                        'intervalLabel' => $intervalLabel,
                        'dayType' => $dayType,
                        'workdayIntervals' => array(
                            'start' => WORKDAY_START,
                            'end'   => WORKDAY_END
                        )
                    );
                    break;

                case 'recordCounts':
                    $response[$member] = $recordCounts;
                    break;

                case 'weatherData':
                    $response[$member] = $weatherData;
                    // ✅ Immediately follow weather with unified holidays array
                    if (isset($data['holidays']) && is_array($data['holidays'])) {
                        $response['holidays'] = $data['holidays'];
                    }
                    break;

                case 'kpiData':
                    $response[$member] = array('contacts'=>36,'orders'=>22,'approvals'=>3);
                    break;

                case 'uiHints':
                    $response[$member] = array('tips'=>array(
                        'Measure twice, cut once.',
                        'Stay positive, work hard, make it happen.',
                        'Quality is never an accident.',
                        'Efficiency is doing better what is already being done.',
                        'Every day is a fresh start.'
                    ));
                    break;

                case 'announcements':
                    $response[$member] = $announcements;
                    break;

                case 'uiEvent':
                    $response[$member] = $uiEvent;
                    break;

                case 'siteMeta':
                    $response[$member] = $siteMeta;
                    break;

                case 'deploymentCheck':
                    $response[$member] = '✅ Deployed successfully from Git at ' . date('Y-m-d H:i:s');
                    break;

                case 'codex':
                    $response[$member] = $codex;
                    break;

                default:
                    $response[$member] = array('note'=>"Unhandled member '$member' per Codex.");
                    logCacheEvent(
                        'getDynamicData',
                        'warning: ⚠️ Policy drift – unhandled member ' . $member . ' in tier ' . $tierName
                    );
            }
        }
    }
} else {
    // Fallback: minimal safe response
    $response = array(
        'timeDateArray' => array_merge($timeData, array(
            // 🕒 Add 12-hour formatted time variants for Skyebot and SSE dashboards
            'currentLocalTime12h'      => date('h:i:s A', $nowTs),       // 02:17:41 PM
            'currentLocalTimeShort'    => date('g:i A', $nowTs),         // 2:17 PM
            'currentLocalTimeCompact'  => strtolower(date('g:i a', $nowTs)), // 2:17 pm
        )),
        'intervalsArray' => array(
            'currentDaySecondsRemaining' => $secondsRemaining,
            'intervalLabel' => $intervalLabel,
            'dayType' => $dayType,
            'workdayIntervals' => array(
                'start' => WORKDAY_START,
                'end'   => WORKDAY_END
            )
        ),
        'recordCounts'   => $recordCounts,
        'weatherData'    => $weatherData,
        'holidays'       => isset($data['holidays']) ? $data['holidays'] : array(),
        'kpiData'        => array('contacts'=>36,'orders'=>22,'approvals'=>3),
        'siteMeta'       => $siteMeta
    );
}

// Append meta
$response['codexVersion'] = $codexVersion;
$response['timestamp'] = date('Y-m-d H:i:s');
#endregion

#region 🧭 Codex Context Merge (Phase 3)
// Version: Codex v1.4
$codexContext = array(
    'version' => (isset($codex['codexMeta']['version']) ? $codex['codexMeta']['version'] : 'unknown'),
    'tierMap' => array(
        'live-feed'      => array('timeDateArray','recordCounts','kpiData'),
        'context-stream' => array('weatherData','siteMeta'),
        'system-pulse'   => array('codexContext')
    ),
    'governance' => array(
        'policyPriority' => array('legal','temporal','operational','inference'),
        'activeModules'  => (is_array($codex) ? array_keys($codex) : array())
    ),
    'summaries' => array(
        'timeIntervalStandards' => (isset($codex['timeIntervalStandards']['purpose']['text']) ? $codex['timeIntervalStandards']['purpose']['text'] : ''),
        'sseStream'             => (isset($codex['sseStream']['purpose']['text']) ? $codex['sseStream']['purpose']['text'] : ''),
        'aiIntegration'         => (isset($codex['aiIntegration']['purpose']['text']) ? $codex['aiIntegration']['purpose']['text'] : '')
    )
);

// Cache Codex Context (5-minute TTL)
$cachePath = sys_get_temp_dir() . '/codex_cache.json';
if (!file_exists($cachePath) || (time() - filemtime($cachePath)) > 300) {
    @file_put_contents($cachePath, json_encode($codexContext, JSON_PRETTY_PRINT));
}

$response['codexContext'] = $codexContext;
#endregion

#region 🎯 Inject Derived Holidays (from in-memory derivation)
// Attach holidays array to top-level output before echo
if (isset($holidaysFinal) && is_array($holidaysFinal)) {
    $response['holidays'] = $holidaysFinal;
}
#endregion

#region 🟢 Output
// Version: Codex v1.4
echo json_encode($response);
exit;
#endregion