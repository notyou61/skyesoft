<?php
// 📄 File: api/ai/intents/temporal.php
// Version: v4.8 – DayType Integration & Sunset Localization
// Purpose: Resolve temporal intent to structured data (Codex-aligned resolver)
// Aligns with: AI Integration (status flags), TIS (interval checks), SSE (live context)

require_once __DIR__ . '/../../helpers.php'; // resolveDayType()

function handleIntent($prompt, $codexPath, $ssePath)
{
    date_default_timezone_set('America/Phoenix');

    // 🔹 1. Load live SSE data (5 s timeout)
    $endpoint = "https://www.skyelighting.com/skyesoft/api/getDynamicData.php";
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'GET',
        'timeout' => 5,
        'header'  => "User-Agent: SkyebotTemporalFetcher/1.0\r\n"
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
    $timeData = isset($sse['timeDateArray']) ? $sse['timeDateArray'] : array();
    $weather  = isset($sse['weatherData']) ? $sse['weatherData'] : array();
    $holidays = isset($weather['federalHolidaysDynamic']) ? $weather['federalHolidaysDynamic'] : array();
    if (!is_array($holidays)) $holidays = array();
    $utcOffset = isset($timeData['utcOffset']) ? (float)$timeData['utcOffset'] : -7; // Phoenix default
    $sunsetUtc = isset($weather['sunset']) ? $weather['sunset'] : null;
    $sunset    = $sunsetUtc ? date('g:i A', strtotime($sunsetUtc) + ($utcOffset * 3600)) : null;

    $nowStr   = isset($timeData['currentLocalTime']) ? $timeData['currentLocalTime'] : date('g:i A');
    $todayStr = isset($timeData['currentDate']) ? $timeData['currentDate'] : date('Y-m-d');
    $tz       = isset($timeData['timeZone']) ? $timeData['timeZone'] : 'America/Phoenix';
    $nowTs    = strtotime("$todayStr $nowStr") ?: time();
    $today    = date('Y-m-d', $nowTs);

    // 🔹 3. Load Codex TIS module
    $codex = json_decode(@file_get_contents($codexPath), true) ?: array();
    $tis   = isset($codex['timeIntervalStandards']) ? $codex['timeIntervalStandards'] : array();

    // 🔹 4. Determine current day classification
    $dayInfo = resolveDayType($tis, $holidays, $nowTs);
    $isWorkdayToday = $dayInfo['isWorkday'];

    // 🔹 5. Build environment-aware segments
    $segments = array();
    $lp = strtolower($prompt);
    $tisKey = (strpos($lp, 'shop') !== false) ? 'segmentsShop' : 'segmentsOffice';
    if (isset($tis[$tisKey]['items']) && is_array($tis[$tisKey]['items'])) {
        foreach ($tis[$tisKey]['items'] as $seg) {
            if (preg_match('/^Worktime$/i', $seg['Interval'])) {
                list($s, $e) = explode(' – ', $seg['Hours']);
                $segments['worktime'] = array('start' => trim($s), 'end' => trim($e), 'name' => $seg['Interval']);
                break;
            }
        }
    }
    if (!isset($segments['worktime'])) {
        $segments['worktime'] = array('start' => '7:30 AM', 'end' => '3:30 PM', 'name' => 'Worktime');
    }
    if ($sunset) {
        $segments['sundown'] = array('start' => $sunset, 'name' => 'Sundown');
    }

    // 🔹 6. Event detection
    $eventMap = array(
        'sundown' => '/sun(set|down)|dusk/',
        'worktime' => '/work(day|time)|office|start|begin|finish|end|close|business hours/',
        'holiday' => '/holiday/',
        'time' => '/time|clock|now/'
    );
    $event = 'time';
    foreach ($eventMap as $key => $pattern) {
        if (preg_match($pattern, $lp)) {
            $event = $key; break;
        }
    }

    // 🔹 7. Prompt flags
    $flags = array(
        'useEndTime' => (strpos($lp, 'finish') !== false || strpos($lp, 'end') !== false || strpos($lp, 'close') !== false),
        'isTomorrow' => strpos($lp, 'tomorrow') !== false,
        'isNext'     => strpos($lp, 'next') !== false,
        'isDelayed'  => strpos($lp, 'delayed') !== false,
        'isAgo'      => strpos($lp, 'ago') !== false
    );

    // 🔹 8. Resolve target
    $targetTs = $nowTs;
    $targetDate = $today;
    $targetTime = null;
    $rollover = false;
    $status = 'pending';
    $workStartToday = null;
    $workEndToday = null;
    $elapsed = null;

    switch ($event) {
        case 'holiday':
            $nextHoliday = null;
            foreach ($holidays as $h) {
                $hDate = isset($h['date']) ? $h['date'] : null;
                $hTs = $hDate ? strtotime($hDate) : null;
                if ($hTs && $hTs >= $nowTs) {
                    $nextHoliday = array('date' => $hDate, 'name' => isset($h['name']) ? $h['name'] : 'Holiday');
                    break;
                }
            }
            if ($nextHoliday) {
                $targetTs = strtotime($nextHoliday['date']);
                $targetDate = $nextHoliday['date'];
                $status = 'holiday';
            }
            break;

        case 'sundown':
            if ($sunset) {
                $targetTs = strtotime("$today $sunset");
                $targetTime = $sunset;
            }
            break;

        case 'worktime':
            $seg = $segments['worktime'];
            $targetTime = $flags['useEndTime'] ? $seg['end'] : $seg['start'];
            $workStartToday = strtotime("$today {$seg['start']}");
            $workEndToday   = strtotime("$today {$seg['end']}");

            // find next valid workday (Codex-aligned)
            $offset = ($flags['isTomorrow'] || $flags['isNext'] || $flags['isDelayed']) ? 1 : 0;
            $candidateOffset = $offset;
            while (true) {
                $candidateTs = strtotime("+{$candidateOffset} days", $nowTs);
                $candidateInfo = resolveDayType($tis, $holidays, $candidateTs);
                if ($candidateInfo['isWorkday']) {
                    $targetDate = date('Y-m-d', $candidateTs);
                    $rollover = ($candidateOffset > 0);
                    break;
                }
                $candidateOffset++;
            }
            $targetTs = strtotime("$targetDate $targetTime");
            if ($targetTs <= $nowTs && !$flags['useEndTime']) {
                $targetTs = strtotime("+1 day", $targetTs);
                $targetDate = date('Y-m-d', $targetTs);
                $rollover = true;
            }

            if ($flags['isDelayed'] && $isWorkdayToday && $nowTs > $workStartToday) {
                $ongoingDelta = $nowTs - $workStartToday;
                $ongoingHrs = floor($ongoingDelta / 3600);
                $ongoingMins = floor(($ongoingDelta % 3600) / 60);
                return array(
                    'domain' => 'temporal',
                    'codexNode' => 'timeIntervalStandards',
                    'prompt' => $prompt,
                    'intent' => 'worktime_delayed',
                    'event' => 'worktime',
                    'status' => 'no_delay_ongoing',
                    'dayInfo' => $dayInfo,
                    'data' => array(
                        'definition' => isset($tis['purpose']['text']) ? $tis['purpose']['text'] : '',
                        'runtime' => array(
                            'now' => date('g:i A', $nowTs),
                            'date' => date('F j, Y', $nowTs),
                            'timezone' => $tz,
                            'sunset' => $sunset,
                            'targetDate' => $targetDate,
                            'targetTime' => $targetTime,
                            'ongoing' => array('hours' => $ongoingHrs, 'minutes' => $ongoingMins)
                        ),
                        'intervals' => $segments
                    ),
                    'flags' => $flags + array('rollover' => $rollover)
                );
            }

            if (!$flags['useEndTime'] && $nowTs >= $workStartToday && $nowTs < $workEndToday) {
                $elapsedDelta = $nowTs - $workStartToday;
                $elapsed = array(
                    'hours' => floor($elapsedDelta / 3600),
                    'minutes' => floor(($elapsedDelta % 3600) / 60)
                );
                $status = 'ongoing';
            }
            break;
    }

    // 🔹 9. Delta calculation
    $delta = $targetTs - $nowTs;
    $absDelta = abs($delta);
    $hrs = floor(($absDelta % 86400) / 3600);
    $mins = floor(($absDelta % 3600) / 60);
    $isPast = $delta < 0;
    $direction = $isPast ? 'ago' : 'until';

    // 🔹 10. Structured output
    $runtime = array(
        'now' => date('g:i A', $nowTs),
        'date' => date('F j, Y', $nowTs),
        'timezone' => $tz,
        'sunset' => $sunset,
        'targetDate' => $targetDate,
        'targetTime' => $targetTime ?: date('g:i A', $targetTs),
        'delta' => array(
            'hours' => $hrs,
            'minutes' => $mins,
            'direction' => $direction,
            'isPast' => $isPast
        )
    );
    if ($elapsed) $runtime['elapsed'] = $elapsed;

    return array(
        'domain' => 'temporal',
        'codexNode' => 'timeIntervalStandards',
        'prompt' => $prompt,
        'intent' => 'temporal_query',
        'event' => $event,
        'status' => $status,
        'dayInfo' => $dayInfo,
        'data' => array(
            'definition' => isset($tis['purpose']['text']) ? $tis['purpose']['text'] : '',
            'runtime' => $runtime,
            'intervals' => $segments
        ),
        'flags' => $flags + array('rollover' => $rollover)
    );
}
