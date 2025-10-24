<?php
// ================================================================
// 🌇 SKYEBOT TEMPORAL INTENT HANDLER  (v4.1 – Workday/Sundown/Time)
// ================================================================
// Purpose:
//   • Respond to temporal queries (time, sundown, workday start)
//   • Pulls live SSE + Codex timeIntervalStandards
//   • Returns structured array for Phase 6 natural composer
// ================================================================

function handleIntent($prompt, $codexPath, $ssePath)
{
    date_default_timezone_set('America/Phoenix');

    // 🔹 1. Load live SSE data (5 s timeout)
    $endpoint = "https://www.skyelighting.com/skyesoft/api/getDynamicData.php";
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET',
        'timeout' => 5,
        'header' => "User-Agent: SkyebotTemporalFetcher/1.0\r\n"
    )));
    $sseRaw = @file_get_contents($endpoint, false, $ctx);
    if ($sseRaw === false || trim($sseRaw) === '') {
        return array('domain' => 'temporal', 'error' => 'SSE unreachable');
    }
    $sse = json_decode($sseRaw, true);
    if (!is_array($sse)) {
        return array('domain' => 'temporal', 'error' => 'Invalid SSE JSON');
    }

    // 🔹 2. Extract runtime fields
    $timeData   = isset($sse['timeDateArray']) ? $sse['timeDateArray'] : array();
    $weather    = isset($sse['weatherData']) ? $sse['weatherData'] : array();
    $holidays   = isset($sse['federalHolidaysDynamic']) ? $sse['federalHolidaysDynamic'] : array();
    $sunset     = isset($weather['sunset']) ? $weather['sunset'] : null;

    $nowStr   = isset($timeData['currentLocalTime']) ? $timeData['currentLocalTime'] : date('g:i A');
    $todayStr = isset($timeData['currentDate']) ? $timeData['currentDate'] : date('Y-m-d');
    $tz       = isset($timeData['timeZone']) ? $timeData['timeZone'] : 'America/Phoenix';
    $nowTs    = strtotime("$todayStr $nowStr") ?: time();

    // 🔹 3. Load Codex + TIS module
    $codex = json_decode(@file_get_contents($codexPath), true) ?: array();
    $tis   = isset($codex['timeIntervalStandards']) ? $codex['timeIntervalStandards'] : array();

    // 🔹 4. Build interval map (office)
    $segments = array();
    if (isset($tis['segmentsOffice']['items']) && is_array($tis['segmentsOffice']['items'])) {
        foreach ($tis['segmentsOffice']['items'] as $seg) {
            if (!isset($seg['Interval']) || !isset($seg['Hours'])) continue;
            list($s, $e) = explode(' – ', $seg['Hours']);
            $segments[strtolower(str_replace(' ', '_', $seg['Interval']))] = array(
                'start' => trim($s), 'end' => trim($e)
            );
        }
    }
    if ($sunset) $segments['sundown'] = array('start' => $sunset);

    // 🔹 5. Detect event type
    $lp = strtolower($prompt);
    $event = 'time'; // default
    if (preg_match('/sun(set|down)|dusk/', $lp))      $event = 'sundown';
    elseif (preg_match('/work(day|time)|office|start|begin/', $lp)) $event = 'worktime';
    elseif (preg_match('/time|clock|now/', $lp))      $event = 'time';

    // 🔹 6. Resolve target timestamp
    $today = date('Y-m-d', $nowTs);
    $dow   = date('N', $nowTs);

    // ✅ Always define variable before use
    $isWorkdayToday = false;

    // Determine if today is a valid workday
    if ($dow >= 1 && $dow <= 5 && !in_array($today, $holidays)) {
        $isWorkdayToday = true;
    }

    $targetTs = $nowTs;

    switch ($event) {
        case 'sundown':
            $targetTs = $sunset ? strtotime("$today $sunset") : $nowTs;
            break;

        case 'worktime':
            $target = isset($segments['worktime']['start']) ? $segments['worktime']['start'] : '7:30 AM';
            $tDate  = $today;

            if (!$isWorkdayToday) {
                $n = 1;
                while (true) {
                    $next = strtotime("+$n day", $nowTs);
                    $dowN = date('N', $next);
                    $dStr = date('Y-m-d', $next);
                    if ($dowN <= 5 && !in_array($dStr, $holidays)) { 
                        $tDate = $dStr; 
                        break; 
                    }
                    $n++;
                }
            }
            $targetTs = strtotime("$tDate $target");
            if ($targetTs <= $nowTs) $targetTs = strtotime("+1 day", $targetTs);
            break;

        default:
            $targetTs = $nowTs;
    }

    // 🔹 7. Delta computation
    $delta = $targetTs - $nowTs;
    $hrs = abs(floor($delta / 3600));
    $mins = abs(floor(($delta % 3600) / 60));
    $direction = ($delta >= 0) ? 'until' : 'since';

    // 🔹 8. Structured output (Phase 6-ready)
    return array(
        'domain' => 'temporal',
        'codexNode' => 'timeIntervalStandards',
        'prompt' => $prompt,
        'event'  => $event,
        'isWorkdayToday' => $isWorkdayToday,
        'data' => array(
            'definition' => isset($tis['purpose']['text']) ? $tis['purpose']['text'] : 'Defines temporal segmentation for scheduling.',
            'runtime' => array(
                'now' => date('g:i A', $nowTs),
                'date' => date('F j, Y', $nowTs),
                'timezone' => $tz,
                'sunset' => $sunset,
                'deltaHours' => $hrs,
                'deltaMinutes' => $mins,
                'direction' => $direction
            ),
            'intervals' => $segments
        ),
        'intent' => "Temporal query ($event) resolved via SSE + Codex TIS module"
    );
}