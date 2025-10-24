<?php
// 📁 File: api/getDynamicData.php (v1.2 – Holidays dynamic generation + weatherData merge)

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
    'federalHolidaysDynamic' => $federalHolidays  // New: Merge holidays here for temporal access
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

if (!isset($current['error']) && isset($current['main']['temp'], $current['weather'][0]['icon'], $current['sys']['sunrise'], $current['sys']['sunset'])) {
    $sunriseUnix = $current['sys']['sunrise'];
    $sunsetUnix = $current['sys']['sunset'];
    $sunriseLocal = date('g:i A', $sunriseUnix + $timeData['UTCOffset'] * 3600);
    $sunsetLocal = date('g:i A', $sunsetUnix + $timeData['UTCOffset'] * 3600);

    $daytimeSeconds = $sunsetUnix - $sunriseUnix;
    $daytimeHours = floor($daytimeSeconds / 3600);
    $daytimeMins = floor(($daytimeSeconds % 3600) / 60);
    $nighttimeSeconds = 86400 - $daytimeSeconds;
    $nighttimeHours = floor($nighttimeSeconds / 3600);
    $nighttimeMins = floor(($nighttimeSeconds % 3600) / 60);

    $weatherData = array(
        'temp' => round($current['main']['temp']),
        'icon' => $current['weather'][0]['icon'],
        'description' => ucwords(str_replace(' ', ' ', strtolower($current['weather'][0]['description']))),
        'lastUpdatedUnix' => time(),
        'sunrise' => $sunriseLocal,
        'sunset' => $sunsetLocal,
        'daytimeHours' => "{$daytimeHours}h {$daytimeMins}m",
        'nighttimeHours' => "{$nighttimeHours}h {$nighttimeMins}m",
        'forecast' => array(),  // Placeholder for forecast
        'federalHolidaysDynamic' => $federalHolidays  // Ensure holidays here
    );

    // Simple forecast stub (expand with API if needed)
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

// Cache weather if successful
if ($weatherData['temp'] !== null) {
    @file_put_contents(WEATHER_CACHE_PATH, json_encode($weatherData, JSON_PRETTY_PRINT));
}
#endregion

#region ⏰ Time Computation
$currentUnixTime = time();
$currentTime = date('H:i:s');
$currentDate = date('Y-m-d');
$year = (int)date('Y');
$yearTotalDays = 365 + (date('L', strtotime($currentDate)) ? 1 : 0);  // Leap year check
$yearDayNumber = (int)date('z', strtotime($currentDate)) + 1;
$yearDaysRemaining = $yearTotalDays - $yearDayNumber;
$monthNumber = (int)date('n');
$weekdayNumber = (int)date('N');  // 1=Mon, 7=Sun
$dayNumber = (int)date('j');
$currentHour = (int)date('G');
$timeOfDayDesc = ($currentHour < 12) ? 'morning' : (($currentHour < 17) ? 'afternoon' : 'evening');
$utcOffset = -7;  // Phoenix fixed

$currentDayStartUnix = strtotime(date('Y-m-d 00:00:00', $nowTs));
$currentDayEndUnix = strtotime(date('Y-m-d 23:59:59', $nowTs));
$secondsRemaining = $currentDayEndUnix - $nowTs;
$intervalLabel = '1';  // Placeholder
$dayType = $isWorkdayToday ? '0' : '1';  // 0=workday, 1=non
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

#region 🧩 Response Assembly (Codex Tier 1)
$response = array();

// Load Codex tiers for dynamic response shape
$codexTiers = isset($codex['sseStream']['tiers']) ? $codex['sseStream']['tiers'] : array();
$codexVersion = isset($codex['codexMeta']['version']) ? $codex['codexMeta']['version'] : 'unknown';

if (is_array($codexTiers)) {
    foreach ($codexTiers as $tierName => $tierDef) {
        if (!isset($tierDef['members']) || !is_array($tierDef['members'])) continue;

        foreach ($tierDef['members'] as $member) {
            switch ($member) {

                case 'skyesoftHolidays':   $response[$member] = $federalHolidays; break;
                case 'timeDateArray':      $response[$member] = array(
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
                        'daylightEnd'   => isset($weatherData['sunset'])  ? $weatherData['sunset']  : DEFAULT_SUNSET
                    ),
                    'defaultLatitudeLongitudeArray' => array(
                        'defaultLatitude' => LATITUDE,
                        'defaultLongitude'=> LONGITUDE,
                        'solarZenithAngle'=> 90.83,
                        'defaultUTCOffset'=> $utcOffset
                    ),
                    'currentDayBeginningEndingUnixTimeArray' => array(
                        'currentDayStartUnixTime' => $currentDayStartUnix,
                        'currentDayEndUnixTime'   => $currentDayEndUnix
                    )
                ); break;

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
                    // Unknown member → mark as drift for audit
                    $response[$member] = array('note'=>"Unhandled member '$member' per Codex.");
                    error_log("⚠️ Policy drift: unhandled member '$member' in tier '$tierName'");
            }
        }
    }

} else {

    // 🕹️ 2️⃣  Fallback: legacy static response (Codex missing or invalid)
    $response = array(
        'skyesoftHolidays' => $federalHolidays,
            'timeDateArray' => array(
                'currentUnixTime'   => $currentUnixTime,
                'currentLocalTime'  => $currentTime,
                'currentDate'       => $currentDate,
                'intervalsArray'    => array(
                    'currentDaySecondsRemaining' => $secondsRemaining,
                    'intervalLabel'              => $intervalLabel,
                    'dayType'                    => $dayType,
                    'workdayIntervals'           => array(
                        'start' => WORKDAY_START,
                        'end'   => WORKDAY_END
                    )
                )
            ),
        'recordCounts' => $recordCounts,
        'weatherData' => $weatherData,
        'kpiData' => array('contacts'=>36,'orders'=>22,'approvals'=>3),
        'siteMeta' => $siteMeta
    );
}

// 🧾 3️⃣  Append global meta info
$response['codexVersion'] = $codexVersion;
$response['timestamp'] = date('Y-m-d H:i:s');

#endregion

#region 🧭 Codex Context Merge (Phase 3)

// Merge Codex governance + tier mapping into SSE heartbeat
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

// Optional short-term cache to minimize disk reads
$cachePath = sys_get_temp_dir() . '/codex_cache.json';
if (!file_exists($cachePath) || (time() - filemtime($cachePath)) > 300) {
    @file_put_contents($cachePath, json_encode($codexContext, JSON_PRETTY_PRINT));
}

// Attach to outgoing SSE response
$response['codexContext'] = $codexContext;

#endregion

#region 🟢 Output
echo json_encode($response);
exit;
#endregion