// 📁 File: assets/js/workdayTicker.js

let lastUiEventId = null; // Place this at top-level

// Dynamically inject Skye Alert Modal if not present
if (!document.getElementById('skyeAlertModal')) {
  const modal = document.createElement('div');
  modal.id = 'skyeAlertModal';
  modal.style.display = 'none';
  modal.innerHTML = `
    <div id="skyeAlertModalContent">
      <div id="skyeAlertModalHeader"></div>
      <div id="skyeAlertModalBody"></div>
      <div id="skyeAlertModalFooter"></div>
      <button onclick="hideSkyeAlertModal()" id="skyeAlertModalClose" aria-label="Close Modal">&times;</button>
    </div>
  `;
  document.body.appendChild(modal);
}


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
  fetch("/skyesoft/api/getDynamicData.php")
    .then(res => res.json())
    .then(data => {
      // #region 🧪 Debug Log
      // console.log("🕒 Polled:", data);
      // console.log("🌡️ Weather Snapshot:", data.weatherData);
      // Debug: Log uiEvent to the console every poll
      console.log('uiEvent in polling:', data.uiEvent);
      // User Interface Event Conditional
      if (data && data.uiEvent && (data.uiEvent.title || data.uiEvent.message || data.uiEvent.icon)) {
        // Use id if present, otherwise time
        let eventId = data.uiEvent.id ?? data.uiEvent.time;
        if (eventId && eventId !== lastUiEventId) {
          lastUiEventId = eventId;
          showSkyeAlertModal(data.uiEvent);
        }
      }
      // #endregion

      // #region ⏰ Update Time Display
      if (data?.timeDateArray?.currentLocalTime) {
        const timeEl = document.getElementById("currentTime");
        if (timeEl) timeEl.textContent = data.timeDateArray.currentLocalTime;
      }
      // #endregion

      // #region ⏳ Update Interval Remaining Message
      const seconds = data?.intervalsArray?.currentDaySecondsRemaining;
      const label = data?.intervalsArray?.intervalLabel;
      const dayType = data?.intervalsArray?.dayType;
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
        // Optional debug:
        // console.log("⏳ Interval Remaining:", message);
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