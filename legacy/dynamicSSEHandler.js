// 📁 File: assets/js/dynamicSSEHandler.js

// Start the browser-local stream count at 0 for this tab/window
window.activeStreams = 0;

//#region 🧮 Format Duration (DD HH MM SS Padded – No leading zero on days)
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
//#endregion

//#region 🌤️ Weather Emoji Helper
function getWeatherEmoji(iconCode) {
  if (!iconCode) return "❓";
  if (iconCode.startsWith("01")) return "☀️";        // Clear sky
  if (iconCode.startsWith("02")) return "🌤️";        // Few clouds
  if (iconCode.startsWith("03")) return "⛅";         // Scattered clouds
  if (iconCode.startsWith("04")) return "☁️";        // Broken clouds
  if (iconCode.startsWith("09") || iconCode.startsWith("10")) return "🌧️"; // Rain
  if (iconCode.startsWith("11")) return "⛈️";        // Thunderstorm
  if (iconCode.startsWith("13")) return "❄️";        // Snow
  if (iconCode.startsWith("50")) return "🌫️";        // Mist
  return "❓";
}
//#endregion

//#region 🔁 Poll Every Second for Dynamic Data
setInterval(() => {
  // 🕒 Increment Active Stream Count
  window.activeStreams++; // Increment by 1 every poll tick  
  // 🗺️ Fetch Dynamic Data
  fetch("/skyesoft/api/getDynamicData.php")
    .then(res => res.json())
    .then(data => {
      window.lastSSEData = data;
      // #region 🧪 Debug Log
      // console.log("🕒 Polled:", data);
      // console.log("🌡️ Weather Snapshot:", data.weatherData);
      // Uid Event Debuggin
      if (
        data.uiEvent &&
        // Optionally: Only show if any meaningful field is set (not just empty defaults)
        (data.uiEvent.title || data.uiEvent.message || data.uiEvent.icon)
      ) {
        // Console Log UI Event
        console.log("🎛️ UI Event received:", data.uiEvent);
      }
      // #endregion

      // #region ⏰ Update Time Display
      if (data?.timeDateArray?.currentLocalTime) {
        const timeEl = document.getElementById("currentTime");
        if (timeEl) timeEl.textContent = data.timeDateArray.currentLocalTime;
      }
      // #endregion

      // #region ⏳ Update Interval Remaining Message (Codex v6 – Interval Engine)
      const intervalData = data?.intervalsArray;

      if (intervalData) {
        const seconds = Number(intervalData.secondsRemainingToInterval);
        const code = Number(intervalData.intervalCode);
        const dayType = intervalData.dayType;

        if (!isNaN(seconds) && !isNaN(code) && dayType) {
          const formatted = formatDurationPadded(seconds);
          let message = "";

          // Holiday / Weekend overrides
          if (dayType === "Company Holiday") {
            message = `🏢 Office closed — next worktime begins in ${formatted}`;
          } else if (dayType === "Weekend") {
            message = `🌴 Weekend — next worktime begins in ${formatted}`;
          } else {
            // Workday logic
            switch (code) {
              case 0:
                message = `🔜 Worktime begins in ${formatted}`;
                break;
              case 1:
                message = `🔚 Worktime ends in ${formatted}`;
                break;
              case 2:
              default:
                message = `📆 Next worktime begins in ${formatted}`;
                break;
            }
          }

          const intervalEl = document.getElementById("intervalRemainingData");
          if (intervalEl) intervalEl.textContent = message;
        }
      }
      // #endregion


      // #region 🏷️ Version Tag
      if (data?.siteMeta?.siteVersion) {
        const versionEl = document.querySelector(".version");
        if (versionEl) {
          versionEl.textContent = `🔖 Skyesoft • Version: ${data.siteMeta.siteVersion}`;
        }
      }
      // #endregion

      // #region 🌦️ Update Weather Display
      if (
        typeof data?.weatherData?.temp === "number" &&
        data.weatherData.description
      ) {
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
//#endregion