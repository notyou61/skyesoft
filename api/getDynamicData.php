<?php
// 📁 File: api/getDynamicData.php

#region ➤ Headers & Timezone
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
date_default_timezone_set("America/Phoenix");
#endregion

#region ➤ File Paths
$holidaysPath = "../../assets/data/federal_holidays_dynamic.json";
$dataPath = "../../assets/data/skyesoft-data.json";
$versionPath = "../../assets/data/version.json";
#endregion

#region ➤ Workday Constants
define('WORKDAY_START', '07:30');
define('WORKDAY_END', '15:30');
#endregion

#region ➤ Utility Functions
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

#region ➤ Weather (static fallback for now)
$weatherData = array(
    "temp" => null,
    "icon" => "❓",
    "description" => "Loading..."
);

$weatherApiKey = getenv("WEATHER_API_KEY");
$weatherUrl = "https://api.openweathermap.org/data/2.5/weather?q=Phoenix,US&appid=$weatherApiKey&units=imperial";
$weatherJson = @file_get_contents($weatherUrl);
if ($weatherJson) {
    $w = json_decode($weatherJson, true);
    $desc = strtolower($w['weather'][0]['main']);
    $icon = "❓";
    if (strpos($desc, "clear") !== false) $icon = "☀️";
    elseif (strpos($desc, "cloud") !== false) $icon = "☁️";
    elseif (strpos($desc, "rain") !== false) $icon = "🌧️";
    elseif (strpos($desc, "storm") !== false) $icon = "⛈️";
    elseif (strpos($desc, "snow") !== false) $icon = "❄️";
    elseif (strpos($desc, "fog") !== false || strpos($desc, "mist") !== false) $icon = "🌫️";

    $weatherData = array(
        "temp" => round($w['main']['temp']),
        "icon" => $icon,
        "description" => $w['weather'][0]['description']
    );
}
#endregion

#region ➤ Load Holidays
$holidays = array();
if (file_exists($holidaysPath)) {
    $holidaysData = json_decode(file_get_contents($holidaysPath), true);
    $holidays = $holidaysData['holidays'];
}
#endregion

#region ➤ Time Info & Intervals
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
    if ($isWorkday && $currentSeconds < $workStart) {
        $nextStart = strtotime($currentDate . " " . WORKDAY_START);
    } else {
        $nextStart = findNextWorkdayStart($currentDate, $holidays);
    }
    $secondsRemaining = $nextStart - $now;
} else {
    $secondsRemaining = $workEnd - $currentSeconds;
}
#endregion

#region ➤ Record Counts
$recordCounts = array(
    "actions" => 0,
    "entities" => 0,
    "locations" => 0,
    "contacts" => 0,
    "orders" => 0,
    "permits" => 0,
    "notes" => 0,
    "tasks" => 0
);

if (file_exists($dataPath)) {
    $data = json_decode(file_get_contents($dataPath), true);
    foreach ($recordCounts as $key => $val) {
        if (isset($data[$key])) {
            $recordCounts[$key] = count($data[$key]);
        }
    }
}
#endregion

#region ➤ Deployment Metadata
$version = array(
    "cronCount" => 0,
    "aiQueryCount" => 0,
    "siteVersion" => "unknown",
    "lastDeployNote" => "Unavailable",
    "lastDeployTime" => null,
    "deployState" => "unknown",
    "deployIsLive" => false
);

if (file_exists($versionPath)) {
    $verData = json_decode(file_get_contents($versionPath), true);
    $version = array_merge($version, $verData);
}
#endregion

#region ➤ Final Output
echo json_encode(array(
    "timeDateArray" => array(
        "currentUnixTime" => $currentUnixTime,
        "currentLocalTime" => $currentTime,
        "currentDate" => $currentDate
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
    "weatherData" => $weatherData,
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
));
#endregion