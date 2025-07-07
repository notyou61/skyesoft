// 📁 File: netlify/functions/getDynamicData.js

// #region 📦 Imports and Config
import { readFile } from "fs/promises";
import fs from "fs";
import path from "path";

const holidaysPath = path.resolve("assets/data/federal_holidays_dynamic.json");
const dataPath = path.resolve("assets/data/skyesoft-data.json");
const versionPath = path.resolve("assets/data/version.json");

const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";
// #endregion

// #region 🔧 Helper Functions
function timeStringToSeconds(timeStr) {
  const [h, m] = timeStr.split(":" ).map(Number);
  return h * 3600 + m * 60;
}

function isHoliday(dateObj, holidays) {
  const dateStr = dateObj.toISOString().slice(0, 10);
  return holidays.some(h => h.date === dateStr);
}

function isWeekend(dateObj) {
  const day = dateObj.getDay();
  return day === 0 || day === 6;
}

function isWorkday(dateObj, holidays) {
  return !isHoliday(dateObj, holidays) && !isWeekend(dateObj);
}

function findNextWorkdayStart(fromDate, holidays) {
  const next = new Date(fromDate);
  next.setHours(0, 0, 0, 0);
  while (!isWorkday(next, holidays)) {
    next.setDate(next.getDate() + 1);
  }
  const [h, m] = WORKDAY_START.split(":" );
  next.setHours(+h, +m, 0, 0);
  return next;
}
// #endregion

// #region 🚀 Serverless Function Handler
export const handler = async () => {
  const holidaysJSON = await readFile(holidaysPath, "utf-8");
  const holidays = JSON.parse(holidaysJSON).holidays;

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
  const currentDate = now.toLocaleDateString("en-US", { timeZone: "America/Phoenix" }).replace(/\//g, "-");

  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  if (!isWorkday(now, holidays)) {
    dayType = isHoliday(now, holidays) ? "2" : "1"; // 2 = Holiday, 1 = Weekend
    intervalLabel = "1"; // Non Worktime
  } else {
    dayType = "0"; // Workday
    if (currentSeconds < workStart) {
      intervalLabel = "1"; // Non Worktime
    } else if (currentSeconds < workEnd) {
      intervalLabel = "0"; // Worktime
    } else {
      intervalLabel = "1"; // Non Worktime
    }
  }

  if (intervalLabel === "1") {
    const today = new Date(now);
    today.setHours(0, 0, 0, 0);
    const nextWorkStart = findNextWorkdayStart(today, holidays);
    secondsRemaining = Math.floor((nextWorkStart.getTime() - now.getTime()) / 1000);
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

  let cronCount = 0;
  let aiQueryCount = 0;

  try {
    const data = JSON.parse(fs.readFileSync(dataPath, "utf8"));
    for (const type in recordCounts) {
      if (data[type]) recordCounts[type] = data[type].length;
    }
  } catch (err) {
    console.warn("⚠️ Could not read data file:", err.message);
  }

  try {
    const version = JSON.parse(fs.readFileSync(versionPath, "utf8"));
    cronCount = version.cronCount || 0;
    aiQueryCount = version.aiQueryCount || 0;
  } catch (err) {
    console.warn("⚠️ Could not read version file:", err.message);
  }

  return {
    statusCode: 200,
    body: JSON.stringify({
      timeDateArray: {
        currentUnixTime,
        currentLocalTime,
        currentDate
      },
      intervalsArray: {
        currentDaySecondsRemaining: secondsRemaining,
        intervalLabel,
        dayType
      },
      recordCounts,
      siteMeta: {
        siteVersion: "v2025.07.06",
        cronCount,
        streamCount: 23,
        aiQueryCount,
        uptimeSeconds: null
      }
    })
  };
};
// #endregion
