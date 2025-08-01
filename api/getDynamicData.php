<?php
// 📁 File: api/getDynamicData.php
// At the very top of getDynamicData.php (before anything else)
ob_start();

#region 🔧 Init and Headers
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
#endregion

#region 🔐 Load Environment Variables
// Set path to .env for local dev (../secure/.env relative to this script)
$envPath = realpath(__DIR__ . '/../secure/.env');
// If not found, try GoDaddy/legacy path (three directories up)
if (!$envPath || !file_exists($envPath)) {
    // If not found, try GoDaddy/legacy path
    $envPath = realpath(__DIR__ . '/../../../secure/.env'); // GoDaddy/legacy
}
// Initialize empty environment variable array
$env = array();
// Attempt to load environment variables from .env file
if ($envPath && file_exists($envPath)) {
    // Read .env file line by line, ignoring empty lines
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // Loop through each line
    foreach ($lines as $line) {
        // Skip comments (lines starting with #)
        if (strpos(trim($line), '#') === 0) continue;
        // Split line into name and value at the first '=' character
        list($name, $value) = explode('=', $line, 2);
        // Add trimmed name and value to the environment array
        $env[trim($name)] = trim($value);
    }
} else {
    // If .env not found in any expected location, return JSON error and exit
    echo json_encode(array("error" => "Configuration error: .env file not found"));
    // Exit with HTTP 500 Internal Server Error
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
$dataPath = dirname(__FILE__) . '/../assets/data/skyesoft-data.json';
$siteMeta = array();

// If data file exists, load site metadata
if (file_exists($dataPath)) {
    $json = file_get_contents($dataPath);
    $data = json_decode($json, true);
    if (is_array($data) && isset($data['siteMeta'])) {
        $siteMeta = $data['siteMeta'];
    }
}

$codexPath = "../../assets/data/codex.json";
$chatLogPath = "../../assets/data/chatLog.json";
$weatherPath = "../../assets/data/weatherCache.json";
$announcementsPath = "../assets/data/announcements.json";  // 📢 Office announcements and tips
$uiEventPath = "../assets/data/uiEvent.json"; // 🎛️ Real-time UI event (modal, effect, etc.)

define('WORKDAY_START', '07:30');
define('WORKDAY_END', '15:30');
#endregion

#region 🎛️ Dynamic UI Event Creation (Reusable Block)
// Usage: Place this in any script that needs to trigger a UI event.
//        All fields should be set dynamically based on the event context.

// Dynamically set these variables from your application logic, user input, or API call
$eventType     = isset($eventType)      ? $eventType      : (isset($_POST['type'])        ? $_POST['type']        : "modal");
$eventTitle    = isset($eventTitle)     ? $eventTitle     : (isset($_POST['title'])       ? $_POST['title']       : "");
$eventMessage  = isset($eventMessage)   ? $eventMessage   : (isset($_POST['message'])     ? $_POST['message']     : "");
$eventColor    = isset($eventColor)     ? $eventColor     : (isset($_POST['color'])       ? $_POST['color']       : "");
$eventIcon     = isset($eventIcon)      ? $eventIcon      : (isset($_POST['icon'])        ? $_POST['icon']        : "");
$eventDuration = isset($eventDuration)  ? $eventDuration  : (isset($_POST['durationSec']) ? intval($_POST['durationSec']) : 8);
$eventSource   = isset($eventSource)    ? $eventSource    : (isset($_POST['source'])      ? $_POST['source']      : "");

// Optional: For "effect" events
$eventEffect   = isset($eventEffect)    ? $eventEffect    : (isset($_POST['effect'])      ? $_POST['effect']      : "");

// Build the event array dynamically
$event = array(
    "type"        => $eventType,
    "title"       => $eventTitle,
    "message"     => $eventMessage,
    "color"       => $eventColor,
    "icon"        => $eventIcon,
    "durationSec" => $eventDuration,
    "source"      => $eventSource
);

// Add effect property if this is an effect event
if ($eventType === "effect" && $eventEffect !== "") {
    $event["effect"] = $eventEffect;
}

// Write to uiEvent.json, overwriting any previous event
file_put_contents($uiEventPath, json_encode($event, JSON_PRETTY_PRINT));
#endregion

#region 📢 Load Announcements Data (PHP 5.6 Safe)
$announcements = array();
if (file_exists($announcementsPath)) {
    $json = file_get_contents($announcementsPath);
    $announcementsData = json_decode($json, true); // decode as assoc array
    if (is_array($announcementsData) && isset($announcementsData['announcements']) && is_array($announcementsData['announcements'])) {
        $announcements = $announcementsData['announcements'];
    }
}
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

#region 📤 Response

// 🕒 Master response array for SSE/SOT stream
$response = array(
    // 📅 Real-time date and time data (local, unix, calendar info)
    "timeDateArray" => array(
        // ⏱️ Unix timestamp for now (seconds since epoch)
        "currentUnixTime" => $currentUnixTime,
        // 🕗 Local human-readable time string
        "currentLocalTime" => $currentTime,
        // 📆 Current date (YYYY-MM-DD)
        "currentDate" => $currentDate,
        // 📅 Total days in the year (e.g., 365)
        "currentYearTotalDays" => $yearTotalDays,
        // 🗓️ Day number of the year (1–365/366)
        "currentYearDayNumber" => $yearDayNumber,
        // 🔢 Days left in the year
        "currentYearDaysRemaining" => $yearDaysRemaining,
        // 7️⃣ Numeric month (as string, e.g. "7")
        "currentMonthNumber" => strval($monthNumber),
        // 1️⃣ Numeric weekday (as string, 1=Monday, 7=Sunday)
        "currentWeekdayNumber" => strval($weekdayNumber),
        // 📅 Day of the month (as string, e.g. "19")
        "currentDayNumber" => strval($dayNumber),
        // 🕓 Hour of the day (as string, "0"–"23")
        "currentHour" => strval($currentHour),
        // 🌅 Time of day (e.g., "morning", "afternoon")
        "timeOfDayDescription" => $timeOfDayDesc,
        // 🌎 Time zone name (IANA, e.g. "America/Phoenix")
        "timeZone" => $timeZone,
        // 🕑 UTC offset (hours from UTC, e.g. -7)
        "UTCOffset" => $utcOffset,
        // 🌄 Daylight period for today
        "daylightStartEndArray" => array(
            // 🌅 Sunrise (static for now, update with live value later)
            "daylightStart" => "05:27:00",  // 🔧 Replace with real sunrise later
            // 🌇 Sunset (static for now)
            "daylightEnd" => "19:42:00"
        ),
        // 📍 Default geographic coordinates (Phoenix, AZ)
        "defaultLatitudeLongitudeArray" => array(
            // 📏 Latitude for calculations and mapping
            "defaultLatitude" => "33.448376",
            // 📏 Longitude for calculations and mapping
            "defaultLongitude" => "-112.074036",
            // 🌞 Solar zenith angle (default 90.83°)
            "solarZenithAngle" => 90.83,
            // 🕒 Default UTC offset (should match above)
            "defaultUTCOffset" => $utcOffset
        ),
        // 🕛 Unix timestamps for start/end of current day
        "currentDayBeginningEndingUnixTimeArray" => array(
            // 🌅 Midnight start of current day
            "currentDayStartUnixTime" => $currentDayStartUnix,
            // 🌃 End of day (23:59:59)
            "currentDayEndUnixTime" => $currentDayEndUnix
        )
    ),
    // ⏲️ Workday/interval information (seconds, labels, type)
    "intervalsArray" => array(
        // ⌛ Remaining seconds in the current day
        "currentDaySecondsRemaining" => $secondsRemaining,
        // 🔖 Interval label (period within the day, e.g., "1")
        "intervalLabel" => $intervalLabel,
        // 🔢 Workday type/classification (e.g., 1=regular, 2=weekend)
        "dayType" => $dayType,
        // 🕘 Start/end time of workday (from constants)
        "workdayIntervals" => array(
            "start" => WORKDAY_START,
            "end" => WORKDAY_END
        )
    ),
    // 📊 Live record counts (entities, orders, permits, etc.)
    "recordCounts" => $recordCounts,
    // 🌦️ Weather info (current + forecast, pulled from API)
    "weatherData" => $weatherData, // Use cURL-based weather data
    // 📈 Key performance indicators (dashboard stats)
    "kpiData" => array(
        // 👥 Count of contacts in system
        "contacts" => 36,
        // 📦 Active orders
        "orders" => 22,
        // ✅ Approvals pending/completed
        "approvals" => 3
    ),
    // 💡 Motivational and productivity tips for the office board
    "uiHints" => array(
        "tips" => array(
            "Measure twice, cut once.",
            "Stay positive, work hard, make it happen.",
            "Quality is never an accident.",
            "Efficiency is doing better what is already being done.",
            "Every day is a fresh start."
        )
    ),
    // 📢 Announcements and notices (from announcements.json)
    "announcements" => $announcements,
    // 🎛️ UI event (modal popups, effects, etc.; loaded from uiEvent.json; can be null or a single event object)
    "uiEvent" => $event,
    // 🏷️ Meta/versioning information for deployment tracking
    "siteMeta" => array(
        // 🏷️ Current site version (from siteMeta, persistent)
        "siteVersion"    => isset($siteMeta['siteVersion'])    ? $siteMeta['siteVersion']    : "unknown",
        // 📝 Last deploy note/summary
        "lastDeployNote" => isset($siteMeta['lastDeployNote']) ? $siteMeta['lastDeployNote'] : "",
        // 🕐 Timestamp of last deploy
        "lastDeployTime" => isset($siteMeta['lastDeployTime']) ? $siteMeta['lastDeployTime'] : "",
        // 🚦 Deploy state (e.g., "published", "staging")
        "deployState"    => isset($siteMeta['deployState'])    ? $siteMeta['deployState']    : "",
        // ✅ True if site is live/published, else false
        "deployIsLive"   => isset($siteMeta['deployIsLive'])   ? $siteMeta['deployIsLive']   : false,
        // 🔄 Cron job counter for live monitoring
        "cronCount"      => isset($siteMeta['cronCount'])      ? $siteMeta['cronCount']      : 0,
        // 🤖 AI queries processed (running total)
        "aiQueryCount"   => isset($siteMeta['aiQueryCount'])   ? $siteMeta['aiQueryCount']   : 0,
        // ⏳ Uptime in seconds (null if unknown)
        "uptimeSeconds"  => null
    )
);

#endregion

// #region 🟢 Output

$rawJson = json_encode($response);
echo $rawJson;

// Capture everything actually sent to the client (including accidental output)
$actualOutput = ob_get_contents();
file_put_contents(__DIR__ . '/debug-actual-output.txt', $actualOutput);

ob_end_flush();

// #endregion