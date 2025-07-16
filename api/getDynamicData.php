<?php
// 📁 File: api/getDynamicData.php

#region 🔧 Init and Headers
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
#endregion

#region 🔐 Load Environment Variables
$envPath = 'C:/Users/steve/Documents/skyesoft/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        putenv(trim($line));
    }
}
#endregion

#region 🌦️ Fetch Weather Data
$weatherApiKey = getenv("WEATHER_API_KEY");
$weatherLocation = "Phoenix,US";

if (!$weatherApiKey) {
    $weatherData = [
        "temp" => null,
        "icon" => "❓",
        "description" => "Missing API key",
        "lastUpdatedUnix" => null
    ];
} else {
    $weatherUrl = "https://api.openweathermap.org/data/2.5/weather?q={$weatherLocation}&appid={$weatherApiKey}&units=imperial";
    $weatherJson = @file_get_contents($weatherUrl);
    $weatherParsed = json_decode($weatherJson, true);

    $weatherData = [
        "temp" => isset($weatherParsed['main']['temp']) ? round($weatherParsed['main']['temp']) : null,
        "icon" => isset($weatherParsed['weather'][0]['icon']) ? $weatherParsed['weather'][0]['icon'] : "❓",
        "description" => isset($weatherParsed['weather'][0]['description']) ? ucfirst($weatherParsed['weather'][0]['description']) : "Unavailable",
        "lastUpdatedUnix" => time()
    ];
}
#endregion

#region 🌐 Headers and Timezone 
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
date_default_timezone_set("America/Phoenix");

// ✅ Declare current time reference
$now = time();
#endregion

#region 📁 Paths and Constants
$holidaysPath = "../../assets/data/federal_holidays_dynamic.json";
$dataPath = "../../assets/data/skyesoft-data.json";
$versionPath = "../../assets/data/version.json";
$codexPath = "../../assets/data/codex.json";
$chatLogPath = "../../assets/data/chatLog.json";
$weatherPath = "../../assets/data/weatherCache.json";

const WORKDAY_START = '07:30';
const WORKDAY_END = '15:30';
#endregion

#region 🔄 Enhanced Time Breakdown (PHP 5.6 compatible)
$yearTotalDays = (date("L", $now) ? 366 : 365);
$yearDayNumber = intval(date("z", $now)) + 1;
$yearDaysRemaining = $yearTotalDays - $yearDayNumber;
$monthNumber = intval(date("n", $now));
$weekdayNumber = intval(date("w", $now));
$dayNumber = intval(date("j", $now));
$currentHour = intval(date("G", $now));
$timeOfDayDesc = ($currentHour < 12) ? "morning" : (($currentHour < 18) ? "afternoon" : "evening");
$timeZone = date_default_timezone_get();
$dt = new DateTime("now", new DateTimeZone($timeZone));
$utcOffset = intval($dt->format('Z')) / 3600;
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
$holidays = [];
if (file_exists($holidaysPath)) {
    $holidaysData = json_decode(file_get_contents($holidaysPath), true);
    $holidays = $holidaysData['holidays'] ?? [];
}
#endregion

#region ⏳ Time Calculations
$now = time();
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

#region ☁️ Fetch Weather Data (PHP 5.6 Compatible)
// 🔐 Uses OpenWeatherMap API with hardcoded location "Phoenix,US"
$weatherApiKey = getenv("WEATHER_API_KEY");
$weatherLocation = "Phoenix,US";  // 👈 Hardcoded location
$weatherData = array(
    "temp" => null,
    "icon" => "❓",
    "description" => "Loading...",
    "lastUpdatedUnix" => null
);
// Attempt to fetch weather data
if ($weatherApiKey) {
    $weatherUrl = "https://api.openweathermap.org/data/2.5/weather?q=" . urlencode($weatherLocation) . "&appid={$weatherApiKey}&units=imperial";
    $weatherJson = @file_get_contents($weatherUrl);
    if ($weatherJson !== false) {
        $weather = json_decode($weatherJson, true);
        if (isset($weather['main']['temp']) && isset($weather['weather'][0]['description'])) {
            $weatherData = array(
                "temp" => $weather['main']['temp'],
                "icon" => $weather['weather'][0]['icon'],
                "description" => $weather['weather'][0]['description'],
                "lastUpdatedUnix" => time()
            );
        } else {
            $weatherData['description'] = "Incomplete data";
        }
    } else {
        $weatherData['description'] = "API call failed";
    }
} else {
    $weatherData['description'] = "Missing API key";
}
#endregion

#region 📊 Record Counts
$recordCounts = ["actions"=>0,"entities"=>0,"locations"=>0,"contacts"=>0,"orders"=>0,"permits"=>0,"notes"=>0,"tasks"=>0];
if (file_exists($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
    foreach ($recordCounts as $key => $val) {
        if (isset($data[$key])) $recordCounts[$key] = count($data[$key]);
    }
}
#endregion

#region 🛰️ Version Metadata
$version = [
    "cronCount" => 0,
    "aiQueryCount" => 0,
    "siteVersion" => "unknown",
    "lastDeployNote" => "Unavailable",
    "lastDeployTime" => null,
    "deployState" => "unknown",
    "deployIsLive" => false
];
if (file_exists($versionPath)) {
    $verData = json_decode(file_get_contents($versionPath), true);
    $version = array_merge($version, $verData);
}
#endregion

#region 📤 Response
$response = [
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
    "intervalsArray" => [
        "currentDaySecondsRemaining" => $secondsRemaining,
        "intervalLabel" => $intervalLabel,
        "dayType" => $dayType,
        "workdayIntervals" => [
            "start" => WORKDAY_START,
            "end" => WORKDAY_END
        ]
    ],
    "recordCounts" => $recordCounts,
    "weatherData" => $weatherData,
    "kpiData" => [
        "contacts" => 36,
        "orders" => 22,
        "approvals" => 3
    ],
    "uiHints" => [
        "tips" => [
            "Measure twice, cut once.",
            "Stay positive, work hard, make it happen.",
            "Quality is never an accident.",
            "Efficiency is doing better what is already being done.",
            "Every day is a fresh start."
        ]
    ],
    "siteMeta" => [
        "siteVersion" => $version['siteVersion'],
        "lastDeployNote" => $version['lastDeployNote'],
        "lastDeployTime" => $version['lastDeployTime'],
        "deployState" => $version['deployState'],
        "deployIsLive" => ($version['deployState'] === "published"),
        "cronCount" => $version['cronCount'],
        "streamCount" => 23,
        "aiQueryCount" => $version['aiQueryCount'],
        "uptimeSeconds" => null
    ]
];
#endregion

#region 🟢 Output
echo json_encode($response);
#endregion
