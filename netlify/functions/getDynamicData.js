// 📁 File: netlify/functions/getDynamicData.js
import { readFile } from "fs/promises";
import path from "path";

// ✅ Bulletproof path resolution
const holidaysPath = path.join(process.cwd(), "netlify/functions/federal_holidays_dynamic.json");

// ⏰ Workday hours
const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";

// 🔧 Convert "HH:MM" to seconds since midnight
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

// 🧠 Always start search from 00:00
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

export const handler = async () => {
  const holidaysJSON = await readFile(holidaysPath, "utf-8");
  const holidays = JSON.parse(holidaysJSON).holidays;

  const now = new Date();
  const currentUnixTime = Math.floor(now.getTime() / 1000);
  const currentSeconds = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();

  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  // ✅ Force stream to jump to next valid workday if today is not one
  if (!isWorkday(now, holidays)) {
    dayType = isHoliday(now, holidays) ? "Holiday" : "Weekend";
    intervalLabel = "After Worktime"; // Force forward search
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

  // ⏱️ Calculate time until transition
  if (intervalLabel === "Before Worktime") {
    secondsRemaining = workStart - currentSeconds;
  } else if (intervalLabel === "Worktime") {
    secondsRemaining = workEnd - currentSeconds;
  } else {
    // 🔁 Always begin from tomorrow at 00:00
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(0, 0, 0, 0);

    const nextWorkStart = findNextWorkdayStart(tomorrow, holidays);
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
