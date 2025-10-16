<?php
// 📁 File: api/getDynamicData.php

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
// Note: $chatLogPath and $weatherPath are defined but unused; retained for potential future use
define('CHAT_LOG_PATH', '../../assets/data/chatLog.json');
define('WEATHER_CACHE_PATH', '../../assets/data/weatherCache.json');
#endregion

#region 🔧 Initialization and Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

$federalHolidays = array();
if (file_exists(HOLIDAYS_PATH)) {
    $json = file_get_contents(HOLIDAYS_PATH);
    $federalHolidays = json_decode($json, true);
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

#region 🎯 Federal Holiday Normalization
// ======================================================================
// Convert associative holiday array (date => name)
// into a structured array of objects [{name, date}]
// Ensures consistency with Codex and SSE schema expectations.
// ======================================================================
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

#region 🌦️ Weather Data
$weatherApiKey = requireEnv('WEATHER_API_KEY');
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

$currentUrl = 'https://api.openweathermap.org/data/2.5/weather'
    . '?q=' . rawurlencode(WEATHER_LOCATION)
    . '&appid=' . rawurlencode($weatherApiKey)
    . '&units=imperial';
$current = fetchJsonCurl($currentUrl);

if (!isset($current['main']['temp'], $current['weather'][0]['icon'], $current['sys']['sunrise'], $current['sys']['sunset'])) {
    $weatherData['description'] = 'API call failed (current)';
    error_log('❌ Current weather failed: ' . json_encode($current));
} else {
    $sunriseUnix = (int)$current['sys']['sunrise'];
    $sunsetUnix  = (int)$current['sys']['sunset'];

    // Convert API UTC → Phoenix local time
    $tz = new DateTimeZone("America/Phoenix");

    $dtSunrise = new DateTime("@$sunriseUnix");
    $dtSunrise->setTimezone($tz);
    $sunriseLocal = $dtSunrise->format("g:i A");

    $dtSunset = new DateTime("@$sunsetUnix");
    $dtSunset->setTimezone($tz);
    $sunsetLocal = $dtSunset->format("g:i A");

    $daySecs   = max(0, $sunsetUnix - $sunriseUnix);
    $nightSecs = max(0, 86400 - $daySecs);

    $weatherData['temp']            = (int)round($current['main']['temp']);
    $weatherData['icon']            = (string)$current['weather'][0]['icon'];
    $weatherData['description']     = ucfirst((string)$current['weather'][0]['description']);
    $weatherData['lastUpdatedUnix'] = time();
    $weatherData['sunrise']         = $sunriseLocal;
    $weatherData['sunset']          = $sunsetLocal;
    $weatherData['daytimeHours']    = gmdate('G\h i\m', $daySecs);
    $weatherData['nighttimeHours']  = gmdate('G\h i\m', $nightSecs);
}

$forecastUrl = 'https://api.openweathermap.org/data/2.5/forecast'
    . '?q=' . rawurlencode(WEATHER_LOCATION)
    . '&appid=' . rawurlencode($weatherApiKey)
    . '&units=imperial';
$forecast = fetchJsonCurl($forecastUrl);

if (isset($forecast['list']) && is_array($forecast['list'])) {
    $daily = array();
    foreach ($forecast['list'] as $entry) {
        if (!isset($entry['dt'], $entry['main']['temp_max'], $entry['main']['temp_min'], $entry['weather'][0]['description'], $entry['weather'][0]['icon'])) continue;
        $date = date('Y-m-d', (int)$entry['dt']);
        if (!isset($daily[$date])) {
            $daily[$date] = array(
                'high' => (float)$entry['main']['temp_max'],
                'low' => (float)$entry['main']['temp_min'],
                'desc' => (string)$entry['weather'][0]['description'],
                'icon' => (string)$entry['weather'][0]['icon'],
                'pop' => isset($entry['pop']) ? (float)$entry['pop'] * 100 : 0,
                'wind' => isset($entry['wind']['speed']) ? (float)$entry['wind']['speed'] : 0
            );
        } else {
            $daily[$date]['high'] = max($daily[$date]['high'], (float)$entry['main']['temp_max']);
            $daily[$date]['low'] = min($daily[$date]['low'], (float)$entry['main']['temp_min']);
            if (isset($entry['pop'])) $daily[$date]['pop'] = max($daily[$date]['pop'], (float)$entry['pop'] * 100);
            if (isset($entry['wind']['speed'])) $daily[$date]['wind'] = max($daily[$date]['wind'], (float)$entry['wind']['speed']);
        }
    }

    $count = 0;
    foreach ($daily as $date => $info) {
        if ($count++ >= 3) break;
        $weatherData['forecast'][] = array(
            'date' => date('l, M j', strtotime($date)),
            'description' => ucfirst($info['desc']),
            'high' => (int)round($info['high']),
            'low' => (int)round($info['low']),
            'icon' => $info['icon'],
            'precip' => (int)round($info['pop']),
            'wind' => (int)round($info['wind'])
        );
    }
} else {
    error_log('❌ Forecast fetch failed: ' . json_encode($forecast));
}
#endregion

#region 📅 Time and Date Calculations

date_default_timezone_set('America/Phoenix');
$now = time();
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

#region 📤 Response Assembly
$response = array(
    // ✅ Add dynamic holidays at the top level (proper scope)
    'skyesoftHolidays' => is_array($federalHolidays) ? $federalHolidays : array(),
    'timeDateArray' => array(
        'currentUnixTime' => $currentUnixTime,
        'currentLocalTime' => $currentTime,
        'currentDate' => $currentDate,
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
            'daylightEnd' => isset($weatherData['sunset']) ? $weatherData['sunset'] : DEFAULT_SUNSET
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
    ),
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
    'kpiData' => array(
        'contacts' => 36,
        'orders' => 22,
        'approvals' => 3
    ),
    'uiHints' => array(
        'tips' => array(
            'Measure twice, cut once.',
            'Stay positive, work hard, make it happen.',
            'Quality is never an accident.',
            'Efficiency is doing better what is already being done.',
            'Every day is a fresh start.'
        )
    ),
    'announcements' => $announcements,
    'uiEvent' => $uiEvent,
    'siteMeta' => $siteMeta,
    'deploymentCheck' => '✅ Deployed successfully from Git at ' . date('Y-m-d H:i:s'),
    'codex' => $codex
);
#endregion

#region 🟢 Output
echo json_encode($response);
exit;
#endregion