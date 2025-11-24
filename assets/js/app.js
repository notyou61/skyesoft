/* ============================================================
   #region GLOBALS
============================================================ */
let serverData = null;
let serverTimeOffset = 0;
let cardIndex = 0;
/* #endregion */

/* ============================================================
   #region VERSION FOOTER
============================================================ */
function updateVersionFooterFromSSE(d) {
  try {
    if (!d.versions) {
      safeSet("versionFooter", "Version info unavailable");
      return;
    }

    const v = d.versions;
    const vb = v.modules.officeBoard.version;
    const vp = v.modules.activePermits.version;
    const cx = v.codex.version;

    safeSet("versionFooter", `OfficeBoard ${vb} • ActivePermits ${vp} • Codex ${cx}`);
  } catch {
    safeSet("versionFooter", "Version info unavailable");
  }
}
/* #endregion */

/* ============================================================
   #region LIVE DATA LOADER
============================================================ */
function loadLiveData() {
  fetch("/skyesoft/api/getDynamicData.php")
    .then(r => r.json())
    .then(d => {
      serverData = d;

      updateVersionFooterFromSSE(d);

      // TIME
      if (d?.timeDateArray?.currentUnixTime) {
        serverTimeOffset = (d.timeDateArray.currentUnixTime * 1000) - Date.now();
      }
      updateClock();

      // WEATHER
      if (d.weatherData) {
        renderWeatherBlock(d.weatherData);
      }

      // HOLIDAYS
      if (d.holidays) {
        const today = new Date();
        today.setHours(0,0,0,0);

        let closest = Infinity;
        let name = "";

        for (let ds in d.holidays) {
          const hd = new Date(ds);
          if (hd >= today) {
            const diff = Math.ceil((hd - today) / 86400000);
            if (diff < closest) {
              closest = diff;
              name = d.holidays[ds];
            }
          }
        }

        safeSet("nextHoliday", `${name} — in ${closest} day${closest>1?"s":""}`);
      }
    });
}
/* #endregion */

/* ============================================================
   #region CLOCK
============================================================ */
function updateClock() {
  if (!serverData) return;

  const now = new Date(Date.now() + serverTimeOffset);
  const h = now.getHours();
  const m = now.getMinutes().toString().padStart(2,'0');
  const s = now.getSeconds().toString().padStart(2,'0');
  const am = h >= 12 ? "PM":"AM";

  safeSet("currentTime", `${(h%12)||12}:${m}:${s} ${am}`);

  // INTERVALS
  if (serverData.intervalsArray) {
    let sec = Math.max(0, serverData.intervalsArray.secondsRemainingToInterval);
    const hh = Math.floor(sec/3600).toString().padStart(2,'0');
    const mm = Math.floor((sec%3600)/60).toString().padStart(2,'0');
    const ss = (sec%60).toString().padStart(2,'0');

    const i = serverData.intervalsArray;
    let msg="", emoji="";

    if (i.dayType !== "Workday") {
      msg = "Office closed"; emoji = "🟦";
    } else if (i.intervalName === "Worktime") {
      msg = `Workday ends in ${hh}h ${mm}m ${ss}s`; emoji = "🟩";
    } else if (i.intervalName === "Before Worktime") {
      msg = `Workday begins in ${hh}h ${mm}m ${ss}s`; emoji = "🟨";
    } else {
      msg = "Workday resumes tomorrow"; emoji = "🔴";
    }

    safeSet("intervalRemainingData", `${emoji} ${msg}`);
    serverData.intervalsArray.secondsRemainingToInterval = sec - 1;
  }
}
/* #endregion */

/* ============================================================
   #region CARD ROTATION
============================================================ */
function rotateCards() {
  const c = cards[cardIndex];
  populateCard(c);

  if (cardIndex === 0) {
    setTimeout(loadPermitData, 50);
    setTimeout(autoScrollActivePermits, 500);
  }

  if (cardIndex === 1) {
    setTimeout(updateHighlightsCard, 50);
  }

  cardIndex = (cardIndex + 1) % cards.length;
  setTimeout(rotateCards, c.duration);
}
/* #endregion */

/* ============================================================
   #region INIT
============================================================ */
window.addEventListener("DOMContentLoaded", () => {
  rotateCards();
  loadLiveData();
  setInterval(loadLiveData, 15000);
  setInterval(updateClock, 1000);
});
/* #endregion */
