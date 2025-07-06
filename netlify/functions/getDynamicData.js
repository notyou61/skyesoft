// 📁 File: netlify/functions/getDynamicData.js

// #region 📦 Imports and Config
import { readFile } from "fs/promises";
// #region ✅ Safe for Netlify functions
import path from "path";
const holidaysPath = path.resolve("assets/data/federal_holidays_dynamic.json");

const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";
// #endregion

// #region 🔧 Helper Functions
function timeStringToSeconds(timeStr) {
  const [h, m] = timeStr.split(":").map(Number);
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
  const [h, m] = WORKDAY_START.split(":");
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

  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  if (!isWorkday(now, holidays)) {
    dayType = isHoliday(now, holidays) ? "Holiday" : "Weekend";
    intervalLabel = "After Worktime";
  } else {
    dayType = "Workday";
    if (currentSeconds < workStart) {
      intervalLabel = "Before Worktime";
    } else if (currentSeconds < workEnd) {
      intervalLabel = "Worktime";
    } else {
      intervalLabel = "After Worktime";
    }
  }

  if (intervalLabel === "Before Worktime") {
    secondsRemaining = workStart - currentSeconds;
  } else if (intervalLabel === "Worktime") {
    secondsRemaining = workEnd - currentSeconds;
  } else {
    const today = new Date(now);
    today.setHours(0, 0, 0, 0);
    const nextWorkStart = findNextWorkdayStart(today, holidays);
    secondsRemaining = Math.floor((nextWorkStart.getTime() - now.getTime()) / 1000);
  }

  return {
    statusCode: 200,
    body: JSON.stringify({
      timeDateArray: {
        currentUnixTime
      },
      intervalsArray: {
        currentDayDurationsArray: {
          currentDaySecondsRemaining: secondsRemaining
        },
        currentIntervalTypeArray: {
          intervalLabel,
          dayType
        }
      },
      siteDetailsArray: {
        siteName: "v2025.07.06"
      }
    })
  };
};
// #endregion