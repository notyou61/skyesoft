<?php
// -----------------------------------------------------------------------------
// 📁 File: api/getDynamicData.php
// 🛰️ Skyesoft SSE Stream Engine — Version 6.0
// Governed by: Codex • standards.sse (Hierarchy B-1)
// Runtime Inputs: assets/data/sseInputs.json
// Doctrine Source: assets/data/codex.json
// Notes: This file is auto-aligned to Codex v1.0.0 tier architecture.
// -----------------------------------------------------------------------------

#region 🌐 HTTP Headers
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
#endregion

#region 🔧 Error + Path Initialization
error_reporting(E_ALL);
ini_set('display_errors', 1);

$rootPath = realpath(dirname(__DIR__));
$dataPath = realpath($rootPath . '/assets/data');
$logPath  = realpath($rootPath . '/logs');

if (!$dataPath) {
    $dataPath = __DIR__ . '/../assets/data';
}

$localLog = ($logPath && is_dir($logPath))
    ? $logPath . '/error_log.txt'
    : $dataPath . '/error_log.txt';

ini_set('log_errors', '1');
ini_set('error_log',  $localLog);
#endregion

#region 🧩 Helper Loading + Env
$helperPath = realpath(__DIR__ . '/helpers.php');
if ($helperPath && !in_array($helperPath, get_included_files())) {
    require_once $helperPath;
}

if (!function_exists('envVal')) {
    function envVal($key, $default = null) {
        $v = getenv($key);
        return ($v !== false && $v !== null && $v !== '') ? $v : $default;
    }
}

function loadEnvFileCandidates() {
    $candidates = array(
        '/home/notyou64/secure/.env',
        realpath(dirname(__FILE__) . '/../secure/.env'),
        realpath(dirname(__FILE__) . '/../../../secure/.env'),
        realpath(dirname(dirname(__FILE__)) . '/../.data/.env'),
        realpath(dirname(__FILE__) . '/../../../.data/.env'),
        realpath('C:/Users/SteveS/Documents/skyesoft/secure/.env')
    );
    foreach ($candidates as $p) {
        if (!$p || !is_readable($p)) continue;
        $lines = file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') continue;
            if (strpos($trim, '=') === false) continue;
            list($k, $v) = array_map('trim', explode('=', $trim, 2));
            putenv(strtoupper($k) . '=' . trim($v, "\"' "));
        }
        break;
    }
}

function timeStringToSeconds($timeStr) {
    $timeStr = trim(strtolower($timeStr));
    $timeStr = preg_replace('/[^\d:apm\s]/', '', $timeStr);
    $ts = strtotime($timeStr);
    if ($ts === false) return 0;
    return (int)date('G', $ts) * 3600 + (int)date('i', $ts) * 60 + (int)date('s', $ts);
}

function logCacheEventSimple($key, $status) {
    global $logPath;
    $log = $logPath ? $logPath . '/cache-events.log' : sys_get_temp_dir() . '/cache-events.log';
    @file_put_contents($log, date('Y-m-d H:i:s') . " | {$key} : {$status}\n", FILE_APPEND);
}
#endregion

#region 📘 Load Codex (Tier Minimal: meta / constitution / standards / modules)
$codex = array();
$codexFile = $dataPath . '/codex.json';

if (file_exists($codexFile)) {
    $raw = file_get_contents($codexFile);
    $codex = json_decode($raw, true);
    if (!is_array($codex)) $codex = array();
} else {
    $codex = array();
}

$requiredTiers = array('meta', 'constitution', 'standards', 'modules');
$codexIntegrityPassed = true;

foreach ($requiredTiers as $tier) {
    if (!isset($codex[$tier]) || !is_array($codex[$tier])) {
        error_log("🚫 Codex missing: {$tier}");
        $codexIntegrityPassed = false;
    }
}

if (!$codexIntegrityPassed) {
    echo json_encode(array(
        "error" => "❌ Critical Codex failure. SSE stream aborted.",
        "status" => "stopped"
    ));
    exit;
}

$codexVersion = isset($codex['meta']['version']) ? $codex['meta']['version'] : 'unknown';
#endregion

#region 📂 Load sseInputs.json (Runtime Data Layer)
$sseInputsPath = $dataPath . '/sseInputs.json';
$inputs = array(
    'officeHours' => array('start' => '7:30 AM', 'end' => '3:30 PM'),
    'shopHours'   => array('start' => '6:00 AM', 'end' => '2:30 PM'),
    'weather'     => array('defaultLocation' => 'Phoenix,US', 'latitude' => 33.4484, 'longitude' => -112.0740),
    'daylight'    => array('defaultSunrise' => '6:00 AM', 'defaultSunset' => '6:00 PM'),
    'intervalLabels' => array(
        'beforeWork' => 'Before Worktime',
        'work'       => 'Worktime',
        'afterWork'  => 'After Worktime'
    )
);

if (is_readable($sseInputsPath)) {
    $raw = file_get_contents($sseInputsPath);
    $json = json_decode($raw, true);
    if (is_array($json)) $inputs = array_replace_recursive($inputs, $json);
}
#endregion

#region ⏰ Time Engine (Baseline)
date_default_timezone_set('America/Phoenix');
$nowTs = time();

$currentDate   = date('Y-m-d', $nowTs);
$currentHour   = (int)date('G', $nowTs);
$currentMinute = (int)date('i', $nowTs);
$currentSecond = (int)date('s', $nowTs);
$currentSeconds = $currentHour * 3600 + $currentMinute * 60 + $currentSecond;

$currentDayStartTs = strtotime('today', $nowTs);
$currentDayEndTs   = strtotime('tomorrow', $nowTs) - 1;
$secondsRemaining  = max(0, $currentDayEndTs - $nowTs);

$yearTotalDays     = date('L', $nowTs) ? 366 : 365;
$yearDayNumber     = (int)date('z', $nowTs) + 1;
$timeOfDayDesc      = ($currentHour < 12) ? 'morning' : (($currentHour < 18) ? 'afternoon' : 'evening');
#endregion

#region 🕒 Build timeDateArray
$timeDateArray = array(
    'currentUnixTime'          => $nowTs,
    'currentLocalTime'         => date('h:i:s A', $nowTs),
    'currentLocalTimeShort'    => date('g:i A', $nowTs),
    'currentLocalTimeCompact'  => strtolower(date('g:i a', $nowTs)),
    'currentDate'              => $currentDate,
    'currentYearTotalDays'     => $yearTotalDays,
    'currentYearDayNumber'     => $yearDayNumber,
    'currentYearDaysRemaining' => $yearTotalDays - $yearDayNumber,
    'currentMonthNumber'       => (int)date('n', $nowTs),
    'currentWeekdayNumber'     => (int)date('N', $nowTs),
    'currentDayNumber'         => (int)date('j', $nowTs),
    'currentHour'              => $currentHour,
    'timeOfDayDescription'     => $timeOfDayDesc,
    'timeZone'                 => 'America/Phoenix',
    'UTCOffset'                => -7,
    'currentDayBeginningEndingUnixTimeArray' => array(
        'currentDayStartUnixTime' => $currentDayStartTs,
        'currentDayEndUnixTime'   => $currentDayEndTs
    )
);
#endregion

#region ⏱️ Interval Engine (A-1 Ultra Clean)
$officeStart = timeStringToSeconds($inputs['officeHours']['start']);
$officeEnd   = timeStringToSeconds($inputs['officeHours']['end']);
$shopStart   = timeStringToSeconds($inputs['shopHours']['start']);
$shopEnd     = timeStringToSeconds($inputs['shopHours']['end']);

if ($currentSeconds < $officeStart) {
    $intervalCode = 0;
    $intervalName = $inputs['intervalLabels']['beforeWork'];
} elseif ($currentSeconds <= $officeEnd) {
    $intervalCode = 1;
    $intervalName = $inputs['intervalLabels']['work'];
} else {
    $intervalCode = 2;
    $intervalName = $inputs['intervalLabels']['afterWork'];
}

$isWeekend = ((int)date('N', $nowTs) >= 6);
$dayType = $isWeekend ? "Weekend" : "Workday";

$intervalsArray = array(
    'currentDaySecondsRemaining' => $secondsRemaining,
    'intervalCode'               => $intervalCode,
    'intervalName'               => $intervalName,
    'labels'                     => $inputs['intervalLabels'],
    'dayType'                    => $dayType,
    'workdayIntervals' => array(
        'office' => array('startSeconds' => $officeStart, 'endSeconds' => $officeEnd),
        'shop'   => array('startSeconds' => $shopStart,   'endSeconds' => $shopEnd)
    )
);
#endregion

#region 🗓️ Holiday Loader
define('HOLIDAYS_PATH',        $dataPath . '/federal_holidays_dynamic.json');
define('FEDERAL_HOLIDAYS_PHP', __DIR__ . '/federalHolidays.php');

$holidays = array();

if (file_exists(FEDERAL_HOLIDAYS_PHP)) {
    define('SKYESOFT_INTERNAL_CALL', true);
    $raw = include FEDERAL_HOLIDAYS_PHP;
    if (is_array($raw)) $holidays = $raw;
} elseif (is_readable(HOLIDAYS_PATH)) {
    $raw = json_decode(file_get_contents(HOLIDAYS_PATH), true);
    if (isset($raw['holidays']) && is_array($raw['holidays'])) {
        $holidays = $raw['holidays'];
    } elseif (is_array($raw)) {
        $holidays = $raw;
    }
}
#endregion

#region 📥 Load Skyesoft Core Data Files
define('DATA_PATH',          $dataPath . '/skyesoft-data.json');
define('VERSION_PATH',       $dataPath . '/version.json');
define('ANNOUNCEMENTS_PATH', $dataPath . '/announcements.json');

$mainData      = is_readable(DATA_PATH) ? json_decode(file_get_contents(DATA_PATH), true) : array();
$siteMeta      = is_readable(VERSION_PATH) ? json_decode(file_get_contents(VERSION_PATH), true) : array();
$announceData  = is_readable(ANNOUNCEMENTS_PATH) ? json_decode(file_get_contents(ANNOUNCEMENTS_PATH), true) : array();

if (!is_array($mainData))      $mainData = array();
if (!is_array($siteMeta))      $siteMeta = array();
if (!is_array($announceData))  $announceData = array();

$announcements = isset($announceData['announcements']) && is_array($announceData['announcements'])
    ? $announceData['announcements']
    : array();

$uiEvent = isset($mainData['uiEvent']) ? $mainData['uiEvent'] : null;
if ($uiEvent !== null) {
    $mainData['uiEvent'] = null;
    @file_put_contents(DATA_PATH, json_encode($mainData, JSON_PRETTY_PRINT));
}
#endregion

#region 📊 recordCounts
$recordCounts = array();
$recordDefs = array('actions','entities','locations','contacts','orders','permits','notes','tasks');

foreach ($recordDefs as $r) {
    $recordCounts[$r] = (isset($mainData[$r]) && is_array($mainData[$r]))
        ? count($mainData[$r])
        : 0;
}
#endregion

#region 🌦️ Weather Engine (OpenWeather, 15 min cache)
loadEnvFileCandidates();
$weatherApiKey = envVal('WEATHER_API_KEY', '');
$loc = $inputs['weather']['defaultLocation'];
$lat = $inputs['weather']['latitude'];
$lon = $inputs['weather']['longitude'];

$weatherCachePath = $dataPath . '/weatherCache.json';

$weatherData = array(
    'temp'            => null,
    'icon'            => 'na',
    'description'     => 'Unavailable',
    'lastUpdatedUnix' => null,
    'sunrise'         => $inputs['daylight']['defaultSunrise'],
    'sunset'          => $inputs['daylight']['defaultSunset'],
    'daytimeHours'    => null,
    'nighttimeHours'  => null,
    'forecast'        => array()
);

if (is_readable($weatherCachePath)) {
    $cached = json_decode(file_get_contents($weatherCachePath), true);
    if (is_array($cached) && isset($cached['lastUpdatedUnix'])) {
        $age = time() - (int)$cached['lastUpdatedUnix'];
        if ($age < 900) {
            $weatherData = $cached;
            logCacheEventSimple('weather','cache-hit');
        }
    }
}

if ($weatherData['temp'] === null && !empty($weatherApiKey)) {
    $base = 'https://api.openweathermap.org/data/2.5';
    $currentUrl  = "{$base}/weather?q={$loc}&appid={$weatherApiKey}&units=imperial";
    $forecastUrl = "{$base}/forecast?q={$loc}&appid={$weatherApiKey}&units=imperial";

    $fetch = function($url) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ));
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return array('error'=>$err);
        $json = json_decode($res, true);
        return is_array($json) ? $json : array('error'=>'bad-json');
    };

    $current = $fetch($currentUrl);

    if (!isset($current['error'])
        && isset($current['main']['temp'])
        && isset($current['weather'][0]['description'])
        && isset($current['sys']['sunrise'])
        && isset($current['sys']['sunset'])) {

        $sunriseUnix = (int)$current['sys']['sunrise'];
        $sunsetUnix  = (int)$current['sys']['sunset'];

        $tz = new DateTimeZone('America/Phoenix');

        $srDT = new DateTime('@'.$sunriseUnix); $srDT->setTimezone($tz);
        $ssDT = new DateTime('@'.$sunsetUnix);  $ssDT->setTimezone($tz);

        $sunriseLocal = $srDT->format('g:i A');
        $sunsetLocal  = $ssDT->format('g:i A');

        $daySeconds = max(0, $sunsetUnix - $sunriseUnix);
        $nightSeconds = 86400 - $daySeconds;

        $weatherData['temp']            = round($current['main']['temp']);
        $weatherData['icon']            = $current['weather'][0]['icon'];
        $weatherData['description']     = ucwords($current['weather'][0]['description']);
        $weatherData['lastUpdatedUnix'] = time();
        $weatherData['sunrise']         = $sunriseLocal;
        $weatherData['sunset']          = $sunsetLocal;
        $weatherData['daytimeHours']    = floor($daySeconds/3600) . "h " . floor(($daySeconds%3600)/60) . "m";
        $weatherData['nighttimeHours']  = floor($nightSeconds/3600) . "h " . floor(($nightSeconds%3600)/60) . "m";

        $weatherData['forecast'] = array();
        $forecast = $fetch($forecastUrl);

        if (!isset($forecast['error']) && isset($forecast['list'])) {
            $seen = array();
            foreach ($forecast['list'] as $item) {
                $label = date('l, M j', $item['dt']);
                if (isset($seen[$label])) continue;
                $seen[$label] = true;

                $weatherData['forecast'][] = array(
                    'date'        => $label,
                    'description' => ucwords($item['weather'][0]['description']),
                    'high'        => round($item['main']['temp_max']),
                    'low'         => round($item['main']['temp_min']),
                    'icon'        => $item['weather'][0]['icon'],
                    'precip'      => isset($item['pop']) ? round($item['pop']*100) : 0,
                    'wind'        => isset($item['wind']['speed']) ? round($item['wind']['speed']) : null
                );
                if (count($weatherData['forecast']) >= 3) break;
            }
        }

        @file_put_contents($weatherCachePath, json_encode($weatherData, JSON_PRETTY_PRINT));
        logCacheEventSimple('weather','api-refresh');
    }
}
#endregion

#region 🧭 Codex Tier Map (B-1 SSE Standard)
$codexTiers = array();
if (isset($codex['standards']['sse']['tiers'])) {
    $codexTiers = $codex['standards']['sse']['tiers'];
} else {
    $codexTiers = array(
        'core'   => array('members'=>array('timeDateArray','intervalsArray','weatherData','holidays','recordCounts')),
        'ui'     => array('members'=>array('uiEvent','announcements','siteMeta')),
        'system' => array('members'=>array('codexContext'))
    );
}
#endregion

#region 🧠 codexContext (A-1 Final)
$codexContext = array(
    'version'       => $codexVersion,
    'sseStandard'   => array(
        'hierarchyOrder' => isset($codex['standards']['sse']['hierarchyOrder']) 
            ? $codex['standards']['sse']['hierarchyOrder'] 
            : 'B-1',
        'status' => isset($codex['standards']['sse']['status']) 
            ? $codex['standards']['sse']['status'] 
            : 'unknown'
    ),
    'activeModules' => array_keys($codex['modules']),
    'dayType'       => $dayType,
    'intervalName'  => $intervalName
);
#endregion

#region 📦 Assemble Final SSE Output
$response = array();

foreach ($codexTiers as $tier => $def) {
    if (!isset($def['members'])) continue;

    foreach ($def['members'] as $member) {
        switch ($member) {
            case 'timeDateArray': $response[$member] = $timeDateArray; break;
            case 'intervalsArray': $response[$member] = $intervalsArray; break;
            case 'weatherData':    $response[$member] = $weatherData; break;
            case 'holidays':       $response[$member] = $holidays; break;
            case 'recordCounts':   $response[$member] = $recordCounts; break;
            case 'uiEvent':        $response[$member] = $uiEvent; break;
            case 'announcements':  $response[$member] = $announcements; break;
            case 'siteMeta':       $response[$member] = $siteMeta; break;
            case 'codexContext':   $response[$member] = $codexContext; break;
            default:
                $response[$member] = array(
                    'note'=>"Unhandled Codex-declared SSE member: {$member}"
                );
        }
    }
}

$response['codexVersion'] = $codexVersion;
$response['timestamp']    = date('Y-m-d H:i:s', $nowTs);
#endregion

#region 🟢 Output
echo json_encode($response);
exit;
#endregion
