// 📁 File: assets/js/workdayTicker.js

// #region 🧮 Format Duration (DD HH MM SS Padded – No leading zero on days)
function formatDurationPadded(seconds) {
  const d = Math.floor(seconds / 86400);
  const h = Math.floor((seconds % 86400) / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;

  const dayPart = d > 0 ? `${d}d ` : "";  // No leading zero
  const hourPart = `${String(h).padStart(2, '0')}h`;
  const minutePart = `${String(m).padStart(2, '0')}m`;
  const secondPart = `${String(s).padStart(2, '0')}s`;

  return `${dayPart}${hourPart} ${minutePart} ${secondPart}`.trim();
}
// #endregion

// #region 🔁 Poll Every Second for Dynamic Data
setInterval(() => {
  fetch("/skyesoft/api/getDynamicData.php")
    .then(res => res.json())
    .then(data => {
      // #region 🧪 Debug Log
      console.log("🕒 Polled:", data);
      console.log("🌡️ Weather Snapshot:", {
        temp: data.weatherData?.temp,
        description: data.weatherData?.description,
        icon: data.weatherData?.icon
      });
      // #endregion

      // #region ⏰ Update Time Display
      if (data.timeDateArray?.currentLocalTime) {
        const timeEl = document.getElementById("currentTime");
        if (timeEl) timeEl.textContent = data.timeDateArray.currentLocalTime;
      }
      // #endregion

      // #region ⏳ Update Interval Remaining Message
      const seconds = data.intervalsArray?.currentDaySecondsRemaining;
      const label = data.intervalsArray?.intervalLabel;
      const dayType = data.intervalsArray?.dayType;
      if (seconds !== undefined && label !== undefined && dayType !== undefined) {
        const formatted = formatDurationPadded(seconds);
        let message = "";
        switch (`${dayType}-${label}`) {
          case "0-0": message = `🔚 Workday ends in ${formatted}`; break;
          case "0-1": message = `🔜 Workday begins in ${formatted}`; break;
          default:    message = `📆 Next workday begins in ${formatted}`; break;
        }

        const intervalEl = document.getElementById("intervalRemainingData");
        if (intervalEl) intervalEl.textContent = message;
        console.log("⏳ Interval Remaining:", message);
      }
      // #endregion

      // #region 🏷️ Version Tag
      if (data.siteMeta?.siteVersion) {
        const versionEl = document.querySelector(".version");
        if (versionEl) {
          versionEl.textContent = `🔖 Skyesoft • Version: ${data.siteMeta.siteVersion}`;
        }
      }
      // #endregion

      // #region 🌦️ Update Weather Display
      if (data.weatherData?.temp !== null && data.weatherData?.description) {
        const tempEl = document.getElementById("weatherTemp");
        const descEl = document.getElementById("weatherDesc");
        const iconEl = document.getElementById("weatherIcon");

        if (tempEl) tempEl.textContent = `${Math.round(data.weatherData.temp)}°F`;
        if (descEl) descEl.textContent = data.weatherData.description;
        if (iconEl) iconEl.textContent = getWeatherEmoji(data.weatherData.icon);
      }
      // #endregion
    })
    // #region ❌ Handle Fetch Errors
    .catch(err => {
      console.error("❌ Polling Error:", err);
    });
    // #endregion
}, 1000);
// #endregion

// #region 🌤️ Emoji Helper
function getWeatherEmoji(iconCode) {
  if (!iconCode) return "❓";
  if (iconCode.startsWith("01")) return "☀️";
  if (iconCode.startsWith("02")) return "🌤️";
  if (iconCode.startsWith("03")) return "⛅";
  if (iconCode.startsWith("04")) return "☁️";
  if (iconCode.startsWith("09") || iconCode.startsWith("10")) return "🌧️";
  if (iconCode.startsWith("11")) return "⛈️";
  if (iconCode.startsWith("13")) return "❄️";
  if (iconCode.startsWith("50")) return "🌫️";
  return "❓";
}
// #endregion
