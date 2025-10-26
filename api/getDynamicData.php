<?php
// 📁 File: api/getDynamicData.php (v1.3 – Fixed undefined $timeData/$nowTs notices, PHP 5.6-safe)

#region 🌐 HTTP Headers
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
#endregion

#region 📁 Constants and File Paths
define('WORKDAY_START', '07:30');
define('WORKDAY_END', '15:30');
define('WEATHER_LOCATION', 'Phoenix,US');
define('LATITUDE', '33.448376');
define('LONGITUDE', '-112.074036');
define('DEFAULT_SUNRISE', '05:27:00');
define('DEFAULT_SUNSET', '19:42:00');

define('HOLIDAYS_PATH', '/home/notyou64/public_html/data/federal_holidays_dynamic.json');
define('DATA_PATH', '/home/notyou64/public_html/data/skyesoft-data.json');
define('VERSION_PATH', '/home/notyou64/public_html/data/version.json');
define('CODEX_PATH', '/home/notyou64/public_html/skyesoft/assets/data/codex.json');
define('ANNOUNCEMENTS_PATH', '/home/notyou64/public_html/data/announcements.json');
define('FEDERAL_HOLIDAYS_PHP', __DIR__ . '/federalHolidays.php');  // New: Generator script
define('CHAT_LOG_PATH', '../../assets/data/chatLog.json');
define('WEATHER_CACHE_PATH', '../../assets/data/weatherCache.json');
#endregion

#region 🔧 Initialization and Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Include helper functions
require_once __DIR__ . '/helpers.php';
#endregion

#region 📊 Data Loading
$mainData = array();
$uiEvent = null;

if (is_readable(DATA_PATH)) {
    $rawData = file_get_contents(DATA_PATH);
    if ($rawData !== false) {
        $mainData = json_decode($rawData, true);
        if (!is_array($mainData)) {
            error_log('❌ Invalid JSON in ' . DATA_PATH);
            $mainData = array();
        } elseif (isset($mainData['uiEvent']) && $mainData['uiEvent'] !== null) {
            $uiEvent = $mainData['uiEvent'];
            $mainData['uiEvent'] = null;
            if (!file_put_contents(DATA_PATH, json_encode($mainData, JSON_PRETTY_PRINT))) {
                error_log('❌ Could not write to ' . DATA_PATH);
            }
        }
    } else {
        error_log('❌ Could not read ' . DATA_PATH);
    }
}

$siteMeta = array();
if (is_readable(VERSION_PATH)) {
    $versionJson = file_get_contents(VERSION_PATH);
    if ($versionJson !== false) {
        $siteMeta = json_decode($versionJson, true);
        if (!is_array($siteMeta)) {
            $siteMeta = array();
        }
    }
}

$codex = array();
if (is_readable(CODEX_PATH)) {
    $raw = file_get_contents(CODEX_PATH);
    if ($raw !== false) {
        $codex = json_decode($raw, true);
        if (!is_array($codex)) {
            $codex = array();
        }
    }
}

$announcements = array();
if (is_readable(ANNOUNCEMENTS_PATH)) {
    $json = file_get_contents(ANNOUNCEMENTS_PATH);
    if ($json !== false) {
        $announcementsData = json_decode($json, true);
        if (is_array($announcementsData) && isset($announcementsData['announcements']) && is_array($announcementsData['announcements'])) {
            $announcements = $announcementsData['announcements'];
        }
    }
}

// New: Generate/Load Federal Holidays
$federalHolidays = array();
if (file_exists(FEDERAL_HOLIDAYS_PHP)) {
    // Include generator (returns array if defined)
    define('SKYESOFT_INTERNAL_CALL', true);  // Trigger return mode
    $federalHolidays = include FEDERAL_HOLIDAYS_PHP;
    if (!is_array($federalHolidays)) {
        error_log('⚠️ federalHolidays.php did not return array');
        $federalHolidays = array();
    }
} else {
    // Fallback load from JSON if generator missing
    if (file_exists(HOLIDAYS_PATH)) {
        $json = file_get_contents(HOLIDAYS_PATH);
        $federalHolidays = json_decode($json, true);
    }
    error_log('⚠️ federalHolidays.php missing; used JSON fallback');
}

// Convert associative to structured if needed
if (is_array($federalHolidays) && array_keys($federalHolidays) !== range(0, count($federalHolidays) - 1)) {
    $converted = array();
    foreach ($federalHolidays as $date => $name) {
        $converted[] = array(
            'name' => $name,
            'date' => $date
        );
    }
    $federalHolidays = $converted;
}
#endregion

#region 🔐 Environment Variables
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
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            $env[$k] = trim($v, "\"'");
        }
        break;
    }
}

function envVal($key, $default = '') {
    global $env;
    if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

function requireEnv($key) {
    $v = envVal($key);
    if ($v === '') {
        echo "data: " . json_encode(array('error' => "Missing env: $key")) . "\n\n";
        flush();
        exit;
    }
    return $v;
}
#endregion

#region 🌦️ Weather Data
$weatherApiKey = requireEnv('WEATHER_API_KEY');

// ✅ Define timezone and offset early (used by both weather + time sections)
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
    'forecast' => array(),
    'federalHolidaysDynamic' => $federalHolidays
);

function fetchJsonCurl($url) {
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SkyeSoft/1.0 (+skyelighting.com)',
    ));
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) return array('error' => $err);
    $json = json_decode($res, true);
    return is_array($json) ? $json : array('error' => 'Invalid JSON', 'code' => $code);
}

// 🌤️ Fetch current conditions
$currentUrl = 'https://api.openweathermap.org/data/2.5/weather'
    . '?q=' . rawurlencode(WEATHER_LOCATION)
    . '&appid=' . rawurlencode($weatherApiKey)
    . '&units=imperial';
$current = fetchJsonCurl($currentUrl);

if (!isset($current['error']) && isset($current['main']['temp'], $current['weather'][0]['icon'], $current['sys']['sunrise'], $current['sys']['sunset'])) {
    $sunriseUnix = $current['sys']['sunrise'];
    $sunsetUnix  = $current['sys']['sunset'];

    // ✅ Use known offset (defined above)
    $sunriseLocal = date('g:i A', $sunriseUnix + ($utcOffset * 3600));
    $sunsetLocal  = date('g:i A', $sunsetUnix + ($utcOffset * 3600));

    $daytimeSeconds   = $sunsetUnix - $sunriseUnix;
    $daytimeHours     = floor($daytimeSeconds / 3600);
    $daytimeMins      = floor(($daytimeSeconds % 3600) / 60);
    $nighttimeSeconds = 86400 - $daytimeSeconds;
    $nighttimeHours   = floor($nighttimeSeconds / 3600);
    $nighttimeMins    = floor(($nighttimeSeconds % 3600) / 60);

    $weatherData = array(
        'temp' => round($current['main']['temp']),
        'icon' => $current['weather'][0]['icon'],
        'description' => ucwords(strtolower($current['weather'][0]['description'])),
        'lastUpdatedUnix' => time(),
        'sunrise' => $sunriseLocal,
        'sunset' => $sunsetLocal,
        'daytimeHours' => "{$daytimeHours}h {$daytimeMins}m",
        'nighttimeHours' => "{$nighttimeHours}h {$nighttimeMins}m",
        'forecast' => array(),
        'federalHolidaysDynamic' => $federalHolidays
    );

    // 🌦️ 3-Day Forecast (optional stub)
    $forecastUrl = 'https://api.openweathermap.org/data/2.5/forecast'
        . '?q=' . rawurlencode(WEATHER_LOCATION)
        . '&appid=' . rawurlencode($weatherApiKey)
        . '&units=imperial';
    $forecast = fetchJsonCurl($forecastUrl);
    if (!isset($forecast['error']) && isset($forecast['list'])) {
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
if ($weatherData['temp'] !== null) {
    @file_put_contents(WEATHER_CACHE_PATH, json_encode($weatherData, JSON_PRETTY_PRINT));
}
#endregion

#region ⏰ Time Computation
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

// Compute day boundaries and workday interval flags
$currentDayStartUnix = strtotime(date('Y-m-d 00:00:00', $nowTs));
$currentDayEndUnix   = strtotime(date('Y-m-d 23:59:59', $nowTs));
$secondsRemaining    = $currentDayEndUnix - $nowTs;
$intervalLabel       = '1';  // Placeholder
$dayType             = isset($isWorkdayToday) && $isWorkdayToday ? '0' : '1';  // 0=workday, 1=non
#endregion

#region 📈 Record Counts (Stubbed)
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
        error_log('❌ Could not open ' . DATA_PATH . ' for writing');
    }
}
#endregion

#region 🧭 Codex Tier Configuration
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
    error_log('⚠️ Codex tiers missing or invalid – using legacy fallback.');
}
#endregion

#region 📅 Time and Date Calculations
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

$workStart = timeStringToSeconds(WORKDAY_START);
$workEnd = timeStringToSeconds(WORKDAY_END);

$holidays = array();
if (is_readable(HOLIDAYS_PATH)) {
    $holidaysData = json_decode(file_get_contents(HOLIDAYS_PATH), true);
    $holidays = (is_array($holidaysData) && isset($holidaysData['holidays']) && is_array($holidaysData['holidays'])) ? $holidaysData['holidays'] : array();
}

$isHoliday = isHoliday($currentDate, $holidays);
$isWorkday = isWorkday($currentDate, $holidays);

$intervalLabel = ($isWorkday && $currentSeconds >= $workStart && $currentSeconds < $workEnd) ? '0' : '1';
$dayType = (!$isWorkday ? ($isHoliday ? '2' : '1') : '0');

if ($intervalLabel === '1') {
    $nextStart = ($isWorkday && $currentSeconds < $workStart)
        ? strtotime($currentDate . ' ' . WORKDAY_START)
        : findNextWorkdayStart($currentDate, $holidays);
    $secondsRemaining = $nextStart - $now;
} else {
    $secondsRemaining = $workEnd - $currentSeconds;
}
#endregion

#region 🔧 Utility Functions
function timeStringToSeconds($timeStr) {
    list($h, $m) = explode(':', $timeStr);
    return (int)$h * 3600 + (int)$m * 60;
}

function isHoliday($dateStr, $holidays) {
    foreach ($holidays as $holiday) {
        if ($holiday['date'] === $dateStr) return true;
    }
    return false;
}

function isWorkday($date, $holidays) {
    $day = date('w', strtotime($date));
    return $day != 0 && $day != 6 && !isHoliday($date, $holidays);
}

function findNextWorkdayStart($startDate, $holidays) {
    $date = strtotime($startDate . ' +1 day');
    while (!isWorkday(date('Y-m-d', $date), $holidays)) {
        $date = strtotime('+1 day', $date);
    }
    return strtotime(date('Y-m-d', $date) . ' ' . WORKDAY_START);
}
#endregion

#region 📊 Record Counts
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
        error_log('❌ Could not open ' . DATA_PATH . ' for writing');
    }
}
#endregion

#region 🧭 Codex Tier Configuration
$codexTiers = array();

if (isset($codex['sseStream']['tiers']) && is_array($codex['sseStream']['tiers'])) {
    $codexTiers = $codex['sseStream']['tiers'];
} else {
    error_log('⚠️ Codex tiers missing or invalid – using legacy fallback.');
}
#endregion

#region 📅 Time and Date Calculations
// Early definitions to avoid undefined notices
$timeData = array(
    'currentUnixTime' => $nowTs,
    'currentLocalTime' => date('H:i:s', $nowTs),
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

#region 🧩 Response Assembly (Codex-Aware Dynamic Builder)
$response = array();
$codexVersion = isset($codex['codexMeta']['version']) ? $codex['codexMeta']['version'] : 'unknown';

if (is_array($codexTiers)) {
    foreach ($codexTiers as $tierName => $tierDef) {
        if (!isset($tierDef['members']) || !is_array($tierDef['members'])) continue;

        foreach ($tierDef['members'] as $member) {
            switch ($member) {
                case 'skyesoftHolidays':   $response[$member] = $federalHolidays; break;
                case 'timeDateArray':      $response[$member] = $timeData; break;
                case 'intervalsArray':     $response[$member] = array(
                    'currentDaySecondsRemaining' => $secondsRemaining,
                    'intervalLabel' => $intervalLabel,
                    'dayType' => $dayType,
                    'workdayIntervals' => array(
                        'start' => WORKDAY_START,
                        'end'   => WORKDAY_END
                    )
                ); break;
                case 'recordCounts':       $response[$member] = $recordCounts; break;
                case 'weatherData':        $response[$member] = $weatherData;  break;
                case 'kpiData':            $response[$member] = array('contacts'=>36,'orders'=>22,'approvals'=>3); break;
                case 'uiHints':            $response[$member] = array('tips'=>array(
                                                'Measure twice, cut once.',
                                                'Stay positive, work hard, make it happen.',
                                                'Quality is never an accident.',
                                                'Efficiency is doing better what is already being done.',
                                                'Every day is a fresh start.'
                                            )); break;
                case 'announcements':      $response[$member] = $announcements; break;
                case 'uiEvent':            $response[$member] = $uiEvent; break;
                case 'siteMeta':           $response[$member] = $siteMeta; break;
                case 'deploymentCheck':    $response[$member] = '✅ Deployed successfully from Git at ' . date('Y-m-d H:i:s'); break;
                case 'codex':              $response[$member] = $codex; break;

                default:
                    $response[$member] = array('note'=>"Unhandled member '$member' per Codex.");
                    error_log("⚠️ Policy drift: unhandled member '$member' in tier '$tierName'");
            }
        }
    }
} else {
    // Fallback: legacy static response
    $response = array(
        'skyesoftHolidays' => $federalHolidays,
        'timeDateArray' => $timeData,
        'intervalsArray' => array(
            'currentDaySecondsRemaining' => $secondsRemaining,
            'intervalLabel' => $intervalLabel,
            'dayType' => $dayType,
            'workdayIntervals' => array(
                'start' => WORKDAY_START,
                'end' => WORKDAY_END
            )
        ),
        'recordCounts' => $recordCounts,
        'weatherData' => $weatherData,
        'kpiData' => array('contacts'=>36,'orders'=>22,'approvals'=>3),
        'siteMeta' => $siteMeta
    );
}

// Append global meta
$response['codexVersion'] = $codexVersion;
$response['timestamp'] = date('Y-m-d H:i:s');
#endregion

#region 🧭 Codex Context Merge (Phase 3)
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

$cachePath = sys_get_temp_dir() . '/codex_cache.json';
if (!file_exists($cachePath) || (time() - filemtime($cachePath)) > 300) {
    @file_put_contents($cachePath, json_encode($codexContext, JSON_PRETTY_PRINT));
}

$response['codexContext'] = $codexContext;
#endregion

#region 🟢 Output
echo json_encode($response);
exit;
#endregion