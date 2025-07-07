// #region 🚀 Serverless Function Handler
const fs = require("fs");
const path = require("path");
const { readFile } = require("fs/promises");

const holidaysPath = path.resolve(__dirname, "../../assets/data/federal_holidays_dynamic.json");
const dataPath = path.resolve(__dirname, "../../assets/data/skyesoft-data.json");
const versionPath = path.resolve(__dirname, "../../assets/data/version.json");

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
  nextDate.setHours(7, 30, 0, 0); // Start of next workday
  return nextDate;
}

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
  const currentDate = now.toLocaleDateString("en-CA", { timeZone: "America/Phoenix" }); // yyyy-mm-dd

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
        dayType,
        workdayIntervals: {
          start: WORKDAY_START,
          end: WORKDAY_END
        }
      },
      recordCounts,
      weatherData: {
        temp: null,
        icon: "❓",
        description: "Loading..."
      },
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
};
// #endregion
