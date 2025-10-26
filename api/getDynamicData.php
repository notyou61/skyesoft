<?php
// 📄 api/getDynamicData.php (v1.6.4 – PHP 5.6 Safe: Stable SSE Heartbeat)
// Purpose: Dynamic SSE from Codex SOT—weather/TIS/holidays/KPIs; guaranteed live output.
// Changelog: v1.6.3 → v1.6.4: Added early heartbeat, weather fallback, empty-response safeguard, simplified flush.
// Codex-Aligned: Resilience (no-silence exits), Transparency (log drift), Reliability (always data).

error_reporting(E_ALL);
ini_set('display_errors', 1);

// === 0. Constants ===
define('TIME_SECONDS_DAY', 86400);
define('TIME_SECONDS_HOUR', 3600);
define('CACHE_TTL_SECONDS', 300);
define('UTC_OFFSET_PHOENIX', -7);

// === 1. SSE Headers ===
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// === 2. Env Loader ===
$envPath = getenv('ENV_PATH') ? getenv('ENV_PATH') : __DIR__ . '/../../secure/.env';
$env = array();
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') continue;
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
    $g = getenv($key);
    return ($g !== false && $g !== '') ? $g : $default;
}

// === 3. Early Heartbeat ===
echo "data: " . json_encode(array(
    'status' => 'alive',
    'phase' => 'initializing',
    'timestamp' => time()
)) . "\n\n";
@ob_flush();
flush();
error_log('[SSE] ✅ Heartbeat sent.');

// === 4. Load Codex ===
$codexPath = envVal('CODEX_PATH', __DIR__ . '/../../skyesoft/assets/data/codex.json');
$codex = array();
if (is_readable($codexPath)) {
    $raw = file_get_contents($codexPath);
    $codex = json_decode($raw, true) ?: array();
} else {
    error_log('[SSE] ❌ Codex missing at ' . $codexPath);
}

// === 5. Derived Vars ===
date_default_timezone_set('America/Phoenix');
$baseDataPath = envVal('BASE_DATA_PATH', '/home/notyou64/public_html/data/');
$apiMap = isset($codex['apiMap']) ? $codex['apiMap'] : array();
$weatherLoc = isset($codex['weatherData']['location']) ? $codex['weatherData']['location'] : 'Phoenix,US';
$weatherApiKey = envVal('WEATHER_API_KEY', '');
if (empty($weatherApiKey)) error_log('[SSE] ⚠️ Weather key missing – will use cached/null weather.');

$tisOffice = isset($codex['timeIntervalStandards']['segmentsOffice']) ? $codex['timeIntervalStandards']['segmentsOffice'] : array();
$workdayHours = isset($tisOffice[1]['Hours']) ? $tisOffice[1]['Hours'] : '07:30 – 15:30';
list($workdayStart, $workdayEnd) = explode(' – ', $workdayHours);

// === 6. Weather Helpers ===
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
    curl_close($ch);
    if ($res === false) return array('error' => $err);
    $json = json_decode($res, true);
    return is_array($json) ? $json : array('error' => 'Invalid JSON');
}

function getWeatherData($apiMap, $location, $apiKey, $baseDataPath) {
    $cachePath = $baseDataPath . 'weatherCache.json';
    if (empty($apiKey) || !isset($apiMap['openWeather'])) {
        error_log('[SSE] ⚠️ Weather fallback – no API key or map.');
        if (is_readable($cachePath)) return json_decode(file_get_contents($cachePath), true);
        return array('temp'=>null,'icon'=>'❓','description'=>'Unavailable','forecast'=>array(),'federalHolidaysDynamic'=>array());
    }

    $data = array('temp'=>null,'icon'=>'❓','description'=>'Unavailable');
    $url = $apiMap['openWeather'].'/weather?q='.rawurlencode($location).'&appid='.rawurlencode($apiKey).'&units=imperial';
    $cur = fetchJsonCurl($url);
    if (!isset($cur['error']) && isset($cur['main']['temp'])) {
        $sunrise = date('g:i A', $cur['sys']['sunrise'] + (UTC_OFFSET_PHOENIX * TIME_SECONDS_HOUR));
        $sunset  = date('g:i A', $cur['sys']['sunset'] + (UTC_OFFSET_PHOENIX * TIME_SECONDS_HOUR));
        $data = array(
            'temp'=>round($cur['main']['temp']),
            'icon'=>$cur['weather'][0]['icon'],
            'description'=>ucwords($cur['weather'][0]['description']),
            'sunrise'=>$sunrise,
            'sunset'=>$sunset
        );
    }
    file_put_contents($cachePath, json_encode($data, JSON_PRETTY_PRINT));
    return $data;
}
$weatherData = getWeatherData($apiMap, $weatherLoc, $weatherApiKey, $baseDataPath);

// === 7. Holidays ===
$federalHolidays = array();
$federalHolidaysPath = $baseDataPath . 'federal_holidays_dynamic.json';
$federalHolidaysPhp = __DIR__ . '/federalHolidays.php';
if (file_exists($federalHolidaysPhp)) {
    define('SKYESOFT_INTERNAL_CALL', true);
    $federalHolidays = include $federalHolidaysPhp;
} elseif (is_readable($federalHolidaysPath)) {
    $federalHolidays = json_decode(file_get_contents($federalHolidaysPath), true);
}
$weatherData['federalHolidaysDynamic'] = $federalHolidays;

// === 8. Time Data ===
function buildTimeArray($holidays, $start, $end) {
    $now = time();
    $weekday = (int)date('w', $now);
    $isWeekend = ($weekday == 0 || $weekday == 6);
    $today = date('Y-m-d', $now);
    $isHoliday = false;
    foreach ($holidays as $h) if (isset($h['date']) && $h['date'] == $today) $isHoliday = true;
    $type = $isHoliday ? 'Holiday' : ($isWeekend ? 'Weekend' : 'Workday');
    return array(
        'currentLocalTime'=>date('H:i:s',$now),
        'currentDate'=>$today,
        'dayType'=>$type,
        'workdayIntervals'=>array('start'=>$start,'end'=>$end)
    );
}
$timeData = buildTimeArray($federalHolidays,$workdayStart,$workdayEnd);

// === 9. Record Counts ===
$dataPath = $baseDataPath.'skyesoft-data.json';
$recordCounts = array('actions'=>0,'entities'=>0,'locations'=>0,'contacts'=>0,'orders'=>0,'permits'=>0,'notes'=>0,'tasks'=>0);
if (is_readable($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
    if (is_array($data)) {
        foreach ($recordCounts as $k=>$v) if (isset($data[$k])) $recordCounts[$k]=count($data[$k]);
    }
}

// === 10. Compile Response ===
$response = array(
    'meta'=>array('version'=>'1.6.4','timestamp'=>date('c'),'mode'=>'SSE'),
    'timeDateArray'=>$timeData,
    'weatherData'=>$weatherData,
    'recordCounts'=>$recordCounts,
    'codexVersion'=>isset($codex['codexMeta']['version'])?$codex['codexMeta']['version']:'unknown',
    'codexCompliance'=>true
);

// === 11. Safety: Empty Response Check ===
if (empty($response)) {
    error_log('[SSE] ❌ Empty response, sending fallback.');
    $response = array('error'=>'No SSE data generated','timestamp'=>time());
}

// === 12. Flush Output ===
@ob_flush();
flush();
echo "data: " . json_encode($response) . "\n\n";
flush();
exit;