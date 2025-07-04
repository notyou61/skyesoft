// 📁 File: netlify/functions/getDynamicData.js

// #region 🔧 Configuration
const WORKDAY_START = "07:30";
const WORKDAY_END = "15:30";
const HOLIDAY_URL = "https://skyesoft-ai.netlify.app/assets/data/federal_holidays_dynamic.json";
// #endregion

// #region 🧮 Convert HH:MM string to seconds since midnight
function timeStringToSeconds(timeStr) {
  const [h, m] = timeStr.split(":".padStart(2, "0")).map(Number);
  return h * 3600 + m * 60;
}
// #endregion

// #region 📅 Check if a given date is a holiday or weekend
function isHoliday(dateObj, holidays) {
  const dateStr = dateObj.toISOString().slice(0, 10);
  return holidays.some((holiday) => holiday.date === dateStr);
}
function isWeekend(dateObj) {
  const day = dateObj.getDay();
  return day === 0 || day === 6;
}
function isWorkday(dateObj, holidays) {
  return !isWeekend(dateObj) && !isHoliday(dateObj, holidays);
}
// #endregion

// #region ⏳ Find next workday start datetime
function findNextWorkdayStart(now, holidays) {
  const next = new Date(now);
  while (!isWorkday(next, holidays)) {
    next.setDate(next.getDate() + 1);
  }
  const [h, m] = WORKDAY_START.split(":");
  next.setHours(+h, +m, 0, 0);
  return next;
}
// #endregion

// #region 🚀 Main API Handler
export const handler = async () => {
  // #region 📅 Fetch Holidays from Public URL
  let holidays = [];
  try {
    const res = await fetch(HOLIDAY_URL);
    holidays = await res.json();
  } catch (err) {
    console.error("❌ Failed to fetch holidays:", err.message);
  }
  // #endregion

  const now = new Date();
  const currentUnixTime = Math.floor(now.getTime() / 1000);
  const currentSeconds = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
  const workStart = timeStringToSeconds(WORKDAY_START);
  const workEnd = timeStringToSeconds(WORKDAY_END);

  let intervalLabel = "";
  let dayType = "";
  let secondsRemaining = 0;

  if (isHoliday(now, holidays)) {
    dayType = "Holiday";
    intervalLabel = "Holiday";
  } else if (isWeekend(now)) {
    dayType = "Weekend";
    intervalLabel = "Weekend";
  } else {
    dayType = "Workday";
    if (currentSeconds < workStart) intervalLabel = "Before Worktime";
    else if (currentSeconds < workEnd) intervalLabel = "Worktime";
    else intervalLabel = "After Worktime";
  }

  if (intervalLabel === "Before Worktime") {
    secondsRemaining = workStart - currentSeconds;
  } else if (intervalLabel === "Worktime") {
    secondsRemaining = workEnd - currentSeconds;
  } else {
    const nextWorkStart = findNextWorkdayStart(now, holidays);
    secondsRemaining = Math.floor((nextWorkStart.getTime() - now.getTime()) / 1000);
  }

  return {
    statusCode: 200,
    body: JSON.stringify({
      timeDateArray: { currentUnixTime },
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
};
// #endregion
