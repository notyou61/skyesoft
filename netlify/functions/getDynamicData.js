export const handler = async () => {
  const now = new Date();
  const currentUnixTime = Math.floor(now.getTime() / 1000);
  const secondsSinceMidnight = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
  const currentDaySecondsRemaining = 86400 - secondsSinceMidnight;

  return {
    statusCode: 200,
    body: JSON.stringify({
      timeDateArray: {
        currentUnixTime
      },
      intervalsArray: {
        currentDayDurationsArray: {
          currentDaySecondsRemaining
        },
        currentIntervalTypeArray: {
          intervalLabel: "After Worktime", // or anything valid: "Before Worktime", "Worktime"
          dayType: "Holiday"               // ← 🔒 Hardcoded for test
        }
      },
      siteDetailsArray: {
        siteName: "v2025.07.02"
      }
    })
  };
};
