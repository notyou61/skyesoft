export const handler = async () => {
  const now = new Date();
  const currentUnixTime = Math.floor(now.getTime() / 1000);
  const timeString = now.toLocaleTimeString();

  return {
    statusCode: 200,
    body: JSON.stringify({
      timeDateArray: {
        currentUnixTime
      },
      intervalsArray: {
        currentDayDurationsArray: {
          currentDaySecondsRemaining: 86400 - (now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds())
        }
      },
      siteDetailsArray: {
        siteName: "v2025.07.02"
      }
    })
  };
};
