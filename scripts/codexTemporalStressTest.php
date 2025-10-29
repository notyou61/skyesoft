<?php
// =============================================================
// 🧪 Skyesoft Temporal Stress-Test Suite
// Version: 1.1 – Codex v5.2.1 (Weather & Time Validation)
// Purpose: Stress-test getDynamicData.php for all temporal logic
// Compatible: PHP 5.6+
// =============================================================

// --- Load environment and dependencies ---
date_default_timezone_set('America/Phoenix');
require_once __DIR__ . '/../api/getDynamicData.php';

// --- Allow local testing without SSE context ---
if (!function_exists('getDynamicData')) {
    echo "❌ getDynamicData() not found. Make sure /api/getDynamicData.php defines it.\n";
    exit(1);
}

// --- Helper for formatted output ---
function check($label, $value) {
    printf("%-28s %s\n", $label . ':', ($value !== null ? $value : '—'));
}

// --- Optional: direct weather summon (bypass SSE cache) ---
function summonWeatherData($location = 'Phoenix,US') {
    $envPath = realpath(__DIR__ . '/../.env');
    if ($envPath && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'WEATHER_API_KEY=') === 0) {
                list(, $val) = explode('=', $line, 2);
                $key = trim($val);
                break;
            }
        }
    }
    if (empty($key)) {
        echo "⚠️ WEATHER_API_KEY missing or unreadable.\n";
        return null;
    }

    $url = "https://api.openweathermap.org/data/2.5/weather?q=" .
           rawurlencode($location) . "&appid=" . $key . "&units=imperial";

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'SkyeSoft/1.0 (+skyelighting.com)'
    ));
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        echo "❌ Weather API error: $err\n";
        return null;
    }

    $json = json_decode($res, true);
    if (!is_array($json)) {
        echo "❌ Invalid JSON from API.\n";
        return null;
    }

    $tz = new DateTimeZone('America/Phoenix');
    $rise = new DateTime('@' . $json['sys']['sunrise']); $rise->setTimezone($tz);
    $set  = new DateTime('@' . $json['sys']['sunset']);  $set->setTimezone($tz);

    return array(
        'temp' => round($json['main']['temp']),
        'desc' => ucwords($json['weather'][0]['description']),
        'sunrise' => $rise->format('g:i A'),
        'sunset'  => $set->format('g:i A')
    );
}

// --- Pull dynamic data once (normal SSE route) ---
$data = getDynamicData();
$weather = summonWeatherData(); // live API call

echo "=== Temporal Validation ===\n";
check('Current Date', $data['currentDate']);
check('Current Time', $data['currentTime']);
check('Weather Status', $weather ? $weather['desc'] : $data['weather']['description']);
check('Temperature (°F)', $weather ? $weather['temp'] : 'N/A');
check('Sunrise', $weather ? $weather['sunrise'] : $data['weather']['sunrise']);
check('Sunset',  $weather ? $weather['sunset']  : $data['weather']['sunset']);
check('Daytime Hours', $data['weather']['daytimeHours']);
check('Nighttime Hours', $data['weather']['nighttimeHours']);
check('Next Holiday', $data['holidays'][count($data['holidays']) - 1]['name'] . ' – ' .
                      $data['holidays'][count($data['holidays']) - 1]['date']);
check('Workday Ends', defined('WORKDAY_END') ? WORKDAY_END : 'N/A');
check('Seconds Until Work End', $data['intervalsArray']['currentDaySecondsRemaining']);

echo "\n=== Live Weather Sample ===\n";
if ($weather) {
    echo "🌤️  {$weather['desc']} — {$weather['temp']}°F (Sunrise: {$weather['sunrise']}, Sunset: {$weather['sunset']})\n";
} else {
    echo "⚠️  Live weather unavailable — using Codex fallback.\n";
}

echo "\n✅ Stress test complete.\n";