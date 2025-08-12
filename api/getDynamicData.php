<?php
// 📁 File: api/getDynamicData.php

// Set SSE headers
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// Paths
$holidaysPath = "/home/notyou64/public_html/data/federal_holidays_dynamic.json";
$dataPath = "/home/notyou64/public_html/data/skyesoft-data.json";
$versionPath = "/home/notyou64/public_html/data/version.json";

// #region 🔧 Init and Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
// #endregion

// #region 🔄 Load & Clear Current UI Event from JSON (Ephemeral)
$mainData = [];
$uiEvent = null;
if (file_exists($dataPath)) {
    $mainData = json_decode(file_get_contents($dataPath), true);
    if (isset($mainData['uiEvent']) && $mainData['uiEvent'] !== null) {
        $uiEvent = $mainData['uiEvent'];
        // Clear uiEvent after first read
        $mainData['uiEvent'] = null;
        if (!file_put_contents($dataPath, json_encode($mainData, JSON_PRETTY_PRINT))) {
            error_log("❌ Could not write to $dataPath in getDynamicData.php");
        }
    }
}
// #endregion

// #region 🔐 Load Environment Variables (prefer /secure/.env; fallback to $_SERVER)
$env = array(); // Init cache

// Candidate .env locations (absolute first, then legacy + .data)
$envFiles = array(
    '/home/notyou64/secure/.env',
    realpath(__DIR__ . '/../secure/.env'),
    realpath(__DIR__ . '/../../../secure/.env'),
    realpath(dirname(__DIR__) . '/../.data/.env'),
    realpath(__DIR__ . '/../../../.data/.env')
);

// Read first readable .env (stop after first hit)
foreach ($envFiles as $p) {
    if ($p && @is_readable($p)) {
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

// Get value (prefers .env; falls back to $_SERVER/getenv)
function envVal($key, $default = '') {
    global $env;
    if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

// Require value (emit SSE error then exit if missing)
function requireEnv($key) {
    $v = envVal($key, '');
    if ($v === '') {
        echo "data: " . json_encode(array('error' => "Missing env: ".$key)) . "\n\n";
        @ob_flush(); @flush();
        exit;
    }
    return $v;
}
// #endregion


// #region 🌦️ Fetch Weather Data (current + 3-day forecast)
$weatherApiKey = requireEnv('WEATHER_API_KEY');   // Must exist (from /secure/.env or fallback)
$weatherLocation = 'Phoenix,US';                  // City,Country (OpenWeatherMap)
$lat = '33.448376';                               // Phoenix latitude (static)
$lon = '-112.074036';                             // Phoenix longitude (static)

// Init return (all properties present)
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

// Curl JSON helper (timeouts + GoDaddy TLS quirks)
function fetchJsonCurl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // (shared hosting safe)
    curl_setopt($ch, CURLOPT_USERAGENT, 'SkyeSoft/1.0 (+skyelighting.com)');
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) return array('error' => $err);
    $json = json_decode($res, true);
    if (!is_array($json)) return array('error' => 'Invalid JSON', 'code' => $code);
    return $json;
}

// Current weather (units=imperial)
$currentUrl = 'https://api.openweathermap.org/data/2.5/weather'
            . '?q=' . rawurlencode($weatherLocation)
            . '&appid=' . rawurlencode($weatherApiKey)
            . '&units=imperial';
$current = fetchJsonCurl($currentUrl);

// Validate current response
if (!isset($current['main']['temp'], $current['weather'][0]['icon'], $current['sys']['sunrise'], $current['sys']['sunset'])) {
    $weatherData['description'] = 'API call failed (current)';
    error_log('❌ Current weather failed: ' . json_encode($current));
} else {
    // Compute day/night durations (guard negatives)
    $sunriseUnix = (int)$current['sys']['sunrise'];
    $sunsetUnix  = (int)$current['sys']['sunset'];
    $daySecs  = max(0, $sunsetUnix - $sunriseUnix);
    $nightSecs = max(0, 86400 - $daySecs);

    // Populate current block
    $weatherData['temp'] = (int)round($current['main']['temp']);
    $weatherData['icon'] = (string)$current['weather'][0]['icon'];
    $weatherData['description'] = ucfirst((string)$current['weather'][0]['description']);
    $weatherData['lastUpdatedUnix'] = time();
    $weatherData['sunrise'] = date('g:i A', $sunriseUnix);
    $weatherData['sunset']  = date('g:i A', $sunsetUnix);
    $weatherData['daytimeHours']   = gmdate('G\h i\m', $daySecs);
    $weatherData['nighttimeHours'] = gmdate('G\h i\m', $nightSecs);
}

// 3-day forecast (aggregate highs/lows by day)
$forecastUrl = 'https://api.openweathermap.org/data/2.5/forecast'
             . '?q=' . rawurlencode($weatherLocation)
             . '&appid=' . rawurlencode($weatherApiKey)
             . '&units=imperial';
$forecast = fetchJsonCurl($forecastUrl);

// Parse forecast list (group by YYYY-MM-DD)
if (isset($forecast['list']) && is_array($forecast['list'])) {
    $daily = array();
    foreach ($forecast['list'] as $entry) {
        if (!isset($entry['dt'], $entry['main']['temp_max'], $entry['main']['temp_min'], $entry['weather'][0]['description'], $entry['weather'][0]['icon'])) continue;
        $date = date('Y-m-d', (int)$entry['dt']);
        if (!isset($daily[$date])) {
            // Init day bucket (pop: probability of precip; wind: mph)
            $daily[$date] = array(
                'high' => (float)$entry['main']['temp_max'],
                'low'  => (float)$entry['main']['temp_min'],
                'desc' => (string)$entry['weather'][0]['description'],
                'icon' => (string)$entry['weather'][0]['icon'],
                'pop'  => isset($entry['pop']) ? (float)$entry['pop'] * 100 : 0,
                'wind' => isset($entry['wind']['speed']) ? (float)$entry['wind']['speed'] : 0
            );
        } else {
            // Update high/low (keep first icon/desc)
            $daily[$date]['high'] = max($daily[$date]['high'], (float)$entry['main']['temp_max']);
            $daily[$date]['low']  = min($daily[$date]['low'], (float)$entry['main']['temp_min']);
            if (isset($entry['pop'])) $daily[$date]['pop'] = max($daily[$date]['pop'], (float)$entry['pop'] * 100);
            if (isset($entry['wind']['speed'])) $daily[$date]['wind'] = max($daily[$date]['wind'], (float)$entry['wind']['speed']);
        }
    }

    // Emit next 3 days
    $count = 0;
    foreach ($daily as $date => $info) {
        if ($count++ >= 3) break;
        $weatherData['forecast'][] = array(
            'date'        => date('l, M j', strtotime($date)),
            'description' => ucfirst($info['desc']),
            'high'        => (int)round($info['high']),
            'low'         => (int)round($info['low']),
            'icon'        => $info['icon'],
            'precip'      => (int)round($info['pop']),
            'wind'        => (int)round($info['wind'])
        );
    }
} else {
    error_log('❌ Forecast fetch failed: ' . json_encode($forecast));
}
// #endregion


// #region 📁 Paths and Constants
$holidaysPath = '/home/notyou64/public_html/data/federal_holidays_dynamic.json';
$dataPath = dirname(__FILE__) . '/home/notyou64/public_html/data/skyesoft-data.json';
$siteMeta = [];

// Load site metadata if available
if (file_exists($versionPath)) {
    $versionJson = file_get_contents($versionPath);
    $siteMeta = json_decode($versionJson, true);
}

$codexPath = '../../assets/data/codex.json';
$chatLogPath = '../../assets/data/chatLog.json';
$weatherPath = '../../assets/data/weatherCache.json';
$announcementsPath = '/home/notyou64/public_html/data/announcements.json';

define('WORKDAY_START', '07:30');
define('WORKDAY_END', '15:30');
// #endregion

// #region 📢 Load Announcements Data
$announcements = [];
if (file_exists($announcementsPath)) {
    $json = file_get_contents($announcementsPath);
    $announcementsData = json_decode($json, true);
    if (is_array($announcementsData) && isset($announcementsData['announcements']) && is_array($announcementsData['announcements'])) {
        $announcements = $announcementsData['announcements'];
    }
}
// #endregion

// #region 🔄 Enhanced Time Breakdown
$timeZone = 'America/Phoenix';
date_default_timezone_set($timeZone);
$now = time();
$yearTotalDays = (date('L', $now) ? 366 : 365);
$yearDayNumber = intval(date('z', $now)) + 1;
$yearDaysRemaining = $yearTotalDays - $yearDayNumber;
$monthNumber = intval(date('n', $now));
$weekdayNumber = intval(date('w', $now));
$dayNumber = intval(date('j', $now));
$currentHour = intval(date('G', $now));
$timeOfDayDesc = ($currentHour < 12) ? 'morning' : (($currentHour < 18) ? 'afternoon' : 'evening');
$dt = new DateTime('now', new DateTimeZone($timeZone));
$utcOffset = intval($dt->format('Z')) / 3600;
$currentDayStartUnix = strtotime('today', $now);
$currentDayEndUnix = strtotime('tomorrow', $now) - 1;
// #endregion

// #region 🔧 Utility Functions
function timeStringToSeconds($timeStr) {
    list($h, $m) = explode(':', $timeStr);
    return $h * 3600 + $m * 60;
}

function isHoliday($dateStr, $holidays) {
    foreach ($holidays as $holiday) {
        if ($holiday['date'] == $dateStr) return true;
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
// #endregion

// #region 📅 Load Holidays
$holidays = [];
if (file_exists($holidaysPath)) {
    $holidaysData = json_decode(file_get_contents($holidaysPath), true);
    $holidays = isset($holidaysData['holidays']) ? $holidaysData['holidays'] : [];
}
// #endregion

// #region ⏳ Time Calculations
$currentDate = date('Y-m-d', $now);
$currentTime = date('h:i:s A', $now);
$currentSeconds = date('G', $now) * 3600 + date('i', $now) * 60 + date('s', $now);
$currentUnixTime = $now;

$workStart = timeStringToSeconds(WORKDAY_START);
$workEnd = timeStringToSeconds(WORKDAY_END);

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
// #endregion

// #region 📊 Record Counts
$recordCounts = ['actions' => 0, 'entities' => 0, 'locations' => 0, 'contacts' => 0, 'orders' => 0, 'permits' => 0, 'notes' => 0, 'tasks' => 0];
if (file_exists($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
    foreach ($recordCounts as $key => $val) {
        if (isset($data[$key])) $recordCounts[$key] = count($data[$key]);
    }
}
// #endregion

// #region 🔔 One‑shot UI Event (emit, expire, ack)
$dirty   = false;
$uiEvent = isset($data['uiEvent']) ? $data['uiEvent'] : null;

// Auto‑expire by TTL
if (is_array($uiEvent) && isset($uiEvent['nonce'])) {
  $nowMs = (int) round(microtime(true) * 1000);
  $born  = isset($uiEvent['nonce'])  ? (int)$uiEvent['nonce']  : 0;
  $ttl   = isset($uiEvent['ttlMs'])  ? (int)$uiEvent['ttlMs']  : 15000;
  if ($born > 0 && ($nowMs - $born) > $ttl) {
    $data['uiEvent'] = null;
    $uiEvent = null;
    $dirty = true;
  }
}

// ACK clear (supports JSON or form POST)
if ($uiEvent && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw  = file_get_contents('php://input');
  $post = array();
  if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) $post = $json;
  }
  if (empty($post) && !empty($_POST)) $post = $_POST;

  if (isset($post['ackNonce'])) {
    $ack = (int) $post['ackNonce'];
    if (isset($uiEvent['nonce']) && (int)$uiEvent['nonce'] === $ack) {
      $data['uiEvent'] = null;
      $uiEvent = null;
      $dirty = true;
    }
  }
}

// Persist if we modified the data
if ($dirty) {
  $fp = fopen($jsonPath, 'c+');
  if ($fp) {
    flock($fp, LOCK_EX); rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  }
}
// #endregion

// #region 📤 Response
$response = [
    'timeDateArray' => [
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
        'timeZone' => $timeZone,
        'UTCOffset' => $utcOffset,
        'daylightStartEndArray' => [
            'daylightStart' => $weatherData['sunrise'] ?: '05:27:00', // Use real sunrise if available
            'daylightEnd' => $weatherData['sunset'] ?: '19:42:00' // Use real sunset if available
        ],
        'defaultLatitudeLongitudeArray' => [
            'defaultLatitude' => '33.448376',
            'defaultLongitude' => '-112.074036',
            'solarZenithAngle' => 90.83,
            'defaultUTCOffset' => $utcOffset
        ],
        'currentDayBeginningEndingUnixTimeArray' => [
            'currentDayStartUnixTime' => $currentDayStartUnix,
            'currentDayEndUnixTime' => $currentDayEndUnix
        ]
    ],
    'intervalsArray' => [
        'currentDaySecondsRemaining' => $secondsRemaining,
        'intervalLabel' => $intervalLabel,
        'dayType' => $dayType,
        'workdayIntervals' => [
            'start' => WORKDAY_START,
            'end' => WORKDAY_END
        ]
    ],
    'recordCounts' => $recordCounts,
    'weatherData' => $weatherData,
    'kpiData' => [
        'contacts' => 36,
        'orders' => 22,
        'approvals' => 3
    ],
    'uiHints' => [
        'tips' => [
            'Measure twice, cut once.',
            'Stay positive, work hard, make it happen.',
            'Quality is never an accident.',
            'Efficiency is doing better what is already being done.',
            'Every day is a fresh start.'
        ]
    ],
    'announcements' => $announcements,
    'uiEvent' => $uiEvent,
    'siteMeta' => $siteMeta
];
// #endregion

// #region 🟢 Output (AJAX/Fetch JSON only; PHP 5.6 safe)
echo json_encode($response);
exit;
// #endregion