<?php
// api/getDynamicData.php — Block 5 + Heartbeat (1 Hz SSE loop, PHP 5.6 safe)

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('TIME_SECONDS_DAY', 86400);
define('TIME_SECONDS_HOUR', 3600);
define('UTC_OFFSET_PHOENIX', -7);

// --- SSE headers + flush setup ---
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

@ini_set('zlib.output_compression', 0);
@ini_set('output_buffering', 0);
while (@ob_end_flush()) {}
ob_implicit_flush(1);

// --- env helpers ---
$env = array();
$envPath = getenv('ENV_PATH') ? getenv('ENV_PATH') : __DIR__ . '/../../secure/.env';
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1), "\"'");
        $env[$k] = $v;
    }
}
function envVal($k, $dflt) {
    global $env;
    if (isset($env[$k]) && $env[$k] !== '') return $env[$k];
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return $_SERVER[$k];
    $g = getenv($k);
    return ($g !== false && $g !== '') ? $g : $dflt;
}

// --- codex + meta preload (static outside loop) ---
$codex = array();
$codexPath = envVal('CODEX_PATH', __DIR__.'/../../skyesoft/assets/data/codex.json');
if (is_readable($codexPath)) {
    $raw = file_get_contents($codexPath);
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $codex = $tmp;
}

$baseDataPath = envVal('BASE_DATA_PATH', '/home/notyou64/public_html/data/');
$versionPath = $baseDataPath.'version.json';
$siteMeta = array();
if (is_readable($versionPath)) {
    $tmp = json_decode(file_get_contents($versionPath), true);
    if (is_array($tmp)) $siteMeta = $tmp;
}

$announcementsPath = $baseDataPath.'announcements.json';
$announcements = array();
if (is_readable($announcementsPath)) {
    $tmp = json_decode(file_get_contents($announcementsPath), true);
    if (is_array($tmp) && isset($tmp['announcements']) && is_array($tmp['announcements'])) {
        $announcements = $tmp['announcements'];
    }
}

// --- holidays (static load once) ---
$federalHolidays = array();
$federalHolidaysPhp = __DIR__ . '/federalHolidays.php';
$holJson = $baseDataPath . 'federal_holidays_dynamic.json';
if (file_exists($federalHolidaysPhp)) {
    define('SKYESOFT_INTERNAL_CALL', true);
    $h = include $federalHolidaysPhp;
    if (is_array($h)) $federalHolidays = $h;
} elseif (is_readable($holJson)) {
    $h2 = json_decode(file_get_contents($holJson), true);
    if (is_array($h2)) $federalHolidays = $h2;
}
if (is_array($federalHolidays) && array_keys($federalHolidays) !== range(0, count($federalHolidays)-1)) {
    $tmp = array();
    foreach ($federalHolidays as $date => $name) {
        $tmp[] = array('name'=>$name,'date'=>$date);
    }
    $federalHolidays = $tmp;
}

// --- weather static preload (reuse last cached) ---
$weatherData = array('temp'=>null,'icon'=>'❓','description'=>'Unavailable',
    'lastUpdatedUnix'=>null,'sunrise'=>null,'sunset'=>null,
    'daytimeHours'=>null,'nighttimeHours'=>null,'forecast'=>array(),
    'federalHolidaysDynamic'=>$federalHolidays);
$cachePath = $baseDataPath . 'weatherCache.json';
if (is_readable($cachePath)) {
    $tmp = json_decode(file_get_contents($cachePath), true);
    if (is_array($tmp)) $weatherData = array_merge($weatherData, $tmp);
}

// --- counts (static) ---
$recordCounts = array('actions'=>0,'entities'=>0,'locations'=>0,'contacts'=>0,'orders'=>0,'permits'=>0,'notes'=>0,'tasks'=>0);
$dataPath = $baseDataPath . 'skyesoft-data.json';
if (is_readable($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
    if (is_array($data)) {
        foreach ($recordCounts as $k => $v) {
            if (isset($data[$k]) && is_array($data[$k])) $recordCounts[$k] = count($data[$k]);
        }
    }
}

// --- looped SSE emitter (1 Hz) ---
$start = time();
while (true) {

    // rebuild lightweight dynamic fields each tick
    date_default_timezone_set('America/Phoenix');
    $nowTs = time();
    $yearTotalDays = 365 + (int)date('L', $nowTs);
    $timeData = array(
        'currentUnixTime' => $nowTs,
        'currentLocalTime' => date('H:i:s', $nowTs),
        'currentDate' => date('Y-m-d', $nowTs),
        'currentYearTotalDays' => $yearTotalDays,
        'currentYearDayNumber' => (int)date('z', $nowTs) + 1,
        'currentYearDaysRemaining' => $yearTotalDays - ((int)date('z', $nowTs) + 1),
        'timeZone' => 'America/Phoenix'
    );

    // assemble response (same as before)
    $response = array('meta'=>array(
        'version'=>'block-5.heartbeat',
        'timestamp'=>date('c'),
        'uptimeSec'=>time() - $start,
        'mode'=>'SSE'
    ));

    $tiers = isset($codex['sseStream']['tiers']) ? $codex['sseStream']['tiers'] : array();
    if (is_array($tiers) && !empty($tiers)) {
        foreach ($tiers as $tierName => $tierDef) {
            if (!isset($tierDef['members']) || !is_array($tierDef['members'])) continue;
            foreach ($tierDef['members'] as $member) {
                if ($member === 'timeDateArray') $response[$member] = $timeData;
                elseif ($member === 'skyesoftHolidays') $response[$member] = $federalHolidays;
                elseif ($member === 'weatherData') $response[$member] = $weatherData;
                elseif ($member === 'recordCounts') $response[$member] = $recordCounts;
                elseif ($member === 'siteMeta') $response[$member] = $siteMeta;
                elseif ($member === 'announcements') $response[$member] = $announcements;
                elseif ($member === 'deploymentCheck') $response[$member] = '✅ Deployed ' . date('Y-m-d H:i:s');
                elseif ($member === 'codex') $response[$member] = $codex;
                elseif ($member === 'uiHints') $response[$member] = array('tips'=>array(
                    'Measure twice, cut once.',
                    'Every day is a fresh start.'
                ));
                elseif ($member === 'uiEvent') $response[$member] = null;
                elseif ($member === 'kpiData') $response[$member] = isset($codex['kpiData']) ? $codex['kpiData'] : array('contacts'=>0,'orders'=>0,'approvals'=>0);
                else $response[$member] = array('note'=>"Unhandled member '$member'");
            }
        }
    } else {
        $response['timeDateArray'] = $timeData;
        $response['skyesoftHolidays'] = $federalHolidays;
        $response['weatherData'] = $weatherData;
        $response['recordCounts'] = $recordCounts;
        $response['siteMeta'] = $siteMeta;
    }

    $response['codexVersion'] = isset($codex['codexMeta']['version']) ? $codex['codexMeta']['version'] : 'unknown';
    $response['codexCompliance'] = true;

    // emit JSON event
    echo "data: " . json_encode($response) . "\n\n";
    flush();

    // 1 Hz heartbeat
    sleep(1);
}