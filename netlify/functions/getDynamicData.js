// 📁 File: netlify/functions/getDynamicData.js

// #region 📁 File: netlify/functions/getDynamicData.js
import fs from "fs";
import path from "path";

const __dirname = path.dirname(new URL(import.meta.url).pathname);
const holidaysPath = path.join(__dirname, "federal_holidays_dynamic.json");
const holidays = JSON.parse(fs.readFileSync(holidaysPath, "utf8"));
// #endregion


// #region 🔧 Workday Configuration
const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";
// #endregion

// #region 🧮 Utility: Convert "HH:MM" to seconds since midnight
function timeStringToSeconds(timeStr) {
  const [h, m] = timeStr.split(":").map(Number);
  return h * 3600 + m * 60;
}
// #endregion

// #region 📅 Holiday & Workday Logic
function isHoliday(dateObj) {
  const dateStr = dateObj.toISOString().slice(0, 10);
  return holidays.some(holiday => holiday.date === dateStr);
}

function isWeekend(dateObj) {
  const day = dateObj.getDay();
  return day === 0 || day === 6; // Sunday = 0, Saturday = 6
}

function isWorkday(dateObj) {
  return !isHoliday(dateObj) && !isWeekend(dateObj);
}
// #endregion

// #region ⏩ Find Next Workday Start
function findNextWorkdayStart(now) {
  const next = new Date(now);
  while (!isWorkday(next)) {
    next.setDate(next.getDate() + 1);
  }
  const [h, m] = WORKDAY_START.split(":");
  next.setHours(+h, +m, 0, 0);
  return next;
}
// #endregion

// #region 🚀 Main Handler
export const handler = async () => {
  const now = new Date();
  const currentUnixTime = Math.floor(now.getTime() / 1000);
  const currentSeconds = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();

  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  // #region 📆 Determine Day Type and Interval
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
    // Weekend, Holiday, or After Worktime
    const nextWorkStart = findNextWorkdayStart(now);
    secondsRemaining = Math.floor((nextWorkStart.getTime() - now.getTime()) / 1000);
  }
  // #endregion

  // #region 📦 API Response
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
        siteName: "v2025.07.02"
      }
    })
  };
  // #endregion
};
// #endregion
