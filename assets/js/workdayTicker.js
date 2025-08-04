// 📁 File: assets/js/workdayTicker.js

let lastUiEventId = null; // Place this at top-level

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

      // In polling .then():
      if (data && data.uiEvent && (data.uiEvent.title || data.uiEvent.message || data.uiEvent.icon)) {
        // Simple ID or timestamp logic (customize for your backend)
        if (data.uiEvent.id && data.uiEvent.id !== lastUiEventId) {
          lastUiEventId = data.uiEvent.id;
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

let skyeAlertModalTimeout = null;

function showSkyeAlertModal(event) {
  // Set header (icon + title)
  const header = document.getElementById("skyeAlertModalHeader");
  header.innerHTML = `${event.icon ? event.icon : ""} ${event.title ? event.title : ""}`;

  // Set body (main message)
  const body = document.getElementById("skyeAlertModalBody");
  body.textContent = event.message || "";

  // Set footer (optional: user/time/source)
  const footer = document.getElementById("skyeAlertModalFooter");
  const user = event.user ? `User: ${event.user}` : "";
  const time = event.time
    ? `Time: ${new Date(event.time * 1000).toLocaleTimeString('en-US', { timeZone: 'America/Phoenix' })}`
    : "";
  const source = event.source ? `Source: ${event.source}` : "";
  footer.textContent = [user, time, source].filter(Boolean).join(" • ");

  // Set modal background color if present
  const modalContent = document.getElementById("skyeAlertModalContent");
  if (event.color) modalContent.style.borderTop = `5px solid ${event.color}`;
  else modalContent.style.borderTop = "";

  // Show modal
  document.getElementById("skyeAlertModal").style.display = "flex";
  document.getElementById("skyeAlertModal").style.opacity = "1";

  // Auto-hide after durationSec (default: 8s)
  clearTimeout(skyeAlertModalTimeout);
  const duration = (event.durationSec && !isNaN(event.durationSec))
    ? parseInt(event.durationSec) * 1000
    : 8000;
  skyeAlertModalTimeout = setTimeout(hideSkyeAlertModal, duration);
}

function hideSkyeAlertModal() {
  const modal = document.getElementById("skyeAlertModal");
  modal.style.opacity = "0";
  setTimeout(() => { modal.style.display = "none"; }, 400); // matches CSS transition
}