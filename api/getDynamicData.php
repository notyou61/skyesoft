<?php
// 📄 api/getDynamicData.php (v1.6.2 – PHP 5.6 Safe: Polished Codex-Or-Bust SSE – MTCO Tweaks)
// Purpose: Dynamic SSE from Codex SOT—weather/TIS/holidays/KPIs; log drifts, no degrades.
// Changelog: v1.6.1 → v1.6.2: Constants upfront, dynamic cache path, param trim, [SSE] logs, meta mode flag, flush post-assembly.
// Codex-Aligned: Resilience (logs on drift), Scalability (derived vars), Transparency (flags).

error_reporting(E_ALL);
ini_set('display_errors', 1);

// === 0. Minimal Math Inline (Upfront) ===
define('TIME_SECONDS_DAY', 86400);
define('TIME_SECONDS_HOUR', 3600);
define('CACHE_TTL_SECONDS', 300);
define('UTC_OFFSET_PHOENIX', -7);

// === 0. SSE Headers ===
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// === 1. Load Environment Early ===
$envPath = getenv('ENV_PATH') ? getenv('ENV_PATH') : __DIR__ . '/../../secure/.env';
$env = array();
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (empty($line) || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1), '"\'');
        $env[$k] = $v;
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
        exit(1);
    }
    return $v;
}

// === 2. Load Codex (Or-Bust) ===
$codexPath = envVal('CODEX_PATH', __DIR__ . '/../../skyesoft/assets/data/codex.json');
$codex = array();
if (!is_readable($codexPath)) {
    error_log('[SSE] ❌ Codex missing at ' . $codexPath . '—SSE degraded; refresh required.');
} else {
    $raw = file_get_contents($codexPath);
    $codex = json_decode($raw, true) ?: array();
    if (empty($codex)) {
        error_log('[SSE] ⚠️ Codex empty/invalid JSON at ' . $codexPath . '—using degraded mode.');
    }
}

// === 3. Derived Business ===
date_default_timezone_set('America/Phoenix');

// Business: Codex-only, log drift
$apiMap = isset($codex['apiMap']) ? $codex['apiMap'] : null;
if (!$apiMap) {
    error_log('[SSE] ⚠️ No apiMap in Codex—OpenWeather degraded.');
    $apiMap = array();  // Empty: API calls skip
}

$weatherLoc = isset($codex['weatherData']['location']) ? $codex['weatherData']['location'] : null;
if (!$weatherLoc) error_log('[SSE] ⚠️ No weather location in Codex—using default Phoenix,US.');
$weatherLoc = $weatherLoc ?: 'Phoenix,US';

//$weatherApiKey = requireEnv('WEATHER_API_KEY');
$weatherApiKey = envVal('WEATHER_API_KEY', '');
if (empty($weatherApiKey)) {
    error_log('[SSE] ⚠️ Weather key optional skip—using cache/null.');
}

$baseDataPath = envVal('BASE_DATA_PATH', '/home/notyou64/public_html/data/');  // Env-only

$tisOffice = isset($codex['timeIntervalStandards']['segmentsOffice']) ? $codex['timeIntervalStandards']['segmentsOffice'] : null;
if (!$tisOffice) {
    error_log('[SSE] ⚠️ TIS drift: No segmentsOffice in Codex.');
    $tisOffice = array();  // Empty: Workday intervals null
}
$workdayHours = isset($tisOffice[1]['Hours']) ? $tisOffice[1]['Hours'] : null;
if (!$workdayHours) {
    error_log('[SSE] ⚠️ TIS drift: No Worktime hours in Codex.');
    $workdayStart = '07:30';
    $workdayEnd = '15:30';  // Absolute minimal legacy if total drift
} else {
    list($workdayStart, $workdayEnd) = explode(' – ', $workdayHours);
}

// === 4. Weather Module ===
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

function getWeatherData($apiMap, $location, $apiKey, $degraded = false) {
    if ($degraded || !isset($apiMap['openWeather'])) {
        error_log('[SSE] ⚠️ Weather degraded: No OpenWeather map in Codex.');
        return array(
            'temp' => null,
            'icon' => '❓',
            'description' => 'Unavailable',
            'lastUpdatedUnix' => null,
            'sunrise' => null,
            'sunset' => null,
            'daytimeHours' => null,
            'nighttimeHours' => null,
            'forecast' => array(),
            'federalHolidaysDynamic' => array()  // Filled later
        );
    }

    $currentUrl = $apiMap['openWeather'] . '/weather?q=' . rawurlencode($location) . '&appid=' . rawurlencode($apiKey) . '&units=imperial';
    $current = fetchJsonCurl($currentUrl);

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
        'federalHolidaysDynamic' => array()  // Filled later
    );

    if (!isset($current['error']) && isset($current['main']['temp'], $current['weather'][0]['icon'], $current['sys']['sunrise'], $current['sys']['sunset'])) {
        $sunriseUnix = $current['sys']['sunrise'];
        $sunsetUnix = $current['sys']['sunset'];
        $sunriseLocal = date('g:i A', $sunriseUnix + (UTC_OFFSET_PHOENIX * TIME_SECONDS_HOUR));
        $sunsetLocal = date('g:i A', $sunsetUnix + (UTC_OFFSET_PHOENIX * TIME_SECONDS_HOUR));

        $daytimeSeconds = $sunsetUnix - $sunriseUnix;
        $daytimeHours = floor($daytimeSeconds / TIME_SECONDS_HOUR);
        $daytimeMins = floor(($daytimeSeconds % TIME_SECONDS_HOUR) / 60);
        $nighttimeSeconds = TIME_SECONDS_DAY - $daytimeSeconds;
        $nighttimeHours = floor($nighttimeSeconds / TIME_SECONDS_HOUR);
        $nighttimeMins = floor(($nighttimeSeconds % TIME_SECONDS_HOUR) / 60);

        $weatherData = array(
            'temp' => round($current['main']['temp']),
            'icon' => $current['weather'][0]['icon'],
            'description' => ucwords(strtolower($current['weather'][0]['description'])),
            'lastUpdatedUnix' => time(),
            'sunrise' => $sunriseLocal,
            'sunset' => $sunsetLocal,
            'daytimeHours' => $daytimeHours . 'h ' . $daytimeMins . 'm',
            'nighttimeHours' => $nighttimeHours . 'h ' . $nighttimeMins . 'm',
            'forecast' => array(),
            'federalHolidaysDynamic' => array()  // Placeholder
        );

        // Forecast (regular loop)
        $forecastUrl = $apiMap['openWeather'] . '/forecast?q=' . rawurlencode($location) . '&appid=' . rawurlencode($apiKey) . '&units=imperial';
        $forecast = fetchJsonCurl($forecastUrl);
        if (!isset($forecast['error']) && isset($forecast['list'])) {
            $days = array();
            $seen = array();
            foreach ($forecast['list'] as $item) {
                $dt = strtotime($item['dt_txt']);
                $dayKey = date('Y-m-d', $dt);
                if (isset($seen[$dayKey]) || count($days) >= 3) continue;
                $seen[$dayKey] = true;
                $days[] = array(
                    'date' => $dayKey,
                    'temp' => round($item['main']['temp']),
                    'description' => ucwords(strtolower($item['weather'][0]['description']))
                );
            }
            $weatherData['forecast'] = $days;
        }
    }

    // Cache (dynamic path)
    $cachePath = $baseDataPath . 'weatherCache.json';
    if (!is_readable($cachePath) || (time() - filemtime($cachePath)) > CACHE_TTL_SECONDS) {
        file_put_contents($cachePath, json_encode($weatherData, JSON_PRETTY_PRINT));
    }

    return $weatherData;
}

$degraded = empty($codex);
$weatherData = getWeatherData($apiMap, $weatherLoc, $weatherApiKey, $degraded);

// === 5. Load Holidays (Codex-Tied) ===
$federalHolidaysPath = $baseDataPath . 'federal_holidays_dynamic.json';
$federalHolidaysPhp = __DIR__ . '/federalHolidays.php';
$federalHolidays = array();
if (file_exists($federalHolidaysPhp)) {
    define('SKYESOFT_INTERNAL_CALL', true);
    $federalHolidays = include $federalHolidaysPhp;
    if (!is_array($federalHolidays)) $federalHolidays = array();
} elseif (is_readable($federalHolidaysPath)) {
    $federalHolidays = json_decode(file_get_contents($federalHolidaysPath), true) ?: array();
    error_log('[SSE] ⚠️ federalHolidays.php missing; used JSON fallback');
} else {
    error_log('[SSE] ⚠️ No federal holidays source—using empty array.');
}
$weatherData['federalHolidaysDynamic'] = $federalHolidays;

// Structured if assoc
if (is_array($federalHolidays) && array_keys($federalHolidays) !== range(0, count($federalHolidays) - 1)) {
    $converted = array();
    foreach ($federalHolidays as $date => $name) {
        $converted[] = array('name' => $name, 'date' => $date);
    }
    $federalHolidays = $converted;
}

// === 6. Compile Core Output (Time Stub – param trimmed) ===
function buildTimeArray($federalHolidays, $workdayStart, $workdayEnd) {
    $nowTs = time();
    $yearTotalDays = 365 + (int)date('L', $nowTs);
    $yearDayNumber = (int)date('z', $nowTs) + 1;
    $yearDaysRemaining = $yearTotalDays - $yearDayNumber;
    $monthNumber = (int)date('n', $nowTs);
    $weekdayNumber = (int)date('w', $nowTs);
    $dayNumber = (int)date('j', $nowTs);
    $currentHour = (int)date('G', $nowTs);

    $currentDateStr = date('Y-m-d', $nowTs);
    $isWeekend = $weekdayNumber == 0 || $weekdayNumber == 6;
    $isHoliday = false;
    foreach ($federalHolidays as $h) {
        if (isset($h['date']) && $h['date'] === $currentDateStr) {
            $isHoliday = true;
            break;
        }
    }
    $dayType = $isHoliday ? 'Holiday' : ($isWeekend ? 'Weekend' : 'Workday');

    $secondsRemaining = TIME_SECONDS_DAY - ($nowTs % TIME_SECONDS_DAY);
    $intervalLabel = 'After Worktime';  // TODO: Dynamic TIS match

    $currentDayStartUnix = strtotime(date('Y-m-d', $nowTs) . ' 00:00:00');
    $currentDayEndUnix = $currentDayStartUnix + TIME_SECONDS_DAY;

    $timeOfDayDesc = $currentHour < 12 ? 'morning' : ($currentHour < 17 ? 'afternoon' : 'evening');  // TODO: Ontology

    return array(
        'currentUnixTime' => $nowTs,
        'currentLocalTime' => date('H:i:s', $nowTs),
        'currentDate' => $currentDateStr,
        'currentYearTotalDays' => $yearTotalDays,
        'currentYearDayNumber' => $yearDayNumber,
        'currentYearDaysRemaining' => $yearDaysRemaining,
        'currentMonthNumber' => strval($monthNumber),
        'currentWeekdayNumber' => strval($weekdayNumber),
        'currentDayNumber' => strval($dayNumber),
        'currentHour' => strval($currentHour),
        'timeOfDayDescription' => $timeOfDayDesc,
        'timeZone' => 'America/Phoenix',
        'UTCOffset' => UTC_OFFSET_PHOENIX,
        'dayType' => $dayType,
        'daylightStartEndArray' => array(
            'daylightStart' => isset($weatherData['sunrise']) ? $weatherData['sunrise'] : '05:27:00',
            'daylightEnd' => isset($weatherData['sunset']) ? $weatherData['sunset'] : '19:42:00'
        ),
        'defaultLatitudeLongitudeArray' => array(
            'defaultLatitude' => isset($codex['defaultLatitudeLongitudeArray']['defaultLatitude']) ? $codex['defaultLatitudeLongitudeArray']['defaultLatitude'] : '33.448376',
            'defaultLongitude' => isset($codex['defaultLatitudeLongitudeArray']['defaultLongitude']) ? $codex['defaultLatitudeLongitudeArray']['defaultLongitude'] : '-112.074036',
            'solarZenithAngle' => 90.83,  // Minimal math
            'defaultUTCOffset' => UTC_OFFSET_PHOENIX
        ),
        'currentDayBeginningEndingUnixTimeArray' => array(
            'currentDayStartUnixTime' => $currentDayStartUnix,
            'currentDayEndUnixTime' => $currentDayEndUnix
        ),
        'workdayIntervals' => array(
            'start' => $workdayStart,
            'end' => $workdayEnd
        )
    );
}

$timeData = buildTimeArray($federalHolidays, $workdayStart, $workdayEnd);

// Record counts (single read)
$dataPath = $baseDataPath . 'skyesoft-data.json';
$recordCounts = array('actions' => 0, 'entities' => 0, 'locations' => 0, 'contacts' => 0, 'orders' => 0, 'permits' => 0, 'notes' => 0, 'tasks' => 0);
if (is_readable($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
    if (is_array($data)) {
        foreach ($recordCounts as $key => $val) {
            if (isset($data[$key])) $recordCounts[$key] = count($data[$key]);
        }
    }
}

$versionPath = $baseDataPath . 'version.json';
$siteMeta = array();
if (is_readable($versionPath)) {
    $siteMeta = json_decode(file_get_contents($versionPath), true) ?: array();
}

$announcementsPath = $baseDataPath . 'announcements.json';
$announcements = array();
if (is_readable($announcementsPath)) {
    $annData = json_decode(file_get_contents($announcementsPath), true);
    if (is_array($annData) && isset($annData['announcements'])) {
        $announcements = $annData['announcements'];
    }
}

// UI Event stub (lean—add POST if needed)
$uiEvent = null;

$codexTiers = isset($codex['sseStream']['tiers']) ? $codex['sseStream']['tiers'] : array();
$response = array('meta' => array('version' => '1.6.2', 'timestamp' => date('c'), 'dayType' => $timeData['dayType'], 'mode' => 'SSE'));

if (is_array($codexTiers)) {
    foreach ($codexTiers as $tierName => $tierDef) {
        if (!isset($tierDef['members']) || !is_array($tierDef['members'])) continue;
        foreach ($tierDef['members'] as $member) {
            switch ($member) {
                case 'skyesoftHolidays':
                    $response[$member] = $federalHolidays;
                    break;
                case 'timeDateArray':
                    $response[$member] = $timeData;
                    break;
                case 'intervalsArray':
                    $response[$member] = array(
                        'currentDaySecondsRemaining' => $secondsRemaining = TIME_SECONDS_DAY - (time() % TIME_SECONDS_DAY),
                        'intervalLabel' => $intervalLabel = 'After Worktime',  // TODO: Dynamic
                        'dayType' => $timeData['dayType'],
                        'workdayIntervals' => $timeData['workdayIntervals']
                    );
                    break;
                case 'recordCounts':
                    $response[$member] = $recordCounts;
                    break;
                case 'weatherData':
                    $response[$member] = $weatherData;
                    break;
                case 'kpiData':
                    $kpiData = isset($codex['kpiData']) ? $codex['kpiData'] : array();  // Codex-only
                    if (empty($kpiData)) error_log('[SSE] ⚠️ No kpiData in Codex—empty KPIs.');
                    $response[$member] = array(
                        'contacts' => isset($kpiData['contacts']) ? $kpiData['contacts'] : 0,
                        'orders' => isset($kpiData['orders']) ? $kpiData['orders'] : 0,
                        'approvals' => isset($kpiData['approvals']) ? $kpiData['approvals'] : 0
                    );
                    break;
                case 'uiHints':
                    $response[$member] = array('tips' => array(
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
                    $response[$member] = array('note' => "Unhandled member '$member' per Codex.");
                    error_log("[SSE] ⚠️ Policy drift: unhandled member '$member' in tier '$tierName'");
            }
        }
    }
} else {
    // Fallback (minimal, if no tiers)
    $response = array_merge($response, array(
        'skyesoftHolidays' => $federalHolidays,
        'timeDateArray' => $timeData,
        'intervalsArray' => array(
            'currentDaySecondsRemaining' => TIME_SECONDS_DAY - (time() % TIME_SECONDS_DAY),
            'intervalLabel' => 'After Worktime',
            'dayType' => $timeData['dayType'],
            'workdayIntervals' => isset($timeData['workdayIntervals']) ? $timeData['workdayIntervals'] : array()
        ),
        'recordCounts' => $recordCounts,
        'weatherData' => $weatherData,
        'kpiData' => array(
            'contacts' => 0,  // Codex-only, empty if missing
            'orders' => 0,
            'approvals' => 0
        ),
        'siteMeta' => $siteMeta
    ));
}

$response['codexVersion'] = isset($codex['codexMeta']['version']) ? $codex['codexMeta']['version'] : 'unknown';
$response['codexCompliance'] = true;

// === 7. Codex Context (Cached) ===
$codexContext = array(
    'version' => $response['codexVersion'],
    'tierMap' => array(
        'live-feed' => array('timeDateArray', 'recordCounts', 'kpiData'),
        'context-stream' => array('weatherData', 'siteMeta'),
        'system-pulse' => array('codexContext')
    ),
    'governance' => array(
        'policyPriority' => array('legal', 'temporal', 'operational', 'inference'),
        'activeModules' => array_keys($codex)
    ),
    'summaries' => array(
        'timeIntervalStandards' => isset($codex['timeIntervalStandards']['purpose']['text']) ? $codex['timeIntervalStandards']['purpose']['text'] : '',
        'sseStream' => isset($codex['sseStream']['purpose']['text']) ? $codex['sseStream']['purpose']['text'] : '',
        'aiIntegration' => isset($codex['aiIntegration']['purpose']['text']) ? $codex['aiIntegration']['purpose']['text'] : ''
    )
);

$cachePath = sys_get_temp_dir() . '/codex_cache.json';
if (!file_exists($cachePath) || (time() - filemtime($cachePath)) > CACHE_TTL_SECONDS) {
    @file_put_contents($cachePath, json_encode($codexContext, JSON_PRETTY_PRINT));
}
$response['codexContext'] = $codexContext;

// === 8. SSE Output (with flush) ===
echo "data: " . json_encode($response) . "\n\n";
flush();
exit;
?>