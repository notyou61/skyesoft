<?php
// 📁 File: api/getDynamicData.php

#region 🔧 Init and Headers
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
#endregion

#region 🔐 Load Environment Variables
$envPath = realpath(__DIR__ . '/../../../secure/.env');
$env = array();

if ($envPath && file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value);
    }
} else {
    echo json_encode(array("error" => "Configuration error: .env file not found"));
    exit;
}
#endregion

#region 🌦️ Fetch Weather Data (Upgraded with Forecast + Curl)
$weatherApiKey = isset($env['WEATHER_API_KEY']) ? $env['WEATHER_API_KEY'] : null;
$weatherLocation = "Phoenix,US";
$lat = "33.448376";
$lon = "-112.074036";

$weatherData = array(
    "temp" => null,
    "icon" => "❓",
    "description" => "Unavailable",
    "lastUpdatedUnix" => null,
    "sunrise" => null,
    "sunset" => null,
    "daytimeHours" => null,
    "nighttimeHours" => null,
    "forecast" => []
);

function fetchJsonCurl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // ✅ GoDaddy-safe
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return $res === false ? ["error" => $err] : json_decode($res, true);
}

if (!$weatherApiKey) {
    $weatherData["description"] = "Missing API key";
    error_log("❌ Missing WEATHER_API_KEY in .env");
} else {
    // 🌡️ Current Weather
    $currentUrl = "https://api.openweathermap.org/data/2.5/weather?q={$weatherLocation}&appid={$weatherApiKey}&units=imperial";
    $current = fetchJsonCurl($currentUrl);

    if (!isset($current["main"]["temp"])) {
        $weatherData["description"] = "API call failed (current)";
        error_log("❌ Current weather failed: " . json_encode($current));
    } else {
        $sunriseUnix = $current['sys']['sunrise'];
        $sunsetUnix = $current['sys']['sunset'];
        $daySecs = $sunsetUnix - $sunriseUnix;
        $nightSecs = 86400 - $daySecs;

        $weatherData["temp"] = round($current['main']['temp']);
        $weatherData["icon"] = $current['weather'][0]['icon'];
        $weatherData["description"] = ucfirst($current['weather'][0]['description']);
        $weatherData["lastUpdatedUnix"] = time();
        $weatherData["sunrise"] = date("g:i A", $sunriseUnix);
        $weatherData["sunset"] = date("g:i A", $sunsetUnix);
        $weatherData["daytimeHours"] = gmdate("G\h i\m", $daySecs);
        $weatherData["nighttimeHours"] = gmdate("G\h i\m", $nightSecs);
    }

    // 📅 Forecast (next 3 days)
    $forecastUrl = "https://api.openweathermap.org/data/2.5/forecast?q={$weatherLocation}&appid={$weatherApiKey}&units=imperial";
    $forecast = fetchJsonCurl($forecastUrl);

    if (isset($forecast['list'])) {
        $daily = [];
        foreach ($forecast['list'] as $entry) {
            $date = date("Y-m-d", $entry['dt']);
            if (!isset($daily[$date])) {
                $daily[$date] = [
                    "high" => $entry['main']['temp_max'],
                    "low" => $entry['main']['temp_min'],
                    "desc" => $entry['weather'][0]['description'],
                    "icon" => $entry['weather'][0]['icon'],
                    "pop" => $entry['pop'] * 100,
                    "wind" => $entry['wind']['speed']
                ];
            } else {
                $daily[$date]['high'] = max($daily[$date]['high'], $entry['main']['temp_max']);
                $daily[$date]['low'] = min($daily[$date]['low'], $entry['main']['temp_min']);
            }
        }

        $count = 0;
        foreach ($daily as $date => $info) {
            if ($count++ >= 3) break;
            $weatherData["forecast"][] = [
                "date" => date("l, M j", strtotime($date)),
                "description" => ucfirst($info["desc"]),
                "high" => round($info["high"]),
                "low" => round($info["low"]),
                "icon" => $info["icon"],
                "precip" => round($info["pop"]),
                "wind" => round($info["wind"])
            ];
        }
    } else {
        error_log("❌ Forecast fetch failed: " . json_encode($forecast));
    }
}
#endregion

#region 📁 Paths and Constants
$holidaysPath = "../../assets/data/federal_holidays_dynamic.json";
$dataPath = "../../assets/data/skyesoft-data.json";
$versionPath = "../../assets/data/version.json";
$codexPath = "../../assets/data/codex.json";
$chatLogPath = "../../assets/data/chatLog.json";
$weatherPath = "../../assets/data/weatherCache.json";
$announcementsPath = "../../assets/data/announcements.json";  // 📢 Office announcements and tips

define('WORKDAY_START', '07:30');
define('WORKDAY_END', '15:30');
#endregion

#region 🔄 Enhanced Time Breakdown (PHP 5.6 compatible)
// 🔒 Set fixed timezone for the system (Skyesoft standard)
date_default_timezone_set("America/Phoenix");
$timeZone = "America/Phoenix";  // Used for display or logging
// 🕒 Capture current time snapshot
$now = time();
$yearTotalDays = (date("L", $now) ? 366 : 365);
$yearDayNumber = intval(date("z", $now)) + 1;
$yearDaysRemaining = $yearTotalDays - $yearDayNumber;
$monthNumber = intval(date("n", $now));
$weekdayNumber = intval(date("w", $now));
$dayNumber = intval(date("j", $now));
$currentHour = intval(date("G", $now));
$timeOfDayDesc = ($currentHour < 12) ? "morning" : (($currentHour < 18) ? "afternoon" : "evening");
// 🕓 UTC offset based on timezone
$dt = new DateTime("now", new DateTimeZone($timeZone));
$utcOffset = intval($dt->format('Z')) / 3600;
// 📆 Day start/end
$currentDayStartUnix = strtotime("today", $now);
$currentDayEndUnix = strtotime("tomorrow", $now) - 1;
#endregion

#region 🔧 Utility Functions
function timeStringToSeconds($timeStr) {
    list($h, $m) = explode(":", $timeStr);
    return $h * 3600 + $m * 60;
}

function isHoliday($dateStr, $holidays) {
    foreach ($holidays as $holiday) {
        if ($holiday['date'] == $dateStr) return true;
    }
    return false;
}

function isWorkday($date, $holidays) {
    $day = date('w', strtotime($date));
    return $day != 0 && $day != 6 && !isHoliday($date, $holidays);
}

function findNextWorkdayStart($startDate, $holidays) {
    $date = strtotime($startDate . " +1 day");
    while (!isWorkday(date("Y-m-d", $date), $holidays)) {
        $date = strtotime("+1 day", $date);
    }
    return strtotime(date("Y-m-d", $date) . " " . WORKDAY_START);
}
#endregion

#region 📅 Load Data and Holidays
$holidays = array();
if (file_exists($holidaysPath)) {
    $holidaysData = json_decode(file_get_contents($holidaysPath), true);
    $holidays = isset($holidaysData['holidays']) ? $holidaysData['holidays'] : array();
}
#endregion

#region ⏳ Time Calculations
$currentDate = date("Y-m-d", $now);
$currentTime = date("h:i:s A", $now);
$currentSeconds = date("G", $now) * 3600 + date("i", $now) * 60 + date("s", $now);
$currentUnixTime = $now;

$workStart = timeStringToSeconds(WORKDAY_START);
$workEnd = timeStringToSeconds(WORKDAY_END);

$isHoliday = isHoliday($currentDate, $holidays);
$isWorkday = isWorkday($currentDate, $holidays);

$intervalLabel = ($isWorkday && $currentSeconds >= $workStart && $currentSeconds < $workEnd) ? "0" : "1";
$dayType = (!$isWorkday ? ($isHoliday ? "2" : "1") : "0");

if ($intervalLabel === "1") {
    $nextStart = ($isWorkday && $currentSeconds < $workStart)
        ? strtotime($currentDate . " " . WORKDAY_START)
        : findNextWorkdayStart($currentDate, $holidays);
    $secondsRemaining = $nextStart - $now;
} else {
    $secondsRemaining = $workEnd - $currentSeconds;
}
#endregion

#region 📊 Record Counts
$recordCounts = array("actions"=>0, "entities"=>0, "locations"=>0, "contacts"=>0, "orders"=>0, "permits"=>0, "notes"=>0, "tasks"=>0);
if (file_exists($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
    foreach ($recordCounts as $key => $val) {
        if (isset($data[$key])) $recordCounts[$key] = count($data[$key]);
    }
}
#endregion

#region 🛰️ Version Metadata
// 🔧 Default values
$version = [
    "siteVersion"     => "unknown",
    "lastDeployNote"  => "Unavailable",
    "lastDeployTime"  => null,
    "commitHash"      => "unknown",
    "deployState"     => "unknown",
    "deployIsLive"    => false,
    "cronCount"       => 0,
    "streamCount"     => 0,
    "aiQueryCount"    => 0,
    "uptimeSeconds"   => null
];

// 📥 Attempt to load from file
if (file_exists($versionPath)) {
    $json = file_get_contents($versionPath);
    $verData = json_decode($json, true);
    if (is_array($verData)) {
        $version = array_merge($version, $verData);
    } else {
        error_log("❌ version.json found but contains invalid JSON.");
    }
} else {
    error_log("⚠️ version.json not found at: $versionPath");
}
#endregion

#region 📤 Response
$response = array(
    "timeDateArray" => array(
        "currentUnixTime" => $currentUnixTime,
        "currentLocalTime" => $currentTime,
        "currentDate" => $currentDate,
        "currentYearTotalDays" => $yearTotalDays,
        "currentYearDayNumber" => $yearDayNumber,
        "currentYearDaysRemaining" => $yearDaysRemaining,
        "currentMonthNumber" => strval($monthNumber),
        "currentWeekdayNumber" => strval($weekdayNumber),
        "currentDayNumber" => strval($dayNumber),
        "currentHour" => strval($currentHour),
        "timeOfDayDescription" => $timeOfDayDesc,
        "timeZone" => $timeZone,
        "UTCOffset" => $utcOffset,
        "daylightStartEndArray" => array(
            "daylightStart" => "05:27:00",  // 🔧 Replace with real sunrise later
            "daylightEnd" => "19:42:00"
        ),
        "defaultLatitudeLongitudeArray" => array(
            "defaultLatitude" => "33.448376",
            "defaultLongitude" => "-112.074036",
            "solarZenithAngle" => 90.83,
            "defaultUTCOffset" => $utcOffset
        ),
        "currentDayBeginningEndingUnixTimeArray" => array(
            "currentDayStartUnixTime" => $currentDayStartUnix,
            "currentDayEndUnixTime" => $currentDayEndUnix
        )
    ),
    "intervalsArray" => array(
        "currentDaySecondsRemaining" => $secondsRemaining,
        "intervalLabel" => $intervalLabel,
        "dayType" => $dayType,
        "workdayIntervals" => array(
            "start" => WORKDAY_START,
            "end" => WORKDAY_END
        )
    ),
    "recordCounts" => $recordCounts,
    "weatherData" => $weatherData, // Use cURL-based weather data
    "kpiData" => array(
        "contacts" => 36,
        "orders" => 22,
        "approvals" => 3
    ),
    "uiHints" => array(
        "tips" => array(
            "Measure twice, cut once.",
            "Stay positive, work hard, make it happen.",
            "Quality is never an accident.",
            "Efficiency is doing better what is already being done.",
            "Every day is a fresh start."
        )
    ),
    "siteMeta" => array(
        "siteVersion" => $version['siteVersion'],
        "lastDeployNote" => $version['lastDeployNote'],
        "lastDeployTime" => $version['lastDeployTime'],
        "deployState" => $version['deployState'],
        "deployIsLive" => ($version['deployState'] === "published"),
        "cronCount" => $version['cronCount'],
        "streamCount" => 23,
        "aiQueryCount" => $version['aiQueryCount'],
        "uptimeSeconds" => null
    )
);
#endregion

#region 🟢 Output
echo json_encode($response);
#endregion