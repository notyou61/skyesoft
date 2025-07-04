import fs from 'fs';
import path from 'path';

export const handler = async () => {
  const now = new Date();
  const currentUnixTime = Math.floor(now.getTime() / 1000);

  // Read holidays
  const filePath = path.resolve('assets/data/federal_holidays_dynamic.json');
  const raw = fs.readFileSync(filePath);
  const holidays = JSON.parse(raw).holidays;

  // Determine if today is a holiday
  const todayStr = now.toISOString().split("T")[0];
  const isHoliday = holidays.some(holiday => holiday.date === todayStr);

  // Determine if weekend
  const isWeekend = now.getDay() === 0 || now.getDay() === 6;

  // Get hours and seconds since midnight
  const currentSeconds = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();

  // Workday interval config
  const workStart = 7 * 3600 + 30 * 60;  // 7:30 AM
  const workEnd = 15 * 3600 + 30 * 60;   // 3:30 PM

  // Determine interval label and seconds remaining
  let intervalLabel = "Off-hours";
  let dayType = "Workday";
  let remainingSeconds = 0;

  if (isHoliday) {
    dayType = "Holiday";
    intervalLabel = "Holiday";
    remainingSeconds = 86400 - currentSeconds;
  } else if (isWeekend) {
    dayType = "Weekend";
    intervalLabel = "Weekend";
    remainingSeconds = 86400 - currentSeconds;
  } else {
    if (currentSeconds < workStart) {
      intervalLabel = "Before Worktime";
      remainingSeconds = workStart - currentSeconds;
    } else if (currentSeconds < workEnd) {
      intervalLabel = "Worktime";
      remainingSeconds = workEnd - currentSeconds;
    } else {
      intervalLabel = "After Worktime";
      remainingSeconds = 86400 - currentSeconds;
    }
  }

  return {
    statusCode: 200,
    body: JSON.stringify({
      timeDateArray: { currentUnixTime },
      intervalsArray: {
        currentDayDurationsArray: {
          currentDaySecondsRemaining: remainingSeconds
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
