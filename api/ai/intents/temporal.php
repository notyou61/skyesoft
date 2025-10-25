<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Generate temporal reasoning context (Codex + SSE integration)
// Version: v3.4 – Enhanced delta verbalization (multi-day, ago overrides), SSE intervals override, PHP 5.6-safe

function handleIntent($prompt, $codexPath, $ssePath)
{
    date_default_timezone_set('America/Phoenix');

    // ✅ Pull live data from remote endpoint
    $endpoint = "https://www.skyelighting.com/skyesoft/api/getDynamicData.php";
    $context = stream_context_create(array(
        'http' => array(
            'method'  => 'GET',
            'timeout' => 5,
            'header'  => "User-Agent: SkyebotTemporalFetcher/1.0\r\n"
        )
    ));

    $dynamicData = @file_get_contents($endpoint, false, $context);
    if ($dynamicData === false || trim($dynamicData) === '') {
        return array('error' => 'SSE dynamic data unavailable (endpoint unreachable).');
    }

    $sse = json_decode($dynamicData, true);
    if (!is_array($sse) || json_last_error() !== JSON_ERROR_NONE) {
        return array('error' => 'Invalid SSE JSON.');
    }

    // Extract live data
    $timeData    = isset($sse['timeDateArray']) ? $sse['timeDateArray'] : array();
    $weatherData = isset($sse['weatherData'])   ? $sse['weatherData']   : array();
    $sunset      = isset($weatherData['sunset']) ? $weatherData['sunset'] : null;
    $holidays    = isset($weatherData['federalHolidaysDynamic']) && is_array($weatherData['federalHolidaysDynamic'])
        ? $weatherData['federalHolidaysDynamic'] : array();

    // Load Codex
    $codexRaw = file_exists($codexPath) ? file_get_contents($codexPath) : '{}';
    $codex = json_decode($codexRaw, true) ?: array();
    $timeModule = isset($codex['timeIntervalStandards']) ? $codex['timeIntervalStandards'] : array();

    $nowStr   = isset($timeData['currentLocalTime']) ? $timeData['currentLocalTime'] : date("g:i A");
    $phase    = isset($timeData['timeOfDayDescription']) ? $timeData['timeOfDayDescription'] : 'unknown';
    $tz       = isset($timeData['timeZone']) ? $timeData['timeZone'] : 'America/Phoenix';
    $todayStr = isset($timeData['currentDate']) ? $timeData['currentDate'] : date('Y-m-d');

    // --- 🔹 Parse current time first (then classify day) ---
    $nowTimestamp = strtotime("$todayStr $nowStr");
    if ($nowTimestamp === false) {
        $nowTimestamp = time(); // Fallback to Unix timestamp
    }

    // --- 🔹 Determine Day Type via Codex + Helper ---
    $dayOfWeek  = (int)date('N', $nowTimestamp);
    $todayDate  = date('Y-m-d', $nowTimestamp);


    // Safely include the helper if not already loaded
    if (!function_exists('resolveDayType')) {
        $helperPath = dirname(__DIR__) . '/../helpers.php';
        if (file_exists($helperPath)) {
            include_once($helperPath);
        }
    }

    // Use Codex-based day resolver
    $dayTypeInfo = array('dayType' => 'Unknown', 'isWorkday' => false);
    if (function_exists('resolveDayType')) {
        $dayTypeInfo = resolveDayType($timeModule, $holidays, $nowTimestamp);
    }
    $isWorkdayToday = isset($dayTypeInfo['isWorkday']) ? $dayTypeInfo['isWorkday'] : false;


    // Normalize prompt to lowercase for matching
    $lowerPrompt = strtolower(trim($prompt));

    // Parse intervals from Codex tables (Office/Shop segments) – override with SSE if available
    $events = array();
    $environment = 'office'; // Default; detect from prompt (e.g., 'shop' keyword)

    // Check SSE for live workday intervals (overrides Codex)
    $sseWorkIntervals = isset($sse['intervalsArray']['workdayIntervals']) ? $sse['intervalsArray']['workdayIntervals'] : null;
    if ($sseWorkIntervals && isset($sseWorkIntervals['start']) && isset($sseWorkIntervals['end'])) {
        $events['worktime'] = array(
            'start' => $sseWorkIntervals['start'],
            'end'   => $sseWorkIntervals['end'],
            'name'  => 'Worktime',
            'environment' => $environment  // Inherit detected env
        );
        error_log("⏱️ SSE live intervals override: {$sseWorkIntervals['start']}–{$sseWorkIntervals['end']}");
    } else {
        // Fallback to Codex parsing
        // Office segments
        if (isset($timeModule['segmentsOffice']) && isset($timeModule['segmentsOffice']['items']) && is_array($timeModule['segmentsOffice']['items'])) {
            foreach ($timeModule['segmentsOffice']['items'] as $item) {
                if (stripos($item['Interval'], 'Worktime') !== false) {  // Focus on worktime for default
                    $intervalKey = 'worktime';
                    $hoursRange = $item['Hours']; // e.g., "7:30 AM – 3:30 PM"
                    list($startTime, $endTime) = explode(' – ', $hoursRange);
                    $events[$intervalKey] = array(
                        'start' => trim($startTime),
                        'end'   => trim($endTime),
                        'name'  => $item['Interval'],
                        'environment' => 'office'
                    );
                    break;  // Use first match for simplicity
                }
            }
        }

        // Shop segments (if detected, override)
        if (strpos($lowerPrompt, 'shop') !== false && isset($timeModule['segmentsShop']) && isset($timeModule['segmentsShop']['items']) && is_array($timeModule['segmentsShop']['items'])) {
            $environment = 'shop';
            foreach ($timeModule['segmentsShop']['items'] as $item) {
                if (stripos($item['Interval'], 'Worktime') !== false) {
                    $intervalKey = 'worktime';
                    $hoursRange = $item['Hours']; // e.g., "6:00 AM – 2:00 PM"
                    list($startTime, $endTime) = explode(' – ', $hoursRange);
                    $events[$intervalKey] = array(
                        'start' => trim($startTime),
                        'end'   => trim($endTime),
                        'name'  => $item['Interval'],
                        'environment' => 'shop'
                    );
                    break;
                }
            }
        }
    }

    // Sundown as special event
    if ($sunset) {
        $events['sundown'] = array(
            'start' => $sunset,
            'end'   => null,
            'name'  => 'Sundown',
            'environment' => 'general'
        );
    }

    // Detect intent from prompt (simple keyword matching, extensible to regex/AI)
    $detectedEvent = null;
    $eventMapping = array(
        'worktime' => array('workday', 'work day', 'worktime', 'office hours', 'business hours', 'work begins', 'work starts', 'business hours', 'next workday'),
        'sundown'  => array('sundown', 'sunset', 'sun down', 'dusk')
    );

    foreach ($eventMapping as $eventKey => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($lowerPrompt, $kw) !== false) {
                $detectedEvent = $eventKey;
                break 2;
            }
        }
    }

    // Fallback: if no specific event, default to generic time query (e.g., current time)
    if (!$detectedEvent) {
        $detectedEvent = 'worktime'; // Assume workday query as default for temporal
    }

    $eventData = isset($events[$detectedEvent]) ? $events[$detectedEvent] : null;
    if (!$eventData) {
        return array('error' => "Unsupported temporal event: $detectedEvent");
    }

    // For workday: adjust target to next workday start if today is not workday or prompt specifies "next"/"tomorrow"
    $targetTimeStr = $eventData['start']; // Default to start
    $useEndTime = (strpos($lowerPrompt, 'finish') !== false || strpos($lowerPrompt, 'end') !== false);
    if ($useEndTime) {
        $targetTimeStr = $eventData['end'];
    }
    $targetDateStr = $todayStr;
    $isNextDay = (strpos($lowerPrompt, 'tomorrow') !== false || strpos($lowerPrompt, 'next') !== false);
    if (($detectedEvent === 'worktime' && (!$isWorkdayToday || $isNextDay)) || $useEndTime) {
        // Find next valid occurrence (workday for worktime; today for end/sundown)
        $offsetDays = $useEndTime ? 0 : 1;  // End is today; next start skips if needed
        // Loop until next valid workday found
        while (true) {
            $nextDateTimestamp = strtotime("+$offsetDays days", $nowTimestamp);
            if (function_exists('resolveDayType')) {
                $nextDayInfo = resolveDayType($timeModule, $holidays, $nextDateTimestamp);
                if ($detectedEvent !== 'worktime' || $nextDayInfo['isWorkday']) {
                    $targetDateStr = date('Y-m-d', $nextDateTimestamp);
                    break;
                }
            } else {
                // Fallback to weekday logic if helper not loaded
                $nextDayOfWeek = (int)date('N', $nextDateTimestamp);
                $nextDateStr = date('Y-m-d', $nextDateTimestamp);
                if ($detectedEvent !== 'worktime' || ($nextDayOfWeek >= 1 && $nextDayOfWeek <= 5 && !in_array($nextDateStr, $holidays))) {
                    $targetDateStr = $nextDateStr;
                    break;
                }
            }
            $offsetDays++;
        }
    }

    // Handle sundown specially (single point, today only)
    if ($detectedEvent === 'sundown') {
        $targetTimestamp = $sunset ? strtotime("$todayStr $sunset") : $nowTimestamp;
    } else {
        // Parse target time for target date
        $targetTimestamp = strtotime("$targetDateStr $targetTimeStr");
        if ($targetTimestamp === false || $targetTimestamp <= $nowTimestamp) {
            // If past or equal, roll to next occurrence
            $nextOccurrence = strtotime("+1 day", $targetTimestamp);
            $targetTimestamp = $nextOccurrence;
            $targetDateStr = date('Y-m-d', $targetTimestamp);  // Update date
        }
    }

    // Compute delta with multi-day support
    $deltaSeconds = $targetTimestamp - $nowTimestamp;
    $totalDays = floor(abs($deltaSeconds) / 86400);
    $remainingSeconds = abs($deltaSeconds) % 86400;
    $deltaHours = floor($remainingSeconds / 3600);
    $deltaMinutes = floor(($remainingSeconds % 3600) / 60);
    $isPast = $deltaSeconds < 0;

    // Verbal delta for Phase 6 (e.g., "1 day, 2 hours" or "15 minutes ago")
    $verbalParts = array();
    if ($totalDays > 0) {
        $verbalParts[] = "{$totalDays} day" . ($totalDays > 1 ? 's' : '');
    }
    if ($deltaHours > 0) {
        $verbalParts[] = "{$deltaHours} hour" . ($deltaHours > 1 ? 's' : '');
    }
    if ($deltaMinutes > 0 || (empty($verbalParts) && $totalDays == 0 && $deltaHours == 0)) {
        $verbalParts[] = "{$deltaMinutes} minute" . ($deltaMinutes > 1 ? 's' : '');
    }
    $verbalDelta = implode(' and ', $verbalParts);
    $direction = $isPast ? 'ago' : 'until';
    if (strpos($lowerPrompt, 'ago') !== false && !$isPast) {
        $verbalDelta = "The event hasn't started yet—it's {$verbalDelta} {$direction}.";
    } elseif ($isPast) {
        $verbalDelta .= " {$direction}";
    } else {
        $verbalDelta = $verbalDelta ? "in {$verbalDelta}" : 'imminent';
    }

    // Structured response for Phase 6 composer
    return array(
        'domain'    => 'temporal',
        'codexNode' => 'timeIntervalStandards',
        'prompt'    => $prompt,
        'event'     => $detectedEvent,
        'environment' => $environment,
        'isWorkdayToday' => $isWorkdayToday,
        'data' => array(
            'definition' => isset($timeModule['purpose']['text']) ? $timeModule['purpose']['text'] : 'Time interval standards for operational scheduling.',
            'runtime' => array(
                'now'           => $nowStr,
                'nowTimestamp'  => $nowTimestamp,
                'today'         => $todayStr,
                'targetEvent'   => $eventData['name'],
                'targetDate'    => $targetDateStr,
                'targetTime'    => $targetTimeStr,
                'targetTimestamp' => $targetTimestamp,
                'delta'         => array(
                    'seconds' => $deltaSeconds,
                    'days'    => $totalDays,
                    'hours'   => $deltaHours,
                    'minutes' => $deltaMinutes,
                    'isPast'  => $isPast,
                    'direction' => $direction,
                    'verbal'  => $verbalDelta  // Ready for Phase 6 natural text
                ),
                'timezone'      => $tz,
                'phase'         => $phase,
                'sunset'        => $sunset,
                'conditions'    => isset($weatherData['description']) ? $weatherData['description'] : 'unknown',
                'dayOfWeek'     => $dayOfWeek,
                'holidays'      => $holidays
            ),
            'intervals' => $events // For broader context if needed
        ),
        'intent' => "Temporal query for '$detectedEvent' resolved with enhanced delta (multi-day verbal, ago overrides) using SSE/Codex + live data (holiday/weekend aware)."
    );
}