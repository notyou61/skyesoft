<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Time- and schedule-related reasoning

function handle_temporal($prompt, $codex, $sse) {
    $end   = isset($sse['timeDateArray']['intervalsArray']['workdayIntervals']['end'])
        ? $sse['timeDateArray']['intervalsArray']['workdayIntervals']['end'] : 'unknown';
    $start = isset($sse['timeDateArray']['intervalsArray']['workdayIntervals']['start'])
        ? $sse['timeDateArray']['intervalsArray']['workdayIntervals']['start'] : 'unknown';
    $time  = isset($sse['timeDateArray']['currentLocalTime'])
        ? $sse['timeDateArray']['currentLocalTime'] : '';

    if (stripos($prompt, 'end') !== false)
        return "According to today's Time Interval Standards, the workday ends at {$end}. Current time is {$time}.";
    if (stripos($prompt, 'start') !== false)
        return "The workday begins at {$start}. Current time is {$time}.";
    return "Today's work schedule runs from {$start} to {$end}. It’s now {$time}.";
}