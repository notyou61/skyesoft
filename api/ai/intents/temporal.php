<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Resolve temporal intent to structured data (Codex-aligned resolver)
// Version: v4.5 – Minor resilience (holiday validation, init flags); consistent return; exact segment match fix.
// Aligns with: AI Integration (status flags), TIS (interval checks), SSE (live context)

function handleIntent($prompt, $codexPath, $ssePath)
{
    date_default_timezone_set('America/Phoenix');

    // 🔹 1. Load live SSE data (5s timeout)
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
    $holidays   = isset($weather['federalHolidaysDynamic']) ? $weather['federalHolidaysDynamic'] : array();
    if (!is_array($holidays)) $holidays = array(); // Validation for malformed SSE
    $sunset     = isset($weather['sunset']) ? $weather['sunset'] : null;

    $nowStr   = isset($timeData['currentLocalTime']) ? $timeData['currentLocalTime'] : date('g:i A');
    $todayStr = isset($timeData['currentDate']) ? $timeData['currentDate'] : date('Y-m-d');
    $tz       = isset($timeData['timeZone']) ? $timeData['timeZone'] : 'America/Phoenix';
    $nowTs    = strtotime("$todayStr $nowStr") ?: time();
    $today    = date('Y-m-d', $nowTs);

    // 🔹 3. Load Codex TIS module (read-only)
    $codex = json_decode(@file_get_contents($codexPath), true) ?: array();
    $tis   = isset($codex['timeIntervalStandards']) ? $codex['timeIntervalStandards'] : array();

    // 🔹 4. Build environment-aware segments (from Codex TIS)
    $segments = array();
    $environment = 'office'; // Default
    $lp = strtolower($prompt);
    $tisKey = (strpos($lp, 'shop') !== false) ? 'segmentsShop' : 'segmentsOffice';
    if (isset($tis[$tisKey]['items']) && is_array($tis[$tisKey]['items'])) {
        $environment = (strpos($lp, 'shop') !== false) ? 'shop' : 'office';
        foreach ($tis[$tisKey]['items'] as $seg) {
            // FIXED: Exact match for 'Worktime' to avoid 'Before Worktime' false-positive
            if (preg_match('/^Worktime$/i', $seg['Interval'])) {
                list($s, $e) = explode(' – ', $seg['Hours']);
                $segments['worktime'] = array(
                    'start' => trim($s), 'end' => trim($e),
                    'name' => $seg['Interval']
                );
                break;
            }
        }
    } else {
        // Fallback (Codex-aligned default)
        $segments['worktime'] = array('start' => '7:30 AM', 'end' => '3:30 PM', 'name' => 'Worktime');
    }
    if ($sunset) {
        $segments['sundown'] = array('start' => $sunset, 'name' => 'Sundown');
    }

    // 🔹 5. Minimal event detection (Codex extensible; flags for router)
    $eventMap = array(
        'sundown' => '/sun(set|down)|dusk/',
        'worktime' => '/work(day|time)|office|start|begin|finish|end|close|business hours/',
        'holiday' => '/holiday/',
        'time' => '/time|clock|now/' // Default
    );
    $event = 'time';
    foreach ($eventMap as $key => $pattern) {
        if (preg_match($pattern, $lp)) {
            $event = $key;
            break;
        }
    }

    // Flags from prompt (for composer hooks)
    $flags = array(
        'useEndTime' => (strpos($lp, 'finish') !== false || strpos($lp, 'end') !== false || strpos($lp, 'close') !== false),
        'isTomorrow' => strpos($lp, 'tomorrow') !== false,
        'isNext' => strpos($lp, 'next') !== false,
        'isDelayed' => strpos($lp, 'delayed') !== false,
        'isAgo' => strpos($lp, 'ago') !== false
    );

    // 🔹 6. Resolve target (structured; rollover via loop)
    $dow = date('N', $nowTs); // 1=Mon, 7=Sun
    $isWorkdayToday = false; // FIXED: Explicit init
    if ($dow >= 1 && $dow <= 5 && !in_array($today, $holidays)) {
        $isWorkdayToday = true;
    }
    $targetTs = $nowTs;
    $targetDate = $today;
    $targetTime = null;
    $rollover = false;
    $status = 'pending';
    $workStartToday = null;
    $workEndToday = null;
    $elapsed = null; // For ongoing

    switch ($event) {
        case 'holiday':
            $nextHoliday = null;
            foreach ($holidays as $h) {
                $hDate = isset($h['date']) ? $h['date'] : (isset($h) ? $h : null);
                $hTs = strtotime($hDate);
                if ($hTs && $hTs >= $nowTs) {
                    $nextHoliday = array('date' => $hDate, 'name' => isset($h['name']) ? $h['name'] : 'Holiday');
                    break;
                }
            }
            if ($nextHoliday) {
                $targetTs = strtotime($nextHoliday['date']);
                $targetDate = $nextHoliday['date'];
            }
            break;

        case 'sundown':
            if ($sunset) {
                $targetTs = strtotime("$today $sunset");
                $targetTime = $sunset;
            }
            break;

        case 'worktime':
            $seg = isset($segments['worktime']) ? $segments['worktime'] : array('start' => '7:30 AM', 'end' => '3:30 PM');
            $targetTime = $flags['useEndTime'] ? $seg['end'] : $seg['start'];
            $workStartToday = strtotime("$today {$seg['start']}");
            $workEndToday = strtotime("$today {$seg['end']}");

            // FIXED Offset: Only future/delayed flags; no useEndTime
            $offset = ($flags['isTomorrow'] || $flags['isNext'] || $flags['isDelayed']) ? 1 : 0;
            $candidateOffset = $offset;
            while (true) {
                $candidateTs = strtotime("+{$candidateOffset} days", $nowTs);
                $candidateDow = date('N', $candidateTs);
                $candidateDate = date('Y-m-d', $candidateTs);
                if ($candidateDow >= 1 && $candidateDow <= 5 && !in_array($candidateDate, $holidays)) {
                    $targetDate = $candidateDate;
                    $rollover = ($candidateOffset > 0);
                    break;
                }
                $candidateOffset++;
            }
            $targetTs = strtotime("$targetDate $targetTime");
            if ($targetTs <= $nowTs) {
                $targetTs = strtotime("+1 day", $targetTs);
                $targetDate = date('Y-m-d', $targetTs);
                $rollover = true;
            }

            // FIXED: Special cases
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
                    'data' => array(
                        'definition' => isset($tis['purpose']['text']) ? $tis['purpose']['text'] : 'Defines temporal segmentation for scheduling.',
                        'runtime' => array(
                            'now' => date('g:i A', $nowTs),
                            'date' => date('F j, Y', $nowTs),
                            'timezone' => $tz,
                            'sunset' => $sunset,
                            'targetDate' => $targetDate,
                            'targetTime' => $targetTime,
                            'workStart' => $seg['start'],
                            'isWorkdayToday' => $isWorkdayToday,
                            'ongoing' => array('hours' => $ongoingHrs, 'minutes' => $ongoingMins)
                        ),
                        'intervals' => $segments
                    ),
                    'flags' => $flags + array('rollover' => $rollover)
                );
            }

            // FIXED Override: For start queries in progress (unifies 'in_effect')
            if (!$flags['useEndTime'] && $nowTs >= $workStartToday && $nowTs < $workEndToday) {
                $targetTs = $workStartToday;
                $targetDate = $today;
                $rollover = false;
                $elapsedDelta = $nowTs - $workStartToday;
                $elapsed = array(
                    'hours' => floor($elapsedDelta / 3600),
                    'minutes' => floor(($elapsedDelta % 3600) / 60)
                );
                $status = 'ongoing';
            }
            break;

        default:
            // Time query: no change
            break;
    }

    // 🔹 7. Compute raw delta (multi-day aware; for composer)
    $delta = $targetTs - $nowTs;
    $absDelta = abs($delta);
    $totalDays = floor($absDelta / 86400);
    $remSeconds = $absDelta % 86400;
    $hrs = floor($remSeconds / 3600);
    $mins = floor(($remSeconds % 3600) / 60);
    $isPast = $delta < 0;
    $direction = $isPast ? 'ago' : 'until';

    // Post-start flag for start queries
    $postStart = ($event === 'worktime' && !$flags['useEndTime'] && $nowTs > ($workStartToday ?: strtotime("$today {$segments['worktime']['start']}")));

    // 🔹 8. Structured output (Phase 6-ready: data only)
    $runtime = array(
        'now' => date('g:i A', $nowTs),
        'date' => date('F j, Y', $nowTs),
        'timezone' => $tz,
        'sunset' => $sunset,
        'targetDate' => $targetDate,
        'targetTime' => $targetTime ?: date('g:i A', $targetTs),
        'delta' => array(
            'totalDays' => $totalDays,
            'hours' => $hrs,
            'minutes' => $mins,
            'direction' => $direction,
            'isPast' => $isPast
        )
    );
    if ($elapsed) {
        $runtime['elapsed'] = $elapsed; // Positive ongoing components for composer
    }

    $data = array(
        'definition' => isset($tis['purpose']['text']) ? $tis['purpose']['text'] : 'Defines temporal segmentation for scheduling.',
        'runtime' => $runtime,
        'intervals' => $segments
    );

    return array(
        'domain' => 'temporal',
        'codexNode' => 'timeIntervalStandards',
        'prompt' => $prompt,
        'intent' => 'temporal_query',
        'event' => $event,
        'status' => $status ?? 'pending',
        'isWorkdayToday' => $isWorkdayToday,
        'data' => $data,
        'flags' => $flags + array(
            'rollover' => $rollover,
            'postStart' => $postStart,
            'isAgoMismatch' => $flags['isAgo'] && !$isPast
        )
    );
}