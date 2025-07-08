// #region 🚀 Imports & Paths
import fs from "fs";
import path from "path";
import { readFile } from "fs/promises";
import { DateTime } from "luxon";

const holidaysPath = path.resolve(__dirname, "../../assets/data/federal_holidays_dynamic.json");
const dataPath = path.resolve(__dirname, "../../assets/data/skyesoft-data.json");
const versionPath = path.resolve(__dirname, "../../assets/data/version.json");
// #endregion

// #region 🕒 Workday Settings
const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";

function timeStringToSeconds(timeStr) {
  const [h, m] = timeStr.split(":" ).map(Number);
  return h * 3600 + m * 60;
}

function isHoliday(date, holidays) {
  const formatted = date.toISOString().split("T")[0];
  return holidays.some(holiday => holiday.date === formatted);
}

function isWorkday(date, holidays) {
  const day = date.getDay();
  return day !== 0 && day !== 6 && !isHoliday(date, holidays);
}

function findNextWorkdayStart(startDate, holidays) {
  const nextDate = new Date(startDate);
  nextDate.setDate(nextDate.getDate() + 1);
  while (!isWorkday(nextDate, holidays)) {
    nextDate.setDate(nextDate.getDate() + 1);
  }
  nextDate.setHours(7, 30, 0, 0);
  return nextDate;
}
// #endregion

// #region ☁️ Weather Cache (15-minute memory)
let cachedWeather = {
  temp: null,
  icon: "❓",
  description: "Loading...",
  timestamp: 0
};
// #endregion

// #region 📦 Serverless Handler
export const handler = async () => {
  const FIFTEEN_MINUTES = 15 * 60 * 1000;
  const weatherNow = Date.now();

  const weatherData = {
    temp: cachedWeather.temp,
    icon: cachedWeather.icon,
    description: cachedWeather.description
  };

  if (weatherNow - cachedWeather.timestamp > FIFTEEN_MINUTES || cachedWeather.temp === null) {
    (async () => {
      try {
        const res = await fetch(`https://api.openweathermap.org/data/2.5/weather?q=Phoenix,US&appid=${process.env.WEATHER_API_KEY}&units=imperial`);
        if (!res.ok) return console.warn("❌ Weather fetch failed:", res.status);

        const data = await res.json();
        const desc = data.weather[0].main.toLowerCase();

        let icon = "❓";
        if (desc.includes("clear")) icon = "☀️";
        else if (desc.includes("cloud")) icon = "☁️";
        else if (desc.includes("rain")) icon = "🌧️";
        else if (desc.includes("storm")) icon = "⛈️";
        else if (desc.includes("snow")) icon = "❄️";
        else if (desc.includes("fog") || desc.includes("mist")) icon = "🌫️";

        cachedWeather = {
          temp: Math.round(data.main.temp),
          icon,
          description: data.weather[0].description,
          timestamp: weatherNow
        };

        console.log("✅ Weather data refreshed at", new Date(weatherNow).toLocaleTimeString());
      } catch (err) {
        console.error("🔥 Weather background fetch failed:", err.message);
      }
    })();
  }

  const holidaysJSON = await readFile(holidaysPath, "utf-8");
  const holidays = JSON.parse(holidaysJSON).holidays;

  // #region ⏱️ Calculate Time Info
  const now = DateTime.now().setZone("America/Phoenix");
  const nativeNow = new Date(now.toISO());

  const currentUnixTime = Math.floor(now.toSeconds());
  const currentSeconds = now.hour * 3600 + now.minute * 60 + now.second;
  const currentLocalTime = now.toFormat("hh:mm:ss a");
  const currentDate = now.toFormat("yyyy-MM-dd");
  // #endregion

  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  if (!isWorkday(nativeNow, holidays)) {
    dayType = isHoliday(nativeNow, holidays) ? "2" : "1";
    intervalLabel = "1";
  } else {
    dayType = "0";
    intervalLabel = (currentSeconds < workStart || currentSeconds >= workEnd) ? "1" : "0";
  }

  if (intervalLabel === "1") {
    const today = new Date(nativeNow);
    today.setHours(0, 0, 0, 0);
    const nextWorkStart = findNextWorkdayStart(today, holidays);
    secondsRemaining = Math.floor((nextWorkStart.getTime() - nativeNow.getTime()) / 1000);
  } else {
    secondsRemaining = workEnd - currentSeconds;
  }

  let recordCounts = {
    actions: 0,
    entities: 0,
    locations: 0,
    contacts: 0,
    orders: 0,
    permits: 0,
    notes: 0,
    tasks: 0
  };

  try {
    const data = JSON.parse(fs.readFileSync(dataPath, "utf8"));
    for (const type in recordCounts) {
      if (data[type]) recordCounts[type] = data[type].length;
    }
  } catch (err) {
    console.warn("⚠️ Could not read data file:", err.message);
  }

  // #region 📦 Counters & Deployment Info
  let cronCount = 0;
  let aiQueryCount = 0;

  let siteVersion = "unknown";
  let lastDeployNote = "Unavailable";
  let lastDeployTime = null;
  let deployState = "unknown";
  let deployIsLive = false;

  // Load usage counters from version.json
  try {
    const version = JSON.parse(fs.readFileSync(versionPath, "utf8"));
    cronCount = version.cronCount || 0;
    aiQueryCount = version.aiQueryCount || 0;
  } catch (err) {
    console.warn("⚠️ Could not read version counters:", err.message);
  }

  // Load live deploy info from getDeployStatus function
  try {
    const deployRes = await fetch("https://skyesoft-ai.netlify.app/.netlify/functions/getDeployStatus");
    if (deployRes.ok) {
      const deployData = await deployRes.json();
      siteVersion     = deployData.siteVersion     || siteVersion;
      lastDeployNote  = deployData.lastDeployNote  || lastDeployNote;
      lastDeployTime  = deployData.lastDeployTime  || lastDeployTime;
      deployState     = deployData.deployState     || deployState;
      deployIsLive    = deployState === "published";
    } else {
      console.warn("⚠️ Failed to fetch deploy status:", deployRes.status);
    }
  } catch (err) {
    console.error("🔥 Error fetching deploy status:", err.message);
  }
  // #endregion

  return {
    statusCode: 200,
    headers: {
      "Access-Control-Allow-Origin": "*",
      "Access-Control-Allow-Headers": "Content-Type",
    },
    body: JSON.stringify({
      timeDateArray: {
        currentUnixTime,
        currentLocalTime,
        currentDate
      },
      intervalsArray: {
        currentDaySecondsRemaining: secondsRemaining,
        intervalLabel,
        dayType,
        workdayIntervals: {
          start: WORKDAY_START,
          end: WORKDAY_END
        }
      },
      recordCounts,
      weatherData,
      kpiData: {
        contacts: 36,
        orders: 22,
        approvals: 3
      },
      uiHints: {
        tips: [
          "Measure twice, cut once.",
          "Stay positive, work hard, make it happen.",
          "Quality is never an accident.",
          "Efficiency is doing better what is already being done.",
          "Every day is a fresh start.",
          "Take small steps every day toward big goals.",
          "Be Proactive – Take responsibility for your actions.",
          "Begin with the End in Mind – Define clear goals.",
          "Put First Things First – Prioritize what matters most.",
          "Think Win-Win – Seek mutually beneficial solutions.",
          "Seek First to Understand, Then to Be Understood – Practice empathetic listening.",
          "Synergize – Value teamwork and collaboration.",
          "Sharpen the Saw – Invest in continuous personal growth."
        ]
      },
      siteMeta: {
        siteVersion,
        lastDeployNote,
        lastDeployTime,
        deployState,         // 🆕 e.g., "building", "published", etc.
        deployIsLive,        // 🆕 boolean
        cronCount,
        streamCount: 23,
        aiQueryCount,
        uptimeSeconds: null
      }
    })
  };
};
// #endregion
