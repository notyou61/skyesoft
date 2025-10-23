<?php
// 📄 File: api/ai/intents/temporal.php
// Purpose: Time- and schedule-related reasoning

function handleIntent($prompt, $codexPath, $ssePath)
{
    date_default_timezone_set('America/Phoenix');
    $now = new DateTime();

    // Workday end = 3:30 PM for office schedule
    $end = new DateTime('15:30');
    if ($now > $end) {
        return "🌇 The workday has already ended.";
    }

    $diff = $now->diff($end);
    $hrs  = $diff->h;
    $mins = $diff->i;

    $timeLeft = sprintf("%d hour%s and %d minute%s",
        $hrs, ($hrs !== 1 ? 's' : ''), $mins, ($mins !== 1 ? 's' : ''));

    return "⏳ There are {$timeLeft} remaining in the workday.";
}