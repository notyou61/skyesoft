<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Generate temporal reasoning context (Codex + SSE integration)
// Version: v4.1 – Workday/Sundown/Time; fixed unassigned $isWorkdayToday, full rollover/post-start, tomorrow validation, consistent deltas, PHP 5.6-safe

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

    // 🔹 4. Build interval map (office) – focus on worktime/sundown
    $segments = array();
    if (isset($tis['segmentsOffice']['items']) && is_array($tis['segmentsOffice']['items'])) {
        foreach ($tis['segmentsOffice']['items'] as $seg) {
            if (!isset($seg['Interval']) || !isset($seg['Hours'])) continue;
            if (stripos($seg['Interval'], 'Worktime') !== false) {
                list($s, $e) = explode(' – ', $seg['Hours']);
                $segments['worktime'] = array(
                    'start' => trim($s), 'end' => trim($e),
                    'name' => $seg['Interval']
                );
            }
        }
    }
    // Shop override if prompted
    $lp = strtolower($prompt);
    if (strpos($lp, 'shop') !== false && isset($tis['segmentsShop']['items']) && is_array($tis['segmentsShop']['items'])) {
        foreach ($tis['segmentsShop']['items'] as $seg) {
            if (stripos($seg['Interval'], 'Worktime') !== false) {
                list($s, $e) = explode(' – ', $seg['Hours']);
                $segments['worktime'] = array(
                    'start' => trim($s), 'end' => trim($e),
                    'name' => $seg['Interval']
                );
                break;
            }
        }
    }
    if ($sunset) $segments['sundown'] = array('start' => $sunset, 'name' => 'Sundown');

    // 🔹 5. Detect event type
    $event = 'time'; // default
    if (preg_match('/sun(set|down)|dusk/', $lp))      $event = 'sundown';
    elseif (preg_match('/work(day|time)|office|start|begin|finish|end|business hours/', $lp)) $event = 'worktime';
    elseif (preg_match('/time|clock|now/', $lp))      $event = 'time';

    // 🔹 6. Resolve target timestamp
    $today = date('Y-m-d', $nowTs);
    $dow   = date('N', $nowTs);  // 1=Mon, 7=Sun

    // ✅ Always define variable before use
    $isWorkdayToday = ($dow >= 1 && $dow <= 5) && !in_array($today, $holidays);

    $targetTs = $nowTs;
    $targetDate = $today;
    $useEndTime = (strpos($lp, 'finish') !== false || strpos($lp, 'end') !== false);
    $isTomorrow = strpos($lp, 'tomorrow') !== false;
    $isNext = strpos($lp, 'next') !== false;

    switch ($event) {
        case 'sundown':
            $targetTs = $sunset ? strtotime("$today $sunset") : $nowTs;
            break;

        case 'worktime':
            $seg = isset($segments['worktime']) ? $segments['worktime'] : array('start' => '7:30 AM', 'end' => '3:30 PM');
            $targetTime = $useEndTime ? $seg['end'] : $seg['start'];
            $targetDate = $today;

            // Rollover logic: Skip non-workdays, handle tomorrow/next/post-start
            $offset = $isTomorrow ? 1 : 0;
            if ($isNext || $isTomorrow || $useEndTime) $offset = 1;
            $candidateOffset = $offset;
            while (true) {
                $candidateTs = strtotime("+{$candidateOffset} days", $nowTs);
                $candidateDow = date('N', $candidateTs);
                $candidateDate = date('Y-m-d', $candidateTs);
                if ($candidateDow >= 1 && $candidateDow <= 5 && !in_array($candidateDate, $holidays)) {
                    $targetDate = $candidateDate;
                    break;
                }
                $candidateOffset++;
            }
            $targetTs = strtotime("$targetDate $targetTime");
            if ($targetTs <= $nowTs) {
                $targetTs = strtotime("+1 day", $targetTs);
                $targetDate = date('Y-m-d', $targetTs);
            }
            break;

        default:
            $targetTs = $nowTs;
    }

    // 🔹 7. Delta computation with multi-day verbal
    $delta = $targetTs - $nowTs;
    $absDelta = abs($delta);
    $totalDays = floor($absDelta / 86400);
    $remSeconds = $absDelta % 86400;
    $hrs = floor($remSeconds / 3600);
    $mins = floor(($remSeconds % 3600) / 60);
    $isPast = $delta < 0;
    $direction = $isPast ? 'ago' : 'until';

    // Verbal delta (e.g., "1 day and 2 hours 30 minutes")
    $parts = array();
    if ($totalDays > 0) $parts[] = "{$totalDays} day" . ($totalDays > 1 ? 's' : '');
    if ($hrs > 0) $parts[] = "{$hrs} hour" . ($hrs > 1 ? 's' : '');
    if ($mins > 0 || empty($parts)) $parts[] = "{$mins} minute" . ($mins != 1 ? 's' : '');
    $verbal = implode(' and ', $parts) ?: 'imminent';

    $verbalDelta = $isPast ? "{$verbal} {$direction}" : "in {$verbal}";
    $isAgoPrompt = strpos($lp, 'ago') !== false;
    if ($isAgoPrompt && !$isPast) {
        $verbalDelta = "The event hasn't started yet—{$verbal} {$direction}.";
    }

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
                'targetDate' => $targetDate,
                'targetTime' => isset($targetTime) ? $targetTime : date('g:i A', $targetTs),
                'deltaHours' => $hrs,
                'deltaMinutes' => $mins,
                'totalDays' => $totalDays,
                'direction' => $direction,
                'verbalDelta' => $verbalDelta,
                'isPast' => $isPast
            ),
            'intervals' => $segments
        ),
        'intent' => "Temporal query ($event) resolved via SSE + Codex TIS module (rollover/verbal fixed)"
    );
}