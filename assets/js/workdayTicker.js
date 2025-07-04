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
// #region 🔁 Poll Every Second for Dynamic Data
setInterval(() => {
  //fetch("/.netlify/functions/getDynamicData")
  fetch("https://skyesoft-ai.netlify.app/.netlify/functions/getDynamicData")
    .then((res) => res.json())
    .then((data) => {
        // 🧪 Debug log to see the raw data received
        console.log("🕒 Polled:", data); // 🧪 Debug log
        // #region 🌐 Update Current Time
        if (data.timeDateArray?.currentUnixTime) {
            glbVar.timeDate.now = new Date(data.timeDateArray.currentUnixTime * 1000);
            updateDOMFromGlbVar(); // ✅ This updates the clock and anything else using glbVar
        }
        // #endregion
        // #region ⏳ Update Interval Remaining
        if (
        data.intervalsArray?.currentDayDurationsArray?.currentDaySecondsRemaining !== undefined &&
        data.intervalsArray?.currentIntervalTypeArray?.intervalLabel &&
        data.intervalsArray?.currentIntervalTypeArray?.dayType
        ) {
        const seconds = data.intervalsArray.currentDayDurationsArray.currentDaySecondsRemaining;
        const label = data.intervalsArray.currentIntervalTypeArray.intervalLabel;
        const dayType = data.intervalsArray.currentIntervalTypeArray.dayType;

        const formatted = formatDurationPadded(seconds);
        let message = "";

        if (dayType === "Workday") {
            if (label === "Before Worktime") {
            message = `🕔 Work begins in ${formatted}`;
            } else if (label === "Worktime") {
            message = `🔚 Workday ends in ${formatted}`;
            } else {
            message = `📆 Next workday begins in ${formatted}`;
            }
        } else {
            message = `📅 Next workday begins in ${formatted}`;
        }

        const intervalEl = document.getElementById("intervalRemainingData");
        if (intervalEl) intervalEl.textContent = message;
        }
        // #endregion
    // #region 🏷️ Update Site Version
      if (data.siteDetailsArray?.siteName) {
        const versionEl = document.querySelector(".version");
        if (versionEl) versionEl.textContent = `🔖 Skyesoft • Version: ${data.siteDetailsArray.siteName}`;
      }
      // #endregion
    })
    .catch((err) => {
      // #region ❌ Handle Polling Errors
      console.error("❌ Polling Error:", err);
      // #endregion
    });
}, 1000);
// #endregion
