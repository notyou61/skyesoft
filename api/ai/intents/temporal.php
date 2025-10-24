<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Generate temporal reasoning context (Codex + SSE integration)
// Version: v3.3 – Parses Codex table-based intervals (Office/Shop), holiday/weekend checks, PHP 5.6-safe

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

    // Parse current time to timestamp (PHP 5.6-safe)
    $nowTimestamp = strtotime("$todayStr $nowStr");
    if ($nowTimestamp === false) {
        $nowTimestamp = time(); // Fallback to Unix timestamp
    }

    // Determine if today is a workday (not weekend/holiday)
    $dayOfWeek = date('N', $nowTimestamp); // 1=Mon, 7=Sun
    $todayDate = date('Y-m-d', $nowTimestamp);
    $isWorkdayToday = ($dayOfWeek >= 1 && $dayOfWeek <= 5) && !in_array($todayDate, $holidays);

    // Normalize prompt to lowercase for matching
    $lowerPrompt = strtolower(trim($prompt));

    // Parse intervals from Codex tables (Office/Shop segments)
    $events = array();
    $environment = 'office'; // Default; could detect from prompt (e.g., 'shop' keyword)

    // Office segments
    if (isset($timeModule['segmentsOffice']) && isset($timeModule['segmentsOffice']['items']) && is_array($timeModule['segmentsOffice']['items'])) {
        foreach ($timeModule['segmentsOffice']['items'] as $item) {
            $intervalKey = strtolower(str_replace(' ', '_', $item['Interval']));
            $hoursRange = $item['Hours']; // e.g., "12:00 AM – 7:29 AM"
            list($startTime, $endTime) = explode(' – ', $hoursRange);
            $events[$intervalKey] = array(
                'start' => trim($startTime),
                'end'   => trim($endTime),
                'name'  => $item['Interval'],
                'environment' => 'office'
            );
        }
    }

    // Shop segments (if detected, override or add)
    if (strpos($lowerPrompt, 'shop') !== false && isset($timeModule['segmentsShop']) && isset($timeModule['segmentsShop']['items']) && is_array($timeModule['segmentsShop']['items'])) {
        $environment = 'shop';
        foreach ($timeModule['segmentsShop']['items'] as $item) {
            $intervalKey = strtolower(str_replace(' ', '_', $item['Interval']));
            $hoursRange = $item['Hours']; // e.g., "12:00 AM – 5:59 AM"
            list($startTime, $endTime) = explode(' – ', $hoursRange);
            $events[$intervalKey] = array(
                'start' => trim($startTime),
                'end'   => trim($endTime),
                'name'  => $item['Interval'],
                'environment' => 'shop'
            );
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
        'worktime' => array('workday', 'work day', 'worktime', 'office hours', 'business hours', 'work begins', 'work starts'),
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

    // For workday: adjust target to next workday start if today is not workday
    $targetTimeStr = $eventData['start']; // Default to start
    $targetDateStr = $todayStr;
    if ($detectedEvent === 'worktime' && !$isWorkdayToday) {
        // Find next workday
        $offsetDays = 1;
        while (true) {
            $nextDateTimestamp = strtotime("+$offsetDays days", $nowTimestamp);
            $nextDayOfWeek = date('N', $nextDateTimestamp);
            $nextDateStr = date('Y-m-d', $nextDateTimestamp);
            if ($nextDayOfWeek >= 1 && $nextDayOfWeek <= 5 && !in_array($nextDateStr, $holidays)) {
                $targetDateStr = $nextDateStr;
                break;
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
        }
    }

    // Compute delta
    $deltaSeconds = $targetTimestamp - $nowTimestamp;
    $deltaHours = floor($deltaSeconds / 3600);
    $deltaMinutes = floor(($deltaSeconds % 3600) / 60);
    $isPast = $deltaSeconds < 0;

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
                    'hours'   => abs($deltaHours), // Abs for display, but flag isPast
                    'minutes' => abs($deltaMinutes),
                    'isPast'  => $isPast,
                    'direction' => $isPast ? 'ago' : 'until'
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
        'intent' => "Temporal query for '$detectedEvent' resolved with delta calculation using Codex table-parsed intervals + live SSE data (holiday/weekend aware)."
    );
}