// 📁 File: assets/js/workdayTicker.js

// #region 🧮 Format Duration (DD HH MM SS Padded)
function formatDurationPadded(seconds) {
  const d = Math.floor(seconds / 86400);
  const h = Math.floor((seconds % 86400) / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;

  const daysPart = d > 0 ? `${String(d).padStart(2, '0')}d ` : '';
  const hoursPart = `${String(h).padStart(2, '0')}h`;
  const minutesPart = `${String(m).padStart(2, '0')}m`;
  const secondsPart = `${String(s).padStart(2, '0')}s`;

  return `${daysPart}${hoursPart} ${minutesPart} ${secondsPart}`.trim();
}
// #endregion

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
      console.log("🕒 Polled:", data); // 🧪 Debug log

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
      // Ensure all required data is available before formatting
      if (seconds !== undefined && label !== undefined && dayType !== undefined) {
        const formatted = formatDurationPadded(seconds);
        let message = "";
        // Determine message based on dayType and label
        switch (`${dayType}-${label}`) {
          case "0-0": message = `🔚 Workday ends in ${formatted}`; break;     // During worktime
          case "0-1": message = `🔜 Workday begins in ${formatted}`; break;   // Before work
          case "0-2":
          case "2-1":
          case "1-1":
          default:   message = `📆 Next workday begins in ${formatted}`; break; // After Worktime / Holiday / Weekend
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
    })
    // #region ❌ Handle Fetch Errors
    .catch(err => {
      console.error("❌ Polling Error:", err);
    });
  // #endregion
}, 1000);
// #endregion