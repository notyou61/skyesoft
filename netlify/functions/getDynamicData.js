// #region 🚀 Imports & Paths
import fs from "fs";
import path from "path";
import { readFile } from "fs/promises";

const holidaysPath = path.resolve(__dirname, "../../assets/data/federal_holidays_dynamic.json");
const dataPath = path.resolve(__dirname, "../../assets/data/skyesoft-data.json");
const versionPath = path.resolve(__dirname, "../../assets/data/version.json");
// #endregion

// #region 🕒 Workday Settings
const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";

function timeStringToSeconds(timeStr) {
  const [h, m] = timeStr.split(":").map(Number);
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
  
  // #region 🌦️ Add Weather Data (OpenWeatherMap - 15 min cache)
  const FIFTEEN_MINUTES = 15 * 60 * 1000;
  const nowEpoch = Date.now();  // For weather caching
  // Check if cached weather is older than 15 minutes or has no temp
  if (nowEpoch - cachedWeather.timestamp > FIFTEEN_MINUTES || cachedWeather.temp === null) {
    try {
      const res = await fetch(`https://api.openweathermap.org/data/2.5/weather?q=Phoenix,US&appid=${process.env.WEATHER_API_KEY}&units=imperial`);
      if (res.ok) {
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
          timestamp: now
        };
      } else {
        console.warn("❌ Weather fetch failed:", res.status);
      }
    } catch (err) {
      console.error("🔥 Weather fetch error:", err.message);
    }
  }
  // Use cached weather data
  const weatherData = {
    temp: cachedWeather.temp,
    icon: cachedWeather.icon,
    description: cachedWeather.description
  };
  // #endregion
  
  // #region 📅 Load Holiday List
  const holidaysJSON = await readFile(holidaysPath, "utf-8");
  const holidays = JSON.parse(holidaysJSON).holidays;
  // #endregion

  // #region ⏱️ Calculate Time Info
  const now = new Date(new Date().toLocaleString("en-US", { timeZone: "America/Phoenix" }));
  const currentUnixTime = Math.floor(now.getTime() / 1000);
  const currentSeconds = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
  const currentLocalTime = now.toLocaleTimeString("en-US", {
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
    timeZone: "America/Phoenix"
  });
  const currentDate = now.toLocaleDateString("en-CA", { timeZone: "America/Phoenix" });
  // #endregion

  // #region 🧠 Interval & Workday Logic
  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  if (!isWorkday(now, holidays)) {
    dayType = isHoliday(now, holidays) ? "2" : "1";
    intervalLabel = "1";
  } else {
    dayType = "0";
    intervalLabel = (currentSeconds < workStart || currentSeconds >= workEnd) ? "1" : "0";
  }

  if (intervalLabel === "1") {
    const today = new Date(now);
    today.setHours(0, 0, 0, 0);
    const nextWorkStart = findNextWorkdayStart(today, holidays);
    secondsRemaining = Math.floor((nextWorkStart.getTime() - now.getTime()) / 1000);
  } else {
    secondsRemaining = workEnd - currentSeconds;
  }
  // #endregion

  // #region 🧾 Record Counts
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
  // #endregion

  // #region 🧮 Version & Counters
  let cronCount = 0;
  let aiQueryCount = 0;

  try {
    const version = JSON.parse(fs.readFileSync(versionPath, "utf8"));
    cronCount = version.cronCount || 0;
    aiQueryCount = version.aiQueryCount || 0;
  } catch (err) {
    console.warn("⚠️ Could not read version file:", err.message);
  }
  // #endregion

  // #region 📤 JSON Response
  return {
    statusCode: 200,
    headers: {
      "Access-Control-Allow-Origin": "*", // Or restrict to your domain
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
        siteVersion: "v2025.07.06",
        cronCount,
        streamCount: 23,
        aiQueryCount,
        uptimeSeconds: null
      }
    })
  };
  // #endregion
};
// #endregion
