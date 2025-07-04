// 📁 File: netlify/functions/getDynamicData.js

// 📁 File: netlify/functions/getDynamicData.js
import { readFile } from "fs/promises";
import { fileURLToPath } from "url";
import path from "path";

// ✅ ESM-compatible path setup
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// ✅ Properly resolve path to JSON file
const holidaysPath = path.join(__dirname, "federal_holidays_dynamic.json");

// 🔁 Read and parse holiday data
const holidaysJSON = await readFile(holidaysPath, "utf-8");
const holidays = JSON.parse(holidaysJSON).holidays;

// Workday start/end time config
const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";

function timeStringToSeconds(timeStr) {
  const [h, m] = timeStr.split(":").map(Number);
  return h * 3600 + m * 60;
}

function isHoliday(dateObj) {
  const dateStr = dateObj.toISOString().slice(0, 10);
  return holidays.some(h => h.date === dateStr);
}

function isWeekend(dateObj) {
  const day = dateObj.getDay();
  return day === 0 || day === 6;
}

function isWorkday(dateObj) {
  return !isHoliday(dateObj) && !isWeekend(dateObj);
}

function findNextWorkdayStart(now) {
  const next = new Date(now);
  while (!isWorkday(next)) {
    next.setDate(next.getDate() + 1);
  }
  const [h, m] = WORKDAY_START.split(":");
  next.setHours(+h, +m, 0, 0);
  return next;
}

export const handler = async () => {
  const now = new Date();
  const currentUnixTime = Math.floor(now.getTime() / 1000);
  const currentSeconds = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();

  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  if (isHoliday(now)) {
    dayType = "Holiday";
    intervalLabel = "Holiday";
  } else if (isWeekend(now)) {
    dayType = "Weekend";
    intervalLabel = "Weekend";
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
    const nextWorkStart = findNextWorkdayStart(now);
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
        siteName: "v2025.07.04"
      }
    })
  };
};

